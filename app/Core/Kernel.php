<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Middleware\AuthenticateMiddleware;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\GuestMiddleware;
use App\Http\Middleware\MiddlewareInterface;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Services\AuthContext;
use App\Services\AuthorizationService;
use App\Services\RateLimiter;
use App\Services\SessionService;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Nucleo HTTP: resuelve la ruta, ejecuta la cadena de middleware,
 * invoca al controlador y traduce cualquier excepcion en una respuesta
 * segura (sin filtrar detalles internos).
 */
final class Kernel
{
    public function __construct(private Container $container, private Router $router)
    {
    }

    public function handle(Request $request): Response
    {
        $context = $this->container->get(AuthContext::class);
        $context->setRequestInfo(
            $request->ip(),
            $request->userAgent(),
            $request->device(),
            $request->method(),
            $request->path()
        );

        try {
            $match = $this->router->match($request->method(), $request->path());
            if ($match === null) {
                throw HttpException::notFound('La pagina solicitada no existe.');
            }

            $route  = $match['route'];
            $params = $match['params'];

            $pipeline = array_reverse($route['middleware']);
            $handler  = function (Request $request) use ($route, $params): Response {
                return $this->dispatch($route['handler'], $request, $params);
            };

            foreach ($pipeline as $name) {
                $middleware = $this->resolveMiddleware($name);
                $next       = $handler;
                $handler    = static fn (Request $r): Response => $middleware->handle($r, $next);
            }

            return $handler($request);
        } catch (Throwable $e) {
            return $this->renderException($e, $request);
        }
    }

    /** @param array{0:class-string,1:string}|callable $handler */
    private function dispatch(mixed $handler, Request $request, array $params): Response
    {
        if (is_callable($handler)) {
            $result = $handler($request, $params);
        } else {
            [$class, $method] = $handler;
            $controller = $this->autowire($class);
            $result     = $controller->{$method}($request, $params);
        }

        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }

    /** Instancia una clase resolviendo sus dependencias desde el contenedor. */
    public function autowire(string $class): object
    {
        if ($this->container->has($class)) {
            return $this->container->get($class);
        }
        $reflection  = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->autowire($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            throw new \RuntimeException('No se pudo resolver la dependencia de ' . $class);
        }
        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveMiddleware(string $name): MiddlewareInterface
    {
        if (str_starts_with($name, 'perm:')) {
            return new PermissionMiddleware(
                $this->container->get(AuthorizationService::class),
                substr($name, 5)
            );
        }
        if (str_starts_with($name, 'throttle:')) {
            return new RateLimitMiddleware($this->container->get(RateLimiter::class), substr($name, 9));
        }

        return match ($name) {
            'security' => new SecurityHeadersMiddleware(),
            'cors'     => new CorsMiddleware(),
            'auth'     => new AuthenticateMiddleware(
                $this->container->get(SessionService::class),
                $this->container->get(\App\Repositories\UserRepository::class),
                $this->container->get(AuthContext::class),
                $this->container->get(\App\Services\SettingsService::class)
            ),
            'guest'    => new GuestMiddleware($this->container->get(SessionService::class)),
            'csrf'     => new CsrfMiddleware(
                $this->container->get(SessionService::class),
                $this->container->get(AuthContext::class),
                $this->container->get(\App\Services\AuditService::class)
            ),
            default    => throw new \RuntimeException('Middleware desconocido: ' . $name),
        };
    }

    /**
     * Traduccion de errores a respuestas.
     *
     * En produccion NUNCA se expone la traza, el mensaje interno ni la
     * consulta SQL: eso alimentaria a un atacante. El detalle completo
     * queda en el log tecnico del servidor.
     */
    private function renderException(Throwable $e, Request $request): Response
    {
        $isHttp = $e instanceof HttpException;
        $status = $isHttp ? $e->statusCode() : 500;
        $debug  = (bool) Config::get('app.debug', false);

        if (!$isHttp || $status >= 500) {
            Logger::error('Excepcion no controlada', [
                'clase'   => $e::class,
                'mensaje' => $e->getMessage(),
                'archivo' => $e->getFile() . ':' . $e->getLine(),
                'ruta'    => $request->path(),
                'traza'   => $debug ? $e->getTraceAsString() : '[oculta]',
            ]);
        }

        $message = $isHttp
            ? $e->getMessage()
            : ($debug ? $e->getMessage() : 'Ocurrio un error inesperado. El incidente quedo registrado.');

        if ($request->wantsJson()) {
            $payload = ['error' => $message, 'status' => $status];
            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }
            if ($e instanceof ReauthRequiredException) {
                $payload['reauth_required'] = true;
            }
            return Response::json($payload, $status);
        }

        if ($e instanceof ValidationException) {
            // Los formularios web reciben los errores por sesion flash.
            Flash::set('errors', $e->errors());
            Flash::set('error', $message);
            $back = $request->header('Referer') ?? (Config::get('app.base_path', '') . '/');
            return Response::redirect($back);
        }

        try {
            $html = View::page('errors/error', [
                'status'  => $status,
                'message' => $message,
                'title'   => match ($status) {
                    400     => 'Solicitud invalida',
                    401     => 'Sesion requerida',
                    403     => 'Acceso denegado',
                    404     => 'No encontrado',
                    405     => 'Metodo no permitido',
                    409     => 'Operacion no disponible',
                    419     => 'Sesion expirada',
                    423     => 'Confirmacion requerida',
                    429     => 'Demasiadas solicitudes',
                    default => 'Error del sistema',
                },
            ], 'layouts/minimal');
        } catch (Throwable) {
            $html = '<!doctype html><meta charset="utf-8"><title>Error</title>'
                  . '<p style="font-family:sans-serif;padding:2rem">'
                  . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return Response::html($html, $status);
    }
}
