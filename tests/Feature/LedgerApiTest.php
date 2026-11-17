<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

final class LedgerApiTest extends ApiTestCase
{
    public function test_first_credit_creates_user_and_returns_new_balance(): void
    {
        $this->credit('u123', 10000)->assertOk()->assertExactJson(['user_id' => 'u123', 'balance' => 10000]);
        $this->credit('u123', 15000)->assertOk()->assertExactJson(['user_id' => 'u123', 'balance' => 25000]);
        $this->send('GET', '/balance/u123')->assertOk()->assertExactJson(['user_id' => 'u123', 'balance' => 25000]);
    }

    public function test_debit_returns_balance_after_debit(): void
    {
        $this->credit('u123', 25000);
        $this->debit('u123', 4000)->assertOk()->assertExactJson(['user_id' => 'u123', 'balance' => 21000]);
    }

    public function test_debit_to_exactly_zero_is_allowed(): void
    {
        $this->credit('u1', 100);
        $this->debit('u1', 100)->assertOk()->assertJson(['balance' => 0]);
    }

    public function test_overdraw_returns_409_and_writes_nothing(): void
    {
        $this->credit('u1', 100);
        $this->assertError($this->debit('u1', 101), 409, 'insufficient_funds');

        $this->assertSame(100, (int) DB::table('wallets')->where('user_id', 'u1')->value('balance'));
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_unknown_user_returns_404_for_balance_and_debit(): void
    {
        $this->assertError($this->send('GET', '/balance/ghost'), 404, 'user_not_found');
        $this->assertError($this->debit('ghost', 1), 404, 'user_not_found');
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_user_ids_are_case_sensitive(): void
    {
        $this->credit('abc', 5);
        $this->credit('ABC', 7);
        $this->send('GET', '/balance/abc')->assertJson(['balance' => 5]);
        $this->send('GET', '/balance/ABC')->assertJson(['balance' => 7]);
    }

    public function test_every_operation_writes_one_immutable_ledger_row(): void
    {
        $this->credit('u1', 500);
        $this->debit('u1', 200);
        $this->debit('u1', 1000); // 409, nothing written

        $rows = DB::table('ledger_entries')->where('user_id', 'u1')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['credit', 500, 500], [$rows[0]->type, (int) $rows[0]->amount, (int) $rows[0]->balance_after]);
        $this->assertSame(['debit', 200, 300], [$rows[1]->type, (int) $rows[1]->amount, (int) $rows[1]->balance_after]);
        $this->assertNotNull($rows[0]->created_at);
    }

    public function test_wallet_matches_ledger_after_a_sequence(): void
    {
        foreach ([[1, 'c', 1000], [2, 'd', 300], [3, 'c', 50], [4, 'd', 750], [5, 'd', 1]] as [, $op, $amt]) {
            $op === 'c' ? $this->credit('u1', $amt) : $this->debit('u1', $amt);
        }
        $net = (int) DB::table('ledger_entries')->where('user_id', 'u1')
            ->sum(DB::raw("IF(type = 'credit', amount, -amount)"));
        $this->assertSame($net, (int) DB::table('wallets')->where('user_id', 'u1')->value('balance'));
        $this->assertSame(0, $net);
    }

    public function test_unknown_route_and_method_use_error_shape(): void
    {
        $this->assertError($this->send('GET', '/nope'), 404, 'not_found');
        $this->assertError($this->send('GET', '/credit'), 405, 'method_not_allowed');
    }

    public function test_idempotent_replay_returns_original_response_and_writes_once(): void
    {
        $headers = ['Idempotency-Key' => 'req-1'];
        $this->credit('u1', 100, $headers)->assertOk()->assertJson(['balance' => 100]);
        $this->credit('u1', 50); // a different, normal credit in between
        $this->credit('u1', 100, $headers)->assertOk()->assertJson(['balance' => 100]);

        $this->assertDatabaseCount('ledger_entries', 2);
        $this->send('GET', '/balance/u1')->assertJson(['balance' => 150]);
    }

    public function test_idempotency_key_reused_with_different_request_is_rejected(): void
    {
        $this->credit('u1', 100, ['Idempotency-Key' => 'k1'])->assertOk();
        $this->assertError($this->credit('u1', 999, ['Idempotency-Key' => 'k1']), 422, 'idempotency_key_reused');
        $this->assertError($this->debit('u1', 100, ['Idempotency-Key' => 'k1']), 422, 'idempotency_key_reused');
    }

    public function test_idempotency_keys_are_scoped_per_user(): void
    {
        $this->credit('u1', 100, ['Idempotency-Key' => 'same'])->assertJson(['balance' => 100]);
        $this->credit('u2', 200, ['Idempotency-Key' => 'same'])->assertJson(['balance' => 200]);
    }

    public function test_keyed_debit_without_funds_is_409_and_can_be_retried_after_a_credit(): void
    {
        $headers = ['Idempotency-Key' => 'pay-1'];
        $this->assertError($this->debit('ghost', 60, $headers), 404, 'user_not_found');

        $this->credit('u1', 50);
        $this->assertError($this->debit('u1', 60, $headers), 409, 'insufficient_funds');
        $this->assertSame(0, DB::table('ledger_entries')->where('type', 'debit')->count());

        // A 409 writes nothing, so the same key is not "used" yet.
        $this->credit('u1', 50);
        $this->debit('u1', 60, $headers)->assertOk()->assertJson(['balance' => 40]);
        $this->debit('u1', 60, $headers)->assertOk()->assertJson(['balance' => 40]); // replay
        $this->assertSame(1, DB::table('ledger_entries')->where('type', 'debit')->count());
    }
}
