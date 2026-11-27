<?php

require_once __DIR__.'/secrets.php';

use App\Http\Middleware\ApiSecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ApiSecurityHeaders::class);

        // Reject unexpected Host headers (not applied in local and testing environments).
        // The loopback names serve the container health check.
        $middleware->trustHosts(at: fn (): array => array_map(
            fn (string $host): string => '^'.preg_quote($host).'$',
            array_values(array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), 'localhost', '127.0.0.1'])),
        ), subdomains: false);

        // Sanctum SPA authentication: cookie and CSRF, same origin. No API tokens.
        $middleware->statefulApi();

        // Only trust forwarding headers from the configured proxy ranges.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));

        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
