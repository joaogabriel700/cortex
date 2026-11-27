<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Use createAsTenant(): a plain create() is refused by row level security.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public const PASSWORD = 'correct-horse-battery';

    protected static ?string $passwordHash;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'name' => fake()->name(),
            // Unique across runs of the same test database, not only inside one process.
            'email' => Str::lower(Str::random(20)).'@example.test',
            'password' => static::$passwordHash ??= Hash::make(self::PASSWORD),
            'locale' => 'pt-BR',
            'timezone' => 'America/Sao_Paulo',
            'default_currency' => 'BRL',
        ];
    }

    /**
     * Creates one user inside their own context, with a remember_token (sessions need it).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createAsTenant(array $attributes = []): User
    {
        $id = (string) Str::uuid7();

        return app(TenantContext::class)->run($id, function () use ($id, $attributes): User {
            $user = $this->createOne(['id' => $id] + $attributes);
            $user->forceFill(['remember_token' => Str::random(60)])->save();

            return $user->fresh();
        });
    }
}
