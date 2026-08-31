/* CWV handling, v0.10 */
/* global ajaxurl, wpsaData */
(function ($) {
  'use strict';
  
  function isWpsaTestsTab() {
    try {
      var url = new URL(window.location.href);
      var page = url.searchParams.get('page') || '';
      var tab  = url.searchParams.get('tab'); // may be null

      if (page !== 'speed-analyzer') return false;

      // Default tab (no tab param) should be treated as "tests"
      if (tab === null || tab === '' || tab === 'tests') return true;

      return false;
    } catch (e) {
      // Fallback: allow page=speed-analyzer with no tab or tab=tests
      var qs = String(window.location.search || '');
      if (qs.indexOf('page=speed-analyzer') === -1) return false;

      // If "tab=" doesn't exist at all -> default tests
      if (qs.indexOf('tab=') === -1) return true;

      return (qs.indexOf('tab=tests') !== -1);
    }
  }



var inflight = { mobile: false, desktop: false };
var lastCall = { mobile: 0, desktop: 0 };
var MIN_GAP_MS = 1200;

  var retryCount = { mobile: 0, desktop: 0 };
  var retryKey = { mobile: '', desktop: '' };
  var MAX_RETRIES = 5;


// Track last-known test number (learned from PSI AJAX)
var currentTestNo = 0;

// Prevent re-fetching CWV for the same test/strategy over and over
var lastFetchedTestNo = { mobile: 0, desktop: 0 };

// Cache p75 (field) metrics from CWV response per strategy
var lastP75 = { mobile: null, desktop: null };

  function hasCWVPanels() {
    return !!(document.getElementById('perf-mobile') || document.getElementById('perf-desktop'));
  }

    function findTestNo() {
  // Best source: learned from the PSI AJAX payload
  if (currentTestNo && currentTestNo > 0) return currentTestNo;

  // Preferred: any element that carries data-test-no
  var el = document.querySelector('[data-test-no]');

    if (el) {
      var raw = el.getAttribute('data-test-no');
      var n = parseInt(raw, 10);
      if (!isNaN(n) && n > 0) return n;
    }

    // Fallback: try to parse "Test #123" from visible HTML
    var scope = document.getElementById('test-results') || document;
    var txt = (scope && scope.innerText) ? String(scope.innerText) : '';
    var m = txt.match(/Test\s*#\s*(\d+)/i);
    if (m && m[1]) {
      var n3 = parseInt(m[1], 10);
      if (!isNaN(n3) && n3 > 0) return n3;
    }

    // Fallback: Load test # input
    var inp = document.getElementById('wpsa-loadtest-input');
    if (inp && inp.value) {
      var n2 = parseInt(inp.value, 10);
      if (!isNaN(n2) && n2 > 0) return n2;
    }

    return 0;
  }


  function ensureRow(strategy) {
    var panelId = strategy === 'desktop' ? 'perf-desktop' : 'perf-mobile';
    var panel = document.getElementById(panelId);
    if (!panel) return null;

    var existing = panel.querySelector('.wpsa-cwv-assessment-row');
    if (existing) return existing;

    var h3 = panel.querySelector('h3.wpsa-subheading');
    if (!h3) return null;

    var row = document.createElement('div');
    row.className = 'wpsa-cwv-assessment-row';
    row.setAttribute('data-strategy', strategy);
    row.setAttribute('data-cwv-status', 'unknown');
    row.style.margin = '6px 0 10px 0';
    row.style.fontSize = '13px';
    row.style.opacity = '0.95';

row.innerHTML =
  'Core Web Vitals (field) Assessment: ' +
  '<strong class="wpsa-cwv-status">--</strong>' +
  '<span class="wpsa-cwv-scope"></span>' +
  ' <span class="custom-tooltip" data-tooltip="The real user (field) data gathered from the Core Web Vitals metrics over the latest 28-day collection period. If URL-level data has insufficient samples, it will show Origin data or -- instead. Origin is aggregated real-user (field) data for the site overall.">?</span>' +
 ' <button type="button" class="button-link wpsa-psi-metrics-toggle" data-strategy="' + strategy + '" aria-expanded="false" style="cursor:pointer;">Show field metrics</button>';


    if (h3.nextSibling) {
      h3.parentNode.insertBefore(row, h3.nextSibling);
    } else {
      h3.parentNode.appendChild(row);
    }
    
    return row;
  }

function renderRow(strategy, payload) {
  var row = ensureRow(strategy);
  if (!row) return;

  var statusEl = row.querySelector('.wpsa-cwv-status');
  var scopeEl = row.querySelector('.wpsa-cwv-scope');
  if (!statusEl || !scopeEl) return;

  var a = (payload && payload.assessment) ? String(payload.assessment).toUpperCase() : '';

  // normalize a simple status token for CSS hooks
  var token = 'unknown';
  if (a === 'PASSED') token = 'passed';
  if (a === 'FAILED') token = 'failed';

  // easy-to-style hooks
  row.setAttribute('data-cwv-status', token);
  row.classList.remove('is-cwv-passed', 'is-cwv-failed', 'is-cwv-unknown');
  row.classList.add('is-cwv-' + token);

  statusEl.setAttribute('data-cwv-status', token);
  statusEl.classList.remove('is-cwv-passed', 'is-cwv-failed', 'is-cwv-unknown');
  statusEl.classList.add('is-cwv-' + token);

  // text label (with icons)
  var statusText = '--';
  if (a === 'PASSED') statusText = 'PASSED ✅';
  if (a === 'FAILED') statusText = 'FAILED ❌';

  var scope = (payload && payload.scope) ? String(payload.scope) : '';
  scope = (String(scope).toLowerCase() === 'origin') ? 'Origin' : (scope ? scope : 'URL');

  statusEl.textContent = statusText;
  scopeEl.textContent = (statusText !== '--') ? (' - ' + scope) : '';
}

function gradeLcpSeconds(v) {
  if (!isFinite(v)) return 'unknown';
  if (v <= 2.5) return 'good';
  if (v <= 4.0) return 'ni';
  return 'poor';
}

function gradeInpMs(v) {
  if (!isFinite(v)) return 'unknown';
  if (v <= 200) return 'good';
  if (v <= 500) return 'ni';
  return 'poor';
}

function gradeCls(v) {
  if (!isFinite(v)) return 'unknown';
  if (v <= 0.1) return 'good';
  if (v <= 0.25) return 'ni';
  return 'poor';
}

function gradeFcpSeconds(v) {
  if (!isFinite(v)) return 'unknown';
  if (v <= 1.8) return 'good';
  if (v <= 3.0) return 'ni';
  return 'poor';
}

function gradeTtfbSeconds(v) {
  if (!isFinite(v)) return 'unknown';
  if (v <= 0.8) return 'good';
  if (v <= 1.8) return 'ni';
  return 'poor';
}

function metricColorClass(grade) {
  if (grade === 'good') return 'is-good';
  if (grade === 'ni') return 'is-ni';
  if (grade === 'poor') return 'is-poor';
  return 'is-unknown';
}

// Try to extract a metric value from the existing metric cards in the panel.
// If not found, we fall back to sample values (so UI is still visible).
function extractMetricFromPanel(strategy, metricKey) {
  var panelId = strategy === 'desktop' ? 'perf-desktop' : 'perf-mobile';
  var panel = document.getElementById(panelId);
  if (!panel) return null;

  var label = metricKey === 'lcp' ? 'LCP' : 'INP';
  var unitRe = metricKey === 'lcp'
    ? /(\d+(?:\.\d+)?)\s*s\b/i
    : /(\d+(?:\.\d+)?)\s*ms\b/i;

  var candidates = panel.querySelectorAll('.wpsa-stat-cards, .wpsa-stat-card, .wpsa-stat-box, .metric, .metrics, .wpsa-module-5, div');
  for (var i = 0; i < candidates.length; i++) {
    var el = candidates[i];
    if (!el || !el.textContent) continue;

    var txt = String(el.textContent);
    if (txt.indexOf(label) === -1) continue;

    var m = txt.match(unitRe);
    if (m && m[1]) {
      var n = parseFloat(m[1]);
      if (isFinite(n)) return n;
    }
  }

  return null;
}

function buildPsiMetricBlock(key, suffix, title, valueText, goodLabel, niLabel, poorLabel, goodPct, niPct, poorPct) {
  return '' +
    '<div class="wpsa-psi-metric wpsa-psi-metric-' + key + '" id="wpsa-psi-metric-' + key + '-' + suffix + '">' +
      '<div class="wpsa-psi-metric-head" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">' +
        '<div class="wpsa-psi-metric-title" id="wpsa-psi-' + key + '-title-' + suffix + '" style="font-weight:600;">' + title + '</div>' +
        '<div class="wpsa-psi-metric-value is-good" id="wpsa-psi-' + key + '-value-' + suffix + '" data-grade="good" style="font-weight:700;">' + valueText + '</div>' +
      '</div>' +
      '<div class="wpsa-psi-dist" id="wpsa-psi-' + key + '-dist-' + suffix + '">' +
        '<div class="wpsa-psi-dist-row wpsa-psi-dist-good" id="wpsa-psi-' + key + '-good-' + suffix + '" style="display:flex;justify-content:space-between;gap:10px;">' +
          '<span class="wpsa-psi-dist-label">' + goodLabel + '</span>' +
          '<span class="wpsa-psi-dist-pct" id="wpsa-psi-' + key + '-good-pct-' + suffix + '">' + goodPct + '</span>' +
        '</div>' +
        '<div class="wpsa-psi-dist-row wpsa-psi-dist-ni" id="wpsa-psi-' + key + '-ni-' + suffix + '" style="display:flex;justify-content:space-between;gap:10px;">' +
          '<span class="wpsa-psi-dist-label">' + niLabel + '</span>' +
          '<span class="wpsa-psi-dist-pct" id="wpsa-psi-' + key + '-ni-pct-' + suffix + '">' + niPct + '</span>' +
        '</div>' +
        '<div class="wpsa-psi-dist-row wpsa-psi-dist-poor" id="wpsa-psi-' + key + '-poor-' + suffix + '" style="display:flex;justify-content:space-between;gap:10px;">' +
          '<span class="wpsa-psi-dist-label">' + poorLabel + '</span>' +
          '<span class="wpsa-psi-dist-pct" id="wpsa-psi-' + key + '-poor-pct-' + suffix + '">' + poorPct + '</span>' +
        '</div>' +
      '</div>' +
    '</div>';
}


function ensurePsiExpandedPanel(strategy) {
  var panelId = strategy === 'desktop' ? 'perf-desktop' : 'perf-mobile';
  var panel = document.getElementById(panelId);
  if (!panel) return null;

  var existing = panel.querySelector('.wpsa-psi-expanded-metrics[data-strategy="' + strategy + '"]');
  if (existing) return existing;

  var row = ensureRow(strategy);
  if (!row || !row.parentNode) return null;

  var wrap = document.createElement('div');
  wrap.className = 'wpsa-psi-expanded-metrics';
  wrap.setAttribute('data-strategy', strategy);
  wrap.style.display = 'none';

  var suffix = (strategy === 'desktop') ? 'desktop' : 'mobile';

  // PSI-like: 3 columns row (LCP/INP/CLS) then next row (FCP/TTFB)
  wrap.innerHTML =
    '<div class="wpsa-psi-grid" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:12px;border-radius:10px;background:#fff;">' +

        buildPsiMetricBlock('lcp',  suffix, '75th percentile LCP',  '--',  'Good (≤ 2.5 s)', 'Needs Improvement (2.5 s - 4 s)', 'Poor (&gt; 4 s)',  '--', '--', '--') +
        buildPsiMetricBlock('inp',  suffix, '75th percentile INP',  '--',  'Good (≤ 200 ms)', 'Needs Improvement (200 ms - 500 ms)', 'Poor (&gt; 500 ms)', '--', '--', '--') +
        buildPsiMetricBlock('cls',  suffix, '75th percentile CLS',  '--',  'Good (≤ 0.1)', 'Needs Improvement (0.1 - 0.25)', 'Poor (&gt; 0.25)', '--', '--', '--') +
        buildPsiMetricBlock('fcp',  suffix, '75th percentile FCP',  '--',  'Good (≤ 1.8 s)', 'Needs Improvement (1.8 s - 3 s)', 'Poor (&gt; 3 s)', '--', '--', '--') +
        buildPsiMetricBlock('ttfb', suffix, '75th percentile TTFB', '--',  'Good (≤ 0.8 s)', 'Needs Improvement (0.8 s - 1.8 s)', 'Poor (&gt; 1.8 s)', '--', '--', '--') +


      // 3-col grid spacer so 2nd row doesn’t stretch awkwardly
      '<div class="wpsa-psi-metric wpsa-psi-metric-spacer" style="opacity:0;"></div>' +

    '</div>';

  if (row.nextSibling) {
    row.parentNode.insertBefore(wrap, row.nextSibling);
  } else {
    row.parentNode.appendChild(wrap);
  }

  return wrap;
}


function updatePsiExpandedPanel(strategy) {
  var wrap = ensurePsiExpandedPanel(strategy);
  if (!wrap) return;

  var suffix = (strategy === 'desktop') ? 'desktop' : 'mobile';

  // Try to pull live values from the panel; fall back to the seeded sample values.
  var lcp = extractMetricFromPanel(strategy, 'lcp');
  if (!isFinite(lcp)) lcp = 2.0;

  var inp = extractMetricFromPanel(strategy, 'inp');
  if (!isFinite(inp)) inp = 102;

  var lcpGrade = gradeLcpSeconds(lcp);
  var inpGrade = gradeInpMs(inp);

  var lcpEl = document.getElementById('wpsa-psi-lcp-value-' + suffix);
  if (lcpEl) {
    lcpEl.textContent = lcp.toFixed( (lcp % 1 === 0) ? 0 : 1 ) + ' s';
    lcpEl.setAttribute('data-grade', lcpGrade);
    lcpEl.classList.remove('is-good', 'is-ni', 'is-poor', 'is-unknown');
    lcpEl.classList.add(metricColorClass(lcpGrade));
  }

  var inpEl = document.getElementById('wpsa-psi-inp-value-' + suffix);
  if (inpEl) {
    inpEl.textContent = Math.round(inp) + ' ms';
    inpEl.setAttribute('data-grade', inpGrade);
    inpEl.classList.remove('is-good', 'is-ni', 'is-poor', 'is-unknown');
    inpEl.classList.add(metricColorClass(inpGrade));
  }

  // Segment-level hooks (so you can style entire blocks later)
  var lcpWrap = document.getElementById('wpsa-psi-metric-lcp-' + suffix);
  if (lcpWrap) {
    lcpWrap.setAttribute('data-grade', lcpGrade);
    lcpWrap.classList.remove('is-good', 'is-ni', 'is-poor', 'is-unknown');
    lcpWrap.classList.add(metricColorClass(lcpGrade));
  }

  var inpWrap = document.getElementById('wpsa-psi-metric-inp-' + suffix);
  if (inpWrap) {
    inpWrap.setAttribute('data-grade', inpGrade);
    inpWrap.classList.remove('is-good', 'is-ni', 'is-poor', 'is-unknown');
    inpWrap.classList.add(metricColorClass(inpGrade));
  }
}

function applyP75ToExpandedPanel(strategy, p75) {
  if (!p75 || typeof p75 !== 'object') return;

  var suffix = (strategy === 'desktop') ? 'desktop' : 'mobile';

  function setPct(metricKey, dist) {
    if (!dist || !dist.length || dist.length < 3) return;
    var g = document.getElementById('wpsa-psi-' + metricKey + '-good-pct-' + suffix);
    var n = document.getElementById('wpsa-psi-' + metricKey + '-ni-pct-' + suffix);
    var p = document.getElementById('wpsa-psi-' + metricKey + '-poor-pct-' + suffix);
    if (g) g.textContent = String(dist[0]) + '%';
    if (n) n.textContent = String(dist[1]) + '%';
    if (p) p.textContent = String(dist[2]) + '%';
  }

  function setValue(metricKey, text, grade) {
    var el = document.getElementById('wpsa-psi-' + metricKey + '-value-' + suffix);
    if (el) {
      el.textContent = text;
      el.setAttribute('data-grade', grade);
      el.classList.remove('is-good', 'is-ni', 'is-poor', 'is-unknown');
      el.classList.add(metricColorClass(grade));
    }

    var wrap = document.getElementById('wpsa-psi-metric-' + metricKey + '-' + suffix);
    if (wrap) {
      wrap.setAttribute('data-grade', grade);
      wrap.classList.remove('is-good', 'is-ni', 'is-poor', 'is-unknown');
      wrap.classList.add(metricColorClass(grade));
    }
  }

  // LCP (ms -> s)
  if (typeof p75.lcp_ms === 'number' && isFinite(p75.lcp_ms)) {
    var lcpS = p75.lcp_ms / 1000;
    var lcpGrade = gradeLcpSeconds(lcpS);
    setValue('lcp', lcpS.toFixed((lcpS % 1 === 0) ? 0 : 1) + ' s', lcpGrade);
    setPct('lcp', p75.lcp_dist);
  }

  // INP (ms)
  if (typeof p75.inp_ms === 'number' && isFinite(p75.inp_ms)) {
    var inpMs = p75.inp_ms;
    var inpGrade = gradeInpMs(inpMs);
    setValue('inp', Math.round(inpMs) + ' ms', inpGrade);
    setPct('inp', p75.inp_dist);
  }

  // CLS (unitless)
  if (typeof p75.cls === 'number' && isFinite(p75.cls)) {
    var clsV = p75.cls;
    var clsGrade = gradeCls(clsV);
    var clsText = (clsV % 1 === 0) ? String(clsV.toFixed(0)) : String(clsV.toFixed(2));
    setValue('cls', clsText, clsGrade);
    setPct('cls', p75.cls_dist);
  }

  // FCP (ms -> s)
  if (typeof p75.fcp_ms === 'number' && isFinite(p75.fcp_ms)) {
    var fcpS = p75.fcp_ms / 1000;
    var fcpGrade = gradeFcpSeconds(fcpS);
    setValue('fcp', fcpS.toFixed((fcpS % 1 === 0) ? 0 : 1) + ' s', fcpGrade);
    setPct('fcp', p75.fcp_dist);
  }

  // TTFB (ms -> s)
  if (typeof p75.ttfb_ms === 'number' && isFinite(p75.ttfb_ms)) {
    var ttfbS = p75.ttfb_ms / 1000;
    var ttfbGrade = gradeTtfbSeconds(ttfbS);
    setValue('ttfb', ttfbS.toFixed((ttfbS % 1 === 0) ? 0 : 1) + ' s', ttfbGrade);
    setPct('ttfb', p75.ttfb_dist);
  }
}

function setEmptyExpandedPanel(strategy) {
  var suffix = (strategy === 'desktop') ? 'desktop' : 'mobile';
  var keys = ['lcp', 'inp', 'cls', 'fcp', 'ttfb'];

  for (var i = 0; i < keys.length; i++) {
    var k = keys[i];

    var vEl = document.getElementById('wpsa-psi-' + k + '-value-' + suffix);
    if (vEl) {
      vEl.textContent = '--';
      vEl.setAttribute('data-grade', 'unknown');
      vEl.classList.remove('is-good', 'is-ni', 'is-poor', 'is-unknown');
      vEl.classList.add('is-unknown');
    }

    var wrap = document.getElementById('wpsa-psi-metric-' + k + '-' + suffix);
    if (wrap) {
      wrap.setAttribute('data-grade', 'unknown');
      wrap.classList.remove('is-good', 'is-ni', 'is-poor', 'is-unknown');
      wrap.classList.add('is-unknown');
    }

    var g = document.getElementById('wpsa-psi-' + k + '-good-pct-' + suffix);
    var n = document.getElementById('wpsa-psi-' + k + '-ni-pct-' + suffix);
    var p = document.getElementById('wpsa-psi-' + k + '-poor-pct-' + suffix);
    if (g) g.textContent = '--';
    if (n) n.textContent = '--';
    if (p) p.textContent = '--';
  }
}


  function safeFetchCWV(strategy) {
    if (!hasCWVPanels()) return;

    var testNo = findTestNo();
if (!testNo) {
  renderRow(strategy, { assessment: '', scope: '' });
  return;
}

// If we already fetched CWV for this exact test_no + strategy and the row is not "--", don’t refetch.
var existingRow = ensureRow(strategy);
if (existingRow) {
  var existingStatusEl = existingRow.querySelector('.wpsa-cwv-status');
  var existingStatus = existingStatusEl ? String(existingStatusEl.textContent || '').trim() : '';
  if (lastFetchedTestNo[strategy] === testNo) {
  if (existingStatus && existingStatus !== '--') return;
  if (retryCount[strategy] >= MAX_RETRIES) return;
   }
}


    var now = Date.now();
    if (inflight[strategy]) return;
    if (now - lastCall[strategy] < MIN_GAP_MS) return;

    lastCall[strategy] = now;
    inflight[strategy] = true;

    var nval = (window.wpsaData && wpsaData.perfNonce) ? wpsaData.perfNonce : '';
    if (!nval) {
  inflight[strategy] = false;
  return; // no nonce → don’t spam admin-ajax
}


    $.post(ajaxurl, {
      action: 'wpsa_get_cwv_assessment',
      _ajax_nonce: nval,
      nonce: nval,
      test_no: testNo,
      strategy: strategy
    })

    .done(function (resp) {
        var data = (resp && resp.success && resp.data) ? resp.data : null;

        // Reset retry state when test changes
        var key = String(testNo) + '|' + String(strategy);
        if (retryKey[strategy] !== key) {
          retryKey[strategy] = key;
          retryCount[strategy] = 0;
        }

       if (data) {
      renderRow(strategy, data);
    
      // Cache p75 if provided by backend
      if (data.p75 && typeof data.p75 === 'object') {
        lastP75[strategy] = data.p75;
    
        // If the expanded panel exists and is open, update it immediately
        var wrap = ensurePsiExpandedPanel(strategy);
        if (wrap && wrap.style.display !== 'none') {
          applyP75ToExpandedPanel(strategy, data.p75);
        }
      }
    
      // If log isn't populated yet, retry a few times (bounded, throttled)
      var a = (data.assessment ? String(data.assessment) : '').toUpperCase();

          if (!a && retryCount[strategy] < MAX_RETRIES) {
              lastFetchedTestNo[strategy] = testNo; // marks “we already tried this test”, even if still empty
            retryCount[strategy]++;

            setTimeout(function () {
              safeFetchCWV(strategy);
            }, 1500 + (retryCount[strategy] * 800));
          } else if (a) {
            // Got a real answer -> stop retrying + remember we fetched this test
            retryCount[strategy] = 0;
            lastFetchedTestNo[strategy] = testNo;
          }
        } else {
          renderRow(strategy, { assessment: '', scope: '' });
        }
      })


      .fail(function (xhr) {
        renderRow(strategy, { assessment: '', scope: '' });
        
        // Hard stop on auth/nonce problems (prevents endless 403 / -1 loops)
        var txt = '';
        try { txt = String(xhr && xhr.responseText ? xhr.responseText : ''); } catch (e) {}
        var code = (xhr && xhr.status) ? parseInt(xhr.status, 10) : 0;
        if (code === 401 || code === 403 || txt === '-1') {
          retryCount[strategy] = MAX_RETRIES;
          return;
        }


        var key = String(testNo) + '|' + String(strategy);
        if (retryKey[strategy] !== key) {
          retryKey[strategy] = key;
          retryCount[strategy] = 0;
        }

        if (retryCount[strategy] < MAX_RETRIES) {
          retryCount[strategy]++;
          setTimeout(function () {
            safeFetchCWV(strategy);
          }, 1800 + (retryCount[strategy] * 900));
        }
      })

      .always(function () {
        inflight[strategy] = false;
      });
  }

  function fetchBoth() {
    safeFetchCWV('mobile');
    safeFetchCWV('desktop');
  }

  function bootWhenReady() {
    // Create placeholders ASAP (no network)
    ensureRow('mobile');
    ensureRow('desktop');
    ensurePsiExpandedPanel('mobile');
    ensurePsiExpandedPanel('desktop');


    // Initial quick attempts
    setTimeout(fetchBoth, 400);
    setTimeout(fetchBoth, 1200);
    setTimeout(fetchBoth, 2400);
    setTimeout(fetchBoth, 30000);
  }

  
  function extractParam(body, key) {
  if (!body) return '';
  var s = String(body);
  var re = new RegExp('(?:^|&)' + key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^&]*)', 'i');
  var m = s.match(re);
  if (!m || !m[1]) return '';
var raw = String(m[1]).replace(/\+/g, ' ');
try {
  return decodeURIComponent(raw);
} catch (e) {
  return raw; // best-effort fallback, don’t crash the whole script
}

}

function strategyFromBody(body) {
  var v = extractParam(body, 'strategy');
  v = String(v || '').toLowerCase();
  if (v === 'mobile' || v === 'desktop') return v;
  return '';
}

// Learn test_no from PSI AJAX and fetch CWV once PSI has completed
$(document).ajaxSuccess(function (e, xhr, settings) {
  if (!isWpsaTestsTab()) return;
  if (!settings || !settings.data) return;

  var dataStr = String(settings.data);

  // Only react to Module 5 PSI request
  if (dataStr.indexOf('action=wpsa_performance') === -1) return;

  var tn = parseInt(extractParam(dataStr, 'test_no'), 10);
  if (!isNaN(tn) && tn > 0) {
    if (currentTestNo !== tn) {
      currentTestNo = tn;
      lastFetchedTestNo.mobile = 0;
      lastFetchedTestNo.desktop = 0;
    }
  } else {
    return;
  }

  var st = strategyFromBody(dataStr);
  if (st) {
    setTimeout(function () { safeFetchCWV(st); }, 150);
  } else {
    setTimeout(fetchBoth, 150);
  }
});



$(function () {
  if (!isWpsaTestsTab()) return;

  bootWhenReady();

  $(document).on('click', '.wpsa-psi-metrics-toggle', function (ev) {
    ev.preventDefault();

    var st = String($(this).data('strategy') || '');
    if (st !== 'mobile' && st !== 'desktop') return;

    var wrap = ensurePsiExpandedPanel(st);
    if (!wrap) return;

    var isOpen = (wrap.style.display !== 'none');
    if (isOpen) {
      wrap.style.display = 'none';
      this.setAttribute('aria-expanded', 'false');
      this.textContent = 'Show field metrics';
      return;
    }

    // Prefer p75 from CWV response; otherwise show "--" everywhere (no lab fallback)
    if (lastP75[st]) {
      applyP75ToExpandedPanel(st, lastP75[st]);
    } else {
      setEmptyExpandedPanel(st);
    }

    wrap.style.display = 'block';
    this.setAttribute('aria-expanded', 'true');
   this.textContent = 'Hide field metrics';
  });

  // On tab switches (your existing handler)
  $(document).on('click', '.wpsa-tab5', function () {
    var st = $(this).data('strategy');
    if (st === 'mobile' || st === 'desktop') {
      safeFetchCWV(st);
    }
  });

  // After clicking "Load test #"
  $(document).on('click', '#wpsa-loadtest-btn', function () {
    var inp = document.getElementById('wpsa-loadtest-input');
    var n2 = inp && inp.value ? parseInt(inp.value, 10) : 0;
    if (!isNaN(n2) && n2 > 0) {
      currentTestNo = n2;
      // allow refetch for this test
      lastFetchedTestNo.mobile = 0;
      lastFetchedTestNo.desktop = 0;
    }

    setTimeout(fetchBoth, 500);
    setTimeout(fetchBoth, 1200);
  });

  // After running a new test (form submit)
  $(document).on('submit', '#speed-test-form', function () {
    setTimeout(fetchBoth, 800);
    setTimeout(fetchBoth, 1800);
  });
});


})(jQuery);
