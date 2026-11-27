<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 04. Contador de versão por usuário e função de carimbo.
 * O upsert no contador trava a linha até o COMMIT: as escritas de um mesmo
 * usuário ficam em fila e a ordem das versões é a ordem de gravação.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE public.user_sync_state (
                user_id uuid NOT NULL,
                last_version bigint NOT NULL DEFAULT 0,
                purged_through_version bigint NOT NULL DEFAULT 0,
                CONSTRAINT user_sync_state_pkey PRIMARY KEY (user_id),
                CONSTRAINT user_sync_state_user_fk FOREIGN KEY (user_id) REFERENCES public.users (id) ON DELETE CASCADE,
                CONSTRAINT user_sync_state_purged_check CHECK (purged_through_version <= last_version)
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.stamp_sync_version() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            BEGIN
              INSERT INTO public.user_sync_state AS s (user_id, last_version) VALUES (NEW.user_id, 1)
              ON CONFLICT (user_id) DO UPDATE SET last_version = s.last_version + 1
              RETURNING s.last_version INTO NEW.sync_version;
              RETURN NEW;
            END
            $$
            SQL);

        // Falha fechada: as validações leem sob RLS. Se o contexto não for o do dono
        // da linha, elas não enxergariam os dados e aprovariam por engano.
        // Só o runtime depende do contexto (a manutenção tem política própria).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.assert_row_context(p_user_id uuid) RETURNS void
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $$
            BEGIN
              IF current_user = 'app_runtime'
                 AND p_user_id IS DISTINCT FROM nullif(current_setting('app.user_id', true), '')::uuid THEN
                RAISE EXCEPTION 'tenant_context_changed';
              END IF;
            END
            $$
            SQL);

        // Usada por triggers com WHEN (só dispara quando o valor muda).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.column_immutable() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $$
            BEGIN
              RAISE EXCEPTION 'column_immutable' USING DETAIL = TG_TABLE_NAME || ': ' || TG_ARGV[0];
            END
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.guard_sync_cursor() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $$
            BEGIN
              IF NEW.user_id IS DISTINCT FROM OLD.user_id THEN
                RAISE EXCEPTION 'column_immutable' USING DETAIL = 'user_sync_state: user_id';
              END IF;
              IF NEW.last_version < OLD.last_version OR NEW.purged_through_version < OLD.purged_through_version THEN
                RAISE EXCEPTION 'sync_cursor_rollback';
              END IF;
              RETURN NEW;
            END
            $$
            SQL);

        DB::statement(
            'CREATE TRIGGER user_sync_state_cursor_guard BEFORE UPDATE ON public.user_sync_state '
            .'FOR EACH ROW EXECUTE FUNCTION public.guard_sync_cursor()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.user_sync_state');
        DB::statement('DROP FUNCTION IF EXISTS public.guard_sync_cursor()');
        DB::statement('DROP FUNCTION IF EXISTS public.column_immutable()');
        DB::statement('DROP FUNCTION IF EXISTS public.assert_row_context(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS public.stamp_sync_version()');
    }
};
