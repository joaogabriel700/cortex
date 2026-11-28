<?php

namespace App\Modules\Finance\Support;

use App\Support\Http\Fields;
use App\Support\Http\ProblemException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only write path of a synchronized record: the future sync must go through it too.
 * Needs the tenant transaction of the user. Subclasses declare fields and business rules.
 */
abstract class RecordWriter
{
    abstract protected function table(): string;

    /**
     * Field rules of the contract; all of them are required.
     *
     * @return array<string, \Closure>
     */
    abstract protected function rules(?object $current): array;

    /**
     * The record as the API returns it.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(object $row): array;

    /**
     * Normalizes the input, so that a repeated request compares equal.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalize(array $data): array
    {
        if (isset($data['archived_at'])) {
            $data['archived_at'] = Values::instant($data['archived_at']);
        }

        return $data;
    }

    /** Checks that need the database, such as references to other records of the user. */
    protected function checkReferences(string $userId, string $id, array $data, ?object $current): void {}

    /** @return array{0: object, 1: bool} the row and whether it was created now */
    public function create(string $userId, array $input): array
    {
        $this->guardCreate($input);
        $id = $input['id'] ?? null;
        unset($input['id']);

        if (! is_string($id) || ! Str::isUuid($id)) {
            throw Fields::failure([['id', 'uuid']]);
        }

        if (! Str::isUuid($id, 7)) {
            throw new ProblemException('invalid_id_version', 422);
        }

        $id = strtolower($id);
        $data = $this->normalize(Fields::check($input, $this->rules(null)));
        $this->lock($userId);

        $existing = $this->find($userId, $id, withDeleted: true);

        if ($existing !== null) {
            $stored = array_intersect_key($this->toArray($existing), $data);

            // A retry of the same request is not an error and writes nothing.
            if ($existing->deleted_at === null && $this->sorted($stored) === $this->sorted($data)) {
                return [$existing, false];
            }

            throw new ProblemException('write_rejected', 409);
        }

        $this->checkReferences($userId, $id, $data, null);
        $now = $this->databaseInstant(Carbon::now('UTC'));

        $this->write(fn () => DB::table($this->table())->insert(
            ['id' => $id, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now] + $data,
        ));

        return [$this->find($userId, $id), true];
    }

    /** Replaces the whole record when $ifMatch is still its version. */
    public function update(string $userId, string $id, ?string $ifMatch, array $input): object
    {
        if ($ifMatch === null || $ifMatch === '') {
            throw new ProblemException('precondition_required', 428);
        }

        $this->lock($userId);
        $current = $this->find($userId, $id) ?? throw new ProblemException('not_found', 404);

        // The web server renames the ETag of a compressed response ("15" becomes "15-gzip")
        // and the client sends it back as received.
        $ifMatch = preg_replace('/-(gzip|zstd|br)"$/D', '"', $ifMatch);

        if ($ifMatch !== $this->etag($current)) {
            throw new ProblemException('version_conflict', 412, ['current' => $this->toArray($current)]);
        }

        $data = $this->normalize(Fields::check($input, $this->rules($current)));
        $this->checkReferences($userId, $current->id, $data, $current);

        // Always after the stored value, even with a clock behind it ("last edit wins").
        $now = Carbon::now('UTC');
        $next = Carbon::parse($current->updated_at)->utc()->addMicrosecond();
        $updatedAt = $this->databaseInstant($now->greaterThan($next) ? $now : $next);

        $this->write(fn () => DB::table($this->table())
            ->where('user_id', $userId)->where('id', $current->id)
            ->update($data + ['updated_at' => $updatedAt, 'last_device_id' => null]));

        return $this->find($userId, $current->id);
    }

    protected function guardCreate(array $input): void {}

    public function find(string $userId, string $id, bool $withDeleted = false): ?object
    {
        return DB::table($this->table())
            ->where('user_id', $userId)->where('id', $id)
            ->when(! $withDeleted, fn ($query) => $query->whereNull('deleted_at'))
            ->first();
    }

    public function etag(object $row): string
    {
        return '"'.$row->sync_version.'"';
    }

    /**
     * Columns every synchronized record returns; user_id is never returned.
     *
     * @return array<string, mixed>
     */
    protected function syncColumns(object $row): array
    {
        return [
            'id' => $row->id,
            'created_at' => Values::instant($row->created_at),
            'updated_at' => Values::instant($row->updated_at),
            'deleted_at' => Values::instant($row->deleted_at),
            'sync_version' => (int) $row->sync_version,
            'last_device_id' => $row->last_device_id,
        ];
    }

    /** Every write locks the user's counter first, which serializes the user's writes. */
    private function lock(string $userId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Records are written inside the tenant transaction.');
        }

        DB::statement('insert into public.user_sync_state (user_id) values (?) on conflict (user_id) do nothing', [$userId]);
        DB::select('select 1 from public.user_sync_state where user_id = ? for update', [$userId]);
    }

    /** Runs the deferred rules now, so their errors become a normal answer instead of a failed COMMIT. */
    private function write(callable $statement): void
    {
        try {
            $statement();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        } catch (QueryException $e) {
            throw DatabaseErrors::toProblem($e);
        }
    }

    private function databaseInstant(Carbon $at): string
    {
        return $at->format('Y-m-d H:i:s.uP');
    }

    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
