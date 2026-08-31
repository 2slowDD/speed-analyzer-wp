// Guards the cr_url comparison key against a well-meaning "fix".
//
// cr_url carries a canonical, scheme-less "host/path" key. It is tempting to
// silence the Plugin Check InputNotSanitized warning by wrapping it in
// sanitize_text_field() - but WP core's _sanitize_text_fields() strips every
// %[a-f0-9]{2} sequence, so "example.com/caf%C3%A9" becomes "example.com/caf",
// matches no row, and the Compare tab silently shows a DIFFERENT URL's data.
// esc_url_raw() is equally wrong: the key has no scheme, and esc_url() prepends
// one. The correct handling is wp_unslash() + trim() behind a justified ignore.
//
// Run: node tests/compare-url-key-harness.js

const fs = require('fs');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '..', 'compare.php'), 'utf8');

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const lines = source.split(/\r?\n/);
const readLines = lines.filter(function (line) {
  return line.indexOf('$raw_selected') !== -1 && line.indexOf("$_GET['cr_url']") !== -1;
});

assert(readLines.length === 1, 'Expected exactly one cr_url read, found ' + readLines.length + '.');
const read = readLines[0];

assert(
  read.indexOf('wp_unslash(') !== -1,
  'The cr_url read must still wp_unslash() the request value.'
);
assert(
  read.indexOf('trim(') !== -1,
  'The cr_url read must use trim() to normalise the key.'
);
assert(
  read.indexOf('sanitize_text_field(') === -1,
  'cr_url must NOT go through sanitize_text_field(): WP core strips percent-encoded ' +
  'octets, which corrupts canonical URL keys containing %XX and makes the Compare tab ' +
  'fall back to a different URL. Use trim( wp_unslash( ... ) ) behind a justified ignore.'
);
assert(
  read.indexOf('esc_url_raw(') === -1 && read.indexOf('sanitize_url(') === -1,
  'cr_url must NOT go through esc_url_raw()/sanitize_url(): the canonical key is ' +
  'scheme-less and esc_url() would prepend a scheme, breaking the match.'
);

// The suppression must still be present and must name both sniffs, otherwise
// Plugin Check goes red again and the next person re-applies the broken fix.
const readIndex = lines.indexOf(read);
const preceding = lines.slice(Math.max(0, readIndex - 8), readIndex).join('\n');
assert(
  preceding.indexOf('phpcs:ignore') !== -1,
  'The cr_url read must carry a phpcs:ignore directive.'
);
assert(
  preceding.indexOf('WordPress.Security.ValidatedSanitizedInput.InputNotSanitized') !== -1,
  'The cr_url ignore must name InputNotSanitized.'
);
assert(
  preceding.indexOf('WordPress.Security.NonceVerification.Recommended') !== -1,
  'The cr_url ignore must still name NonceVerification.Recommended.'
);
assert(
  /phpcs:ignore[^\n]*\n\s*\$raw_selected/.test(source.replace(/\r\n/g, '\n')),
  'The phpcs:ignore directive must sit directly above the cr_url read - a comment ' +
  'line in between consumes the directive scope and silently disables it.'
);
assert(
  preceding.indexOf('%') !== -1,
  'Keep the explanation of why sanitize_text_field() is wrong next to the ignore.'
);

// Every place the key reaches output must still escape it.
assert(
  source.indexOf("esc_attr( $selected_key )") !== -1,
  'The hidden cr_url input must keep escaping $selected_key with esc_attr().'
);

console.log('OK compare-url-key');
