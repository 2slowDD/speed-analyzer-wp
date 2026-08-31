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

// ---------------------------------------------------------------------------
// Progress notice basics
// ---------------------------------------------------------------------------
const messageIndex = source.indexOf(message);
const helperIndex = source.indexOf('function showPdfBuildNotice');
const renderHelperIndex = source.indexOf('function renderPdfBuildNotice');
const callIndex = source.indexOf('showPdfBuildNotice($btn);');
const postIndex = source.indexOf('$.post(wpsaPdf.ajaxUrl');
const updateIndex = source.indexOf('updatePdfButton(r.data.remaining, r.data.limit);');
const updateRenderIndex = source.indexOf('renderPdfBuildNotice($top);');
const saveIndex = source.indexOf('.save()');
const cleanupIndex = source.indexOf('clearPdfBuildNotice();');

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
assert(cleanupIndex !== -1, 'Missing build notice cleanup.');

// ---------------------------------------------------------------------------
// The activity indicator must survive a blocked main thread.
//
// While html2canvas rasterises the report the main thread is busy, so anything
// driven by JS timers or by a main-thread-only CSS property stops dead and the
// page looks hung. Only transform/opacity animations are handed to the
// compositor and keep running. That property is what actually fixes the
// "Page Unresponsive" experience, so it is pinned here.
// ---------------------------------------------------------------------------
assert(source.includes('wpsaPdfBuildSpin'), 'Build notice must define a spinner animation.');
assert(source.includes('wpsaPdfBuildDotPulse'), 'Build notice must define an activity-dot animation.');
assert(source.includes('.wpsa-pdf-build-spinner'), 'Build notice must include a visible spinner element.');
assert(source.includes('wpsa-pdf-build-pulse-dot'), 'Build notice must include a pulsing activity dot.');

const styleStart = source.indexOf('$(\'<style id="wpsa-pdf-build-notice-style"></style>\')');
assert(styleStart !== -1, 'Missing injected build-notice stylesheet.');
const styleEnd = source.indexOf(".appendTo('head');", styleStart);
assert(styleEnd !== -1, 'Build-notice stylesheet is never appended.');
const styleBlock = source.slice(styleStart, styleEnd);

// The style block is built by concatenating JS string literals, so rebuild the
// real CSS text first, then walk only the balanced bodies of each @keyframes
// rule. Scanning the whole block would wrongly flag static rules (the spinner
// legitimately sets a fixed width/height; it just must not ANIMATE them).
const cssText = (styleBlock.match(/'[^']*'/g) || []).join('').replace(/'/g, '');

function keyframeBodiesOf(css) {
  const bodies = [];
  let i = 0;
  while ((i = css.indexOf('@keyframes', i)) !== -1) {
    const open = css.indexOf('{', i);
    if (open === -1) break;
    let depth = 0;
    let j = open;
    for (; j < css.length; j++) {
      if (css[j] === '{') { depth++; }
      else if (css[j] === '}') { depth--; if (depth === 0) { break; } }
    }
    bodies.push(css.slice(open, j + 1));
    i = j + 1;
  }
  return bodies;
}

const keyframeBodies = keyframeBodiesOf(cssText).join(' ');
assert(keyframeBodies.length > 0, 'Build notice must define at least one @keyframes rule.');
['box-shadow', 'background', 'width', 'height', 'margin', 'top', 'left', 'filter'].forEach(function (prop) {
  assert(
    !keyframeBodies.includes(prop + ':'),
    'Build-notice keyframes must not animate "' + prop + '" - it is main-thread only and freezes ' +
    'exactly when the report is rendering. Animate transform/opacity instead.'
  );
});
assert(
  keyframeBodies.includes('transform:'),
  'Build-notice keyframes must animate transform so the compositor can run them.'
);
assert(source.includes('@media (prefers-reduced-motion: reduce)'), 'Build notice animation must respect reduced-motion preferences.');
assert(!source.includes('startPdfBuildPulseFallback'), 'The JS pulse fallback cannot fire while the main thread is blocked; it should be gone.');
assert(!source.includes('window.setInterval'), 'No timer-driven pulse: timers do not fire while the main thread is blocked.');

// ---------------------------------------------------------------------------
// Staged build: the browser must get a repaint between the heavy steps.
// ---------------------------------------------------------------------------
assert(source.includes('function wpsaPdfYield()'), 'Missing the repaint-yield helper.');
assert(source.includes('requestAnimationFrame'), 'The yield helper must wait on animation frames so a frame is actually painted.');

// Order checks run against the code with whole-line // comments stripped, so a
// comment that merely mentions .toPdf() cannot be mistaken for the call itself.
const code = source
  .split(String.fromCharCode(10))
  .filter(function (line) { return line.trim().indexOf('//') !== 0; })
  .join(String.fromCharCode(10));

