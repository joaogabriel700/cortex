<?php

use Database\Seeders\Finance\DefaultCategories;
use Database\Seeders\Finance\DefaultCategoriesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Finance schema on a real PostgreSQL, as app_runtime. No RefreshDatabase: the deferred
| rules only run at a real COMMIT. Every test creates its own users.
| app_owner is used only to simulate time and the demo flag; the superuser only for R1.
*/

function uid7(): string
{
    return (string) Str::uuid7();
}

function setContext(string $userId): void
{
    DB::select("select set_config('app.user_id', ?, true)", [$userId]);
}

function asUser(string $userId, Closure $callback): mixed
{
    return DB::transaction(function () use ($userId, $callback) {
        setContext($userId);

        return $callback();
    });
}

function newUser(): string
{
    $id = uid7();
    asUser($id, fn () => DB::table('users')->insert([
        'id' => $id, 'name' => 'Test', 'email' => "{$id}@example.test", 'password' => 'hash',
        'created_at' => now(), 'updated_at' => now(),
    ]));

    return $id;
}

/** Inserts a row in the current transaction and returns its id. */
function row(string $table, string $userId, array $values): string
{
    $id = $values['id'] ?? uid7();
    DB::table($table)->insert(['id' => $id, 'user_id' => $userId] + $values);

    return $id;
}

function account(string $u, string $currency = 'BRL', array $extra = []): string
{
    return row('accounts', $u, $extra + [
        'name' => 'Conta', 'type' => 'checking', 'currency' => $currency, 'opening_date' => '2026-01-01',
    ]);
}

function entry(string $u, string $kind, int $cents, array $extra = []): string
{
    return row('transactions', $u, $extra + [
        'kind' => $kind, 'amount_cents' => $cents, 'occurred_on' => '2026-02-10',
    ]);
}

function card(string $u, array $extra = []): string
{
    return row('credit_cards', $u, $extra + ['name' => 'Cartao', 'closing_day' => 28, 'due_day' => 5]);
}

function invoice(string $u, string $card): string
{
    return row('card_invoices', $u, [
        'card_id' => $card, 'reference_month' => '2026-03-01', 'closing_date' => '2026-02-28', 'due_date' => '2026-03-05',
    ]);
}

/** Purchase with its installments, one per amount, all on the same invoice. */
function purchase(string $u, string $card, string $invoice, array $amounts, array $extra = []): array
{
    $id = row('card_purchases', $u, $extra + [
        'card_id' => $card, 'description' => 'Compra', 'purchased_on' => '2026-02-10',
        'total_cents' => array_sum($amounts), 'installment_count' => count($amounts),
    ]);
    $parts = [];
    foreach ($amounts as $i => $cents) {
        $parts[] = row('card_installments', $u, [
            'purchase_id' => $id, 'invoice_id' => $invoice, 'number' => $i + 1, 'amount_cents' => $cents,
        ]);
    }

    return [$id, $parts];
}

function product(string $u, string $currency = 'BRL', array $extra = []): string
{
    return row('investment_products', $u, $extra + ['name' => 'Produto', 'type' => 'fund', 'currency' => $currency]);
}

function recurrence(string $u, string $kind, array $extra): string
{
    return row('recurrences', $u, $extra + [
        'kind' => $kind, 'description' => 'Rec', 'amount_cents' => 100, 'frequency' => 'monthly',
        'day_of_month' => 10, 'start_date' => '2026-01-10',
    ]);
}

function occurrence(string $u, string $recurrence): string
{
    return row('recurrence_occurrences', $u, [
        'recurrence_id' => $recurrence, 'scheduled_on' => '2026-02-10', 'due_on' => '2026-02-10', 'amount_cents' => 100,
    ]);
}

function planItem(string $u, string $kind, array $extra = []): string
{
    $plan = row('monthly_plans', $u, ['month' => '2026-02-01']);

    return row('monthly_plan_items', $u, $extra + [
        'plan_id' => $plan, 'kind' => $kind, 'description' => 'Item', 'amount_cents' => 100,
    ]);
}

function softDelete(string $table, string $id): void
{
    DB::table($table)->where('id', $id)->update(['deleted_at' => now()]);
}

