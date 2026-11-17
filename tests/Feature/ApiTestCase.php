<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ledger.api_token' => self::TOKEN]);
    }

    /**
     * RefreshDatabase calls this hook BEFORE it drops and re-creates tables, so a
     * misconfigured run stops here instead of wiping the seeded database.
     * (Defined here, not in TestCase: a trait's method overrides an inherited one.)
     */
    protected function beforeRefreshingDatabase(): void
    {
        $this->assertUsingTestDatabase();
    }

    /** Send a raw body so we can test malformed JSON and exact types. */
    protected function send(string $method, string $uri, ?string $body = null, ?string $auth = 'Bearer '.self::TOKEN, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($auth !== null) {
            $server['HTTP_AUTHORIZATION'] = $auth;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    protected function credit(string $userId, int $amount, array $headers = []): TestResponse
    {
        return $this->send('POST', '/credit', json_encode(['user_id' => $userId, 'amount' => $amount]), headers: $headers);
    }

    protected function debit(string $userId, int $amount, array $headers = []): TestResponse
    {
        return $this->send('POST', '/debit', json_encode(['user_id' => $userId, 'amount' => $amount]), headers: $headers);
    }

    protected function assertError(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status);
        $response->assertExactJson(['error' => $code, 'message' => $response->json('message')]);
        $this->assertIsString($response->json('message'));
    }
}
