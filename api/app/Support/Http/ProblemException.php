<?php

namespace App\Support\Http;

use RuntimeException;

/** An expected error with a stable code of the API contract. */
class ProblemException extends RuntimeException
{
    public function __construct(public readonly string $problem, public readonly int $status)
    {
        parent::__construct($problem);
    }
}
