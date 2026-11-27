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

    /** @return array{role: string, superuser: bool, bypass_rls: bool, owned_tables: int, memberships: int} */
    public function inspect(): array
    {
        $row = $this->connection->selectOne(<<<'SQL'
            SELECT r.rolname AS role,
                   r.rolsuper AS superuser,
                   r.rolbypassrls AS bypass_rls,
                   (SELECT count(*) FROM pg_class c
                     WHERE c.relowner = r.oid AND c.relkind IN ('r', 'p', 'v', 'm')) AS owned_tables,
                   (SELECT count(*) FROM pg_auth_members m WHERE m.member = r.oid) AS memberships
              FROM pg_roles r
             WHERE r.rolname = current_user
            SQL);

        return [
            'role' => (string) $row->role,
            'superuser' => (bool) $row->superuser,
            'bypass_rls' => (bool) $row->bypass_rls,
            'owned_tables' => (int) $row->owned_tables,
            'memberships' => (int) $row->memberships,
        ];
    }

    /**
     * @param  array{role: string, superuser: bool, bypass_rls: bool, owned_tables: int, memberships: int}  $facts
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
