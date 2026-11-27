<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs the rest of the request inside the authenticated user's transaction.
 * DB::transaction() around $next would not work: the pipeline turns exceptions into
 * responses before they get back here, and the partial writes would be committed.
 */
class ApplyTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $this->tenant->begin($user->getAuthIdentifier());

        try {
            SetRequestLocale::apply($user->locale);

            $response = $next($request);
        } catch (Throwable $e) {
            $this->tenant->rollBack();

            throw $e;
        }

        if (($response->exception ?? null) !== null || $response->getStatusCode() >= 400) {
            $this->tenant->rollBack();
        } else {
            // A failed COMMIT discards the response: the exception becomes the answer.
            $this->tenant->commit();
        }

        return $response;
    }
}
