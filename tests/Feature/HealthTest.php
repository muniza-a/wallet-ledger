<?php

namespace Tests\Feature;

final class HealthTest extends ApiTestCase
{
    public function test_health_reports_ok_when_database_is_reachable(): void
    {
        $this->send('GET', '/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_health_returns_503_when_database_is_unreachable(): void
    {
        $port = config('database.connections.mysql.port');
        config(['database.connections.mysql.port' => 1]); // nothing listens on port 1
        $this->app['db']->purge('mysql');

        try {
            $this->assertError($this->send('GET', '/health'), 503, 'service_unavailable');
        } finally {
            // Restore the real connection so the test-transaction cleanup can run.
            config(['database.connections.mysql.port' => $port]);
            $this->app['db']->purge('mysql');
        }
    }
}
