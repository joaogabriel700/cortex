<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 03. users, password_reset_tokens e deleted_accounts.
 *
 * Substitui a parte "users" e "password_reset_tokens" da migration padrão
 * 0001_01_01_000000_create_users_table. NÃO cria sessions (é do DevOps).
 * GRANT e RLS de users e deleted_accounts ficam na migration 20.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE public.users (
                id uuid NOT NULL,
                name varchar(120) NOT NULL,
                email varchar(254) NOT NULL,
                password varchar(255) NOT NULL,
                email_verified_at timestamptz,
                remember_token varchar(100),
                locale varchar(5) NOT NULL DEFAULT 'pt-BR',
                timezone varchar(64) NOT NULL DEFAULT 'America/Sao_Paulo',
                default_currency char(3) NOT NULL DEFAULT 'BRL',
                is_demo boolean NOT NULL DEFAULT false,
                deletion_requested_at timestamptz,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CONSTRAINT users_pkey PRIMARY KEY (id),
                CONSTRAINT users_default_currency_fk FOREIGN KEY (default_currency) REFERENCES public.currencies (code),
                CONSTRAINT users_locale_check CHECK (locale IN ('pt-BR', 'en'))
            )
            SQL);

        DB::statement('CREATE UNIQUE INDEX users_email_uq ON public.users (lower(email))');
        DB::statement('CREATE INDEX users_deletion_idx ON public.users (deletion_requested_at) WHERE deletion_requested_at IS NOT NULL');

        DB::statement(<<<'SQL'
            CREATE TABLE public.password_reset_tokens (
                email varchar(254) NOT NULL,
                token varchar(255) NOT NULL,
                created_at timestamptz,
                CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email)
            )
            SQL);

        // Matriz D2. Sem RLS: usada antes de haver usuário autenticado.
        DB::statement('REVOKE ALL ON public.password_reset_tokens FROM PUBLIC, app_runtime, app_maintenance');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.password_reset_tokens TO app_runtime');
        DB::statement('GRANT SELECT (email), DELETE ON public.password_reset_tokens TO app_maintenance');

        // Registro mínimo de contas excluídas: sem FK, sem dado pessoal, sem RLS (exceção declarada).
        DB::statement(<<<'SQL'
            CREATE TABLE public.deleted_accounts (
                user_id uuid NOT NULL,
                deleted_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT deleted_accounts_pkey PRIMARY KEY (user_id)
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.deleted_accounts');
        DB::statement('DROP TABLE IF EXISTS public.password_reset_tokens');
        DB::statement('DROP TABLE IF EXISTS public.users');
    }
};
