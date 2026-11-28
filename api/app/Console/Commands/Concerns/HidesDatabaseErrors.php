<?php

namespace App\Console\Commands\Concerns;

use App\Support\Tenancy\CommitFailedException;
use Closure;
use PDOException;

/**
 * The console prints uncaught exceptions, and a database message quotes the values of the
 * statement (e-mail, password hash). Only the SQLSTATE is shown.
 */
trait HidesDatabaseErrors
{
    /** @param  Closure(): int  $callback */
    private function hidingDatabaseErrors(Closure $callback): int
    {
        try {
            return $callback();
        } catch (PDOException|CommitFailedException $e) {
            // QueryException is a PDOException.
            $this->error("Erro no banco de dados (SQLSTATE {$e->getCode()}). Nada foi alterado.");

            return self::FAILURE;
        }
    }
}
