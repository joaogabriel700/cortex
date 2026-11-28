<?php

use App\Models\User;
use App\Support\Tenancy\CommitFailedException;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Database\Seeders\Finance\DefaultCategoriesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Browser;

beforeEach(fn () => Cache::flush());

/** A signed in user with the default categories. */
function owner($test): array
{
    $user = User::factory()->createAsTenant();
    app(TenantContext::class)->run($user->id, fn () => DefaultCategoriesSeeder::insertFor($user->id));
    $browser = new Browser($test);
    $browser->signIn($user->email, UserFactory::PASSWORD)->assertOk();

    return [$user, $browser];
}

function as_(User $user, array $extra = []): array
{
    return $extra + ['X-Cortex-User' => $user->id];
}

function accountBody(array $extra = []): array
{
    return $extra + [
        'id' => (string) Str::uuid7(), 'name' => 'Conta Aurora', 'type' => 'checking', 'institution' => null,
        'currency' => 'BRL', 'opening_balance_cents' => -12345, 'opening_date' => '2026-01-01',
        'include_in_total' => true, 'sort_order' => 0, 'archived_at' => null,
    ];
}

function categoryBody(array $extra = []): array
{
    return $extra + [
        'id' => (string) Str::uuid7(), 'parent_id' => null, 'kind' => 'expense', 'name' => 'Pets',
        'icon' => null, 'color' => '#AA00ff', 'sort_order' => 20, 'archived_at' => null,
    ];
}

function fieldsOf(array $body): array
{
    return collect($body)->except('id')->all();
}

