<?php

namespace App\Http\Controllers\Finance;

use App\Modules\Finance\Accounts\AccountWriter;
use App\Modules\Finance\Accounts\Balances;
use App\Modules\Finance\Support\Values;
use App\Support\Http\Fields;
use App\Support\Http\ProblemException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends RecordController
{
    public function __construct(AccountWriter $writer)
    {
        parent::__construct($writer);
    }

    public function index(Request $request, Balances $balances): JsonResponse
    {
        $user = $request->user();
        // "Today" is the user's day, not the server's.
        $asOf = $request->query('as_of', CarbonImmutable::now($user->timezone)->toDateString());
        $points = $request->query('history_points', '11');
        $errors = [];

        if (Values::date()($asOf) !== null) {
            $errors[] = ['as_of', 'date'];
        }

        if (! is_string($points) || preg_match('/^(\d|1\d|2[0-4])$/D', $points) !== 1) {
            $errors[] = ['history_points', 'out_of_range'];
        }

        if ($errors !== []) {
            throw Fields::failure($errors);
        }

        $rows = $this->listQuery($request, 'accounts')->get();
        $history = Balances::historyDates($asOf, (int) $points);
        $byDate = $balances->at($user->id, array_values(array_unique([$asOf, ...$history])));

        return response()->json([
            'data' => $rows->map(fn (object $row) => $this->writer->toArray($row) + [
                'balance_cents' => $byDate[$row->id][$asOf],
                // No point before the account existed.
                'balance_history' => array_values(array_map(
                    fn (string $date) => ['date' => $date, 'cents' => $byDate[$row->id][$date]],
                    array_filter($history, fn (string $date) => $date >= $row->opening_date),
                )),
            ])->all(),
            'meta' => ['as_of' => $asOf, 'totals' => $balances->totals($user->id, $byDate, $asOf)],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $row = $this->writer->find($request->user()->id, $id) ?? throw new ProblemException('not_found', 404);

        return $this->record($row);
    }
}
