<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    DB::table('jobs')->delete();
});

it('queues and runs a job as the runtime role', function () {
    Queue::connection()->push(function () {
        cache()->forever('probe', 'done');
    });

    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)->and(cache()->get('probe'))->toBe('done');
});

it('stores the session of a same-origin request encrypted, on the infra connection', function () {
    DB::table('sessions')->delete();

    $this->get('/sanctum/csrf-cookie', ['Origin' => 'http://localhost:8080', 'Referer' => 'http://localhost:8080/'])
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');

    expect(config('session.connection'))->toBe('pgsql_infra')
        ->and(config('session.encrypt'))->toBeTrue()
        ->and(DB::table('sessions')->count())->toBe(1);
});

it('dispatches jobs only after the transaction commits', function () {
    expect(config('queue.connections.database.after_commit'))->toBeTrue();
});
