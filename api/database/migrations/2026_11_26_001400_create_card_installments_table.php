<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 14. Parcelas e integridade agregada compra x parcelas (conferida no COMMIT). */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('card_installments', <<<'SQL'
            purchase_id uuid NOT NULL,
            invoice_id uuid NOT NULL,
            number smallint NOT NULL CHECK (number BETWEEN 1 AND 120),
            amount_cents public.cents NOT NULL CHECK (amount_cents > 0),
            CONSTRAINT inst_uq UNIQUE (user_id, purchase_id, number)
            SQL);

        FinanceSchema::foreign('card_installments', 'card_installments_purchase_fk', 'user_id, purchase_id', 'card_purchases', 'user_id, id');
        FinanceSchema::foreign('card_installments', 'card_installments_invoice_fk', 'user_id, invoice_id', 'card_invoices', 'user_id, id');

        DB::statement('CREATE INDEX inst_invoice_idx ON public.card_installments (user_id, invoice_id) WHERE deleted_at IS NULL');

        // Parcela não muda de compra (o id dela deriva de purchase_id). Sem isto, a
        // integridade conferiria só o destino e a compra de origem ficaria quebrada.
        DB::statement(
            'CREATE TRIGGER card_installments_purchase_immutable BEFORE UPDATE OF purchase_id ON public.card_installments '
            .'FOR EACH ROW WHEN (OLD.purchase_id IS DISTINCT FROM NEW.purchase_id) '
            ."EXECUTE FUNCTION public.column_immutable('purchase_id')"
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_purchase_integrity() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
              pid uuid;
              p public.card_purchases%ROWTYPE;
              n int;
              s numeric;
              bad int;
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              IF TG_TABLE_NAME = 'card_purchases' THEN
                pid := NEW.id;
              ELSE
                pid := NEW.purchase_id;
              END IF;

              SELECT * INTO p FROM public.card_purchases WHERE id = pid;
              IF NOT FOUND THEN
                RETURN NULL;
              END IF;

              SELECT count(*), coalesce(sum(i.amount_cents), 0),
                     count(*) FILTER (WHERE i.number < p.first_installment_number
                                         OR i.number > p.installment_count
                                         OR v.card_id <> p.card_id
                                         OR v.deleted_at IS NOT NULL)
                INTO n, s, bad
                FROM public.card_installments i
                JOIN public.card_invoices v ON v.user_id = i.user_id AND v.id = i.invoice_id
               WHERE i.user_id = p.user_id AND i.purchase_id = p.id AND i.deleted_at IS NULL;

              IF p.deleted_at IS NOT NULL THEN
                IF n <> 0 THEN
                  RAISE EXCEPTION 'purchase_deleted_with_live_installments';
                END IF;
              ELSIF n <> p.installment_count - p.first_installment_number + 1 OR s <> p.total_cents OR bad > 0 THEN
                RAISE EXCEPTION 'purchase_installments_mismatch';
              END IF;
              RETURN NULL;
            END
            $$
            SQL);

        DB::statement(
            'CREATE CONSTRAINT TRIGGER purchase_integrity AFTER INSERT OR UPDATE ON public.card_purchases '
            .'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_purchase_integrity()'
        );
        DB::statement(
            'CREATE CONSTRAINT TRIGGER installment_integrity AFTER INSERT OR UPDATE ON public.card_installments '
            .'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_purchase_integrity()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.card_installments');
        DB::statement('DROP TRIGGER IF EXISTS purchase_integrity ON public.card_purchases');
        DB::statement('DROP FUNCTION IF EXISTS public.check_purchase_integrity()');
    }
};
