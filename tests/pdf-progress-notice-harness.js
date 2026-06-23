const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'report-scripts.js'), 'utf8');
const message = 'Please wait a moment while the PDF report is being built.';

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const messageIndex = source.indexOf(message);
const helperIndex = source.indexOf('function showPdfBuildNotice');
const renderHelperIndex = source.indexOf('function renderPdfBuildNotice');
const callIndex = source.indexOf('showPdfBuildNotice($btn);');
const postIndex = source.indexOf('$.post(wpsaPdf.ajaxUrl');
const updateIndex = source.indexOf('updatePdfButton(r.data.remaining, r.data.limit);');
const updateRenderIndex = source.indexOf('renderPdfBuildNotice($top);');
const saveIndex = source.indexOf('.save()');
const cleanupIndex = source.indexOf('clearPdfBuildNotice();');
const yieldIndex = source.indexOf('window.setTimeout(function(){');
const prepIndex = source.indexOf('// Prepare off-DOM HTML & strip tooltips');
const pulseName = 'wpsaPdfBuildPulse';

assert(messageIndex !== -1, 'Missing PDF build progress message.');
assert(helperIndex !== -1, 'Missing showPdfBuildNotice helper.');
assert(renderHelperIndex !== -1, 'Missing near-button render helper.');
assert(callIndex !== -1, 'Missing build notice call.');
assert(postIndex !== -1, 'Missing PDF AJAX call.');
assert(updateIndex !== -1, 'Missing PDF button refresh call.');
assert(callIndex < postIndex, 'Build notice must appear before the server PDF request starts.');
assert(postIndex < saveIndex, 'Server PDF request should still happen before the save dialog path.');
assert(updateRenderIndex !== -1, 'Button refresh must preserve the build notice near the regenerated PDF buttons.');
assert(source.includes('$button.after($notice);'), 'Build notice must be inserted next to the Generate PDF button.');
assert(!source.includes("$('body').append($notice);"), 'Build notice should not be detached from the Generate PDF button area.');
assert(yieldIndex !== -1, 'PDF build must yield before heavy client-side rendering so the notice can paint.');
assert(yieldIndex < prepIndex, 'The paint-yield must happen before the heavy off-DOM PDF preparation starts.');
assert(source.includes(pulseName), 'Build notice must define a slow pulse animation.');
assert(source.includes('animation: \'wpsaPdfBuildPulse 2.4s ease-in-out infinite\''), 'Build notice must use the slow pulse animation.');
assert(source.includes('wpsa-pdf-build-pulse-dot'), 'Build notice must include a visible pulsing activity dot.');
assert(source.includes('wpsaPdfBuildDotPulse'), 'Build notice must define an activity-dot pulse animation.');
assert(source.includes('animation: \'wpsaPdfBuildDotPulse 1.45s ease-in-out infinite\''), 'Activity dot must use an obvious slow pulse animation.');
assert(source.includes('let wpsaPdfBuildPulseTimer = null;'), 'Build notice must track the JS pulse fallback timer.');
assert(source.includes('function startPdfBuildPulseFallback()'), 'Build notice must start a JS pulse fallback.');
assert(source.includes('window.setInterval(function(){'), 'JS pulse fallback must use a repeating timer.');
assert(source.includes('display: \'inline-block\''), 'Activity dot must be inline-block so transform pulses are visible.');
assert(source.includes('window.clearInterval(wpsaPdfBuildPulseTimer);'), 'Build notice cleanup must clear the pulse fallback timer.');
assert(source.includes('@media (prefers-reduced-motion: reduce)'), 'Build notice animation must respect reduced-motion preferences.');
assert(cleanupIndex !== -1, 'Missing build notice cleanup.');

console.log('pdf progress notice harness passed');
