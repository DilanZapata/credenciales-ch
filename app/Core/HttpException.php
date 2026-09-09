<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        private int $statusCode,
        string $message = '',
        private array $context = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function context(): array
    {
        return $this->context;
    }

    public static function notFound(string $m = 'Recurso no encontrado.'): self
    {
        return new self(404, $m);
    }

    public static function forbidden(string $m = 'No tiene autorizacion para realizar esta accion.'): self
    {
        return new self(403, $m);
    }

    public static function unauthorized(string $m = 'Debe iniciar sesion.'): self
    {
        return new self(401, $m);
    }

    public static function badRequest(string $m = 'Solicitud invalida.'): self
    {
        return new self(400, $m);
    }

    public static function tooManyRequests(string $m = 'Demasiadas solicitudes. Intente nuevamente mas tarde.'): self
    {
        return new self(429, $m);
    }

    public static function conflict(string $m = 'Conflicto con el estado actual del recurso.'): self
    {
        return new self(409, $m);
    }
}
