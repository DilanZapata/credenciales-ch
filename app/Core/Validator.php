<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validacion de entrada por lista blanca. Un dato que no supera la
 * validacion no llega jamas a la capa de persistencia.
 */
final class Validator
{
    private array $errors = [];
    private array $clean  = [];

    public function __construct(private array $data)
    {
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    private function value(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function required(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v === null || (is_string($v) && trim($v) === '') || (is_array($v) && $v === [])) {
            $this->errors[$field] = $label . ' es obligatorio.';
        }
        return $this;
    }

    public function string(string $field, string $label, int $min = 0, int $max = 255, bool $required = true): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            } else {
                $this->clean[$field] = null;
            }
            return $this;
        }
        if (!is_string($v)) {
            $this->errors[$field] = $label . ' tiene un formato invalido.';
            return $this;
        }
        $v   = trim($v);
        $len = mb_strlen($v);
        if ($len < $min) {
            $this->errors[$field] = $label . " debe tener al menos {$min} caracteres.";
        } elseif ($len > $max) {
            $this->errors[$field] = $label . " no puede superar {$max} caracteres.";
        } else {
            $this->clean[$field] = $v;
        }
        return $this;
    }

    public function email(string $field, string $label, bool $required = true): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            } else {
                $this->clean[$field] = null;
            }
            return $this;
        }
        $v = trim((string) $v);
        if (filter_var($v, FILTER_VALIDATE_EMAIL) === false || mb_strlen($v) > 190) {
            $this->errors[$field] = $label . ' no es una direccion de correo valida.';
            return $this;
        }
        $this->clean[$field] = mb_strtolower($v);
        return $this;
    }

    public function nationalId(string $field, string $label): self
    {
        $v = trim((string) ($this->value($field) ?? ''));
        if ($v === '') {
            $this->errors[$field] = $label . ' es obligatorio.';
            return $this;
        }
        if (preg_match('/^[A-Za-z0-9.\-]{5,30}$/', $v) !== 1) {
            $this->errors[$field] = $label . ' solo admite letras, numeros, puntos y guiones (5 a 30 caracteres).';
            return $this;
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function username(string $field, string $label): self
    {
        $v = trim((string) ($this->value($field) ?? ''));
        if (preg_match('/^[a-zA-Z0-9._\-]{4,60}$/', $v) !== 1) {
            $this->errors[$field] = $label . ' debe tener entre 4 y 60 caracteres (letras, numeros, punto, guion, guion bajo).';
            return $this;
        }
        $this->clean[$field] = mb_strtolower($v);
        return $this;
    }

    public function integer(string $field, string $label, ?int $min = null, ?int $max = null, bool $required = true): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            } else {
                $this->clean[$field] = null;
            }
            return $this;
        }
        $int = filter_var($v, FILTER_VALIDATE_INT);
        if ($int === false) {
            $this->errors[$field] = $label . ' debe ser un numero entero.';
            return $this;
        }
        if ($min !== null && $int < $min) {
            $this->errors[$field] = $label . " no puede ser menor que {$min}.";
            return $this;
        }
        if ($max !== null && $int > $max) {
            $this->errors[$field] = $label . " no puede ser mayor que {$max}.";
            return $this;
        }
        $this->clean[$field] = $int;
        return $this;
    }

    /** @param array<int,string> $allowed */
    public function in(string $field, string $label, array $allowed, bool $required = true): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            } else {
                $this->clean[$field] = null;
            }
            return $this;
        }
        if (!in_array((string) $v, $allowed, true)) {
            $this->errors[$field] = $label . ' contiene un valor no permitido.';
            return $this;
        }
        $this->clean[$field] = (string) $v;
        return $this;
    }

    public function date(string $field, string $label, bool $required = false): self
    {
        $v = trim((string) ($this->value($field) ?? ''));
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            } else {
                $this->clean[$field] = null;
            }
            return $this;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            $this->errors[$field] = $label . ' debe tener el formato AAAA-MM-DD.';
            return $this;
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function url(string $field, string $label, bool $required = false): self
    {
        $v = trim((string) ($this->value($field) ?? ''));
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            } else {
                $this->clean[$field] = null;
            }
            return $this;
        }
        if (filter_var($v, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $v) || mb_strlen($v) > 500) {
            $this->errors[$field] = $label . ' debe ser una URL http(s) valida.';
            return $this;
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function ip(string $field, string $label, bool $required = false): self
    {
        $v = trim((string) ($this->value($field) ?? ''));
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            } else {
                $this->clean[$field] = null;
            }
            return $this;
        }
        if (filter_var($v, FILTER_VALIDATE_IP) === false) {
            $this->errors[$field] = $label . ' no es una direccion IP valida.';
            return $this;
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function phone(string $field, string $label, bool $required = false): self
    {
        $v = trim((string) ($this->value($field) ?? ''));
        if ($v === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            } else {
                $this->clean[$field] = null;
            }
            return $this;
        }
        if (preg_match('/^[0-9 +().\-]{6,40}$/', $v) !== 1) {
            $this->errors[$field] = $label . ' no es un telefono valido.';
            return $this;
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function text(string $field, string $label, int $max = 5000, bool $required = false): self
    {
        $v = $this->value($field);
        if ($v === null || trim((string) $v) === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            } else {
                $this->clean[$field] = null;
            }
            return $this;
        }
        $v = trim((string) $v);
        if (mb_strlen($v) > $max) {
            $this->errors[$field] = $label . " no puede superar {$max} caracteres.";
            return $this;
        }
        $this->clean[$field] = $v;
        return $this;
    }

    public function bool(string $field): self
    {
        $v = $this->value($field);
        $this->clean[$field] = in_array(strtolower((string) (is_array($v) ? '' : $v)), ['1', 'true', 'on', 'yes', 'si'], true);
        return $this;
    }

    public function custom(string $field, bool $condition, string $message): self
    {
        if (!$condition) {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
        return $this->clean;
    }
}
