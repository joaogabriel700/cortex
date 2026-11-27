<?php

namespace Database\Migrations\Support;

use Illuminate\Support\Facades\DB;

/**
 * Auxiliar das migrations de Finanças v1.
 *
 * Fica numa subpasta para o migrator do Laravel não tratá-lo como migration.
 * Depois de publicado, fica congelado junto com as migrations que o usam:
 * mudar este arquivo mudaria o resultado de migrations antigas.
 */
final class FinanceSchema
{
    /** Colunas padrão das tabelas sincronizadas (financas.md §3.0). */
    private const SYNC_COLUMNS = <<<'SQL'
        id uuid NOT NULL,
        user_id uuid NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        deleted_at timestamptz,
        sync_version bigint NOT NULL DEFAULT 0,
        last_device_id uuid
        SQL;

    /**
     * Cria uma tabela sincronizada: colunas padrão, PK, UNIQUE (user_id, id),
     * FK para users, índice de download, trigger de carimbo e trigger que trava
     * id, user_id e created_at.
     * $definition traz as colunas e restrições próprias, sem vírgula no fim.
     */
    public static function createSyncTable(string $table, string $definition): void
    {
        $columns = self::SYNC_COLUMNS;

        DB::statement(<<<SQL
            CREATE TABLE public.{$table} (
            {$columns},
            {$definition},
            CONSTRAINT {$table}_pkey PRIMARY KEY (id),
            CONSTRAINT {$table}_user_id_uq UNIQUE (user_id, id),
            CONSTRAINT {$table}_user_fk FOREIGN KEY (user_id) REFERENCES public.users (id) ON DELETE CASCADE
            )
            SQL);

        DB::statement("CREATE INDEX {$table}_sync_idx ON public.{$table} (user_id, sync_version)");

        DB::statement(
            "CREATE TRIGGER {$table}_sync_stamp BEFORE INSERT OR UPDATE ON public.{$table} "
            .'FOR EACH ROW EXECUTE FUNCTION public.stamp_sync_version()'
        );

        // Trocar o id depois de criado contornaria a validação de UUID da API.
        DB::statement(
            "CREATE TRIGGER {$table}_immutable_std BEFORE UPDATE ON public.{$table} FOR EACH ROW "
            .'WHEN (OLD.id IS DISTINCT FROM NEW.id OR OLD.user_id IS DISTINCT FROM NEW.user_id '
            .'OR OLD.created_at IS DISTINCT FROM NEW.created_at) '
            ."EXECUTE FUNCTION public.column_immutable('id, user_id ou created_at')"
        );
    }

    /**
     * FK entre tabelas do usuário: composta, adiada e NO ACTION (nunca RESTRICT).
     */
    public static function foreign(string $table, string $name, string $columns, string $refTable, string $refColumns): void
    {
        DB::statement(
            "ALTER TABLE public.{$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$columns}) "
            ."REFERENCES public.{$refTable} ({$refColumns}) DEFERRABLE INITIALLY DEFERRED"
        );
    }
}
