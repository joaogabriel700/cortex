<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Categories\CategoryWriter;
use App\Support\Http\Fields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends RecordController
{
    public function __construct(CategoryWriter $writer)
    {
        parent::__construct($writer);
    }

    public function index(Request $request): JsonResponse
    {
        $kind = $request->query('kind');

        if ($kind !== null && ! in_array($kind, ['income', 'expense'], true)) {
            throw Fields::failure([['kind', 'in']]);
        }

        $query = $this->listQuery($request, 'categories');

        if ($kind !== null) {
            $query->where('kind', $kind);
        }

        return response()->json(['data' => $query->get()->map($this->writer->toArray(...))->all()]);
    }
}
