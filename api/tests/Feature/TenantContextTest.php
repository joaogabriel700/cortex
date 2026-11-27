<?php

use App\Models\User;
use App\Support\Database\RoleGuard;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Browser;

beforeEach(function () {
    Cache::flush();

    // Test only routes behind the same stack as the real authenticated ones.
    Route::middleware(['api', 'authenticated'])->group(function () {
        Route::get('/api/test/context', fn () => response()->json([
            'context' => currentTenant(),
            'level' => DB::transactionLevel(),
            'users' => DB::table('users')->pluck('id'),
            'category_owners' => DB::table('categories')->distinct()->pluck('user_id'),
        ]));

        Route::get('/api/test/write/{outcome}', function (string $outcome) {
            DB::table('accounts')->insert([
                'id' => (string) Str::uuid7(), 'user_id' => auth()->id(), 'name' => 'Teste',
                'type' => 'checking', 'currency' => 'BRL', 'opening_date' => '2026-01-01',
            ]);

            return match ($outcome) {
                'ok' => response()->noContent(),
                'refused' => response()->json([], 422),
                'abort' => abort(409),
                'crash' => throw new RuntimeException('secret detail'),
            };
        });
    });
});

function currentTenant(): ?string
{
    return DB::scalar("select nullif(current_setting('app.user_id', true), '')");
}

function signedIn($test): array
{
    $user = User::factory()->createAsTenant();
    app(TenantContext::class)->run($user->id, fn () => DB::table('categories')->insert([
        'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'kind' => 'expense', 'name' => 'Minha',
    ]));
    $browser = new Browser($test);
    $browser->signIn($user->email, UserFactory::PASSWORD)->assertOk();

    return [$user, $browser];
}

function accountsOf(User $user): int
{
    return app(TenantContext::class)->run($user->id, fn () => DB::table('accounts')->count());
}

it('isolates each request by app.user_id and leaves nothing behind', function () {
    [$ana, $anaBrowser] = signedIn($this);
    [$bia, $biaBrowser] = signedIn($this);

    foreach ([[$ana, $anaBrowser], [$bia, $biaBrowser], [$ana, $anaBrowser]] as [$user, $browser]) {
        $browser->get('/api/test/context')->assertOk()->assertExactJson([
            'context' => $user->id,
            'level' => 1,
            'users' => [$user->id],
            'category_owners' => [$user->id],
        ]);

        expect(currentTenant())->toBeNull()
            ->and(DB::transactionLevel())->toBe(0)
            ->and(DB::table('users')->count())->toBe(0)
            ->and(DB::table('categories')->count())->toBe(0);
    }
});

it('commits a successful request', function () {
    [$user, $browser] = signedIn($this);

    $browser->get('/api/test/write/ok')->assertNoContent();

    expect(accountsOf($user))->toBe(1);
});

it('rolls everything back when the request fails', function (string $outcome, int $status) {
    [$user, $browser] = signedIn($this);

    $browser->get("/api/test/write/{$outcome}")->assertStatus($status)->assertDontSee('secret detail');

    expect(DB::transactionLevel())->toBe(0)->and(currentTenant())->toBeNull()->and(accountsOf($user))->toBe(0);
    $browser->get('/api/test/context')->assertOk();
})->with([['refused', 422], ['abort', 409], ['crash', 500]]);

it('never switches user inside a transaction', function () {
    $tenant = app(TenantContext::class);
    [$a, $b] = [(string) Str::uuid7(), (string) Str::uuid7()];

    expect(fn () => $tenant->run($a, fn () => $tenant->run($b, fn () => null)))->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => $tenant->run($a, fn () => null)))->toThrow(LogicException::class)
        ->and($tenant->run($a, fn () => $tenant->run($a, fn () => currentTenant())))->toBe($a)
        ->and(currentTenant())->toBeNull()
        ->and(DB::transactionLevel())->toBe(0)
        ->and($tenant->userId())->toBeNull();
});

it('accepts a new connection only as app_runtime', function () {
    RoleGuard::assertRuntimeRole(DB::connection('pgsql'));
    RoleGuard::assertRuntimeRole(DB::connection('pgsql_infra'));

    config(['database.connections.as_owner' => [
        ...config('database.connections.pgsql'),
        'username' => 'app_owner',
        'password' => getenv('DB_OWNER_PASSWORD'),
    ]]);

    expect(fn () => RoleGuard::assertRuntimeRole(DB::connection('as_owner')))->toThrow(RuntimeException::class, 'not app_runtime');
    DB::purge('as_owner');
});
