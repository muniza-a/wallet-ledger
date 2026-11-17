#!/usr/bin/env bash
# Crash test: SIGKILL MySQL, then the API container, while writes are in flight.
#
# Afterwards it checks:
#   1. every wallet equals its ledger credits minus debits (whole database)
#   2. no balance is negative
#   3. every write that was answered 200 is in the ledger (acknowledged = durable)
#   4. the API answers 200 again after recovery
#
# Usage: ./scripts/crash-test.sh        (stack must be up: docker compose up -d)
# Safe on a seeded database: it uses fresh user ids and never truncates.
# Works with macOS's default bash 3.2.

set -u
cd "$(dirname "$0")/.."

BASE_URL="${BASE_URL:-http://localhost:8080}"
TOKEN="${API_TOKEN:-recruitment-test-token}"
RUN="crash$(date +%s)"
USERS=20
WORKERS=40
DIR="$(mktemp -d)"
trap 'touch "$DIR/stop"; wait 2>/dev/null; rm -rf "$DIR"' EXIT

FAILED=0
pass() { printf '\033[32m  PASS  %s\033[0m\n' "$1"; }
fail() { printf '\033[31m  FAIL  %s\033[0m\n' "$1"; FAILED=1; }
sql()  { docker compose exec -T mysql mysql -uledger -pledger ledger -N -B -e "$1" 2>/dev/null; }

# post OP USER AMOUNT -> prints the HTTP status ("000" if no response)
post() {
  curl -s -o /dev/null -m 5 -w '%{http_code}' -X POST "$BASE_URL/$1" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    --data "{\"user_id\":\"$2\",\"amount\":$3}"
}

wait_healthy() {
  local id; id="$(docker compose ps -a -q "$1")"
  for _ in $(seq 1 60); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null)" = healthy ] && return 0
    sleep 2
  done
  echo "  $1 did not become healthy"; exit 1
}

# Each worker logs one line per request: "<status> <user_id>".
worker() {
  local out="$DIR/worker$1.log" user op
  while [ ! -f "$DIR/stop" ]; do
    user="${RUN}_$((RANDOM % USERS))"
    if [ $((RANDOM % 2)) -eq 0 ]; then op=credit; else op=debit; fi
    echo "$(post "$op" "$user" $((RANDOM % 500 + 1))) $user" >> "$out"
  done
}

echo "== Setup: $USERS users, $WORKERS concurrent writers"
for i in $(seq 0 $((USERS - 1))); do
  echo "$(post credit "${RUN}_$i" 1000000) ${RUN}_$i" >> "$DIR/setup.log"
done

for w in $(seq 1 $WORKERS); do worker "$w" & done
sleep 3

echo "== SIGKILL MySQL mid-load"
docker compose kill -s SIGKILL mysql >/dev/null 2>&1
sleep 3
docker compose start mysql >/dev/null 2>&1
wait_healthy mysql
echo "   MySQL recovered"
sleep 3

echo "== SIGKILL the API container mid-load"
docker compose kill -s SIGKILL app >/dev/null 2>&1
sleep 2
docker compose start app >/dev/null 2>&1
wait_healthy app
echo "   API recovered"
sleep 3

touch "$DIR/stop"
wait

echo "== Recovery: the API serves writes again"
RECOVERY_OK=0
for i in $(seq 0 $((USERS - 1))); do
  status="$(post credit "${RUN}_$i" 1)"
  echo "$status ${RUN}_$i" >> "$DIR/recovery.log"
  [ "$status" = 200 ] && RECOVERY_OK=$((RECOVERY_OK + 1))
done
if [ "$RECOVERY_OK" -eq "$USERS" ]; then pass "$USERS/$USERS credits after recovery returned 200"; else fail "only $RECOVERY_OK/$USERS credits after recovery returned 200"; fi

echo "== Requests during the test"
cat "$DIR"/*.log | awk '{print $1}' | sort | uniq -c | awk '{printf "   %6d x HTTP %s\n", $1, $2}'
echo "   (non-200 answers are expected while MySQL or the API is down)"

echo "== Consistency"
MISMATCH="$(sql "SELECT COUNT(*) FROM wallets w LEFT JOIN (SELECT user_id, SUM(IF(type='credit',amount,-amount)) net FROM ledger_entries GROUP BY user_id) l ON l.user_id = w.user_id WHERE w.balance <> COALESCE(l.net, 0)")"
if [ "$MISMATCH" = 0 ]; then pass "every wallet equals credits - debits (whole database)"; else fail "$MISMATCH wallets disagree with the ledger"; fi
NEG="$(sql "SELECT COUNT(*) FROM wallets WHERE balance < 0")"
if [ "$NEG" = 0 ]; then pass "no negative balances"; else fail "$NEG negative balances"; fi

echo "== Durability: every 200 is in the ledger"
cat "$DIR"/*.log | awk '$1 == 200 {n[$2]++} END {for (u in n) print u, n[u]}' | sort > "$DIR/acked.txt"
sql "SELECT user_id, COUNT(*) FROM ledger_entries WHERE user_id LIKE '${RUN}\_%' GROUP BY user_id" | sort > "$DIR/ledger.txt"
# One line per user: "<user> <acknowledged> <ledger rows>" (0 if the user has no rows).
join -a 1 -e 0 -o 0,1.2,2.2 "$DIR/acked.txt" "$DIR/ledger.txt" > "$DIR/compare.txt"
if [ "$(wc -l < "$DIR/compare.txt")" -ne "$(wc -l < "$DIR/acked.txt")" ]; then
  fail "could not compare acknowledged writes with the ledger"
fi
LOST="$(awk '$3 < $2 {n++} END {print n + 0}' "$DIR/compare.txt")"
ACKED="$(awk '{s += $2} END {print s + 0}' "$DIR/acked.txt")"
STORED="$(awk '{s += $2} END {print s + 0}' "$DIR/ledger.txt")"
if [ "$LOST" = 0 ]; then pass "$ACKED acknowledged writes, all in the ledger ($STORED rows; extra rows committed but their response was lost in the crash)"; else fail "$LOST users have fewer ledger rows than acknowledged writes"; fi

echo
if [ "$FAILED" = 0 ]; then printf '\033[32mCRASH TEST PASSED\033[0m\n'; else printf '\033[31mCRASH TEST FAILED\033[0m\n'; exit 1; fi
