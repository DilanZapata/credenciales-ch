<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\AuthContext;
use App\Services\AuthService;
use App\Services\SessionService;
use App\Services\SettingsService;

/** Reautenticacion (step-up) y estado de la sesion. */
final class AuthApiController extends Controller
{
    public function __construct(
        private AuthService $auth,
        private SessionService $sessions,
        private AuthContext $context,
        private SettingsService $settings
    ) {
    }

    /** POST /api/v1/reauth - confirma la identidad antes de una accion sensible. */
    public function reauth(Request $request): Response
    {
        $userId = (int) $this->context->id();
        $ok     = $this->auth->reauthenticate(
            $userId,
            $request->secret('password'),
            $request->string('code') ?: null
        );

        if (!$ok) {
            // Mensaje uniforme: no se distingue entre clave y codigo erroneos.
            return $this->json(['error' => 'No fue posible confirmar su identidad.'], 401);
        }

        $timestamp = $this->sessions->markReauthenticated((string) $this->context->sessionId());
        $this->context->markReauthenticated($timestamp);

        return $this->json([
            'ok'              => true,
            'valid_until'     => date('c', time() + ($this->settings->int('security.reauth_minutes', 10) * 60)),
            'minutes'         => $this->settings->int('security.reauth_minutes', 10),
        ]);
    }

    /** GET /api/v1/sesion - estado de la sesion para el frontend. */
    public function status(Request $request): Response
    {
        $session = $this->context->session() ?? [];
        $idle    = $this->settings->int('security.session_idle_minutes', 30);
        $last    = isset($session['last_activity_at']) ? strtotime((string) $session['last_activity_at']) : time();

        return $this->json([
            'authenticated'      => $this->context->check(),
            'user'               => [
                'id'          => $this->context->id(),
                'name'        => $this->context->fullName(),
                'national_id' => $this->context->nationalId(),
                'roles'       => $this->context->roleCodes(),
            ],
            'expires_in_seconds' => max(0, ($last + $idle * 60) - time()),
            'reauth_valid'       => $this->context->reauthenticatedWithin($this->settings->int('security.reauth_minutes', 10)),
        ]);
    }
}
