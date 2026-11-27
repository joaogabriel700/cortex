<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 19. Visões de leitura (security_invoker: respeitam o RLS) e ciclo do cartão.
 * v_occurrence_status e v_plan_items usam "*": a lista de colunas é fixada na
 * criação, então coluna nova na tabela exige recriar a view em outra migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW public.v_account_entries WITH (security_invoker = true) AS
            SELECT user_id, account_id, id AS transaction_id, occurred_on,
                   CASE WHEN kind IN ('income', 'investment_out') THEN amount_cents ELSE -amount_cents END AS signed_cents
              FROM public.transactions
             WHERE deleted_at IS NULL AND account_id IS NOT NULL
            UNION ALL
            -- só transferências têm destino (CHECK)
            SELECT user_id, counter_account_id, id, occurred_on, coalesce(counter_amount_cents, amount_cents)
              FROM public.transactions
             WHERE deleted_at IS NULL AND counter_account_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW public.v_category_entries WITH (security_invoker = true) AS
            SELECT t.user_id, t.category_id, t.kind::text AS kind,
                   date_trunc('month', t.occurred_on::timestamp)::date AS month,
                   a.currency, t.amount_cents::bigint AS amount_cents
              FROM public.transactions t
              JOIN public.accounts a ON a.user_id = t.user_id AND a.id = t.account_id
             WHERE t.deleted_at IS NULL AND t.kind IN ('income', 'expense')
            UNION ALL
            SELECT p.user_id, p.category_id, 'expense', v.reference_month, c.currency,
                   (CASE WHEN p.kind = 'refund' THEN -i.amount_cents ELSE i.amount_cents END)::bigint
              FROM public.card_installments i
              JOIN public.card_purchases p ON p.user_id = i.user_id AND p.id = i.purchase_id AND p.deleted_at IS NULL
              JOIN public.card_invoices v ON v.user_id = i.user_id AND v.id = i.invoice_id
              JOIN public.credit_cards c ON c.user_id = p.user_id AND c.id = p.card_id
             WHERE i.deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW public.v_occurrence_status WITH (security_invoker = true) AS
            SELECT o.*,
                   CASE
                     WHEN EXISTS (SELECT 1 FROM public.transactions t
                                   WHERE t.user_id = o.user_id AND t.recurrence_occurrence_id = o.id AND t.deleted_at IS NULL)
                       OR EXISTS (SELECT 1 FROM public.card_purchases p
                                   WHERE p.user_id = o.user_id AND p.recurrence_occurrence_id = o.id AND p.deleted_at IS NULL)
                       THEN 'confirmed'
                     WHEN o.skipped_at IS NOT NULL THEN 'skipped'
                     ELSE 'pending'
                   END AS status
              FROM public.recurrence_occurrences o
             WHERE o.deleted_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW public.v_plan_items WITH (security_invoker = true) AS
            SELECT i.*,
                   EXISTS (SELECT 1 FROM public.transactions t
                            WHERE t.user_id = i.user_id AND t.plan_item_id = i.id AND t.deleted_at IS NULL)
                   OR EXISTS (SELECT 1 FROM public.card_purchases p
                               WHERE p.user_id = i.user_id AND p.plan_item_id = i.id AND p.deleted_at IS NULL) AS fulfilled
              FROM public.monthly_plan_items i
             WHERE i.deleted_at IS NULL
            SQL);

        // Mês de referência (vencimento) em que uma data de compra no cartão cai.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.card_reference_month(p_closing int, p_due int, p_date date) RETURNS date
            LANGUAGE sql IMMUTABLE
            SET search_path = pg_catalog, public, pg_temp
            AS $$
              WITH m AS (SELECT date_trunc('month', p_date::timestamp)::date AS m0),
              c AS (SELECT CASE WHEN p_date < m0 + (least(p_closing, extract(day FROM (m0 + interval '1 month - 1 day'))::int) - 1)
                                THEN m0 ELSE (m0 + interval '1 month')::date END AS cm
                      FROM m)
              SELECT CASE WHEN p_due > p_closing THEN cm ELSE (cm + interval '1 month')::date END FROM c
            $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS public.card_reference_month(int, int, date)');
        DB::statement('DROP VIEW IF EXISTS public.v_plan_items');
        DB::statement('DROP VIEW IF EXISTS public.v_occurrence_status');
        DB::statement('DROP VIEW IF EXISTS public.v_category_entries');
        DB::statement('DROP VIEW IF EXISTS public.v_account_entries');
    }
};
