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

console.log('OK tbt-harness');
