<?php

namespace App\Modules\Finance\Accounts;

use App\Modules\Finance\Support\RecordWriter;
use App\Modules\Finance\Support\Values;
use Illuminate\Support\Facades\DB;

class AccountWriter extends RecordWriter
{
    private const TYPES = ['checking', 'savings', 'cash', 'digital_wallet', 'other'];

    protected function table(): string
    {
        return 'accounts';
    }

    protected function rules(?object $current): array
    {
        return [
            'name' => Values::text(80),
            'type' => Values::oneOf(self::TYPES),
            'institution' => Values::text(80, nullable: true),
            'currency' => fn (mixed $v): ?string => is_string($v) && preg_match('/^[A-Z]{3}$/D', $v) === 1
                && DB::table('currencies')->where('code', $v)->exists() ? null : 'currency',
            'opening_balance_cents' => Values::cents(),
            'opening_date' => Values::date(),
            'include_in_total' => Values::boolean(),
            'sort_order' => Values::int32(),
            'archived_at' => Values::nullableInstant(),
        ];
    }

    public function toArray(object $row): array
    {
        return $this->syncColumns($row) + [
            'name' => $row->name,
            'type' => $row->type,
            'institution' => $row->institution,
            'currency' => $row->currency,
            'opening_balance_cents' => (int) $row->opening_balance_cents,
            'opening_date' => $row->opening_date,
            'include_in_total' => (bool) $row->include_in_total,
            'sort_order' => (int) $row->sort_order,
            'archived_at' => Values::instant($row->archived_at),
        ];
    }
}
