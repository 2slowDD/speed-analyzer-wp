const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'wp-speed-analyzer.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'admin-styles.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'admin-scripts.js'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');
const readmeMd = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
const reportJs = fs.readFileSync(path.join(root, 'report-scripts.js'), 'utf8');

const toggleIndex = php.indexOf('id="wpsa-sidebars-top-toggle"');
const navIndex = php.indexOf('class="wpsa-sidebar-nav"');
assert(toggleIndex > -1, 'Missing sidebars-on-top checkbox');
assert(navIndex > -1, 'Missing sidebar nav markup');
assert(toggleIndex < navIndex, 'Checkbox should render above .wpsa-sidebar-nav');
assert(php.includes('Place sidebars on top'), 'Missing checkbox label text');

assert(css.includes('.wpsa-sidebar-options'), 'Missing checkbox card styles');
assert(
  /\.wpsa-layout\.wpsa-sidebars-on-top[\s\S]*?z-index\s*:\s*(?:[7-9]\d|[1-9]\d{2,})\s*;/.test(css),
  'Toggled sidebars must layer above report modules'
);
assert(
  css.includes('.wpsa-sidebar-nav') && css.includes('.wpsa-feedback-stack'),
  'Toggle styles should cover left and right sidebar cards'
);

assert(js.includes("WPSA_SIDEBARS_TOP_KEY = 'wpsa:sidebarsOnTop:v1'"), 'Missing persisted preference key');
assert(js.includes("toggleClass('wpsa-sidebars-on-top'"), 'Missing layout class toggle');
assert(js.includes("change.wpsaSidebarsTop"), 'Missing checkbox change handler');
assert(js.includes('wpsa_initSidebarsTopToggle();'), 'Missing sidebars toggle init call');

assert(php.includes('* Version:         1.18.5'), 'Plugin header should be bumped to 1.18.5');
assert(php.includes("define( 'SAWP_VERSION', '1.18.5' );"), 'SAWP_VERSION should be bumped to 1.18.5');
assert(readme.includes('Stable tag: 1.18.5'), 'readme.txt stable tag should be bumped');
assert(readme.includes('= 1.18.5 ='), 'readme.txt changelog should include 1.18.5');
assert(readmeMd.includes('Version 1.18.5'), 'README badge should be bumped');
assert(readmeMd.includes('Version: 1.18.5'), 'README version line should be bumped');
assert(js.includes('admin-scripts.js Version: v0.797'), 'admin-scripts marker should be bumped');
assert(css.includes('admin-styles.css - Version: v0.746'), 'admin-styles marker should be bumped');
assert(!/Perfmatters/i.test(reportJs), 'PDF report script should not mention Perfmatters');
assert(reportJs.includes('https://wpservice.pro/our-products/ai-assets-scanner/'), 'PDF report script should link AI Assets Scanner product page');
assert(reportJs.includes('AI Assets Scanner'), 'PDF report script should mention AI Assets Scanner');

console.log('OK sidebar-top-toggle');
