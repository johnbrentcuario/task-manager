<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseSafetyTest extends TestCase
{
    public function test_laravel_is_using_in_memory_sqlite(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}