/** Runs the callback on a second connection with another role, then restores the default. */
function asRole(string $role, string $password, Closure $callback): mixed
{
    config(["database.connections.{$role}_test" => array_merge(config('database.connections.pgsql'), [
        'username' => $role, 'password' => $password,
    ])]);

    try {
        return $callback(DB::connection("{$role}_test"));
    } finally {
        DB::purge("{$role}_test");
    }
}

/** What only the owner can do: change a protected users column, bypassing the guard. */
function ownerUpdatesUser(string $userId, string $set): void
{
    asRole('app_owner', (string) getenv('DB_OWNER_PASSWORD'), fn ($owner) => $owner->transaction(function () use ($owner, $userId, $set) {
        $owner->statement('ALTER TABLE users DISABLE TRIGGER users_guard_deletion');
        $owner->statement('ALTER TABLE users NO FORCE ROW LEVEL SECURITY');
        expect($owner->update("UPDATE users SET {$set} WHERE id = ?", [$userId]))->toBe(1);
        $owner->statement('ALTER TABLE users FORCE ROW LEVEL SECURITY');
        $owner->statement('ALTER TABLE users ENABLE TRIGGER users_guard_deletion');
    }));
}

function seedCategories(string $userId): void
{
    (new DefaultCategoriesSeeder)->run($userId);
}

const SYNC_TABLES = [
    'accounts', 'categories', 'credit_cards', 'card_invoices', 'investment_products', 'investment_rates',
    'investment_balance_checks', 'recurrences', 'recurrence_occurrences', 'monthly_plans', 'monthly_plan_items',
    'card_purchases', 'card_installments', 'transactions', 'budgets', 'import_batches', 'import_rows',
];

it('forces row level security on every table with user data', function () {
    $unprotected = DB::select(<<<'SQL'
        SELECT c.relname FROM pg_class c
          JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
         WHERE c.relkind = 'r' AND NOT (c.relrowsecurity AND c.relforcerowsecurity)
           AND (c.relname = 'users' OR EXISTS (SELECT 1 FROM pg_attribute a
                 WHERE a.attrelid = c.oid AND a.attname = 'user_id' AND NOT a.attisdropped))
           AND c.relname NOT IN ('sessions', 'deleted_accounts')
        SQL);

    expect($unprotected)->toBe([]);
});

it('isolates two users: no cross read, write or reference', function () {
    [$a, $b] = [newUser(), newUser()];
    [$accountA, $txA] = asUser($a, function () use ($a) {
        $account = account($a);
        $c = card($a);
        purchase($a, $c, invoice($a, $c), [100, 100]);

        return [$account, entry($a, 'expense', 500, ['account_id' => $account])];
    });

    asUser($b, function () use ($a) {
        foreach ([...SYNC_TABLES, 'user_sync_state'] as $table) {
            expect(DB::table($table)->where('user_id', $a)->count())->toBe(0, "B reads {$table} of A");
        }
        expect(DB::table('users')->where('id', $a)->count())->toBe(0)
            ->and(DB::table('v_account_entries')->where('user_id', $a)->count())->toBe(0)
            ->and(DB::table('v_category_entries')->where('user_id', $a)->count())->toBe(0);
    });

    expect(asUser($b, fn () => DB::table('transactions')->where('id', $txA)->update(['amount_cents' => 1])))->toBe(0);

    expect(fn () => asUser($b, fn () => account($a)))->toThrow(QueryException::class, 'row-level security');
    // Upsert over another user's id and a foreign key to another user's row.
    expect(fn () => asUser($b, fn () => DB::table('accounts')->upsert(
        [['id' => $accountA, 'user_id' => $b, 'name' => 'x', 'type' => 'cash', 'opening_date' => '2026-01-01']],
        ['id'], ['name'],
    )))->toThrow(QueryException::class, '42501');
    expect(fn () => asUser($b, fn () => entry($b, 'expense', 1, ['account_id' => $accountA])))
        ->toThrow(PDOException::class, 'transactions_account_fk');

    // Without a context nothing is visible, also right after a transaction that had one.
    expect(DB::table('accounts')->count())->toBe(0)->and(DB::table('users')->count())->toBe(0);
    expect(asUser($a, fn () => (int) DB::table('transactions')->where('id', $txA)->value('amount_cents')))->toBe(500);
});

