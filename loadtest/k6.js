// Load test: mixed traffic across seeded users, then a hot-user phase.
// Run: docker compose --profile loadtest run --rm k6
// Reports p50 / p95 / p99 per endpoint (see the tagged http_req_duration lines).
import http from 'k6/http';
import { check } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const TOKEN = __ENV.API_TOKEN || 'recruitment-test-token';
const SEED_USERS = parseInt(__ENV.SEED_USERS || '99950', 10);
const HOT_USER = __ENV.HOT_USER || 'whale_01';

const params = (endpoint, scenario) => ({
  headers: { Authorization: `Bearer ${TOKEN}`, 'Content-Type': 'application/json' },
  tags: { endpoint, phase: scenario },
});

// 404 and 409 are valid business answers; only anything else counts as a failure.
http.setResponseCallback(http.expectedStatuses(200, 404, 409));

export const options = {
  summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
  scenarios: {
    mixed: { executor: 'constant-vus', vus: 100, duration: '60s', exec: 'mixed' },
    hot_writes: { executor: 'constant-vus', vus: 90, duration: '30s', startTime: '65s', exec: 'hotUser' },
    hot_other_reads: { executor: 'constant-vus', vus: 10, duration: '30s', startTime: '65s', exec: 'otherReads' },
  },
  thresholds: {
    http_req_failed: ['rate==0'],
    // Thresholds on tagged sub-metrics make k6 print a separate line per endpoint.
    'http_req_duration{endpoint:credit,phase:mixed}': ['p(99)<10000'],
    'http_req_duration{endpoint:debit,phase:mixed}': ['p(99)<10000'],
    'http_req_duration{endpoint:balance,phase:mixed}': ['p(99)<10000'],
    'http_req_duration{endpoint:credit,phase:hot}': ['p(99)<10000'],
    'http_req_duration{endpoint:debit,phase:hot}': ['p(99)<10000'],
    'http_req_duration{endpoint:balance,phase:hot_other}': ['p(99)<10000'],
  },
};

const randomSeedUser = () => `seed_${String(1 + Math.floor(Math.random() * SEED_USERS)).padStart(6, '0')}`;
const amount = (max) => 1 + Math.floor(Math.random() * max);

function post(endpoint, user, amt, phase) {
  const res = http.post(`${BASE_URL}/${endpoint}`, JSON.stringify({ user_id: user, amount: amt }), params(endpoint, phase));
  check(res, { [`${endpoint} ok`]: (r) => r.status === 200 || r.status === 409 });
}

function getBalance(user, phase) {
  const res = http.get(`${BASE_URL}/balance/${user}`, params('balance', phase));
  check(res, { 'balance ok': (r) => r.status === 200 });
}

export function mixed() {
  const r = Math.random();
  const user = randomSeedUser();
  if (r < 0.5) getBalance(user, 'mixed');
  else if (r < 0.85) post('credit', user, amount(100000), 'mixed');
  else post('debit', user, amount(50000), 'mixed');
}

export function hotUser() {
  if (Math.random() < 0.5) post('credit', HOT_USER, amount(1000), 'hot');
  else post('debit', HOT_USER, amount(1000), 'hot');
}

export function otherReads() {
  getBalance(randomSeedUser(), 'hot_other');
}
