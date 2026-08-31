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
  // The plugin is no longer flat: PHP lives in includes/, JS in assets/js/, CSS in
  // assets/css/. Callers still pass a bare filename, so resolve it across the layout
  // and fail loudly if it is nowhere - a silently-skipped file would fake a pass.
  const dirs = ['', 'includes', 'assets/js', 'assets/css', 'assets/img'];
  for (const d of dirs) {
    const p = path.join(__dirname, '..', d, file);
    if (fs.existsSync(p)) return fs.readFileSync(p, 'utf8');
  }
  throw new Error('loadSrc: cannot find ' + file + ' anywhere in the plugin layout');
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

const rFirst = extractFn(reportSrc, 'parseTbtMs');
const rSecond = extractFn(reportSrc, 'parseTbtMs', rFirst.end);
const parseInpMsMobile = instantiate(rFirst.src, 'parseTbtMs');
const parseInpMsDesktop = instantiate(rSecond.src, 'parseTbtMs');

assert(
  rSecond.start > rFirst.start,
  'report-scripts.js must still define parseTbtMs twice, once per device block.'
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

// ---------------------------------------------------------------------------
// AC-1 — device-split threshold bands, EXECUTED, not pinned.
//
//   mobile   green <= 200, amber <= 600, red > 600
//   desktop  green <= 150, amber <= 350, red > 350
//
// These come from Lighthouse's own defaultOptions, which declares both curves
// and picks one at runtime via context.options[context.settings.formFactor]:
//   mobile  { p10: 200, median: 600 }
//   desktop { p10: 150, median: 350 }
// p10 is the score-0.9 point (green edge) and median the score-0.5 point (amber
// edge), so "<= good / <= warn / else" reproduces Lighthouse exactly.
//
// Confirmed empirically: forbes.com desktop at 395 ms scores 0.42 in PSI (RED).
// A single 200/600 rule would call that AMBER and contradict PSI on a customer
// report.
// ---------------------------------------------------------------------------
const summarySrc = loadSrc('summary.php');

const tFirst = extractFn(reportSrc, 'tbtLevel');
const tSecond = extractFn(reportSrc, 'tbtLevel', tFirst.end);
const tbtLevelMobile = instantiate(tFirst.src, 'tbtLevel');
const tbtLevelDesktop = instantiate(tSecond.src, 'tbtLevel');
assert(tSecond.start > tFirst.start, 'report-scripts.js must define tbtLevel twice, once per device.');

// 0 = good, 1 = needs-improvement, 2 = poor, -1 = unknown.
// Boundary values are included deliberately: <= good and <= warn.
const MOBILE_BANDS = [[null, -1], [0, 0], [199, 0], [200, 0], [201, 1],
                      [599, 1], [600, 1], [601, 2], [1909, 2]];
const DESKTOP_BANDS = [[null, -1], [0, 0], [149, 0], [150, 0], [151, 1],
                       [349, 1], [350, 1], [351, 2], [395, 2]];

for (const pair of MOBILE_BANDS) {
  const got = tbtLevelMobile(pair[0]);
  assert(got === pair[1],
    'tbtLevel (mobile block) for ' + pair[0] + ' ms must be ' + pair[1] + ', got ' + got +
    '. Mobile thresholds are 200/600.');
}
for (const pair of DESKTOP_BANDS) {
  const got = tbtLevelDesktop(pair[0]);
  assert(got === pair[1],
    'tbtLevel (desktop block) for ' + pair[0] + ' ms must be ' + pair[1] + ', got ' + got +
    '. Desktop thresholds are 150/350 — NOT the mobile 200/600.');
}

// The two copies must genuinely differ, or someone pasted the mobile numbers twice.
assert(
  tbtLevelMobile(175) === 0 && tbtLevelDesktop(175) === 1,
  'A 175 ms page must grade GREEN on mobile and AMBER on desktop. Identical results in ' +
  'both blocks mean the device split was lost.'
);
assert(
  tbtLevelMobile(395) === 1 && tbtLevelDesktop(395) === 2,
  'The forbes.com desktop case: 395 ms is AMBER on mobile but RED on desktop, which is ' +
  'what PSI actually reported (score 0.42).'
);

// Threshold tables carry both device entries with the exact numbers.
assert(
  /'tbt_mobile'\s*=>\s*\[\s*'good'\s*=>\s*200,\s*'warn'\s*=>\s*600\s*\]/.test(cmpSrc),
  "compare.php must define 'tbt_mobile' => ['good'=>200,'warn'=>600]."
);
assert(
  /'tbt_desktop'\s*=>\s*\[\s*'good'\s*=>\s*150,\s*'warn'\s*=>\s*350\s*\]/.test(cmpSrc),
  "compare.php must define 'tbt_desktop' => ['good'=>150,'warn'=>350]."
);
assert(
  /mobile:\s*\{\s*good:\s*200,\s*ok:\s*600\s*\}/.test(adminSrc) &&
  /desktop:\s*\{\s*good:\s*150,\s*ok:\s*350\s*\}/.test(adminSrc),
  'admin-scripts.js tbtMs table must carry both device entries with 200/600 and 150/350.'
);

// No INP threshold literals or helpers may survive on the lab surfaces.
const labFiles = [['admin-scripts.js', adminSrc], ['report-scripts.js', reportSrc],
                  ['compare.php', cmpSrc], ['conclusion.php', conclSrc],
                  ['summary.php', summarySrc]];
for (const pair of labFiles) {
  assert(
    !/inp_bad|inpMs\s*<=\s*200|inpM\s*<=\s*200|function inpLevel|'inp'\s*=>\s*\[/.test(pair[1]),
    pair[0] + ' still carries an INP threshold literal or helper. Bare 200/500 values live ' +
    'OUTSIDE the tables — grep the literals, not just the keys.'
  );
}

// AC-5 addendum — the tile renderer has a FIFTH comma-parsing site, inline
// rather than in a named function, so the executed AC-5 checks above cannot
// reach it. Pin it by source text.
const tileStart = adminSrc.indexOf('$inpCard.length');
assert(tileStart !== -1, 'Could not locate the TBT tile renderer.');
const tileBlock = adminSrc.slice(tileStart, tileStart + 2200);
assert(
  tileBlock.indexOf("replace(/,/g, '')") !== -1,
  'The inline TBT tile parser must use the same thousands-separator rule as inpMs(). It ' +
  'parses the very metric this release introduces.'
);

// ---------------------------------------------------------------------------
// AC-10 — customer-visible copy, and the D3 history notice.
// ---------------------------------------------------------------------------
const editorsSrc = loadSrc('editors.php');
const diagSrc2 = loadSrc('diagnostics.php');

assert(
  cmpSrc.indexOf('measured as INP and is preserved in the raw log') !== -1 &&
  /v1\.19/.test(cmpSrc),
  'The Compare tab must carry the D3 history notice naming the version, or the blank ' +
  'pre-upgrade cells read as a bug.'
);
assert(
  cmpSrc.indexOf('<span class="h-main">TBT (ms)</span>') !== -1,
  'The Compare column header must read TBT (ms).'
);
assert(
  reportSrc.indexOf('Your TBT is') !== -1 && reportSrc.indexOf('Your INP is') === -1,
  'The PDF pages must report TBT, not INP.'
);
assert(
  /long tasks|main thread|main-thread/i.test(reportSrc),
  'PDF advice must describe main-thread blocking, not interaction latency.'
);
assert(
  conclSrc.indexOf('interaction latency') === -1 &&
  conclSrc.indexOf('interactions feel instant') === -1,
  'conclusion.php advice must no longer describe INP-style interaction latency.'
);
assert(
  schedSrc.indexOf("$body .= 'TBT: <span") !== -1,
  'The scheduled email must label the row TBT.'
);
assert(
  schedSrc.indexOf('INP 200ms') === -1 && /TBT 200ms mobile \/ 150ms desktop/.test(schedSrc),
  'The scheduler threshold prose must quote both device TBT numbers.'
);
assert(
  editorsSrc.indexOf(">TBT: '") !== -1 && editorsSrc.indexOf('\\bTBT\\s*:') !== -1,
  'The editor column must be labelled TBT and read the TBT log line.'
);
assert(
  diagSrc2.indexOf('<div class="header">TBT ') !== -1,
  'The score tiles must be labelled TBT.'
);
assert(
  loadSrc('summary.php').indexOf('<div class="header">TBT</div>') !== -1,
  'The summary tile must be labelled TBT.'
);

// D8 — the alert metric setting is the one genuinely persisted key.
assert(
  /\$allowed_reg_metrics\s*=\s*array\([^)]*'tbt'[^)]*'inp'[^)]*\)/.test(schedSrc) &&
  /\$allowed_thresh_metrics\s*=\s*array\([^)]*'tbt'[^)]*'inp'[^)]*\)/.test(schedSrc),
  'Both alert allowlists must accept "tbt" AND keep "inp" as a legacy alias, or an alert ' +
  'configured before 1.19.0 is rejected on the next save.'
);
assert(
  schedSrc.indexOf("if ( 'inp' === $reg_metric ) {") !== -1 &&
  schedSrc.indexOf("if ( 'inp' === $th_metric ) {") !== -1,
  'A legacy "inp" setting already in the database must be normalised to "tbt" ON READ, ' +
  'not only when the user re-saves.'
);
assert(
  schedSrc.indexOf("$prev_dev['tbt'] ?? $prev_dev['inp'] ?? null") !== -1,
  'Regression comparison must fall back to the legacy "inp" key in previous-run snapshots.'
);
// Every surviving `'inp' === $x` branch must be a NORMALISER (its body assigns
// 'tbt'). A comparison branch that merely acts on 'inp' would be dead code once
// the value is normalised upstream, and the alert would silently never fire.
const inpBranches = [];
const branchRe = /if \(\s*'inp'\s*===\s*\$(\w+)\s*\)\s*\{([\s\S]{0,120}?)\}/g;
let bm;
while ((bm = branchRe.exec(schedSrc)) !== null) {
  inpBranches.push({ variable: bm[1], body: bm[2] });
}
assert(inpBranches.length > 0, 'The D8 legacy-alias normalisers must exist.');
for (const br of inpBranches) {
  assert(
    /=\s*'tbt'/.test(br.body),
    'The branch on $' + br.variable + " compares against 'inp' but does not normalise to " +
    "'tbt'. Once the value is normalised upstream such a branch is dead, and the alert " +
    'would silently never fire. Body: ' + JSON.stringify(br.body.trim().slice(0, 80))
  );
}
assert(
  !/elseif \(\s*'inp'\s*===\s*\$(th_metric|mk)\s*\)/.test(schedSrc),
  'No alert comparison branch may still test for "inp" — the value is normalised to ' +
  '"tbt" before those run.'
);
assert(
  schedSrc.indexOf('<option value="tbt"') !== -1 && schedSrc.indexOf('<option value="inp"') === -1,
  'The alert dropdowns must offer TBT.'
);

