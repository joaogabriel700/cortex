<?php

use App\Models\User;
use App\Modules\Finance\Accounts\Balances;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Browser;

// owner(), as_(), accountBody(), fieldsOf() and stateOf() come from AccountsCategoriesTest.

beforeEach(fn () => Cache::flush());

function newAccount(Browser $browser, User $user, array $extra = []): string
{
    $body = accountBody($extra + ['opening_balance_cents' => 0]);
    $browser->post('/api/v1/accounts', $body, as_($user))->assertCreated();

    return $body['id'];
}

function txBody(string $account, array $extra = []): array
{
    return $extra + [
        'id' => (string) Str::uuid7(), 'kind' => 'expense', 'occurred_on' => '2026-03-10', 'amount_cents' => 1000,
        'description' => '', 'notes' => null, 'account_id' => $account, 'counter_account_id' => null,
        'counter_amount_cents' => null, 'category_id' => null, 'card_invoice_id' => null,
        'investment_product_id' => null, 'recurrence_occurrence_id' => null, 'plan_item_id' => null,
    ];
}

/** @return array{0: string, 1: array, 2: string} id, body and ETag */
function newTx(Browser $browser, User $user, string $account, array $extra = []): array
{
    $body = txBody($account, $extra);
    $response = $browser->post('/api/v1/transactions', $body, as_($user))->assertCreated();

    return [$body['id'], $body, $response->headers->get('ETag')];
}

function balancesOf(Browser $browser, string $query = 'as_of=2026-12-31'): array
{
    $response = $browser->get("/api/v1/accounts?{$query}")->assertOk();

    return [
        collect($response->json('data'))->pluck('balance_cents', 'id')->all(),
        collect($response->json('meta.totals'))->pluck('cents', 'currency')->all(),
    ];
}

function categoryId(Browser $browser, string $key): string
{
    return collect($browser->get('/api/v1/categories')->json('data'))->firstWhere('system_key', $key)['id'];
}

it('computes balances from income, expense and transfer, per currency', function () {
    [$user, $browser] = owner($this);
    $main = newAccount($browser, $user, ['opening_balance_cents' => 10000]);
    $savings = newAccount($browser, $user);
    $outside = newAccount($browser, $user, ['include_in_total' => false]);
    $dollars = newAccount($browser, $user, ['currency' => 'USD']);

    newTx($browser, $user, $main, ['kind' => 'income', 'amount_cents' => 50000]);
    newTx($browser, $user, $main, ['amount_cents' => 1234]);
    newTx($browser, $user, $main, ['kind' => 'transfer', 'amount_cents' => 20000, 'counter_account_id' => $savings]);
    newTx($browser, $user, $main, ['kind' => 'transfer', 'amount_cents' => 5000, 'counter_account_id' => $outside]);
    newTx($browser, $user, $main, ['kind' => 'transfer', 'amount_cents' => 6000, 'counter_account_id' => $dollars, 'counter_amount_cents' => 1100]);

    [$balances, $totals] = balancesOf($browser);

    expect($balances)->toEqual([$main => 27766, $savings => 20000, $outside => 5000, $dollars => 1100])
        ->and($totals)->toEqual(['BRL' => 47766, 'USD' => 1100]);
});

it('leaves out entries after as_of and before the opening date', function () {
    [$user, $browser] = owner($this);
    $account = newAccount($browser, $user, ['opening_balance_cents' => 100, 'opening_date' => '2026-02-01']);
    newTx($browser, $user, $account, ['kind' => 'income', 'amount_cents' => 999, 'occurred_on' => '2026-01-31']);
    newTx($browser, $user, $account, ['kind' => 'income', 'amount_cents' => 10, 'occurred_on' => '2026-02-01']);
    newTx($browser, $user, $account, ['kind' => 'income', 'amount_cents' => 1, 'occurred_on' => '2026-03-16']);

    expect(balancesOf($browser, 'as_of=2026-03-15')[0][$account])->toBe(110)
        ->and(balancesOf($browser, 'as_of=2026-03-16')[0][$account])->toBe(111);

    $browser->get('/api/v1/accounts?as_of=2026-03-15&history_points=4')->assertOk()
        ->assertJsonPath('meta.as_of', '2026-03-15')
        ->assertJsonPath('data.0.balance_history', [
            ['date' => '2026-02-28', 'cents' => 110], ['date' => '2026-03-15', 'cents' => 110],
        ]);
    $browser->get('/api/v1/accounts?history_points=0')->assertJsonPath('data.0.balance_history', []);
    $browser->get('/api/v1/accounts?as_of=2026-02-30')->assertStatus(422);
    $browser->get('/api/v1/accounts?history_points=25')->assertStatus(422);

    expect(Balances::historyDates('2026-03-31', 3))->toBe(['2026-01-31', '2026-02-28', '2026-03-31']);
});

