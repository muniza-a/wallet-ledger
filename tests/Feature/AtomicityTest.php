<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * A credit or debit is written completely or not at all.
 *
 * A BEFORE INSERT trigger makes the ledger insert fail AFTER the wallet row has
 * already been changed in the same transaction, which is the worst point for a
 * failure. The wallet change must be rolled back with it.
 *
 * The trigger is created and dropped on a separate PDO connection: DDL commits
 * implicitly, and running it on the test connection would commit RefreshDatabase's
 * wrapping transaction.
 */
final class AtomicityTest extends ApiTestCase
{
    private const FAILING_AMOUNT = 777;

    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // The simulated failures are real 500s and get logged with a stack trace; keep the output clean.
        config(['logging.default' => 'null']);

        $c = config('database.connections.mysql');
        $this->admin = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']}",
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->admin->exec('DROP TRIGGER IF EXISTS test_fail_ledger_insert');
        $this->admin->exec(
            'CREATE TRIGGER test_fail_ledger_insert BEFORE INSERT ON ledger_entries FOR EACH ROW
             BEGIN
               IF NEW.amount = '.self::FAILING_AMOUNT." THEN
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated failure after wallet update';
               END IF;
             END"
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown(); // rolls back the test transaction first, releasing its table locks
        $this->admin->exec('DROP TRIGGER IF EXISTS test_fail_ledger_insert');
    }

    public function test_failed_credit_leaves_existing_wallet_unchanged(): void
    {
        $this->credit('u1', 1000)->assertOk();

        $this->assertError($this->credit('u1', self::FAILING_AMOUNT), 500, 'internal_error');

        $this->assertSame(1000, $this->walletBalance('u1'));
        $this->assertSame(1, DB::table('ledger_entries')->where('user_id', 'u1')->count());
    }

    public function test_failed_first_credit_does_not_create_the_wallet(): void
    {
        $this->assertError($this->credit('new_user', self::FAILING_AMOUNT), 500, 'internal_error');

        $this->assertFalse(DB::table('wallets')->where('user_id', 'new_user')->exists());
        $this->assertError($this->send('GET', '/balance/new_user'), 404, 'user_not_found');
    }

    public function test_failed_debit_leaves_wallet_unchanged(): void
    {
        $this->credit('u1', 1000)->assertOk();

        $this->assertError($this->debit('u1', self::FAILING_AMOUNT), 500, 'internal_error');

        $this->assertSame(1000, $this->walletBalance('u1'));
        $this->debit('u1', 1000)->assertOk()->assertJson(['balance' => 0]); // the row lock was released
    }

    public function test_failure_message_does_not_leak_sql(): void
    {
        $response = $this->credit('u1', self::FAILING_AMOUNT);

        $this->assertStringNotContainsString('simulated failure', $response->getContent());
        $this->assertStringNotContainsString('ledger_entries', $response->getContent());
    }

    private function walletBalance(string $userId): int
    {
        return (int) DB::table('wallets')->where('user_id', $userId)->value('balance');
    }
}
