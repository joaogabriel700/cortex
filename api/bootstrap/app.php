<?php

require_once __DIR__.'/secrets.php';

use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\ApplyTenantContext;
use App\Http\Middleware\LimitApiBody;
use App\Http\Middleware\RequireCortexUser;
use App\Http\Middleware\SetRequestLocale;
use App\Http\Middleware\VerifySessionMark;
use App\Support\Http\Problem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(ApiSecurityHeaders::class);
        $middleware->append(LimitApiBody::class);

        // Reject unexpected Host headers (not applied in local and testing environments).
        // The loopback names serve the container health check.
        $middleware->trustHosts(at: fn (): array => array_map(
            fn (string $host): string => '^'.preg_quote($host).'$',
            array_values(array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), 'localhost', '127.0.0.1'])),
        ), subdomains: false);

        // Sanctum SPA authentication: cookie and CSRF, same origin. No API tokens.
        $middleware->statefulApi();
        $middleware->api(prepend: [SetRequestLocale::class]);

        // The API keeps the submitted text as it is ('' is not null, nothing is trimmed).
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/*')]);

        // Authenticated routes: authentication, session revocation, then the tenant
        // transaction, always before route model binding.
        $middleware->alias([
            'session.mark' => VerifySessionMark::class,
            'cortex.user' => RequireCortexUser::class,
            'tenant' => ApplyTenantContext::class,
        ]);
        $middleware->prependToPriorityList(SubstituteBindings::class, ApplyTenantContext::class);
        $middleware->prependToPriorityList(ApplyTenantContext::class, RequireCortexUser::class);
        $middleware->prependToPriorityList(RequireCortexUser::class, VerifySessionMark::class);
        $middleware->group('authenticated', ['auth:sanctum', 'session.mark', 'cortex.user', 'tenant']);

        // Only trust forwarding headers from the configured proxy ranges.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));

        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A QueryException message carries the bound values (e-mail, hashes, amounts):
        // log the statement without them.
        $exceptions->report(function (QueryException $e): bool {
            Log::error('database error', ['sqlstate' => (string) $e->getCode(), 'sql' => $e->getSql()]);

            return false;
        });
        $exceptions->render(fn (Throwable $e, Request $request) => Problem::render($e, $request));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
