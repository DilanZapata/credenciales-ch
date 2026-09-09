<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Repositories\AssignmentRepository;
use App\Repositories\UserRepository;

/** Ciclo de vida de los usuarios del sistema (art. 2 y 19). */
final class UserService
{
    public function __construct(
        private Database $db,
        private UserRepository $users,
        private AssignmentRepository $assignments,
        private SessionService $sessions,
        private CryptoService $crypto,
        private AuthService $auth,
        private AuthorizationService $gate,
        private AuthContext $context,
        private AuditService $audit,
        private PasswordGeneratorService $passwords
    ) {
    }

    public function list(array $filters, int $page, int $perPage): array
    {
        $this->gate->require('users.view');
        $perPage = max(5, min(100, $perPage));
        $result  = $this->users->paginate($filters, max(1, $page), $perPage);
        return [
            'items'    => $result['items'],
            'total'    => $result['total'],
            'page'     => max(1, $page),
            'per_page' => $perPage,
            'pages'    => (int) ceil($result['total'] / $perPage),
        ];
    }

    public function show(int $id): array
    {
        $this->gate->require('users.view', 'user', $id);
        $user = $this->users->find($id);
        if ($user === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        return [
            'user'        => $user,
            'roles'       => $this->users->rolesOf($id),
            'permissions' => $this->users->effectivePermissions($id),
            'overrides'   => $this->users->permissionOverrides($id),
            'assignments' => $this->assignments->forUser($id, false),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,temporary_password:string}
     */
    public function create(array $data, array $roleIds, ?string $password = null): array
    {
        $this->gate->require('users.create');
        $this->assertUnique($data);
        $this->assertAssignableRoles($roleIds);

        // Si no se indica contrasena se genera una temporal robusta que el
        // usuario debera cambiar en su primer ingreso.
        $temporary = $password === null || $password === '';
        $plain     = $temporary
            ? $this->passwords->generate(['length' => 16, 'exclude_ambiguous' => true])
            : $password;

        if (!$temporary) {
            $this->auth->validatePasswordPolicy($plain, $data);
        }

        $hash = $this->crypto->hashPassword($plain);

        $id = $this->db->transaction(function () use ($data, $hash, $roleIds, $temporary): int {
            $id = $this->users->create(array_merge($data, [
                'password_hash'        => $hash['hash'],
                'password_algo'        => $hash['algo'],
                'must_change_password' => 1,
                'created_by'           => $this->context->id(),
            ]));
            $this->users->setRoles($id, $roleIds, (int) $this->context->id());
            return $id;
        });
        unset($temporary);

        $this->audit->log(AuditService::USER_CREATED, 'user', $id,
            $data['first_name'] . ' ' . $data['last_name'], 'success',
            ['cedula' => $data['national_id'], 'usuario' => $data['username'], 'roles' => $roleIds], 'notice');

        return ['id' => $id, 'temporary_password' => $plain];
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, ?array $roleIds = null): void
    {
        $this->gate->require('users.update', 'user', $id);
        $target = $this->users->find($id);
        if ($target === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        $this->gate->requireManageUser($this->levelOf($id));
        $this->assertUnique($data, $id);

        $this->users->update($id, $data, $this->context->id());

        if ($roleIds !== null) {
            $this->gate->require('users.assign_roles', 'user', $id);
            $this->assertAssignableRoles($roleIds);
            $before = array_column($this->users->rolesOf($id), 'code');
            $this->users->setRoles($id, $roleIds, (int) $this->context->id());
            $after  = array_column($this->users->rolesOf($id), 'code');
            if ($before !== $after) {
                $this->audit->log(AuditService::USER_ROLES_CHANGED, 'user', $id,
                    $target['first_name'] . ' ' . $target['last_name'], 'success',
                    ['antes' => $before, 'despues' => $after], 'warning');
                // Cambiar privilegios invalida las sesiones abiertas del usuario.
                $this->sessions->revokeAllForUser($id, $this->context->id(), 'cambio de roles');
            }
        }

        $this->audit->log(AuditService::USER_UPDATED, 'user', $id,
            $target['first_name'] . ' ' . $target['last_name'], 'success',
            ['campos' => array_keys($data)], 'notice');
    }

    /** @param array<string,string> $overrides */
    public function setPermissionOverrides(int $id, array $overrides): void
    {
        $this->gate->require('users.assign_roles', 'user', $id);
        $this->gate->requireManageUser($this->levelOf($id));

        // Nadie puede concederse a si mismo un permiso que no posee.
        foreach ($overrides as $code => $effect) {
            if ($effect === 'allow' && !$this->context->can($code) && !$this->context->isSuperAdmin()) {
                throw HttpException::forbidden('No puede otorgar un permiso que usted no posee: ' . $code);
            }
        }

        $this->users->setPermissionOverrides($id, $overrides, (int) $this->context->id());
        $this->sessions->revokeAllForUser($id, $this->context->id(), 'cambio de permisos');
        $this->audit->log(AuditService::USER_PERMS_CHANGED, 'user', $id, null, 'success',
            ['excepciones' => $overrides], 'warning');
    }

    /**
     * Baja de un empleado (art. 19): bloquea el acceso, revoca asignaciones
     * y CONSERVA todo el historial y la auditoria.
     */
    public function deactivate(int $id, string $reason, ?int $reassignToUserId = null): array
    {
        $this->gate->require('users.deactivate', 'user', $id);
        $target = $this->users->find($id);
        if ($target === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        $this->gate->requireManageUser($this->levelOf($id), $id);

        $previousAssignments = $this->assignments->forUser($id, true);

        $result = $this->db->transaction(function () use ($id, $reason, $reassignToUserId): array {
            $this->users->deactivate($id, (int) $this->context->id(), $reason);
            $revoked = $this->assignments->revokeAllForUser($id, (int) $this->context->id(), 'baja del usuario: ' . $reason);
            $reassigned = 0;
            if ($reassignToUserId !== null) {
                $reassigned = $this->assignments->reassign($id, $reassignToUserId, (int) $this->context->id());
            }
            return ['revoked' => $revoked, 'reassigned' => $reassigned];
        });

        $closed = $this->sessions->revokeAllForUser($id, $this->context->id(), 'usuario desactivado');

        $this->audit->log(AuditService::USER_DEACTIVATED, 'user', $id,
            $target['first_name'] . ' ' . $target['last_name'], 'success', [
                'motivo'               => $reason,
                'accesos_revocados'    => $result['revoked'],
                'accesos_reasignados'  => $result['reassigned'],
                'sesiones_cerradas'    => $closed,
                'credenciales_previas' => array_map(
                    static fn (array $a): string => (string) $a['system_name'] . ' / ' . (string) $a['credential_name'],
                    $previousAssignments
                ),
            ], 'warning');

        return array_merge($result, ['sessions_closed' => $closed, 'previous' => $previousAssignments]);
    }

    public function reactivate(int $id): void
    {
        $this->gate->require('users.deactivate', 'user', $id);
        $target = $this->users->find($id);
        if ($target === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        $this->gate->requireManageUser($this->levelOf($id));
        $this->users->reactivate($id, (int) $this->context->id());
        $this->audit->log(AuditService::USER_REACTIVATED, 'user', $id,
            $target['first_name'] . ' ' . $target['last_name'], 'success', [], 'notice');
    }

    /** Restablecimiento administrativo: entrega una clave temporal de un solo uso. */
    public function resetPassword(int $id): string
    {
        $this->gate->require('users.reset_password', 'user', $id);
        $target = $this->users->find($id);
        if ($target === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        $this->gate->requireManageUser($this->levelOf($id), $id);
        $this->gate->requireStepUp('secret');

        $plain = $this->passwords->generate(['length' => 16, 'exclude_ambiguous' => true]);
        $hash  = $this->crypto->hashPassword($plain);
        $this->users->updatePassword($id, $hash['hash'], $hash['algo'], true);
        $this->sessions->revokeAllForUser($id, $this->context->id(), 'restablecimiento administrativo');

        $this->audit->log(AuditService::USER_PASSWORD_RESET, 'user', $id,
            $target['first_name'] . ' ' . $target['last_name'], 'success', [], 'warning');

        return $plain;
    }

    public function assignmentsOf(int $id): array
    {
        $this->gate->requireAny(['users.view', 'credentials.assign'], 'user', $id);
        return $this->assignments->forUser($id, false);
    }

    // -----------------------------------------------------------------

    private function levelOf(int $userId): int
    {
        $roles = $this->users->rolesOf($userId);
        $max   = 0;
        foreach ($roles as $role) {
            $max = max($max, (int) $role['level']);
        }
        return $max;
    }

    /** Nadie puede otorgar un rol de nivel igual o superior al suyo. */
    private function assertAssignableRoles(array $roleIds): void
    {
        if ($this->context->isSuperAdmin()) {
            return;
        }
        $level = $this->context->level();
        foreach ($roleIds as $roleId) {
            $roleLevel = (int) $this->db->scalar('SELECT level FROM roles WHERE id = ?', [(int) $roleId]);
            if ($roleLevel >= $level) {
                throw HttpException::forbidden('No puede asignar un rol de nivel igual o superior al suyo.');
            }
        }
    }

    private function assertUnique(array $data, ?int $exceptId = null): void
    {
        $errors = [];
        foreach (['national_id' => 'La cedula', 'username' => 'El nombre de usuario', 'email' => 'El correo'] as $field => $label) {
            if (!empty($data[$field]) && $this->users->existsField($field, (string) $data[$field], $exceptId)) {
                $errors[$field] = $label . ' ya esta registrado para otro usuario.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
