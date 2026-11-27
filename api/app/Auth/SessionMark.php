<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Binds a session to the user's current remember_token. Changing the token (password reset)
 * revokes every session in the same transaction, even if deleting the session rows fails.
 * The token is not used for persistent login.
 */
class SessionMark
{
    private const KEY = 'auth.mark';

    public static function put(Session $session, User $user): void
    {
        $session->put(self::KEY, self::of($user));
    }

    public static function matches(Session $session, User $user): bool
    {
        $stored = $session->get(self::KEY);

        return is_string($stored)
            && (string) $user->getRememberToken() !== ''
            && hash_equals(self::of($user), $stored);
    }

    private static function of(User $user): string
    {
        return hash_hmac('sha256', $user->getAuthIdentifier().'|'.$user->getRememberToken(), (string) config('app.key'));
    }
}
