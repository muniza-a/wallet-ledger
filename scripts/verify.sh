#!/usr/bin/env bash
# End-to-end verification against the running stack.
# Usage:  ./scripts/verify.sh            (stack must be up: docker compose up -d)
#         SKIP_TESTS=1 ./scripts/verify.sh   (only the HTTP + DB checks)
# Safe on a seeded database: it uses fresh random user ids and never truncates.
# Works with macOS's default bash 3.2.

set -u
BASE_URL="${BASE_URL:-http://localhost:8080}"
TOKEN="${API_TOKEN:-recruitment-test-token}"
RUN="v$(date +%s)$RANDOM"
PASS=0
FAIL=0
BODY="$(mktemp)"
trap 'rm -f "$BODY"' EXIT

green() { printf '\033[32m%s\033[0m\n' "$1"; }
red()   { printf '\033[31m%s\033[0m\n' "$1"; }
pass()  { PASS=$((PASS + 1)); green "  PASS  $1"; }
fail()  { FAIL=$((FAIL + 1)); red   "  FAIL  $1"; [ -n "${2:-}" ] && echo "        $2"; }

# call METHOD PATH [BODY] [AUTH_HEADER] [EXTRA_HEADER] -> prints HTTP status, body in $BODY
call() {
  local method="$1" path="$2" data="${3:-}" auth="${4-Authorization: Bearer $TOKEN}" extra="${5:-}"
  local args=(-s -o "$BODY" -w '%{http_code}' -X "$method" "$BASE_URL$path" -H 'Content-Type: application/json')
  [ -n "$auth" ] && args+=(-H "$auth")
  [ -n "$extra" ] && args+=(-H "$extra")
  [ -n "$data" ] && args+=(--data "$data")
  curl "${args[@]}"
}

# expect NAME EXPECTED_STATUS EXPECTED_BODY_SUBSTRING METHOD PATH [BODY] [AUTH] [EXTRA]
expect() {
  local name="$1" want_status="$2" want_body="$3"; shift 3
  local status; status="$(call "$@")"
  local body; body="$(cat "$BODY")"
  if [ "$status" = "$want_status" ] && [[ "$body" == *"$want_body"* ]]; then
    pass "$name"
  else
    fail "$name" "expected $want_status containing '$want_body', got $status: $body"
  fi
}

sql() { docker compose exec -T mysql mysql -uledger -pledger ledger -N -B -e "$1" 2>/dev/null; }

echo "== 0. Stack"
if curl -s -o /dev/null "$BASE_URL/balance/x"; then pass "API reachable at $BASE_URL"; else fail "API reachable at $BASE_URL" "run: docker compose up -d"; exit 1; fi
if [ "$(sql 'SELECT 1')" = "1" ]; then pass "MySQL reachable"; else fail "MySQL reachable"; exit 1; fi

echo "== 1. Auth (checked before routing and before any DB access)"
expect "no Authorization header -> 401"      401 '"error":"unauthorized"' GET  /balance/u1 "" ""
expect "empty Authorization header -> 401"   401 '"error":"unauthorized"' GET  /balance/u1 "" "Authorization;"
expect "wrong scheme -> 401"                 401 '"error":"unauthorized"' GET  /balance/u1 "" "Authorization: Basic abc"
expect "wrong token -> 401"                  401 '"error":"unauthorized"' GET  /balance/u1 "" "Authorization: Bearer nope"
expect "no token on credit -> 401"           401 '"error":"unauthorized"' POST /credit '{"user_id":"u1","amount":1}' ""
expect "no token on unknown route -> 401"    401 '"error":"unauthorized"' GET  /nope "" ""
if grep -q "$TOKEN" "$BODY"; then fail "token never echoed in responses"; else pass "token never echoed in responses"; fi
expect "no token on /health -> 401"          401 '"error":"unauthorized"' GET  /health "" ""
expect "GET /health -> 200"                  200 '{"status":"ok"}' GET /health
BIG="{\"user_id\":\"u1\",\"amount\":1,\"pad\":\"$(head -c 70000 /dev/zero | tr '\0' x)\"}"
expect "body over 64 KB -> 413 JSON"         413 '"error":"payload_too_large"' POST /credit "$BIG"

echo "== 2. Contract (user ${RUN}_a)"
U="${RUN}_a"
expect "balance of unknown user -> 404"      404 '"error":"user_not_found"' GET  "/balance/$U"
expect "debit of unknown user -> 404"        404 '"error":"user_not_found"' POST /debit  "{\"user_id\":\"$U\",\"amount\":1}"
expect "first credit creates user -> 200"    200 "{\"user_id\":\"$U\",\"balance\":10000}" POST /credit "{\"user_id\":\"$U\",\"amount\":10000}"
expect "second credit -> 25000"              200 "\"balance\":25000" POST /credit "{\"user_id\":\"$U\",\"amount\":15000}"
expect "debit -> 21000"                      200 "\"balance\":21000" POST /debit  "{\"user_id\":\"$U\",\"amount\":4000}"
expect "get_balance -> 21000"                200 "{\"user_id\":\"$U\",\"balance\":21000}" GET "/balance/$U"
expect "overdraw -> 409"                     409 '"error":"insufficient_funds"' POST /debit "{\"user_id\":\"$U\",\"amount\":21001}"
expect "balance unchanged after 409"         200 "\"balance\":21000" GET "/balance/$U"
expect "debit to exactly 0 -> 200"           200 "\"balance\":0" POST /debit "{\"user_id\":\"$U\",\"amount\":21000}"
expect "unknown fields ignored"              200 "\"balance\":5" POST /credit "{\"user_id\":\"$U\",\"amount\":5,\"note\":\"x\"}"
ROWS="$(sql "SELECT COUNT(*) FROM ledger_entries WHERE user_id='$U'")"
if [ "$ROWS" = "5" ]; then pass "one ledger row per successful write (5), none for 409/404"; else fail "ledger rows for $U" "expected 5, got $ROWS"; fi

