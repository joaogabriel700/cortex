<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 15. Lançamentos: o razão das contas. Valores sempre positivos; o sinal vem de kind. */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('transactions', <<<'SQL'
            kind varchar(15) NOT NULL CHECK (kind IN ('income', 'expense', 'transfer', 'card_payment', 'investment_in', 'investment_out')),
            occurred_on date NOT NULL,
            amount_cents public.cents NOT NULL CHECK (amount_cents > 0),
            description varchar(200) NOT NULL DEFAULT '',
            notes text,
            account_id uuid,
            counter_account_id uuid,
            counter_amount_cents public.cents CHECK (counter_amount_cents > 0),
            category_id uuid,
            card_invoice_id uuid,
            investment_product_id uuid,
            recurrence_occurrence_id uuid,
            plan_item_id uuid,
            CONSTRAINT transactions_plan_item_kind_check CHECK (plan_item_id IS NULL OR kind IN ('income', 'expense', 'investment_in')),
            CONSTRAINT transactions_single_link_check CHECK (num_nonnulls(recurrence_occurrence_id, plan_item_id) <= 1),
            CONSTRAINT transactions_category_check CHECK (category_id IS NULL OR kind IN ('income', 'expense')),
            CONSTRAINT transactions_counter_amount_check CHECK (counter_amount_cents IS NULL OR kind = 'transfer'),
            CONSTRAINT transactions_shape_check CHECK (CASE
                WHEN kind IN ('income', 'expense') THEN account_id IS NOT NULL
                    AND num_nonnulls(counter_account_id, card_invoice_id, investment_product_id) = 0
                WHEN kind = 'transfer' THEN num_nonnulls(account_id, counter_account_id) = 2
                    AND account_id <> counter_account_id
                    AND num_nonnulls(card_invoice_id, investment_product_id) = 0
                WHEN kind = 'card_payment' THEN num_nonnulls(account_id, card_invoice_id) = 2
                    AND num_nonnulls(counter_account_id, investment_product_id) = 0
                WHEN kind IN ('investment_in', 'investment_out') THEN investment_product_id IS NOT NULL
                    AND num_nonnulls(counter_account_id, card_invoice_id) = 0
            END)
            SQL);

        FinanceSchema::foreign('transactions', 'transactions_account_fk', 'user_id, account_id', 'accounts', 'user_id, id');
        FinanceSchema::foreign('transactions', 'transactions_counter_account_fk', 'user_id, counter_account_id', 'accounts', 'user_id, id');
        FinanceSchema::foreign('transactions', 'transactions_category_fk', 'user_id, category_id, kind', 'categories', 'user_id, id, kind');
        FinanceSchema::foreign('transactions', 'transactions_invoice_fk', 'user_id, card_invoice_id', 'card_invoices', 'user_id, id');
        FinanceSchema::foreign('transactions', 'transactions_product_fk', 'user_id, investment_product_id', 'investment_products', 'user_id, id');
        FinanceSchema::foreign('transactions', 'transactions_occurrence_fk', 'user_id, recurrence_occurrence_id', 'recurrence_occurrences', 'user_id, id');
        FinanceSchema::foreign('transactions', 'transactions_plan_item_fk', 'user_id, plan_item_id, kind', 'monthly_plan_items', 'user_id, id, kind');

        DB::statement('CREATE INDEX tx_account_idx ON public.transactions (user_id, account_id, occurred_on) INCLUDE (kind, amount_cents) WHERE deleted_at IS NULL AND account_id IS NOT NULL');
        DB::statement('CREATE INDEX tx_counter_idx ON public.transactions (user_id, counter_account_id, occurred_on) INCLUDE (amount_cents, counter_amount_cents) WHERE deleted_at IS NULL AND counter_account_id IS NOT NULL');
        DB::statement('CREATE INDEX tx_period_idx ON public.transactions (user_id, occurred_on DESC, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX tx_category_idx ON public.transactions (user_id, category_id, occurred_on) WHERE deleted_at IS NULL AND category_id IS NOT NULL');
        DB::statement('CREATE INDEX tx_invoice_idx ON public.transactions (user_id, card_invoice_id) WHERE deleted_at IS NULL AND card_invoice_id IS NOT NULL');
        DB::statement('CREATE INDEX tx_product_idx ON public.transactions (user_id, investment_product_id, occurred_on) WHERE deleted_at IS NULL AND investment_product_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX tx_occurrence_uq ON public.transactions (user_id, recurrence_occurrence_id) WHERE deleted_at IS NULL AND recurrence_occurrence_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX tx_plan_item_uq ON public.transactions (user_id, plan_item_id) WHERE deleted_at IS NULL AND plan_item_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.transactions');
    }
};
