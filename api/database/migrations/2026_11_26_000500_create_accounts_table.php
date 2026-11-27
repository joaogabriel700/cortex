<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 05. Contas. O saldo não é gravado. */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('accounts', <<<'SQL'
            name varchar(80) NOT NULL CHECK (btrim(name) <> ''),
            type varchar(20) NOT NULL CHECK (type IN ('checking', 'savings', 'cash', 'digital_wallet', 'other')),
            institution varchar(80),
            currency char(3) NOT NULL DEFAULT 'BRL' REFERENCES public.currencies (code),
            opening_balance_cents public.cents NOT NULL DEFAULT 0,
            opening_date date NOT NULL,
            include_in_total boolean NOT NULL DEFAULT true,
            sort_order integer NOT NULL DEFAULT 0,
            archived_at timestamptz
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.accounts');
    }
};
