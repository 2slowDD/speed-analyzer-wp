const assert = require('assert');
const fs = require('fs');
const path = require('path');

const css = fs.readFileSync(path.join(__dirname, '..', 'admin-styles.css'), 'utf8');

const stackRule = css.match(/(?:^|\n)\.wpsa-feedback-stack\s*\{[\s\S]*?\}/);
assert(stackRule, 'Missing .wpsa-feedback-stack rule');
assert(
  /z-index\s*:\s*(?:0|1|2|3|4|5|6|7|8|9|10)\s*;/.test(stackRule[0]),
  '.wpsa-feedback-stack should stay on a low z-index layer'
);

const moduleZRule = css.match(/\/\* Report module z-index \*\/[\s\S]*?\{[\s\S]*?z-index\s*:\s*60\s*;[\s\S]*?\}/);
assert(moduleZRule, 'Missing report module z-index rule');
assert(
  /\.wpsa-module-5\b/.test(moduleZRule[0]),
  'Performance & Diagnostics (.wpsa-module-5) should layer above the right promo stack'
);

console.log('OK promo-card-z-index');
