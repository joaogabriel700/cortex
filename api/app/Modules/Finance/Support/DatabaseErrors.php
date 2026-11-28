<?php

namespace App\Modules\Finance\Support;

use App\Support\Http\ProblemException;
use Illuminate\Database\QueryException;

/** Turns database refusals into codes of the contract, without the PostgreSQL message. */
class DatabaseErrors
{
    private const RAISED = ['currency_immutable' => 409, 'category_depth' => 422, 'currency_counter_amount' => 422];

    public static function toProblem(QueryException $e): ProblemException|QueryException
    {
        $state = (string) $e->getCode();

        if ($state === 'P0001') {
            foreach (self::RAISED as $code => $status) {
                if (str_contains($e->getMessage(), $code)) {
                    return new ProblemException($code, $status);
                }
            }
        }

        return match ($state) {
            // The same answer for both, so an id taken by another account is not revealed.
            '23505', '42501' => new ProblemException('write_rejected', 409),
            '23503' => new ProblemException('invalid_reference', 422),
            default => $e,
        };
    }
}
