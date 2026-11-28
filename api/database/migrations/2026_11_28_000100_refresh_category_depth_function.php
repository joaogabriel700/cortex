<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Databases migrated before the fix of 000600 keep the old check_category_depth(), because
 * Laravel does not run an edited migration again. Replaces it in place, without touching data.
 */
return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        // Nothing to undo: the corrected function is also what 000600 creates.
    }
};
