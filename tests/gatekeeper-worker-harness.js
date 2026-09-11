// Harness for the Gatekeeper v4 HTTP worker (cloudflare/gatekeeper/worker.js).
// Fix round 1/5, Finding 1: the property this release exists for — 'unverified'
// must never be served as an actionable 200 — lives in worker.js, not decide.js,
// and a manual trace is not a guard. This drives every scenario through the real
// fetch() entry point (routing + status mapping + body all execute together). The
// one exception is scenario 13, which calls the exported fetchDlm() directly to pin
// its DLM status check; no other helper (loadRecord, readCache, ...) is called directly.
// Run: node tests/gatekeeper-worker-harness.js

let failures = 0;
function check(name, actual, expected) {
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a !== e) { console.error(`FAIL ${name}\n  expected ${e}\n  actual   ${a}`); failures++; }
}
async function scenario(name, fn) {
  try { await fn(); } catch (e) { console.error(`FAIL ${name} threw: ${e && e.stack || e}`); failures++; }
}

// ---- globalThis.caches stub: in-memory, Request/Response-shaped, per-op failure switches ----
// Stubbed BEFORE worker.js is imported, per the fix-round instruction.
const cacheStore = new Map(); // request.url -> { bodyText, headers: {Content-Type, X-Fetched-At} }
const cacheFail = { match: false, put: false, delete: false };
function resetCacheFail() { cacheFail.match = cacheFail.put = cacheFail.delete = false; }

globalThis.caches = {
  default: {
    async match(req) {
      if (cacheFail.match) throw new Error('simulated cache read failure');
      const entry = cacheStore.get(req.url);
      if (!entry) return undefined;
      return new Response(entry.bodyText, { headers: entry.headers });
    },
    async put(req, res) {
      if (cacheFail.put) throw new Error('simulated cache write failure');
      const bodyText = await res.text();
      cacheStore.set(req.url, {
        bodyText,
        headers: {
          'Content-Type': res.headers.get('Content-Type') || 'application/json',
          'X-Fetched-At': res.headers.get('X-Fetched-At') || '0',
          'X-Checked-At': res.headers.get('X-Checked-At') || '0',
          'Cache-Control': res.headers.get('Cache-Control') || '',
        },
      });
    },
    async delete(req) {
      if (cacheFail.delete) throw new Error('simulated cache delete failure');
      return cacheStore.delete(req.url);
    },
  },
};

// ---- globalThis.fetch stub: call-count tracked, per-scenario behaviour swapped in ----
// Stubbed BEFORE worker.js is imported, per the fix-round instruction.
let fetchCalls = 0;
let fetchImpl = async () => { throw new Error('fetchImpl not configured for this scenario'); };
globalThis.fetch = async (url, opts) => { fetchCalls++; return fetchImpl(url, opts); };

// Node 24 provides Request/Response/fetch/crypto.subtle natively — no polyfill needed.
const worker = await import('../cloudflare/gatekeeper/worker.js');
const env = { DLM_KEY: 'test-key', DLM_SECRET: 'test-secret' };

function req(path, { method = 'GET', body } = {}) {
  const url = `https://gatekeepersa.example.workers.dev${path}`;
  const init = { method };
  if (body !== undefined) {
    init.body = JSON.stringify(body);
    init.headers = { 'Content-Type': 'application/json' };
  }
  return new Request(url, init);
}
function dlmJson(body, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}
function resetAll() { cacheStore.clear(); resetCacheFail(); fetchCalls = 0; }

const PAID3 = { product_id: 11414, status: 3, expires_at: null, activations: [] }; // premium3
const PAID1 = { product_id: 11208, status: 3, expires_at: null, activations: [] }; // premium1

