<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Guard: the tests write to the database, so they only run on the test database.
        if (config('database.connections.pgsql.database') !== 'cortex_test') {
            throw new RuntimeException('Tests must run against the cortex_test database. Use `make test-api`.');
        }
    }
}