it('uses the day of the user time zone when as_of is missing', function () {
    [$user, $browser] = owner($this);
    newAccount($browser, $user);
    $this->travelTo('2026-03-10 02:00:00'); // still the 9th in São Paulo

    $browser->get('/api/v1/accounts')->assertJsonPath('meta.as_of', '2026-03-09');
});

it('keeps archived accounts in the totals', function () {
    [$user, $browser] = owner($this);
    newAccount($browser, $user, ['opening_balance_cents' => 700, 'archived_at' => '2026-02-01T00:00:00Z']);

    [$balances, $totals] = balancesOf($browser);

    expect($balances)->toBe([])->and($totals)->toBe(['BRL' => 700]);
});

it('changes and deletes entries, and the balances follow', function () {
    [$user, $browser] = owner($this);
    $from = newAccount($browser, $user, ['opening_balance_cents' => 1000]);
    $to = newAccount($browser, $user);
    [$id, $body, $etag] = newTx($browser, $user, $from, ['kind' => 'transfer', 'amount_cents' => 400, 'counter_account_id' => $to]);
    $url = "/api/v1/transactions/{$id}";

    $changed = $browser->put($url, fieldsOf(['amount_cents' => 300] + $body), as_($user, ['If-Match' => $etag]))->assertOk();
    expect(balancesOf($browser)[0])->toEqual([$from => 700, $to => 300]);

    $browser->delete($url, as_($user))->assertStatus(428);
    $browser->delete($url, as_($user, ['If-Match' => $etag]))->assertStatus(412)->assertJsonPath('current.amount_cents', 300);
    $browser->delete($url, as_($user, ['If-Match' => substr($changed->headers->get('ETag'), 0, -1).'-gzip"']))->assertNoContent();

    expect(balancesOf($browser)[0])->toEqual([$from => 1000, $to => 0]);
    $browser->get($url)->assertNotFound();
    $browser->get('/api/v1/transactions')->assertJsonCount(0, 'data');
    $browser->delete($url, as_($user, ['If-Match' => $etag]))->assertNotFound();
    // The id stays taken: repeating the create does not bring the entry back.
    $browser->post('/api/v1/transactions', $body, as_($user))->assertStatus(409);

    $row = stateOf($user, 'transactions')[0];
    expect($row['deleted_at'])->not->toBeNull()->and($row['sync_version'])->toBeGreaterThan((int) trim($changed->headers->get('ETag'), '"'));
});

it('creates once when the request is repeated', function () {
    [$user, $browser] = owner($this);
    $account = newAccount($browser, $user);
    [, $body] = newTx($browser, $user, $account, ['description' => '  mercado  ', 'notes' => '']);

    $browser->post('/api/v1/transactions', $body, as_($user))->assertOk()->assertJsonPath('data.description', '  mercado  ');

    expect(stateOf($user, 'transactions'))->toHaveCount(1);
});

it('validates entries', function (Closure $change, string $code, ?string $field) {
    [$user, $browser] = owner($this);
    $account = newAccount($browser, $user);
    $dollars = newAccount($browser, $user, ['currency' => 'USD']);
    $body = $change(txBody($account), $account, $dollars, $browser);

    $response = $browser->post('/api/v1/transactions', $body, as_($user))->assertStatus(422)->assertJsonPath('code', $code);

    if ($field !== null) {
        expect(collect($response->json('errors'))->pluck('field')->all())->toContain($field);
    }
    expect(stateOf($user, 'transactions'))->toBe([]);
})->with([
    'zero' => [fn ($b) => ['amount_cents' => 0] + $b, 'validation_failed', 'amount_cents'],
    'negative' => [fn ($b) => ['amount_cents' => -1] + $b, 'validation_failed', 'amount_cents'],
    'cents as text' => [fn ($b) => ['amount_cents' => '10'] + $b, 'validation_failed', 'amount_cents'],
    'null description' => [fn ($b) => ['description' => null] + $b, 'validation_failed', 'description'],
    'card payment' => [fn ($b) => ['kind' => 'card_payment'] + $b, 'validation_failed', 'kind'],
    'investment' => [fn ($b) => ['kind' => 'investment_in'] + $b, 'validation_failed', 'kind'],
    'plan link' => [fn ($b) => ['plan_item_id' => (string) Str::uuid7()] + $b, 'validation_failed', 'plan_item_id'],
    'no account' => [fn ($b) => ['account_id' => null] + $b, 'validation_failed', 'account_id'],
    'expense with destination' => [fn ($b, $a, $usd) => ['counter_account_id' => $usd] + $b, 'validation_failed', 'counter_account_id'],
    'transfer without destination' => [fn ($b) => ['kind' => 'transfer'] + $b, 'validation_failed', 'counter_account_id'],
    'transfer to itself' => [fn ($b, $a) => ['kind' => 'transfer', 'counter_account_id' => $a] + $b, 'validation_failed', 'counter_account_id'],
    'transfer with category' => [fn ($b, $a, $usd) => ['kind' => 'transfer', 'counter_account_id' => $usd, 'counter_amount_cents' => 5, 'category_id' => (string) Str::uuid7()] + $b, 'validation_failed', 'category_id'],
    'currencies without received amount' => [fn ($b, $a, $usd) => ['kind' => 'transfer', 'counter_account_id' => $usd] + $b, 'currency_counter_amount', null],
    'category of the other kind' => [fn ($b, $a, $usd, $browser) => ['category_id' => categoryId($browser, 'income.salary')] + $b, 'invalid_reference', null],
    'unknown account' => [fn ($b) => ['account_id' => (string) Str::uuid7()] + $b, 'invalid_reference', null],
]);

