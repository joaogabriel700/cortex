<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 01. Pré-condições dos papéis e domínio "cents".
 * Os papéis são criados no init do PostgreSQL (DevOps). Aqui só se confere,
 * para falhar cedo e com mensagem clara.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
              IF current_user <> 'app_owner' THEN
                RAISE EXCEPTION 'cortex: rode as migrations como app_owner (atual: %)', current_user;
              END IF;

              IF (SELECT count(*) FROM pg_catalog.pg_roles
                  WHERE rolname IN ('app_owner', 'app_auth', 'app_runtime', 'app_maintenance')) <> 4 THEN
                RAISE EXCEPTION 'cortex: faltam papeis (app_owner, app_auth, app_runtime, app_maintenance)';
              END IF;

              IF EXISTS (SELECT 1 FROM pg_catalog.pg_roles
                  WHERE rolname IN ('app_owner', 'app_auth', 'app_runtime', 'app_maintenance')
                    AND (rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb)) THEN
                RAISE EXCEPTION 'cortex: nenhum papel do app pode ter SUPERUSER, BYPASSRLS, CREATEROLE ou CREATEDB';
              END IF;

              IF EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = 'app_auth' AND rolcanlogin) THEN
                RAISE EXCEPTION 'cortex: app_auth deve ser NOLOGIN';
              END IF;

              IF NOT EXISTS (
                  SELECT 1
                  FROM pg_catalog.pg_auth_members m
                  JOIN pg_catalog.pg_roles g ON g.oid = m.roleid
                  JOIN pg_catalog.pg_roles u ON u.oid = m.member
                  WHERE g.rolname = 'app_auth' AND u.rolname = 'app_owner'
                    AND m.set_option AND NOT m.inherit_option) THEN
                RAISE EXCEPTION 'cortex: falta no init: GRANT app_auth TO app_owner WITH INHERIT FALSE, SET TRUE';
              END IF;

              IF EXISTS (
                  SELECT 1
                  FROM pg_catalog.pg_auth_members m
                  JOIN pg_catalog.pg_roles u ON u.oid = m.member
                  WHERE u.rolname IN ('app_runtime', 'app_maintenance', 'app_auth')) THEN
                RAISE EXCEPTION 'cortex: app_runtime, app_maintenance e app_auth nao podem ser membros de outro papel';
              END IF;

              -- assert_row_context e a trava de conta comparam current_user: um papel
              -- membro herdaria GRANTs e políticas e pularia as duas. Concessão apenas
              -- administrativa (sem INHERIT e sem SET) é tolerada.
              IF EXISTS (
                  SELECT 1
                  FROM pg_catalog.pg_auth_members m
                  JOIN pg_catalog.pg_roles g ON g.oid = m.roleid
                  WHERE g.rolname IN ('app_runtime', 'app_maintenance')
                    AND (m.inherit_option OR m.set_option)) THEN
                RAISE EXCEPTION 'cortex: nenhum papel pode ser membro de app_runtime ou app_maintenance (INHERIT ou SET)';
              END IF;

              IF NOT EXISTS (
                  SELECT 1 FROM pg_catalog.pg_namespace n
                  WHERE n.nspname = 'public' AND pg_catalog.pg_has_role(current_user, n.nspowner, 'USAGE')) THEN
                RAISE EXCEPTION 'cortex: app_owner precisa ser dono do banco (ou do schema public)';
              END IF;

              -- Só app_owner usa app_auth, e sem herdar. Concessão apenas administrativa
              -- (sem INHERIT e sem SET), comum em banco gerenciado, é tolerada.
              IF EXISTS (
                  SELECT 1
                  FROM pg_catalog.pg_auth_members m
                  JOIN pg_catalog.pg_roles g ON g.oid = m.roleid
                  JOIN pg_catalog.pg_roles u ON u.oid = m.member
                  WHERE g.rolname = 'app_auth'
                    AND (m.inherit_option OR (m.set_option AND u.rolname <> 'app_owner'))) THEN
                RAISE EXCEPTION 'cortex: so app_owner pode usar app_auth, e sem INHERIT';
              END IF;

              IF EXISTS (
                  SELECT 1
                  FROM pg_catalog.pg_auth_members m
                  JOIN pg_catalog.pg_roles g ON g.oid = m.roleid
                  JOIN pg_catalog.pg_roles u ON u.oid = m.member
                  WHERE u.rolname = 'app_owner' AND g.rolname <> 'app_auth') THEN
                RAISE EXCEPTION 'cortex: app_owner nao pode ser membro de outro papel';
              END IF;

              -- Privilégio padrão abriria tabela nova antes de ela ter RLS.
              IF EXISTS (
                  SELECT 1
                  FROM pg_catalog.pg_default_acl d
                  CROSS JOIN LATERAL pg_catalog.aclexplode(d.defaclacl) a
                  LEFT JOIN pg_catalog.pg_roles r ON r.oid = a.grantee
                  WHERE r.rolname IN ('app_runtime', 'app_maintenance', 'app_auth')
                     OR (a.grantee = 0 AND d.defaclobjtype IN ('r', 'S'))) THEN
                RAISE EXCEPTION 'cortex: remova os privilegios padrao (pg_default_acl) dos papeis do app ou de PUBLIC';
              END IF;
            END $$
            SQL);

        // Já é o padrão do PostgreSQL 15+, mas banco herdado ou gerenciado pode diferir.
        DB::statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');

        // REVOKE sem permissão só gera aviso: confere o resultado.
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
              IF EXISTS (
                  SELECT 1
                  FROM pg_catalog.pg_namespace n
                  CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(n.nspacl, pg_catalog.acldefault('n', n.nspowner))) a
                  WHERE n.nspname = 'public' AND a.grantee = 0 AND a.privilege_type = 'CREATE') THEN
                RAISE EXCEPTION 'cortex: PUBLIC ainda tem CREATE no schema public';
              END IF;
            END $$
            SQL);

        // Falha, sem apagar nada, se alguma coluna ainda usar o domínio.
        DB::statement('DROP DOMAIN IF EXISTS public.cents');

        // Dinheiro em unidades mínimas; limite = maior inteiro exato em JavaScript.
        DB::statement('CREATE DOMAIN public.cents AS bigint CHECK (VALUE BETWEEN -9007199254740991 AND 9007199254740991)');
    }

    public function down(): void
    {
        DB::statement('DROP DOMAIN IF EXISTS public.cents');
    }
};
