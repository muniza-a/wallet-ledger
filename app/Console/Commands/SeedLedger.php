<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Seeds 10M+ ledger rows across 100k+ users with an uneven spread.
 *
 * Generates rows in PHP (tracking each user's running balance so it never goes
 * below 0), writes them to TSV chunks and bulk-loads them with LOAD DATA LOCAL INFILE.
 * Secondary indexes are dropped during the load and rebuilt once at the end:
 * one sorted index build is much faster than 10M incremental index inserts.
 */
final class SeedLedger extends Command
{
    protected $signature = 'ledger:seed
        {--rows=10500000 : Total ledger rows to create}
        {--users=100000 : Total users}
        {--whales=50 : Users that get half of all rows between them}
        {--chunk=1000000 : Rows per LOAD DATA file}
        {--fresh : Truncate wallets and ledger_entries first}
        {--skip-verify : Skip the reconciliation check at the end}';

    protected $description = 'Bulk-load a large, valid ledger for performance testing';

    private const SECONDS_OF_HISTORY = 730 * 86400;

    public function handle(): int
    {
        $rows = (int) $this->option('rows');
        $users = (int) $this->option('users');
        $whales = (int) $this->option('whales');
        $chunk = max(10_000, (int) $this->option('chunk'));

        if ($whales < 1 || $users <= $whales || $rows < $users) {
            $this->error('Need: whales >= 1, users > whales, rows >= users.');

            return self::FAILURE;
        }

        $pdo = DB::connection('seed')->getPdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $existing = (int) $pdo->query('SELECT COUNT(*) FROM wallets')->fetchColumn();
        if ($existing > 0 && !$this->option('fresh')) {
            $this->error("wallets already has {$existing} rows. Re-run with --fresh to replace all data.");

            return self::FAILURE;
        }

        $totalStart = microtime(true);
        mt_srand(42); // reproducible data

        if ($this->option('fresh')) {
            $this->phase('Truncating tables', function () use ($pdo) {
                $pdo->exec('TRUNCATE TABLE ledger_entries');
                $pdo->exec('TRUNCATE TABLE wallets');
            });
        }

        [$whaleRows, $regularCounts] = $this->planRowCounts($rows, $users, $whales);
        $this->info(sprintf(
            'Plan: %s rows, %s users (%d whales x %s rows, %s regular users, max %s rows)',
            number_format($rows), number_format($users), $whales, number_format($whaleRows),
            number_format(count($regularCounts)), number_format(max($regularCounts))
        ));

        $pdo->exec('SET SESSION unique_checks = 0');
        $this->phase('Dropping secondary indexes', fn () => $pdo->exec(
            'ALTER TABLE ledger_entries DROP INDEX idx_ledger_user_id_id, DROP INDEX uq_ledger_user_idempotency'
        ));

        try {
            $this->phase('Generating + loading ledger rows', fn () => $this->generateAndLoad($pdo, $whales, $whaleRows, $regularCounts, $chunk));
        } finally {
            // Always restore the indexes, even if the load failed half-way.
            $this->phase('Rebuilding secondary indexes', fn () => $pdo->exec(
                'ALTER TABLE ledger_entries
                   ADD INDEX idx_ledger_user_id_id (user_id, id),
                   ADD UNIQUE INDEX uq_ledger_user_idempotency (user_id, idempotency_key),
                   ALGORITHM=INPLACE, LOCK=NONE'
            ));
            $pdo->exec('SET SESSION unique_checks = 1');
        }

        $this->phase('Analyzing tables', function () use ($pdo) {
            $pdo->query('ANALYZE TABLE wallets, ledger_entries')->fetchAll();
        });

        if (!$this->option('skip-verify') && !$this->verify($pdo)) {
            return self::FAILURE;
        }

        $this->info(sprintf('Seed finished in %.1f s', microtime(true) - $totalStart));

        return self::SUCCESS;
    }

    /**
     * Whales split half the rows; regular users share the rest with a Pareto-like skew.
     *
     * @return array{0: int, 1: list<int>}
     */
    private function planRowCounts(int $rows, int $users, int $whales): array
    {
        $whaleRows = intdiv(intdiv($rows, 2), $whales);
        $regularUsers = $users - $whales;
        $remaining = $rows - $whaleRows * $whales;

        $weights = [];
        $sum = 0.0;
        for ($i = 0; $i < $regularUsers; $i++) {
            $u = mt_rand() / mt_getrandmax();
            $w = (1.0 - $u * 0.999) ** (-1 / 1.3); // Pareto(alpha 1.3), min 1, bounded tail
            $weights[] = $w;
            $sum += $w;
        }

        $counts = [];
        foreach ($weights as $w) {
            $counts[] = max(1, (int) round($w * $remaining / $sum));
        }

        // Fix rounding so the total is exact.
        $diff = $remaining - array_sum($counts);
        for ($i = 0; $diff !== 0; $i = ($i + 1) % $regularUsers) {
            if ($diff > 0) {
                $counts[$i]++;
                $diff--;
            } elseif ($counts[$i] > 1) {
                $counts[$i]--;
                $diff++;
            }
        }

        return [$whaleRows, $counts];
    }

