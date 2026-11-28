<?php

namespace App\Http\Middleware;

use App\Support\Http\Fields;
use App\Support\Http\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every authenticated write names the account of the screen that sent it, so a tab left open
 * for another account cannot write into this one. A safety latch, never authorization.
 * Runs before the tenant transaction: nothing happens when it fails.
 */
class RequireCortexUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $header = (string) $request->headers->get('X-Cortex-User', '');

        if ($header === '') {
            throw Fields::failure([['X-Cortex-User', 'required']]);
        }

        if (strtolower($header) !== $request->user()->getAuthIdentifier()) {
            throw new ProblemException('user_mismatch', 409);
        }

        return $next($request);
    }
}
