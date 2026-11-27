<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * HTTP trigger for hosts whose scheduler cannot run a command. Disabled (404) unless a secret
 * of at least 32 characters is configured. It starts the same `tick` command in a separate CLI
 * process: the queue worker uses process signals, which must not run inside the web server.
 *
 * It runs with the credentials of the web container (app_runtime only).
 */
class TickController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.tick.secret');

        abort_if(strlen($secret) < 32, 404);
        abort_unless(hash_equals($secret, (string) $request->bearerToken()), 401);

        set_time_limit(150);

        $result = Process::path(base_path())->timeout(140)->run(['tick']);

        if ($result->failed()) {
            Log::error('tick failed', ['exit_code' => $result->exitCode()]);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok']);
    }
}
