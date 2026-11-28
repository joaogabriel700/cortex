<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Accounts\AccountWriter;
use App\Support\Http\ProblemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends RecordController
{
    public function __construct(AccountWriter $writer)
    {
        parent::__construct($writer);
    }

    /** Balances, history and totals of the contract come with the transactions stage. */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->listQuery($request, 'accounts')->get()->map($this->writer->toArray(...))->all(),
            'meta' => (object) [],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $row = $this->writer->find($request->user()->id, $id) ?? throw new ProblemException('not_found', 404);

        return $this->record($row);
    }
}
