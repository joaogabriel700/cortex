<?php

namespace App\Providers;

use App\Support\Database\RoleGuard;
use App\Support\Heartbeat;
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

        // Fired on every iteration of `queue:work`.
        Queue::looping(fn () => Heartbeat::beat());
    }
}