function stateOf(User $user, string $table): array
{
    return app(TenantContext::class)->run($user->id, fn () => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
}

it('creates, reads, lists, changes and archives an account', function () {
    [$user, $browser] = owner($this);
    $body = accountBody();

    $created = $browser->post('/api/v1/accounts', $body, as_($user));

    $created->assertCreated()
        ->assertJsonPath('data.id', $body['id'])
        ->assertJsonPath('data.opening_balance_cents', -12345)
        ->assertJsonPath('data.opening_date', '2026-01-01')
        ->assertJsonPath('data.deleted_at', null)
        ->assertJsonMissingPath('data.user_id');
    $etag = $created->headers->get('ETag');
    expect($etag)->toBe('"'.$created->json('data.sync_version').'"')
        ->and($created->json('data.created_at'))->toMatch('/^\d{4}-\d\d-\d\dT[\d:]{8}\.\d{6}Z$/');

    $browser->get("/api/v1/accounts/{$body['id']}")->assertOk()->assertHeader('ETag', $etag);
    $browser->get('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data');

    $changed = $browser->put("/api/v1/accounts/{$body['id']}",
        fieldsOf(['name' => 'Carteira', 'archived_at' => '2026-02-01T10:00:00Z'] + $body), as_($user, ['If-Match' => $etag]));

    $changed->assertOk()->assertJsonPath('data.name', 'Carteira')->assertJsonPath('data.archived_at', '2026-02-01T10:00:00.000000Z');
    expect($changed->json('data.sync_version'))->toBeGreaterThan($created->json('data.sync_version'))
        ->and($changed->json('data.updated_at') > $created->json('data.updated_at'))->toBeTrue();
    $browser->get('/api/v1/accounts')->assertJsonCount(0, 'data');
    $browser->get('/api/v1/accounts?include_archived=true')->assertJsonCount(1, 'data');
    $browser->get('/api/v1/accounts?include_archived=yes')->assertStatus(422);
});

it('accepts a repeated create once and refuses the same id with other data', function () {
    [$user, $browser] = owner($this);
    $body = accountBody(['archived_at' => '2026-02-01T10:00:00.5Z']);

    $first = $browser->post('/api/v1/accounts', $body, as_($user))->assertCreated();
    $again = $browser->post('/api/v1/accounts', $body, as_($user))->assertOk();
    $browser->post('/api/v1/accounts', ['name' => 'Outra'] + $body, as_($user))
        ->assertStatus(409)->assertJsonPath('code', 'write_rejected');

    expect($again->json('data'))->toBe($first->json('data'))->and(stateOf($user, 'accounts'))->toHaveCount(1);
});

it('requires the current version to change a record', function () {
    [$user, $browser] = owner($this);
    $body = accountBody();
    $etag = $browser->post('/api/v1/accounts', $body, as_($user))->headers->get('ETag');
    $url = "/api/v1/accounts/{$body['id']}";

    $browser->put($url, fieldsOf($body), as_($user))->assertStatus(428)->assertJsonPath('code', 'precondition_required');
    $browser->put($url, fieldsOf(['name' => 'Um'] + $body), as_($user, ['If-Match' => $etag]))->assertOk();
    $browser->put($url, fieldsOf(['name' => 'Dois'] + $body), as_($user, ['If-Match' => $etag]))
        ->assertStatus(412)->assertJsonPath('code', 'version_conflict')->assertJsonPath('current.name', 'Um');
});

it('accepts the ETag as renamed by the compression of the web server', function (string $suffix) {
    [$user, $browser] = owner($this);
    $body = accountBody();
    $etag = $browser->post('/api/v1/accounts', $body, as_($user))->headers->get('ETag');

    $browser->put("/api/v1/accounts/{$body['id']}", fieldsOf($body), as_($user, ['If-Match' => substr($etag, 0, -1).$suffix.'"']))
        ->assertOk();
    // The same old version, renamed or not, is still a conflict.
    $browser->put("/api/v1/accounts/{$body['id']}", fieldsOf($body), as_($user, ['If-Match' => substr($etag, 0, -1).$suffix.'"']))
        ->assertStatus(412);
})->with(['-gzip', '-zstd']);

it('validates the account strictly', function (array $change, string $field, string $code) {
    [$user, $browser] = owner($this);

    $response = $browser->post('/api/v1/accounts', $change + accountBody(), as_($user));

    $response->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect(collect($response->json('errors'))->pluck('code', 'field')->all())->toBe([$field => $code])
        ->and(stateOf($user, 'accounts'))->toBe([]);
})->with([
    'cents as text' => [['opening_balance_cents' => '100'], 'opening_balance_cents', 'integer'],
    'cents with decimals' => [['opening_balance_cents' => 1.5], 'opening_balance_cents', 'integer'],
    'cents above the limit' => [['opening_balance_cents' => 9007199254740992], 'opening_balance_cents', 'out_of_range'],
    'blank name' => [['name' => '   '], 'name', 'blank'],
    'long name' => [['name' => str_repeat('a', 81)], 'name', 'max'],
    'name with NUL' => [['name' => "a\0b"], 'name', 'invalid'],
    'unknown type' => [['type' => 'credit'], 'type', 'in'],
    'unknown currency' => [['currency' => 'XXX'], 'currency', 'currency'],
    'impossible date' => [['opening_date' => '2026-02-30'], 'opening_date', 'date'],
    'old date' => [['opening_date' => '1899-12-31'], 'opening_date', 'date'],
    'boolean as number' => [['include_in_total' => 1], 'include_in_total', 'boolean'],
    'big order' => [['sort_order' => 3000000000], 'sort_order', 'out_of_range'],
    'local time' => [['archived_at' => '2026-02-01T10:00:00'], 'archived_at', 'instant'],
    'owner from the body' => [['user_id' => '019a1b00-0000-7000-8000-000000000001'], 'user_id', 'prohibited'],
    'server version from the body' => [['sync_version' => 1], 'sync_version', 'prohibited'],
    'dotted unknown field' => [['a.b' => 1], 'a.b', 'prohibited'],
]);

it('requires a UUIDv7 from the client', function () {
    [$user, $browser] = owner($this);

    $browser->post('/api/v1/accounts', accountBody(['id' => (string) Str::uuid()]), as_($user))
        ->assertStatus(422)->assertJsonPath('code', 'invalid_id_version');
});

it('does not change the currency of an account in use', function () {
    [$user, $browser] = owner($this);
    $body = accountBody();
    $etag = $browser->post('/api/v1/accounts', $body, as_($user))->headers->get('ETag');
    app(TenantContext::class)->run($user->id, fn () => DB::table('transactions')->insert([
        'id' => (string) Str::uuid7(), 'user_id' => $user->id, 'kind' => 'expense', 'amount_cents' => 100,
        'occurred_on' => '2026-02-01', 'account_id' => $body['id'],
    ]));

    $browser->put("/api/v1/accounts/{$body['id']}", fieldsOf(['currency' => 'USD'] + $body), as_($user, ['If-Match' => $etag]))
        ->assertStatus(409)->assertJsonPath('code', 'currency_immutable');
});

it('lists the default categories and creates subcategories up to two levels', function () {
    [$user, $browser] = owner($this);

    $browser->get('/api/v1/categories')->assertOk()->assertJsonCount(14, 'data');
    $browser->get('/api/v1/categories?kind=income')->assertJsonCount(4, 'data');
    $browser->get('/api/v1/categories?kind=other')->assertStatus(422);

    $parent = categoryBody();
    $child = categoryBody(['name' => 'Ração', 'parent_id' => $parent['id']]);
    $browser->post('/api/v1/categories', $parent, as_($user))->assertCreated()->assertJsonPath('data.system_key', null);
    $browser->post('/api/v1/categories', $child, as_($user))->assertCreated()->assertJsonPath('data.parent_id', $parent['id']);

    $browser->post('/api/v1/categories', categoryBody(['parent_id' => $child['id']]), as_($user))
        ->assertStatus(422)->assertJsonPath('code', 'category_depth');
    $browser->post('/api/v1/categories', categoryBody(['parent_id' => $parent['id'], 'kind' => 'income']), as_($user))
        ->assertStatus(422)->assertJsonPath('code', 'invalid_reference');

    expect(stateOf($user, 'categories'))->toHaveCount(16);
    // The refused requests left the connection usable.
    $browser->get('/api/v1/categories')->assertJsonCount(16, 'data');
});

it('refuses to nest a category that already has children, or itself', function () {
    [$user, $browser] = owner($this);
    [$a, $b] = [categoryBody(['name' => 'A']), categoryBody(['name' => 'B'])];
    $child = categoryBody(['name' => 'C', 'parent_id' => $a['id']]);
    $etag = $browser->post('/api/v1/categories', $a, as_($user))->headers->get('ETag');
    $browser->post('/api/v1/categories', $b, as_($user))->assertCreated();
    $browser->post('/api/v1/categories', $child, as_($user))->assertCreated();

    foreach ([$b['id'], $a['id']] as $parent) {
        $browser->put("/api/v1/categories/{$a['id']}", fieldsOf(['parent_id' => $parent] + $a), as_($user, ['If-Match' => $etag]))
            ->assertStatus(422)->assertJsonPath('code', 'category_depth');
    }
});

it('renames a default category and keeps kind and system_key', function () {
    [$user, $browser] = owner($this);
    $food = collect($browser->get('/api/v1/categories')->json('data'))->firstWhere('system_key', 'expense.food');
    $fields = collect($food)->only(['parent_id', 'kind', 'name', 'icon', 'color', 'sort_order', 'archived_at'])->all();
    $url = "/api/v1/categories/{$food['id']}";
    $match = fn (array $record) => as_($user, ['If-Match' => '"'.$record['sync_version'].'"']);

    $renamed = $browser->put($url, ['name' => 'Comida'] + $fields, $match($food))->assertOk()->json('data');
    expect($renamed['system_key'])->toBe('expense.food')->and($renamed['name'])->toBe('Comida');

    $browser->put($url, ['kind' => 'income'] + $fields, $match($renamed))->assertStatus(422)->assertJsonPath('code', 'identity_field_immutable');
    $browser->put($url, $fields + ['system_key' => 'expense.other'], $match($renamed))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    $browser->put($url, ['name' => null] + $fields, $match($renamed))->assertOk()->assertJsonPath('data.name', null);
});

it('does not let the client create system categories or unnamed ones', function () {
    [$user, $browser] = owner($this);

    $browser->post('/api/v1/categories', categoryBody() + ['system_key' => 'expense.food'], as_($user))
        ->assertStatus(422)->assertJsonPath('code', 'system_key_reserved');
    $browser->post('/api/v1/categories', categoryBody(['name' => null]), as_($user))
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'name');
});

