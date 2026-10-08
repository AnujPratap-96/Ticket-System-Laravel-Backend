<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase runs migrate:fresh, which drops every table. Refuse to run
     * unless the connection is clearly a local test database, so a misconfigured
     * .env (e.g. DB_URL pointing at a hosted database) can never be wiped by the suite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $conn = config('database.connections.'.config('database.default'));
        $host = (string) ($conn['host'] ?? '');
        $name = (string) ($conn['database'] ?? '');

        if (! in_array($host, ['127.0.0.1', 'localhost', 'mysql', 'db'], true) || ! str_ends_with($name, '_testing')) {
            throw new RuntimeException("Refusing to run tests against [{$host}/{$name}]. Tests must use a local *_testing database.");
        }
    }
}
