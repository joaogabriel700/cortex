<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * The only place that sets app.user_id, which row level security reads.
 * One user per transaction. The setting is local to the transaction (set_config(..., true)):
 * never clear or change it before COMMIT, the deferred rules would fail.
 */
class TenantContext
{
    private ?string $userId = null;

    public function userId(): ?string
    {
        return $this->userId;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string $userId, Closure $callback): mixed
    {
        if ($this->userId === $userId) {
            return $callback();
        }

        $this->begin($userId);

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        $this->commit();

        return $result;
    }

    public function begin(string $userId): void
    {
        if ($this->userId !== null) {
            throw new LogicException('A transaction cannot switch to another user.');
        }

        if (DB::transactionLevel() !== 0) {
            throw new LogicException('The tenant context must open the outermost transaction.');
        }

        DB::beginTransaction();

        try {
            DB::select("select set_config('app.user_id', ?, true)", [$userId]);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->userId = $userId;
    }

    public function commit(): void
    {
        try {
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack(0);

            throw $e;
        } finally {
            $this->userId = null;
        }
    }

    public function rollBack(): void
    {
        try {
            DB::rollBack(0);
        } finally {
            $this->userId = null;
        }
    }
}