    /** @param list<int> $regularCounts */
    private function generateAndLoad(PDO $pdo, int $whales, int $whaleRows, array $regularCounts, int $chunk): void
    {
        $dir = sys_get_temp_dir();
        $walletsPath = "{$dir}/seed_wallets.tsv";
        $wallets = fopen($walletsPath, 'wb');

        $chunkNo = 0;
        $rowsInChunk = 0;
        $loaded = 0;
        $path = "{$dir}/seed_ledger_{$chunkNo}.tsv";
        $out = fopen($path, 'wb');
        $buf = '';
        $now = time();

        $flushChunk = function () use (&$out, &$buf, &$path, &$chunkNo, &$rowsInChunk, &$loaded, $pdo, $dir) {
            fwrite($out, $buf);
            $buf = '';
            fclose($out);
            $pdo->exec(
                'LOAD DATA LOCAL INFILE '.$pdo->quote($path).' INTO TABLE ledger_entries
                 CHARACTER SET ascii FIELDS TERMINATED BY \'\t\' LINES TERMINATED BY \'\n\'
                 (user_id, type, amount, balance_after, created_at)'
            );
            unlink($path);
            $loaded += $rowsInChunk;
            $this->line(sprintf('  loaded %s rows', number_format($loaded)));
            $chunkNo++;
            $rowsInChunk = 0;
            $path = "{$dir}/seed_ledger_{$chunkNo}.tsv";
            $out = fopen($path, 'wb');
        };

        $emitUser = function (string $userId, int $count) use (&$buf, &$rowsInChunk, &$out, $wallets, $chunk, $flushChunk, $now) {
            $balance = 0;
            $step = max(1, intdiv(self::SECONDS_OF_HISTORY, $count));
            $ts = $now - self::SECONDS_OF_HISTORY;

            for ($i = 0; $i < $count; $i++) {
                // First row is always a credit; a debit is only drawn when it fits the
                // running balance, so no balance is ever below 0 at any point in history.
                if ($i === 0 || $balance < 100 || mt_rand(1, 100) <= 70) {
                    $type = 'credit';
                    $amount = mt_rand(100, 500_000);
                    $balance += $amount;
                } else {
                    $type = 'debit';
                    $amount = mt_rand(100, min($balance, 300_000));
                    $balance -= $amount;
                }
                $buf .= $userId."\t".$type."\t".$amount."\t".$balance."\t".gmdate('Y-m-d H:i:s', $ts)."\n";
                $ts += $step;

                if (++$rowsInChunk % 50_000 === 0) {
                    fwrite($out, $buf);
                    $buf = '';
                }
                if ($rowsInChunk >= $chunk) {
                    $flushChunk();
                }
            }
            fwrite($wallets, $userId."\t".$balance."\n");
        };

        for ($w = 1; $w <= $whales; $w++) {
            $emitUser(sprintf('whale_%02d', $w), $whaleRows);
        }
        foreach ($regularCounts as $i => $count) {
            $emitUser(sprintf('seed_%06d', $i + 1), $count);
        }

        if ($rowsInChunk > 0) {
            $flushChunk();
        }
        fclose($out);
        @unlink($path);
        fclose($wallets);

        // LOAD DATA LOCAL silently skips rows whose key already exists (it implies IGNORE).
        // That only happens if the API created a wallet while the seed was running, which
        // would leave that wallet out of step with the ledger, so fail loudly instead.
        $expectedWallets = $whales + count($regularCounts);
        $loadedWallets = $pdo->exec(
            'LOAD DATA LOCAL INFILE '.$pdo->quote($walletsPath).' INTO TABLE wallets
             CHARACTER SET ascii FIELDS TERMINATED BY \'\t\' LINES TERMINATED BY \'\n\'
             (user_id, balance)'
        );
        unlink($walletsPath);

        if ($loadedWallets !== $expectedWallets) {
            throw new \RuntimeException(sprintf(
                'Loaded %s of %s wallets: %s already existed, so something wrote to the API while seeding. '
                .'Stop all traffic (k6 etc.) and re-run with --fresh.',
                number_format((int) $loadedWallets), number_format($expectedWallets),
                number_format($expectedWallets - (int) $loadedWallets)
            ));
        }
    }

    private function verify(PDO $pdo): bool
    {
        $start = microtime(true);
        $mismatches = (int) $pdo->query(
            "SELECT COUNT(*) FROM wallets w
             LEFT JOIN (
               SELECT user_id, SUM(IF(type = 'credit', amount, -amount)) AS net
               FROM ledger_entries GROUP BY user_id
             ) l ON l.user_id = w.user_id
             WHERE w.balance <> COALESCE(l.net, 0)"
        )->fetchColumn();
        $ledgerUsers = (int) $pdo->query('SELECT COUNT(DISTINCT user_id) FROM ledger_entries')->fetchColumn();
        $walletUsers = (int) $pdo->query('SELECT COUNT(*) FROM wallets')->fetchColumn();
        $ledgerRows = (int) $pdo->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn();

        $this->info(sprintf(
            'Verify (%.1f s): %s ledger rows, %s wallets, %s users in ledger, %d mismatching balances',
            microtime(true) - $start, number_format($ledgerRows), number_format($walletUsers), number_format($ledgerUsers), $mismatches
        ));

        if ($mismatches !== 0 || $ledgerUsers !== $walletUsers) {
            $this->error('Verification FAILED: wallets do not match the ledger.');

            return false;
        }

        return true;
    }

    private function phase(string $label, callable $fn): void
    {
        $start = microtime(true);
        $this->line("{$label}...");
        $fn();
        $this->info(sprintf('  done in %.1f s', microtime(true) - $start));
    }
}
