<?php

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Cron\CronExpression;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Throwable;

/**
 * Catch-up scheduler. A task is due when its cron expression had an occurrence after the
 * last successful run. Late or missed triggers run it once, as soon as the next tick arrives.
 *
 * Rules for tasks: they must be idempotent and process everything that is pending, not only
 * "today". A task that fails is retried on the next tick. The last run is kept in the cache
 * store; if it is lost, the task simply runs once more.
 */
class RecurringTasks
{
    /** @var array<string, array{cron: CronExpression, run: Closure}> */
    private array $tasks = [];

    public function __construct(private readonly Repository $cache) {}

    /** @param  Closure(): void  $run */
    public function register(string $name, string $cron, Closure $run): void
    {
        if (isset($this->tasks[$name])) {
            throw new InvalidArgumentException("Recurring task [{$name}] is already registered.");
        }

        $this->tasks[$name] = ['cron' => new CronExpression($cron), 'run' => $run];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tasks);
    }

    /** @return array{ran: list<string>, failed: list<string>} */
    public function runDue(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now())->utc();
        $result = ['ran' => [], 'failed' => []];

        foreach ($this->tasks as $name => $task) {
            // The lock prevents two overlapping ticks from running the same task.
            $lock = $this->cache->getStore()->lock("recurring:lock:{$name}", 600);

            if (! $lock->get()) {
                continue;
            }

            try {
                $lastRun = $this->cache->get("recurring:last:{$name}");
                $dueAt = $task['cron']->getPreviousRunDate($now, 0, true, 'UTC')->getTimestamp();

                if ($lastRun !== null && (int) $lastRun >= $dueAt) {
                    continue;
                }

                ($task['run'])();

                $this->cache->forever("recurring:last:{$name}", $now->getTimestamp());
                $result['ran'][] = $name;
            } catch (Throwable $exception) {
                report($exception);
                $result['failed'][] = $name;
            } finally {
                $lock->release();
            }
        }

        return $result;
    }
}
