<?php

namespace App\Auth;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Finance\DefaultCategoriesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Account creation and password reset, used only by the server commands: there is no sign up. */
class OwnerAccounts
{
    public function __construct(private readonly TenantContext $tenant) {}

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public function findId(string $email): ?string
    {
        return DB::selectOne('select id from public.auth_lookup_by_email(?)', [self::normalizeEmail($email)])?->id;
    }

    public function create(string $name, string $email, #[\SensitiveParameter] string $password, string $locale, string $timezone): string
    {
        $id = (string) Str::uuid7();

        $this->tenant->run($id, function () use ($id, $name, $email, $password, $locale, $timezone): void {
            $user = new User;
            $user->forceFill([
                'id' => $id,
                'name' => $name,
                'email' => self::normalizeEmail($email),
                'password' => $password, // hashed by the model cast
                'locale' => $locale,
                'timezone' => $timezone,
                'default_currency' => 'BRL',
            ])->save();

            // app_runtime may UPDATE remember_token but not INSERT it.
            $user->forceFill(['remember_token' => Str::random(60)])->save();

            DefaultCategoriesSeeder::insertFor($id);
        });

        return $id;
    }

    public function passwordIsCurrent(string $userId, #[\SensitiveParameter] string $password): bool
    {
        return $this->tenant->run($userId, fn () => Hash::check($password, User::query()->findOrFail($userId)->password));
    }

    /** Sets the new password and revokes every session of the user. */
    public function resetPassword(string $userId, #[\SensitiveParameter] string $password): void
    {
        $this->tenant->run($userId, function () use ($userId, $password): void {
            $user = User::query()->findOrFail($userId);
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

            DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', [self::normalizeEmail($user->email)])->delete();
        });

        // Cleanup only: the new remember_token already revoked these sessions (SessionMark).
        DB::connection('pgsql_infra')->table('sessions')->where('user_id', $userId)->delete();
    }
}
