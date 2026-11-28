<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/** The CSRF token is required on every write of the web app, without the framework shortcuts. */
class VerifyCsrfToken extends PreventRequestForgery
{
    /** The framework would accept "Sec-Fetch-Site: same-origin" without any token. */
    protected function hasValidOrigin($request): bool
    {
        return false;
    }

    /** The framework would skip the check under tests, hiding a broken protection. */
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
