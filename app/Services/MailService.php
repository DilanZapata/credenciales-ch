<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;

/**
 * Envio de notificaciones por correo.
 *
 * Politica: el correo NUNCA transporta contrasenas ni secretos. Solo
 * avisos y enlaces de un solo uso. Si el envio esta deshabilitado, el
 * mensaje se registra en el log tecnico para trazabilidad del proceso.
 */
final class MailService
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->bool('mail.enabled', false);
    }

    public function send(string $to, string $subject, string $body): bool
    {
        // Cortafuegos: si el cuerpo contiene algo con aspecto de secreto, no se envia.
        if ($this->looksLikeSecret($body)) {
            Logger::critical('Se bloqueo un correo que parecia contener un secreto', ['asunto' => $subject]);
            return false;
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        if (!$this->enabled()) {
            Logger::info('Correo no enviado (envio deshabilitado)', ['para' => $to, 'asunto' => $subject]);
            return false;
        }

        $from    = (string) $this->settings->get('mail.from', 'no-reply@empresa.local');
        $headers = implode("\r\n", [
            'From: ' . $this->sanitizeHeader($from),
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: SCGCA',
        ]);

        return @mail(
            $this->sanitizeHeader($to),
            $this->sanitizeHeader($subject),
            wordwrap($body, 78),
            $headers
        );
    }

    public function sendPasswordResetLink(string $to, string $name, string $link): bool
    {
        $app  = (string) Config::get('app.name', 'Gestion de Credenciales');
        $body = "Hola {$name}:\n\n"
              . "Recibimos una solicitud para restablecer su contrasena de acceso a {$app}.\n\n"
              . "Abra el siguiente enlace (valido por 30 minutos y de un solo uso):\n{$link}\n\n"
              . "Si usted no realizo esta solicitud, ignore este mensaje y avise al administrador.\n\n"
              . "Por seguridad, este sistema nunca envia contrasenas por correo electronico.\n";
        return $this->send($to, "[{$app}] Restablecimiento de contrasena", $body);
    }

    /** @param array<int,array<string,mixed>> $alerts */
    public function sendAlertDigest(array $alerts): void
    {
        if ($alerts === []) {
            return;
        }
        $app  = (string) Config::get('app.name', 'Gestion de Credenciales');
        $body = "Resumen de alertas de {$app} - " . date('d/m/Y H:i') . "\n\n";
        foreach ($alerts as $alert) {
            $body .= sprintf("[%s] %s\n    %s\n\n", strtoupper((string) $alert['severity']), $alert['title'], $alert['message']);
        }
        $body .= "Ingrese al sistema para revisar el detalle.\n";
        $this->send((string) $this->settings->get('mail.from', 'no-reply@empresa.local'), "[{$app}] Alertas de seguridad", $body);
    }

    private function sanitizeHeader(string $value): string
    {
        return str_replace(["\r", "\n", "\0", '%0a', '%0d'], '', $value);
    }

    private function looksLikeSecret(string $body): bool
    {
        return (bool) preg_match('/\b(contrasena|password|clave|secret)\s*[:=]\s*\S+/i', $body);
    }
}
