<?php

use App\Support\Database\RoleGuard;

$safe = [
    'role' => 'app_runtime', 'session_role' => 'app_runtime', 'superuser' => false, 'bypass_rls' => false,
    'can_create' => false, 'owned_tables' => 0, 'owned_other' => 0, 'memberships' => 0, 'usable_members' => 0,
];

it('accepts a restricted role', function () use ($safe) {
    expect(RoleGuard::violations($safe))->toBe([]);
});

it('rejects anything that could get around row level security', function (string $fact, bool|int|string $value) use ($safe) {
    expect(RoleGuard::violations([...$safe, $fact => $value]))->toHaveCount(1);
})->with([
    'another role' => ['role', 'app_owner'],
    'superuser' => ['superuser', true],
    'bypassrls' => ['bypass_rls', true],
    'table owner' => ['owned_tables', 1],
    'role member' => ['memberships', 1],
    'other session user' => ['session_role', 'postgres'],
    'may create roles or databases' => ['can_create', true],
    'owns another object' => ['owned_other', 1],
    'another role can act as the app' => ['usable_members', 1],
]);
