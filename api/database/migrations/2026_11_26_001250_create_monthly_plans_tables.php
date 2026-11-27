<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 12b. Plano do mês e itens previstos. "Cumprido" é derivado (view v_plan_items). */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('monthly_plans', <<<'SQL'
            month date NOT NULL CHECK (extract(day FROM month) = 1),
            currency char(3) NOT NULL DEFAULT 'BRL' REFERENCES public.currencies (code),
            opening_available_cents public.cents,
            note varchar(255),
            CONSTRAINT monthly_plans_uq UNIQUE (user_id, month, currency)
            SQL);

        FinanceSchema::createSyncTable('monthly_plan_items', <<<'SQL'
            plan_id uuid NOT NULL,
            kind varchar(15) NOT NULL CHECK (kind IN ('income', 'expense', 'investment_in')),
            description varchar(200) NOT NULL CHECK (btrim(description) <> ''),
            amount_cents public.cents NOT NULL CHECK (amount_cents > 0),
            category_id uuid,
            investment_product_id uuid,
            sort_order integer NOT NULL DEFAULT 0,
            CONSTRAINT monthly_plan_items_category_check CHECK (category_id IS NULL OR kind IN ('income', 'expense')),
            CONSTRAINT monthly_plan_items_product_check CHECK (investment_product_id IS NULL OR kind = 'investment_in'),
            CONSTRAINT plan_items_kind_uq UNIQUE (user_id, id, kind)
            SQL);

        FinanceSchema::foreign('monthly_plan_items', 'monthly_plan_items_plan_fk', 'user_id, plan_id', 'monthly_plans', 'user_id, id');
        FinanceSchema::foreign('monthly_plan_items', 'monthly_plan_items_category_fk', 'user_id, category_id, kind', 'categories', 'user_id, id, kind');
        FinanceSchema::foreign('monthly_plan_items', 'monthly_plan_items_product_fk', 'user_id, investment_product_id', 'investment_products', 'user_id, id');

        DB::statement('CREATE INDEX plan_items_idx ON public.monthly_plan_items (user_id, plan_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.monthly_plan_items');
        DB::statement('DROP TABLE IF EXISTS public.monthly_plans');
    }
};