/*
| A1: each case prepares valid data and returns the invalid write. Without a context change
| the rule itself rejects it; with another context at COMMIT it must not be approved.
*/
dataset('deferred rules', [
    'category depth' => ['category_depth', function (string $u) {
        $root = row('categories', $u, ['kind' => 'expense', 'name' => 'a']);
        $child = row('categories', $u, ['kind' => 'expense', 'name' => 'b', 'parent_id' => $root]);

        return fn () => row('categories', $u, ['kind' => 'expense', 'name' => 'c', 'parent_id' => $child]);
    }],
    'purchase installments' => ['purchase_installments_mismatch', function (string $u) {
        $c = card($u);
        $i = invoice($u, $c);

        return fn () => purchase($u, $c, $i, [200], ['total_cents' => 300]);
    }],
    'transfer currency' => ['currency_counter_amount', function (string $u) {
        [$brl, $usd] = [account($u), account($u, 'USD')];

        return fn () => entry($u, 'transfer', 100, ['account_id' => $brl, 'counter_account_id' => $usd]);
    }],
    'card currency' => ['currency_mismatch_card_account', function (string $u) {
        $usd = account($u, 'USD');

        return fn () => card($u, ['default_payment_account_id' => $usd]);
    }],
    'recurrence currency' => ['currency_mismatch_investment', function (string $u) {
        [$brl, $p] = [account($u), product($u, 'USD')];

        return fn () => recurrence($u, 'investment_in', ['account_id' => $brl, 'investment_product_id' => $p]);
    }],
    'plan item currency' => ['currency_mismatch_plan_item', function (string $u) {
        $p = product($u, 'USD');

        return fn () => planItem($u, 'investment_in', ['investment_product_id' => $p]);
    }],
    'balance check date' => ['balance_check_before_opening', function (string $u) {
        $p = product($u, 'BRL', ['opening_date' => '2026-01-10']);

        return fn () => row('investment_balance_checks', $u, ['product_id' => $p, 'checked_on' => '2026-01-01', 'balance_cents' => 1]);
    }],
    'transaction link' => ['link_occurrence_twice', function (string $u) {
        $account = account($u);
        $o = occurrence($u, recurrence($u, 'expense', ['account_id' => $account]));
        $c = card($u);
        purchase($u, $c, invoice($u, $c), [100], ['recurrence_occurrence_id' => $o]);

        return fn () => entry($u, 'expense', 100, ['account_id' => $account, 'recurrence_occurrence_id' => $o]);
    }],
    'purchase link' => ['link_plan_item_twice', function (string $u) {
        $item = planItem($u, 'expense');
        entry($u, 'expense', 100, ['account_id' => account($u), 'plan_item_id' => $item]);
        $c = card($u);
        $i = invoice($u, $c);

        return fn () => purchase($u, $c, $i, [100], ['plan_item_id' => $item]);
    }],
    'plan item fulfilment' => ['link_plan_item_mismatch', function (string $u) {
        [$p1, $p2] = [product($u), product($u)];
        $item = planItem($u, 'investment_in', ['investment_product_id' => $p1]);
        entry($u, 'investment_in', 100, ['investment_product_id' => $p1, 'plan_item_id' => $item]);

        return fn () => DB::table('monthly_plan_items')->where('id', $item)->update(['investment_product_id' => $p2]);
    }],
    'invoice protection' => ['invoice_has_live_children', function (string $u) {
        $c = card($u);
        $i = invoice($u, $c);
        entry($u, 'card_payment', 100, ['account_id' => account($u), 'card_invoice_id' => $i]);

        return fn () => softDelete('card_invoices', $i);
    }],
    'recurrence deletion' => ['recurrence_has_live_occurrences', function (string $u) {
        $r = recurrence($u, 'expense', ['account_id' => account($u)]);
        occurrence($u, $r);

        return fn () => softDelete('recurrences', $r);
    }],
]);

function lastVersion(string $userId): int
{
    return asUser($userId, fn () => (int) DB::table('user_sync_state')->where('user_id', $userId)->value('last_version'));
}