// ---------------------------------------------------------------------------
// AC-9 — the FIELD lane must keep INP.
//
// This guard exists because "replace INP with TBT everywhere" is the obvious
// reading, and acting on it here would be a correctness bug, not a cosmetic one.
// TBT has no CrUX field data. Google defines the Core Web Vitals assessment as
// LCP + INP + CLS, so dropping INP makes it a two-of-three check that can print
// PASSED for a site Google marks FAILED, on a customer report. Google's own
// guidance: TBT is "a proxy metric for INP in the lab".
// ---------------------------------------------------------------------------
const helpersSrc = loadSrc('helpers.php');
const cwvUiSrc = loadSrc('cwv-ui.js');

assert(
  helpersSrc.indexOf('INTERACTION_TO_NEXT_PAINT') !== -1,
  'helpers.php must still read INTERACTION_TO_NEXT_PAINT — the field lane keeps INP.'
);
const assessStart = helpersSrc.indexOf('function wpsa_cwv_assessment_from_metrics');
assert(assessStart !== -1, 'wpsa_cwv_assessment_from_metrics() must still exist.');
const assessBody = helpersSrc.slice(assessStart, assessStart + 1800);
for (const k of ['LARGEST_CONTENTFUL_PAINT_MS', 'CUMULATIVE_LAYOUT_SHIFT_SCORE',
                 'INTERACTION_TO_NEXT_PAINT']) {
  assert(
    assessBody.indexOf(k) !== -1,
    'The CWV assessment must still require ' + k + '. All three are needed: a two-of-three ' +
    'verdict can print PASSED where Google reports FAILED.'
  );
}
assert(
  assessBody.indexOf('total-blocking-time') === -1 && assessBody.indexOf('TBT') === -1,
  'TBT must NEVER appear in the field CWV assessment — there is no field TBT.'
);
assert(
  cwvUiSrc.indexOf('75th percentile INP') !== -1,
  'cwv-ui.js is the CrUX field panel and must keep its INP block.'
);
assert(
  cwvUiSrc.indexOf('TBT') === -1,
  'cwv-ui.js must not mention TBT — it renders real-user field data only.'
);
assert(
  cwvUiSrc.indexOf('gradeInpMs') !== -1 && /if \(v <= 200\) return 'good'/.test(cwvUiSrc),
  "cwv-ui.js must keep INP's device-independent Core Web Vitals thresholds (200/500)."
);

