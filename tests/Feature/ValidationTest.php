<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

final class ValidationTest extends ApiTestCase
{
    public static function invalidBodies(): array
    {
        return [
            'malformed json' => ['{"user_id": "u1", "amount": '],
            'empty body' => [''],
            'json array' => ['[1,2]'],
            'json string' => ['"hello"'],
            'json null' => ['null'],
            'empty object' => ['{}'],
            'missing user_id' => ['{"amount": 100}'],
            'missing amount' => ['{"user_id": "u1"}'],
            'amount zero' => ['{"user_id": "u1", "amount": 0}'],
            'amount negative' => ['{"user_id": "u1", "amount": -5}'],
            'amount decimal' => ['{"user_id": "u1", "amount": 1.5}'],
            'amount float integral' => ['{"user_id": "u1", "amount": 10.0}'],
            'amount exponent' => ['{"user_id": "u1", "amount": 1e3}'],
            'amount string' => ['{"user_id": "u1", "amount": "100"}'],
            'amount bool' => ['{"user_id": "u1", "amount": true}'],
            'amount null' => ['{"user_id": "u1", "amount": null}'],
            'amount too large' => ['{"user_id": "u1", "amount": 1000000001}'],
            'amount huge' => ['{"user_id": "u1", "amount": 99999999999999999999999}'],
            'user_id empty' => ['{"user_id": "", "amount": 1}'],
            'user_id too long' => ['{"user_id": "'.str_repeat('a', 65).'", "amount": 1}'],
            'user_id space' => ['{"user_id": "a b", "amount": 1}'],
            'user_id trailing newline' => ['{"user_id": "u1\n", "amount": 1}'],
            'user_id unicode' => ['{"user_id": "ü", "amount": 1}'],
            'user_id dot' => ['{"user_id": "a.b", "amount": 1}'],
            'user_id number' => ['{"user_id": 123, "amount": 1}'],
            'user_id null' => ['{"user_id": null, "amount": 1}'],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_credit_rejects_invalid_body(string $body): void
    {
        $this->assertError($this->send('POST', '/credit', $body), 400, 'invalid_request');
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    #[DataProvider('invalidBodies')]
    public function test_debit_rejects_invalid_body(string $body): void
    {
        $this->assertError($this->send('POST', '/debit', $body), 400, 'invalid_request');
    }

    public function test_boundary_values_are_accepted(): void
    {
        $max = str_repeat('a', 64);
        $this->credit($max, 1)->assertOk()->assertExactJson(['user_id' => $max, 'balance' => 1]);
        $this->credit('A-z_0-9', 1_000_000_000)->assertOk()->assertJson(['balance' => 1_000_000_000]);
    }

    public function test_unknown_fields_are_ignored(): void
    {
        $this->send('POST', '/credit', '{"user_id":"u1","amount":5,"note":"x","currency":"USD"}')
            ->assertOk()->assertExactJson(['user_id' => 'u1', 'balance' => 5]);
    }

    public function test_balance_rejects_invalid_user_id_in_path(): void
    {
        $this->assertError($this->send('GET', '/balance/a%20b'), 400, 'invalid_request');
        $this->assertError($this->send('GET', '/balance/'.str_repeat('a', 65)), 400, 'invalid_request');
    }

    public function test_invalid_idempotency_key_is_rejected(): void
    {
        $this->assertError($this->credit('u1', 5, ['Idempotency-Key' => str_repeat('k', 129)]), 400, 'invalid_request');
        $this->assertError($this->credit('u1', 5, ['Idempotency-Key' => 'has space']), 400, 'invalid_request');
    }
}