it('refuses a received amount between accounts of the same currency', function () {
    [$user, $browser] = owner($this);
    [$a, $b] = [newAccount($browser, $user), newAccount($browser, $user)];

    $browser->post('/api/v1/transactions', txBody($a, ['kind' => 'transfer', 'counter_account_id' => $b, 'counter_amount_cents' => 1000]), as_($user))
        ->assertStatus(422)->assertJsonPath('code', 'currency_counter_amount');
});

it('filters the statement by period, account, category and kind, newest first', function () {
    [$user, $browser] = owner($this);
    [$a, $b] = [newAccount($browser, $user), newAccount($browser, $user)];
    $food = categoryId($browser, 'expense.food');
    [$jan] = newTx($browser, $user, $a, ['occurred_on' => '2026-01-31']);
    [$feb] = newTx($browser, $user, $a, ['occurred_on' => '2026-02-01', 'category_id' => $food]);
    [$move] = newTx($browser, $user, $a, ['occurred_on' => '2026-02-15', 'kind' => 'transfer', 'counter_account_id' => $b]);
    [$pay] = newTx($browser, $user, $b, ['occurred_on' => '2026-02-28', 'kind' => 'income']);
    $ids = fn (string $query) => collect($browser->get("/api/v1/transactions?{$query}")->assertOk()->json('data'))->pluck('id')->all();

    expect($ids(''))->toBe([$pay, $move, $feb, $jan])
        ->and($ids('from=2026-02-01&to=2026-02-28'))->toBe([$pay, $move, $feb])
        ->and($ids("account_id={$b}"))->toBe([$pay, $move])
        ->and($ids("account_id={$a}&from=2026-02-01"))->toBe([$move, $feb])
        ->and($ids("category_id={$food}"))->toBe([$feb])
        ->and($ids('kind=income&kind=transfer'))->toBe([$pay, $move])
        ->and($ids('kind[]=expense'))->toBe([$feb, $jan])
        ->and($ids('from=2026-03-01&to=2026-01-01'))->toBe([]);
});

it('pages through the statement without skipping or repeating', function () {
    [$user, $browser] = owner($this);
    $account = newAccount($browser, $user);
    foreach (['2026-02-01', '2026-02-02', '2026-02-02', '2026-02-02', '2026-02-03'] as $day) {
        newTx($browser, $user, $account, ['occurred_on' => $day]);
    }
    $all = collect($browser->get('/api/v1/transactions')->json('data'))->pluck('id')->all();
    $seen = [];
    $cursor = null;

    do {
        $page = $browser->get('/api/v1/transactions?limit=2'.($cursor === null ? '' : "&cursor={$cursor}"))->assertOk();
        $seen = [...$seen, ...collect($page->json('data'))->pluck('id')->all()];
        $cursor = $page->json('meta.next_cursor');
    } while ($cursor !== null);

    expect($seen)->toBe($all)->toHaveCount(5);
    foreach (['limit=0', 'limit=201', 'limit=abc', 'cursor=broken', 'from=yesterday', 'account_id=1', 'kind=gift'] as $bad) {
        $browser->get("/api/v1/transactions?{$bad}")->assertStatus(422);
    }
});

