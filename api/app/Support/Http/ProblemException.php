<?php

namespace App\Support\Http;

use RuntimeException;

/** An expected error with a stable code of the API contract. */
class ProblemException extends RuntimeException
{
    /** @param  array<string, mixed>  $extra  more members of the problem document */
    public function __construct(
        public readonly string $problem,
        public readonly int $status,
        public readonly array $extra = [],
    ) {
        parent::__construct($problem);
    }
}
