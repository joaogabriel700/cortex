<?php

namespace App\Auth;

use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "users" is under row level security: a user is only visible inside their own context.
 * The only way to find one by e-mail is the auth_lookup_by_email() database function.
 */
class TenantUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, string $model, private readonly TenantContext $tenant)
    {
        parent::__construct($hasher, $model);
    }

    public function retrieveById($identifier)
    {
        if (! is_string($identifier) || ! Str::isUuid($identifier)) {
            return null;
        }

        return $this->tenant->run($identifier, fn () => parent::retrieveById($identifier));
    }

    public function retrieveByCredentials(array $credentials)
    {
        $found = DB::selectOne('select id from public.auth_lookup_by_email(?)', [(string) ($credentials['email'] ?? '')]);

        return $found === null ? null : $this->retrieveById($found->id);
    }

    /**
     * Checks the password in about the same time whether or not the user exists.
     */
    public function passwordMatches(?Authenticatable $user, #[\SensitiveParameter] string $password): bool
    {
        if ($user === null) {
            $dummy = Cache::rememberForever(
                'auth.dummy-hash.'.config('hashing.bcrypt.rounds'),
                fn () => $this->hasher->make(Str::random(40)),
            );
            $this->hasher->check($password, $dummy);

            return false;
        }

        return $this->hasher->check($password, $user->getAuthPassword());
    }

    /** No persistent login: nobody is retrieved from a "remember me" cookie. */
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        return null;
    }

    /** The framework must not rotate remember_token: that would revoke every session. */
    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
    {
        //
    }

    /** No rehash on login (also disabled in config/hashing.php). */
    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false)
    {
        //
    }
}
