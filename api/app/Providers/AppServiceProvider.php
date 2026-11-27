<?php

namespace App\Providers;

use App\Support\Database\RoleGuard;
use App\Support\Heartbeat;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RoleGuard::class, fn ($app) => new RoleGuard($app['db']->connection()));
    }

    public function boot(): void
    {
        // /up fails when the database is unreachable or the role is unsafe.
        Event::listen(DiagnosingHealth::class, fn () => $this->app->make(RoleGuard::class)->assertSafe());

        // HTTP requests refuse any other role on every new connection. Console processes are
        // checked at container start (db:assert-role); migrations run as app_owner.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $serving = ! $this->app->runningInConsole();

            if ($serving && in_array($event->connectionName, ['pgsql', 'pgsql_infra'], true)) {
                RoleGuard::assertRuntimeRole($event->connection);
            }
        });

        // Fired on every iteration of `queue:work`.
        Queue::looping(fn () => Heartbeat::beat());
    }
}
