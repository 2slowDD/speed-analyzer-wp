// Speed Analyzer — Total Blocking Time acceptance harness.
//
// Unlike the older harnesses in this folder, this one EXECUTES the functions it
// checks. Threshold bands and millisecond parsing are arithmetic; a source-text
// pin would happily pass while the arithmetic was wrong.
//
// Run: node tests/tbt-harness.js

const fs = require('fs');
const path = require('path');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function loadSrc(file) {
  return fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
}

// Lift a named function out of a source file by brace-matching, so it can be
// instantiated and called. `from` lets callers skip earlier definitions of the
// same name (report-scripts.js defines several helpers twice, once per device).
function extractFn(source, name, from) {
  const start = source.indexOf('function ' + name, from || 0);
  assert(start !== -1, 'function ' + name + ' not found in source.');
  let depth = 0;
  let end = -1;
  for (let j = source.indexOf('{', start); j < source.length; j++) {
    if (source[j] === '{') {
      depth++;
    } else if (source[j] === '}') {
      depth--;
      if (depth === 0) { end = j + 1; break; }
    }
  }
  assert(end !== -1, 'Unbalanced braces extracting ' + name + '.');
  return { src: source.slice(start, end), start: start, end: end };
}

// Instantiate an extracted function so we can call it.
//
// On new Function(): this is a DEV-ONLY harness. `tests/` is never shipped — the
// release package contains plugin runtime files only — and it is run by hand as
// `node tests/tbt-harness.js`. Its only input is this repository's own source,
// which is already executed verbatim by the plugin itself, so instantiating it
// here crosses no privilege boundary. Do not copy this pattern into plugin code,
// and do not feed this function anything that did not come from loadSrc().
function instantiate(fnSrc, name) {
  return new Function(fnSrc + '; return ' + name + ';')();
}

// ---------------------------------------------------------------------------
// AC-5 — thousands separators must not be read as decimal points.
//
// PSI formats displayValue with a comma once TBT crosses 1000 ms (observed on
// forbes.com/mobile: numericValue 1908.99 -> "1,910 ms"). The original code did
// .replace(',', '.'), turning "1,910" into 1.91 — so the worst-performing page
// would have rendered GREEN. INP never triggered this because INP values stay
// under 1000 ms; TBT crosses it routinely.
//
// inpMs() is the dangerous one: it feeds the Module 5 log WRITER, so a misread
// is persisted into ttfb-results-log.txt permanently.
// ---------------------------------------------------------------------------
const adminSrc = loadSrc('admin-scripts.js');
const reportSrc = loadSrc('report-scripts.js');

const inpMsFn = instantiate(extractFn(adminSrc, 'inpMs').src, 'inpMs');
const parseMsFn = instantiate(extractFn(adminSrc, 'parseMs').src, 'parseMs');

const rFirst = extractFn(reportSrc, 'parseInpMs');
const rSecond = extractFn(reportSrc, 'parseInpMs', rFirst.end);
const parseInpMsMobile = instantiate(rFirst.src, 'parseInpMs');
const parseInpMsDesktop = instantiate(rSecond.src, 'parseInpMs');

assert(
  rSecond.start > rFirst.start,
  'report-scripts.js must still define parseInpMs twice, once per device block.'
);

// Thousands separator -> full magnitude.
assert(
  inpMsFn('1,910 ms') === '1910',
  'inpMs() must read "1,910 ms" as 1910. It feeds the Module 5 LOG WRITER, so a ' +
  'misread is written into ttfb-results-log.txt permanently. Got: ' + inpMsFn('1,910 ms')
);
assert(
  parseMsFn('1,910 ms') === 1910,
  'parseMs() must read "1,910 ms" as 1910, not 1.91. Got: ' + parseMsFn('1,910 ms')
);
assert(
  parseInpMsMobile('1,910 ms') === 1910,
  'parseInpMs (mobile block) must read "1,910 ms" as 1910. Got: ' + parseInpMsMobile('1,910 ms')
);
assert(
  parseInpMsDesktop('1,910 ms') === 1910,
  'parseInpMs (desktop block) must read "1,910 ms" as 1910. Got: ' + parseInpMsDesktop('1,910 ms')
);

// Multi-group thousands must work too.
assert(
  inpMsFn('1,910,000 ms') === '1910000',
  'inpMs() must handle multi-group thousands. Got: ' + inpMsFn('1,910,000 ms')
);

// A decimal comma must still behave as before (European-locale input).
assert(
  parseMsFn('0,22 s') === 220,
  'parseMs() must still treat "0,22 s" as a decimal comma -> 220 ms. Got: ' + parseMsFn('0,22 s')
);
assert(
  parseInpMsMobile('0,22 s') === 220,
  'parseInpMs (mobile) must still treat "0,22 s" as 220 ms. Got: ' + parseInpMsMobile('0,22 s')
);

