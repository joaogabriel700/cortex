<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 02. Moedas. É dado de referência: a carga fica na migration, porque
 * app_runtime (que roda os seeders) só tem SELECT nesta tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE public.currencies (
                code char(3) NOT NULL,
                minor_unit smallint NOT NULL,
                CONSTRAINT currencies_pkey PRIMARY KEY (code),
                CONSTRAINT currencies_code_format CHECK (code ~ '^[A-Z]{3}$'),
                CONSTRAINT currencies_minor_unit_range CHECK (minor_unit BETWEEN 0 AND 4)
            )
            SQL);

        DB::statement("INSERT INTO public.currencies (code, minor_unit) VALUES ('BRL', 2), ('USD', 2), ('EUR', 2)");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.currencies');
    }
};
