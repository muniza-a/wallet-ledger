<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

final class AuthTest extends ApiTestCase
{
    public static function badAuthHeaders(): array
    {
        return [
            'missing header' => [null],
            'empty header' => [''],
            'wrong scheme' => ['Basic '.base64_encode('a:b')],
            'lowercase scheme' => ['bearer '.self::TOKEN],
            'no token' => ['Bearer '],
            'wrong token' => ['Bearer nope'],
            'token prefix' => ['Bearer test'],
            'token with suffix' => ['Bearer '.self::TOKEN.'x'],
        ];
    }

    public static function endpoints(): array
    {
        return [
            'credit' => ['POST', '/credit', '{"user_id":"u1","amount":1}'],
            'debit' => ['POST', '/debit', '{"user_id":"u1","amount":1}'],
            'balance' => ['GET', '/balance/u1', null],
            'health' => ['GET', '/health', null],
            'unknown route' => ['GET', '/nope', null],
        ];
    }

    #[DataProvider('badAuthHeaders')]
    public function test_rejects_bad_auth_on_every_endpoint(?string $auth): void
    {
        foreach (self::endpoints() as [$method, $uri, $body]) {
            $this->assertError($this->send($method, $uri, $body, $auth), 401, 'unauthorized');
        }
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_rejects_everything_when_no_token_is_configured(): void
    {
        config(['ledger.api_token' => '']);
        $this->assertError($this->send('GET', '/balance/u1', null, 'Bearer '), 401, 'unauthorized');
    }

    public function test_token_is_never_echoed(): void
    {
        $response = $this->send('GET', '/balance/u1', null, 'Bearer wrong');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
    }

    public function test_valid_token_passes(): void
    {
        $this->credit('u1', 10)->assertOk();
    }
}