// Existing behaviour must be untouched.
assert(inpMsFn('220 ms') === '220', 'inpMs("220 ms") regression.');
assert(inpMsFn('0.22 s') === '220', 'inpMs("0.22 s") regression.');
assert(inpMsFn('') === 'N/A', 'inpMs("") must still be N/A.');
assert(parseMsFn('220 ms') === 220, 'parseMs("220 ms") regression.');
assert(parseInpMsMobile('N/A') === null, 'parseInpMs("N/A") must still be null.');
assert(parseInpMsDesktop('180') === 180, 'parseInpMs bare-number regression.');

// numOrNA is deliberately NOT hardened — it only ever sees seconds (LCP/FCP) and
// unitless values (CLS), neither of which can carry a thousands separator. Assert
// it was left alone so nobody "fixes" it and changes LCP/FCP/CLS parsing as a
// side effect. Its parseFloat line is byte-identical to inpMs()'s, which makes it
// an easy accidental target for a replace-all.
const numOrNASrc = extractFn(adminSrc, 'numOrNA').src;
assert(
  numOrNASrc.indexOf("replace(',', '.')") !== -1 &&
  numOrNASrc.indexOf('replace(/,/g') === -1,
  'numOrNA() must keep its original decimal-comma handling — it is out of scope, ' +
  'and changing it would alter LCP/FCP/CLS parsing.'
);

// ---------------------------------------------------------------------------
// AC-7 / AC-8 — acquisition is lab-only.
//
// CrUX exposes no total_blocking_time. Its complete field-metric set is
// largest_contentful_paint, interaction_to_next_paint, cumulative_layout_shift,
// first_contentful_paint, experimental_time_to_first_byte and round_trip_time.
// So the field fallback that existed for INP has no counterpart here and must be
// DELETED, not repointed to some imagined field TBT.
// ---------------------------------------------------------------------------
const diagSrc = loadSrc('diagnostics.php');

const tbtBlockStart = diagSrc.indexOf('$tbt_lab');
assert(tbtBlockStart !== -1, 'diagnostics.php must acquire TBT into $tbt_lab.');
const tbtBlockEnd = diagSrc.indexOf('Build Opportunities', tbtBlockStart);
assert(tbtBlockEnd > tbtBlockStart, 'Could not bound the TBT acquisition block.');
const tbtBlock = diagSrc.slice(tbtBlockStart, tbtBlockEnd);

assert(
  tbtBlock.indexOf("'total-blocking-time'") !== -1,
  'TBT must be read from the total-blocking-time Lighthouse audit.'
);
assert(
  tbtBlock.indexOf("'numericValue'") !== -1,
  'TBT must be read from numericValue.'
);
assert(
  tbtBlock.indexOf('displayValue') === -1,
  'TBT must NOT use displayValue: PSI rounds it to the nearest ten (19 -> "20 ms") ' +
  'and inserts a thousands separator above 1000 ms (1908.99 -> "1,910 ms"). The ' +
  'adjacent LCP/FCP lines DO use displayValue, which is exactly why this guard exists.'
);
assert(
  tbtBlock.indexOf('totalBlockingTime') !== -1,
  'Keep metrics.details.items[0].totalBlockingTime as the secondary source.'
);
assert(
  tbtBlock.indexOf('loadingExperience') === -1 &&
  tbtBlock.indexOf('INTERACTION_TO_NEXT_PAINT') === -1,
  'The TBT acquisition path must contain NO field-data fallback. TBT has no CrUX ' +
  'counterpart, so a field branch here would silently report a different metric.'
);

assert(/'tbt'\s*=>/.test(diagSrc), "The AJAX payload must send 'tbt'.");
assert(
  diagSrc.indexOf("'inp_source'") === -1,
  "'inp_source' must be removed: it distinguished lab-vs-field provenance, which is " +
  'meaningless for a lab-only metric.'
);
assert(
  adminSrc.indexOf('data.inp_source') === -1,
  'The lab/field source badge must be removed from the tile renderer along with the ' +
  'payload field that fed it.'
);
assert(
  adminSrc.indexOf('data.tbt') !== -1,
  'The tile renderer must read the tbt payload key.'
);

// ---------------------------------------------------------------------------
// AC-11 / AC-2 / AC-3 / AC-6 — the results-log grammar (decision D7).
//
// Per device, 1.19.0 writes TWO lines:
//   Module 5 Mobile: Performance: 87, LCP: 2.1, FCP: 1.4, CLS: 0.05, INP: N/A
//   Module 5 Mobile TBT: 340
//
// The first keeps 1.18.6's EXACT grammar so a downgrade can still read
// Performance/LCP/FCP/CLS. TBT could not be folded into that line: 1.18.6's
// parser requires CLS to be followed directly by ", INP:" and then end-of-line,
// so any TBT token on it breaks the $ anchor. The second line is invisible to
// every 1.18.6 parser (they are if/elseif chains; unmatched lines are skipped).
// ---------------------------------------------------------------------------
const schedSrc = loadSrc('schedule.php');
const cmpSrc = loadSrc('compare.php');
const conclSrc = loadSrc('conclusion.php');

