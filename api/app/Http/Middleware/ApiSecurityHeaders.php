<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers for every API response, errors included. Caddy sets the same values at the edge;
 * they are repeated here so hosts without that proxy keep them.
 */
class ApiSecurityHeaders
{
    private const HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Referrer-Policy' => 'same-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api', 'api/*', 'sanctum/*', 'up')) {
            foreach (self::HEADERS as $name => $value) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
