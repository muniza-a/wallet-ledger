-- Every wallet must equal its ledger credits minus debits. Expected: 0 rows.
-- Run: docker compose exec mysql mysql -uledger -pledger ledger -e "source /sql/reconcile.sql"
SELECT w.user_id, w.balance, COALESCE(l.net, 0) AS ledger_net
FROM wallets w
LEFT JOIN (
  SELECT user_id, SUM(IF(type = 'credit', amount, -amount)) AS net
  FROM ledger_entries
  GROUP BY user_id
) l ON l.user_id = w.user_id
WHERE w.balance <> COALESCE(l.net, 0)
LIMIT 100;

-- Ledger users with no wallet row. Expected: 0 rows.
SELECT DISTINCT l.user_id
FROM ledger_entries l
LEFT JOIN wallets w ON w.user_id = l.user_id
WHERE w.user_id IS NULL
LIMIT 100;

-- Negative balances. Expected: 0 rows (also blocked by CHECK constraints).
SELECT user_id, balance FROM wallets WHERE balance < 0 LIMIT 100;
