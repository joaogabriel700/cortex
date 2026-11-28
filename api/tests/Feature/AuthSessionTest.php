<?php

use App\Auth\TenantUserProvider;
use App\Models\User;
use App\Providers\AuthServiceProvider;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\Browser;

beforeEach(fn () => Cache::flush()); // rate limit counters live in the cache table

function person(array $attributes = []): User
{
    return User::factory()->createAsTenant($attributes);
}

function stored(User $user): object
{
    return app(TenantContext::class)->run($user->id, fn () => DB::table('users')->where('id', $user->id)->first());
}

function sessionRows(User $user): int
{
    return DB::connection('pgsql_infra')->table('sessions')->where('user_id', $user->id)->count();
}

it('signs in and returns the session of the contract', function () {
    $user = person();
    $browser = new Browser($this);

    $response = $browser->signIn(Str::upper($user->email), UserFactory::PASSWORD);

    $response->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.is_demo', false)
        ->assertJsonPath('data.deletion', ['requested_at' => null, 'scheduled_for' => null])
        ->assertJsonPath('data.schema_version', 1)
        ->assertJsonPath('data.sync', ['last_version' => 0, 'purged_through_version' => 0])
        ->assertJsonMissingPath('data.user.password');

    $cookie = collect($response->headers->getCookies())->keyBy->getName();
    expect($cookie->get('cortex_session')->isHttpOnly())->toBeTrue()
        ->and($cookie->get('cortex_session')->getSameSite())->toBe('lax')
        ->and($cookie->keys()->filter(fn ($name) => str_starts_with($name, 'remember_')))->toBeEmpty()
        ->and(sessionRows($user))->toBe(1);

    $browser->get('/api/v1/auth/session')->assertOk()->assertJsonPath('data.user.id', $user->id);
});

