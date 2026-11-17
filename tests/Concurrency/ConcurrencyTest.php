<?php

namespace Tests\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real parallel HTTP requests (curl_multi) against the running API.
 * In-process Laravel tests run one request at a time and cannot exercise races.
 *
 * Run inside the app container while the stack is up:
 *   docker compose exec app php artisan test --testsuite=Concurrency
 *
 * Uses fresh random user ids, so it is safe to run against a seeded database.
 */
final class ConcurrencyTest extends TestCase
{
    private string $baseUrl;
    private string $token;
    private string $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = rtrim(getenv('CONCURRENCY_BASE_URL') ?: 'http://localhost:8080', '/');
        $this->token = (string) (getenv('API_TOKEN') ?: 'recruitment-test-token');
        $this->run = 'ct'.bin2hex(random_bytes(4));

        config(['database.connections.live' => array_merge(
            config('database.connections.mysql'),
            ['database' => getenv('LIVE_DB_DATABASE') ?: 'ledger']
        )]);
    }

    public function test_two_concurrent_debits_of_60_against_100_give_one_200_and_one_409(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $user = "{$this->run}_race{$i}";
            $this->assertSame(200, $this->parallel([$this->req('credit', $user, 100)])[0]['status']);

            $results = $this->parallel([$this->req('debit', $user, 60), $this->req('debit', $user, 60)]);
            $statuses = array_column($results, 'status');
            sort($statuses);

            $this->assertSame([200, 409], $statuses, "iteration {$i}");
            $this->assertSame(40, $this->balanceOf($user));
        }
    }

    public function test_1000_concurrent_credits_have_no_lost_updates(): void
    {
        $user = "{$this->run}_credits";
        $results = $this->parallel(array_fill(0, 1000, $this->req('credit', $user, 100)), 100);

        $this->assertSame([200 => 1000], array_count_values(array_column($results, 'status')));
        $this->assertSame(100_000, $this->balanceOf($user));
        $this->assertSame(1000, $this->live()->table('ledger_entries')->where('user_id', $user)->count());
    }

    public function test_mixed_concurrent_traffic_reconciles_with_the_ledger(): void
    {
        $users = [];
        for ($u = 0; $u < 20; $u++) {
            $users[] = "{$this->run}_mix{$u}";
        }
        $this->parallel(array_map(fn ($u) => $this->req('credit', $u, 10_000), $users));

        mt_srand(7);
        $requests = [];
        for ($i = 0; $i < 4000; $i++) {
            $user = $users[mt_rand(0, count($users) - 1)];
            $requests[] = mt_rand(0, 1)
                ? $this->req('credit', $user, mt_rand(1, 5_000))
                : $this->req('debit', $user, mt_rand(1, 8_000));
        }
        $results = $this->parallel($requests, 100);

        $unexpected = array_filter($results, fn ($r) => !in_array($r['status'], [200, 409], true));
        $this->assertSame([], array_values($unexpected), 'only 200 and 409 are allowed under load');

        foreach ($users as $user) {
            $net = (int) $this->live()->table('ledger_entries')->where('user_id', $user)
                ->sum(DB::raw("IF(type = 'credit', amount, -amount)"));
            $balance = $this->balanceOf($user);
            $this->assertSame($net, $balance, "wallet vs ledger for {$user}");
            $this->assertGreaterThanOrEqual(0, $balance);
        }
    }

    public function test_read_your_writes(): void
    {
        $user = "{$this->run}_ryw";
        $expected = 0;
        for ($i = 0; $i < 200; $i++) {
            $amount = mt_rand(1, 1000);
            $expected += $amount;
            $credit = $this->parallel([$this->req('credit', $user, $amount)])[0];
            $this->assertSame(200, $credit['status']);
            $this->assertSame($expected, $this->balanceOf($user), "read after write #{$i}");
        }
    }

    public function test_concurrent_requests_with_same_idempotency_key_apply_once(): void
    {
        $user = "{$this->run}_idem";
        $results = $this->parallel(array_fill(0, 30, $this->req('credit', $user, 250, 'same-key')), 30);

        $this->assertSame([200 => 30], array_count_values(array_column($results, 'status')));
        $this->assertSame([250], array_values(array_unique(array_map(fn ($r) => $r['json']['balance'], $results))));
        $this->assertSame(250, $this->balanceOf($user));
        $this->assertSame(1, $this->live()->table('ledger_entries')->where('user_id', $user)->count());
    }

    public function test_concurrent_keyed_debits_replay_instead_of_409(): void
    {
        $user = "{$this->run}_idemdebit";
        $this->parallel([$this->req('credit', $user, 100)]);
        $results = $this->parallel(array_fill(0, 20, $this->req('debit', $user, 60, 'pay-1')), 20);

        $this->assertSame([200 => 20], array_count_values(array_column($results, 'status')));
        $this->assertSame(40, $this->balanceOf($user));
        $this->assertSame(1, $this->live()->table('ledger_entries')->where('user_id', $user)->where('type', 'debit')->count());
    }

    public function test_hot_user_does_not_block_other_users(): void
    {
        $hot = "{$this->run}_hot";
        $others = [];
        for ($u = 0; $u < 20; $u++) {
            $others[] = "{$this->run}_other{$u}";
        }
        $this->parallel(array_map(fn ($u) => $this->req('credit', $u, 1_000), [...$others, $hot]));

        $baselineReqs = [];
        for ($i = 0; $i < 200; $i++) {
            $baselineReqs[] = $this->balanceReq($others[$i % 20]);
        }
        $baseline = $this->p95(array_column($this->parallel($baselineReqs, 20), 'time'));

        // 600 writes to one user mixed with 200 reads of other users, all in flight together.
        $mixed = [];
        for ($i = 0; $i < 800; $i++) {
            $mixed[] = $i % 4 === 0 ? $this->balanceReq($others[$i % 20]) : $this->req('credit', $hot, 1);
        }
        $results = $this->parallel($mixed, 100);

        $otherTimes = [];
        foreach ($results as $i => $r) {
            $this->assertSame(200, $r['status']);
            if ($i % 4 === 0) {
                $otherTimes[] = $r['time'];
            }
        }
        $underLoad = $this->p95($otherTimes);
        fwrite(STDERR, sprintf("\n  other-user read p95: baseline %.1f ms, during hot-user load %.1f ms\n", $baseline * 1000, $underLoad * 1000));

        $this->assertSame(1_000 + 600, $this->balanceOf($hot));
        $this->assertLessThan(1.0, $underLoad, 'other users stayed responsive');
    }

    // ---------------------------------------------------------------- helpers

    private function req(string $op, string $user, int $amount, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: '.$idempotencyKey;
        }

        return ['POST', "/{$op}", json_encode(['user_id' => $user, 'amount' => $amount]), $headers];
    }

    private function balanceReq(string $user): array
    {
        return ['GET', "/balance/{$user}", null, []];
    }

    private function balanceOf(string $user): int
    {
        $r = $this->parallel([$this->balanceReq($user)])[0];
        $this->assertSame(200, $r['status'], 'balance lookup');

        return $r['json']['balance'];
    }

    /**
     * Runs requests with up to $concurrency in flight at once.
     *
     * @param  list<array{0:string,1:string,2:?string,3:list<string>}>  $requests
     * @return list<array{status:int, json:mixed, time:float}>
     */
    private function parallel(array $requests, int $concurrency = 100): array
    {
        $multi = curl_multi_init();
        $results = [];
        $handles = [];
        $next = 0;

        $add = function () use (&$next, $requests, $multi, &$handles) {
            [$method, $path, $body, $headers] = $requests[$next];
            $ch = curl_init($this->baseUrl.$path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$this->token,
                    'Content-Type: application/json',
                    ...$headers,
                ],
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            curl_multi_add_handle($multi, $ch);
            $handles[(int) $ch] = $next;
            $next++;
        };

        while ($next < count($requests) && $next < $concurrency) {
            $add();
        }

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
            while ($info = curl_multi_info_read($multi)) {
                $ch = $info['handle'];
                $index = $handles[(int) $ch];
                $results[$index] = [
                    'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                    'json' => json_decode((string) curl_multi_getcontent($ch), true),
                    'time' => (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                ];
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                if ($next < count($requests)) {
                    $add();
                    $running = 1;
                }
            }
        } while ($running > 0 || count($results) < count($requests));

        curl_multi_close($multi);
        ksort($results);

        return array_values($results);
    }

    private function p95(array $times): float
    {
        sort($times);

        return $times[(int) floor(0.95 * (count($times) - 1))];
    }

    private function live(): Connection
    {
        return DB::connection('live');
    }
}
