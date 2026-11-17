-- Query plans for the README. EXPLAIN on INSERT/UPDATE does not execute them.
-- Run: docker compose exec mysql mysql -uledger -pledger ledger -t -e "source /sql/explain.sql"
SELECT COUNT(*) AS ledger_rows FROM ledger_entries;

-- get_balance
EXPLAIN SELECT balance FROM wallets WHERE user_id = 'whale_01';
EXPLAIN ANALYZE SELECT balance FROM wallets WHERE user_id = 'whale_01';

-- credit: step 1 (create-or-add)
EXPLAIN INSERT INTO wallets (user_id, balance) VALUES ('whale_01', 100) AS new
  ON DUPLICATE KEY UPDATE balance = wallets.balance + new.balance;

-- debit: step 1 (conditional update)
EXPLAIN UPDATE wallets SET balance = balance - 100 WHERE user_id = 'whale_01' AND balance >= 100;

-- credit/debit: step 2 (read back under lock)
EXPLAIN SELECT balance FROM wallets WHERE user_id = 'whale_01' FOR UPDATE;

-- credit/debit: step 3 (append ledger row)
EXPLAIN INSERT INTO ledger_entries (user_id, type, amount, balance_after, idempotency_key)
  VALUES ('whale_01', 'credit', 100, 100, NULL);

-- idempotency replay lookup
EXPLAIN SELECT type, amount, balance_after FROM ledger_entries
  WHERE user_id = 'whale_01' AND idempotency_key = 'some-key';