// -- 1. DLM reachable, valid paid licence -> 200, status ok, right tier --
await scenario('1 reachable paid licence', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({ success: true, data: PAID3 });
  const res = await worker.default.fetch(req('/check?license_key=PAIDKEY&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('1 status code', res.status, 200);
  check('1 body status', body.status, 'ok');
  check('1 tier', body.tier, 'premium3');
});

// -- 2. DLM returns non-2xx, nothing cached -> 503, unverified (THE assertion this release exists for) --
await scenario('2 DLM non-2xx, no cache', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({}, 500);
  const res = await worker.default.fetch(req('/check?license_key=NOCACHE1&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('2 status code', res.status, 503);
  check('2 body status', body.status, 'unverified');
});

// -- 3. DLM throws / times out, nothing cached -> 503, unverified --
await scenario('3 DLM throws, no cache', async () => {
  resetAll();
  fetchImpl = async () => { throw new Error('simulated network failure'); };
  const res = await worker.default.fetch(req('/check?license_key=NOCACHE2&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('3 status code', res.status, 503);
  check('3 body status', body.status, 'unverified');
});

// -- 4. DLM 200 with a malformed body ({} or success:false) -> 503, unverified --
for (const [label, malformed] of [['empty object', {}], ['success false', { success: false }]]) {
  await scenario(`4 malformed body (${label})`, async () => {
    resetAll();
    fetchImpl = async () => dlmJson(malformed, 200);
    const res = await worker.default.fetch(
      req(`/check?license_key=MALFORMED_${label.replace(/\s/g, '_')}&operation=ttfb&daily_used=0`), env);
    const body = await res.json();
    check(`4 (${label}) status code`, res.status, 503);
    check(`4 (${label}) body status`, body.status, 'unverified');
  });
}

// -- 5. DLM fails now but a cached record exists (aged past freshness) -> 200, stale, tier preserved --
await scenario('5 DLM fails, cached record exists', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({ success: true, data: PAID3 });
  await worker.default.fetch(req('/check?license_key=STALEKEY&operation=ttfb&daily_used=0'), env);
  // Age the one cached entry past the 6h freshness window without waiting on real time.
  const entry = [...cacheStore.values()][0];
  entry.headers['X-Fetched-At'] = String(Date.now() - 7 * 60 * 60 * 1000);

  fetchImpl = async () => dlmJson({}, 500); // DLM now unreachable
  const res = await worker.default.fetch(req('/check?license_key=STALEKEY&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('5 status code', res.status, 200);
  check('5 body status', body.status, 'stale');
  check('5 tier preserved', body.tier, 'premium3');
});

// -- 6. A cached record younger than FRESH_MS is served WITHOUT calling fetch at all --
await scenario('6 fresh cache hit skips fetch', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({ success: true, data: PAID1 });
  await worker.default.fetch(req('/check?license_key=FRESHKEY&operation=ttfb&daily_used=0'), env);
  check('6 prime used fetch once', fetchCalls, 1);

  fetchCalls = 0;
  fetchImpl = async () => { throw new Error('fetch must not be called for a fresh cache hit'); };
  const res = await worker.default.fetch(req('/check?license_key=FRESHKEY&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('6 status code', res.status, 200);
  check('6 body status', body.status, 'ok');
  check('6 tier', body.tier, 'premium1');
  check('6 fetch call count', fetchCalls, 0);
});

// -- 7. GET /activate (wrong method) and an unknown path -> 404 --
await scenario('7 wrong method and unknown path', async () => {
  resetAll();
  const res1 = await worker.default.fetch(req('/activate', { method: 'GET' }), env);
  check('7 GET /activate status', res1.status, 404);
  const res2 = await worker.default.fetch(req('/nope'), env);
  check('7 unknown path status', res2.status, 404);
});

// -- 8. POST /activate, licence not active/grace -> 409, reason preserved --
// SOLD (status:1) must surface reason 'not_delivered', never something implying cancellation.
await scenario('8 activate on a SOLD licence', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({ success: true, data: { product_id: 11414, status: 1, expires_at: null, activations: [] } });
  const res = await worker.default.fetch(
    req('/activate', { method: 'POST', body: { license_key: 'SOLDKEY', site_url: 'https://example.com' } }), env);
  const body = await res.json();
  check('8 status code', res.status, 409);
  check('8 success', body.success, false);
  check('8 reason', body.reason, 'not_delivered');
});

// -- 9. POST /deactivate with no token -> {success:false, reason:'no_token'} --
await scenario('9 deactivate without a token', async () => {
  resetAll();
  const res = await worker.default.fetch(
    req('/deactivate', { method: 'POST', body: { license_key: 'ANYKEY' } }), env);
  const body = await res.json();
  check('9 status code', res.status, 200);
  check('9 success', body.success, false);
  check('9 reason', body.reason, 'no_token');
});

// -- 10/11/12: Fix round 1/5, Finding 2 regression — a throwing cache must degrade, never crash --

// 10. writeCache throws -> a successful DLM answer must still be served, not lost.
await scenario('10 cache write throws', async () => {
  resetAll();
  cacheFail.put = true;
  fetchImpl = async () => dlmJson({ success: true, data: PAID3 });
  const res = await worker.default.fetch(req('/check?license_key=WRITEFAIL&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('10 status code', res.status, 200);
  check('10 body status', body.status, 'ok');
  check('10 tier', body.tier, 'premium3');
});

// 11. readCache throws -> must fall through to a live fetch, not fail the request.
await scenario('11 cache read throws', async () => {
  resetAll();
  cacheFail.match = true;
  fetchImpl = async () => dlmJson({ success: true, data: PAID1 });
  const res = await worker.default.fetch(req('/check?license_key=READFAIL&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('11 status code', res.status, 200);
  check('11 body status', body.status, 'ok');
  check('11 tier', body.tier, 'premium1');
});

// 12. bustCache throws AFTER a real DLM activation succeeded -> the activation result
//     (which already consumed a real slot) must still be reported to the caller.
await scenario('12 cache bust throws after a real activation', async () => {
  resetAll();
  cacheFail.delete = true;
  fetchImpl = async (url) => {
    const path = new URL(url).pathname;
    if (path.includes('/activate/')) return dlmJson({ success: true, data: { token: 'TOK-1' } });
    return dlmJson({ success: true, data: PAID3 });
  };
  const res = await worker.default.fetch(
    req('/activate', { method: 'POST', body: { license_key: 'BUSTFAIL', site_url: 'https://newsite.com' } }), env);
  const body = await res.json();
  check('12 status code', res.status, 200);
  check('12 success', body.success, true);
  check('12 token', body.token, 'TOK-1');
});

// ---- The DLM status check, and the early refetch of a record that is not active ----
const DAY_MS  = 86400000;
const dlmDate = (ms) => new Date(ms).toISOString().slice(0, 19).replace('T', ' ');
const EXPIRED3 = () => ({ product_id: 11414, status: 3, expires_at: dlmDate(Date.now() - 40 * DAY_MS), activations: [] });
const RENEWED3 = () => ({ product_id: 11414, status: 3, expires_at: dlmDate(Date.now() + 365 * DAY_MS), activations: [] });
async function cacheUrlFor(licenseKey) { // the worker's own cache-key derivation
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(licenseKey));
  return `https://gk.internal/dlm/${[...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('')}`;
}
async function ageEntry(licenseKey, ms) { // move both clocks of a cached entry into the past
  const entry = cacheStore.get(await cacheUrlFor(licenseKey));
  const t = String(Date.now() - ms);
  entry.headers['X-Fetched-At'] = t;
  entry.headers['X-Checked-At'] = t;
}
function oddRecord(status) {
  const data = { product_id: 11414, expires_at: null, activations: [] };
  if (status !== undefined) data.status = status;
  return data;
}
const ODD = [['0', 0], ['6', 6], ['none', undefined]];

// 13. fetchDlm, called directly: it is exported for this pin. A status outside 1-5 is a DLM failure.
await scenario('13 fetchDlm rejects a status outside 1-5', async () => {
  resetAll();
  for (const [label, status] of ODD) {
    fetchImpl = async () => dlmJson({ success: true, data: oddRecord(status) });
    check(`13 status ${label}: ok false`, await worker.fetchDlm('ANYKEY', env), { ok: false });
  }
  fetchImpl = async () => dlmJson({ success: true, data: { ...PAID3, status: '3' } });
  check('13 a numeric-string status 3 is still accepted', (await worker.fetchDlm('ANYKEY', env)).ok, true);
});

// 14. Through /check with nothing cached: unverified with a 503, never a confirmed invalid, nothing cached.
await scenario('14 /check never turns an unrecognised status into a confirmed invalid', async () => {
  for (const [label, status] of ODD) {
    resetAll();
    fetchImpl = async () => dlmJson({ success: true, data: oddRecord(status) });
    const res  = await worker.default.fetch(req(`/check?license_key=ODD_${label}&operation=ttfb&daily_used=0`), env);
    const body = await res.json();
    check(`14 status ${label}: 503, unverified, nothing cached`, [res.status, body.status, cacheStore.size], [503, 'unverified', 0]);
  }
});

// 15. A good record cached, and DLM now answers status 6: the stale path serves the good record.
await scenario('15 an unrecognised status with a good record cached is served stale', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({ success: true, data: PAID3 });
  await worker.default.fetch(req('/check?license_key=ODDLATER&operation=ttfb&daily_used=0'), env);
  await ageEntry('ODDLATER', 7 * 60 * 60 * 1000); // past the 6 h freshness window
  fetchImpl = async () => dlmJson({ success: true, data: { ...PAID3, status: 6 } });
  const res  = await worker.default.fetch(req('/check?license_key=ODDLATER&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('15 200, stale, tier and state of the good record', [res.status, body.status, body.tier, body.state], [200, 'stale', 'premium3', 'active']);
});

// 16. An entry cached before the status check existed, holding status 0, with DLM down: never served.
await scenario('16 a cached record with an unrecognised status is never served', async () => {
  resetAll();
  cacheStore.set(await cacheUrlFor('OLDENTRY'), {
    bodyText: JSON.stringify(oddRecord(0)),
    headers: { 'Content-Type': 'application/json', 'X-Fetched-At': String(Date.now() - 60000) },
  });
  fetchImpl = async () => dlmJson({}, 500);
  const res  = await worker.default.fetch(req('/check?license_key=OLDENTRY&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('16 503 unverified, never a 200 invalid', [res.status, body.status], [503, 'unverified']);
});

// 17. The cache says expired; the customer renews; DLM now says active.
await scenario('17 a renewal after expiry shows once RECHECK_MS has passed', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({ success: true, data: EXPIRED3() });
  const first = await (await worker.default.fetch(req('/check?license_key=RENEWKEY&operation=ttfb&daily_used=0'), env)).json();
  check('17 the cached answer says expired', [first.state, first.tier], ['expired', 'free']);
  await ageEntry('RENEWKEY', 6 * 60 * 1000); // past RECHECK_MS, far inside the 6 h window
  fetchCalls = 0;
  fetchImpl = async () => dlmJson({ success: true, data: RENEWED3() });
  const res  = await worker.default.fetch(req('/check?license_key=RENEWKEY&operation=ttfb&daily_used=0'), env);
  const body = await res.json();
  check('17 renewed: 200, ok, active, premium3, after one DLM fetch',
        [res.status, body.status, body.state, body.tier, fetchCalls], [200, 'ok', 'active', 'premium3', 1]);
});

// 18. At most one early refetch per key per RECHECK_MS, whether the refetch succeeds or fails.
await scenario('18 the early refetch is rate-limited', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({ success: true, data: EXPIRED3() });
  await worker.default.fetch(req('/check?license_key=RATEKEY&operation=ttfb&daily_used=0'), env);
  fetchCalls = 0;
  fetchImpl = async () => dlmJson({ success: true, data: RENEWED3() });
  const inside = await (await worker.default.fetch(req('/check?license_key=RATEKEY&operation=ttfb&daily_used=0'), env)).json();
  check('18 inside the window: the cached answer, and no DLM fetch', [inside.state, fetchCalls], ['expired', 0]);

  await ageEntry('RATEKEY', 6 * 60 * 1000);
  fetchCalls = 0;
  fetchImpl = async () => dlmJson({}, 500); // DLM down for the refetch
  const failed     = await worker.default.fetch(req('/check?license_key=RATEKEY&operation=ttfb&daily_used=0'), env);
  const failedBody = await failed.json();
  check('18 a failed refetch: one attempt, and the cached answer as the cache would have served it',
        [failed.status, failedBody.status, failedBody.state, fetchCalls], [200, 'ok', 'expired', 1]);
  await worker.default.fetch(req('/check?license_key=RATEKEY&operation=ttfb&daily_used=0'), env);
  check('18 after the failed refetch: no second attempt inside the window', fetchCalls, 1);
  const entry  = cacheStore.get(await cacheUrlFor('RATEKEY'));
  const maxAge = Number((/max-age=(\d+)/.exec(entry.headers['Cache-Control'] || '') || [])[1]);
  const lost   = 7 * 24 * 60 * 60 - maxAge; // noting the attempt must not extend retention past fetch + 7 d
  check('18 retention still counts from the DLM fetch (max-age lost the 6 minutes)', lost >= 360 && lost <= 366, true);
});

// 19. /activate for the renewed key: refetched, not refused with 409 expired from the cache.
await scenario('19 /activate for a renewed key succeeds', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({ success: true, data: EXPIRED3() });
  await worker.default.fetch(req('/check?license_key=REACTKEY&operation=ttfb&daily_used=0'), env);
  await ageEntry('REACTKEY', 6 * 60 * 1000);
  fetchCalls = 0;
  fetchImpl = async (url) => {
    if (new URL(url).pathname.includes('/activate/')) return dlmJson({ success: true, data: { token: 'TOK-R' } });
    return dlmJson({ success: true, data: RENEWED3() });
  };
  const res = await worker.default.fetch(
    req('/activate', { method: 'POST', body: { license_key: 'REACTKEY', site_url: 'https://renewed.example' } }), env);
  const body = await res.json();
  check('19 200, success, token, active, two DLM calls (record refetch + activation)',
        [res.status, body.success, body.token, body.state, fetchCalls], [200, true, 'TOK-R', 'active', 2]);
});

// 20. An active record keeps the 6 h freshness window: no early refetch.
await scenario('20 an active record is not refetched early', async () => {
  resetAll();
  fetchImpl = async () => dlmJson({ success: true, data: PAID3 });
  await worker.default.fetch(req('/check?license_key=ACTIVEKEY&operation=ttfb&daily_used=0'), env);
  await ageEntry('ACTIVEKEY', 60 * 60 * 1000);
  fetchCalls = 0;
  fetchImpl = async () => { throw new Error('an active record inside the 6 h window must not be refetched'); };
  const body = await (await worker.default.fetch(req('/check?license_key=ACTIVEKEY&operation=ttfb&daily_used=0'), env)).json();
  check('20 served from the cache, no DLM fetch', [body.status, body.state, fetchCalls], ['ok', 'active', 0]);
});

// ---- Activation failure codes reach the plugin as written ----
const FOREIGN3 = { product_id: 99999, status: 3, expires_at: null, activations: [] };
async function postActivate(licenseKey, siteUrl = 'https://new.example') {
  const res = await worker.default.fetch(
    req('/activate', { method: 'POST', body: { license_key: licenseKey, site_url: siteUrl } }), env);
  return { res, body: await res.json() };
}

// 21. Each /activate failure carries its own code and HTTP status, and puts success and
//     reason after every field of the service's answer, so no answer field can replace them.
await scenario('21 /activate failure codes', async () => {
  const OUTCOME_FIELDS = ['success', 'reason', 'http']; // what the worker adds to the answer
  const cases = [
    ['unverified (DLM down, nothing cached)', 'A21UNV', async () => { throw new Error('down'); }, 503, 'unverified'],
    ['slots (a one-site plan already on another site)', 'A21SLOT',
      async () => dlmJson({ success: true, data: { ...PAID1, activations: [{ label: 'other.example', token: 't1' }] } }), 409, 'slots'],
    ['activate_failed (DLM refuses the activation)', 'A21FAIL',
      async (url) => (new URL(url).pathname.includes('/activate/') ? dlmJson({ success: false }, 500) : dlmJson({ success: true, data: PAID3 })),
      502, 'activate_failed'],
    ['not_delivered (sold)', 'A21SOLD', async () => dlmJson({ success: true, data: { ...PAID3, status: 1 } }), 409, 'not_delivered'],
    ['expired', 'A21EXP', async () => dlmJson({ success: true, data: EXPIRED3() }), 409, 'expired'],
    ['not_found (another product)', 'A21FOR', async () => dlmJson({ success: true, data: FOREIGN3 }), 409, 'not_found'],
    ['inactive', 'A21INA', async () => dlmJson({ success: true, data: { ...PAID3, status: 4 } }), 409, 'inactive'],
    ['disabled', 'A21DIS', async () => dlmJson({ success: true, data: { ...PAID3, status: 5 } }), 409, 'disabled'],
  ];
  for (const [label, key, impl, http, reason] of cases) {
    resetAll();
    fetchImpl = impl;
    const { res, body } = await postActivate(key);
    check(`21 ${label}`, [res.status, body.success, body.reason], [http, false, reason]);
    const keys = Object.keys(body);
    const lastAnswerField = Math.max(...keys.filter((k) => !OUTCOME_FIELDS.includes(k)).map((k) => keys.indexOf(k)));
    check(`21 ${label}: success and reason come after the answer's own fields`,
          [keys.includes('state'), keys.indexOf('success') > lastAnswerField, keys.indexOf('reason') > lastAnswerField],
          [true, true, true]);
  }
});

// 22. DLM's own "not found" answer for a key with no cached record: named at activation, never proof on /check.
const DLM_NOT_FOUND = { code: 'data_error', message: "The license key 'SAMPLE-MISSING-KEY' could not be found", data: { code: 404 } };
await scenario('22 DLM not-found: named at activation, never proof on /check', async () => {
  resetAll();
  fetchImpl = async () => dlmJson(DLM_NOT_FOUND, 500);
  const { res, body } = await postActivate('SAMPLE-MISSING-KEY');
  const text = JSON.stringify(body);
  check('22 /activate: 409 not_found', [res.status, body.success, body.reason], [409, false, 'not_found']);
  check("22 /activate: neither the key nor DLM's message comes back",
        [text.includes('SAMPLE-MISSING-KEY'), text.includes('could not be found')], [false, false]);

  resetAll();
  fetchImpl = async () => dlmJson(DLM_NOT_FOUND, 500);
  const chk   = await worker.default.fetch(req('/check?license_key=SAMPLE-MISSING-KEY&operation=ttfb&daily_used=0'), env);
  const cbody = await chk.json();
  check('22 /check: still 503 unverified', [chk.status, cbody.status], [503, 'unverified']);
});

// 23. Anything that is not exactly DLM's not-found answer stays a DLM failure at activation.
await scenario('23 near-misses are DLM failures, never not_found', async () => {
  for (const [label, status, payload] of [
    ['data_error with data.code 500', 500, { code: 'data_error', data: { code: 500 } }],
    ['rest_no_route 404', 404, { code: 'rest_no_route', message: 'No route', data: { status: 404 } }],
    ['permission_denied 403', 403, { code: 'permission_denied', data: { status: 403 } }],
    ['route_disabled', 403, { code: 'route_disabled', data: { status: 403 } }],
    ['a prefixed code', 500, { code: 'x_data_error', data: { code: 404 } }],
  ]) {
    resetAll();
    fetchImpl = async () => dlmJson(payload, status);
    const { res, body } = await postActivate(`A23_${label.replace(/\W/g, '_')}`);
    check(`23 ${label}: 503 unverified`, [res.status, body.reason], [503, 'unverified']);
  }
  resetAll();
  fetchImpl = async () => new Response('<html>Error</html>', { status: 500, headers: { 'Content-Type': 'text/html' } });
  const { res, body } = await postActivate('A23HTML');
  check('23 an HTML 500: 503 unverified', [res.status, body.reason], [503, 'unverified']);
});

// 24. Through fetch(): /check answers carry v:4 and no reason.
await scenario('24 /check answers: v:4, no reason', async () => {
  resetAll();
  const keyless = await (await worker.default.fetch(req('/check?operation=ttfb&daily_used=0'), env)).json();
  fetchImpl = async () => dlmJson({ success: true, data: { ...PAID3, status: 5 } });
  const disabled = await (await worker.default.fetch(req('/check?license_key=A24DIS&operation=ttfb&daily_used=0'), env)).json();
  check('24 keyless: v4, free, no reason', [keyless.v, keyless.state, 'reason' in keyless], [4, 'free', false]);
  check('24 disabled: v4, disabled, no reason', [disabled.v, disabled.state, 'reason' in disabled], [4, 'disabled', false]);
});

if (failures) { console.error(`\n${failures} check(s) failed`); process.exit(1); }
console.log('gatekeeper worker harness passed');
