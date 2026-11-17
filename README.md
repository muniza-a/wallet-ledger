# Wallet Ledger API

HTTP API (Laravel 12, PHP 8.4, Nginx + PHP-FPM) backed by MySQL 8.4 that keeps a money ledger for many users and exposes `credit`, `debit` and `get_balance`.

## Run

```bash
docker compose up --build          # API on http://localhost:8080, migrations run automatically
```

Token: `API_TOKEN` (default `recruitment-test-token` in `docker-compose.yml`). Override with `API_TOKEN=xyz docker compose up`.

```bash
curl -s -X POST localhost:8080/credit -H "Authorization: Bearer recruitment-test-token" \
     -H "Content-Type: application/json" -d '{"user_id":"u123","amount":10000}'
curl -s localhost:8080/balance/u123 -H "Authorization: Bearer recruitment-test-token"
```

| Task | Command |
| --- | --- |
| Seed 10.5M rows / 100k users | `docker compose exec app php artisan ledger:seed --fresh` |
| Seed 1M rows (comparison) | `docker compose exec app php artisan ledger:seed --fresh --rows=1000000` |
| Feature tests (uses `ledger_test` DB) | `docker compose exec app php artisan test --testsuite=Feature` |
| Concurrency tests (real HTTP, safe on seeded DB) | `docker compose exec app php artisan test --testsuite=Concurrency` |
| Load test (k6) | `docker compose --profile loadtest run --rm k6` |
| End-to-end checks against the running stack | `./scripts/verify.sh` |
| Crash test (kills MySQL and the API mid-load) | `./scripts/crash-test.sh` |
| Health | `curl localhost:8080/health -H "Authorization: Bearer recruitment-test-token"` |
| Reconcile wallets vs ledger | `docker compose exec mysql mysql -uledger -pledger ledger -e "source /sql/reconcile.sql"` |
| Query plans | `docker compose exec mysql mysql -uledger -pledger ledger -t -e "source /sql/explain.sql"` |

## Design

**Schema.** `ledger_entries` is the append-only source of truth (`id`, `user_id`, `type`, `amount`, `balance_after`, `idempotency_key`, `created_at`); rows are never updated or deleted. `wallets` holds one row per user with the current balance. Both are written in the same transaction, so the wallet is a materialized sum of the ledger that cannot drift. See `database/migrations/`.

- `user_id` is `VARCHAR(64) ascii_bin`: the allowed alphabet is ASCII (1 byte/char, smaller indexes) and matching is case-sensitive (`u1` ≠ `U1`).
- Money is `BIGINT` minor units. `CHECK` constraints enforce `balance >= 0`, `amount > 0`, `balance_after >= 0` in the database itself.
- Indexes: `wallets` PK `user_id`; `ledger_entries` PK `id` (appends at the end of the clustered index), `(user_id, id)` for per-user history, unique `(user_id, idempotency_key)` for idempotency.
- No foreign key from ledger to wallets: an FK adds a shared lock and a lookup per insert; the transaction already guarantees both rows.

**How the balance is computed.** `GET /balance/{id}` is a primary-key lookup on `wallets`: constant time at any ledger size, and a non-locking read that is never blocked by writers. It never scans the ledger.

**How concurrent writes stay safe** (`app/Services/LedgerService.php`). Each credit/debit is one short transaction:

1. Credit: `INSERT INTO wallets … ON DUPLICATE KEY UPDATE balance = balance + ?`. Debit: `UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND balance >= ?`. This first statement takes the exclusive lock on that one wallet row. For a debit, the check and the write are one atomic statement: a second concurrent debit waits on the row lock and then re-evaluates `balance >= ?` against the new value, so it gets 0 rows and a 409. There is no read-then-write gap.
2. `SELECT balance … FOR UPDATE` reads back the value we hold the lock on.
3. `INSERT INTO ledger_entries` with `balance_after`.
4. `COMMIT`.

**Isolation level: READ COMMITTED.** Correctness comes from the row lock plus the conditional update, not from the isolation level. READ COMMITTED avoids the gap/next-key locks of REPEATABLE READ, which only add deadlock risk here.

**Deadlocks / lock timeouts** (MySQL 1213 / 1205) are retried up to 5 times with jitter; they never surface as a 500 or a wrong balance. If retries are exhausted the API returns `503 service_unavailable` (not observed in testing).

**Atomicity / crashes.** The wallet change and the ledger row commit together or not at all. InnoDB rolls back any uncommitted transaction after a crash, and `innodb_flush_log_at_trx_commit=1` makes committed writes durable.

**Read-your-writes.** One primary database; the response is sent only after `COMMIT`.

