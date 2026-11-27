<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 20. GRANTs, RLS, políticas, função de login e proteção da data de exclusão
 * (financas.md §11 e seguranca/rls-financas.md).
 *
 * As tabelas são listadas explicitamente. Não descobrir pela coluna user_id:
 * sessions e deleted_accounts têm essa coluna e ficam SEM RLS.
 * Tabelas do framework (sessions, cache, filas) são do DevOps.
 */
return new class extends Migration
{
    /** Tabelas sincronizadas. user_sync_state também tem RLS, mas GRANTs próprios. */
    private const BUSINESS_TABLES = [
        'accounts',
        'credit_cards',
        'card_invoices',
        'categories',
        'investment_products',
        'investment_rates',
        'investment_balance_checks',
        'recurrences',
        'recurrence_occurrences',
        'monthly_plans',
        'monthly_plan_items',
        'card_purchases',
        'card_installments',
        'transactions',
        'budgets',
        'import_batches',
        'import_rows',
    ];

    private const VIEWS = [
        'v_account_entries',
        'v_category_entries',
        'v_occurrence_status',
        'v_plan_items',
    ];

    /** Contexto do usuário, definido por set_config('app.user_id', ?, true). */
    private const CONTEXT = "(SELECT nullif(current_setting('app.user_id', true), '')::uuid)";

    public function up(): void
    {
        DB::statement('GRANT USAGE ON SCHEMA public TO app_runtime, app_maintenance');

        $this->secureSyncState();
        $this->secureBusinessTables();
        $this->secureReferenceTables();
        $this->secureUsers();
        $this->createLoginFunction();
        $this->createDeletionGuard();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS users_guard_deletion ON public.users');
        DB::statement('DROP FUNCTION IF EXISTS public.guard_deletion_request()');

        $this->dropLoginFunction();
        DB::statement('REVOKE ALL ON public.users FROM app_auth, app_runtime, app_maintenance');
        DB::statement('REVOKE USAGE ON SCHEMA public FROM app_auth');

        foreach (['self_only', 'maintenance_read', 'maintenance_delete', 'auth_read'] as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON public.users");
        }
        DB::statement('ALTER TABLE public.users NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.users DISABLE ROW LEVEL SECURITY');

        DB::statement('REVOKE ALL ON public.currencies, public.deleted_accounts FROM app_runtime, app_maintenance');

        foreach (self::VIEWS as $view) {
            DB::statement("REVOKE ALL ON public.{$view} FROM app_runtime");
        }

        foreach (self::BUSINESS_TABLES as $table) {
            $this->removeTenantSecurity($table);
        }
        $this->removeTenantSecurity('user_sync_state');

        // USAGE no schema para runtime e manutenção não é revogado:
        // as tabelas do framework, do DevOps, dependem dele.
    }

    private function enableTenantSecurity(string $table): void
    {
        $context = self::CONTEXT;

        DB::statement("REVOKE ALL ON public.{$table} FROM PUBLIC, app_runtime, app_maintenance");

        DB::statement("ALTER TABLE public.{$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE public.{$table} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY tenant_isolation ON public.{$table} TO app_runtime "
            ."USING (user_id = {$context}) WITH CHECK (user_id = {$context})"
        );
        DB::statement("CREATE POLICY maintenance_all ON public.{$table} TO app_maintenance USING (true) WITH CHECK (true)");
    }

    private function removeTenantSecurity(string $table): void
    {
        DB::statement("REVOKE ALL ON public.{$table} FROM app_runtime, app_maintenance");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON public.{$table}");
        DB::statement("DROP POLICY IF EXISTS maintenance_all ON public.{$table}");
        DB::statement("ALTER TABLE public.{$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE public.{$table} DISABLE ROW LEVEL SECURITY");
    }

    /**
     * Contador de sincronização. O runtime só altera last_version (basta para o
     * carimbo e para o SELECT ... FOR UPDATE) e o trigger da 000400 impede
     * retroceder. A manutenção não apaga: a exclusão da conta remove por cascata.
     */
    private function secureSyncState(): void
    {
        $this->enableTenantSecurity('user_sync_state');

        DB::statement('GRANT SELECT, INSERT (user_id, last_version), UPDATE (last_version) ON public.user_sync_state TO app_runtime');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON public.user_sync_state TO app_maintenance');
    }

    private function secureBusinessTables(): void
    {
        foreach (self::BUSINESS_TABLES as $table) {
            $this->enableTenantSecurity($table);

            // Sem DELETE para o runtime: exclusão é soft delete.
            DB::statement("GRANT SELECT, INSERT, UPDATE ON public.{$table} TO app_runtime");
            DB::statement("GRANT SELECT, DELETE ON public.{$table} TO app_maintenance");
        }

        // Só as colunas que a limpeza altera (o carimbo em sync_version vem do trigger).
        DB::statement(
            'GRANT UPDATE (status, raw, external_id, description, amount_cents, occurred_on, error_message, '
            .'transaction_id, card_installment_id, balance_check_id, investment_product_id, monthly_plan_item_id, '
            .'budget_id, monthly_plan_id) '
            .'ON public.import_rows TO app_maintenance'
        );
    }

    private function secureReferenceTables(): void
    {
        DB::statement('REVOKE ALL ON public.currencies FROM PUBLIC, app_runtime, app_maintenance');
        DB::statement('GRANT SELECT ON public.currencies TO app_runtime, app_maintenance');

        foreach (self::VIEWS as $view) {
            DB::statement("REVOKE ALL ON public.{$view} FROM PUBLIC, app_runtime, app_maintenance");
            DB::statement("GRANT SELECT ON public.{$view} TO app_runtime");
        }

        // Sem RLS. Ninguém recebe UPDATE, DELETE ou TRUNCATE.
        DB::statement('REVOKE ALL ON public.deleted_accounts FROM PUBLIC, app_runtime, app_maintenance');
        DB::statement('GRANT SELECT ON public.deleted_accounts TO app_runtime');
        DB::statement('GRANT INSERT ON public.deleted_accounts TO app_maintenance');
    }

    private function secureUsers(): void
    {
        $context = self::CONTEXT;

        DB::statement('REVOKE ALL ON public.users FROM PUBLIC, app_runtime, app_maintenance');

        DB::statement('GRANT SELECT ON public.users TO app_runtime');
        DB::statement('GRANT INSERT (id, name, email, password, locale, timezone, default_currency, created_at, updated_at) ON public.users TO app_runtime');
        DB::statement('GRANT UPDATE (name, email, password, email_verified_at, remember_token, locale, timezone, default_currency, deletion_requested_at, updated_at) ON public.users TO app_runtime');
        DB::statement('GRANT SELECT (id, email, deletion_requested_at), DELETE ON public.users TO app_maintenance');
        DB::statement('GRANT SELECT (id, email, password) ON public.users TO app_auth');

        DB::statement('ALTER TABLE public.users ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.users FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY self_only ON public.users TO app_runtime USING (id = {$context}) WITH CHECK (id = {$context})");
        DB::statement('CREATE POLICY maintenance_read ON public.users FOR SELECT TO app_maintenance USING (true)');
        DB::statement("CREATE POLICY maintenance_delete ON public.users FOR DELETE TO app_maintenance USING (deletion_requested_at <= now() - interval '30 days')");
        DB::statement('CREATE POLICY auth_read ON public.users FOR SELECT TO app_auth USING (true)');
    }

    /** Única função SECURITY DEFINER; o dono é app_auth, que não faz login. */
    private function createLoginFunction(): void
    {
        // Explícito, caso o init tenha tirado o USAGE de PUBLIC.
        DB::statement('GRANT USAGE ON SCHEMA public TO app_auth');

        // "migrate:fresh" apaga tabelas, mas deixa a função, que pertence a app_auth.
        $this->dropLoginFunction();

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.auth_lookup_by_email(p_email text) RETURNS TABLE(id uuid, password text)
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS 'SELECT u.id, u.password::text FROM public.users u WHERE lower(u.email) = lower(p_email) LIMIT 1'
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION public.auth_lookup_by_email(text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.auth_lookup_by_email(text) TO app_runtime');

        // CREATE no schema é concedido só durante a troca de dono.
        DB::statement('GRANT USAGE, CREATE ON SCHEMA public TO app_auth');
        DB::statement('ALTER FUNCTION public.auth_lookup_by_email(text) OWNER TO app_auth');
        DB::statement('REVOKE CREATE ON SCHEMA public FROM app_auth');
    }

    /** Depende da transação da migration (SET LOCAL). Não desligar $withinTransaction. */
    private function dropLoginFunction(): void
    {
        DB::statement('SET LOCAL ROLE app_auth');
        DB::statement('DROP FUNCTION IF EXISTS public.auth_lookup_by_email(text)');
        DB::statement('RESET ROLE');
    }

    /**
     * A data do pedido de exclusão é do servidor: não antedata nem reinicia.
     * Para o runtime, reforça duas regras da API: depois do prazo não se cancela,
     * e a conta demo (credencial pública) não troca e-mail ou senha nem pede exclusão.
     */
    private function createDeletionGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.guard_deletion_request() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
              IF current_user = 'app_runtime' THEN
                IF OLD.is_demo AND (NEW.email IS DISTINCT FROM OLD.email
                    OR NEW.password IS DISTINCT FROM OLD.password
                    OR NEW.deletion_requested_at IS DISTINCT FROM OLD.deletion_requested_at) THEN
                  RAISE EXCEPTION 'demo_account_locked';
                END IF;
                -- Mesmo limite da política de DELETE da manutenção.
                IF OLD.deletion_requested_at <= now() - interval '30 days' AND NEW.deletion_requested_at IS NULL THEN
                  RAISE EXCEPTION 'deletion_window_closed';
                END IF;
              END IF;

              IF NEW.deletion_requested_at IS NOT NULL THEN
                NEW.deletion_requested_at := coalesce(OLD.deletion_requested_at, now());
              END IF;
              RETURN NEW;
            END
            $$
            SQL);

        DB::statement(
            'CREATE TRIGGER users_guard_deletion BEFORE UPDATE ON public.users '
            .'FOR EACH ROW EXECUTE FUNCTION public.guard_deletion_request()'
        );
    }
};
