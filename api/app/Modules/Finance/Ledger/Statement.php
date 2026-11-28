<?php

namespace App\Modules\Finance\Ledger;

use App\Modules\Finance\Support\Values;
use App\Support\Http\Fields;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The paginated statement: newest date first, then id (index tx_period_idx). */
class Statement
{
    private const ALL_KINDS = ['income', 'expense', 'transfer', 'card_payment', 'investment_in', 'investment_out'];

    private const ID_FILTERS = ['account_id', 'category_id', 'card_invoice_id', 'investment_product_id'];

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $kinds
     * @return array{rows: list<object>, next_cursor: ?string, limit: int}
     */
    public function page(string $userId, array $query, array $kinds): array
    {
        $errors = [];

        foreach (['from', 'to'] as $name) {
            if (array_key_exists($name, $query) && Values::date()($query[$name]) !== null) {
                $errors[] = [$name, 'date'];
            }
        }

        foreach (self::ID_FILTERS as $name) {
            if (array_key_exists($name, $query) && ! (is_string($query[$name]) && Str::isUuid($query[$name]))) {
                $errors[] = [$name, 'uuid'];
            }
        }

        if (array_diff($kinds, self::ALL_KINDS) !== []) {
            $errors[] = ['kind', 'in'];
        }

        $limit = $query['limit'] ?? '50';

        if (! is_string($limit) || preg_match('/^[1-9]\d{0,2}$/D', $limit) !== 1 || (int) $limit > 200) {
            $errors[] = ['limit', 'out_of_range'];
        }

        $cursor = array_key_exists('cursor', $query) ? $this->decode($query['cursor']) : null;

        if (array_key_exists('cursor', $query) && $cursor === null) {
            $errors[] = ['cursor', 'invalid'];
        }

        if ($errors !== []) {
            throw Fields::failure($errors);
        }

        $limit = (int) $limit;

        $rows = DB::table('transactions')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->when(isset($query['from']), fn (Builder $q) => $q->where('occurred_on', '>=', $query['from']))
            ->when(isset($query['to']), fn (Builder $q) => $q->where('occurred_on', '<=', $query['to']))
            // Either side of a transfer belongs to the account's statement.
            ->when(isset($query['account_id']), fn (Builder $q) => $q->where(fn (Builder $either) => $either
                ->where('account_id', $query['account_id'])->orWhere('counter_account_id', $query['account_id'])))
            ->when(isset($query['category_id']), fn (Builder $q) => $q->where('category_id', $query['category_id']))
            ->when(isset($query['card_invoice_id']), fn (Builder $q) => $q->where('card_invoice_id', $query['card_invoice_id']))
            ->when(isset($query['investment_product_id']), fn (Builder $q) => $q->where('investment_product_id', $query['investment_product_id']))
            ->when($kinds !== [], fn (Builder $q) => $q->whereIn('kind', $kinds))
            ->when($cursor !== null, fn (Builder $q) => $q->where(fn (Builder $after) => $after
                ->where('occurred_on', '<', $cursor[0])
                ->orWhere(fn (Builder $same) => $same->where('occurred_on', $cursor[0])->where('id', '>', $cursor[1]))))
            ->orderByDesc('occurred_on')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get()
            ->all();

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $more ? $rows[count($rows) - 1] : null;

        return [
            'rows' => $rows,
            'next_cursor' => $last === null ? null
                : rtrim(strtr(base64_encode("{$last->occurred_on}|{$last->id}"), '+/', '-_'), '='),
            'limit' => $limit,
        ];
    }

    /** @return array{0: string, 1: string}|null */
    private function decode(mixed $cursor): ?array
    {
        if (! is_string($cursor) || strlen($cursor) > 100) {
            return null;
        }

        $parts = explode('|', (string) base64_decode(strtr($cursor, '-_', '+/'), true));

        return count($parts) === 2 && Values::date()($parts[0]) === null && Str::isUuid($parts[1]) ? $parts : null;
    }
}
