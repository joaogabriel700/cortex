<?php

namespace App\Http\Middleware;

use App\Auth\SessionMark;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Runs on every authenticated request, after authentication. Fails closed. */
class VerifySessionMark
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            throw new AuthenticationException;
        }

        if (! SessionMark::matches($request->session(), $request->user())) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
