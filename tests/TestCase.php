<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertUsingTestDatabase();
    }

    protected function assertUsingTestDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();
        if ($database !== 'ledger_test') {
            throw new \RuntimeException("Refusing to run: tests must use the ledger_test database, got '{$database}'.");
        }
    }
}
