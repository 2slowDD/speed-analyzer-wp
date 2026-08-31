const assert = require('assert');
const fs = require('fs');
const path = require('path');

const js = fs.readFileSync(path.join(__dirname, '..', 'assets/js/admin-scripts.js'), 'utf8');

const stateFn = js.match(/function wpsa_updateLoadBtnState\(\)\s*\{[\s\S]*?\n\}/);
assert(stateFn, 'Missing wpsa_updateLoadBtnState');
assert(
  /var\s+hasNumber\s*=\s*parseInt\([^;]+,\s*10\s*\)\s*>\s*0\s*;/.test(stateFn[0]),
  'Load button state should require a positive test number, not just any text'
);
assert(
  /var\s+enable\s*=\s*hasNumber\s*;/.test(stateFn[0]),
  'A valid test number should enable the Load button even before a report shell is present'
);

assert(
  /\.on\('input\.wpsaLoadInput change\.wpsaLoadInput keyup\.wpsaLoadInput paste\.wpsaLoadInput'/.test(js),
  'Load test input should refresh state on input, change, keyup, and paste'
);
assert(
  /setTimeout\(wpsa_updateLoadBtnState,\s*0\)/.test(js),
  'Load button state should refresh once more after browser restore/autofill settles'
);

console.log('OK load-test-button-state');
