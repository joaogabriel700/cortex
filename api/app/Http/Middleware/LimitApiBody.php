<?php

namespace App\Http\Middleware;

use App\Support\Http\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bodies of /api/v1: JSON only and at most 64 KB. The routes of the contract that accept more
 * (sync, imports, restore) must declare their own limit when they are built.
 */
class LimitApiBody
{
    public const MAX_BYTES = 65536;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/*') || $request->isMethodSafe()) {
            return $next($request);
        }

        $declared = (int) $request->headers->get('Content-Length', '0');

        if ($declared > self::MAX_BYTES) {
            throw new ProblemException('payload_too_large', 413);
        }

        $length = strlen((string) $request->getContent());

        if ($length > self::MAX_BYTES) {
            throw new ProblemException('payload_too_large', 413);
        }

        // PHP does not expose multipart bodies as content, hence the declared length too.
        if ($declared > 0 || $length > 0) {
            $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));

            if ($type !== 'application/json') {
                throw new ProblemException('unsupported_media_type', 415);
            }
        }

        return $next($request);
    }
}