echo "== 3. Validation -> 400 invalid_request"
for body in \
  '{"user_id":"u1","amount":' '' '[1,2]' '{}' '{"amount":1}' '{"user_id":"u1"}' \
  '{"user_id":"u1","amount":0}' '{"user_id":"u1","amount":-1}' '{"user_id":"u1","amount":1.5}' \
  '{"user_id":"u1","amount":10.0}' '{"user_id":"u1","amount":1e3}' '{"user_id":"u1","amount":"100"}' \
  '{"user_id":"u1","amount":true}' '{"user_id":"u1","amount":1000000001}' \
  '{"user_id":"","amount":1}' '{"user_id":"a b","amount":1}' '{"user_id":"u1\n","amount":1}' \
  '{"user_id":123,"amount":1}' "{\"user_id\":\"$(printf 'a%.0s' $(seq 1 65))\",\"amount\":1}"; do
  expect "credit body ${body:0:45}" 400 '"error":"invalid_request"' POST /credit "$body"
done
expect "invalid user_id in path"             400 '"error":"invalid_request"' GET "/balance/a%20b"
expect "amount 1000000000 accepted"          200 '"balance":1000000000' POST /credit "{\"user_id\":\"${RUN}_max\",\"amount\":1000000000}"

echo "== 4. Idempotency-Key"
U="${RUN}_idem"
expect "first keyed credit"                  200 '"balance":500' POST /credit "{\"user_id\":\"$U\",\"amount\":500}" "Authorization: Bearer $TOKEN" "Idempotency-Key: k-1"
expect "replay returns original response"    200 '"balance":500' POST /credit "{\"user_id\":\"$U\",\"amount\":500}" "Authorization: Bearer $TOKEN" "Idempotency-Key: k-1"
expect "same key, different amount -> 422"   422 '"error":"idempotency_key_reused"' POST /credit "{\"user_id\":\"$U\",\"amount\":999}" "Authorization: Bearer $TOKEN" "Idempotency-Key: k-1"
ROWS="$(sql "SELECT COUNT(*) FROM ledger_entries WHERE user_id='$U'")"
if [ "$ROWS" = "1" ]; then pass "replay wrote no extra ledger row"; else fail "replay rows" "expected 1, got $ROWS"; fi

echo "== 5. Concurrency smoke (curl in parallel)"
U="${RUN}_race"
call POST /credit "{\"user_id\":\"$U\",\"amount\":100}" >/dev/null
S1="$(mktemp)"; S2="$(mktemp)"
curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/debit" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' --data "{\"user_id\":\"$U\",\"amount\":60}" >"$S1" &
curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE_URL/debit" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' --data "{\"user_id\":\"$U\",\"amount\":60}" >"$S2" &
wait
GOT="$(printf '%s\n%s\n' "$(cat "$S1")" "$(cat "$S2")" | sort | tr '\n' ' ')"
rm -f "$S1" "$S2"
if [ "$GOT" = "200 409 " ]; then pass "two debits of 60 vs 100 -> one 200, one 409"; else fail "two debits of 60 vs 100" "got: $GOT"; fi
expect "balance after race is 40"            200 '"balance":40' GET "/balance/$U"

U="${RUN}_burst"
for i in $(seq 1 200); do
  curl -s -o /dev/null -X POST "$BASE_URL/credit" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' --data "{\"user_id\":\"$U\",\"amount\":100}" &
done
wait
expect "200 parallel credits of 100 -> 20000" 200 '"balance":20000' GET "/balance/$U"

echo "== 6. Ledger reconciliation (whole database)"
MISMATCH="$(sql "SELECT COUNT(*) FROM wallets w LEFT JOIN (SELECT user_id, SUM(IF(type='credit',amount,-amount)) net FROM ledger_entries GROUP BY user_id) l ON l.user_id=w.user_id WHERE w.balance <> COALESCE(l.net,0)")"
if [ "$MISMATCH" = "0" ]; then pass "every wallet equals credits - debits"; else fail "wallets vs ledger" "$MISMATCH mismatching wallets"; fi
NEG="$(sql 'SELECT COUNT(*) FROM wallets WHERE balance < 0')"
if [ "$NEG" = "0" ]; then pass "no negative balances"; else fail "negative balances" "$NEG wallets"; fi

if [ -z "${SKIP_TESTS:-}" ]; then
  echo "== 7. PHPUnit suites"
  if docker compose exec -T app grep -q '<server name="DB_DATABASE"' phpunit.xml; then
    if docker compose exec -T app php artisan test --testsuite=Feature >/tmp/feature.log 2>&1; then pass "Feature suite"; else fail "Feature suite" "see /tmp/feature.log"; tail -25 /tmp/feature.log; fi
  else
    fail "Feature suite NOT run" "the image has the old phpunit.xml, which would reset the live 'ledger' DB; update it and rebuild"
  fi
  if docker compose exec -T app php artisan test --testsuite=Concurrency >/tmp/concurrency.log 2>&1; then pass "Concurrency suite"; else fail "Concurrency suite" "see /tmp/concurrency.log"; tail -25 /tmp/concurrency.log; fi
  grep -h "other-user read p95" /tmp/concurrency.log 2>/dev/null
fi

echo
if [ "$FAIL" -eq 0 ]; then green "ALL $PASS CHECKS PASSED"; exit 0; else red "$FAIL FAILED, $PASS passed"; exit 1; fi
