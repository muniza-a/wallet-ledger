<?php

namespace App\Services;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * All money movement lives here.
 *
 * Design: `wallets` holds one row per user with the current balance; `ledger_entries`
 * is append-only. Every credit/debit updates the wallet row AND inserts the ledger row
 * in ONE short READ COMMITTED transaction. The first statement takes an exclusive lock
 * on that single wallet row, so writers for the same user queue up and writers for
 * other users are never blocked.
 */
final class LedgerService
{
    private const ER_DUP_ENTRY = 1062;
    private const ER_LOCK_WAIT_TIMEOUT = 1205;
    private const ER_LOCK_DEADLOCK = 1213;

    public function __construct(private readonly int $maxAttempts = 5)
    {
    }

    public function credit(string $userId, int $amount, ?string $idempotencyKey = null): int
    {
        return $this->write('credit', $userId, $amount, $idempotencyKey, function (ConnectionInterface $db) use ($userId, $amount) {
            // Create-or-add in one statement; takes the X lock on this user's wallet row.
            $db->statement(
                'INSERT INTO wallets (user_id, balance) VALUES (?, ?) AS new
                 ON DUPLICATE KEY UPDATE balance = wallets.balance + new.balance',
                [$userId, $amount]
            );
        });
    }

    /**
     *
     * @param string $userId
     * @param integer $amount
     * @param string|null $idempotencyKey
     * @return integer
     */
    public function debit(string $userId, int $amount, ?string $idempotencyKey = null): int
    {
        return $this->write('debit', $userId, $amount, $idempotencyKey, function (ConnectionInterface $db) use ($userId, $amount) {
            // Check and write in ONE atomic statement. A concurrent debit waits on the row
            // lock, then re-evaluates "balance >= ?" against the new value.
            $affected = $db->update(
                'UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND balance >= ?',
                [$amount, $userId, $amount]
            );

            if ($affected === 0) {
                $exists = $db->selectOne('SELECT 1 AS found FROM wallets WHERE user_id = ?', [$userId]);
                throw $exists ? ApiException::insufficientFunds() : ApiException::userNotFound();
            }
        });
    }

    /**
     *
     * @param string $userId
     * @return integer
     */
    public function balance(string $userId): int
    {
        // Primary-key lookup; non-locking consistent read, never blocked by writers.
        $row = DB::selectOne('SELECT balance FROM wallets WHERE user_id = ?', [$userId]);
        if ($row === null) {
            throw ApiException::userNotFound();
        }

        return (int) $row->balance;
    }

    /**
     * Shared transaction shape for credit and debit.
     *
     * @param  Closure(ConnectionInterface): void  $applyToWallet
     */
    private function write(string $type, string $userId, int $amount, ?string $idempotencyKey, Closure $applyToWallet): int
    {
        if ($idempotencyKey !== null) {
            $replayed = $this->findReplay($userId, $idempotencyKey, $type, $amount);
            if ($replayed !== null) {
                return $replayed;
            }
        }

        try {
            return $this->withRetry(function () use ($type, $userId, $amount, $idempotencyKey, $applyToWallet) {
                $db = DB::connection();
                $db->beginTransaction();
                try {
                    $applyToWallet($db);

                    // We hold the row's X lock until commit, so this is exactly the value we wrote.
                    $balance = (int) $db->selectOne(
                        'SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE',
                        [$userId]
                    )->balance;

                    $db->insert(
                        'INSERT INTO ledger_entries (user_id, type, amount, balance_after, idempotency_key)
                         VALUES (?, ?, ?, ?, ?)',
                        [$userId, $type, $amount, $balance, $idempotencyKey]
                    );

                    $db->commit();

                    return $balance;
                } catch (Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
            });
        } catch (QueryException $e) {
            // Two identical requests raced past findReplay(): the loser's ledger insert hit
            // the (user_id, idempotency_key) unique index and its whole transaction,
            // including its wallet change, was rolled back. Answer with the winner's result.
            if ($idempotencyKey !== null && self::mysqlCode($e) === self::ER_DUP_ENTRY) {
                $replayed = $this->findReplay($userId, $idempotencyKey, $type, $amount);
                if ($replayed !== null) {
                    return $replayed;
                }
            }
            throw $e;
        }
    }

    /**
     *
     * @param string $userId
     * @param string $key
     * @param string $type
     * @param integer $amount
     * @return integer|null
     */
    private function findReplay(string $userId, string $key, string $type, int $amount): ?int
    {
        $row = DB::selectOne(
            'SELECT type, amount, balance_after FROM ledger_entries WHERE user_id = ? AND idempotency_key = ?',
            [$userId, $key]
        );
        if ($row === null) {
            return null;
        }
        if ($row->type !== $type || (int) $row->amount !== $amount) {
            throw ApiException::idempotencyKeyReused();
        }

        return (int) $row->balance_after;
    }

    /**
     * Retry the whole transaction on deadlock / lock-wait timeout, with jitter,
     * so these never surface to the client as an error or a wrong balance.
     */
    private function withRetry(Closure $transaction): int
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $transaction();
            } catch (QueryException $e) {
                if (!self::isRetryable($e) || $attempt >= $this->maxAttempts) {
                    throw $e;
                }
                usleep(random_int(5_000, 30_000) * $attempt);
            }
        }
    }

    public static function isRetryable(QueryException $e): bool
    {
        return in_array(self::mysqlCode($e), [self::ER_LOCK_DEADLOCK, self::ER_LOCK_WAIT_TIMEOUT], true);
    }

    private static function mysqlCode(QueryException $e): ?int
    {
        $code = $e->errorInfo[1] ?? null;

        return $code === null ? null : (int) $code;
    }
}
