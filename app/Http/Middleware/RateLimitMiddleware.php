<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimiter;

/** Limitador general de peticiones por IP y por sesion. */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private RateLimiter $limiter, private string $profile = 'global')
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $config = (array) Config::get('security.rate_limits.' . $this->profile, ['max' => 600, 'window' => 60]);
        $bucket = $this->profile . ':' . $request->ip() . ':' . substr((string) $request->cookie('scgca_session'), 0, 16);

        if (!$this->limiter->attempt($bucket, (int) $config['max'], (int) $config['window'])) {
            throw HttpException::tooManyRequests();
        }
        return $next($request);
    }
}
