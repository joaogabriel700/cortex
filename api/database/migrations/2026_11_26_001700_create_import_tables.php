<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 17. Importação, deduplicação e a trava do estado "purged". */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('import_batches', <<<'SQL'
            source_format varchar(4) NOT NULL CHECK (source_format IN ('csv', 'ofx', 'xlsx')),
            file_name varchar(255) NOT NULL,
            file_sha256 char(64) NOT NULL CHECK (file_sha256 ~ '^[0-9a-f]{64}$'),
            column_mapping jsonb CHECK (jsonb_typeof(column_mapping) = 'object'),
            status varchar(15) NOT NULL DEFAULT 'processing' CHECK (status IN ('processing', 'pending_review', 'completed', 'failed'))
            SQL);

        DB::statement('CREATE INDEX import_hash_idx ON public.import_batches (user_id, file_sha256) WHERE deleted_at IS NULL');

        FinanceSchema::createSyncTable('import_rows', <<<'SQL'
            batch_id uuid NOT NULL,
            row_number integer NOT NULL CHECK (row_number > 0),
            account_id uuid,
            card_id uuid,
            raw jsonb,
            external_id varchar(255),
            dedup_key varchar(128) NOT NULL,
            occurred_on date,
            amount_cents public.cents,
            description varchar(500),
            status varchar(10) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'imported', 'matched', 'duplicate', 'ignored', 'error', 'purged')),
            error_message varchar(500),
            transaction_id uuid,
            card_installment_id uuid,
            balance_check_id uuid,
            investment_product_id uuid,
            monthly_plan_item_id uuid,
            budget_id uuid,
            monthly_plan_id uuid,
            CONSTRAINT import_rows_destination_check CHECK (num_nonnulls(account_id, card_id) <= 1),
            CONSTRAINT import_rows_single_target_check CHECK (num_nonnulls(transaction_id, card_installment_id, balance_check_id, investment_product_id, monthly_plan_item_id, budget_id, monthly_plan_id) <= 1),
            CONSTRAINT import_rows_done_target_check CHECK (status NOT IN ('imported', 'matched')
                OR num_nonnulls(transaction_id, card_installment_id, balance_check_id, investment_product_id, monthly_plan_item_id, budget_id, monthly_plan_id) = 1),
            CONSTRAINT import_rows_purged_target_check CHECK (status <> 'purged'
                OR num_nonnulls(transaction_id, card_installment_id, balance_check_id, investment_product_id, monthly_plan_item_id, budget_id, monthly_plan_id) = 0),
            CONSTRAINT import_rows_line_uq UNIQUE (user_id, batch_id, row_number)
            SQL);

        FinanceSchema::foreign('import_rows', 'import_rows_batch_fk', 'user_id, batch_id', 'import_batches', 'user_id, id');
        FinanceSchema::foreign('import_rows', 'import_rows_account_fk', 'user_id, account_id', 'accounts', 'user_id, id');
        FinanceSchema::foreign('import_rows', 'import_rows_card_fk', 'user_id, card_id', 'credit_cards', 'user_id, id');
        FinanceSchema::foreign('import_rows', 'import_rows_transaction_fk', 'user_id, transaction_id', 'transactions', 'user_id, id');
        FinanceSchema::foreign('import_rows', 'import_rows_installment_fk', 'user_id, card_installment_id', 'card_installments', 'user_id, id');
        FinanceSchema::foreign('import_rows', 'import_rows_balance_check_fk', 'user_id, balance_check_id', 'investment_balance_checks', 'user_id, id');
        FinanceSchema::foreign('import_rows', 'import_rows_product_fk', 'user_id, investment_product_id', 'investment_products', 'user_id, id');
        FinanceSchema::foreign('import_rows', 'import_rows_plan_item_fk', 'user_id, monthly_plan_item_id', 'monthly_plan_items', 'user_id, id');
        FinanceSchema::foreign('import_rows', 'import_rows_budget_fk', 'user_id, budget_id', 'budgets', 'user_id, id');
        FinanceSchema::foreign('import_rows', 'import_rows_plan_fk', 'user_id, monthly_plan_id', 'monthly_plans', 'user_id, id');

        // "purged" continua na deduplicação: reimportar o arquivo não ressuscita o lançamento apagado.
        DB::statement("CREATE UNIQUE INDEX import_dedup_account_uq ON public.import_rows (user_id, account_id, dedup_key) WHERE account_id IS NOT NULL AND status IN ('imported', 'matched', 'purged') AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX import_dedup_card_uq ON public.import_rows (user_id, card_id, dedup_key) WHERE card_id IS NOT NULL AND status IN ('imported', 'matched', 'purged') AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX import_dedup_other_uq ON public.import_rows (user_id, dedup_key) WHERE account_id IS NULL AND card_id IS NULL AND status IN ('imported', 'matched', 'purged') AND deleted_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX import_tx_once_per_account_uq ON public.import_rows (user_id, account_id, transaction_id) WHERE transaction_id IS NOT NULL AND account_id IS NOT NULL AND status IN ('imported', 'matched') AND deleted_at IS NULL");

        // Só o que foi importado ou conciliado vira "purged", que segura a chave de
        // deduplicação. Uma sugestão (linha pending com vínculo) nunca foi importada:
        // se virasse "purged", reimportar o arquivo perderia o lançamento.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.guard_import_row_purge() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $$
            BEGIN
              RAISE EXCEPTION 'import_row_never_imported';
            END
            $$
            SQL);

        DB::statement(
            'CREATE TRIGGER import_rows_purge_guard BEFORE UPDATE OF status ON public.import_rows FOR EACH ROW '
            ."WHEN (NEW.status = 'purged' AND OLD.status NOT IN ('imported', 'matched', 'purged')) "
            .'EXECUTE FUNCTION public.guard_import_row_purge()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.import_rows');
        DB::statement('DROP TABLE IF EXISTS public.import_batches');
        DB::statement('DROP FUNCTION IF EXISTS public.guard_import_row_purge()');
    }
};
