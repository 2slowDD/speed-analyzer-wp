// Harness for the Gatekeeper v3 pure decision function.
// Run: node tests/gatekeeper-decide-harness.js
import { readFileSync } from 'node:fs';
import { decide, normaliseHost, dlmStatus, shouldRefetch, RECHECK_MS } from '../cloudflare/gatekeeper/decide.js';

let failures = 0;
function check(name, actual, expected) {
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  if (a !== e) { console.error(`FAIL ${name}\n  expected ${e}\n  actual   ${a}`); failures++; }
}
const DAY = 86400000;
const NOW = Date.parse('2027-06-15T12:00:00Z');
const AGENCY = { product_id: 11414, status: 3, is_expired: false, expires_at: null, activations: [] };
const base = { nowMs: NOW, operation: 'ttfb', dailyUsed: 0, siteHost: 'example.com',
               status: 'ok', fetchedAtMs: NOW };
const d = (over = {}) => decide({ ...base, dlm: AGENCY, ...over });

// AC-W1 perpetual active
check('AC-W1 state', d().state, 'active');
check('AC-W1 tier',  d().tier,  'premium3');
check('AC-W1 exp',   d().expires_at, null);

// AC-W2 expires in 5 days
const in5 = { ...AGENCY, expires_at: '2027-06-20 12:00:00' };
check('AC-W2 state', d({ dlm: in5 }).state, 'active');
check('AC-W2 days',  d({ dlm: in5 }).days_left, 5);

// AC-W3 grace, absolute date (r3: assert the date, not an offset)
const ago3 = { ...AGENCY, expires_at: '2027-06-12 12:00:00' };
check('AC-W3 state', d({ dlm: ago3 }).state, 'grace');
check('AC-W3 tier',  d({ dlm: ago3 }).tier, 'premium3');
check('AC-W3 until', d({ dlm: ago3 }).grace_until, '2027-06-19');

// AC-W4 beyond grace
const ago8 = { ...AGENCY, expires_at: '2027-06-07 12:00:00' };
check('AC-W4 state', d({ dlm: ago8 }).state, 'expired');
check('AC-W4 tier',  d({ dlm: ago8 }).tier, 'free');

// AC-W5 status 4 and 5 -> invalid. STATUS 1 IS DELIBERATELY ABSENT (see AC-W10).
check('AC-W5 s4 state',  d({ dlm: { ...AGENCY, status: 4 } }).state,  'invalid');
check('AC-W5 s4 reason', d({ dlm: { ...AGENCY, status: 4 } }).reason, 'inactive');
check('AC-W5 s5 state',  d({ dlm: { ...AGENCY, status: 5 } }).state,  'invalid');
check('AC-W5 s5 reason', d({ dlm: { ...AGENCY, status: 5 } }).reason, 'disabled');

// AC-W10 status 1 -> sold, NEVER invalid
check('AC-W10 state',  d({ dlm: { ...AGENCY, status: 1 } }).state,  'sold');
check('AC-W10 reason', d({ dlm: { ...AGENCY, status: 1 } }).reason, 'not_delivered');
check('AC-W10 tier',   d({ dlm: { ...AGENCY, status: 1 } }).tier,   'free');

// AC-W11 no record -> invalid/not_found
check('AC-W11 state',  d({ dlm: null }).state,  'invalid');
check('AC-W11 reason', d({ dlm: null }).reason, 'not_found');

// AC-W6 unverified: no record available at all
const unv = d({ dlm: null, status: 'unverified' });
check('AC-W6 status', unv.status, 'unverified');
check('AC-W6 tier',   unv.tier,   'free');
check('AC-W6 legacy keys present',
      ['allowed','tier','limit','remaining'].every(k => k in unv), true);

// AC-W7 stale: served from a 2-day-old cached record
const st = d({ status: 'stale', fetchedAtMs: NOW - 2 * DAY });
check('AC-W7 status', st.status, 'stale');
check('AC-W7 tier',   st.tier,   'premium3');

// AC-W12 fresh cache hit is 'ok' with a real age (r2-M1: Rev 1 had no defined value here)
const hit = d({ fetchedAtMs: NOW - 7200000 });
check('AC-W12 status', hit.status, 'ok');
check('AC-W12 age',    hit.age_s,  7200);

// AC-W13 fetched_at and age_s present on every status value
for (const s of ['ok', 'stale', 'unverified']) {
  const r = d({ status: s });
  check(`AC-W13 ${s} fetched_at`, typeof r.fetched_at, 'string');
  check(`AC-W13 ${s} age_s`,      typeof r.age_s,      'number');
}

// Site cap (r1-C1). PRO = max 1.
const PRO = { product_id: 11208, status: 3, is_expired: false, expires_at: null,
              activations: [{ label: 'a.com' }] };
// AC-W8 at the cap, host already active -> allowed
check('AC-W8', d({ dlm: PRO, siteHost: 'a.com' }).allowed, true);
// AC-W8b at the cap, host NOT active -> DENIED (Rev 1 returned true here)
check('AC-W8b', d({ dlm: PRO, siteHost: 'b.com' }).allowed, false);
// AC-W8c under the cap, new host -> allowed
const PRO0 = { ...PRO, activations: [] };
check('AC-W8c', d({ dlm: PRO0, siteHost: 'b.com' }).allowed, true);
// sites block is reported accurately
check('AC-W8 sites', d({ dlm: PRO, siteHost: 'a.com' }).sites,
      { max: 1, used: 1, remaining: 0, active: true });