// ---------------------------------------------------------------------------
// PRODUCER/CONSUMER CONSISTENCY on window._wpsa_perf.
//
// The renderer, the summary and the log writer all read `.tbt` off this object.
// Four separate places WRITE it: the AJAX response handler, the log rehydrator,
// a failure placeholder and a reset. Changing the readers without the writers
// leaves the tile and the results log silently showing N/A — which is exactly
// what happened during implementation, on three of the four writers.
// ---------------------------------------------------------------------------
assert(
  !/\binp:\s*(inpTxt|'N\/A')/.test(adminSrc),
  'A window._wpsa_perf writer still sets the key `inp`. Every reader now uses `.tbt`, ' +
  'so this silently yields undefined and the tile and results log show N/A.'
);
const perfWriters = (adminSrc.match(/\btbt:\s*(inpTxt|'N\/A'|tbtTxt)/g) || []).length;
assert(
  perfWriters >= 4,
  'Expected at least 4 window._wpsa_perf writers to set `tbt` (AJAX handler, log ' +
  'rehydrator, failure placeholder, reset); found ' + perfWriters + '.'
);
assert(
  loadSrc('schedule-scripts.js').indexOf("m === 'tbt'") !== -1 &&
  loadSrc('schedule-scripts.js').indexOf("m === 'inp'") === -1,
  'schedule-scripts.js must branch on "tbt" for the threshold-input defaults. The ' +
  'dropdown now emits "tbt", so an "inp" branch is dead and selecting TBT would fall ' +
  "through to LCP's defaults (2.5, step 0.1, suffix s) instead of 200/1/ms."
);

console.log('OK tbt-harness');