**Isolation across users.** Locks are per wallet row. Ledger inserts use `innodb_autoinc_lock_mode=2` (no table-level AUTO-INC lock). A hot user only queues requests for that same user.

**Auth.** Global middleware runs before routing and before any DB access (unknown routes also get 401). Constant-time comparison: `hash_equals` over SHA-256 of both values, so the token length is not leaked either. The token comes from the environment only, and is never logged or returned. If it is unset, every request is rejected.

**Validation.** Hand-written (`app/Support/RequestValidator.php`): Laravel's `integer` rule accepts `"100"`, and its JSON parsing turns malformed JSON into an empty array. `amount` must be a JSON integer (`is_int` after `json_decode`, so `10.0`, `1e3`, `"100"`, `true` and huge numbers are all rejected). `user_id` uses `\A…\z` anchors (`$` would accept a trailing newline).

**Performance on PHP-FPM.** OPcache with `validate_timestamps=0` and JIT, config/route cache, global middleware stack reduced to auth only, raw queries (no Eloquent), persistent MySQL connections, emulated prepares (1 round trip per query instead of 3), static FPM pool of 64 workers, and Nginx → FPM over a Unix socket (no FastCGI keepalive: idle kept-alive connections pin FPM workers and starve new requests).

**Extras.** `Idempotency-Key` header on credit/debit: a replay returns the original response from the ledger row (`balance_after`) and writes nothing; the same key with a different request returns `422`; a concurrent duplicate loses on the unique index, its whole transaction rolls back, and it returns the winner's result. A concurrent duplicate *debit* can fail earlier, on the balance check, because the original already spent the money. Before returning that 409, the service looks up the key again and, if the original committed, returns its result instead, so a client retrying a successful payment never sees "insufficient funds" (`test_concurrent_keyed_debits_replay_instead_of_409`). A `409` writes nothing, so retrying a 409 re-evaluates it. `GET /health` runs `SELECT 1` and returns `{"status":"ok"}` or `503 service_unavailable`. Like every endpoint it needs the bearer token, and the app container's Docker health check calls it with the container's own `API_TOKEN`. Bodies over 64 KB are rejected by Nginx before PHP runs, with `413 payload_too_large` in the same JSON error shape (this check comes before auth, so it is cheap). Graceful shutdown: `STOPSIGNAL SIGQUIT`; the entrypoint drains Nginx, then PHP-FPM (`process_control_timeout=20s`).

### Alternatives considered

| Topic | Rejected | Why |
| --- | --- | --- |
| Balance | `SUM()` over the ledger per request | O(history); whales have 100k+ rows |
| Balance | Latest `balance_after` row via index | Still needs a lock to write safely; no gain over a wallet row |
| Concurrency | `SELECT … FOR UPDATE` then `UPDATE` | Correct, but one extra round trip |
| Concurrency | Optimistic `version` column | Retry storms on a hot user |
| Concurrency | `GET_LOCK()` / Redis locks | Extra calls, leak risk, extra moving part across instances |
| Isolation | REPEATABLE READ / SERIALIZABLE | More gap locks and deadlocks; SERIALIZABLE makes plain reads block behind writers |
| Durability | `innodb_flush_log_at_trx_commit=2` | Faster, but can lose committed writes on power loss |
| Seed | Row-by-row inserts, recursive CTE | Hours / slow and hard to keep non-negative |

## Seed

`php artisan ledger:seed` generates rows in PHP, tracking each user's running balance (first row is always a credit; a debit is only generated when it fits the balance, so no balance is ever below 0 at any point), writes 1M-row TSV chunks and loads them with `LOAD DATA LOCAL INFILE`. Secondary indexes are dropped during the load and rebuilt once at the end. It finishes by reconciling every wallet against the ledger.

**Run the seed with the API idle** (no k6 or other traffic). It is an offline tool: `--fresh` truncates the tables the API writes to, and the unique idempotency index is dropped during the load. If a request creates a wallet mid-seed, the seed's own `wallets` load collides with it (`LOAD DATA LOCAL` silently skips existing keys), so that wallet would disagree with the ledger. The seed counts the wallets it actually loaded and, if any were skipped, stops with an error naming the cause. The ledger is already loaded at that point, so re-run `ledger:seed --fresh` with the API idle.

Distribution: 50 whales (`whale_01`…`whale_50`) with 105,000 rows each (5.25M), and 99,950 users (`seed_000001`…) sharing the other 5.25M with a Pareto-like skew (median ~26, max ~3,000 rows).

**Seed time** (Apple M1, 8 cores, 16 GB RAM; Docker Desktop with 8 CPUs / 7.7 GB):

