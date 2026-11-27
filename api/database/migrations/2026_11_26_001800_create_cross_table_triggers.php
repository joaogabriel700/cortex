<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 18. Regras entre tabelas (financas.md §3.18). SQL NOVO, AINDA NÃO TESTADO.
 *
 * - Regras de consistência: constraint triggers adiados, que releem a linha
 *   atual. Assim a ordem dentro de um lote de sincronização não importa.
 * - Toda função adiada começa por assert_row_context: se app.user_id mudar antes
 *   do COMMIT, o RLS esconderia as linhas e a regra passaria; em vez disso, falha.
 * - Colunas imutáveis: triggers BEFORE imediatos. Nas travas de moeda, que leem
 *   outras tabelas, o prefixo "zz_" faz rodarem depois do carimbo (ordem
 *   alfabética), já com a linha de user_sync_state travada, o que serializa as
 *   escritas do mesmo usuário.
 * - Todas as funções são SECURITY INVOKER: respeitam o RLS de quem grava.
 * - Linha excluída (deleted_at preenchido) não é validada: excluir sempre pode,
 *   exceto fatura com parcela ou pagamento vivo.
 */
return new class extends Migration
{
    private const FUNCTIONS = [
        'check_transaction_currency',
        'check_card_currency',
        'check_recurrence_currency',
        'check_plan_item_currency',
        'block_currency_change',
        'check_balance_check_date',
        'check_transaction_link',
        'check_purchase_link',
        'check_plan_item_fulfilment',
        'check_invoice_protection',
        'check_recurrence_deletion',
    ];

    /** nome do trigger => [tabela, evento, função] (adiados até o COMMIT) */
    private const DEFERRED = [
        'transactions_currency' => ['transactions', 'INSERT OR UPDATE', 'check_transaction_currency'],
        'credit_cards_currency' => ['credit_cards', 'INSERT OR UPDATE', 'check_card_currency'],
        'recurrences_currency' => ['recurrences', 'INSERT OR UPDATE', 'check_recurrence_currency'],
        'plan_items_currency' => ['monthly_plan_items', 'INSERT OR UPDATE', 'check_plan_item_currency'],
        'balance_checks_date' => ['investment_balance_checks', 'INSERT OR UPDATE', 'check_balance_check_date'],
        'products_balance_check_date' => ['investment_products', 'UPDATE', 'check_balance_check_date'],
        'transactions_link' => ['transactions', 'INSERT OR UPDATE', 'check_transaction_link'],
        'card_purchases_link' => ['card_purchases', 'INSERT OR UPDATE', 'check_purchase_link'],
        'plan_items_fulfilment' => ['monthly_plan_items', 'UPDATE', 'check_plan_item_fulfilment'],
        'card_invoices_protection' => ['card_invoices', 'INSERT OR UPDATE', 'check_invoice_protection'],
        'transactions_invoice_protection' => ['transactions', 'INSERT OR UPDATE', 'check_invoice_protection'],
        'recurrences_deletion' => ['recurrences', 'INSERT OR UPDATE', 'check_recurrence_deletion'],
        'occurrences_recurrence_alive' => ['recurrence_occurrences', 'INSERT OR UPDATE', 'check_recurrence_deletion'],
    ];

    /** nome do trigger => [tabela, coluna, função] (moeda: imediatos, só quando o valor muda) */
    private const CURRENCY_GUARDS = [
        'accounts_zz_currency_immutable' => ['accounts', 'currency', 'block_currency_change'],
        'credit_cards_zz_currency_immutable' => ['credit_cards', 'currency', 'block_currency_change'],
        'investment_products_zz_currency_immutable' => ['investment_products', 'currency', 'block_currency_change'],
    ];

    /**
     * nome do trigger => [tabela, coluna] que nunca muda (função column_immutable, da 000400).
     * id, user_id e created_at são travados por createSyncTable; purchase_id, na 001400.
     * Inclui todos os campos que formam o UUIDv5 do registro (financas.md §4.3):
     * mudar um deles separaria a linha do próprio id. "Mudar a data" = excluir e criar.
     */
    private const FIXED_COLUMNS = [
        'card_invoices_card_immutable' => ['card_invoices', 'card_id'],
        'card_invoices_month_immutable' => ['card_invoices', 'reference_month'],
        'card_installments_number_immutable' => ['card_installments', 'number'],
        'categories_system_key_immutable' => ['categories', 'system_key'],
        'categories_kind_immutable' => ['categories', 'kind'],
        'recurrences_kind_immutable' => ['recurrences', 'kind'],
        'occurrences_recurrence_immutable' => ['recurrence_occurrences', 'recurrence_id'],
        'occurrences_scheduled_on_immutable' => ['recurrence_occurrences', 'scheduled_on'],
        'budgets_category_immutable' => ['budgets', 'category_id'],
        'budgets_month_immutable' => ['budgets', 'month'],
        'budgets_currency_immutable' => ['budgets', 'currency'],
        'monthly_plans_month_immutable' => ['monthly_plans', 'month'],
        'monthly_plans_currency_immutable' => ['monthly_plans', 'currency'],
        'investment_rates_product_immutable' => ['investment_rates', 'product_id'],
        'investment_rates_date_immutable' => ['investment_rates', 'effective_from'],
        'balance_checks_product_immutable' => ['investment_balance_checks', 'product_id'],
        'balance_checks_date_immutable' => ['investment_balance_checks', 'checked_on'],
    ];

    public function up(): void
    {
        $this->createCurrencyFunctions();
        $this->createBalanceCheckFunction();
        $this->createLinkFunctions();
        $this->createInvoiceFunctions();
        $this->createRecurrenceFunction();

        foreach (self::DEFERRED as $trigger => [$table, $event, $function]) {
            DB::statement(
                "CREATE CONSTRAINT TRIGGER {$trigger} AFTER {$event} ON public.{$table} "
                ."DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.{$function}()"
            );
        }

        foreach (self::CURRENCY_GUARDS as $trigger => [$table, $column, $function]) {
            DB::statement(
                "CREATE TRIGGER {$trigger} BEFORE UPDATE OF {$column} ON public.{$table} "
                ."FOR EACH ROW WHEN (OLD.{$column} IS DISTINCT FROM NEW.{$column}) EXECUTE FUNCTION public.{$function}()"
            );
        }

        foreach (self::FIXED_COLUMNS as $trigger => [$table, $column]) {
            DB::statement(
                "CREATE TRIGGER {$trigger} BEFORE UPDATE OF {$column} ON public.{$table} "
                ."FOR EACH ROW WHEN (OLD.{$column} IS DISTINCT FROM NEW.{$column}) "
                ."EXECUTE FUNCTION public.column_immutable('{$column}')"
            );
        }
    }

    public function down(): void
    {
        foreach (self::DEFERRED + self::CURRENCY_GUARDS + self::FIXED_COLUMNS as $trigger => [$table]) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON public.{$table}");
        }

        foreach (self::FUNCTIONS as $function) {
            DB::statement("DROP FUNCTION IF EXISTS public.{$function}()");
        }
    }

    private function createCurrencyFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_transaction_currency() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
              t public.transactions%ROWTYPE;
              account_currency char(3);
              other_currency char(3);
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              SELECT * INTO t FROM public.transactions WHERE id = NEW.id;
              IF NOT FOUND OR t.deleted_at IS NOT NULL OR t.account_id IS NULL THEN
                RETURN NULL;
              END IF;

              SELECT currency INTO account_currency FROM public.accounts
               WHERE user_id = t.user_id AND id = t.account_id;

              -- Pai ausente deixa a comparação nula: quem acusa é a FK.
              IF t.kind = 'transfer' THEN
                SELECT currency INTO other_currency FROM public.accounts
                 WHERE user_id = t.user_id AND id = t.counter_account_id;
                IF (account_currency <> other_currency) <> (t.counter_amount_cents IS NOT NULL) THEN
                  RAISE EXCEPTION 'currency_counter_amount';
                END IF;
              ELSIF t.kind IN ('investment_in', 'investment_out') THEN
                SELECT currency INTO other_currency FROM public.investment_products
                 WHERE user_id = t.user_id AND id = t.investment_product_id;
                IF account_currency <> other_currency THEN
                  RAISE EXCEPTION 'currency_mismatch_investment';
                END IF;
              ELSIF t.kind = 'card_payment' THEN
                SELECT c.currency INTO other_currency
                  FROM public.card_invoices v
                  JOIN public.credit_cards c ON c.user_id = v.user_id AND c.id = v.card_id
                 WHERE v.user_id = t.user_id AND v.id = t.card_invoice_id;
                IF account_currency <> other_currency THEN
                  RAISE EXCEPTION 'currency_mismatch_card_payment';
                END IF;
              END IF;
              RETURN NULL;
            END
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_card_currency() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              IF EXISTS (
                  SELECT 1
                    FROM public.credit_cards c
                    JOIN public.accounts a ON a.user_id = c.user_id AND a.id = c.default_payment_account_id
                   WHERE c.id = NEW.id AND c.deleted_at IS NULL AND a.currency <> c.currency) THEN
                RAISE EXCEPTION 'currency_mismatch_card_account';
              END IF;
              RETURN NULL;
            END
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_recurrence_currency() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              IF EXISTS (
                  SELECT 1
                    FROM public.recurrences r
                    JOIN public.accounts a ON a.user_id = r.user_id AND a.id = r.account_id
                    JOIN public.investment_products p ON p.user_id = r.user_id AND p.id = r.investment_product_id
                   WHERE r.id = NEW.id AND r.deleted_at IS NULL AND a.currency <> p.currency) THEN
                RAISE EXCEPTION 'currency_mismatch_investment';
              END IF;
              RETURN NULL;
            END
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_plan_item_currency() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              IF EXISTS (
                  SELECT 1
                    FROM public.monthly_plan_items i
                    JOIN public.monthly_plans m ON m.user_id = i.user_id AND m.id = i.plan_id
                    JOIN public.investment_products p ON p.user_id = i.user_id AND p.id = i.investment_product_id
                   WHERE i.id = NEW.id AND i.deleted_at IS NULL AND p.currency <> m.currency) THEN
                RAISE EXCEPTION 'currency_mismatch_plan_item';
              END IF;
              RETURN NULL;
            END
            $$
            SQL);

        // "Movimento" = qualquer linha viva cujo significado dependa da moeda.
        // Saldo inicial sozinho não trava: o usuário pode corrigir a moeda.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.block_currency_change() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
              in_use boolean := false;
            BEGIN
              PERFORM public.assert_row_context(OLD.user_id);

              IF TG_TABLE_NAME = 'accounts' THEN
                in_use :=
                     EXISTS (SELECT 1 FROM public.transactions
                              WHERE user_id = OLD.user_id AND deleted_at IS NULL
                                AND (account_id = OLD.id OR counter_account_id = OLD.id))
                  OR EXISTS (SELECT 1 FROM public.recurrences
                              WHERE user_id = OLD.user_id AND deleted_at IS NULL
                                AND (account_id = OLD.id OR counter_account_id = OLD.id))
                  OR EXISTS (SELECT 1 FROM public.credit_cards
                              WHERE user_id = OLD.user_id AND deleted_at IS NULL
                                AND default_payment_account_id = OLD.id);
              ELSIF TG_TABLE_NAME = 'credit_cards' THEN
                in_use :=
                     EXISTS (SELECT 1 FROM public.card_purchases
                              WHERE user_id = OLD.user_id AND card_id = OLD.id AND deleted_at IS NULL)
                  OR EXISTS (SELECT 1 FROM public.recurrences
                              WHERE user_id = OLD.user_id AND card_id = OLD.id AND deleted_at IS NULL)
                  OR EXISTS (SELECT 1
                               FROM public.card_invoices v
                               JOIN public.transactions t ON t.user_id = v.user_id AND t.card_invoice_id = v.id
                              WHERE v.user_id = OLD.user_id AND v.card_id = OLD.id AND t.deleted_at IS NULL);
              ELSIF TG_TABLE_NAME = 'investment_products' THEN
                in_use :=
                     EXISTS (SELECT 1 FROM public.transactions
                              WHERE user_id = OLD.user_id AND investment_product_id = OLD.id AND deleted_at IS NULL)
                  OR EXISTS (SELECT 1 FROM public.investment_balance_checks
                              WHERE user_id = OLD.user_id AND product_id = OLD.id AND deleted_at IS NULL)
                  OR EXISTS (SELECT 1 FROM public.recurrences
                              WHERE user_id = OLD.user_id AND investment_product_id = OLD.id AND deleted_at IS NULL)
                  OR EXISTS (SELECT 1 FROM public.monthly_plan_items
                              WHERE user_id = OLD.user_id AND investment_product_id = OLD.id AND deleted_at IS NULL);
              ELSE
                -- Falha fechada: ligada a uma tabela nova, não libera a troca em silêncio.
                RAISE EXCEPTION 'currency_guard_unknown_table';
              END IF;

              IF in_use THEN
                RAISE EXCEPTION 'currency_immutable';
              END IF;
              RETURN NEW;
            END
            $$
            SQL);
    }

    private function createBalanceCheckFunction(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_balance_check_date() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
              pid uuid;
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              IF TG_TABLE_NAME = 'investment_products' THEN
                pid := NEW.id;
              ELSE
                pid := NEW.product_id;
              END IF;

              IF EXISTS (
                  SELECT 1
                    FROM public.investment_balance_checks k
                    JOIN public.investment_products p ON p.user_id = k.user_id AND p.id = k.product_id
                   WHERE k.user_id = NEW.user_id AND k.product_id = pid
                     AND k.deleted_at IS NULL AND p.deleted_at IS NULL
                     AND k.checked_on < p.opening_date) THEN
                RAISE EXCEPTION 'balance_check_before_opening';
              END IF;
              RETURN NULL;
            END
            $$
            SQL);
    }

    private function createLinkFunctions(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_transaction_link() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
              t public.transactions%ROWTYPE;
              recurrence_kind text;
              plan_currency char(3);
              item_product uuid;
              money_currency char(3);
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              SELECT * INTO t FROM public.transactions WHERE id = NEW.id;
              IF NOT FOUND OR t.deleted_at IS NOT NULL THEN
                RETURN NULL;
              END IF;

              IF t.recurrence_occurrence_id IS NOT NULL THEN
                IF EXISTS (SELECT 1 FROM public.card_purchases
                            WHERE user_id = t.user_id AND deleted_at IS NULL
                              AND recurrence_occurrence_id = t.recurrence_occurrence_id) THEN
                  RAISE EXCEPTION 'link_occurrence_twice';
                END IF;
                SELECT r.kind INTO recurrence_kind
                  FROM public.recurrence_occurrences o
                  JOIN public.recurrences r ON r.user_id = o.user_id AND r.id = o.recurrence_id
                 WHERE o.user_id = t.user_id AND o.id = t.recurrence_occurrence_id;
                IF recurrence_kind <> t.kind THEN
                  RAISE EXCEPTION 'link_occurrence_kind';
                END IF;
              END IF;

              -- O tipo do item já é garantido pela FK (user_id, plan_item_id, kind).
              IF t.plan_item_id IS NOT NULL THEN
                IF EXISTS (SELECT 1 FROM public.card_purchases
                            WHERE user_id = t.user_id AND deleted_at IS NULL AND plan_item_id = t.plan_item_id) THEN
                  RAISE EXCEPTION 'link_plan_item_twice';
                END IF;
                SELECT m.currency, i.investment_product_id INTO plan_currency, item_product
                  FROM public.monthly_plan_items i
                  JOIN public.monthly_plans m ON m.user_id = i.user_id AND m.id = i.plan_id
                 WHERE i.user_id = t.user_id AND i.id = t.plan_item_id;

                IF t.account_id IS NOT NULL THEN
                  SELECT currency INTO money_currency FROM public.accounts
                   WHERE user_id = t.user_id AND id = t.account_id;
                ELSE
                  SELECT currency INTO money_currency FROM public.investment_products
                   WHERE user_id = t.user_id AND id = t.investment_product_id;
                END IF;

                IF money_currency <> plan_currency THEN
                  RAISE EXCEPTION 'link_plan_currency';
                END IF;
                IF item_product IS NOT NULL AND t.investment_product_id IS DISTINCT FROM item_product THEN
                  RAISE EXCEPTION 'link_plan_product';
                END IF;
              END IF;
              RETURN NULL;
            END
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_purchase_link() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
              p public.card_purchases%ROWTYPE;
              recurrence_kind text;
              plan_currency char(3);
              card_currency char(3);
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              SELECT * INTO p FROM public.card_purchases WHERE id = NEW.id;
              IF NOT FOUND OR p.deleted_at IS NOT NULL THEN
                RETURN NULL;
              END IF;

              IF p.recurrence_occurrence_id IS NOT NULL THEN
                IF EXISTS (SELECT 1 FROM public.transactions
                            WHERE user_id = p.user_id AND deleted_at IS NULL
                              AND recurrence_occurrence_id = p.recurrence_occurrence_id) THEN
                  RAISE EXCEPTION 'link_occurrence_twice';
                END IF;
                SELECT r.kind INTO recurrence_kind
                  FROM public.recurrence_occurrences o
                  JOIN public.recurrences r ON r.user_id = o.user_id AND r.id = o.recurrence_id
                 WHERE o.user_id = p.user_id AND o.id = p.recurrence_occurrence_id;
                -- Só compra (não estorno) confirma, e só recorrência de despesa.
                IF recurrence_kind <> 'expense' OR p.kind <> 'purchase' THEN
                  RAISE EXCEPTION 'link_occurrence_kind';
                END IF;
              END IF;

              IF p.plan_item_id IS NOT NULL THEN
                IF EXISTS (SELECT 1 FROM public.transactions
                            WHERE user_id = p.user_id AND deleted_at IS NULL AND plan_item_id = p.plan_item_id) THEN
                  RAISE EXCEPTION 'link_plan_item_twice';
                END IF;
                SELECT m.currency INTO plan_currency
                  FROM public.monthly_plan_items i
                  JOIN public.monthly_plans m ON m.user_id = i.user_id AND m.id = i.plan_id
                 WHERE i.user_id = p.user_id AND i.id = p.plan_item_id;
                SELECT currency INTO card_currency FROM public.credit_cards
                 WHERE user_id = p.user_id AND id = p.card_id;
                IF card_currency <> plan_currency THEN
                  RAISE EXCEPTION 'link_plan_currency';
                END IF;
              END IF;
              RETURN NULL;
            END
            $$
            SQL);

        // Mudar o plano ou o produto de um item já cumprido não pode quebrar o vínculo.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_plan_item_fulfilment() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
              item public.monthly_plan_items%ROWTYPE;
              plan_currency char(3);
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              SELECT * INTO item FROM public.monthly_plan_items WHERE id = NEW.id;
              IF NOT FOUND OR item.deleted_at IS NOT NULL THEN
                RETURN NULL;
              END IF;

              SELECT currency INTO plan_currency FROM public.monthly_plans
               WHERE user_id = item.user_id AND id = item.plan_id;

              IF EXISTS (
                  SELECT 1
                    FROM public.transactions t
                    LEFT JOIN public.accounts a ON a.user_id = t.user_id AND a.id = t.account_id
                    LEFT JOIN public.investment_products ip ON ip.user_id = t.user_id AND ip.id = t.investment_product_id
                   WHERE t.user_id = item.user_id AND t.plan_item_id = item.id AND t.deleted_at IS NULL
                     AND (coalesce(a.currency, ip.currency) <> plan_currency
                          OR (item.investment_product_id IS NOT NULL
                              AND t.investment_product_id IS DISTINCT FROM item.investment_product_id)))
                 OR EXISTS (
                  SELECT 1
                    FROM public.card_purchases p
                    JOIN public.credit_cards c ON c.user_id = p.user_id AND c.id = p.card_id
                   WHERE p.user_id = item.user_id AND p.plan_item_id = item.id AND p.deleted_at IS NULL
                     AND c.currency <> plan_currency) THEN
                RAISE EXCEPTION 'link_plan_item_mismatch';
              END IF;
              RETURN NULL;
            END
            $$
            SQL);
    }

    /** Excluída só sem ocorrência viva; havendo histórico, a recorrência é encerrada (end_date). */
    private function createRecurrenceFunction(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_recurrence_deletion() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
              rid uuid;
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              IF TG_TABLE_NAME = 'recurrences' THEN
                rid := NEW.id;
              ELSE
                rid := NEW.recurrence_id;
              END IF;

              IF EXISTS (
                  SELECT 1
                    FROM public.recurrences r
                    JOIN public.recurrence_occurrences o ON o.user_id = r.user_id AND o.recurrence_id = r.id
                   WHERE r.user_id = NEW.user_id AND r.id = rid
                     AND r.deleted_at IS NOT NULL AND o.deleted_at IS NULL) THEN
                RAISE EXCEPTION 'recurrence_has_live_occurrences';
              END IF;
              RETURN NULL;
            END
            $$
            SQL);
    }

    private function createInvoiceFunctions(): void
    {
        // Cobre os dois lados: excluir a fatura e apontar pagamento para fatura excluída.
        // Parcela em fatura excluída já é rejeitada por check_purchase_integrity.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_invoice_protection() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              IF TG_TABLE_NAME = 'card_invoices' THEN
                IF EXISTS (SELECT 1 FROM public.card_invoices
                            WHERE id = NEW.id AND deleted_at IS NOT NULL)
                   AND (EXISTS (SELECT 1 FROM public.card_installments
                                 WHERE user_id = NEW.user_id AND invoice_id = NEW.id AND deleted_at IS NULL)
                        OR EXISTS (SELECT 1 FROM public.transactions
                                    WHERE user_id = NEW.user_id AND card_invoice_id = NEW.id AND deleted_at IS NULL)) THEN
                  RAISE EXCEPTION 'invoice_has_live_children';
                END IF;
              ELSE
                IF EXISTS (
                    SELECT 1
                      FROM public.transactions t
                      JOIN public.card_invoices v ON v.user_id = t.user_id AND v.id = t.card_invoice_id
                     WHERE t.id = NEW.id AND t.deleted_at IS NULL AND v.deleted_at IS NOT NULL) THEN
                  RAISE EXCEPTION 'invoice_has_live_children';
                END IF;
              END IF;
              RETURN NULL;
            END
            $$
            SQL);
    }
};
