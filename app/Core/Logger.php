<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Registro tecnico en disco (JSON lines).
 *
 * REGLA INVIOLABLE: ningun secreto puede llegar al log. Antes de serializar,
 * el contexto pasa por un redactor que elimina cualquier clave sensible y
 * cualquier valor con aspecto de token/clave.
 */
final class Logger
{
    private const REDACTED = '[REDACTADO]';

    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pass', 'contrasena', 'contrasenia', 'clave',
        'secret', 'secreto', 'pin', 'access_code', 'codigo_acceso', 'token',
        'csrf', 'csrf_token', 'authorization', 'cookie', 'session', 'session_id',
        'master_key', 'app_key', 'dek', 'kek', 'ciphertext', 'wrapped_dek',
        'nonce', 'totp', 'otp', 'mfa_code', 'backup_code', 'recovery_answer',
        'new_password', 'current_password', 'password_confirmation', 'api_key',
    ];

    private static string $dir = '';

    public static function setDirectory(string $dir): void
    {
        self::$dir = rtrim($dir, '/');
    }

    public static function debug(string $m, array $c = []): void    { self::write('debug', $m, $c); }
    public static function info(string $m, array $c = []): void     { self::write('info', $m, $c); }
    public static function warning(string $m, array $c = []): void  { self::write('warning', $m, $c); }
    public static function error(string $m, array $c = []): void    { self::write('error', $m, $c); }
    public static function critical(string $m, array $c = []): void { self::write('critical', $m, $c); }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$dir === '') {
            return;
        }
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0750, true);
        }
        $record = [
            'ts'      => date('c'),
            'level'   => $level,
            'message' => self::redactString($message),
            'context' => self::redact($context),
        ];
        $file = self::$dir . '/app-' . date('Y-m-d') . '.log';
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        @chmod($file, 0640);
    }

    /** Elimina recursivamente cualquier dato sensible de un arreglo. */
    public static function redact(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[PROFUNDIDAD_MAXIMA]';
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && self::isSensitiveKey($key)) {
                    $out[$key] = self::REDACTED;
                    continue;
                }
                $out[$key] = self::redact($value, $depth + 1);
            }
            return $out;
        }
        if (is_object($data)) {
            return self::redact(get_object_vars($data), $depth + 1);
        }
        if (is_string($data)) {
            return self::redactString($data);
        }
        return $data;
    }

    public static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9_]/i', '', $key) ?? '');
        foreach (self::SENSITIVE_KEYS as $needle) {
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function redactString(string $value): string
    {
        // Datos binarios o cadenas muy largas nunca se vuelcan literalmente.
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '[BINARIO:' . strlen($value) . 'B]';
        }
        if (strlen($value) > 2000) {
            return substr($value, 0, 2000) . '...[TRUNCADO]';
        }
        return $value;
    }
}
