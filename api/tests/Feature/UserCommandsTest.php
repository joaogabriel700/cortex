<?php

use App\Auth\OwnerAccounts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Browser;

beforeEach(fn () => Cache::flush());

const NEW_PASSWORD = 'a-long-owner-password';

function createThroughCommand($test, string $email, string $password = NEW_PASSWORD, ?string $confirmation = null)
{
    return $test->artisan('cortex:user:create')
        ->expectsQuestion('Nome', 'Dona da Conta')
        ->expectsQuestion('E-mail', $email)
        ->expectsQuestion('Nova senha (12 a 72 caracteres)', $password)
        ->expectsQuestion('Repita a senha', $confirmation ?? $password);
}

function randomEmail(): string
{
    return Str::lower(Str::random(16)).'@example.test';
}

it('creates a user who can sign in, with the default categories', function () {
    $email = randomEmail();

    createThroughCommand($this, '  '.Str::upper($email).' ')
        ->expectsOutput('Usuário criado.')
        ->doesntExpectOutputToContain(NEW_PASSWORD)
        ->assertSuccessful();

    $id = app(OwnerAccounts::class)->findId($email);
    [$user, $categories] = app(TenantContext::class)->run($id, fn () => [
        DB::table('users')->where('id', $id)->first(), DB::table('categories')->count(),
    ]);

    expect($user->email)->toBe($email)
        ->and($user->password)->not->toBe(NEW_PASSWORD)->toStartWith('$2y$')
        ->and(strlen($user->remember_token))->toBe(60)
        ->and($user->is_demo)->toBeFalse()
        ->and($categories)->toBe(14);

    (new Browser($this))->signIn($email, NEW_PASSWORD)->assertOk();
});

it('refuses a password outside the policy or typed differently', function (string $password, ?string $confirmation) {
    $email = randomEmail();

    createThroughCommand($this, $email, $password, $confirmation)
        ->doesntExpectOutputToContain($password)
        ->assertFailed();

    expect(app(OwnerAccounts::class)->findId($email))->toBeNull();
})->with([
    '11 characters' => ['abcdefghijk', null],
    '73 bytes' => [str_repeat('a', 73), null],
    '37 accented characters are 74 bytes' => [str_repeat('é', 37), null],
    'different confirmation' => ['a-long-owner-password', 'a-long-owner-passwore'],
]);

it('does not create the same e-mail twice', function () {
    $email = randomEmail();
    createThroughCommand($this, $email)->assertSuccessful();

    $this->artisan('cortex:user:create')
        ->expectsQuestion('Nome', 'Outra')
        ->expectsQuestion('E-mail', Str::upper($email))
        ->expectsOutput('Já existe um usuário com esse e-mail.')
        ->assertFailed();
});

it('takes the password only from the hidden prompt', function (string $command) {
    $definition = Artisan::all()[$command]->getDefinition();
    $inputs = [...array_keys($definition->getArguments()), ...array_keys($definition->getOptions())];

    expect(array_filter($inputs, fn ($name) => str_contains($name, 'pass') || str_contains($name, 'senha')))->toBe([]);
})->with(['cortex:user:create', 'cortex:user:reset-password']);

it('refuses to run without an interactive terminal', function () {
    $this->artisan('cortex:user:reset-password', ['email' => 'nobody@example.test', '--no-interaction' => true])->assertFailed();
});

it('resets the password and signs the user out everywhere', function () {
    $email = randomEmail();
    createThroughCommand($this, $email)->assertSuccessful();
    $browser = new Browser($this);
    $browser->signIn($email, NEW_PASSWORD)->assertOk();
    $id = app(OwnerAccounts::class)->findId($email);
    DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'x', 'created_at' => now()]);

    $reset = fn (string $password) => $this->artisan('cortex:user:reset-password', ['email' => $email])
        ->expectsQuestion('Nova senha (12 a 72 caracteres)', $password)
        ->expectsQuestion('Repita a senha', $password);

    $reset(NEW_PASSWORD)->expectsOutput('A nova senha precisa ser diferente da atual.')->assertFailed();
    $browser->get('/api/v1/auth/session')->assertOk();

    $reset('another-long-password')->doesntExpectOutputToContain('another-long-password')->assertSuccessful()->run();

    expect(DB::table('sessions')->where('user_id', $id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $email)->count())->toBe(0);
    $browser->get('/api/v1/auth/session')->assertUnauthorized();
    (new Browser($this))->signIn($email, NEW_PASSWORD)->assertStatus(422);
    (new Browser($this))->signIn($email, 'another-long-password')->assertOk();
});

it('reports an unknown e-mail', function () {
    $this->artisan('cortex:user:reset-password', ['email' => 'nobody@example.test'])
        ->expectsOutput('Usuário não encontrado.')
        ->assertFailed();
});