it('keeps two users apart on every route (IDOR)', function () {
    [$ana, $anaBrowser] = owner($this);
    [$bia, $biaBrowser] = owner($this);
    $account = accountBody();
    $category = categoryBody();
    $accountTag = $anaBrowser->post('/api/v1/accounts', $account, as_($ana))->headers->get('ETag');
    $categoryTag = $anaBrowser->post('/api/v1/categories', $category, as_($ana))->headers->get('ETag');
    $before = [stateOf($ana, 'accounts'), stateOf($ana, 'categories')];

    // Reading and changing another user's record answers exactly like a missing one.
    $strip = fn ($response) => collect($response->assertNotFound()->json())->except('request_id')->all();
    $missing = (string) Str::uuid7();
    expect($strip($biaBrowser->get("/api/v1/accounts/{$account['id']}")))->toBe($strip($biaBrowser->get("/api/v1/accounts/{$missing}")));
    $strip($biaBrowser->put("/api/v1/accounts/{$account['id']}", fieldsOf($account), as_($bia, ['If-Match' => $accountTag])));
    $strip($biaBrowser->put("/api/v1/categories/{$category['id']}", fieldsOf($category), as_($bia, ['If-Match' => $categoryTag])));
    $biaBrowser->get('/api/v1/accounts?include_archived=true')->assertJsonCount(0, 'data');
    expect(collect($biaBrowser->get('/api/v1/categories')->json('data'))->pluck('id'))->not->toContain($category['id']);

    // Taking an id, or hanging a record on a parent, of another user.
    $biaBrowser->post('/api/v1/accounts', $account, as_($bia))->assertStatus(409)->assertJsonPath('code', 'write_rejected');
    $biaBrowser->post('/api/v1/categories', $category, as_($bia))->assertStatus(409)->assertJsonPath('code', 'write_rejected');
    $foreign = $biaBrowser->post('/api/v1/categories', categoryBody(['parent_id' => $category['id']]), as_($bia));
    $unknown = $biaBrowser->post('/api/v1/categories', categoryBody(['parent_id' => $missing]), as_($bia));
    expect($foreign->assertStatus(422)->json('code'))->toBe('invalid_reference')->toBe($unknown->json('code'));

    expect([stateOf($ana, 'accounts'), stateOf($ana, 'categories')])->toBe($before)
        ->and(stateOf($bia, 'accounts'))->toBe([]);
});

