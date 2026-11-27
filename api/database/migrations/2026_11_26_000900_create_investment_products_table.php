<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 09. Produtos de investimento. Saldo atual e rendimento são derivados. */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('investment_products', <<<'SQL'
            name varchar(80) NOT NULL CHECK (btrim(name) <> ''),
            institution varchar(80),
            type varchar(20) NOT NULL CHECK (type IN ('fixed_income', 'treasury', 'fund', 'stock', 'savings_box', 'pension', 'crypto', 'other')),
            currency char(3) NOT NULL DEFAULT 'BRL' REFERENCES public.currencies (code),
            opening_balance_cents public.cents NOT NULL DEFAULT 0 CHECK (opening_balance_cents >= 0),
            opening_date date,
            goal_amount_cents public.cents CHECK (goal_amount_cents > 0),
            goal_target_date date,
            archived_at timestamptz,
            CONSTRAINT investment_products_opening_date_check CHECK (opening_balance_cents = 0 OR opening_date IS NOT NULL),
            CONSTRAINT investment_products_goal_check CHECK (goal_target_date IS NULL OR goal_amount_cents IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.investment_products');
    }
};