// AC-W9 host normalisation: any *.wpservice.pro collapses to wpservice.pro
check('AC-W9 sub',  normaliseHost('https://staging.wpservice.pro/x'), 'wpservice.pro');
check('AC-W9 apex', normaliseHost('https://wpservice.pro'),           'wpservice.pro');
check('AC-W9 other',normaliseHost('https://Example.COM/path'),        'example.com');

// Quota arithmetic
check('limit ttfb', d().limit, 700);
check('limit pdf',  d({ operation: 'pdf' }).limit, 100);
check('remaining',  d({ dailyUsed: 7 }).remaining, 693);
check('exhausted',  d({ dailyUsed: 700 }).allowed, false);

// Empty key
const nokey = decide({ ...base, dlm: null, licenseKeyPresent: false });
check('no key state',  nokey.state,  'free');
check('no key reason', nokey.reason, 'no_key');
check('no key tier',   nokey.tier,   'free');

// AC-W16 the status guard survives a realistic wire shape.
// DLM's REST layer may serialize integer columns as strings; a hand-built
// fixture cannot catch that, so parse actual JSON here.
const wire = JSON.parse('{"product_id":11414,"status":"3","is_expired":false,"expires_at":null,"activations":[]}');
check('AC-W16 numeric-string status', d({ dlm: wire }).state, 'active');
check('AC-W16 tier',                  d({ dlm: wire }).tier,  'premium3');
const wireNum = JSON.parse('{"product_id":11414,"status":3,"is_expired":false,"expires_at":null,"activations":[]}');
check('AC-W16 numeric status still ok', d({ dlm: wireNum }).state, 'active');
// Junk must still deny, never grant.
for (const bad of ['"abc"', 'null', 'true', '3.5']) {
  const j = JSON.parse('{"product_id":11414,"status":' + bad + ',"is_expired":false,"expires_at":null,"activations":[]}');
  check('AC-W16 junk denies ' + bad, d({ dlm: j }).tier, 'free');
}

// AC-W17 BUSINESS (cap 2) — second site allowed, third denied.
const BIZ = { product_id: 11413, status: 3, is_expired: false, expires_at: null,
              activations: [{ label: 'a.com' }] };
check('AC-W17 2nd site allowed', d({ dlm: BIZ, siteHost: 'b.com' }).allowed, true);
const BIZ2 = { ...BIZ, activations: [{ label: 'a.com' }, { label: 'b.com' }] };
check('AC-W17 3rd site denied',  d({ dlm: BIZ2, siteHost: 'c.com' }).allowed, false);

// ---- The shared display fixture: decide()'s answers, also fed to the plugin ----
// tests/_license-display-fixtures.json holds inputs and the answers decide() gave for
// them. tests/license-state-harness.php feeds the same answers to the licence panel
// and the expiry notice, so this is what keeps the PHP side's input equal to what the
// real producer returns: if decide() changes, the case that moved goes red here.
const FIXTURE = JSON.parse(readFileSync(new URL('./_license-display-fixtures.json', import.meta.url), 'utf8'));
check('display fixture: the five cases', FIXTURE.cases.map((c) => c.id),
      ['grace_day_2', 'expired_40_days', 'boundary_10_days_1800', 'boundary_10_days_0000', 'active_200_days']);
for (const c of FIXTURE.cases) {
  check(`display fixture ${c.id}: decide() still returns the stored answer`, decide(c.input), c.answer);
}

// ---- dlmStatus(): the DLM statuses the worker accepts ----
// decide() reads the status through the same function, so what the worker rejects as a
// DLM failure and what decide() cannot place are the same set.
for (const s of [1, 2, 3, 4, 5]) check(`dlmStatus ${s}`, dlmStatus(s), s);
check('dlmStatus numeric string', dlmStatus('3'), 3);
for (const [label, raw] of [['0', 0], ['6', 6], ['missing', undefined], ['null', null], ['empty', ''],
                             ['text label', 'active'], ['fraction', 3.5], ['boolean', true]]) {
  check(`dlmStatus rejects ${label}`, dlmStatus(raw), null);
}

// ---- shouldRefetch(): refetch a cached record early only when it is not active ----
check('RECHECK_MS is five minutes', RECHECK_MS, 5 * 60 * 1000);
const EXPIRED_REC = { ...AGENCY, expires_at: '2027-05-01 12:00:00' }; // past grace at NOW
const GRACE_REC   = { ...AGENCY, expires_at: '2027-06-13 12:00:00' }; // two days into grace
const SOLD_REC    = { ...AGENCY, status: 1 };
check('shouldRefetch active, last checked 5 h ago: no', shouldRefetch(AGENCY, NOW, NOW - 5 * 3600000), false);
check('shouldRefetch expired, checked exactly RECHECK_MS ago: yes', shouldRefetch(EXPIRED_REC, NOW, NOW - RECHECK_MS), true);
check('shouldRefetch expired, checked 1 ms short of RECHECK_MS: no', shouldRefetch(EXPIRED_REC, NOW, NOW - RECHECK_MS + 1), false);
check('shouldRefetch expired, checked an hour ago: yes', shouldRefetch(EXPIRED_REC, NOW, NOW - 3600000), true);
check('shouldRefetch grace counts as not active', shouldRefetch(GRACE_REC, NOW, NOW - RECHECK_MS), true);
check('shouldRefetch sold counts as not active', shouldRefetch(SOLD_REC, NOW, NOW - RECHECK_MS), true);

if (failures) { console.error(`\n${failures} check(s) failed`); process.exit(1); }
console.log('gatekeeper decide harness passed');
