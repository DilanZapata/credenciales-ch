<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Contexto de seguridad de la peticion en curso: quien actua, con que
 * sesion, desde donde y con que permisos efectivos.
 *
 * Es la unica fuente de verdad de autorizacion del backend. Los
 * controladores y servicios preguntan aqui; nunca al frontend.
 */
final class AuthContext
{
    /** @var array<string,mixed>|null */
    private ?array $user = null;
    /** @var array<string,mixed>|null */
    private ?array $session = null;
    /** @var array<string,bool> */
    private array $permissions = [];
    /** @var array<int,array<string,mixed>> */
    private array $roles = [];

    private string $ip = '0.0.0.0';
    private string $userAgent = '';
    private string $device = '';
    private string $route = '';
    private string $method = '';

    public function setRequestInfo(string $ip, string $userAgent, string $device, string $method, string $route): void
    {
        $this->ip        = $ip;
        $this->userAgent = $userAgent;
        $this->device    = $device;
        $this->method    = $method;
        $this->route     = $route;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $session
     * @param array<int,string>   $permissions
     * @param array<int,array<string,mixed>> $roles
     */
    public function authenticate(array $user, array $session, array $permissions, array $roles): void
    {
        $this->user        = $user;
        $this->session     = $session;
        $this->roles       = $roles;
        $this->permissions = array_fill_keys($permissions, true);
    }

    public function forget(): void
    {
        $this->user        = null;
        $this->session     = null;
        $this->permissions = [];
        $this->roles       = [];
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user !== null ? (int) $this->user['id'] : null;
    }

    public function nationalId(): ?string
    {
        return $this->user['national_id'] ?? null;
    }

    public function fullName(): string
    {
        if ($this->user === null) {
            return 'Sistema';
        }
        return trim(($this->user['first_name'] ?? '') . ' ' . ($this->user['last_name'] ?? ''));
    }

    /** @return array<string,mixed>|null */
    public function session(): ?array
    {
        return $this->session;
    }

    public function sessionId(): ?string
    {
        return $this->session['id'] ?? null;
    }

    public function csrfToken(): ?string
    {
        return $this->session['csrf_token'] ?? null;
    }

    public function can(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }

    public function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,string> */
    public function permissions(): array
    {
        return array_keys($this->permissions);
    }

    /** @return array<int,array<string,mixed>> */
    public function roles(): array
    {
        return $this->roles;
    }

    /** @return array<int,string> */
    public function roleCodes(): array
    {
        return array_map(static fn (array $r): string => (string) $r['code'], $this->roles);
    }

    public function hasRole(string $code): bool
    {
        return in_array($code, $this->roleCodes(), true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('SUPERADMIN');
    }

    /** Nivel de privilegio mas alto entre los roles del usuario. */
    public function level(): int
    {
        $max = 0;
        foreach ($this->roles as $role) {
            $max = max($max, (int) $role['level']);
        }
        return $max;
    }

    /** True si la reautenticacion (step-up) sigue vigente. */
    public function reauthenticatedWithin(int $minutes): bool
    {
        $at = $this->session['reauth_at'] ?? null;
        if ($at === null) {
            return false;
        }
        return strtotime((string) $at) >= (time() - ($minutes * 60));
    }

    public function markReauthenticated(string $timestamp): void
    {
        if ($this->session !== null) {
            $this->session['reauth_at'] = $timestamp;
        }
    }

    public function ip(): string        { return $this->ip; }
    public function userAgent(): string { return $this->userAgent; }
    public function device(): string    { return $this->device; }
    public function route(): string     { return $this->route; }
    public function method(): string    { return $this->method; }
}
