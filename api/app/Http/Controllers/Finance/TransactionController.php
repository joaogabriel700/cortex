<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Ledger\Statement;
use App\Modules\Finance\Ledger\TransactionWriter;
use App\Support\Http\ProblemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TransactionController extends RecordController
{
    public function __construct(TransactionWriter $writer)
    {
        parent::__construct($writer);
    }

    public function index(Request $request, Statement $statement): JsonResponse
    {
        $query = $request->query();
        unset($query['kind']);
        $page = $statement->page($request->user()->id, $query, $this->repeated($request, 'kind'));

        return response()->json([
            'data' => array_map($this->writer->toArray(...), $page['rows']),
            'meta' => ['next_cursor' => $page['next_cursor'], 'limit' => $page['limit']],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $row = $this->writer->find($request->user()->id, $id) ?? throw new ProblemException('not_found', 404);

        return $this->record($row);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->writer->delete($request->user()->id, $id, $request->headers->get('If-Match'));

        return response()->noContent();
    }

    /**
     * "?kind=income&kind=expense", as the contract defines it; PHP alone keeps only the last.
     * "kind[]=" is accepted too.
     *
     * @return list<string>
     */
    private function repeated(Request $request, string $name): array
    {
        $values = [];

        foreach (explode('&', (string) $request->server->get('QUERY_STRING')) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];

            if (preg_match('/^'.$name.'(\[\d*\])?$/D', urldecode($key)) === 1) {
                $values[] = urldecode($value);
            }
        }

        return $values;
    }
}
