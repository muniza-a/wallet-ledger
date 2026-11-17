-- Every wallet must equal its ledger credits minus debits.
-- Run: docker compose exec mysql mysql -uledger -pledger ledger -e "source /sql/reconcile.sql"

-- Summary. Expected: mismatching_wallets, ledger_users_without_wallet and negative_balances are 0.
SELECT
  (SELECT COUNT(*) FROM ledger_entries) AS ledger_rows,
  (SELECT COUNT(*) FROM wallets) AS wallets,
  (SELECT COUNT(*)
     FROM wallets w
     LEFT JOIN (
       SELECT user_id, SUM(IF(type = 'credit', amount, -amount)) AS net
       FROM ledger_entries
       GROUP BY user_id
     ) l ON l.user_id = w.user_id
     WHERE w.balance <> COALESCE(l.net, 0)) AS mismatching_wallets,
  (SELECT COUNT(DISTINCT l.user_id)
     FROM ledger_entries l
     LEFT JOIN wallets w ON w.user_id = l.user_id
     WHERE w.user_id IS NULL) AS ledger_users_without_wallet,
  (SELECT COUNT(*) FROM wallets WHERE balance < 0) AS negative_balances;

-- Details: the offending rows, if any. Expected: no rows from any of these.
SELECT w.user_id, w.balance, COALESCE(l.net, 0) AS ledger_net
FROM wallets w
LEFT JOIN (
  SELECT user_id, SUM(IF(type = 'credit', amount, -amount)) AS net
  FROM ledger_entries
  GROUP BY user_id
) l ON l.user_id = w.user_id
WHERE w.balance <> COALESCE(l.net, 0)
LIMIT 100;

SELECT DISTINCT l.user_id
FROM ledger_entries l
LEFT JOIN wallets w ON w.user_id = l.user_id
WHERE w.user_id IS NULL
LIMIT 100;

SELECT user_id, balance FROM wallets WHERE balance < 0 LIMIT 100;