const MAIN_LINE = 'Module 5 Mobile: Performance: 87, LCP: 2.1, FCP: 1.4, CLS: 0.05, INP: N/A';
const TBT_LINE = 'Module 5 Mobile TBT: 340';
const OLD_LINE = 'Module 5 Mobile: Performance: 87, LCP: 2.1, FCP: 1.4, CLS: 0.05, INP: 180';

// 1.18.6's strict parser, transcribed VERBATIM. Do not "tidy" this — it is a
// frozen copy of shipped behaviour and the entire point of AC-11.
const V1186_STRICT_MOBILE = /^Module\s+5\s+Mobile:\s*Performance:\s*(\d+|N\/A)\s*,\s*LCP:\s*(N\/A|[0-9.]+)\s*,\s*FCP:\s*(N\/A|[0-9.]+)\s*,\s*CLS:\s*(N\/A|[0-9.]+)\s*,\s*INP:\s*(N\/A|[0-9]+)\s*$/i;

// --- AC-11: the rollback pin. ---
// Derive the line the writer ACTUALLY emits from its template literal, rather
// than trusting the constant above. A literal-only check would prove the design
// sound while the code drifted away from it.
const tmplMatch = adminSrc.match(/`(Module 5 Mobile: Performance:[^`]*)`/);
assert(tmplMatch, 'Could not find admin-scripts.js\'s Module 5 Mobile template literal.');
const EMITTED_MAIN = tmplMatch[1]
  .replace(/\$\{mob\.score\}/g, '87')
  .replace(/\$\{mobLcp\}/g, '2.1')
  .replace(/\$\{mobFcp\}/g, '1.4')
  .replace(/\$\{mobCls\}/g, '0.050')
  .replace(/\\n$/, '');
assert(
  V1186_STRICT_MOBILE.test(EMITTED_MAIN),
  'The line admin-scripts.js actually emits must match 1.18.6\'s parser. Emitted: ' +
  JSON.stringify(EMITTED_MAIN)
);
const tmplTbt = adminSrc.match(/`(Module 5 Mobile TBT:[^`]*)`/);
assert(tmplTbt, 'Could not find admin-scripts.js\'s Mobile TBT template literal.');
const EMITTED_TBT = tmplTbt[1].replace(/\$\{mobTbt\}/g, '340').replace(/\\n$/, '');
assert(
  !V1186_STRICT_MOBILE.test(EMITTED_TBT),
  'The TBT line the writer actually emits must be invisible to 1.18.6\'s parser. ' +
  'Emitted: ' + JSON.stringify(EMITTED_TBT)
);
assert(
  /^Module\s+5\s+Mobile\s+TBT:\s*340$/.test(EMITTED_TBT),
  'The emitted TBT line must use the space-separated "Module 5 Mobile TBT:" form. ' +
  'A colon after the device name would collide with the main-line prefix. Emitted: ' +
  JSON.stringify(EMITTED_TBT)
);

assert(
  V1186_STRICT_MOBILE.test(MAIN_LINE),
  'The Module 5 line must keep its exact 1.18.6 shape so a downgrade can still read it. ' +
  'Any TBT token on this line breaks the trailing $ anchor.'
);
assert(
  !V1186_STRICT_MOBILE.test(TBT_LINE),
  'The TBT line must NOT match 1.18.6\'s Module 5 parser. If it did, an old build would ' +
  'read it as a metrics line and populate garbage. Keep the space-separated form ' +
  '"Module 5 Mobile TBT:" — a colon after the device name would collide.'
);
const recovered = MAIN_LINE.match(V1186_STRICT_MOBILE);
assert(
  recovered[1] === '87' && recovered[4] === '0.05' && recovered[5].toUpperCase() === 'N/A',
  'After a downgrade 1.18.6 must still recover Performance and CLS from the main line.'
);

// --- AC-2: the writers. ---
assert(
  /Module 5 %s: Performance: %s, LCP: %s, FCP: %s, CLS: %s, INP: %s/.test(schedSrc),
  'schedule.php must keep the Module 5 sprintf format EXACTLY as 1.18.6 had it. The INP ' +
  'argument is now always the string N/A, supplied by the $out default.'
);
assert(
  /Module 5 %s TBT: %s/.test(schedSrc),
  'schedule.php must write the separate TBT line.'
);
assert(
  schedSrc.indexOf("'tbt'  => 'N/A'") !== -1 || schedSrc.indexOf("'tbt' => 'N/A'") !== -1,
  "schedule.php's $out default must include 'tbt'."
);
assert(
  adminSrc.indexOf('Mobile TBT: ${mobTbt}') !== -1 &&
  adminSrc.indexOf('Desktop TBT: ${desTbt}') !== -1,
  'admin-scripts.js must write a separate TBT line for both devices.'
);
assert(
  adminSrc.indexOf('CLS: ${mobCls}, INP: N/A') !== -1 &&
  adminSrc.indexOf('CLS: ${desCls}, INP: N/A') !== -1,
  'admin-scripts.js must keep the Module 5 line in its 1.18.6 shape with the INP: N/A shim.'
);

// AC-6: the value reaching the writer is a bare integer — no comma, no unit —
// so a 1908.99 ms reading cannot break the grammar.
assert(
  inpMsFn('1,910 ms') === '1910' && !/[^0-9]/.test(inpMsFn('1,910 ms')),
  'The value handed to the log writer must be a bare integer.'
);

// --- AC-8 (extended): schedule.php runs its OWN acquisition. It must be
// lab-only too. The original occurrence map missed this second path. ---
const schedAcqStart = schedSrc.indexOf('$tbt_raw');
assert(schedAcqStart !== -1, 'schedule.php must acquire TBT into $tbt_raw.');
const schedAcqEnd = schedSrc.indexOf('Build two log lines', schedAcqStart);
assert(schedAcqEnd > schedAcqStart, 'Could not bound schedule.php\'s TBT acquisition block.');
const schedAcq = schedSrc.slice(schedAcqStart, schedAcqEnd);
assert(
  schedAcq.indexOf('INTERACTION_TO_NEXT_PAINT') === -1 &&
  schedAcq.indexOf('loadingExperience') === -1,
  'schedule.php\'s TBT acquisition must have NO field fallback. Its INP version had a ' +
  'CrUX stage-3 fallback; TBT has no field counterpart, so it must be deleted.'
);
assert(
  schedAcq.indexOf("'total-blocking-time'") !== -1,
  'schedule.php must read the total-blocking-time audit.'
);
// Check the guard EXPRESSION, not the surrounding prose — the comment above it
// legitimately contains the string "> 0" while explaining why it is wrong.
const schedGuard = schedAcq.split(/\r?\n/)
  .filter(function (l) { return l.indexOf('//') === -1 && l.indexOf('$tbt_raw') !== -1 && l.indexOf('is_numeric') !== -1; })
  .join(' ');
assert(schedGuard !== '', 'Could not find schedule.php\'s TBT numeric guard.');
assert(
  schedGuard.indexOf('>= 0') !== -1 && !/\$tbt_raw\s*>\s*0(?!=)/.test(schedGuard),
  'A TBT of 0 ms is a legitimate, common reading (measured on 5 of 8 real pages sampled). ' +
  'The INP code this replaced guarded with "> 0"; carrying that over would silently ' +
  'discard a real 0 and report N/A instead. Guard found: ' + schedGuard.trim()
);

// --- AC-3: every parser gained a TBT branch and still reads legacy lines. ---
const tbtRe = /^Module\s+5\s+(Mobile|Desktop)\s+TBT:\s*(N\/A|[0-9]+)\s*$/i;
assert(
  tbtRe.test(TBT_LINE) && TBT_LINE.match(tbtRe)[2] === '340',
  'The agreed TBT line shape must parse to its integer value.'
);
for (const [file, src] of [['schedule.php', schedSrc], ['compare.php', cmpSrc],
                           ['conclusion.php', conclSrc], ['admin-scripts.js', adminSrc]]) {
  assert(
    /Module\\s\+5\\s\+(?:Mobile|Desktop)\\s\+TBT|Module 5 (?:Mobile|Desktop)\\s\+TBT/.test(src),
    file + ' must carry a parser branch for the separate TBT line.'
  );
  assert(
    src.indexOf('INP:') !== -1,
    file + ' must still recognise legacy INP: lines, or historical rows lose LCP/FCP/CLS.'
  );
}

// --- The in-memory keys were renamed. ---
for (const [file, src] of [['schedule.php', schedSrc], ['compare.php', cmpSrc]]) {
  assert(
    src.indexOf('m5m_inp') === -1 && src.indexOf('m5d_inp') === -1,
    file + ' must no longer reference m5m_inp/m5d_inp.'
  );
  assert(
    src.indexOf('m5m_tbt') !== -1 && src.indexOf('m5d_tbt') !== -1,
    file + ' must use m5m_tbt/m5d_tbt.'
  );
}

console.log('OK tbt-harness');
