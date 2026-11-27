<?php

namespace App\Console\Commands;

use App\Support\Heartbeat;
use App\Support\Scheduling\RecurringTasks;
use Illuminate\Console\Command;

class Tick extends Command
{
    protected $signature = 'app:tick';

    protected $description = 'Run every recurring task that became due since its last successful run';

    public function handle(RecurringTasks $tasks): int
    {
        $result = $tasks->runDue();

        // Container health: proves the scheduler loop is alive, even when a task fails.
        Heartbeat::beat();

        foreach ($result['ran'] as $name) {
            $this->line("ran {$name}");
        }

        foreach ($result['failed'] as $name) {
            $this->error("failed {$name}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
