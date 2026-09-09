<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Generador de contrasenas criptograficamente seguro (random_int usa el
 * CSPRNG del sistema operativo; nunca rand()/mt_rand()).
 */
final class PasswordGeneratorService
{
    private const LOWER      = 'abcdefghijklmnopqrstuvwxyz';
    private const UPPER      = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const DIGITS     = '0123456789';
    private const SYMBOLS    = '!@#$%^&*()-_=+[]{};:,.?';
    private const AMBIGUOUS  = 'l1IO0o|`\'"S5B8Z2';

    /**
     * @param array{length?:int,upper?:bool,lower?:bool,digits?:bool,symbols?:bool,exclude_ambiguous?:bool} $options
     */
    public function generate(array $options = []): string
    {
        $length  = max(8, min(128, (int) ($options['length'] ?? 20)));
        $useUp   = (bool) ($options['upper']   ?? true);
        $useLow  = (bool) ($options['lower']   ?? true);
        $useNum  = (bool) ($options['digits']  ?? true);
        $useSym  = (bool) ($options['symbols'] ?? true);
        $noAmbig = (bool) ($options['exclude_ambiguous'] ?? false);

        $sets = [];
        if ($useLow) { $sets[] = $this->filter(self::LOWER,   $noAmbig); }
        if ($useUp)  { $sets[] = $this->filter(self::UPPER,   $noAmbig); }
        if ($useNum) { $sets[] = $this->filter(self::DIGITS,  $noAmbig); }
        if ($useSym) { $sets[] = $this->filter(self::SYMBOLS, $noAmbig); }
        if ($sets === []) {
            $sets[] = $this->filter(self::LOWER . self::UPPER . self::DIGITS, $noAmbig);
        }

        $pool = implode('', $sets);
        if ($pool === '') {
            $pool = self::LOWER . self::UPPER . self::DIGITS;
        }

        // Se garantiza al menos un caracter de cada conjunto solicitado.
        $chars = [];
        foreach ($sets as $set) {
            if ($set !== '' && count($chars) < $length) {
                $chars[] = $set[random_int(0, strlen($set) - 1)];
            }
        }
        while (count($chars) < $length) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        // Barajado Fisher-Yates con fuente criptografica.
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    private function filter(string $set, bool $removeAmbiguous): string
    {
        if (!$removeAmbiguous) {
            return $set;
        }
        $out = '';
        foreach (str_split($set) as $char) {
            if (!str_contains(self::AMBIGUOUS, $char)) {
                $out .= $char;
            }
        }
        return $out;
    }

    /**
     * Puntuacion 0-100 de robustez. Se calcula en memoria y solo se
     * almacena el numero: nunca la contrasena.
     */
    public function strength(string $password): int
    {
        $length  = strlen($password);
        $variety = 0;
        if (preg_match('/[a-z]/', $password)) { $variety++; }
        if (preg_match('/[A-Z]/', $password)) { $variety++; }
        if (preg_match('/\d/', $password))    { $variety++; }
        if (preg_match('/[^a-zA-Z0-9]/', $password)) { $variety++; }

        $poolSize = 0;
        if (preg_match('/[a-z]/', $password)) { $poolSize += 26; }
        if (preg_match('/[A-Z]/', $password)) { $poolSize += 26; }
        if (preg_match('/\d/', $password))    { $poolSize += 10; }
        if (preg_match('/[^a-zA-Z0-9]/', $password)) { $poolSize += 30; }

        $entropy = $poolSize > 1 ? $length * (log($poolSize, 2)) : 0.0;
        $score   = (int) round(min(100, ($entropy / 100) * 100));

        if ($length < 8)  { $score = (int) min($score, 25); }
        if ($variety < 2) { $score = (int) min($score, 40); }
        // Penaliza repeticiones y secuencias evidentes.
        if (preg_match('/(.)\1{2,}/', $password)) { $score -= 10; }
        if (stripos($password, '1234') !== false || stripos($password, 'abcd') !== false) { $score -= 15; }

        return max(0, min(100, $score));
    }

    public function strengthLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Muy fuerte',
            $score >= 60 => 'Fuerte',
            $score >= 40 => 'Aceptable',
            $score >= 20 => 'Debil',
            default      => 'Muy debil',
        };
    }
}
