<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Support\RecordWriter;
use App\Support\Http\Fields;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Runs inside the tenant transaction. Every query filters by user_id besides the RLS. */
abstract class RecordController extends Controller
{
    public function __construct(protected readonly RecordWriter $writer) {}

    public function store(Request $request): JsonResponse
    {
        [$row, $created] = $this->writer->create($request->user()->id, $request->json()->all());

        return $this->record($row, $created ? 201 : 200);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = $this->writer->update(
            $request->user()->id, $id, $request->headers->get('If-Match'), $request->json()->all(),
        );

        return $this->record($row);
    }

    protected function record(object $row, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->writer->toArray($row)], $status, ['ETag' => $this->writer->etag($row)]);
    }

    /** Live records of the user, complete (these lists are not paginated). */
    protected function listQuery(Request $request, string $table): Builder
    {
        $archived = $request->query('include_archived', 'false');

        if (! in_array($archived, ['true', 'false'], true)) {
            throw Fields::failure([['include_archived', 'boolean']]);
        }

        return DB::table($table)
            ->where('user_id', $request->user()->id)
            ->whereNull('deleted_at')
            ->when($archived === 'false', fn (Builder $query) => $query->whereNull('archived_at'))
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
