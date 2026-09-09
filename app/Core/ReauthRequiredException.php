<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Se lanza cuando una operacion sensible (revelar/copiar/exportar secretos)
 * exige una reautenticacion reciente (step-up). El cliente debe presentar la
 * contrasena de nuevo y reintentar.
 */
final class ReauthRequiredException extends HttpException
{
    public function __construct(string $message = 'Por seguridad, confirme su contrasena para continuar.')
    {
        parent::__construct(423, $message, ['reauth_required' => true]);
    }
}
