<?php

use App\Support\Scheduling\RecurringTasks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function at(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function dailyAtThree(int &$runs): RecurringTasks
{
    $tasks = new RecurringTasks(Cache::store());
    $tasks->register('daily', '0 3 * * *', function () use (&$runs) {
        $runs++;
    });

    return $tasks;
}

it('runs a daily task once per day even when triggers are late and never hit its minute', function () {
    $runs = 0;
    $tasks = dailyAtThree($runs);

    $tasks->runDue(at('2026-01-01 02:52'));   // first tick ever: catches up the previous day
    $tasks->runDue(at('2026-01-01 03:17'));   // 17 minutes late
    $tasks->runDue(at('2026-01-01 03:32'));
    $tasks->runDue(at('2026-01-02 02:59'));

    expect($runs)->toBe(2);

    $tasks->runDue(at('2026-01-02 03:29'));

    expect($runs)->toBe(3);
});

it('catches up once after several missed days', function () {
    $runs = 0;
    $tasks = dailyAtThree($runs);

    $tasks->runDue(at('2026-01-01 03:00'));
    $tasks->runDue(at('2026-01-05 11:11'));
    $tasks->runDue(at('2026-01-05 11:26'));

    expect($runs)->toBe(2);
});

it('retries a failed task on the next tick without blocking the others', function () {
    $attempts = 0;
    $others = 0;
    $tasks = new RecurringTasks(Cache::store());
    $tasks->register('flaky', '0 * * * *', function () use (&$attempts) {
        if (++$attempts === 1) {
            throw new RuntimeException('boom');
        }
    });
    $tasks->register('other', '0 * * * *', function () use (&$others) {
        $others++;
    });

    expect($tasks->runDue(at('2026-01-01 10:05')))->toBe(['ran' => ['other'], 'failed' => ['flaky']]);
    expect($tasks->runDue(at('2026-01-01 10:06')))->toBe(['ran' => ['flaky'], 'failed' => []]);
    expect([$attempts, $others])->toBe([2, 1]);
});

it('registers the failed job pruning and runs it through app:tick', function () {
    expect(app(RecurringTasks::class)->names())->toContain('prune-failed-jobs');

    $this->artisan('app:tick')->expectsOutputToContain('ran prune-failed-jobs')->assertSuccessful();
});
