<?php

namespace App\Http\Controllers;

use App\Support\Database\RoleGuard;
use Illuminate\Http\JsonResponse;
use Throwable;

class HealthController extends Controller
{
    /** Public endpoint: it reports status only, never role names or error details. */
    public function __invoke(RoleGuard $guard): JsonResponse
    {
        try {
            $guard->assertSafe();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'error', 'database' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ok', 'database' => 'ok']);
    }
}
