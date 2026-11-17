-- Runs once, on first start of an empty data volume.
-- A separate database for PHPUnit feature tests, so tests never touch seeded data.
CREATE DATABASE IF NOT EXISTS ledger_test;
GRANT ALL PRIVILEGES ON ledger_test.* TO 'ledger'@'%';
FLUSH PRIVILEGES;
