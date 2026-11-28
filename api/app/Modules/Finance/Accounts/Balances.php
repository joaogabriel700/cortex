<?php

namespace App\Modules\Finance\Accounts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Balances are never stored (financas.md §5.1): opening balance plus the effects from
 * opening_date up to the reference date. Future entries are left out.
 */
class Balances
{
    /**
     * Balance of every live account of the user at each date.
     *
     * @param  non-empty-list<string>  $dates
     * @return array<string, array<string, int>> account id => date => cents
     */
    public function at(string $userId, array $dates): array
    {
        $values = implode(', ', array_fill(0, count($dates), '(?::date)'));

        $rows = DB::select(<<<SQL
            SELECT a.id, d.day::text AS day,
                   (a.opening_balance_cents + coalesce(sum(e.signed_cents), 0))::bigint AS balance_cents
              FROM accounts a
             CROSS JOIN (VALUES {$values}) AS d(day)
              LEFT JOIN v_account_entries e
                ON e.user_id = a.user_id AND e.account_id = a.id
               AND e.occurred_on >= a.opening_date AND e.occurred_on <= d.day
             WHERE a.user_id = ? AND a.deleted_at IS NULL
             GROUP BY a.id, d.day
            SQL, [...$dates, $userId]);

        $balances = [];

        foreach ($rows as $row) {
            $balances[$row->id][$row->day] = (int) $row->balance_cents;
        }

        return $balances;
    }

    /**
     * Totals per currency of the accounts marked include_in_total, archived ones included:
     * they still hold money. Currencies are never added together.
     *
     * @param  array<string, array<string, int>>  $balances
     * @return list<array{currency: string, cents: int}>
     */
    public function totals(string $userId, array $balances, string $asOf): array
    {
        $totals = [];

        $accounts = DB::table('accounts')->where('user_id', $userId)->whereNull('deleted_at')
            ->where('include_in_total', true)->orderBy('currency')->get(['id', 'currency']);

        foreach ($accounts as $account) {
            $totals[$account->currency] = ($totals[$account->currency] ?? 0) + $balances[$account->id][$asOf];
        }

        return array_map(
            fn (string $currency, int $cents) => ['currency' => $currency, 'cents' => $cents],
            array_keys($totals), array_values($totals),
        );
    }

    /**
     * $points dates ending at $asOf: the ends of the previous months, oldest first, then $asOf.
     *
     * @return list<string>
     */
    public static function historyDates(string $asOf, int $points): array
    {
        if ($points === 0) {
            return [];
        }

        $month = CarbonImmutable::parse($asOf, 'UTC')->startOfMonth();
        $dates = [$asOf];

        for ($back = 0; $back < $points - 1; $back++) {
            array_unshift($dates, $month->subMonthsNoOverflow($back)->subDay()->toDateString());
        }

        return $dates;
    }
}
