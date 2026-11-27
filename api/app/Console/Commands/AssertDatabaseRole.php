<?php

namespace App\Console\Commands;

use App\Support\Database\RoleGuard;
use Illuminate\Console\Command;

class AssertDatabaseRole extends Command
{
    protected $signature = 'db:assert-role';

    protected $description = 'Fail if the application database role could bypass row level security';

    public function handle(RoleGuard $guard): int
    {
        $facts = $guard->inspect();
        $violations = RoleGuard::violations($facts);

        $summary = sprintf(
            'database role "%s": superuser=%s bypassrls=%s owned_tables=%d memberships=%d',
            $facts['role'],
            $facts['superuser'] ? 'yes' : 'no',
            $facts['bypass_rls'] ? 'yes' : 'no',
            $facts['owned_tables'],
            $facts['memberships'],
        );

        if ($violations !== []) {
            $this->error('UNSAFE '.$summary.' ('.implode(', ', $violations).')');

            return self::FAILURE;
        }

        $this->line('OK '.$summary);

        return self::SUCCESS;
    }
}