it('keeps two users apart on entries and balances (IDOR)', function () {
    [$ana, $anaBrowser] = owner($this);
    [$bia, $biaBrowser] = owner($this);
    $anaAccount = newAccount($anaBrowser, $ana, ['opening_balance_cents' => 5000]);
    $biaAccount = newAccount($biaBrowser, $bia);
    $anaCategory = categoryId($anaBrowser, 'expense.food');
    [$id, $body, $etag] = newTx($anaBrowser, $ana, $anaAccount, ['category_id' => $anaCategory]);
    $url = "/api/v1/transactions/{$id}";
    $before = stateOf($ana, 'transactions');

    $biaBrowser->get($url)->assertNotFound();
    $biaBrowser->put($url, fieldsOf($body), as_($bia, ['If-Match' => $etag]))->assertNotFound();
    $biaBrowser->delete($url, as_($bia, ['If-Match' => $etag]))->assertNotFound();
    $biaBrowser->delete($url, as_($ana, ['If-Match' => $etag]))->assertStatus(409)->assertJsonPath('code', 'user_mismatch');
    $biaBrowser->get('/api/v1/transactions')->assertJsonCount(0, 'data');
    $biaBrowser->get("/api/v1/transactions?account_id={$anaAccount}")->assertJsonCount(0, 'data');
    $biaBrowser->get("/api/v1/transactions?category_id={$anaCategory}")->assertJsonCount(0, 'data');
    $biaBrowser->post('/api/v1/transactions', ['account_id' => $biaAccount, 'category_id' => null] + $body, as_($bia))
        ->assertStatus(409)->assertJsonPath('code', 'write_rejected');

    // Another user's account or category, on either side.
    foreach ([
        txBody($anaAccount),
        txBody($biaAccount, ['kind' => 'transfer', 'counter_account_id' => $anaAccount]),
        txBody($anaAccount, ['kind' => 'transfer', 'counter_account_id' => $biaAccount]),
        txBody($biaAccount, ['category_id' => $anaCategory]),
    ] as $attempt) {
        $biaBrowser->post('/api/v1/transactions', $attempt, as_($bia))->assertStatus(422)->assertJsonPath('code', 'invalid_reference');
    }

    expect(stateOf($ana, 'transactions'))->toBe($before)
        ->and(stateOf($bia, 'transactions'))->toBe([])
        ->and(balancesOf($anaBrowser))->toEqual([[$anaAccount => 4000], ['BRL' => 4000]])
        ->and(balancesOf($biaBrowser))->toEqual([[$biaAccount => 0], ['BRL' => 0]]);
});

it('requires CSRF and X-Cortex-User to delete', function () {
    [$user, $browser] = owner($this);
    [$id, , $etag] = newTx($browser, $user, newAccount($browser, $user));

    $browser->send('DELETE', "/api/v1/transactions/{$id}", [], as_($user, ['If-Match' => $etag]))->assertStatus(419);
    $browser->delete("/api/v1/transactions/{$id}", ['If-Match' => $etag])->assertStatus(422);

    expect(stateOf($user, 'transactions')[0]['deleted_at'])->toBeNull();
});

it('does not touch records of kinds that are not built yet', function () {
    [$user, $browser] = owner($this);
    $account = newAccount($browser, $user);
    $id = (string) Str::uuid7();
    app(TenantContext::class)->run($user->id, function () use ($user, $account, $id) {
        $product = (string) Str::uuid7();
        DB::table('investment_products')->insert(['id' => $product, 'user_id' => $user->id, 'name' => 'CDB', 'type' => 'fixed_income']);
        DB::table('transactions')->insert([
            'id' => $id, 'user_id' => $user->id, 'kind' => 'investment_in', 'amount_cents' => 300,
            'occurred_on' => '2026-03-01', 'account_id' => $account, 'investment_product_id' => $product,
        ]);
    });
    $read = $browser->get("/api/v1/transactions/{$id}")->assertOk();

    $browser->delete("/api/v1/transactions/{$id}", as_($user, ['If-Match' => $read->headers->get('ETag')]))->assertStatus(409);
    $browser->put("/api/v1/transactions/{$id}", fieldsOf(txBody($account)), as_($user, ['If-Match' => $read->headers->get('ETag')]))->assertStatus(409);
    // It still counts in the balance.
    expect(balancesOf($browser)[0][$account])->toBe(-300);
});

it('upgrades a database that still has the old category depth function', function () {
    config(['database.connections.as_owner' => [
        ...config('database.connections.pgsql'), 'username' => 'app_owner', 'password' => getenv('DB_OWNER_PASSWORD'),
    ]]);
    $definition = fn () => DB::selectOne("select pg_get_functiondef('public.check_category_depth()'::regprocedure) as d")->d;
    $current = $definition();
    $default = config('database.default');

    try {
        config(['database.default' => 'as_owner']);
        // The body before the fix.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_category_depth() RETURNS trigger
            LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp
            AS $$ BEGIN PERFORM public.assert_row_context(NEW.user_id); RETURN NULL; END $$
            SQL);
        expect($definition())->not->toBe($current);

        (require database_path('migrations/2026_11_28_000100_refresh_category_depth_function.php'))->up();

        expect($definition())->toBe($current)->toContain('c.deleted_at IS NOT NULL');
    } finally {
        config(['database.default' => $default]);
        DB::purge('as_owner');
    }
});
