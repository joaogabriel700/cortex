<?php

use App\Support\Database\RoleGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function can(string $role, string $table, string $privilege): bool
{
    return (bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, $table, $privilege])->ok;
}

it('connects as app_runtime, which cannot get around row level security', function () {
    expect(app(RoleGuard::class)->inspect())->toBe([
        'role' => 'app_runtime', 'superuser' => false, 'bypass_rls' => false, 'owned_tables' => 0, 'memberships' => 0,
    ]);
    expect(Artisan::call('db:assert-role'))->toBe(0);
});

it('creates no application role with elevated attributes', function () {
    $roles = DB::select(<<<'SQL'
        SELECT rolname, rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb AS elevated, rolcanlogin
          FROM pg_roles WHERE rolname LIKE 'app\_%' ORDER BY rolname
        SQL);

    expect(array_map(fn ($r) => [$r->rolname, $r->elevated, $r->rolcanlogin], $roles))->toBe([
        ['app_auth', false, false],
        ['app_maintenance', false, true],
        ['app_owner', false, true],
        ['app_runtime', false, true],
    ]);
});

it('has a single membership between roles: app_owner may SET app_auth without inheriting it', function () {
    $grants = DB::select(<<<'SQL'
        SELECT g.rolname AS granted, u.rolname AS member, m.inherit_option, m.set_option
          FROM pg_auth_members m
          JOIN pg_roles g ON g.oid = m.roleid
          JOIN pg_roles u ON u.oid = m.member
         WHERE g.rolname LIKE 'app\_%' OR u.rolname LIKE 'app\_%'
        SQL);

    expect(array_map(fn ($m) => (array) $m, $grants))->toBe([
        ['granted' => 'app_auth', 'member' => 'app_owner', 'inherit_option' => false, 'set_option' => true],
    ]);
});

it('uses a database owned by app_owner', function () {
    $owner = DB::selectOne('SELECT pg_get_userbyid(datdba) AS owner FROM pg_database WHERE datname = current_database()');

    expect($owner->owner)->toBe('app_owner');
});

it('denies temporary objects to every application role', function (string $role) {
    $row = DB::selectOne("SELECT has_database_privilege(?, current_database(), 'TEMPORARY') AS ok", [$role]);

    expect((bool) $row->ok)->toBeFalse();
})->with(['app_owner', 'app_auth', 'app_runtime', 'app_maintenance']);

it('cannot create a temporary table', function () {
    DB::statement('CREATE TEMPORARY TABLE should_not_exist (id int)');
})->throws(QueryException::class, 'permission denied');

it('has no default privileges for the application roles or PUBLIC', function () {
    $row = DB::selectOne(<<<'SQL'
        SELECT count(*) AS n
          FROM pg_default_acl d
         CROSS JOIN LATERAL aclexplode(d.defaclacl) a
          LEFT JOIN pg_roles r ON r.oid = a.grantee
         WHERE r.rolname LIKE 'app\_%' OR (a.grantee = 0 AND d.defaclobjtype IN ('r', 'S'))
        SQL);

    expect((int) $row->n)->toBe(0);
});

it('lets no application role but the owner create objects in the schema', function (string $role) {
    $row = DB::selectOne("SELECT has_schema_privilege(?, 'public', 'CREATE') AS ok", [$role]);

    expect((bool) $row->ok)->toBeFalse();
})->with(['app_auth', 'app_runtime', 'app_maintenance']);

it('grants the framework tables exactly as the role matrix says', function (string $role, string $table, array $allowed) {
    foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'] as $privilege) {
        expect(can($role, $table, $privilege))
            ->toBe(in_array($privilege, $allowed, true), "{$role} {$privilege} on {$table}");
    }
})->with([
    ['app_runtime', 'sessions', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']],
    ['app_runtime', 'cache', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']],
    ['app_runtime', 'cache_locks', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']],
    ['app_runtime', 'jobs', ['SELECT', 'INSERT', 'UPDATE', 'DELETE']],
    ['app_runtime', 'failed_jobs', ['SELECT', 'INSERT', 'DELETE']],
    ['app_runtime', 'migrations', []],
    ['app_maintenance', 'sessions', ['DELETE']],
    ['app_maintenance', 'cache', []],
    ['app_maintenance', 'cache_locks', []],
    ['app_maintenance', 'jobs', []],
    ['app_maintenance', 'failed_jobs', []],
    ['app_maintenance', 'migrations', []],
]);

it('lets app_maintenance read only the user_id column of sessions', function () {
    $column = fn (string $name) => (bool) DB::selectOne(
        "SELECT has_column_privilege('app_maintenance', 'sessions', ?, 'SELECT') AS ok", [$name]
    )->ok;

    expect($column('user_id'))->toBeTrue()->and($column('payload'))->toBeFalse();
});

it('cannot change the schema', function () {
    DB::statement('CREATE TABLE should_not_exist (id int)');
})->throws(QueryException::class, 'permission denied');

it('has no API token table: authentication is cookie based', function () {
    expect(DB::selectOne("SELECT to_regclass('public.personal_access_tokens') AS t")->t)->toBeNull();
});

it('has no maintenance credentials outside the scheduler', function () {
    expect(config('database.connections.pgsql_maintenance.password'))->toBeNull();
});
