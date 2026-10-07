<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runs before RefreshDatabase and the other test traits are set up.
     * Refuses to continue unless tests are using in-memory SQLite.
     */
    protected function setUpTraits()
    {
        $this->assertTestsUseSafeDatabase();

        return parent::setUpTraits();
    }

    private function assertTestsUseSafeDatabase(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Refusing to run tests: the database connection is [{$connection}] ({$database}), not in-memory SQLite. "
                .'Run tests with .\run-tests.cmd so your real data is not wiped.'
            );
        }
    }
}