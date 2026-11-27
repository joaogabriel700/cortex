<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/**
 * 07. Cartões. Limite disponível é derivado.
 * last_four guarda só o final do cartão. O número completo não cabe na coluna;
 * a API precisa recusá-lo ANTES de chegar ao banco, para não ir parar em log.
 */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('credit_cards', <<<'SQL'
            name varchar(80) NOT NULL CHECK (btrim(name) <> ''),
            institution varchar(80),
            last_four char(4) CHECK (last_four ~ '^[0-9]{4}$'),
            currency char(3) NOT NULL DEFAULT 'BRL' REFERENCES public.currencies (code),
            closing_day smallint NOT NULL CHECK (closing_day BETWEEN 1 AND 31),
            due_day smallint NOT NULL CHECK (due_day BETWEEN 1 AND 31),
            credit_limit_cents public.cents CHECK (credit_limit_cents >= 0),
            default_payment_account_id uuid,
            archived_at timestamptz
            SQL);

        FinanceSchema::foreign('credit_cards', 'credit_cards_payment_account_fk', 'user_id, default_payment_account_id', 'accounts', 'user_id, id');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.credit_cards');
    }
};
