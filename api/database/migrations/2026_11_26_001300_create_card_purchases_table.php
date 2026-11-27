<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/**
 * 13. Compras no cartão.
 * category_kind precisa ser STORED: no PostgreSQL 18 o padrão é VIRTUAL,
 * e coluna virtual não pode participar de FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('card_purchases', <<<'SQL'
            card_id uuid NOT NULL,
            category_id uuid,
            kind varchar(8) NOT NULL DEFAULT 'purchase' CHECK (kind IN ('purchase', 'refund')),
            category_kind varchar(7) GENERATED ALWAYS AS ('expense') STORED,
            description varchar(200) NOT NULL,
            purchased_on date NOT NULL,
            total_cents public.cents NOT NULL CHECK (total_cents > 0),
            installment_count smallint NOT NULL DEFAULT 1 CHECK (installment_count BETWEEN 1 AND 120),
            first_installment_number smallint NOT NULL DEFAULT 1,
            recurrence_occurrence_id uuid,
            plan_item_id uuid,
            CONSTRAINT card_purchases_plan_item_kind_check CHECK (plan_item_id IS NULL OR kind = 'purchase'),
            CONSTRAINT card_purchases_single_link_check CHECK (num_nonnulls(recurrence_occurrence_id, plan_item_id) <= 1),
            CONSTRAINT card_purchases_first_installment_check CHECK (first_installment_number BETWEEN 1 AND installment_count)
            SQL);

        FinanceSchema::foreign('card_purchases', 'card_purchases_card_fk', 'user_id, card_id', 'credit_cards', 'user_id, id');
        FinanceSchema::foreign('card_purchases', 'card_purchases_category_fk', 'user_id, category_id, category_kind', 'categories', 'user_id, id, kind');
        FinanceSchema::foreign('card_purchases', 'card_purchases_occurrence_fk', 'user_id, recurrence_occurrence_id', 'recurrence_occurrences', 'user_id, id');
        FinanceSchema::foreign('card_purchases', 'card_purchases_plan_item_fk', 'user_id, plan_item_id, category_kind', 'monthly_plan_items', 'user_id, id, kind');

        DB::statement('CREATE UNIQUE INDEX purchase_occurrence_uq ON public.card_purchases (user_id, recurrence_occurrence_id) WHERE deleted_at IS NULL AND recurrence_occurrence_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX purchase_plan_item_uq ON public.card_purchases (user_id, plan_item_id) WHERE deleted_at IS NULL AND plan_item_id IS NOT NULL');
        DB::statement('CREATE INDEX purchases_card_idx ON public.card_purchases (user_id, card_id, purchased_on DESC) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.card_purchases');
    }
};
