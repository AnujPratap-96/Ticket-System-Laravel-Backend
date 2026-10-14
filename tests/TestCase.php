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

        // Every test runs inside the workspace created by the migration, unless it builds its own.
        if (\Illuminate\Support\Facades\Schema::hasTable('tenants')) {
            $this->defaultTenant = \App\Models\Tenant::where('slug', 'default')->first();
            \App\Tenancy\TenantContext::set($this->defaultTenant);
        }

        $conn = config('database.connections.'.config('database.default'));
        $host = (string) ($conn['host'] ?? '');
        $name = (string) ($conn['database'] ?? '');

        if (! in_array($host, ['127.0.0.1', 'localhost', 'mysql', 'db'], true) || ! str_ends_with($name, '_testing')) {
            throw new RuntimeException("Refusing to run tests against [{$host}/{$name}]. Tests must use a local *_testing database.");
        }
    }

    protected ?\App\Models\Tenant $defaultTenant = null;

    /** Isolation tests that act in several workspaces turn this on and manage the context themselves. */
    protected bool $keepRequestTenant = false;

    /**
     * Every request starts by forgetting the previous workspace (as a real request must). Afterwards put the test's own
     * workspace back so the lines that follow can keep creating data in it.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            if ($this->defaultTenant && ! $this->keepRequestTenant) {
                \App\Tenancy\TenantContext::set($this->defaultTenant);
            }
        }
    }
}
