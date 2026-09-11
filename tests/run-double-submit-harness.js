// Speed Analyzer — Run button double-submit guard.
//
// A second click on Run (or Enter, or a button re-enabled by other code) while the
// first run's form POST was still on its way started a second run: it used a daily
// test, got no test number (the 60-second header guard in includes/helpers.php) and
// logged its module lines under the previous test. The submit handler now claims the
// form once; every later submit is refused until the page is restored from the
// browser's back/forward cache.
//
// This harness EXECUTES the guard functions and the pageshow listener from
// assets/js/admin-scripts.js, and pins that the submit handler asks the guard before
// it touches anything else.
//
// Run: node tests/run-double-submit-harness.js

const fs = require('fs');
const path = require('path');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const src = fs.readFileSync(path.join(__dirname, '..', 'assets/js/admin-scripts.js'), 'utf8');

// Brace-matched slice from `start` (the same approach as tests/tbt-harness.js).
function braceSlice(source, start, label) {
  assert(start !== -1, label + ' not found in admin-scripts.js.');
  let depth = 0;
  for (let j = source.indexOf('{', start); j < source.length; j++) {
    if (source[j] === '{') {
      depth++;
    } else if (source[j] === '}') {
      depth--;
      if (depth === 0) return source.slice(start, j + 1);
    }
  }
  throw new Error('Unbalanced braces extracting ' + label + '.');
}

// DEV-ONLY: instantiates this repository's own source; see the note in tests/tbt-harness.js.
function named(name) {
  const fnSrc = braceSlice(src, src.indexOf('function ' + name + '('), 'function ' + name);
  return new Function(fnSrc + '; return ' + name + ';')();
}

function fakeElement() {
  const attrs = {};
  return {
    getAttribute: (k) => (Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null),
    setAttribute: (k, v) => { attrs[k] = String(v); },
    removeAttribute: (k) => { delete attrs[k]; },
  };
}
function fakeButton() {
  const b = fakeElement();
  b.disabled = false;
  return b;
}

const claim = named('wpsa_claimRunSubmit');
const release = named('wpsa_releaseRunSubmit');

// 1. The first submit goes through and locks the Run button.
const form = fakeElement();
const btn = fakeButton();
assert(claim(form, btn) === true, 'The first submit must be allowed.');
assert(btn.disabled === true, 'The first submit must disable the Run button.');
assert(btn.getAttribute('aria-disabled') === 'true', 'The first submit must mark the Run button aria-disabled.');

// 2. Every later submit is refused, even when something has re-enabled the button.
btn.disabled = false;
assert(claim(form, btn) === false, 'A second submit must be refused.');
assert(claim(form, null) === false, 'A second submit must be refused when no button is found, too.');

// 3. Release (the back/forward-cache restore) unlocks the form and the button.
release(form, btn);
assert(btn.disabled === false, 'Release must enable the Run button.');
assert(btn.getAttribute('aria-disabled') === null, 'Release must clear aria-disabled.');
assert(claim(form, btn) === true, 'After a release the next submit must be allowed again.');

// 4. The pageshow listener releases only when the page comes back from the
//    back/forward cache; an ordinary page show leaves a submitted form locked.
const psAt = src.indexOf("addEventListener('pageshow'");
assert(psAt !== -1, 'The pageshow listener is missing from admin-scripts.js.');
const psSrc = braceSlice(src, src.indexOf('function', psAt), 'the pageshow listener');
function showPage(persisted) {
  const f = fakeElement();
  const b = fakeButton();
  f.querySelector = (sel) => (sel === '.wpsa-button-run' ? b : null);
  claim(f, b);
  const doc = { getElementById: (id) => (id === 'speed-test-form' ? f : null) };
  const listener = new Function('wpsa_releaseRunSubmit', 'document', 'return (' + psSrc + ');')(release, doc);
  listener({ persisted: persisted });
  return { f, b };
}
let shown = showPage(true);
assert(shown.b.disabled === false && claim(shown.f, shown.b) === true,
  'A back/forward-cache restore must release the Run button.');
shown = showPage(false);
assert(shown.b.disabled === true && claim(shown.f, shown.b) === false,
  'An ordinary page show must leave a submitted form locked.');

// 5. The submit handler asks the guard before anything else and cancels a refused
//    submit, so a refused click resets no state and sends nothing.
const hAt = src.indexOf("$('#speed-test-form').on('submit'");
assert(hAt !== -1, 'The Run form submit handler is missing from admin-scripts.js.');
const handler = braceSlice(src, src.indexOf('function', hAt), 'the Run form submit handler');
assert(
  /^function\s*\(e\)\s*\{\s*if\s*\(\s*!\s*wpsa_claimRunSubmit\(\s*this\s*,\s*\$\(this\)\.find\('\.wpsa-button-run'\)\.get\(0\)\s*\)\s*\)\s*\{\s*e\.preventDefault\(\);\s*return false;\s*\}/.test(handler),
  'The submit handler must ask wpsa_claimRunSubmit first and cancel the submit when refused.'
);

console.log('run double-submit harness passed');