it('enforces each deferred rule at commit', function (string $code, Closure $arrange) {
    $u = newUser();
    $write = asUser($u, fn () => $arrange($u));
    $before = lastVersion($u);

    expect(fn () => asUser($u, $write))->toThrow(PDOException::class, $code);
    expect(lastVersion($u))->toBe($before);
})->with('deferred rules');

it('A1: does not approve invalid data when the context changes before COMMIT', function (string $code, Closure $arrange) {
    [$u, $other] = [newUser(), newUser()];
    $write = asUser($u, fn () => $arrange($u));
    $before = lastVersion($u);

    foreach ([$other, ''] as $context) {
        expect(fn () => asUser($u, function () use ($write, $context) {
            $write();
            setContext($context);
        }))->toThrow(PDOException::class, 'tenant_context_changed');
    }

    expect(lastVersion($u))->toBe($before);
})->with('deferred rules');

it('M1: an installment cannot move to another purchase', function () {
    $u = newUser();
    [$parts, $p2] = asUser($u, function () use ($u) {
        $c = card($u);
        $i = invoice($u, $c);
        [, $parts] = purchase($u, $c, $i, [100, 100, 100]);
        [$p2] = purchase($u, $c, $i, [50, 50]);

        return [$parts, $p2];
    });

    expect(fn () => asUser($u, fn () => DB::table('card_installments')->where('id', $parts[2])->update(['purchase_id' => $p2])))
        ->toThrow(QueryException::class, 'column_immutable');
});

it('M3: a deletion request cannot be cancelled after 30 days', function () {
    $u = newUser();
    $set = fn (?string $value) => asUser($u, fn () => DB::table('users')->where('id', $u)->update(['deletion_requested_at' => $value]));

    // Inside the window it can be requested and cancelled; a backdated value becomes "now".
    $set('2000-01-01');
    expect(asUser($u, fn () => DB::selectOne("select deletion_requested_at > now() - interval '1 minute' as fresh from users where id = ?", [$u])->fresh))->toBeTrue();
    $set(null);

    $set(now()->toIso8601String());
    ownerUpdatesUser($u, "deletion_requested_at = now() - interval '31 days'");

    expect(fn () => $set(null))->toThrow(QueryException::class, 'deletion_window_closed');
});

it('M3: the demo account cannot change e-mail or password, nor ask for deletion', function (string $column, string $value) {
    $u = newUser();
    ownerUpdatesUser($u, 'is_demo = true');

    expect(fn () => asUser($u, fn () => DB::table('users')->where('id', $u)->update([$column => $value])))
        ->toThrow(QueryException::class, 'demo_account_locked');
    expect(asUser($u, fn () => DB::table('users')->where('id', $u)->update(['name' => 'Outro'])))->toBe(1);
})->with([['email', 'x@example.test'], ['password', 'other'], ['deletion_requested_at', '2026-01-01']]);

it('R1: the first migration refuses a role that is a member of an application role', function (string $group, string $options, bool $refused) {
    $password = trim((string) file_get_contents('/run/app-secrets/superuser/password'));
    $default = DB::getDefaultConnection();

    asRole('postgres', $password, function ($su) use ($group, $options, $refused, $default) {
        $su->beginTransaction();
        try {
            $su->statement('CREATE ROLE r1_intruder NOLOGIN');
            $su->statement("GRANT {$group} TO r1_intruder WITH {$options}");
            $su->statement('SET LOCAL ROLE app_owner');
            DB::setDefaultConnection('postgres_test');
            $migration = require database_path('migrations/2026_11_26_000100_create_cents_domain.php');

            // Past the preflight, the migration stops at the domain, which is in use.
            expect(fn () => $migration->up())->toThrow(
                QueryException::class, $refused ? 'membro de app_runtime ou app_maintenance' : 'other objects depend on it'
            );
        } finally {
            DB::setDefaultConnection($default);
            $su->rollBack();
        }
    });
})->with([
    ['app_runtime', 'INHERIT TRUE, SET FALSE', true],
    ['app_runtime', 'INHERIT FALSE, SET TRUE', true],
    ['app_maintenance', 'INHERIT TRUE, SET TRUE', true],
    'admin only is tolerated' => ['app_runtime', 'ADMIN TRUE, INHERIT FALSE, SET FALSE', false],
]);

