<?php

namespace App\Modules\Finance\Ledger;

use App\Modules\Finance\Support\RecordWriter;
use App\Modules\Finance\Support\Values;
use App\Support\Http\Fields;
use App\Support\Http\ProblemException;
use Illuminate\Support\Facades\DB;

/**
 * Income, expense and transfer (one row). Card payments, investments and the links to
 * recurrences and plan items are not accepted yet.
 */
class TransactionWriter extends RecordWriter
{
    public const KINDS = ['income', 'expense', 'transfer'];

    private const LATER_LINKS = ['card_invoice_id', 'investment_product_id', 'recurrence_occurrence_id', 'plan_item_id'];

    protected function table(): string
    {
        return 'transactions';
    }

    protected function rules(?object $current): array
    {
        return [
            'kind' => Values::oneOf(self::KINDS),
            'occurred_on' => Values::date(),
            'amount_cents' => Values::positiveCents(),
            'description' => Values::freeText(200),
            'notes' => Values::freeText(5000, nullable: true),
            'account_id' => Values::nullableUuid(),
            'counter_account_id' => Values::nullableUuid(),
            'counter_amount_cents' => Values::positiveCents(nullable: true),
            'category_id' => Values::nullableUuid(),
        ] + array_fill_keys(self::LATER_LINKS, Values::onlyNull());
    }

    protected function normalize(array $data): array
    {
        foreach (['account_id', 'counter_account_id', 'category_id'] as $field) {
            if (is_string($data[$field])) {
                $data[$field] = strtolower($data[$field]);
            }
        }

        return $data;
    }

    protected function checkReferences(string $userId, string $id, array $data, ?object $current): void
    {
        if ($current !== null) {
            $this->guardDelete($current); // the same limit applies to changing it
        }

        $this->checkShape($data);

        foreach (['account_id', 'counter_account_id'] as $field) {
            // The same answer for a missing account and another user's.
            if ($data[$field] !== null && ! $this->owns('accounts', $userId, $data[$field])) {
                throw new ProblemException('invalid_reference', 422);
            }
        }

        if ($data['category_id'] !== null
            && ! $this->owns('categories', $userId, $data['category_id'], ['kind' => $data['kind']])) {
            throw new ProblemException('invalid_reference', 422);
        }
    }

    /** Records made by stages that do not exist yet must not be changed or removed here. */
    protected function guardDelete(object $current): void
    {
        $linked = array_filter(self::LATER_LINKS, fn (string $link) => $current->{$link} !== null);

        if (! in_array($current->kind, self::KINDS, true) || $linked !== []) {
            throw new ProblemException('write_rejected', 409);
        }
    }

    /** Which fields each kind requires and forbids (financas.md §3.7). */
    private function checkShape(array $data): void
    {
        $transfer = $data['kind'] === 'transfer';
        $errors = [];

        if ($data['account_id'] === null) {
            $errors[] = ['account_id', 'required'];
        }

        if ($transfer) {
            if ($data['counter_account_id'] === null) {
                $errors[] = ['counter_account_id', 'required'];
            } elseif ($data['counter_account_id'] === $data['account_id']) {
                $errors[] = ['counter_account_id', 'same_account'];
            }

            if ($data['category_id'] !== null) {
                $errors[] = ['category_id', 'prohibited'];
            }
        } else {
            foreach (['counter_account_id', 'counter_amount_cents'] as $field) {
                if ($data[$field] !== null) {
                    $errors[] = [$field, 'prohibited'];
                }
            }
        }

        if ($errors !== []) {
            throw Fields::failure($errors);
        }
    }

    private function owns(string $table, string $userId, string $id, array $where = []): bool
    {
        return DB::table($table)->where('user_id', $userId)->where('id', $id)
            ->whereNull('deleted_at')->where($where)->exists();
    }

    public function toArray(object $row): array
    {
        return $this->syncColumns($row) + [
            'kind' => $row->kind,
            'occurred_on' => $row->occurred_on,
            'amount_cents' => (int) $row->amount_cents,
            'description' => $row->description,
            'notes' => $row->notes,
            'account_id' => $row->account_id,
            'counter_account_id' => $row->counter_account_id,
            'counter_amount_cents' => $row->counter_amount_cents === null ? null : (int) $row->counter_amount_cents,
            'category_id' => $row->category_id,
            'card_invoice_id' => $row->card_invoice_id,
            'investment_product_id' => $row->investment_product_id,
            'recurrence_occurrence_id' => $row->recurrence_occurrence_id,
            'plan_item_id' => $row->plan_item_id,
        ];
    }
}
