<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 16. Orçamentos por categoria, mês e moeda. */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('budgets', <<<'SQL'
            category_id uuid NOT NULL,
            month date NOT NULL CHECK (extract(day FROM month) = 1),
            currency char(3) NOT NULL DEFAULT 'BRL' REFERENCES public.currencies (code),
            amount_cents public.cents NOT NULL CHECK (amount_cents >= 0),
            CONSTRAINT budgets_uq UNIQUE (user_id, month, category_id, currency)
            SQL);

        FinanceSchema::foreign('budgets', 'budgets_category_fk', 'user_id, category_id', 'categories', 'user_id, id');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.budgets');
    }
};