it('R2: system_key cannot change and the category seeder can be repeated', function () {
    $u = newUser();
    seedCategories($u);
    $id = DefaultCategories::idFor($u, 'expense.food');

    foreach (['expense.changed', null] as $key) {
        expect(fn () => asUser($u, fn () => DB::table('categories')->where('id', $id)->update(['system_key' => $key, 'name' => 'x'])))
            ->toThrow(QueryException::class, 'column_immutable');
    }

    asUser($u, fn () => DB::table('categories')->where('id', $id)->update(['name' => 'Mercado', 'deleted_at' => now()]));
    seedCategories($u);

    asUser($u, fn () => expect(DB::table('categories')->count())->toBe(count(DefaultCategories::ITEMS))
        ->and(DB::table('categories')->whereNotNull('deleted_at')->count())->toBe(1));
});

it('M2: the seeder fails when a default category id is already taken', function () {
    [$victim, $squatter] = [newUser(), newUser()];
    asUser($squatter, fn () => row('categories', $squatter, [
        'id' => DefaultCategories::idFor($victim, 'expense.food'), 'kind' => 'expense', 'name' => 'x',
    ]));

    expect(fn () => seedCategories($victim))->toThrow(RuntimeException::class, 'expense.food');
    asUser($victim, fn () => expect(DB::table('categories')->count())->toBe(0));
});

it('runs the base seeder as app_runtime', function () {
    $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
});

it('M5: only imported or matched rows can become purged', function () {
    $u = newUser();
    $pending = asUser($u, function () use ($u) {
        $batch = row('import_batches', $u, ['source_format' => 'csv', 'file_name' => 'a.csv', 'file_sha256' => str_repeat('a', 64)]);

        return row('import_rows', $u, ['batch_id' => $batch, 'row_number' => 1, 'dedup_key' => 'k']);
    });

    expect(fn () => asUser($u, fn () => DB::table('import_rows')->where('id', $pending)->update(['status' => 'purged'])))
        ->toThrow(QueryException::class, 'import_row_never_imported');
});

it('computes the balance per account from its opening date', function () {
    $u = newUser();
    [$main, $savings] = asUser($u, function () use ($u) {
        $main = account($u, 'BRL', ['opening_balance_cents' => 100000, 'sort_order' => 1]);
        $savings = account($u, 'BRL', ['sort_order' => 2]);
        $product = product($u);

        entry($u, 'income', 50000, ['account_id' => $main]);
        entry($u, 'expense', 12345, ['account_id' => $main]);
        entry($u, 'transfer', 20000, ['account_id' => $main, 'counter_account_id' => $savings]);
        entry($u, 'investment_in', 3000, ['account_id' => $main, 'investment_product_id' => $product]);
        entry($u, 'investment_out', 1000, ['account_id' => $main, 'investment_product_id' => $product]);
        $c = card($u);
        $i = invoice($u, $c);
        purchase($u, $c, $i, [9999]);                                  // card purchases do not move accounts
        entry($u, 'card_payment', 700, ['account_id' => $main, 'card_invoice_id' => $i]);
        entry($u, 'expense', 1, ['account_id' => $main, 'occurred_on' => '2025-12-31']); // before the opening date
        entry($u, 'expense', 2, ['account_id' => $main, 'occurred_on' => '2026-12-31']); // after the reference date
        softDelete('transactions', entry($u, 'expense', 4, ['account_id' => $main]));

        return [$main, $savings];
    });

    // Query of financas.md §5.1.
    $rows = asUser($u, fn () => DB::select(<<<'SQL'
        SELECT a.id, a.opening_balance_cents + coalesce(sum(e.signed_cents), 0)::bigint AS balance_cents
        FROM accounts a
        LEFT JOIN v_account_entries e
          ON e.user_id = a.user_id AND e.account_id = a.id
         AND e.occurred_on >= a.opening_date AND e.occurred_on <= ?
        WHERE a.user_id = ? AND a.deleted_at IS NULL
        GROUP BY a.id
        ORDER BY a.sort_order, a.id
        SQL, ['2026-06-30', $u]));

    expect(array_column($rows, 'balance_cents', 'id'))->toEqual([
        $main => 100000 + 50000 - 12345 - 20000 - 3000 + 1000 - 700,
        $savings => 20000,
    ]);
});
