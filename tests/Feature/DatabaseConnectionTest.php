<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_on_mysql_8_4_test_database(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->assertStringStartsWith('8.4.', DB::scalar('SELECT VERSION()'));
        $this->assertSame('REPEATABLE-READ', DB::scalar('SELECT @@transaction_isolation'));
    }

    public function test_migrations_run_on_the_test_database(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('jobs'));
    }
}
