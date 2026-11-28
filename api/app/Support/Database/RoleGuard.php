<?php

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Row level security only protects the data if the application role cannot get around it.
 * The role must not be a superuser, have BYPASSRLS, own tables or be a member of another role.
 */
class RoleGuard
{
    public const RUNTIME_ROLE = 'app_runtime';

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Cheap check for every new connection of the application. The full inspection runs when
     * the containers start and in /up.
     */
    public static function assertRuntimeRole(ConnectionInterface $connection): void
    {
        $row = $connection->selectOne('SELECT current_user AS role, session_user AS session_role');

        if ($row->role !== self::RUNTIME_ROLE || $row->session_role !== self::RUNTIME_ROLE) {
            throw new RuntimeException(sprintf('Unsafe database role "%s": is not app_runtime.', $row->role));
        }
    }

    /** @return array{role: string, session_role: string, superuser: bool, bypass_rls: bool, can_create: bool, owned_tables: int, owned_other: int, memberships: int, usable_members: int} */
    public function inspect(): array
    {
        $row = $this->connection->selectOne(<<<'SQL'
            SELECT r.rolname AS role,
                   session_user AS session_role,
                   r.rolsuper AS superuser,
                   r.rolbypassrls AS bypass_rls,
                   (r.rolcreaterole OR r.rolcreatedb) AS can_create,
                   (SELECT count(*) FROM pg_database d WHERE d.datdba = r.oid)
                     + (SELECT count(*) FROM pg_namespace n WHERE n.nspowner = r.oid)
                     + (SELECT count(*) FROM pg_class s WHERE s.relowner = r.oid AND s.relkind = 'S')
                     + (SELECT count(*) FROM pg_proc f WHERE f.proowner = r.oid) AS owned_other,
                   (SELECT count(*) FROM pg_auth_members u JOIN pg_roles g ON g.oid = u.roleid
                     WHERE g.rolname IN ('app_runtime', 'app_maintenance')
                       AND (u.inherit_option OR u.set_option)) AS usable_members,
                   (SELECT count(*) FROM pg_class c
                     WHERE c.relowner = r.oid AND c.relkind IN ('r', 'p', 'v', 'm')) AS owned_tables,
                   (SELECT count(*) FROM pg_auth_members m WHERE m.member = r.oid) AS memberships
              FROM pg_roles r
             WHERE r.rolname = current_user
            SQL);

        return [
            'role' => (string) $row->role,
            'session_role' => (string) $row->session_role,
            'superuser' => (bool) $row->superuser,
            'bypass_rls' => (bool) $row->bypass_rls,
            'can_create' => (bool) $row->can_create,
            'owned_tables' => (int) $row->owned_tables,
            'owned_other' => (int) $row->owned_other,
            'memberships' => (int) $row->memberships,
            'usable_members' => (int) $row->usable_members,
        ];
    }

    /**
     * @param  array{role: string, session_role: string, superuser: bool, bypass_rls: bool, can_create: bool, owned_tables: int, owned_other: int, memberships: int, usable_members: int}  $facts
     * @return list<string>
     */
    public static function violations(array $facts): array
    {
        return array_keys(array_filter([
            'is not app_runtime' => $facts['role'] !== self::RUNTIME_ROLE,
            'is a superuser' => $facts['superuser'],
            'has BYPASSRLS' => $facts['bypass_rls'],
            'owns tables or views' => $facts['owned_tables'] > 0,
            'is a member of another role' => $facts['memberships'] > 0,
            'signed in as another role' => $facts['session_role'] !== self::RUNTIME_ROLE,
            'can create roles or databases' => $facts['can_create'],
            'owns a database, schema, sequence or function' => $facts['owned_other'] > 0,
            'another role can act as an application role' => $facts['usable_members'] > 0,
        ]));
    }

    public function assertSafe(): void
    {
        $facts = $this->inspect();
        $violations = self::violations($facts);

        if ($violations !== []) {
            throw new RuntimeException(sprintf('Unsafe database role "%s": %s.', $facts['role'], implode(', ', $violations)));
        }
    }
}