it('requires X-Cortex-User on writes, with no effect when it is wrong', function () {
    [$ana, $browser] = owner($this);
    [$bia] = owner($this);
    $body = accountBody();

    $browser->post('/api/v1/accounts', $body)
        ->assertStatus(422)->assertJsonPath('errors.0', ['field' => 'X-Cortex-User', 'code' => 'required', 'message' => 'Campo obrigatório.']);
    $browser->post('/api/v1/accounts', $body, as_($bia))->assertStatus(409)->assertJsonPath('code', 'user_mismatch');
    $browser->put('/api/v1/accounts/'.Str::uuid7(), fieldsOf($body), as_($bia, ['If-Match' => '"1"']))->assertStatus(409);

    expect(stateOf($ana, 'accounts'))->toBe([])->and(stateOf($bia, 'accounts'))->toBe([]);
});

it('requires a session and the CSRF token on writes', function () {
    [$user, $browser] = owner($this);

    $browser->send('POST', '/api/v1/accounts', accountBody(), as_($user))->assertStatus(419);
    (new Browser($this))->get('/api/v1/accounts')->assertUnauthorized();
    (new Browser($this))->get('/api/v1/categories')->assertUnauthorized();
    expect(stateOf($user, 'accounts'))->toBe([]);
});

it('accepts only JSON bodies of at most 64 KB', function () {
    [$user, $browser] = owner($this);
    $headers = as_($user, $browser->csrf());

    $browser->send('POST', '/api/v1/accounts', accountBody(), $headers + ['Content-Type' => 'text/plain'])
        ->assertStatus(415)->assertJsonPath('code', 'unsupported_media_type')->assertHeader('Content-Type', 'application/problem+json');
    $browser->send('POST', '/api/v1/accounts', accountBody(['name' => str_repeat('a', 66000)]), $headers)
        ->assertStatus(413)->assertJsonPath('code', 'payload_too_large')->assertHeader('X-Content-Type-Options', 'nosniff');
    $browser->send('POST', '/api/v1/accounts', accountBody(), $headers + ['Content-Type' => 'application/json; charset=utf-8'])
        ->assertCreated();
    expect(stateOf($user, 'accounts'))->toHaveCount(1);
});

it('judges category depth by the row as it is at COMMIT, ignoring deleted ones', function () {
    $user = User::factory()->createAsTenant();
    $insert = fn (string $id, ?string $parent) => DB::table('categories')->insert([
        'id' => $id, 'user_id' => $user->id, 'kind' => 'expense', 'name' => 'C', 'parent_id' => $parent,
    ]);
    $three = fn () => [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];

    // A third level that is soft deleted before the COMMIT must not fail it.
    [$a, $b, $c] = $three();
    app(TenantContext::class)->run($user->id, function () use ($insert, $a, $b, $c) {
        $insert($a, null);
        $insert($b, $a);
        $insert($c, $b);
        DB::table('categories')->where('id', $c)->update(['deleted_at' => now()]);
    });

    // A live third level still fails, and the error carries no database message.
    [$d, $e, $f] = $three();
    try {
        app(TenantContext::class)->run($user->id, function () use ($insert, $d, $e, $f) {
            $insert($d, null);
            $insert($e, $d);
            $insert($f, $e);
        });
        $this->fail('the COMMIT should have failed');
    } catch (CommitFailedException $error) {
        expect($error->getMessage())->toBe('database commit failed (SQLSTATE P0001)')->and($error->getPrevious())->toBeNull();
    }

    expect(stateOf($user, 'categories'))->toHaveCount(3)->and(DB::transactionLevel())->toBe(0);
});