| Rows | Generate + `LOAD DATA` | Rebuild indexes | Verify | Total |
| --- | --- | --- | --- | --- |
| 1,000,000 | 2.5 s | 1.3 s | 1.1 s | **5.0 s** |
| 10,500,000 | 24.3 s | 13.9 s | 9.9 s | **48.2 s** |

The verify step reconciles every wallet against the ledger: 0 mismatching balances at both sizes. Raw output: `loadtest/results/*/1-seed.txt`.

## Query plans

From `sql/explain.sql` at 10,594,531 ledger rows (`loadtest/results/10.5m/3-explain.txt`). Every statement on the request path touches exactly one wallet row through the primary key; none reads `ledger_entries`.

**get_balance**: `SELECT balance FROM wallets WHERE user_id = ?`

```
| select_type | table   | type  | key     | key_len | ref   | rows |
| SIMPLE      | wallets | const | PRIMARY | 66      | const |    1 |

EXPLAIN ANALYZE -> Rows fetched before execution (actual time=0.00892..0.00892 rows=1 loops=1)
```

`const` means MySQL resolves the row by a primary-key lookup while planning, before execution. The same query measured 0.0153 ms at 1M rows: both are a single descent of the 7 MB `wallets` primary key, so the time does not depend on the size of the ledger.

**credit**: step 1, `INSERT INTO wallets … ON DUPLICATE KEY UPDATE balance = wallets.balance + new.balance`

```
| select_type | table   | type | key  | rows |
| INSERT      | wallets | ALL  | NULL | NULL |
```

**debit**: step 1, `UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND balance >= ?`

```
| select_type | table   | type  | key     | key_len | ref   | rows | Extra       |
| UPDATE      | wallets | range | PRIMARY | 66      | const |    1 | Using where |
```

**credit and debit**: step 2, `SELECT balance FROM wallets WHERE user_id = ? FOR UPDATE`

```
| select_type | table   | type  | key     | key_len | ref   | rows |
| SIMPLE      | wallets | const | PRIMARY | 66      | const |    1 |
```

**credit and debit**: step 3, `INSERT INTO ledger_entries (…) VALUES (…)`

```
| select_type | table          | type | key  | rows |
| INSERT      | ledger_entries | ALL  | NULL | NULL |
```

Reading the plans:

- `type: ALL` on an `INSERT` is not a table scan. MySQL prints it for every single-row `INSERT … VALUES`, since there is nothing to look up. The upsert in the credit finds an existing wallet through the `PRIMARY` key when the insert collides with it. The ledger insert appends at the end of the clustered index (auto-increment `id`).
- The debit `UPDATE` shows `range` on `PRIMARY` with `rows = 1`: an equality on the full primary key, with `balance >= ?` checked on that one row (`Using where`).
- The debit's 409/404 branch (`SELECT 1 FROM wallets WHERE user_id = ?`) is the same `const` primary-key lookup as get_balance.
- The idempotency replay lookup (`WHERE user_id = ? AND idempotency_key = ?`) is an equality on the full `uq_ledger_user_idempotency` unique key. MySQL resolves it at plan time too; for the sample key, which does not exist, it prints `no matching row in const table`.

## Load test results

`loadtest/k6.js`: 100 VUs of mixed traffic (50% balance, 35% credit, 15% debit, random users from the seed) for 60 s, then a 30 s hot-user phase: 90 VUs writing credits and debits to `whale_01` (105,000 ledger rows) while 10 VUs read other users. Same machine as the seed; k6 runs in Docker next to the API. Raw output: `loadtest/results/*/2-k6.txt`.

| Endpoint / phase | p50 @ 1M | p99 @ 1M | p50 @ 10.5M | p99 @ 10.5M |
| --- | --- | --- | --- | --- |
| balance (mixed) | 34.0 ms | 108.9 ms | 42.5 ms | 153.1 ms |
| credit (mixed) | 44.5 ms | 130.5 ms | 53.5 ms | 184.5 ms |
| debit (mixed) | 44.5 ms | 129.4 ms | 53.9 ms | 187.4 ms |
| credit (hot user) | 61.1 ms | 100.0 ms | 63.4 ms | 132.6 ms |
| debit (hot user) | 61.1 ms | 97.5 ms | 63.5 ms | 130.6 ms |
| balance of other users, during hot phase | 20.8 ms | 31.9 ms | 21.6 ms | 47.5 ms |