it('changes the session id on sign in and destroys the old one', function () {
    $user = person();
    $browser = new Browser($this);
    $browser->get('/sanctum/csrf-cookie');
    $before = $browser->cookies;

    $browser->post('/api/v1/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertOk();

    expect($browser->cookies['cortex_session'])->not->toBe($before['cortex_session']);

    $stale = new Browser($this);
    $stale->cookies = $before;
    $stale->get('/api/v1/auth/session')->assertUnauthorized();
});

it('answers a wrong password and an unknown e-mail the same way', function () {
    $user = person();

    $wrong = (new Browser($this))->signIn($user->email, 'not-the-password');
    $unknown = (new Browser($this))->signIn('nobody@example.test', 'not-the-password');

    foreach ([$wrong, $unknown] as $response) {
        $response->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
    }

    $strip = fn ($response) => collect($response->json())->except('request_id')->all();
    expect($strip($wrong))->toBe($strip($unknown))->toBe([
        'type' => 'urn:cortex:problem:invalid_credentials',
        'title' => 'E-mail ou senha incorretos',
        'status' => 422,
        'code' => 'invalid_credentials',
    ]);
    expect(sessionRows($user))->toBe(0);
});

it('checks a password even when the user does not exist', function () {
    $provider = auth()->createUserProvider('users');

    expect($provider)->toBeInstanceOf(TenantUserProvider::class)
        ->and($provider->passwordMatches(null, 'whatever-password'))->toBeFalse()
        ->and(Hash::info(Cache::get('auth.dummy-hash.'.config('hashing.bcrypt.rounds')))['options']['cost'])
        ->toBe((int) config('hashing.bcrypt.rounds'));
});

it('validates the fields, refuses unknown ones and never echoes the password', function () {
    $browser = new Browser($this);
    $browser->get('/sanctum/csrf-cookie');

    $response = $browser->post('/api/v1/auth/login', ['email' => 'x', 'remember' => true], ['Accept-Language' => 'en']);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('title', 'Check the submitted data')
        ->assertHeader('Content-Language', 'en');
    expect(collect($response->json('errors'))->pluck('code', 'field')->all())
        ->toBe(['email' => 'email', 'password' => 'required', 'remember' => 'prohibited']);

    $browser->post('/api/v1/auth/login', ['email' => ['a'], 'password' => str_repeat('s3cret-', 40)])
        ->assertStatus(422)->assertDontSee('s3cret-');
});

it('never rewrites the password hash on sign in', function () {
    // A hash with another cost is what would trigger a rehash.
    $hash = password_hash(UserFactory::PASSWORD, PASSWORD_BCRYPT, ['cost' => 5]);
    $user = person();
    app(TenantContext::class)->run($user->id, fn () => DB::table('users')->where('id', $user->id)->update(['password' => $hash]));
    config(['hashing.bcrypt.verify' => false]);

    (new Browser($this))->signIn($user->email, UserFactory::PASSWORD)->assertOk();

    expect(config('hashing.rehash_on_login'))->toBeFalse()
        ->and(stored($user)->password)->toBe($hash);
});

it('fills a missing remember_token once and never replaces it', function () {
    $user = person();
    app(TenantContext::class)->run($user->id, fn () => DB::table('users')->where('id', $user->id)->update(['remember_token' => null]));

    $first = new Browser($this);
    $first->signIn($user->email, UserFactory::PASSWORD)->assertOk();
    $token = stored($user)->remember_token;
    (new Browser($this))->signIn($user->email, UserFactory::PASSWORD)->assertOk();

    expect($token)->not->toBeNull()->and(stored($user)->remember_token)->toBe($token);
    $first->get('/api/v1/auth/session')->assertOk();
});

it('requires the CSRF token on every write', function (array $headers) {
    $user = person();
    $browser = new Browser($this);
    $browser->get('/sanctum/csrf-cookie');
    $credentials = ['email' => $user->email, 'password' => UserFactory::PASSWORD];

    $browser->send('POST', '/api/v1/auth/login', $credentials, $headers)
        ->assertStatus(419)->assertJsonPath('code', 'csrf_token_mismatch');

    expect(sessionRows($user))->toBe(0);

    $browser->post('/api/v1/auth/login', $credentials)->assertOk();
    $browser->send('POST', '/api/v1/auth/logout', [], $headers)->assertStatus(419);
    $browser->get('/api/v1/auth/session')->assertOk();
})->with([
    'no token' => [[]],
    'wrong token' => [['X-XSRF-TOKEN' => 'forged']],
    // The framework alone would let this one through.
    'same origin without a token' => [['Sec-Fetch-Site' => 'same-origin']],
]);

it('gives no session to requests that do not come from the web app', function (?string $origin) {
    $user = person();

    (new Browser($this, $origin))->send('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
        ->assertForbidden()->assertJsonPath('code', 'forbidden');

    $signedIn = new Browser($this);
    $signedIn->signIn($user->email, UserFactory::PASSWORD)->assertOk();
    $foreign = new Browser($this, $origin);
    $foreign->cookies = $signedIn->cookies;
    $foreign->get('/api/v1/auth/session')->assertUnauthorized();
})->with(['no origin' => [null], 'another origin' => ['https://example.com']]);

it('answers 401 without a session, also to a bearer token', function () {
    $this->getJson('/api/v1/auth/session')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated')
        ->assertHeader('Content-Type', 'application/problem+json');

    (new Browser($this))->get('/api/v1/auth/session', ['Authorization' => 'Bearer 1|forged'])->assertUnauthorized();
});

it('ends a session after 14 days without use, renewing it on every use', function () {
    $user = person();
    $browser = new Browser($this);
    $browser->signIn($user->email, UserFactory::PASSWORD)->assertOk();

    expect(config('session.lifetime'))->toBe(14 * 24 * 60)->and(config('session.expire_on_close'))->toBeFalse();

    // Two gaps just under the limit: 27 days in total, still signed in.
    foreach ([1, 2] as $ignored) {
        $this->travel(14 * 24 * 60 - 5)->minutes();
        $browser->get('/api/v1/auth/session')->assertOk();
    }

    $this->travel(14 * 24 * 60 + 5)->minutes();
    $browser->get('/api/v1/auth/session')->assertUnauthorized();
});

it('signs out only the current device', function () {
    $user = person();
    [$laptop, $phone] = [new Browser($this), new Browser($this)];
    $laptop->signIn($user->email, UserFactory::PASSWORD)->assertOk();
    $phone->signIn($user->email, UserFactory::PASSWORD)->assertOk();
    $token = stored($user)->remember_token;
    $oldCookies = $laptop->cookies;

    $laptop->post('/api/v1/auth/logout')->assertNoContent();

    $laptop->get('/api/v1/auth/session')->assertUnauthorized();
    $replay = new Browser($this);
    $replay->cookies = $oldCookies;
    $replay->get('/api/v1/auth/session')->assertUnauthorized();
    $phone->get('/api/v1/auth/session')->assertOk();
    expect(stored($user)->remember_token)->toBe($token)->and(sessionRows($user))->toBe(1);
});

it('revokes every session when remember_token changes, even if the rows remain', function () {
    $user = person();
    $browser = new Browser($this);
    $browser->signIn($user->email, UserFactory::PASSWORD)->assertOk();

    app(TenantContext::class)->run($user->id, fn () => DB::table('users')->where('id', $user->id)->update(['remember_token' => Str::random(60)]));

    expect(sessionRows($user))->toBe(1);
    $browser->get('/api/v1/auth/session')->assertUnauthorized();
});

it('ends the previous session when another user signs in on the same device', function () {
    [$first, $second] = [person(), person()];
    $browser = new Browser($this);
    $browser->signIn($first->email, UserFactory::PASSWORD)->assertOk();

    $browser->post('/api/v1/auth/login', ['email' => $second->email, 'password' => UserFactory::PASSWORD])->assertOk();

    $browser->get('/api/v1/auth/session')->assertJsonPath('data.user.id', $second->id);
    expect(sessionRows($first))->toBe(0);
});

it('limits sign in to 5 attempts a minute per e-mail and address', function () {
    $user = person();
    $browser = new Browser($this);

    foreach ([$user->email, Str::upper($user->email), " {$user->email} ", $user->email, $user->email] as $email) {
        $browser->signIn($email, 'not-the-password')->assertStatus(422);
    }

    $blocked = $browser->signIn($user->email, UserFactory::PASSWORD);
    $blocked->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After');

    // Another address is not blocked by it.
    (new Browser($this, ip: '203.0.113.9'))->signIn($user->email, UserFactory::PASSWORD)->assertOk();

    $this->travel(61)->seconds();
    $browser->signIn($user->email, UserFactory::PASSWORD)->assertOk();
});

it('limits sign in to 20 attempts a minute per address', function () {
    $browser = new Browser($this, ip: '198.51.100.7');

    foreach (range(1, 20) as $i) {
        $browser->signIn("nobody{$i}@example.test", 'not-the-password')->assertStatus(422);
    }

    $browser->signIn('nobody21@example.test', 'not-the-password')->assertStatus(429);
});

it('counts a whole IPv6 /64 as one address', function () {
    expect(AuthServiceProvider::network('2001:db8:1:2:aaaa::1'))->toBe(AuthServiceProvider::network('2001:db8:1:2:ffff::9'))
        ->and(AuthServiceProvider::network('2001:db8:1:3::1'))->not->toBe(AuthServiceProvider::network('2001:db8:1:2::1'))
        ->and(AuthServiceProvider::network('::ffff:203.0.113.5'))->toBe('203.0.113.5')
        ->and(AuthServiceProvider::network('203.0.113.5'))->toBe('203.0.113.5');
});

it('exposes only the routes built so far', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'))
        ->map(fn ($route) => $route->methods()[0].' '.$route->uri());

    expect($routes->sort()->values()->all())->toBe([
        'GET api/v1/accounts', 'GET api/v1/accounts/{id}', 'GET api/v1/auth/session', 'GET api/v1/categories',
        'POST api/v1/accounts', 'POST api/v1/auth/login', 'POST api/v1/auth/logout', 'POST api/v1/categories',
        'PUT api/v1/accounts/{id}', 'PUT api/v1/categories/{id}',
    ]);
});
