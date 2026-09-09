<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Utilidades de token anti-CSRF.
 *
 * Con sesion autenticada se usa el token sincronizador almacenado en la
 * fila de la sesion. Para formularios publicos (acceso, recuperacion) se
 * emplea el patron "double submit cookie": el mismo valor viaja en una
 * cookie HttpOnly/SameSite=Strict y en el campo oculto del formulario.
 */
final class Csrf
{
    public const COOKIE = 'scgca_csrf';

    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValidFormat(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }
}
