<?php

use App\Support\Database\RoleGuard;

$safe = ['role' => 'app_runtime', 'superuser' => false, 'bypass_rls' => false, 'owned_tables' => 0, 'memberships' => 0];

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
]);
