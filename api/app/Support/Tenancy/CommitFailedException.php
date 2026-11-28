<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * A failed COMMIT without the PostgreSQL message, which can quote row values (e-mail, hashes,
 * amounts). Carries only the SQLSTATE and deliberately keeps no previous exception.
 */
class CommitFailedException extends RuntimeException
{
    public function __construct(public readonly string $sqlState)
    {
        parent::__construct("database commit failed (SQLSTATE {$sqlState})");
        $this->code = $sqlState;
    }
}
