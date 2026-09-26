<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Test transactions also cover the independent `audit` connection (E1.4), so audit facts
     * written outside the business transaction do not leak between tests.
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = ['mysql', 'audit'];

    /**
     * Guard against running tests (which migrate and wipe the database) on anything but a
     * MySQL database named *_test. Checked on the booted application, i.e. on the connection
     * Laravel will actually use, before RefreshDatabase touches it.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'mysql' || ! str_ends_with($database, '_test')) {
            throw new RuntimeException("Odmowa uruchomienia testów: wymagany MySQL i baza *_test (połączenie: {$connection}, baza: {$database}).");
        }

        return $app;
    }
}
