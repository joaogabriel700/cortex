<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 11. Recorrências. */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('recurrences', <<<'SQL'
            kind varchar(15) NOT NULL CHECK (kind IN ('income', 'expense', 'transfer', 'investment_in')),
            description varchar(200) NOT NULL,
            amount_cents public.cents NOT NULL CHECK (amount_cents > 0),
            frequency varchar(7) NOT NULL CHECK (frequency IN ('weekly', 'monthly', 'yearly')),
            interval_count smallint NOT NULL DEFAULT 1 CHECK (interval_count BETWEEN 1 AND 60),
            day_of_month smallint CHECK (day_of_month BETWEEN 1 AND 31),
            start_date date NOT NULL,
            end_date date,
            account_id uuid,
            counter_account_id uuid,
            card_id uuid,
            investment_product_id uuid,
            category_id uuid,
            CONSTRAINT recurrences_period_check CHECK (end_date >= start_date),
            CONSTRAINT recurrences_weekly_day_check CHECK ((frequency = 'weekly') = (day_of_month IS NULL)),
            CONSTRAINT recurrences_category_check CHECK (category_id IS NULL OR kind IN ('income', 'expense')),
            CONSTRAINT recurrences_destination_check CHECK (CASE kind
                WHEN 'income' THEN account_id IS NOT NULL
                    AND num_nonnulls(counter_account_id, card_id, investment_product_id) = 0
                WHEN 'expense' THEN num_nonnulls(account_id, card_id) = 1
                    AND num_nonnulls(counter_account_id, investment_product_id) = 0
                WHEN 'transfer' THEN num_nonnulls(account_id, counter_account_id) = 2
                    AND account_id <> counter_account_id
                    AND num_nonnulls(card_id, investment_product_id) = 0
                WHEN 'investment_in' THEN investment_product_id IS NOT NULL
                    AND num_nonnulls(counter_account_id, card_id) = 0
            END)
            SQL);

        FinanceSchema::foreign('recurrences', 'recurrences_account_fk', 'user_id, account_id', 'accounts', 'user_id, id');
        FinanceSchema::foreign('recurrences', 'recurrences_counter_account_fk', 'user_id, counter_account_id', 'accounts', 'user_id, id');
        FinanceSchema::foreign('recurrences', 'recurrences_card_fk', 'user_id, card_id', 'credit_cards', 'user_id, id');
        FinanceSchema::foreign('recurrences', 'recurrences_product_fk', 'user_id, investment_product_id', 'investment_products', 'user_id, id');
        FinanceSchema::foreign('recurrences', 'recurrences_category_fk', 'user_id, category_id, kind', 'categories', 'user_id, id, kind');

        DB::statement('CREATE INDEX recurrences_active_idx ON public.recurrences (user_id, start_date) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.recurrences');
    }
};