const containerIdx = code.indexOf('.toContainer()');
const canvasIdx = code.indexOf('.toCanvas()');
const toPdfIdx = code.indexOf('.toPdf()');
const getPdfIdx = code.indexOf(".get('pdf')");
assert(containerIdx !== -1, 'PDF build must call toContainer() explicitly so progress can be reported.');
assert(canvasIdx !== -1, 'PDF build must call toCanvas() explicitly so progress can be reported.');
assert(toPdfIdx !== -1, 'PDF build must still call toPdf().');
assert(containerIdx < canvasIdx, 'toContainer() must run before toCanvas().');
assert(canvasIdx < toPdfIdx, 'toCanvas() must run before toPdf().');
assert(toPdfIdx < getPdfIdx, "toPdf() must run before get('pdf').");

const chainEnd = code.indexOf('.save()', toPdfIdx);
assert(chainEnd !== -1, 'PDF chain must still end in save().');
const chain = code.slice(containerIdx, chainEnd);
const yieldsInChain = (chain.match(/\.then\(wpsaPdfYield\)/g) || []).length;
assert(
  yieldsInChain >= 2,
  'The PDF chain must yield to the browser between the heavy stages (found ' + yieldsInChain + ' yields).'
);

// Stage captions
assert(source.includes('WPSA_PDF_STAGES'), 'Missing per-stage caption table.');
assert(source.includes('function setPdfBuildStage'), 'Missing stage caption setter.');
['request', 'layout', 'render', 'assemble', 'save'].forEach(function (stage) {
  assert(source.includes('WPSA_PDF_STAGES.' + stage), 'Stage "' + stage + '" is never reported to the user.');
});
assert(source.includes('wpsa-pdf-build-stage'), 'Notice must render the stage caption element.');

// ---------------------------------------------------------------------------
// Canvas budget + regression guards
// ---------------------------------------------------------------------------
assert(source.includes('function wpsaPdfPickScale'), 'Missing the adaptive html2canvas scale helper.');
assert(source.includes('scale: pdfScale'), 'html2canvas must use the adaptive scale, not a hard-coded one.');
assert(!/html2canvas:\s*\{\s*scale:\s*2\b/.test(source), 'html2canvas scale must not be pinned back to a literal 2.');

// The canvas budget must be computed against the width html2canvas ACTUALLY
// rasterises - html2pdf's own container at the page's inner width (763px) - not
// against #report-container, which is a bare div on <body> and therefore inherits
// the full wp-admin content width (~1400px). Measuring the element's width
// overstates the canvas by ~1.8x and drops the scale a whole step too early: a
// 7-page report would render at 1.5 instead of 2.
const helperStart = source.indexOf('function wpsaPdfPickScale');
const helperEnd = source.indexOf('function setPdfBuildStage');
assert(helperEnd > helperStart, 'Could not bound the scale helper.');
const helperBody = source.slice(helperStart, helperEnd);
assert(
  helperBody.indexOf('scrollWidth') === -1 && helperBody.indexOf('offsetWidth') === -1,
  'wpsaPdfPickScale must NOT measure the element width. #report-container inherits the ' +
  'full wp-admin body width, which is not what html2canvas rasterises - using it steps ' +
  'the scale down a notch too early and needlessly softens normal-length reports.'
);
assert(
  helperBody.indexOf('WPSA_PDF_RASTER_W_PX') !== -1,
  'wpsaPdfPickScale must use the derived raster width constant.'
);
assert(
  source.indexOf('const WPSA_PDF_RASTER_W_PX') !== -1 &&
  source.indexOf('WPSA_PDF_PAGE_W_PT - WPSA_PDF_MARGIN_PT[1] - WPSA_PDF_MARGIN_PT[3]') !== -1,
  'The raster width must be derived from the page size and margins, not hard-coded.'
);

// The margin the budget is derived from and the margin handed to html2pdf must be
// the same constant, or the two can silently drift apart.
assert(
  /margin:\s*WPSA_PDF_MARGIN_PT/.test(source),
  'html2pdf().set() must be given the shared WPSA_PDF_MARGIN_PT constant so the ' +
  'canvas-budget math cannot drift from the real page margins.'
);

// enableLinks is why the report keeps clickable links; per-page canvas rendering
// was deliberately not used because it would lose them.
assert(source.includes('enableLinks: true'), 'enableLinks must stay on so PDF links remain clickable.');

// A failed client-side build must not leave the notice spinning forever.
const catchIdx = code.indexOf('.catch(function (err)', toPdfIdx);
assert(catchIdx !== -1, 'The PDF build chain must have a catch handler.');
assert(
  code.indexOf('clearPdfBuildNotice();', catchIdx) !== -1,
  'The catch handler must clear the build notice.'
);

console.log('pdf progress notice harness passed');
