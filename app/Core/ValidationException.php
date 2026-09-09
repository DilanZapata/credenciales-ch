<?php
declare(strict_types=1);

namespace App\Core;

final class ValidationException extends HttpException
{
    /** @param array<string,string> $errors */
    public function __construct(private array $errors, string $message = 'Existen datos invalidos en el formulario.')
    {
        parent::__construct(422, $message, ['errors' => $errors]);
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
