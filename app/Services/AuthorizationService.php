<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\ReauthRequiredException;

/**
 * Punto unico de decision de autorizacion (PDP).
 *
 * Todo control de acceso pasa por aqui, EN EL BACKEND. El frontend solo
 * oculta opciones por comodidad; jamas es la barrera de seguridad
 * (art. 30: "nunca confiar en que el usuario no conozca una URL").
 */
final class AuthorizationService
{
    public function __construct(
        private AuthContext $context,
        private SettingsService $settings,
        private AuditService $audit
    ) {
    }

    public function can(string $permission): bool
    {
        return $this->context->can($permission);
    }

    /** Exige un permiso; registra el intento denegado y corta la peticion. */
    public function require(string $permission, ?string $entityType = null, int|string|null $entityId = null): void
    {
        if ($this->context->can($permission)) {
            return;
        }
        $this->denied($permission, $entityType, $entityId);
    }

    /** Exige al menos uno de varios permisos. */
    public function requireAny(array $permissions, ?string $entityType = null, int|string|null $entityId = null): void
    {
        foreach ($permissions as $permission) {
            if ($this->context->can($permission)) {
                return;
            }
        }
        $this->denied(implode('|', $permissions), $entityType, $entityId);
    }

    private function denied(string $permission, ?string $entityType, int|string|null $entityId): never
    {
        $this->audit->log(
            AuditService::ACCESS_DENIED,
            $entityType,
            $entityId,
            $permission,
            'denied',
            ['permiso_requerido' => $permission, 'ruta' => $this->context->route()],
            'warning'
        );
        throw HttpException::forbidden('No tiene autorizacion para realizar esta accion.');
    }

    /**
     * Step-up: exige que la reautenticacion sea reciente antes de una
     * operacion sensible (revelar/copiar/exportar secretos).
     */
    public function requireStepUp(string $operation): void
    {
        $settingKey = match ($operation) {
            'export' => 'security.reauth_for_export',
            default  => 'security.reauth_for_secret',
        };
        if (!$this->settings->bool($settingKey, true)) {
            return;
        }
        $minutes = max(1, $this->settings->int('security.reauth_minutes', 10));
        if ($this->context->reauthenticatedWithin($minutes)) {
            return;
        }
        throw new ReauthRequiredException();
    }

    /**
     * Alcance de datos: null significa "sin restriccion" (ve todo el
     * inventario); un id de usuario limita la consulta a lo asignado.
     */
    public function credentialScopeUserId(): ?int
    {
        if ($this->context->can('credentials.view_all')) {
            return null;
        }
        return $this->context->id();
    }

    /**
     * Un administrador no puede editar a alguien de nivel superior ni a
     * si mismo en operaciones destructivas (evita escalada y auto-bloqueo).
     */
    public function canManageUser(int $targetLevel, ?int $targetUserId = null): bool
    {
        if ($this->context->isSuperAdmin()) {
            return true;
        }
        if ($targetUserId !== null && $targetUserId === $this->context->id()) {
            return false;
        }
        return $this->context->level() > $targetLevel;
    }

    public function requireManageUser(int $targetLevel, ?int $targetUserId = null): void
    {
        if (!$this->canManageUser($targetLevel, $targetUserId)) {
            $this->audit->log(
                AuditService::ACCESS_DENIED, 'user', $targetUserId, 'gestion de usuario', 'denied',
                ['motivo' => 'nivel de privilegio insuficiente'], 'warning'
            );
            throw HttpException::forbidden('No puede administrar a un usuario con igual o mayor nivel de privilegio.');
        }
    }
}
