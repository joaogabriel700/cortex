<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 10. Taxas anuais e conferências de saldo. */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('investment_rates', <<<'SQL'
            product_id uuid NOT NULL,
            effective_from date NOT NULL,
            annual_rate numeric(9, 6) NOT NULL CHECK (annual_rate > -1 AND annual_rate <= 10),
            CONSTRAINT inv_rates_uq UNIQUE (user_id, product_id, effective_from)
            SQL);

        FinanceSchema::foreign('investment_rates', 'investment_rates_product_fk', 'user_id, product_id', 'investment_products', 'user_id, id');

        FinanceSchema::createSyncTable('investment_balance_checks', <<<'SQL'
            product_id uuid NOT NULL,
            checked_on date NOT NULL,
            balance_cents public.cents NOT NULL CHECK (balance_cents >= 0),
            note varchar(255),
            CONSTRAINT inv_checks_uq UNIQUE (user_id, product_id, checked_on)
            SQL);

        FinanceSchema::foreign('investment_balance_checks', 'investment_balance_checks_product_fk', 'user_id, product_id', 'investment_products', 'user_id, id');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.investment_balance_checks');
        DB::statement('DROP TABLE IF EXISTS public.investment_rates');
    }
};
