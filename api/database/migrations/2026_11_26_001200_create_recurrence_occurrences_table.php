<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 12. Ocorrências. O status é derivado (view v_occurrence_status). */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('recurrence_occurrences', <<<'SQL'
            recurrence_id uuid NOT NULL,
            scheduled_on date NOT NULL,
            due_on date NOT NULL,
            amount_cents public.cents NOT NULL CHECK (amount_cents > 0),
            skipped_at timestamptz,
            CONSTRAINT occ_uq UNIQUE (user_id, recurrence_id, scheduled_on)
            SQL);

        FinanceSchema::foreign('recurrence_occurrences', 'recurrence_occurrences_recurrence_fk', 'user_id, recurrence_id', 'recurrences', 'user_id, id');

        DB::statement('CREATE INDEX occ_due_idx ON public.recurrence_occurrences (user_id, due_on) WHERE deleted_at IS NULL AND skipped_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.recurrence_occurrences');
    }
};
