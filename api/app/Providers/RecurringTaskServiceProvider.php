<?php

namespace App\Providers;

use App\Support\Scheduling\RecurringTasks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class RecurringTaskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecurringTasks::class, function ($app) {
            $tasks = new RecurringTasks($app['cache']->store());

            $this->define($tasks);

            return $tasks;
        });
    }

    /** Register business tasks here. Cron expressions are evaluated in UTC. */
    private function define(RecurringTasks $tasks): void
    {
        // Failed jobs may hold SQL values: keep them for 7 days at most.
        $tasks->register('prune-failed-jobs', '0 * * * *', function (): void {
            if (Artisan::call('queue:prune-failed', ['--hours' => 168]) !== 0) {
                throw new RuntimeException('queue:prune-failed failed.');
            }
        });
    }
}
