<?php

use Database\Migrations\Support\FinanceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/support/FinanceSchema.php';

/** 06. Categorias (no máximo 2 níveis). Nome nulo = tradução de system_key. */
return new class extends Migration
{
    public function up(): void
    {
        FinanceSchema::createSyncTable('categories', <<<'SQL'
            parent_id uuid,
            kind varchar(7) NOT NULL CHECK (kind IN ('income', 'expense')),
            name varchar(80) CHECK (btrim(name) <> ''),
            system_key varchar(60),
            icon varchar(40),
            color char(7) CHECK (color ~ '^#[0-9A-Fa-f]{6}$'),
            sort_order integer NOT NULL DEFAULT 0,
            archived_at timestamptz,
            CONSTRAINT categories_name_or_key CHECK (name IS NOT NULL OR system_key IS NOT NULL),
            CONSTRAINT categories_not_own_parent CHECK (parent_id <> id),
            CONSTRAINT categories_user_id_kind_uq UNIQUE (user_id, id, kind),
            CONSTRAINT categories_system_key_uq UNIQUE (user_id, system_key)
            SQL);

        // Subcategoria do mesmo usuário e do mesmo tipo.
        FinanceSchema::foreign('categories', 'categories_parent_fk', 'user_id, parent_id, kind', 'categories', 'user_id, id, kind');

        DB::statement('CREATE INDEX categories_parent_idx ON public.categories (user_id, parent_id) WHERE deleted_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.check_category_depth() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, public, pg_temp
            AS $$
            DECLARE
              c public.categories%ROWTYPE;
            BEGIN
              PERFORM public.assert_row_context(NEW.user_id);

              -- Deferred: judge the row as it is now, not as it was when the trigger was queued.
              SELECT * INTO c FROM public.categories WHERE id = NEW.id;
              IF NOT FOUND OR c.deleted_at IS NOT NULL OR c.parent_id IS NULL THEN
                RETURN NULL;
              END IF;

              IF EXISTS (SELECT 1 FROM public.categories
                          WHERE user_id = c.user_id AND id = c.parent_id AND parent_id IS NOT NULL)
                 OR EXISTS (SELECT 1 FROM public.categories
                             WHERE user_id = c.user_id AND parent_id = c.id AND deleted_at IS NULL) THEN
                RAISE EXCEPTION 'category_depth';
              END IF;
              RETURN NULL;
            END
            $$
            SQL);

        DB::statement(
            'CREATE CONSTRAINT TRIGGER category_depth AFTER INSERT OR UPDATE ON public.categories '
            .'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.check_category_depth()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.categories');
        DB::statement('DROP FUNCTION IF EXISTS public.check_category_depth()');
    }
};
