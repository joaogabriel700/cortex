<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** SessionEnvelope of the API contract. Call it inside the user's tenant context. */
class SessionResource
{
    /** Version of the sync and backup format. */
    public const SCHEMA_VERSION = 1;

    private const DELETION_GRACE_DAYS = 30;

    public static function respond(User $user): JsonResponse
    {
        $user = $user->fresh();
        $sync = DB::table('user_sync_state')->where('user_id', $user->id)->first();
        $requestedAt = $user->deletion_requested_at;

        return response()->json(['data' => [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'timezone' => $user->timezone,
                'default_currency' => $user->default_currency,
                'is_demo' => $user->is_demo,
            ],
            'deletion' => [
                'requested_at' => self::instant($requestedAt),
                'scheduled_for' => self::instant($requestedAt?->copy()->addDays(self::DELETION_GRACE_DAYS)),
            ],
            'schema_version' => self::SCHEMA_VERSION,
            'server_time' => self::instant(now()),
            'sync' => [
                'last_version' => (int) ($sync->last_version ?? 0),
                'purged_through_version' => (int) ($sync->purged_through_version ?? 0),
            ],
        ]]);
    }

    private static function instant(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
