<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/** Base comun a los controladores web y de API. */
abstract class Controller
{
    protected function view(string $template, array $data = [], string $layout = 'layouts/app'): Response
    {
        return Response::html(View::page($template, $data, $layout));
    }

    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($this->url($path));
    }

    protected function back(Request $request, string $fallback = '/'): Response
    {
        $referer = $request->header('Referer');
        if ($referer !== null && $referer !== '') {
            return Response::redirect($referer);
        }
        return $this->redirect($fallback);
    }

    protected function url(string $path): string
    {
        $base = (string) Config::get('app.base_path', '');
        return $base . '/' . ltrim($path, '/');
    }

    protected function flash(string $key, mixed $value): void
    {
        Flash::set($key, $value);
    }

    protected function success(string $message): void
    {
        Flash::set('success', $message);
    }

    protected function error(string $message): void
    {
        Flash::set('error', $message);
    }

    /** Paginacion segura a partir de la peticion. */
    protected function pagination(Request $request, int $defaultPerPage = 25): array
    {
        return [
            max(1, (int) ($request->int('page') ?? 1)),
            max(5, min(100, (int) ($request->int('per_page') ?? $defaultPerPage))),
        ];
    }
}
