<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/**
 * 08. Faturas. Total e status são derivados.
 * A proteção da fatura fica na migration 18, porque depende de parcelas e lançamentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('card_invoices', <<<'SQL'
            card_id uuid NOT NULL,
            reference_month date NOT NULL CHECK (extract(day FROM reference_month) = 1),
            closing_date date NOT NULL,
            due_date date NOT NULL,
            CONSTRAINT card_invoices_dates_check CHECK (due_date >= closing_date),
            CONSTRAINT card_invoices_month_uq UNIQUE (user_id, card_id, reference_month)
            SQL);

        FinanceSchema::foreign('card_invoices', 'card_invoices_card_fk', 'user_id, card_id', 'credit_cards', 'user_id, id');

        DB::statement('CREATE INDEX invoices_due_idx ON public.card_invoices (user_id, due_date) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.card_invoices');
    }
};