| | 1M | 10.5M |
| --- | --- | --- |
| Requests | 192,384 (2,024/s) | 162,040 (1,705/s) |
| Errors (anything but 200/404/409) | 0 | 0 |
| Reconciliation after the run (`sql/reconcile.sql`) | 0 mismatching wallets | 0 mismatching wallets |

**1M vs 10.5M.** The query plans are identical, and the database time per query stays in microseconds at both sizes (the balance lookup takes 0.009–0.015 ms). End to end, this run's mixed-traffic p50 is 20–25% higher at 10.5M and p99 about 40% higher; hot-user latency barely moves (p50 61 → 63 ms), because it is bound by queueing on one row lock. Two effects contribute:

- **Index maintenance on writes.** Every write inserts into the ledger's two secondary indexes at a random position per user. At 10.5M rows those indexes are about ten times larger than at 1M, so more writes touch a page that is not cached yet.
- **Run-to-run noise.** On this laptop, request handling on 8 shared cores dominates every number, not SQL: Nginx, 64 PHP-FPM workers, MySQL and k6 all compete for the same CPUs. An earlier run with the same settings measured p50 within 1–2 ms between the two sizes (10.5M p50 38.9 ms balance, 49.7 ms credit; p99 122–146 ms), and the 1M p50s moved about ±15% between two runs on identical data.

The table shows the latest complete run, so the numbers match the raw files. A fairer comparison would take the median of several runs per size on an otherwise idle machine.

**Buffer pool.** MySQL runs with `innodb_buffer_pool_size=2G`. At 10.5M rows, `ledger_entries` takes about 1.25 GB (682 MB data + 568 MB indexes) and `wallets` 7 MB, so the working set fits in memory. With a 1 GB pool, where it did not fit, the 10.5M mixed-traffic p99 was about 200 ms; with 2 GB it has measured 145–187 ms across runs.

**Hot-user isolation.** While 90 clients hammered `whale_01`, reads for other users stayed at 21–22 ms p50, well below the 34–43 ms p50 of the mixed phase. The hot-user requests spend most of their time waiting on one row lock, which uses no CPU, so the other users' requests compete with fewer busy workers. The concurrency test asserts the same thing with real parallel requests.

## Crash test

`./scripts/crash-test.sh` runs 40 concurrent writers against 20 fresh users. It then `SIGKILL`s MySQL, restarts it, `SIGKILL`s the API container and restarts it, with requests in flight the whole time. Afterwards it checks:

1. Every wallet equals its ledger credits minus debits, across the whole database.
2. No balance is negative.
3. Every write that was answered `200` is in the ledger (acknowledged means durable).
4. The API answers `200` again after recovery.

Last run, on the 10.5M-row database: 7,066 writes answered `200`, all 7,066 in the ledger, 0 mismatching wallets. The ledger holds 7,070 rows for those users: 4 writes committed just as the crash cut off their responses, which is safe (the client sees an error and the ledger is still consistent). While MySQL or the API was down, requests got `500` or no response, as expected. Requests that fail outright are never half-applied.

`tests/Feature/AtomicityTest.php` covers the same rule in-process. A MySQL trigger makes the ledger insert fail after the wallet row was already changed in the same transaction. The tests assert that the wallet change is rolled back, a failed first credit creates no wallet, the row lock is released, and the error leaks no SQL.

## Known limits

- **Hot user throughput.** Writes to one user are serialized on one row lock; the ceiling is roughly 1 / transaction time, bounded by redo-log fsync. If needed: split a hot wallet into N sub-balance rows and sum them on read.
- **FPM workers.** Requests waiting on a hot user's lock occupy FPM workers; at 10× load, workers run out and other users queue. Fixes: more workers, a lower `innodb_lock_wait_timeout`, or an async/long-lived runtime (Octane).
- **Single primary.** The commit rate of one MySQL primary is the write ceiling. Next steps: connection pooling (ProxySQL), faster disks, then sharding by `user_id`.
- **Ledger growth.** At 100M+ rows, index builds, backups and reconciliation scans get slow. Range-partition `ledger_entries` by `id` or month and archive old partitions. API latency stays flat because the hot paths never scan.
- **Several API instances.** Correct as-is: all coordination is in MySQL row locks and unique indexes, and the app is stateless. Connection count multiplies (pool it), and balance reads must stay on the primary: reading from a replica would break read-your-writes.

## With more time

- Laravel Octane (FrankenPHP/Swoole) to remove per-request framework boot.
- OPcache preloading.
- Run `scripts/crash-test.sh` in CI, and add a power-loss variant (kill the Docker VM, not just the process).
- Return `503` instead of `500` while MySQL is unreachable, so clients know to retry.
- Prometheus metrics for lock waits, retries and latency.
