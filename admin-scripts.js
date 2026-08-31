/**
 * Speed Analyzer – Admin Scripts
 * admin-scripts.js Version: v0.797
 */

 // Expose & log the running admin-scripts version
window.WPSA_ADMIN_JS_VER = 'v0.797';
try { console.info('admin-scripts.js', window.WPSA_ADMIN_JS_VER); } catch(e){}

jQuery(function($){
    var WPSA_SIDEBARS_TOP_KEY = 'wpsa:sidebarsOnTop:v1';

    function wpsa_applySidebarsTopPreference(enabled) {
      $('.wpsa-layout').toggleClass('wpsa-sidebars-on-top', !!enabled);
    }

    function wpsa_initSidebarsTopToggle() {
      var $toggle = $('#wpsa-sidebars-top-toggle');
      if (!$toggle.length) return;

      var enabled = false;
      try {
        enabled = window.localStorage.getItem(WPSA_SIDEBARS_TOP_KEY) === '1';
      } catch(e) {}

      $toggle.prop('checked', enabled);
      wpsa_applySidebarsTopPreference(enabled);

      $(document)
        .off('change.wpsaSidebarsTop', '#wpsa-sidebars-top-toggle')
        .on('change.wpsaSidebarsTop', '#wpsa-sidebars-top-toggle', function () {
          var isEnabled = $(this).is(':checked');
          wpsa_applySidebarsTopPreference(isEnabled);
          try {
            if (isEnabled) {
              window.localStorage.setItem(WPSA_SIDEBARS_TOP_KEY, '1');
            } else {
              window.localStorage.removeItem(WPSA_SIDEBARS_TOP_KEY);
            }
          } catch(e) {}
        });
    }

    wpsa_initSidebarsTopToggle();

    function wpsa_getQueryInt(name) {
  try {
    var p = new URLSearchParams(window.location.search || '');
    if (!p.has(name)) return 0;
    var n = parseInt(p.get(name) || '0', 10) || 0;
    return (n > 0) ? n : 0;
  } catch(e) {
    return 0;
  }
}

function wpsa_applyQueryLoadTestNoOverride() {
  var n = wpsa_getQueryInt('load_test_no');
  if (!n) return;

  var $in  = jQuery('#wpsa-loadtest-input');
  var $btn = jQuery('#wpsa-loadtest-btn');
  var $msg = jQuery('#wpsa-loadtest-msg');

  if ($msg.length) $msg.text(''); // never show "Loaded." on arrival

  if ($in.length) {
    $in.val(n).trigger('input').trigger('change');
  }

  // Recompute enable/disable
  try { wpsa_updateLoadBtnState(); } catch(e){}

  // Attention effect (optional)
  if ($btn.length) {
    $btn.addClass('wpsa-blink');
    setTimeout(function(){ $btn.removeClass('wpsa-blink'); }, 2500);
  }
}

function wpsaRenderResponseHeaders(headersObj) {
  var $wrap = $('#wpsa-m1-respheaders-wrap');
  var $ul   = $('#wpsa-m1-respheaders');

  if (!$wrap.length || !$ul.length) {
    return;
  }

  $ul.empty();

  if (!headersObj || typeof headersObj !== 'object') {
    $wrap.hide();
    return;
  }

  var keys = Object.keys(headersObj);
  if (!keys.length) {
    $wrap.hide();
    return;
  }

  // Prefer a KeyCDN-ish order, then alphabetical for the rest
  var preferred = [
    'status','date','content-type',
    'cache-control','expires','last-modified',
    'cf-cache-status','age','cf-ray',
    'server','x-powered-by','x-turbo-charged-by',
    'content-security-policy','permissions-policy',
    'link','vary','alt-svc',
    'platform','panel'
  ];

  var rank = {};
  for (var i = 0; i < preferred.length; i++) {
    rank[preferred[i]] = i;
  }

  keys.sort(function(a, b) {
    var ak = String(a).toLowerCase();
    var bk = String(b).toLowerCase();
    var ra = (rank[ak] !== undefined) ? rank[ak] : 999;
    var rb = (rank[bk] !== undefined) ? rank[bk] : 999;
    if (ra !== rb) return ra - rb;
    return ak.localeCompare(bk);
  });

  keys.forEach(function(k) {
    var v = headersObj[k];

    if (Array.isArray(v)) {
      v = v.join(', ');
    } else {
      v = String(v);
    }

    // Keep it safe: no HTML injection
    $('<li />').text(String(k) + ': ' + v).appendTo($ul);
  });

  $wrap.show();
}


    
    
    // Prefer ?test_url=... over any restored/last-used URL (edit.php "Test the page" / "Re-test")
(function wpsaPreferQueryTestUrl() {
  try {
    var qs = window.location.search || '';
    if (!qs) return;

    var p = new URLSearchParams(qs);
    if (!p.has('test_url')) return;

    var u = p.get('test_url') || '';
    try { u = decodeURIComponent(u); } catch(e) {}
    u = (u || '').trim();
    if (!u) return;

    // Your actual input in wp-speed-analyzer.php is: class="wpsa-input-url" name="test_url"
    var $in = $('input.wpsa-input-url[name="test_url"]').first();
    if (!$in.length) return;

    // Force it in, and fire change so any listeners pick it up
    $in.val(u).trigger('input').trigger('change');

    // If your script also mirrors it into the UI label, keep it consistent
    try { $('#wpsa-tested-url').text(u); } catch(e) {}
  } catch(e) {}
})();


// Prefill "Load test #" from URL (?load_test_no=123) and visually highlight the Load button.
// IMPORTANT: does NOT auto-load; it only guides the user to click "Load".
(function wpsaPreferQueryLoadTestNo() {
  try {
    var p = new URLSearchParams(window.location.search || '');
    if (!p.has('load_test_no')) return;

    var n = parseInt(p.get('load_test_no') || '0', 10) || 0;
    if (n < 1) return;

    var $in  = jQuery('#wpsa-loadtest-input');
    var $btn = jQuery('#wpsa-loadtest-btn');
    var $msg = jQuery('#wpsa-loadtest-msg');

    if ($msg.length) $msg.text(''); // don’t show stale “Loaded.” from a previous state

    // Prefill input
    if ($in.length) $in.val(n).trigger('input').trigger('change');

    // If the Tests markup isn’t present yet, restore the last saved DOM shell
    // so the Load painter has elements to write into.
    try {
      if (typeof wpsa_hasResultsOnScreen === 'function' && !wpsa_hasResultsOnScreen()) {
        var raw = sessionStorage.getItem('wpsa:testState:v1');
        if (raw) {
          var st = JSON.parse(raw) || {};
          if (st.html && jQuery('#test-results').length) {
            jQuery('#test-results').html(st.html);

            // Re-hydrate best-effort runtime state from the restored DOM
            try { wpsa_hydrateStateFromDOM(); } catch(e){}
            try { updateSummaryCards(); } catch(e){}
            try { jQuery(document).trigger('wpsa_module7_done'); } catch(e){}
          }
        }
      }
    } catch(e){}

    // Ensure blink CSS exists once
    if (!document.getElementById('wpsa-load-blink-css')) {
      var css = '' +
        '#wpsa-loadtest-btn.wpsa-blink{' +
          'animation:wpsaBlink 0.9s ease-in-out 6;' +
        '}' +
        '@keyframes wpsaBlink{' +
          '0%,100%{filter:none; box-shadow:none;}' +
          '50%{filter:brightness(1.05); box-shadow:0 0 0 3px rgba(255,107,53,.35);}' +
        '}';
      var stEl = document.createElement('style');
      stEl.id = 'wpsa-load-blink-css';
      stEl.appendChild(document.createTextNode(css));
      document.head.appendChild(stEl);
    }

    // Blink the Load button (after we tried restoring the shell)
    if ($btn.length) {
      $btn.addClass('wpsa-blink');
      setTimeout(function(){ $btn.removeClass('wpsa-blink'); }, 6500);
    }

    // Recompute enabled/disabled state (your forced override stays in wpsa_updateLoadBtnState)
    try { wpsa_updateLoadBtnState(); } catch(e){}

  } catch (e) {}
})();



        /* ===== Run button 60s cool-down ===== */
    const WPSA_COOLDOWN_KEY = 'wpsa:runCooldownUntil:v1';
    
    
    // Shared thresholds for Modules 1–7 (keep values in one place)
    // REMEMBER TO EDIT compare.php and report-scripts.js as well!
    const WPSA_THRESHOLDS = {
      ttfb: {
        // summary cards (Module 6)
        good: 300,
        ok:   500,
        // Module 1 status box currently uses 300 / 700 → keep that too
        m1_ok: 700
      },
      module2: {
        requests: { good: 50, ok: 70 },
        sizeKB:   { good: 2048, ok: 3584 }
      },
      onload: {
        js:  { good: 11, ok: 16 },  // 10/15 old values
        css: { good: 15, ok: 20 }   // 10/17 old
      },
      psi: {
        lcp:   { good: 2.5, ok: 4.0 },
        fcp:   { good: 1.8, ok: 3.0 },
        cls:   { good: 0.10, ok: 0.25 },
        inpMs: { good: 200, ok: 500 }
      },
      summary: {
        autoloadKB: { good: 800, ok: 1024 },
        plugins:    { good: 40, ok: 55 }
      },
      // used by the rec-builder at the end of updateSummaryCards()
      rec: {
        req_bad:    70,
        size_bad:   3584,
        lcp_bad:    4.0,
        fcp_bad:    3.0,
        cls_bad:    0.25,
        inp_bad:    500,
        onjs_bad:   17,
        oncss_bad:  21,
        autoload_bad: 801
      }
    };
      
      // Central color palette (reduce hex duplication)
    const WPSA_COLORS = {
      green: '#c6f7c3',
      amber: '#fef6b3',
      red:   '#ffdddd',
      na:    '#eeeeee',
      hot:   '#FDFFE0',   // Modules 2/5 “running” tint
      hot7:  '#E8C390',   // Module 7 pre-tint
      m1Amber: '#fff4c3', // Module-1 status amber (kept for parity)
      perfRing: { bad: '#d32f2f', warn: '#f9a825', good: '#388e3c' },
      perfBg:   { bad: '#ffebee', warn: '#fff7ed', good: '#e8f5e9' }
    };
    
    
    function wpsa_clearCooldown() {
      try { sessionStorage.removeItem(WPSA_COOLDOWN_KEY); } catch(e){}
      var $btn = $('.wpsa-button-run');
      if ($btn.length) {
        var txt = $btn.data('origText') || 'Run Speed Audit';
        $btn.prop('disabled', false)
            .removeClass('button-disabled')
            .removeAttr('aria-disabled')
            .text(txt);
      }
      if (window._wpsa_cdTimer) {
        clearInterval(window._wpsa_cdTimer);
        window._wpsa_cdTimer = null;
      }
    }
    
      // --- helper: confirm that all modules have finished rendering ---
        function wpsa_allTestsFinished() {
          return !(
            $('#running-test:visible, #module2-running:visible, #module5-running:visible, #module6-running:visible, #module7-running:visible').length
          );
        }
    
    function wpsa_tickCooldown(untilTs) {
      var $btn = $('.wpsa-button-run');
      if (!$btn.length) return;
    
      function update() {
      var remaining = Math.ceil((untilTs - Date.now())/1000);
    
      // when countdown finished AND all modules done → re-enable button
      if (remaining <= 0 && wpsa_allTestsFinished()) {
        wpsa_clearCooldown();
        return;
      }
    
      // otherwise keep disabled and keep showing the countdown
      if (!$btn.data('origText')) $btn.data('origText', $btn.text());
      $btn.prop('disabled', true)
          .addClass('button-disabled')
          .attr('aria-disabled','true')
          .text($btn.data('origText') + ' (' + Math.max(0, remaining) + 's)');
    }

      if (window._wpsa_cdTimer) clearInterval(window._wpsa_cdTimer);
      update();
      window._wpsa_cdTimer = setInterval(update, 1000);
    }
    
    function wpsa_beginCooldown(seconds) {
      var untilTs = Date.now() + seconds*1000;
      try { sessionStorage.setItem(WPSA_COOLDOWN_KEY, String(untilTs)); } catch(e){}
      wpsa_tickCooldown(untilTs);
    }
    
    function wpsa_initCooldownFromStorage() {
      var until = 0;
      try { until = parseInt(sessionStorage.getItem(WPSA_COOLDOWN_KEY) || '0', 10); } catch(e){}
      if (until && until > Date.now()) {
        wpsa_tickCooldown(until);
      } else {
        wpsa_clearCooldown();
      }
    }
    
    // shared helper: normalize any "--" / "—" / "" to "N/A" in Module 5 cards
    window.wpsa_normalizePsiDashes = function($scope){
      $scope.find(
        '.wpsa-card-lcp .value, .wpsa-card-fcp .value,' +
        '.wpsa-card-cls .value, .wpsa-card-inp .value'
      ).each(function(){
        var t = (jQuery(this).text() || '').trim();
        if (t === '--' || t === '—' || t === '') jQuery(this).text('N/A');
      });
    };
    
        // ===== Live refresh for the "Daily PDF limit remaining" label =====
    function wpsa_refreshPdfCounter() {
      try {
        // Needs the nonce already localized to report-scripts: wpsaPdf.nonce
        if (!window.wpsaPdf || !wpsaPdf.nonce) return;
        jQuery.post(ajaxurl, {
          action: 'wpsa_pdf_quota',
          nonce:  wpsaPdf.nonce
        })
        .done(function (r) {
          if (!(r && r.success && r.data)) return;

          var rem = parseInt(r.data.remaining, 10) || 0;
          var lim = parseInt(r.data.limit, 10) || 0;

          // Update globals so other code can rely on the fresh numbers, too
          wpsaPdf.remaining = rem;
          wpsaPdf.limit     = lim;

          // Paint all visible PDF quota labels
          ['#wpsa-pdf-counter', '#wpsa-pdf-counter-tests', '#wpsa-pdf-remaining'].forEach(function (sel) {
            var $wrap = jQuery(sel);
            if (!$wrap.length) return;

            var $strong = $wrap.find('strong');
            if (!$strong.length) {
              $strong = $wrap;
            }

            $strong.text(rem + '/' + lim);
            if (rem <= 3) {
              $strong.css('color', '#d32f2f');
            } else {
              $strong.css('color', '');
            }
          });

          // After numbers are updated, toggle the Tests PDF button
          wpsa_updatePdfButtons();
        });
      } catch (e) {}
    }

    // Keep Tests "Generate PDF report" button in sync with quota + Module 7 readiness
    function wpsa_updatePdfButtons() {
      var $btnTests = jQuery('#report-button-tests');
      if (!$btnTests.length) return;

      var hasQuota   = !!(window.wpsaPdf && typeof wpsaPdf.remaining !== 'undefined' && wpsaPdf.remaining > 0);
      var mod7Ready  = jQuery('#module7-container').data('rendered') === true;

      var enable = hasQuota && mod7Ready;

      if (enable) {
        $btnTests
          .prop('disabled', false)
          .removeClass('button-disabled')
          .removeAttr('aria-disabled');
      } else {
        $btnTests
          .prop('disabled', true)
          .addClass('button-disabled')
          .attr('aria-disabled', 'true');
      }
    }

    
    // Global state for Module 5
    window._wpsa_perf = {
     
    mobile: null,
    desktop: null,
    logged: false,
    errors: { mobile: false, desktop: false }
  };
   window._wpsa_loaded_from_log = false;
   window._wpsa_cdStarted       = false;   // start cooldown only once we know a run actually progressed
   window._wpsa_is_scheduled    = false;   // flag: current test is a scheduled run (“S”)

  
// === Only run AJAX when the page load came from a submitted test ===
var _wpsa_shouldRun = (function () {
  try {
    var p = new URLSearchParams(window.location.search || '');

    // If we are arriving via editor link with "load_test_no", NEVER auto-run anything.
    // This route is "prefill Load" only.
    if (p.has('load_test_no')) {
      try { sessionStorage.removeItem('WPSA_RUN_AFTER_RELOAD'); } catch(e){}
      return false;
    }

    // If we are arriving with a prefilled URL (?test_url=...), also do not auto-run.
    // User explicitly clicks "Run Speed Audit".
    if (p.has('test_url')) {
      // still allow explicit reload-run flag to win (form submit)
      // so we only short-circuit if the submit-flag is NOT present.
      var f = '';
      try { f = sessionStorage.getItem('WPSA_RUN_AFTER_RELOAD') || ''; } catch(e){}
      if (f !== '1') return false;
    }

    if (sessionStorage.getItem('WPSA_RUN_AFTER_RELOAD') === '1') {
      sessionStorage.removeItem('WPSA_RUN_AFTER_RELOAD');
      return true;
    }
  } catch (e) {}

  if ($('#test-results').data('run') == 1) return true;

  // Only treat run-meta as "live" if we didn't come with load_test_no/test_url.
  // (run-meta can exist from restored DOM and would incorrectly trigger a run)
  try {
    var p2 = new URLSearchParams(window.location.search || '');
    if (p2.has('load_test_no') || p2.has('test_url')) return false;
  } catch(e) {}

  return !!document.getElementById('wpsa-run-meta');
})();

    
    function wpsa_getQueryTestUrl() {
  try {
    var p = new URLSearchParams(window.location.search || '');
    if (!p.has('test_url')) return '';
    var u = p.get('test_url') || '';
    try { u = decodeURIComponent(u); } catch(e) {}
    u = (u || '').trim();
    return u;
  } catch(e) {
    return '';
  }
}


function wpsa_applySavedHeader() {
  try {
    var raw = sessionStorage.getItem('wpsa:testState:v1');
    if (!raw) return;
    var s = JSON.parse(raw) || {};

    // If edit.php opened us with ?test_url=..., prefer it over saved state everywhere
var qUrl = '';
try {
  var p = new URLSearchParams(window.location.search || '');
  if (p.has('test_url')) {
    qUrl = (p.get('test_url') || '');
    try { qUrl = decodeURIComponent(qUrl); } catch(e) {}
    qUrl = (qUrl || '').trim();
  }
} catch(e) {}

var effectiveUrl = qUrl || (s.testedUrl || '');

if (effectiveUrl) {
  $('input[name="test_url"]').val(effectiveUrl);
}


    if (!$('#wpsa-tested-url').length) {
      $('<div id="wpsa-tested-url" class="wpsa-tested-url" />')
        .insertAfter('input[name="test_url"]');
    }
    
        // 🔹 restore in-memory flags first
       if (!qUrl) {
          if (typeof s.testNo !== 'undefined') {
            window._wpsa_testNo = s.testNo || 0;
          }
          window._wpsa_is_scheduled = !!s.isScheduled;
        } else {
          window._wpsa_testNo = 0;
          window._wpsa_is_scheduled = false;
        }

    
        // 🔹 use the shared helper so " S" is applied consistently
        if (typeof wpsa_renderTestedUrlLabel === 'function') {
          wpsa_renderTestedUrlLabel();
        } else {
          // legacy fallback in case helper is not available for some reason
         var _url = (effectiveUrl || '').trim();
          var _no  = parseInt(s.testNo, 10) || 0;
          if (_url) {
            var label = (_no ? ('Test #' + _no + ', ') : '') + 'tested URL: ' +
                        '<a href="' + _url + '" target="_blank" rel="noopener noreferrer">' + _url + '</a>';
            $('#wpsa-tested-url').html(label).show();
          }
        }
    
        // Fallback for very old saved states that only had plain text
        if (!s.testedUrl && s.testedUrlLine) {
          $('#wpsa-tested-url').text(s.testedUrlLine).show();
        }
    
        if (s.origin === 'load') {
          if ($('#wpsa-loadtest-input').length) {
            $('#wpsa-loadtest-input').val(s.loadInput || s.testNo || '');
          }
        }
      } catch (e) {}
    }

  
      // Will hold Module 2 results
      window._wpsa_mod2_data = { mobile: null, desktop: null };
      // Current summary strategy
      var _wpsa_summary_strat = 'mobile';

    // Helper: colorize stat cards (Module 2)
    var applyColor = function($c){
    var cards = $c.find('.wpsa-stat-card');
    var TH = WPSA_THRESHOLDS;

    // Existing two cards in 1.16.1
    var req = parseInt(cards.eq(0).find('.value').text(),10) || 0;
    var sz  = parseFloat(cards.eq(1).find('.value').eq(0).text()) || 0;

    var rc = req <= TH.module2.requests.good ? WPSA_COLORS.green
          : req <= TH.module2.requests.ok ? WPSA_COLORS.amber
          :                                 WPSA_COLORS.red;

    var sc = sz  <= TH.module2.sizeKB.good   ? WPSA_COLORS.green
          : sz  <= TH.module2.sizeKB.ok   ? WPSA_COLORS.amber
          :                                 WPSA_COLORS.red;

    cards.eq(0).css('background', rc);
    cards.eq(1).css('background', sc);

    // checkmarks for the first two cards
    if (rc === WPSA_COLORS.green && !cards.eq(0).find('.icon').length) {
      cards.eq(0).find('.value').append(' <span class="icon">✅</span>');
    }
    if (sc === WPSA_COLORS.green && !cards.eq(1).find('.icon').length) {
      cards.eq(1).find('.value').append(' <span class="icon">✅</span>');
    }

    // from 1.16.2: Onload JS / CSS cards (by class, not index)
    var $jsCard  = $c.find('.wpsa-card-onload-js');
    var $cssCard = $c.find('.wpsa-card-onload-css');

    if ($jsCard.length) {
      var onJs = parseInt($jsCard.find('.value').text(), 10);
      var jc;
      if (!Number.isFinite(onJs)) {
        jc = WPSA_COLORS.na;
        $jsCard.addClass('is-na');
      } else {
        jc = onJs <= TH.onload.js.good ? WPSA_COLORS.green
     : onJs <= TH.onload.js.ok ? WPSA_COLORS.amber
     :                           WPSA_COLORS.red;
        if (jc === WPSA_COLORS.green && !$jsCard.find('.icon').length) {
          $jsCard.find('.value').append(' <span class="icon">✅</span>');
        }
      }
      $jsCard.css('background', jc);
    }

    if ($cssCard.length) {
      var onCss = parseInt($cssCard.find('.value').text(), 10);
      var cc;
      if (!Number.isFinite(onCss)) {
        cc = WPSA_COLORS.na;
        $cssCard.addClass('is-na');
      } else {
        cc = onCss <= TH.onload.css.good ? WPSA_COLORS.green
     : onCss <= TH.onload.css.ok ? WPSA_COLORS.amber
     :                             WPSA_COLORS.red;
        if (cc === WPSA_COLORS.green && !$cssCard.find('.icon').length) {
          $cssCard.find('.value').append(' <span class="icon">✅</span>');
        }
      }
      $cssCard.css('background', cc);
    }
  };


    // Helper: render performance, diagnostics (left) & insights (right) — Module 5
    var renderPerf = function (data, prefix) {
      if (!data) data = {};
    
         // ------- list builder (shared styling) -------
      function buildList(arr, $ul) {
        var items = [];
        (Array.isArray(arr) ? arr : []).forEach(function (item) {
          var v = (item && item.value ? String(item.value) : '');
          var vSp = v.replace(/\u00A0/g, ' '); // NBSP → space
    
          // ignore only true zeros like "0 ms", "0 KiB", "0 resources"
          if (/\b0\s*(?:ms|kb|kib|resources)\b/i.test(vSp)) return;
    
          var m = vSp.match(/[\d,.]+/);
          var num = m ? parseFloat(m[0].replace(/,/g, '')) : 0;
    
          items.push({
            title:    item.title || '',
            value:    vSp,
            severity: String(item.severity || 'low').toLowerCase(),
            num:      num
          });
        });
    
        // PSI-ish sort: high → moderate → low → info, then by numeric desc
        var weight = { high: 3, moderate: 2, medium: 2, low: 1, info: 0 };
        items.sort(function (a, b) {
          var aw = weight[a.severity] || 0, bw = weight[b.severity] || 0;
          if (aw !== bw) return bw - aw;
          return b.num - a.num;
        });
    
        items = items.slice(0, 5); // ← top 5 only
    
        $ul.empty();
        if (!items.length) {
          $ul.append(
            '<li class="diag-na">' +
              '<span class="wpsa-psi-badge wpsa-psi-badge--info" aria-hidden="true"></span>' +
              '<span class="title">No items were returned for this run.</span>' +
            '</li>'
          );
          return;
        }
    
        items.forEach(function (it) {
          var sev = String(it.severity || 'low').toLowerCase();
          var badge =
            sev === 'high' ? 'wpsa-psi-badge--fail' :
            (sev === 'moderate' || sev === 'medium') ? 'wpsa-psi-badge--average' :
            'wpsa-psi-badge--pass';
    
          var $li = $('<li class="diag-item sev-' + sev + '"/>');
          $('<span class="wpsa-psi-badge ' + badge + '" aria-hidden="true"></span>').appendTo($li);
    
          // Split any Worker-enriched title like
          // "Reduce unused JavaScript — Est savings of 157 KiB"
          var rawTitle    = it.title || '';
          var mainTitle   = rawTitle;
          var suffixMetric = '';
    
          var dashParts = rawTitle.split(' — ');
          if (dashParts.length > 1) {
            mainTitle    = dashParts[0];
            suffixMetric = dashParts.slice(1).join(' — ').trim();
          }
    
          // Prefer backend value; fall back to suffix from the title if needed
          var metric = (it.value || '').trim();
          if (!metric && suffixMetric) {
            metric = suffixMetric;
          }
    
          $('<span class="title"/>').text(mainTitle).appendTo($li);
          if (metric) {
            $('<span class="value top5"/>').text(' — ' + metric).appendTo($li);
          }
    
          $ul.append($li);
        });
      }

    
      // LEFT column: diagnostics/opportunities
      buildList(data.diagnostics, $('#diag-' + prefix));
    
      // RIGHT column: insights
      buildList(data.insights, $('#ins-' + prefix));
      
            // --- Module 5: SVG gauge helpers (match PDF gauge) ---
      function wpsaBuildPerfGaugeSvg(score, ringColor) {
        var s = (typeof score === 'number') ? score : parseFloat(score);
        if (!isFinite(s) || s < 0 || s > 100) {
          s = null;
        }

        var r = 58;
        var c = 2 * Math.PI * r;
        var pct  = (s === null) ? 0 : (s / 100);
        var dash = (c * pct).toFixed(1);
        var gap  = (c - dash).toFixed(1);

        var color = ringColor || '#999';

        return '' +
          '<svg class="wpsa-gauge-svg" viewBox="0 0 120 120" role="img" aria-label="Performance score">' +
            '<circle cx="60" cy="60" r="' + r + '" fill="none" stroke="#eee" stroke-width="5"></circle>' +
            '<circle cx="60" cy="60" r="' + r + '" fill="none" stroke="' + color + '" stroke-width="5" ' +
                    'stroke-linecap="round" transform="rotate(-90 60 60)" ' +
                    'stroke-dasharray="' + dash + ' ' + gap + '"></circle>' +
          '</svg>';
      }

      function wpsaApplyPerfGauge($circ, score, ringColor) {
        if (!$circ || !$circ.length) return;
        $circ.find('svg.wpsa-gauge-svg').remove(); // clear old gauge
        var svg = wpsaBuildPerfGaugeSvg(score, ringColor);
        if (svg) {
          $circ.prepend(svg); // SVG behind the text span
        }
      }

      // ------- Performance circle -------
      var circ = $('#perf-circle-' + prefix).removeClass('na'),
          sc   = data.score;

      var scNum = parseFloat(sc);

      if (sc === 'N/A' || !isFinite(scNum)) {
        // N/A – reset styles, remove gauge, show N/A text
        circ.addClass('na')
            .css({ borderColor: '', background: '' })
            .find('.perf-text').css('color', '').text('N/A');

        circ.find('svg.wpsa-gauge-svg').remove();
      } else {
        var col, bg;
        if      (scNum < 50) { col = WPSA_COLORS.perfRing.bad;  bg = WPSA_COLORS.perfBg.bad;  }
        else if (scNum < 90) { col = WPSA_COLORS.perfRing.warn; bg = WPSA_COLORS.perfBg.warn; }
        else                 { col = WPSA_COLORS.perfRing.good; bg = WPSA_COLORS.perfBg.good; }

        // Background still uses your perfBg colours; border no longer used
        circ.css({ borderColor: 'transparent', background: bg })
            .find('.perf-text').css('color', col).text(scNum);

        // NEW: draw SVG gauge ring like in the PDF
        wpsaApplyPerfGauge(circ, scNum, col);
      }

    
      // ------- LCP/FCP cards -------
      var $lcpCard = $('#perf-' + prefix + ' .wpsa-card-lcp');
      var $fcpCard = $('#perf-' + prefix + ' .wpsa-card-fcp');
    
      var lcpTxt = (data.lcp || '').toString().trim();
      var fcpTxt = (data.fcp || '').toString().trim();
      var lcpNA  = !lcpTxt || /^n\/a$/i.test(lcpTxt);
      var fcpNA  = !fcpTxt || /^n\/a$/i.test(fcpTxt);
    
      var lcpN = parseFloat(lcpTxt) || 0;
      var fcpN = parseFloat(fcpTxt) || 0;
    
      var TH = WPSA_THRESHOLDS;
      var lcpBg = lcpNA ? WPSA_COLORS.na
                   : (lcpN <= TH.psi.lcp.good ? WPSA_COLORS.green
                     : (lcpN <= TH.psi.lcp.ok ? WPSA_COLORS.amber : WPSA_COLORS.red));
 var fcpBg = fcpNA ? WPSA_COLORS.na
                   : (fcpN <= TH.psi.fcp.good ? WPSA_COLORS.green
                     : (fcpN <= TH.psi.fcp.ok ? WPSA_COLORS.amber : WPSA_COLORS.red));

    
      $lcpCard.removeClass('is-na').find('.icon').remove();
      $fcpCard.removeClass('is-na').find('.icon').remove();
    
      $lcpCard.css('background', lcpBg).find('.value').text(lcpNA ? 'N/A' : lcpTxt);
      $fcpCard.css('background', fcpBg).find('.value').text(fcpNA ? 'N/A' : fcpTxt);
    
      if (lcpNA) { $lcpCard.addClass('is-na'); }
      if (fcpNA) { $fcpCard.addClass('is-na'); }
    
      if (!lcpNA && lcpBg === WPSA_COLORS.green) { $lcpCard.find('.value').append(' <span class="icon">✅</span>'); }
      if (!fcpNA && fcpBg === WPSA_COLORS.green) { $fcpCard.find('.value').append(' <span class="icon">✅</span>'); }

    // ------- CLS/INP cards -> thresholds (CLS ≤0.1 / ≤0.25, INP ≤200ms / ≤500ms) -------
        var $clsCard = $('#perf-' + prefix + ' .wpsa-card-cls');
        var $inpCard = $('#perf-' + prefix + ' .wpsa-card-inp');
        
        
        
       if ($clsCard.length) {
         var clsTxt = (data.cls || '').toString().trim();
         var clsNA = !clsTxt || /^n\/a$/i.test(clsTxt); 
         $clsCard.removeClass('is-na').find('.icon').remove();
        
          if (clsNA) {
            $clsCard.addClass('is-na').css('background', WPSA_COLORS.na)
                    .find('.value').text('N/A');
          } else {
            var clsNum = parseFloat(clsTxt.replace(',', '.')); // tolerate "0,09"
            var TH = WPSA_THRESHOLDS;
            var clsBg  = !isFinite(clsNum) ? WPSA_COLORS.na
                       : (clsNum <= TH.psi.cls.good ? WPSA_COLORS.green
                         : (clsNum <= TH.psi.cls.ok ? WPSA_COLORS.amber : WPSA_COLORS.red));

            $clsCard.css('background', clsBg).find('.value').text(clsTxt);
            if (isFinite(clsNum) && clsNum <= 0.10) {
              $clsCard.find('.value').append(' <span class="icon">✅</span>');
            }
          }
        }

        
            if ($inpCard.length) {
      var inpTxt = (data.tbt || '').toString().trim();
      var inpNA  = !inpTxt || /^n\/a$/i.test(inpTxt);
      $inpCard.removeClass('is-na').find('.icon').remove();

      // No lab/field source badge: TBT is lab-only, so the distinction the old
      // INP badge drew no longer exists. The field CWV block shows its own scope.
      $inpCard.find('.header .inp-badge').remove();

      if (inpNA) {
        $inpCard.addClass('is-na').css('background', WPSA_COLORS.na)
                .find('.value').text('N/A');
      } else {
        // parse "220 ms" or "0.22 s" → ms
        var m = inpTxt.match(/([\d.,]+)\s*(ms|s)?/i);
        var inpMs = NaN;
        if (m) {
          var val  = parseFloat(m[1].replace(',', '.'));
          var unit = (m[2] || 'ms').toLowerCase();
          inpMs = (unit === 's') ? val * 1000 : val;
        }

        var TH = WPSA_THRESHOLDS;
        var inpBg = !isFinite(inpMs) ? WPSA_COLORS.na
                   : (inpMs <= TH.psi.inpMs.good ? WPSA_COLORS.green
                     : (inpMs <= TH.psi.inpMs.ok ? WPSA_COLORS.amber : WPSA_COLORS.red));

        $inpCard.css('background', inpBg).find('.value').text(inpTxt);
        if (isFinite(inpMs) && inpMs <= 200) {
          $inpCard.find('.value').append(' <span class="icon">✅</span>');
        }
      }
    }

         // ✅ NEW: sweep this pane and convert any "--" to "N/A"
    window.wpsa_normalizePsiDashes($('#perf-' + prefix));
    }; // end renderPerf

  // ------- helpers: screenshots from diagnostics log for Load test # -------

    function wpsa_pickScreenshotFromDiag(side) {
      if (!side) return '';

      // If it’s already a string (data URL or http URL)
      if (typeof side === 'string') {
        return side.trim();
      }

      var cand = null;

      // Most likely shapes — adjust if your diag structure differs
      if (side.screenshot) {
        cand = side.screenshot;
      } else if (side.finalScreenshot && side.finalScreenshot.data) {
        cand = side.finalScreenshot.data;
      } else if (side.psi && side.psi.screenshot) {
        cand = side.psi.screenshot;
      } else if (side.details && side.details.screenshot) {
        cand = side.details.screenshot;
      }

      cand = (cand || '').toString().trim();
      if (!cand) return '';

      // Only accept obvious image data / URLs
      if (!/^data:image\//i.test(cand) && !/^https?:\/\//i.test(cand)) {
        return '';
      }
      return cand;
    }

    function wpsa_applyScreenshotsFromDiag(diag) {
      diag = diag || {};
      var mobileShot  = wpsa_pickScreenshotFromDiag(diag.mobile);
      var desktopShot = wpsa_pickScreenshotFromDiag(diag.desktop);

      // Mobile
      var $mobImg = jQuery('#wpsa-psi-screenshot-img-mobile');
      var $mobBox = jQuery('.wpsa-psi-screenshot-mobile');
      if ($mobImg.length) {
        if (mobileShot) {
          $mobImg.attr('src', mobileShot).addClass('has-screenshot');
          if ($mobBox.length) { $mobBox.addClass('has-screenshot'); }
        } else {
          $mobImg.removeAttr('src').removeClass('has-screenshot');
          if ($mobBox.length) { $mobBox.removeClass('has-screenshot'); }
        }
      }

      // Desktop
      var $deskImg = jQuery('#wpsa-psi-screenshot-img-desktop');
      var $deskBox = jQuery('.wpsa-psi-screenshot-desktop');
      if ($deskImg.length) {
        if (desktopShot) {
          $deskImg.attr('src', desktopShot).addClass('has-screenshot');
          if ($deskBox.length) { $deskBox.addClass('has-screenshot'); }
        } else {
          $deskImg.removeAttr('src').removeClass('has-screenshot');
          if ($deskBox.length) { $deskBox.removeClass('has-screenshot'); }
        }
      }
    }

    // --- helpers to manage the Module 5 warning banner and pick a valid strategy ---
    function _wpsa_hasValidPerf(d){
      // valid if numeric score 1..100
      if (!d) return false;
      var n = parseFloat(d.score);
      return !isNaN(n) && n > 0 && n <= 100;
    }
    
    function _wpsa_updateMod5Notice(){
      var anyOk = _wpsa_hasValidPerf(window._wpsa_perf.mobile) || _wpsa_hasValidPerf(window._wpsa_perf.desktop);
      var $wrap = $('#wpsa-module5');
      var $box  = $wrap.find('.wpsa-notice-failed');
    
      if (anyOk) {
        // hide if present
        if ($box.length) $box.hide();
      } else {
        // show/create if neither side returned metrics
        if ($box.length) {
          $box.show();
        } else {
          $wrap.prepend(
            '<div class="notice notice-warning wpsa-notice-failed"><p>' +
            'Test didn’t finish properly. Performance metrics were not returned by PageSpeed Insights. Please run it again.' +
            '</p></div>'
          );
        }
      }
    }

    /* ---------- Persist Tests tab state while the browser tab is open ---------- */
    const WPSA_STATE_KEY = 'wpsa:testState:v1';
    
    function wpsa_saveTestState() {
      var $results = $('#test-results');
      if (!$results.length) return;
      var html = $results.html();
      if (!html || !html.trim()) return;

      // Preserve previous origin if present (prevents “load” → “run” flip after reload)
      var prevOrigin = null;
      try {
        var existingRaw = sessionStorage.getItem(WPSA_STATE_KEY);
        if (existingRaw) {
          var existing = JSON.parse(existingRaw);
          if (existing && existing.origin) {
            prevOrigin = existing.origin;    // e.g. "run" or "load"
          }
        }
      } catch (e) {}

      // Decide origin:
      //  - if we just loaded from log → always "load"
      //  - else keep previous if any
      //  - else default to "run"
      var origin;
      if (window._wpsa_loaded_from_log) {
        origin = 'load';
      } else if (prevOrigin) {
        origin = prevOrigin;
      } else {
        origin = 'run';
      }

      var state = {
        html: html,
        testedUrl: $('input[name="test_url"]').val() || '',
        testedUrlLine: ($('#wpsa-tested-url').text() || '').trim(),
        testNo: window._wpsa_testNo || 0,
        origin: origin,
        loadInput: ($('#wpsa-loadtest-input').val() || '').toString(),
        isScheduled: !!window._wpsa_is_scheduled,   // 🔹 persist “S” flag
        ts: Date.now()
      };

      try {
        sessionStorage.setItem(WPSA_STATE_KEY, JSON.stringify(state));
      } catch (e) {}
    }


    
   function wpsa_restoreTestStateIfEmpty() {
  var $results = $('#test-results');
  if (!$results.length) return;
  if ($results.html() && $results.html().trim()) return; // already has content

  // If ?test_url=... exists (edit.php “Test the page” / “Re-test”), DO NOT restore old results DOM.
  // Just prefill the URL and exit so we don't snap back to the last loaded test (e.g. Donate).
  var qUrl = '';
  try {
    var p = new URLSearchParams(window.location.search || '');
    if (p.has('test_url')) {
      qUrl = (p.get('test_url') || '');
      try { qUrl = decodeURIComponent(qUrl); } catch(e) {}
      qUrl = (qUrl || '').trim();
    }
  } catch(e) {}

  if (qUrl) {
    var $in = $('input.wpsa-input-url[name="test_url"]').first();
    if ($in.length) {
      $in.val(qUrl).trigger('input').trigger('change');
    } else {
      $('input[name="test_url"]').val(qUrl).trigger('input').trigger('change');
    }

    // Ensure label exists + render from the current input (no saved testNo/scheduled flags)
    try { wpsa_ensureTestedUrlEl(); } catch(e) {}
    window._wpsa_testNo = 0;
    window._wpsa_is_scheduled = false;
    try { wpsa_renderTestedUrlLabel(); } catch(e) {}

    return;
  }

  var raw = null;
  try { raw = sessionStorage.getItem(WPSA_STATE_KEY); } catch(e){}
  if (!raw) return;

  try {
    var state = JSON.parse(raw);
    if (!state || !state.html) return;

    // Re-inject previous results DOM
    $results.html(state.html);
        // Rebuild in-memory state from the restored markup
        wpsa_hydrateStateFromDOM();
        updateSummaryCards();  // paint Summary for whatever tab is active
        
        // Make sure the label element exists near the input
    wpsa_ensureTestedUrlEl();

    // If ?test_url=... exists, do not restore testedUrl from session state
    var qUrl = '';
    try {
      var p = new URLSearchParams(window.location.search || '');
      if (p.has('test_url')) {
        qUrl = (p.get('test_url') || '').trim();
        try { qUrl = decodeURIComponent(qUrl); } catch(e) {}
        qUrl = (qUrl || '').trim();
      }
    } catch(e) {}

    if (!qUrl && state.testedUrl) {
      $('input[name="test_url"]').val(state.testedUrl);
    }

    // Restore flags only for normal restores (no query test_url here, since we returned above)
    window._wpsa_testNo       = state.testNo || 0;
    window._wpsa_is_scheduled = !!state.isScheduled;

        wpsa_ensureTestedUrlEl();
        wpsa_renderTestedUrlLabel();

        
            // If module 7 HTML was part of the saved DOM, re-mark it as rendered
            if ($('#module7-container').children().length) {
              $('#module7-container').data('rendered', true).show();
              $('#module7-running').hide();
            }
        
            // Re-run your validators/bindings that rely on existing DOM
            $(document).trigger('wpsa_module7_done');
             // Ensure the PDF quota label is live after a restore
            if (jQuery('#wpsa-pdf-counter').length) {
              wpsa_refreshPdfCounter();
            }
            wpsa_saveTestState();  // capture the final, fully rendered state (optional)
          } catch(e){}
    }
        
        // After restoring, ensure tab handlers & visibility are correct
        wpsa_bindTabToggles();
        wpsa_syncInitialTabs();

        
        function wpsa_clearTestState() {
          try { sessionStorage.removeItem(WPSA_STATE_KEY); } catch(e){}
        }
        
        /* Try to restore on page load. Also cache whatever is already on screen. */
      wpsa_restoreTestStateIfEmpty();
        wpsa_applySavedHeader();
        
        // IMPORTANT: if the URL contains ?load_test_no=..., it must win over any restored state
        wpsa_applyQueryLoadTestNoOverride();
        
        if ($('#test-results').length && ($('#test-results').html()||'').trim()) {
          wpsa_saveTestState();
        }

        wpsa_pickRunMetaFromModule1(); // paint the label immediately if Module 1 already printed meta
        wpsa_initCooldownFromStorage();
        
         // 1.16.3: Load button tooltip + initial disabled/enabled state
        wpsa_attachLoadTooltip();
        wpsa_updateLoadBtnState();
        wpsa_applyQueryLoadTestNoPrefill();
        wpsa_applyQueryTestUrlRunPrefillAttention();


        
        // Live-enable the Load button as soon as the user enters a test #
        $(document)
          .off('input.wpsaLoadInput change.wpsaLoadInput keyup.wpsaLoadInput paste.wpsaLoadInput')
          .on('input.wpsaLoadInput change.wpsaLoadInput keyup.wpsaLoadInput paste.wpsaLoadInput', '#wpsa-loadtest-input', function (event) {
            $('#wpsa-loadtest-msg').text('');   // clear any prior message
            if (event && event.type === 'paste') {
              setTimeout(wpsa_updateLoadBtnState, 0);
            } else {
              wpsa_updateLoadBtnState();
            }
          });
        setTimeout(wpsa_updateLoadBtnState, 0);

        
        // If any PDF quota label is present anywhere on the page, keep it fresh
        if (jQuery('#wpsa-pdf-remaining, #wpsa-pdf-counter, #wpsa-pdf-counter-tests').length) {
          wpsa_refreshPdfCounter();
        }

        
        /* Make the plugin logo always go back to the Tests screen */
        jQuery(document)
          .off('click.wpsaLogo')
          .on('click.wpsaLogo', '.wpsa-logo', function (e) {
            if (jQuery(this).closest('a[href]').length) return;
            if (window.wpsaData && wpsaData.homeUrl) {
              e.preventDefault();
              window.location.href = wpsaData.homeUrl;
            }
          });
        jQuery(function(){ jQuery('.wpsa-logo').css('cursor','pointer'); });



        /* ---------- /Persist block ---------- */
        /* ===== Hydrate runtime state from the restored DOM ===== */
        function wpsa_hydrateStateFromDOM() {
          try {
            // --- Module 5 (perf) ---
            ['mobile','desktop'].forEach(function (strat) {
              var $p = $('#perf-' + strat);
              if (!$p.length) return;
        
              var scoreTxt = $p.find('.perf-text').text().trim() || 'N/A';
              var scoreNum = parseInt(scoreTxt, 10);
              var score    = isNaN(scoreNum) ? 'N/A' : scoreNum;
        
             var lcpTxt = (function(){
              var $v = $p.find('.wpsa-card-lcp .value').clone();
              $v.find('.icon').remove();
              return ($v.text().trim() || 'N/A');
            })();
            
            var fcpTxt = (function(){
              var $v = $p.find('.wpsa-card-fcp .value').clone();
              $v.find('.icon').remove();
              return ($v.text().trim() || 'N/A');
            })();

        
            var clsTxt = (function(){
              var $v = $p.find('.wpsa-card-cls .value').clone();
              $v.find('.icon').remove();
              return ($v.text().trim() || 'N/A');
            })();
            
            var inpTxt = (function(){
              var $v = $p.find('.wpsa-card-inp .value').clone();
              $v.find('.icon,.inp-badge').remove();
              return ($v.text().trim() || 'N/A');
            })();
            
            window._wpsa_perf[strat] = {
              score: scoreTxt === 'N/A' ? 'N/A' : score,
              lcp:   lcpTxt,
              fcp:   fcpTxt,
              cls:   clsTxt,
              inp:   inpTxt,
              diagnostics: []
            };

            });
        
            if (window._wpsa_perf.mobile && window._wpsa_perf.desktop) {
              window._wpsa_perf.logged = true;
            }
        
            // --- Module 2 (assets) ---
            ['mobile','desktop'].forEach(function (strat) {
              var $a = $('#asset-' + strat);
              if (!$a.length) return;
        
              var req  = parseInt($a.find('.wpsa-card-requests .value').text(), 10);
              var size = parseFloat(($a.find('.wpsa-card-bytes .value').text().trim().split(/\s+/)[0] || ''));
        
              if (Number.isFinite(req) && req > 0 && Number.isFinite(size) && size > 0) {
                window._wpsa_mod2_data[strat] = { requests: req, page_size: size };
              } else {
                window._wpsa_mod2_data[strat] = null;
              }
            });
          } catch (e) {
            // fail silently – we only need “best effort” hydration
          }
        }
        
       /* ===== Stale UI guard (centralized) ===== */
        (function(){
          // mark fresh whenever results land or a test/log is (re)loaded
          function wpsaMarkFresh(){ window._wpsa_dataLoadedAt = Date.now(); }
          function wpsaIsUiStale(maxAgeMs){ 
            var t = window._wpsa_dataLoadedAt || 0;
            return (Date.now() - t) > (maxAgeMs || 12*60*1000); // 12 min
          }
          function wpsaShowStaleNotice($at, msg){
            var $wrap = ($at.closest('.wpsa-card, .wpsa-box, .module, .postbox, .wpsa-pdf-button-wrap').first());
            if (!$wrap.length) $wrap = $('#test-results');
            $wrap.find('.notice.wpsa-stale').remove();
            $wrap.append(
              '<div class="notice notice-error2 wpsa-stale"><p><strong>Error:</strong> ' +
              (msg || 'The results on this screen are stale. Please run a new test and try again.') +
              '</p></div>'
            );
          }
        
         // mark fresh on key milestones (run, load-from-log, ajax completions, restore)
            $(document)
              .on('wpsa_module1_done wpsa_module2_done wpsa_module5_logged wpsa_module7_done', wpsaMarkFresh);

          try { if ($('#test-results').children().length) wpsaMarkFresh(); } catch(e){}
          try { if (window._wpsa_loaded_from_log) wpsaMarkFresh(); } catch(e){}
        
          // single delegated guard for dropdowns/toggles/copy actions
          $(document)
            .off('click.wpsaStale')
            .on('click.wpsaStale',
              'details>summary, .details>summary, .toggle, .disclosure,' +
              ' .wpsa-inline-details, .wpsa-inline-copy, [data-wpsa-copy],' +
              ' .wpsa-copy-btn, .copy, .wpsa-js-toggle, .wpsa-show-onload-js, .wpsa-show-onload-css',
              function(e){
                if (!wpsaIsUiStale()) return;            // still fresh → allow
                e.preventDefault(); e.stopImmediatePropagation();
                wpsaShowStaleNotice($(this), 'The results are stale (expired). Please run a fresh test and try again.');
              });
        })();

    
    /* ===== Bind tab toggles globally (survive reloads/restores) ===== */
    function wpsa_bindTabToggles() {
      // Module 2 tabs
      $(document)
        .off('click.wpsaTab2')
        .on('click.wpsaTab2', '.wpsa-tab2', function (e) {
          e.preventDefault();
          var $t = $(this);
          $t.addClass('active').siblings('.wpsa-tab2').removeClass('active');
          var strat = $t.data('strategy'); // 'mobile' | 'desktop'
          $('#asset-mobile,#asset-desktop').hide();
          $('#asset-' + strat).show();
        });
    
      // Module 5 tabs
      $(document)
        .off('click.wpsaTab5')
        .on('click.wpsaTab5', '.wpsa-tab5', function (e) {
          e.preventDefault();
          var $t = $(this);
          $t.addClass('active').siblings('.wpsa-tab5').removeClass('active');
          var s = $t.data('strategy'); // 'mobile' | 'desktop'
          $('#perf-mobile,#perf-desktop').hide();
          $('#perf-' + s).show();
          $('#perf-mobile .wpsa-subheading,#perf-desktop .wpsa-subheading').hide();
          $('#perf-' + s + ' .wpsa-subheading').show();
        });
    }
    
    /* Make the currently “active” tab control visibility after load/restore */
    function wpsa_syncInitialTabs() {
      var $t2 = $('.wpsa-tab2.active').first();
      if ($t2.length) {
        $('#asset-mobile,#asset-desktop').hide();
        $('#asset-' + $t2.data('strategy')).show();
      }
      var $t5 = $('.wpsa-tab5.active').first();
      if ($t5.length) {
        $('#perf-mobile,#perf-desktop').hide();
        $('#perf-' + $t5.data('strategy')).show();
        $('#perf-mobile .wpsa-subheading,#perf-desktop .wpsa-subheading').hide();
        $('#perf-' + $t5.data('strategy') + ' .wpsa-subheading').show();
      }
      var $t6 = $('.wpsa-tab6.active').first();
        if ($t6.length) {
          _wpsa_summary_strat = $t6.data('strategy');
          updateSummaryCards();
}

    }
    
      /* === Load button: enable only when results exist on screen === */
    function wpsa_hasResultsOnScreen() {
      var $r = $('#test-results');
      if (!$r.length) return false;
      var html = ($r.html() || '').trim();
      if (!html) return false;
      // consider “results on screen” if any key module markup exists
      return $r.find('.wpsa-module-1 table, #module2-results .wpsa-stat-cards, #wpsa-module5').length > 0;
    }
    
  function wpsa_getQueryInt(name) {
  try {
    var p = new URLSearchParams(window.location.search || '');
    if (!p.has(name)) return 0;
    var n = parseInt(p.get(name) || '0', 10) || 0;
    return (n > 0) ? n : 0;
  } catch(e) {
    return 0;
  }
}

function wpsa_startLoadAttention() {
  var $btn = jQuery('#wpsa-loadtest-btn');
  if (!$btn.length) return;

  // Only pulse when the button is actually usable
  if ($btn.prop('disabled') || $btn.attr('aria-disabled') === 'true') return;

  if (!document.getElementById('wpsa-load-attn-css')) {
    var css = '' +
      '#wpsa-loadtest-btn.wpsa-load-attn{' +
        'animation:wpsaLoadAttn 0.9s ease-in-out infinite;' +
      '}' +
      '@keyframes wpsaLoadAttn{' +
        '0%,100%{transform:scale(1); box-shadow:0 0 0 0 rgba(255,107,53,0); filter:none;}' +
        '50%{transform:scale(1.06); box-shadow:0 0 0 4px rgba(255,107,53,.45); filter:brightness(1.05);}' +
      '}' +
      '#wpsa-loadtest-btn.wpsa-load-attn:focus{' +
        'outline:2px solid rgba(255,107,53,.65); outline-offset:2px;' +
      '}';
    var st = document.createElement('style');
    st.id = 'wpsa-load-attn-css';
    st.appendChild(document.createTextNode(css));
    document.head.appendChild(st);
  }

  $btn.addClass('wpsa-load-attn').attr('data-wpsa-attn', '1');

  try { $btn[0].focus(); } catch(e){}
  try { $btn[0].scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch(e){}
}

function wpsa_stopLoadAttention() {
  var $btn = jQuery('#wpsa-loadtest-btn');
  if (!$btn.length) return;
  $btn.removeClass('wpsa-load-attn').removeAttr('data-wpsa-attn');
}

function wpsa_startRunAttention() {
  var $btn = jQuery('.wpsa-button-run').first(); // your Run Speed Audit button
  if (!$btn.length) return;

  // Only pulse when the button is usable
  if ($btn.prop('disabled') || $btn.attr('aria-disabled') === 'true') return;

  if (!document.getElementById('wpsa-run-attn-css')) {
    var css = '' +
      '.wpsa-button-run.wpsa-run-attn{' +
        'animation:wpsaRunAttn 0.9s ease-in-out infinite;' +
      '}' +
      '@keyframes wpsaRunAttn{' +
        '0%,100%{transform:scale(1); box-shadow:0 0 0 0 rgba(255,107,53,0); filter:none;}' +
        '50%{transform:scale(1.06); box-shadow:0 0 0 4px rgba(255,107,53,.45); filter:brightness(1.05);}' +
      '}' +
      '.wpsa-button-run.wpsa-run-attn:focus{' +
        'outline:2px solid rgba(255,107,53,.65); outline-offset:2px;' +
      '}';
    var st = document.createElement('style');
    st.id = 'wpsa-run-attn-css';
    st.appendChild(document.createTextNode(css));
    document.head.appendChild(st);
  }

  $btn.addClass('wpsa-run-attn').attr('data-wpsa-attn', '1');

  try { $btn[0].focus(); } catch(e){}
  try { $btn[0].scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch(e){}
}

function wpsa_stopRunAttention() {
  var $btn = jQuery('.wpsa-button-run').first();
  if (!$btn.length) return;
  $btn.removeClass('wpsa-run-attn').removeAttr('data-wpsa-attn');
}

function wpsa_applyQueryTestUrlRunPrefillAttention() {
  // Only for editor links that pass ?test_url=...
  var u = '';
  try {
    var p = new URLSearchParams(window.location.search || '');
    if (!p.has('test_url')) return;
    u = p.get('test_url') || '';
    try { u = decodeURIComponent(u); } catch(e) {}
    u = (u || '').trim();
  } catch(e) {}

  if (!u) return;

  // Your existing code already prefills the URL input.
  // We only add persistent attention to the Run button.
  wpsa_startRunAttention();
}


function wpsa_applyQueryLoadTestNoPrefill() {
  var n = wpsa_getQueryInt('load_test_no');
  if (!n) return;

  var $in  = jQuery('#wpsa-loadtest-input');
  var $msg = jQuery('#wpsa-loadtest-msg');

  if ($msg.length) $msg.text(''); // never show "Loaded." just because URL has a param
  if ($in.length)  $in.val(n).trigger('input').trigger('change');

  // Recompute enabled/disabled state using your existing rules
  try { wpsa_updateLoadBtnState(); } catch(e){}

  // Start attention ONLY if button is enabled and DOM is usable
  wpsa_startLoadAttention();
}


    function wpsa_attachLoadTooltip() {
      var $btn = $('#wpsa-loadtest-btn');
      if (!$btn.length) return;
      if (!$('#wpsa-loadtest-tooltip').length) {
        $('<span id="wpsa-loadtest-tooltip" class="custom-tooltip" ' +
           'data-tooltip="Load previous test results. You can check the test # in the Compare Results section. Run one speed audit before using it.">?</span>'
         ).insertAfter($btn);
      }
    }
    
function wpsa_canLoadTestHere() {
  // Must have the results container (where we inject/restore DOM)
  var $results = jQuery('#test-results');
  if (!$results.length) return false;

  // Must have the Tests UI skeleton present.
  // Your markup seems to use class-based module wrappers (not #wpsa-module1).
  if (
    !jQuery('.wpsa-module-1').length &&
    !jQuery('#wpsa-module1').length &&
    !jQuery('#module1-results').length &&
    !$results.find('.wpsa-module-1, #module1-results').length
  ) {
    return false;
  }

  // Must have the Load UI itself
  if (!jQuery('#wpsa-loadtest-input').length || !jQuery('#wpsa-loadtest-btn').length) {
    return false;
  }

  return true;
}


function wpsa_updateLoadBtnState() {
  var $btn = jQuery('#wpsa-loadtest-btn');
  if (!$btn.length) return;

  var $msg = jQuery('#wpsa-loadtest-msg');

  var hasNumber = parseInt(jQuery('#wpsa-loadtest-input').val(), 10) > 0;

  // Enable for a valid number; the click guard still blocks screens that cannot paint results.
  var enable = hasNumber;

  // Never show a false-positive "Loaded."
  if (!enable && $msg.length && ($msg.text() || '').trim() === 'Loaded.') {
    $msg.text('');
  }

  if (enable) {
    $btn.prop('disabled', false)
        .removeClass('button-disabled')
        .removeAttr('aria-disabled');
  } else {
    $btn.prop('disabled', true)
        .addClass('button-disabled')
        .attr('aria-disabled','true');
  }
}


    
     // 1.16.7 Expose selected helpers for guarded window.* calls used elsewhere
      window.wpsa_saveTestState       = wpsa_saveTestState;
      window.wpsa_renderTestedUrlLabel = wpsa_renderTestedUrlLabel;
      window.wpsa_updateLoadBtnState   = wpsa_updateLoadBtnState;

         // On form submit: reset UI & state, show spinners
        $('#speed-test-form').on('submit', function(){
          try { wpsa_stopRunAttention(); } catch(e){}
          try { sessionStorage.setItem('WPSA_RUN_AFTER_RELOAD','1'); } catch(e) {}
          // Cooldown will start upon first successful module response
          wpsa_clearTestState();
          $('#test-results').empty();
          $('#running-test').show();
          // keep Load button greyed-out until results appear
          $('#wpsa-loadtest-btn').prop('disabled', true).addClass('button-disabled').attr('aria-disabled','true');
        
          // we’ll tint the whole module via inline style so it always beats CSS order
          var wpsaHotBg = WPSA_COLORS.hot;
        
          // === modules entering "running" state ===
          // 2) whole module, not just the line
          $('.wpsa-module-2')
            .addClass('wpsa-hot')
            .css({ background: wpsaHotBg, 'border-radius': '15px' });
          $('#module2-running').show();
        
          // 5) whole module, not just the line
          $('.wpsa-module-5')
            .addClass('wpsa-hot')
            .css({ background: wpsaHotBg, 'border-radius': '15px' });
          $('#module5-running').show();

        
        // 6 stays hidden during run
        $('#module6-running').show().addClass('wpsa-hot');
        $('#wpsa-module6').hide();
        
        // 7) reset module 7 – keep it hidden until Summary (Module 6) is ready
        $('#module7-wrapper')
          .removeClass('wpsa-hot')
          .css({ background: '', 'border-radius': '' })
          .hide();
        $('#module7-running').hide();
        $('#module7-container')
          .removeData('rendered')
          .data('rendered', false)
          .empty()
          .hide();


        
          window._wpsa_perf.logged = false;
          window._wpsa_perf.mobile = null;
          window._wpsa_perf.desktop = null;
          window._wpsa_mod2_data  = { mobile: null, desktop: null };
          _wpsa_summary_strat     = 'mobile';


        
        // — clear the “Tested URL:” line
          $('#wpsa-tested-url').hide().text('');

          // NEW: clear response headers UI (Module 1)
          try { wpsaRenderResponseHeaders(null); } catch(e){}

          // NEW: don’t carry over a previous “Load test #” or scheduled flag
          window._wpsa_testNo       = 0;
          window._wpsa_is_scheduled = false;
        });



    
    // Load test by number — hydrate UI from results log (handles N/A + off-server)
    jQuery(document).on('click', '#wpsa-loadtest-btn', function (e) {
  e.preventDefault();
  try { wpsa_stopLoadAttention(); } catch(e){}

  // IMPORTANT: "Load test #" cannot show correct response headers (debug log overwrites each run).
  // Clear/hide to avoid stale headers being shown for a different test.
  try { wpsaRenderResponseHeaders(null); } catch(e){}
      
     var forced = false;
        try {
          var p = new URLSearchParams(window.location.search || '');
          forced = p.has('load_test_no');
        } catch(e){}
        
      // Hard block if this screen can't paint loaded results (no skeleton)
    if (typeof wpsa_canLoadTestHere === 'function' && !wpsa_canLoadTestHere()) {
      jQuery('#wpsa-loadtest-msg').text('Load needs the report area to be available. Run one speed audit once, then try Load test # again.');
      return;
    }
    
    // Respect the computed disabled state
    if (jQuery(this).prop('disabled')) {
      jQuery('#wpsa-loadtest-msg').text('Enter a valid test # to load.');
      return;
    }



      var n   = parseInt(jQuery('#wpsa-loadtest-input').val(), 10) || 0;
      var $msg = jQuery('#wpsa-loadtest-msg').text('');
      if (!n || n < 1) { $msg.text('Enter a valid test #'); return; }
    
      $msg.text('Loading…');
    
      jQuery.post(ajaxurl, {
        action: 'wpsa_load_test',
        nonce:  (window.wpsaData && wpsaData.loadTestNonce) || '',
        test_no: n
      })
          .done(function (r) {
          if (!(r && r.success && r.data && Array.isArray(r.data.lines))) {
            $msg.text('Test # ' + n + ' not found'); return;
          }
        
          // Work only with the requested test's block
          var lines = r.data.lines.slice(); // full file
            window._wpsa_loaded_from_log = true;
          (function narrowToBlock(){
            var start = -1, end = lines.length;
            var headRE = new RegExp('^\\s*Test\\s+#' + n + '\\b');  // “Test #<n> …”
            for (var i=0; i<lines.length; i++) { if (headRE.test(lines[i])) { start = i; break; } }
            if (start !== -1) {
              for (var j=start+1; j<lines.length; j++) { if (/^\s*Test\s+#\d+/.test(lines[j])) { end = j; break; } }
              lines = lines.slice(start, end);
            }
          })();
          
          // 1.17.4: update PSI screenshots from diagnostics for this test
          var diag = (r.data && r.data.diag) ? r.data.diag : {};
          wpsa_applyScreenshotsFromDiag(diag);
        
          function find(re){ for (var i=0;i<lines.length;i++){ var m=re.exec(lines[i]); re.lastIndex=0; if(m) return m; } return null; }
          function setText($el, txt){ if($el && $el.length){ $el.text(txt); } 
        }
        function setHTML($el, html){ if($el && $el.length){ $el.html(html); } }
        function paintCard(sel, value, bg){
          var $c = jQuery(sel);
          setHTML($c.find('.value'), value);
          if(bg){ $c.css('background', bg); }
        }
        
        function naCard(sel){
          var $c = jQuery(sel);
          $c.removeClass('is-green is-amber is-red').addClass('is-na').css('background', WPSA_COLORS.na);
          $c.find('.icon').remove();
        }
        function hideAndClearDetails($root){
          if(!$root || !$root.length) return;
          $root.find('ul').empty();
          $root.prop('open', false).hide();
            }
        function isSameHost(u){
          try{
            var h = new URL(u).hostname.toLowerCase();
            var me = window.location.hostname.toLowerCase();
            return h===me || h.endsWith('.'+me) || me.endsWith('.'+h);
          }catch(e){ return true; }
        }
    
        // ── Header: URL / Region / Cache / TTFB
        var mh = find(/^\s*Test\s+#(\d+).+?\|\s*URL:\s*([^\|]+)\|\s*Region:\s*([^\|]+)\|\s*Cache:\s*([^\|]+)\|.*?\bTTFB:\s*([0-9]+)\s*ms/i);
        var testNo = mh ? parseInt(mh[1],10) : n;
        var url    = mh ? (mh[2]||'').trim() : '';
        var region = mh ? (mh[3]||'').trim() : '';
        var cache  = mh ? (mh[4]||'').trim() : '';
        var ttfb   = mh ? (mh[5]||'').trim() : '';

        // Detect scheduled runs from the header line: "Test #22 S |1.17| …"
        var headerLine = (lines[0] || '').trim();
        window._wpsa_is_scheduled = /\bTest\s+#\d+\s+S\b/i.test(headerLine);
    
        if (url) {
          jQuery('input[name="test_url"]').val(url);
          window._wpsa_testNo = testNo;
          if (typeof window.wpsa_renderTestedUrlLabel === 'function') {
            window.wpsa_renderTestedUrlLabel();
          }
        }

        
        // --- KEEP META + HEADER + LOAD INPUT IN SYNC AFTER A "LOAD TEST #" ---
        var $meta = jQuery('#wpsa-run-meta');
        if ($meta.length) {
          $meta
            .attr('data-test-no', testNo)
            .attr('data-tested-url', url)
            .data('testNo', testNo)
            .data('testedUrl', url);
        }
        
        // ensure the label exists and reflects the loaded test
        if (!jQuery('#wpsa-tested-url').length) {
          jQuery('<div id="wpsa-tested-url" class="wpsa-tested-url" />')
            .insertAfter('input[name="test_url"]');
        }
        jQuery('#wpsa-loadtest-input').val(testNo);
        wpsa_renderTestedUrlLabel();
        wpsa_updateLoadBtnState();
        
        // persist so switching tabs restores the loaded test context
        if (typeof window.wpsa_saveTestState === 'function') {
          window.wpsa_saveTestState();
        }

        // Module 1 row
        (function(){
          var $tds = jQuery('.wpsa-module-1 table tbody tr td');
          if ($tds.length >= 3) {
            if (region) setText($tds.eq(0), region);
            if (cache)  setText($tds.eq(1), cache);
            if (ttfb)   setText($tds.eq(2), ttfb + ' ms');
          }
        })();
        try {
        wpsaApplyModule1BgFromDom(document);
        wpsaRewriteModule1StatusFromDom(document); 
        } catch(e){}
        

        // Module 1: Response headers (pulled from this Test # block)
        (function renderM1ResponseHeadersFromLogBlock(){
          function pullHeadersFromBlock(){
            for (var i = 0; i < lines.length; i++) {
              var s = (lines[i] || '').toString().trim();
              if (!s) { continue; }
              if (!/response\s*headers|respheaders|module\s*1.*headers|http\s*headers/i.test(s)) { continue; }

              var a = s.indexOf('{');
              var b = s.lastIndexOf('}');
              if (a === -1 || b <= a) { continue; }

              var jsonStr = s.slice(a, b + 1);
              try { return JSON.parse(jsonStr); } catch (e) {}
            }
            return null;
          }

          var hObj = pullHeadersFromBlock();

          try {
            var raw = hObj ? JSON.stringify(hObj) : '';
            if (raw) {
              jQuery('#wpsa-run-meta').attr('data-response-headers', raw);
              jQuery('#wpsa-m1-respheaders-wrap').attr('data-response-headers', raw);
            } else {
              jQuery('#wpsa-run-meta').removeAttr('data-response-headers');
              jQuery('#wpsa-m1-respheaders-wrap').removeAttr('data-response-headers');
            }
          } catch (e) {}

          try { wpsaRenderResponseHeaders(hObj); } catch (e) {}
          try { jQuery(document).trigger('wpsa_module1_done'); } catch (e) {}
        })();


        
        // Normalize the DOM in JS before painting:
        (function normalizeM34Cards(){
          // Module 3 card: add standard class if missing
          var $m3 = jQuery('.wpsa-module-3-4 .wpsa-module-3-title')
                     .closest('div').find('.wpsa-stat-card').first();
          if ($m3.length && !$m3.hasClass('wpsa-card-autoload')) {
            $m3.addClass('wpsa-card-autoload');
          }
        
          // Module 4 card: add standard class if missing
          var $m4 = jQuery('.wpsa-module-3-4 .wpsa-module-4-title')
                     .closest('div').find('.wpsa-stat-card').first();
          if ($m4.length && !$m4.hasClass('wpsa-card-objectcache')) {
            $m4.addClass('wpsa-card-objectcache');
          }
        
          // also clear NA styling so painters can recolor
          $m3.add($m4).removeClass('is-na').css('background','').find('.icon').remove();
        })();

        
        
    
        // --- M3/M4: robust parser for autoload size + object cache (works with many log variants) ---
        (function () {
          // 1) Helpers to extract values from a single line
          function pickAutoload(line) {
            // Match anything like:
            //   "Autoloaded options size: 442.7 KB"
            //   "Autoload options: 442,7 KiB"
            //   "Autoloaded options size (KB): 442.7"
            //   "Autoload options size: N/A"
            var m = line.match(/autoload(?:ed)?\s*options?\s*(?:size)?\s*[:\-]?\s*(N\/A|[\d.,]+)\s*(?:K(?:I)?B)?/i);
            return m ? (m[1] || '').trim() : null;
          }
        
          function pickObjCache(line) {
            // Match anything like:
            //   "Persistent object cache: Yes/No/Enabled/Disabled/On/Off/Available/Unavailable/N/A"
            //   "Object cache: Enabled"
            var m = line.match(/(?:persistent\s*)?object\s*cache\s*[:\-]?\s*(yes|no|enabled|disabled|on|off|available|unavailable|n\/a)/i);
            if (!m) return null;
            var v = m[1].toLowerCase();
            if (v === 'n/a') return 'N/A';
            if (v === 'yes' || v === 'enabled' || v === 'on' || v === 'available') return 'Yes';
            if (v === 'no' || v === 'disabled' || v === 'off' || v === 'unavailable') return 'No';
            return null;
          }
        
          // 2) Build an index over the whole log (best effort), keyed by Test #
          var all = Array.isArray(r && r.data && r.data.lines) ? r.data.lines : [];
          var tests = {}, current = null;
        
          for (var i = 0; i < all.length; i++) {
            var s = all[i];
        
            var h = s.match(/^\s*Test\s*#\s*(\d+)\b/i);
            if (h) { current = parseInt(h[1], 10); tests[current] = tests[current] || { m3: null, m4: null }; continue; }
            if (current == null) continue;
        
            var a = pickAutoload(s);
            if (a != null) tests[current].m3 = a;
        
            var oc = pickObjCache(s);
            if (oc != null) tests[current].m4 = oc;
          }
        
          // 3) Fallback: also scan only the *current* block (the narrowed `lines`) in case the server returned a slice
          var local = { m3: null, m4: null };
          for (var j = 0; j < lines.length; j++) {
            var s2 = lines[j];
            if (local.m3 == null) {
              var a2 = pickAutoload(s2);
              if (a2 != null) local.m3 = a2;
            }
            if (local.m4 == null) {
              var oc2 = pickObjCache(s2);
              if (oc2 != null) local.m4 = oc2;
            }
            if (local.m3 != null && local.m4 != null) break;
          }
        
          // 4) Painter helpers
          function paintAutoload(val) {
            var $card = jQuery('.wpsa-card-autoload').removeClass('is-na');
            $card.find('.icon').remove();
            if (!val || String(val).toUpperCase() === 'N/A') {
              $card.addClass('is-na').css('background', '#eeeeee').find('.value').text('N/A');
              return;
            }
            var n = parseFloat(String(val).replace(',', '.'));
            if (!Number.isFinite(n)) {
              $card.addClass('is-na').css('background', '#eeeeee').find('.value').text('N/A');
              return;
            }
            var bg = n <= 800 ? '#c6f7c3' : (n <= 1024 ? '#fef6b3' : '#ffdddd');
            $card.css('background', bg).find('.value').text(n.toFixed(1) + ' KB');
            if (bg === '#c6f7c3') { $card.find('.value').append(' <span class="icon">✅</span>'); }
          }
        
          function paintObjectCache(val) {
            var $card = jQuery('.wpsa-card-objectcache').removeClass('is-na');
            $card.find('.icon').remove();
            var v = (val || '').toString().trim().toUpperCase();
            if (!v || v === 'N/A') {
              $card.addClass('is-na').css('background', '#eeeeee').find('.value').text('N/A');
              return;
            }
            var yes = v === 'YES';
            $card.css('background', yes ? '#c6f7c3' : '#ffdddd')
                 .find('.value').text(yes ? 'Yes' : 'No');
            if (yes) { $card.find('.value').append(' <span class="icon">✅</span>'); }
          }
        
          // 5) Choose values for the requested test
          var rec = tests[testNo] || {};
          var autoloadVal = rec.m3 != null ? rec.m3 : local.m3;
          var objCacheVal = rec.m4 != null ? rec.m4 : local.m4;
        
          // Respect off-server rule: if off-server → show N/A + show footnote
          var sameHost = isSameHost(url);
          if (!sameHost) { autoloadVal = 'N/A'; objCacheVal = 'N/A'; }
        
          paintAutoload(autoloadVal);
          paintObjectCache(objCacheVal);
        
          // Toggle the "*N/A for off-server URLs" notes
          jQuery('.wpsa-module-3-4 .wpsa-footnote').filter(function () {
            return /off[-\s]?server/i.test(jQuery(this).text());
          }).toggle(!sameHost);
        })();

        
        

        // ── Module 4.5 (plugins/php/db)
        (function(){
          var same = isSameHost(url);
          var m = find(/^Module 4\.5:\s*Active plugins:\s*([0-9]+);\s*PHP:\s*([0-9.]+);\s*DB:\s*([A-Za-z]+)\s*([0-9.]+)/i);
        
          function setNA(){
            naCard('.wpsa-card-plugins'); setText(jQuery('.wpsa-card-plugins .value'), 'N/A');
            naCard('.wpsa-card-php');     setText(jQuery('.wpsa-card-php .value'), 'N/A');
            naCard('.wpsa-card-db');      setHTML(jQuery('.wpsa-card-db .value'), 'N/A');
            // hide “Show active plugins list”
            hideAndClearDetails(jQuery('#wpsa-plugin-list').closest('details'));
          }
        
          if (!same || !m) { setNA(); return; }
        
          // Values exist → paint them, but still clear any stale details list from a previous run
          setText(jQuery('.wpsa-card-plugins .value'), m[1]);
          setText(jQuery('.wpsa-card-php .value'), m[2]);
          setHTML(jQuery('.wpsa-card-db .value'), (m[3]||'DB') + '<br><span class="subvalue">'+ (m[4]||'') +'</span>');
          jQuery('.wpsa-card-plugins,.wpsa-card-php,.wpsa-card-db').removeClass('is-na').css('background',''); // back to default
          hideAndClearDetails(jQuery('#wpsa-plugin-list').closest('details')); // we can’t reconstruct list from log
        })();
        
        // ── Paint Module 4.5 grid (under Modules 3/4), and hide off-server note
        (function () {
          var same = isSameHost(url);
        
          // hide “N/A for off-server URLs” notes anywhere in the 3–4 panel when same-host
          jQuery('.wpsa-module-3-4 .wpsa-footnote').filter(function () {
            return /off-?server/i.test(jQuery(this).text());
          }).toggle(!same);
        
          // Read values we already wrote into the Summary cards above
          var plugTxt = jQuery('.wpsa-card-plugins .value').text().trim();
          var phpTxt  = jQuery('.wpsa-card-php .value').text().trim();
          var dbTxt   = jQuery('.wpsa-card-db .value').clone().children().remove().end().text().trim();
          var dbVer   = jQuery('.wpsa-card-db .value .subvalue').text().trim();
        
          function setGridCard(headerRegex, valueText, bgClass) {
            var $card = jQuery('.wpsa-module-3-4 .wpsa-meta-card').filter(function () {
              return headerRegex.test(jQuery(this).find('.header').text());
            }).first();
            if (!$card.length) return;
            $card.removeClass('is-na is-green is-amber is-red')
                 .addClass(bgClass)
                 .find('.value').text(valueText);
          }
        
          // decide severities
          var plugNum = parseInt(plugTxt, 10);
          var plugsCls = isFinite(plugNum) ? (plugNum <= 40 ? 'is-green' : (plugNum <= 55 ? 'is-amber' : 'is-red')) : 'is-na';
        
          var phpMajor = (phpTxt.match(/^(\d+(?:\.\d+)?)/) || [,''])[1];
          var phpNum = parseFloat(phpMajor);
          var phpCls = isFinite(phpNum) ? (phpNum >= 8.0 ? 'is-green' : 'is-red') : 'is-na';
        
          function vcmp(a,b){a=(a||'').split('.');b=(b||'').split('.');for(var i=0;i<Math.max(a.length,b.length);i++){var x=parseInt(a[i]||'0',10),y=parseInt(b[i]||'0',10);if(x>y)return 1;if(x<y)return -1;}return 0;}
          var dbCls = 'is-na';
          if (dbTxt && dbVer) {
            if (/mariadb/i.test(dbTxt)) {
              dbCls = vcmp(dbVer,'10.6') >= 0 ? 'is-green' : (vcmp(dbVer,'10.4') >= 0 ? 'is-amber' : 'is-red');
            } else {
              dbCls = vcmp(dbVer,'8.0') >= 0 ? 'is-green' : 'is-red';
            }
          }
        
          // paint Module 4.5 grid cards by their headers
          setGridCard(/Active plugins/i, plugTxt || 'N/A', plugsCls);
          setGridCard(/PHP version/i, phpTxt  || 'N/A', phpCls);
          setGridCard(/DB server/i,  (dbTxt && dbVer) ? (dbTxt + ' ' + dbVer) : 'N/A', dbCls);
        })();
        
        // ✅ Repaint Summary now that 4.5 values exist in the DOM (for “Load test #” flows)
        try { updateSummaryCards(); } catch(e){}
        
        // after modules 3/4/4.5 painting in the Load handler, once:
            jQuery('.wpsa-footnote').filter(function () {
              return /N\/A\s+for\s+off[-\s]?server/i.test(jQuery(this).text());
            }).toggle(!isSameHost(url));

    
        // ── Module 5 (mobile/desktop)
        function pullM5(which){
          // tolerant: optional " s", allows N/A, and (optionally) captures CLS/INP tail
          var re = which === 'mobile'
            ? /^Module 5 Mobile:\s*Performance:\s*([0-9]+),\s*LCP:\s*(N\/A|[0-9.]+)(?:\s*s)?\s*,\s*FCP:\s*(N\/A|[0-9.]+)(?:\s*s)?(?:\s*,\s*CLS:\s*(N\/A|[0-9.]+))?(?:\s*,\s*INP:\s*(N\/A|[0-9]+))?$/i
            : /^Module 5 Desktop:\s*Performance:\s*([0-9]+),\s*LCP:\s*(N\/A|[0-9.]+)(?:\s*s)?\s*,\s*FCP:\s*(N\/A|[0-9.]+)(?:\s*s)?(?:\s*,\s*CLS:\s*(N\/A|[0-9.]+))?(?:\s*,\s*INP:\s*(N\/A|[0-9]+))?$/i;
        
          var m = find(re);
          if (!m) return null;
        
          var score = parseInt(m[1], 10) || 0;
          var lcp   = m[2];                  // as logged (no unit). Add " s" later where needed.
          var fcp   = m[3];
          var cls   = m[4] || 'N/A';
          var inp   = m[5] ? (m[5] === 'N/A' ? 'N/A' : (m[5] + ' ms')) : 'N/A';
        
          return { score: score, lcp: lcp, fcp: fcp, cls: cls, inp: inp, diagnostics: [] };
        }
        
        var m5m = pullM5('mobile'), m5d = pullM5('desktop');
        if (m5m || m5d) {
          window._wpsa_perf = window._wpsa_perf || {};
          window._wpsa_perf.mobile  = m5m || { score:'N/A', lcp:'N/A', fcp:'N/A', cls:'N/A', inp:'N/A', diagnostics:[] };
          window._wpsa_perf.desktop = m5d || { score:'N/A', lcp:'N/A', fcp:'N/A', cls:'N/A', inp:'N/A', diagnostics:[] };
          window._wpsa_perf.logged  = true;

          // Start from a clean preview; a separate helper will hydrate from psi-diag-ss.log
          wpsa_updatePsiScreenshot('mobile',  '');
          wpsa_updatePsiScreenshot('desktop', '');
          
          // Attach diagnostics/insights from server logs (supports new + legacy shapes)
          if (r && r.data && r.data.diag) {
            try {
              var dm = r.data.diag.mobile  || {};
              var dd = r.data.diag.desktop || {};
  
              function arr(v) { return Array.isArray(v) ? v : []; }
  
              var dmDiag = arr(dm.diagnostics || dm.opportunities || r.data.diag.mobile);
              var dmIns  = arr(dm.insights);
  
              var ddDiag = arr(dd.diagnostics || dd.opportunities || r.data.diag.desktop);
              var ddIns  = arr(dd.insights);
  
              window._wpsa_perf.mobile.diagnostics  = dmDiag;
              window._wpsa_perf.mobile.insights     = dmIns;
  
              window._wpsa_perf.desktop.diagnostics = ddDiag;
              window._wpsa_perf.desktop.insights    = ddIns;
            } catch (e) {}
          }

          jQuery('#module5-running').hide(); jQuery('#wpsa-module5').show();
          try {
            renderPerf(window._wpsa_perf.mobile, 'mobile');
            renderPerf(window._wpsa_perf.desktop, 'desktop');
            wpsa_renderM5DiagFromState();
          } catch(e){}
          try { _wpsa_updateMod5Notice(); } catch(e){}
        }
        
        // After painting Module 5 from the log, hydrate PSI screenshots for this Test # from psi-diag-ss.log
        try {
          if (window._wpsa_testNo) {
            wpsa_fetchPsiScreensForTest(window._wpsa_testNo);
          }
        } catch (e) {}


    
                // ── Module 2 (mobile/desktop)
        function setM2NA(which) {
          var pane = '#asset-' + which;

          // Text
          setText(jQuery(pane + ' .wpsa-card-requests .value'), 'N/A');
          setText(jQuery(pane + ' .wpsa-card-bytes .value'), 'N/A');
          setText(jQuery(pane + ' .wpsa-card-onload-js .value'), 'N/A');
          setText(jQuery(pane + ' .wpsa-card-onload-css .value'), 'N/A');

          // Neutral styling + no stale checkmarks
          jQuery(pane + ' .wpsa-stat-card').each(function () {
            jQuery(this).removeClass('is-green is-amber is-red').addClass('is-na');
            this.style.background = WPSA_COLORS.na;
          });
          jQuery(pane + ' .wpsa-stat-card .icon').remove();

          // Summary must become N/A as well
          window._wpsa_mod2_data = window._wpsa_mod2_data || {};
          window._wpsa_mod2_data[which] = null;
        }

        function paintM2(which){
          // Always reset first so we never keep stale values from a previous test
          setM2NA(which);

          // Accept either the full numeric line OR the explicit N/A line
          var re = which === 'mobile'
            ? /^Module 2 Mobile:\s*(?:([0-9]+)\s*req,\s*([0-9.]+)\s*KB;\s*Onload JS:\s*(\d+|N\/A);\s*Onload CSS:\s*(\d+|N\/A)|N\/A)\s*$/i
            : /^Module 2 Desktop:\s*(?:([0-9]+)\s*req,\s*([0-9.]+)\s*KB;\s*Onload JS:\s*(\d+|N\/A);\s*Onload CSS:\s*(\d+|N\/A)|N\/A)\s*$/i;

          var m = find(re);
          if (!m) {
            return; // keep N/A
          }

          // If it was the "N/A" variant, groups will be undefined → keep N/A
          if (!m[1] || !m[2]) {
            return;
          }

          var req = parseInt(m[1], 10);
          var kb  = parseFloat(m[2]);
          var ojs = (m[3] || 'N/A');
          var ocs = (m[4] || 'N/A');

          if (!Number.isFinite(req) || req <= 0 || !Number.isFinite(kb) || kb <= 0) {
            return; // keep N/A
          }

          var pane = '#asset-' + which;

          // Paint real values
          setText(jQuery(pane + ' .wpsa-card-requests .value'), String(req));
          setText(jQuery(pane + ' .wpsa-card-bytes .value'), kb.toFixed(1) + ' KB');
          setText(jQuery(pane + ' .wpsa-card-onload-js .value'), String(ojs));
          setText(jQuery(pane + ' .wpsa-card-onload-css .value'), String(ocs));

          // Remove NA styling so applyColor can do its thing
          jQuery(pane + ' .wpsa-stat-card').removeClass('is-na').each(function () {
            this.style.background = '';
          });
          jQuery(pane + ' .wpsa-stat-card .icon').remove();

          try { applyColor(jQuery(pane)); } catch(e){}

          window._wpsa_mod2_data = window._wpsa_mod2_data || {};
          window._wpsa_mod2_data[which] = { requests: req, page_size: kb };
        }

        paintM2('mobile');
        paintM2('desktop');
        
        // Clear/hide onload file lists — we only have counts in the log
        ['#wpsa-onjs-mobile','#wpsa-onjs-desktop','#wpsa-oncss-mobile','#wpsa-oncss-desktop']
          .forEach(function(id){ hideAndClearDetails(jQuery(id).closest('details')); });
        
         // 1.16.3 — force Module 7 to rebuild for the loaded test #
        $('#module7-container')
          .removeData('rendered')
          .data('rendered', false)
          .empty()
          .hide();
        $('#module7-running').show();
        $('#module7-wrapper')
          .removeClass('wpsa-hot')   // ← make sure load-from-log is neutral
          .show();

        // 1.16.3 end
        // fire events Summary/Conclusion listen for
        jQuery(document).trigger('wpsa_module2_done');
        jQuery(document).trigger('wpsa_module5_logged');

        if (typeof window.wpsa_saveTestState === 'function') { window.wpsa_saveTestState(); }
        $msg.text('Loaded.');
      })
        
      .fail(function(){ $msg.text('Request failed. Try again.'); });
    });


    // — Module 2: Page asset summary —
    if ( _wpsa_shouldRun && $('#module2-running').length ) {
     var wpsaHotBg = WPSA_COLORS.hot;
      $('.wpsa-module-2')
        .addClass('wpsa-hot')
        .css({ background: wpsaHotBg, 'border-radius': '15px' });
    
      // keep the inline "Running tests..." line visible on reload (same as Module 5)
      $('#module2-running').show();

      $.post( ajaxurl, {
        action:      'wpsa_psi',
        _ajax_nonce: wpsaData.psiNonce,
        test_url:    $('input[name="test_url"]').val()
      })
      .done(function(r){
        // If the AJAX call succeeded but we didn't get valid data, show a warning
        if ( ! ( r.success && r.data && typeof r.data.mobile !== 'undefined' && typeof r.data.desktop !== 'undefined' ) ) {
          $('#module2-running').hide();
          $('.wpsa-module-2').removeClass('wpsa-hot');
          const statusCode = (r.data && r.data.error) ? r.data.error : '';
          $('#module2-results').html(
            `<div class="notice notice-warning"><p>
               Test didn't finish${ statusCode ? ' (' + statusCode + ')' : '' }. Please rerun.
             </p></div>`
          );
          // invalid → ensure no cooldown penalty
         wpsa_clearCooldown();
      // Client logging removed — Module 2 is now logged on the server (when available).
      // Fire module2_done so the conclusion spinner can start
      $(document).trigger('wpsa_module2_done');
      return;
    }
    
     // Start cooldown now that PSI returned valid data (real run in progress)
    if (!window._wpsa_cdStarted) { wpsa_beginCooldown(60); window._wpsa_cdStarted = true; }

    // 1) hide spinner
    $('#module2-running').hide();
    $('.wpsa-module-2')
      .removeClass('wpsa-hot')
      .css('background', '');   // ← clear inline tint

    // 2) build tabs + HTML
    const tabs = `
      <div class="wpsa-tabs2">
        <span class="wpsa-tab2 active" data-strategy="mobile">
          <span class="dashicons dashicons-smartphone"></span> Mobile
        </span>
        <span class="wpsa-tab2" data-strategy="desktop">
          <span class="dashicons dashicons-desktop"></span> Desktop
        </span>
      </div>
    `;
    const mHtml = `<h3>Mobile results</h3>${r.data.mobile}`;
    const dHtml = `<h3>Desktop results</h3>${r.data.desktop}`;

    // 3) inject into DOM
    $('#module2-results').html(
      tabs +
      `<div id="asset-mobile">${mHtml}</div>` +
      `<div id="asset-desktop" style="display:none;">${dHtml}</div>`
    );

    // remove any leftover failure banner / NA styling from a previous run
    $('#module2-results .wpsa-notice-failed').remove();
    $('#asset-mobile .wpsa-stat-card, #asset-desktop .wpsa-stat-card').removeClass('is-na').each(function () {
      this.style.background = ''; // wipe any stale inline background
    });


    // 4) colorize cards
    applyColor($('#asset-mobile'));
    applyColor($('#asset-desktop'));
    
    // 4.1) Put the Onload lists under their cards (same column)
        (function slotOnloadLists(){
          function slot(paneId, strat){
            var $pane   = $(paneId);
            if (!$pane.length) return;
        
            var $jsCard  = $pane.find('.wpsa-card-onload-js');
            var $cssCard = $pane.find('.wpsa-card-onload-css');
        
            // find the <details> blocks by the UL ids we output from PHP
            var $jsDet  = $pane.find('#wpsa-onjs-'  + strat).closest('details');
            var $cssDet = $pane.find('#wpsa-oncss-' + strat).closest('details');
        
            // wrap card → stack container, then drop the matching <details> underneath it
            if ($jsCard.length && $jsDet.length) {
              if (!$jsCard.parent().hasClass('wpsa-card-stack')) {
                $jsCard.wrap('<div class="wpsa-card-stack"></div>');
              }
              $jsCard.after($jsDet);
            }
            if ($cssCard.length && $cssDet.length) {
              if (!$cssCard.parent().hasClass('wpsa-card-stack')) {
                $cssCard.wrap('<div class="wpsa-card-stack"></div>');
              }
              $cssCard.after($cssDet);
            }
          }
        
          // do both panes
          slot('#asset-mobile','mobile');
          slot('#asset-desktop','desktop');
        })();
        
        // --- Reconcile card counts with actual list lengths (keeps UI honest) ---
            [['mobile','#asset-mobile'],['desktop','#asset-desktop']].forEach(function(pair){
              var strat  = pair[0], paneId = pair[1];
              var $pane  = $(paneId);
              if (!$pane.length) return;
            
              var jsCt  = $pane.find('#wpsa-onjs-'  + strat + ' li').length;
              var cssCt = $pane.find('#wpsa-oncss-' + strat + ' li').length;
            
              if (jsCt)  { $pane.find('.wpsa-card-onload-js  .value').text(jsCt); }
              if (cssCt) { $pane.find('.wpsa-card-onload-css .value').text(cssCt); }
            
              // repaint thresholds/checkmarks after changing the numbers
              applyColor($pane);
            });



    // 5) capture only the numbers needed for Summary (requests & page size)
    //    (Onload cards are already colored via applyColor(); we don’t log any counts here.)
    const reqM = parseInt($('#asset-mobile  .value').eq(0).text(), 10);
    const szM  = parseFloat($('#asset-mobile  .value').eq(1).text());
    const reqD = parseInt($('#asset-desktop .value').eq(0).text(), 10);
    const szD  = parseFloat($('#asset-desktop .value').eq(1).text());
    
    if (Number.isFinite(reqM) && Number.isFinite(szM)) {
      window._wpsa_mod2_data.mobile = { requests: reqM, page_size: szM };
    } else {
      window._wpsa_mod2_data.mobile = null;
    }
    if (Number.isFinite(reqD) && Number.isFinite(szD)) {
      window._wpsa_mod2_data.desktop = { requests: reqD, page_size: szD };
    } else {
      window._wpsa_mod2_data.desktop = null;
    } 
  
    // Client logging removed — Module 2 is logged server-side inside wpsa_psi().
    // 6) show footnote
    $('#module2-footnote').show();
    
    // --- Put the footnote inside each pane's grid, above the onload triggers ---
        (function moveM2FootnoteIntoGrid(){
          var $global = $('#module2-footnote');                  // the original <p> we already print
          if (!$global.length) return;
          var html = $global.html();                             // keep the same text
        
          $('#asset-mobile .wpsa-stat-cards, #asset-desktop .wpsa-stat-cards').each(function(){
            var $grid = $(this);
            // don’t duplicate if we already added one
            if ($grid.children('.wpsa-m2-footnote').length) return;
        
            var $fn = $('<p class="wpsa-footnote wpsa-m2-footnote"/>').html(html);
        
           // make the footnote a *direct child* of the grid; CSS puts it on row 2
            $grid.append($fn);
          });
        
          // keep the original out-of-grid copy hidden to avoid a duplicate
          $global.hide();
        })();



    // finally, trigger completion for Module 6/7
    $(document).trigger('wpsa_module2_done');
  })
  .fail(function(xhr, status){
    // hide spinner & show warning
    $('#module2-running').hide();
    $('.wpsa-module-2')
      .removeClass('wpsa-hot')
      .css('background', '');   // ← clear inline tint
    $('#module2-results').html(
      `<div class="notice notice-warning"><p>
         Test failed (HTTP ${xhr.status||status}). Please wait a moment and retry.
       </p></div>`
    );
    // network failure → no cooldown
   wpsa_clearCooldown();
    // Client logging removed to avoid WAF blocks. Module 7 can still proceed.
    $(document).trigger('wpsa_module2_done');
  });
}



  // Module 5: Performance & Diagnostics
    if ( _wpsa_shouldRun && $('#module5-running').length) {
      var wpsaHotBg = WPSA_COLORS.hot;
      $('.wpsa-module-5')
        .addClass('wpsa-hot')
        .css({ background: wpsaHotBg, 'border-radius': '15px' });
    
      $('#module5-running').show();
      $('#perf-mobile .wpsa-subheading').show();
      $('#perf-desktop .wpsa-subheading').hide();

      // New run → clear any old PSI screenshots
      wpsa_updatePsiScreenshot('mobile',  '');
      wpsa_updatePsiScreenshot('desktop', '');


   ['mobile','desktop'].forEach(function(strat){

    var $meta     = $('#wpsa-run-meta');
    var ajaxTestNo = window._wpsa_testNo || parseInt($meta.data('test-no') || 0, 10) || 0;

    $.post(ajaxurl,{
      action:      'wpsa_performance',
      _ajax_nonce: wpsaData.perfNonce,
      test_url:    $('input[name="test_url"]').val(),
      strategy:    strat,
      test_no:     ajaxTestNo
    })

    
    .done(function(r){
      var d = (r.success && r.data) ? r.data : null;

      if (!d) {
        window._wpsa_perf[strat] = {
          score:'N/A',
          lcp:'N/A',
          fcp:'N/A',
          cls:'N/A',
          inp:'N/A',
          diagnostics:[{
            title:'Module 5 ' + strat + ' failed. Please retry.',
            value:'',
            severity:'high',
            num:0
          }],
          insights: []
        };

        // No data → also clear any stale PSI screenshot for this strategy
        wpsa_updatePsiScreenshot(strat, '');
        return;
      }

      //  If backend sent a PSI screenshot, update the preview for this strategy
      try {
        var shot = null;

        // Preferred: explicit data URL string from backend (what diagnostics.php sends)
        if (typeof d.psi_screenshot === 'string' && d.psi_screenshot.length) {
          shot = d.psi_screenshot;
        } else if (typeof d.screenshot_data_url === 'string' && d.screenshot_data_url.length) {
          // future-proof: alternate key names
          shot = d.screenshot_data_url;
        } else if (typeof d.screenshotDataUrl === 'string' && d.screenshotDataUrl.length) {
          shot = d.screenshotDataUrl;
        } else if (typeof d.screenshot === 'string' && d.screenshot.length) {
          shot = d.screenshot;
        } else if (d.psi_screenshot && typeof d.psi_screenshot === 'object') {
          // legacy shape: { psi_screenshot: { data_url: 'data:image/...' } }
          if (typeof d.psi_screenshot.data_url === 'string') {
            shot = d.psi_screenshot.data_url;
          } else if (typeof d.psi_screenshot.dataUrl === 'string') {
            shot = d.psi_screenshot.dataUrl;
          }
        }

        if (shot) {
          // Expecting a data:image/...;base64,... URL
          wpsa_updatePsiScreenshot(strat, shot);
        } else {
          // No screenshot for this run → clear any stale one
          wpsa_updatePsiScreenshot(strat, '');
        }
      } catch (e) {}



      // --- Normalize diagnostics / opportunities shape -------------------
      // Server may send:
      //   { diagnostics:[...], insights:[...] }
      // or
      //   { opportunities:[...], insights:[...] }
      // We always want `.diagnostics` + `.insights` for renderPerf().
      (function normalizeDiagShape(){
        function arr(v){ return Array.isArray(v) ? v : []; }
        var diag = arr(d.diagnostics || d.opportunities);
        var ins  = arr(d.insights);
        d.diagnostics = diag;
        d.insights    = ins;
      })();
    
      // --- Normalize INP to a display string the renderer understands ---
      // Prefer milliseconds. Accept a few common server key variants.
      if (d.inp == null || d.inp === '') {
        if (typeof d.inp_ms === 'number')        d.inp = Math.round(d.inp_ms) + ' ms';
        else if (typeof d.inpMs === 'number')    d.inp = Math.round(d.inpMs) + ' ms';
        else if (typeof d.inp === 'number')      d.inp = Math.round(d.inp) + ' ms';
        else if (typeof d.inp_s === 'number')    d.inp = (d.inp_s).toString() + ' s';
        // if still nothing, it’ll render as N/A
      }
    
      window._wpsa_perf[strat] = d;
    })


        .fail(function(xhr, status, err){
      // On error, clear screenshot preview for this strategy
      wpsa_updatePsiScreenshot(strat, '');

      // HTTP/network error guard:
      window._wpsa_perf[strat] = {
        score:'N/A',
        lcp:'N/A',
        fcp:'N/A',
        diagnostics:[
          {title:'Module 5 '+strat+' failed (HTTP '+(xhr.status||status)+'). Please retry.',
           value:'',
           severity:'high',
           num:0}
        ]
      };
    })

    .always(function(){
        // if we just loaded a test from the log, don't overwrite it
      if (window._wpsa_loaded_from_log) return;
      // After either .done or .fail, check if both done:
      if (window._wpsa_perf.mobile && window._wpsa_perf.desktop && !window._wpsa_perf.logged) {
        window._wpsa_perf.logged = true;
        // If M2 didn't start cooldown, do it now (we clearly progressed)
         if (!window._wpsa_cdStarted) { wpsa_beginCooldown(60); window._wpsa_cdStarted = true; }
        
        $('#module5-running').hide();
        $('.wpsa-module-5')
          .removeClass('wpsa-hot')
          .css('background', '');   // ← clear inline tint
        $('#wpsa-module5').show();

        $('#perf-mobile .wpsa-subheading').show();
        $('#perf-desktop .wpsa-subheading').hide();

     // render both strategies
        renderPerf(window._wpsa_perf.mobile,'mobile');
        renderPerf(window._wpsa_perf.desktop,'desktop');

        // rebuild Diagnostics (left column) from the same in-memory data
        try { wpsa_renderM5DiagFromState(); } catch (e) {}

        // hide/show the Module-5 warning depending on real data
        _wpsa_updateMod5Notice();


    // pick a sensible default for Module 6 summary
    (function pickSummaryStrat(){
      var mobileOK  = _wpsa_hasValidPerf(window._wpsa_perf.mobile);
      var desktopOK = _wpsa_hasValidPerf(window._wpsa_perf.desktop);
      if (_wpsa_summary_strat === 'mobile' && !mobileOK && desktopOK) {
        _wpsa_summary_strat = 'desktop';
      } else if (_wpsa_summary_strat === 'desktop' && !desktopOK && mobileOK) {
        _wpsa_summary_strat = 'mobile';
      }
    })();

        // log them (now with CLS and INP). Normalize to numbers-only so Load-test regex matches.
        (function(){
          function numOrNA(val){
            var m = String(val || '').match(/([\d.,]+)/);
            if (!m) return 'N/A';
            var v = parseFloat(m[1].replace(',', '.'));
            return isFinite(v) ? String(v) : 'N/A';
          }
          function inpMs(val){
            // Accept "220 ms", "0.22 s", "220", etc → integer ms or "N/A"
            var m = String(val || '').match(/([\d.,]+)\s*(ms|s)?/i);
            if (!m) return 'N/A';
            // "1,910" is a thousands separator (PSI formats TBT that way once it
            // crosses 1000 ms); "0,22" is a decimal comma. Only the fully-grouped
            // form counts as thousands. This value is written to the results log,
            // so a misread would be persisted.
            var v = parseFloat(/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(m[1])
                      ? m[1].replace(/,/g, '')
                      : m[1].replace(',', '.'));
            if (!isFinite(v)) return 'N/A';
            var unit = (m[2] || 'ms').toLowerCase();
            return String(unit === 's' ? Math.round(v * 1000) : Math.round(v));
          }

          var mob = window._wpsa_perf.mobile  || {};
          var des = window._wpsa_perf.desktop || {};

          var mobLcp = numOrNA(mob.lcp), mobFcp = numOrNA(mob.fcp),
              mobCls = numOrNA(mob.cls), mobInp = inpMs(mob.inp);

          var desLcp = numOrNA(des.lcp), desFcp = numOrNA(des.fcp),
              desCls = numOrNA(des.cls), desInp = inpMs(des.inp);

        var $meta     = $('#wpsa-run-meta');
        var testNo    = parseInt($meta.data('test-no') || 0, 10) || 0;
        var testedUrl = $meta.data('tested-url') || $('input[name="test_url"]').val() || '';
        
        $.post(ajaxurl,{
          action:      'wpsa_log_module5',
          _ajax_nonce: wpsaData.perfNonce,
        
          // NEW — attach correctly to the right Test #
          test_no:     testNo,
          tested_url:  testedUrl,
        
          // unchanged payload
          mobile:  `Module 5 Mobile: Performance: ${mob.score}, LCP: ${mobLcp}, FCP: ${mobFcp}, CLS: ${mobCls}, INP: ${mobInp}\n`,
          desktop: `Module 5 Desktop: Performance: ${des.score}, LCP: ${desLcp}, FCP: ${desFcp}, CLS: ${desCls}, INP: ${desInp}\n`
        });

        })();

        
       // log Module 5 diagnostics (top-5 per strategy) — split fields + legacy
        try {
          var m  = window._wpsa_perf.mobile  || {};
          var d  = window._wpsa_perf.desktop || {};
          var mOp = Array.isArray(m.diagnostics) ? m.diagnostics.slice(0,10) : []; // left
          var mIn = Array.isArray(m.insights)    ? m.insights.slice(0,10)    : []; // right
          var dOp = Array.isArray(d.diagnostics) ? d.diagnostics.slice(0,10) : [];
          var dIn = Array.isArray(d.insights)    ? d.insights.slice(0,10)    : [];
        
          $.post(ajaxurl, {
            action:     'wpsa_log_module5_diag',
            _ajax_nonce: wpsaData.perfNonce,
            test_no:    window._wpsa_testNo || 0,
            tested_url: $('input[name="test_url"]').val() || '',
            // NEW split fields
            mobile_op:  JSON.stringify(mOp),
            mobile_in:  JSON.stringify(mIn),
            desktop_op: JSON.stringify(dOp),
            desktop_in: JSON.stringify(dIn),
            // Legacy fields (server keeps back-compat)
            mobile:     JSON.stringify(mOp),
            desktop:    JSON.stringify(dOp)
          });
        } catch (e) {}

        // fire the event so summary can move on
        $(document).trigger('wpsa_module5_logged');
      }
    });
  });
}

// Helper: rebuild Module 5 Diagnostics (left column) from window._wpsa_perf
function wpsa_renderM5DiagFromState() {
  if (!window._wpsa_perf) return;

  ['mobile','desktop'].forEach(function (strat) {
    var perf = window._wpsa_perf[strat] || {};
    var list = Array.isArray(perf.diagnostics) ? perf.diagnostics : [];

    // pick correct pane
    var $root = (strat === 'mobile') ? jQuery('#perf-mobile') : jQuery('#perf-desktop');
    if (!$root.length) return;

    var $ul = $root.find('ul.diagnostics');
    if (!$ul.length) return;

    // use the existing LI (with badge + .title + .value) as a template
    var $proto = $ul.find('li').first().clone();

    // reset template to a neutral state so we don't keep old text
    $proto.removeClass('diag-empty');
    $proto.find('.wpsa-badge').text('');
    $proto.find('.title').text('');
    $proto.find('.value').text('');

    $ul.empty();

    var items = list.slice(0, 5);
    if (!items.length) {
      // fall back to the original “No items…” row
      var $empty = $proto.clone().addClass('diag-empty');
      $empty.find('.wpsa-badge').text('0');
      $empty.find('.title').text('No items were returned for this run.');
      $empty.find('.value').text('');
      $ul.append($empty);
      return;
    }

    items.forEach(function (item, idx) {
      var $li = $proto.clone().removeClass('diag-empty');

      // badge shows ordinal 1–5
      $li.find('.wpsa-badge').text(idx + 1);

      var title = item && item.title ? String(item.title) : '';
      var value = item && item.value ? String(item.value) : '';

      // title only = the audit name
      $li.find('.title').text(title || 'N/A');

      // value span only = the savings text (if present)
      $li.find('.value').text(value ? ' — ' + value : '');

      $ul.append($li);
    });
  });
}



    // Module 7: Conclusion (prepare tint on auto-run; visibility is handled later)
    if ( _wpsa_shouldRun && $('#module7-running').length ) {
      var wpsaHotBg = WPSA_COLORS.hot7;
      $('#module7-wrapper')
        .addClass('wpsa-hot')
        .css({ background: wpsaHotBg, 'border-radius': '15px' });
      // do NOT .show() here — Module 6 will reveal it inside tryUpdateSummary()
    }


  
// Module 6: Summary & Recommendations
  function updateSummaryCards(){
    // 🔄 first remove any existing checkmarks in summary cards
    $('#summary-module_1 .value .icon,' +
      '#summary-module_2 .value .icon,' +
      '#summary-onload-js .value .icon,' +     // 1.16.2
      '#summary-onload-css .value .icon,' +    // 1.16.2
      '#summary-module_3 .value .icon,' +
      '#summary-module_4 .value .icon,' +
      '#summary-module_5 .psi-half .icon'
    ).remove();
    
    // 1. Update the subheading
    $('.summary-subheading').text(
      _wpsa_summary_strat==='mobile' ? 'Mobile results' : 'Desktop results'
    );

    // 2. Update each card
    var strat   = _wpsa_summary_strat,
        testUrl = $('input[name="test_url"]').val(),
       isLocal = (function(u){
                  try{
                    var h  = new URL(u).hostname.toLowerCase();
                    var me = window.location.hostname.toLowerCase();
                    return h === me || h.endsWith('.'+me) || me.endsWith('.'+h);
                  }catch(e){ return true; } // be permissive if parsing fails
                })(testUrl);

    // Card 1: Server TTFB
    var ttfbRaw = ($('.wpsa-module-1 table tbody tr td').last().text() || '').trim();
    var ttfbNum = parseInt(ttfbRaw, 10);
    var isNA    = !Number.isFinite(ttfbNum);
    
    var bg1 = isNA ? WPSA_COLORS.na
             : (ttfbNum <= WPSA_THRESHOLDS.ttfb.good ? WPSA_COLORS.green
               : ttfbNum <= WPSA_THRESHOLDS.ttfb.ok  ? WPSA_COLORS.amber
               :                                       WPSA_COLORS.red);
    
    $('#summary-module_1')
      .toggleClass('is-na', isNA)
      .css('background', bg1)
      .find('.value').text(isNA ? 'N/A' : (ttfbNum + ' ms'));
        if (bg1===WPSA_COLORS.green) {
     // ✅ already coming from Module 1 output, so no extra append here
    }

    // Card 2: Requests & Page Size
    var mod2 = window._wpsa_mod2_data[strat],
        txt2 = !mod2 ? 'N/A' : mod2.requests+' R, '+mod2.page_size+' KB',
         THm2 = WPSA_THRESHOLDS.module2;
         bg2  = !mod2 ? WPSA_COLORS.na
        : (mod2.requests <= THm2.requests.good ? WPSA_COLORS.green
          : mod2.requests <= THm2.requests.ok ? WPSA_COLORS.amber
            :                                     WPSA_COLORS.red);
    $('#summary-module_2')
  .toggleClass('is-na', !mod2)
  .css('background', bg2)
  .find('.value').text(txt2).end()
  .find('.icon').remove(); // keep it clean if NA

    
    if (bg2 === WPSA_COLORS.green) {
      $('#summary-module_2 .value')
        .append(' <span class="icon">✅</span>');
    }

    // Card 3: Autoloaded Options
    var a3Val='N/A', bg3=WPSA_COLORS.na;
    if (isLocal){
      a3Val = ($('.wpsa-card-autoload .value').text() || '').trim();
      // accept "442.7 KB" → number
      var m = a3Val.match(/([\d.,]+)/);
      var a3n = m ? parseFloat(m[1].replace(',', '.')) : NaN;
      if (Number.isFinite(a3n)) {
        bg3 = (a3n <= WPSA_THRESHOLDS.summary.autoloadKB.good) ? WPSA_COLORS.green
            : (a3n <= WPSA_THRESHOLDS.summary.autoloadKB.ok)   ? WPSA_COLORS.amber
            :                                                    WPSA_COLORS.red;
        a3Val = a3n.toFixed(1) + ' KB';
      } else {
        a3Val = 'N/A';
      }
    }
    $('#summary-module_3')
      .toggleClass('is-na', a3Val==='N/A')
      .css('background', bg3)
      .find('.value').text(a3Val);

     // ✅ already coming from Module 3 output, so no extra append here

    // Card 4: Persistent Cache
    // — strip out any trailing “✅” so we match exactly “Yes” or “No”
    var raw   = $('.wpsa-card-objectcache .value').text() || 'N/A',
        m     = raw.match(/^(Yes|No)/),
        o4Val = m ? m[1] : 'N/A',
        bg4   = (o4Val === 'Yes') ? WPSA_COLORS.green
       : (o4Val === 'No')  ? WPSA_COLORS.red
                          : WPSA_COLORS.na;
    $('#summary-module_4')
      .css('background', bg4)
      .find('.value').text(o4Val);

    if ( bg4 === WPSA_COLORS.green && !$('#summary-module_4 .value .icon').length ) {
      $('#summary-module_4 .value')
        .append(' <span class="icon">✅</span>');
    }
    
    function stripCheckmark(s){
      return (s || 'N/A').toString().replace(/\s*✅\s*$/, '');
    }

    // Card 5: LCP & FCP
    var perf = window._wpsa_perf[strat] || {},
        lcp  = stripCheckmark(perf.lcp),
        fcp  = stripCheckmark(perf.fcp),
        ln   = parseFloat(lcp) || 0,
        fn   = parseFloat(fcp) || 0,
        bgL  = (lcp === 'N/A') ? WPSA_COLORS.na : (ln <= 2.5 ? WPSA_COLORS.green : ln <= 4 ? WPSA_COLORS.amber : WPSA_COLORS.red);
        bgF  = (fcp === 'N/A') ? WPSA_COLORS.na : (fn <= 1.8 ? WPSA_COLORS.green : fn <= 3 ? WPSA_COLORS.amber : WPSA_COLORS.red);
    
    // LCP
    var $lcpTile = $('#summary-module_5 .psi-half.lcp');
    $lcpTile.css('background', bgL).removeClass('na');
    $lcpTile.find('.value').html('<span class="score"></span>');   // hard reset
    $lcpTile.find('.score').text(lcp);
    if (lcp === 'N/A') {
      $lcpTile.addClass('na');
    } else if (bgL === WPSA_COLORS.green) {
      $lcpTile.find('.value').append(' <span class="icon">✅</span>');
    }
    
    // FCP
    var $fcpTile = $('#summary-module_5 .psi-half.fcp');
    $fcpTile.css('background', bgF).removeClass('na');
    $fcpTile.find('.value').html('<span class="score"></span>');   // hard reset
    $fcpTile.find('.score').text(fcp);
    if (fcp === 'N/A') {
      $fcpTile.addClass('na');
    } else if (bgF === WPSA_COLORS.green) {
      $fcpTile.find('.value').append(' <span class="icon">✅</span>');
    }
    
    // --- CLS / INP (Summary: #summary-module_5b) ---
    function parseMs(str){
      var m = String(str||'').match(/([\d.,]+)\s*(ms|s)?/i);
      if (!m) return NaN;
      // Thousands separator vs decimal comma — see inpMs() above.
      var v = parseFloat(/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(m[1])
                ? m[1].replace(/,/g, '')
                : m[1].replace(',', '.'));
      if (!isFinite(v)) return NaN;
      var u = (m[2]||'ms').toLowerCase();
      return u === 's' ? v*1000 : v;
    }
    
    var cls  = stripCheckmark(perf.cls),
        inp  = stripCheckmark(perf.inp);
    
    var clsN = parseFloat(String(cls).replace(',', '.'));
    var inpM = parseMs(inp);
    
    var bgC = (cls === 'N/A' || !isFinite(clsN)) ? WPSA_COLORS.na
         : (clsN <= 0.10 ? WPSA_COLORS.green : (clsN <= 0.25 ? WPSA_COLORS.amber : WPSA_COLORS.red));
    var bgI = (inp === 'N/A' || !isFinite(inpM)) ? WPSA_COLORS.na
         : (inpM <= 200 ? WPSA_COLORS.green : (inpM <= 500 ? WPSA_COLORS.amber : WPSA_COLORS.red));
    
    var $clsTile = $('#summary-module_5b .psi-half.cls');
    $clsTile.css('background', bgC).removeClass('na');
    $clsTile.find('.value').html('<span class="score"></span>');
    $clsTile.find('.score').text((cls === 'N/A' || !isFinite(clsN)) ? 'N/A' : cls);
    if (cls === 'N/A' || !isFinite(clsN)) {
      $clsTile.addClass('na');
    } else if (bgC === WPSA_COLORS.green) {
      $clsTile.find('.value').append(' <span class="icon">✅</span>');
    }
    
    var $inpTile = $('#summary-module_5b .psi-half.inp');
    $inpTile.css('background', bgI).removeClass('na');
    $inpTile.find('.value').html('<span class="score"></span>');
    $inpTile.find('.score').text((inp === 'N/A' || !isFinite(inpM)) ? 'N/A' : inp);
    if (inp === 'N/A' || !isFinite(inpM)) {
      $inpTile.addClass('na');
    } else if (bgI === WPSA_COLORS.green) {
      $inpTile.find('.value').append(' <span class="icon">✅</span>');
    }
    
    
    // ─────────────────────────────────────────────────────
    // 1.16.2: Onload JS / CSS (pulled from Module 2 DOM)
    // ─────────────────────────────────────────────────────
    var onJsTxt  = $('#asset-' + strat + ' .wpsa-card-onload-js .value').text().trim();
    var onCssTxt = $('#asset-' + strat + ' .wpsa-card-onload-css .value').text().trim();
    var onJsNum  = parseInt(onJsTxt, 10);
    var onCssNum = parseInt(onCssTxt, 10);

    // 1.16.7 thresholds → JS: ≤11 green, 12–16 amber, ≥17 red
    //               CSS: ≤15 green, 16–20 amber, ≥21 red
    var jsIsNum  = Number.isFinite(onJsNum);
    var cssIsNum = Number.isFinite(onCssNum);

    var THs = WPSA_THRESHOLDS;
    var jsBg = jsIsNum ? (onJsNum <= THs.onload.js.good ? WPSA_COLORS.green
                        : (onJsNum <= THs.onload.js.ok ? WPSA_COLORS.amber : WPSA_COLORS.red))
                      : WPSA_COLORS.na;
 var cssBg= cssIsNum ? (onCssNum <= THs.onload.css.good ? WPSA_COLORS.green
                        : (onCssNum <= THs.onload.css.ok ? WPSA_COLORS.amber : WPSA_COLORS.red))
                       : WPSA_COLORS.na;


    // Onload JS (Summary)
    var $sumJs = $('#summary-onload-js')
      .toggleClass('is-na', !jsIsNum)
      .css('background', jsBg);
    $sumJs.find('.value').text(jsIsNum ? onJsNum : 'N/A');
    if (jsBg === WPSA_COLORS.green && !$sumJs.find('.icon').length) {
      $sumJs.find('.value').append(' <span class="icon">✅</span>');
    }

    // Onload CSS (Summary)
    var $sumCss = $('#summary-onload-css')
      .toggleClass('is-na', !cssIsNum)
      .css('background', cssBg);
    $sumCss.find('.value').text(cssIsNum ? onCssNum : 'N/A');
    if (cssBg === WPSA_COLORS.green && !$sumCss.find('.icon').length) {
      $sumCss.find('.value').append(' <span class="icon">✅</span>');
    }

    
    // ─────────────────────────────────────────────────────
    // 4.5: Various other information (Active plugins / PHP / DB)
    // ─────────────────────────────────────────────────────
    function vcmp(a,b){a=(a||'').split('.');b=(b||'').split('.');for(var i=0;i<Math.max(a.length,b.length);i++){var x=parseInt(a[i]||'0',10),y=parseInt(b[i]||'0',10);if(x>y)return 1;if(x<y)return -1;}return 0;}
    
    var plugVal='N/A', phpVal='N/A', dbVendor='N/A', dbVer='N/A';
    var bgPlug='#eeeeee', bgPhp='#eeeeee', bgDb='#eeeeee';
    var plugCnt = NaN, phpMajor = NaN;
    
    if (isLocal) {
      // Active plugins
      var $plugSrc = $('.wpsa-card-plugins .value');
      if ($plugSrc.length) {
        plugCnt = parseInt($plugSrc.text(), 10);
        if (!isNaN(plugCnt)) {
          plugVal = String(plugCnt);
          bgPlug  = plugCnt <= 40 ? WPSA_COLORS.green : (plugCnt <= 55 ? WPSA_COLORS.amber : WPSA_COLORS.red);
        }
      }
    
      // PHP version
      var $phpSrc = $('.wpsa-card-php .value');
      if ($phpSrc.length) {
        phpVal = $phpSrc.text().replace('✅','').trim();
        var mPhp = phpVal.match(/^(\d+(?:\.\d+)?)/);
        phpMajor = mPhp ? parseFloat(mPhp[1]) : NaN;
        if (!isNaN(phpMajor)) {
          bgPhp = phpMajor >= 8.0 ? WPSA_COLORS.green : WPSA_COLORS.red;
        }
      }
    
      // DB server — try the top card first, then fall back to the 4.5 grid card
      (function readDbFromDom(){
        var $dbTop = $('.wpsa-card-db .value');
        if ($dbTop.length) {
          dbVendor = $dbTop.clone().children().remove().end().text().trim() || 'N/A';
          dbVer    = $dbTop.find('.subvalue').text().trim() || 'N/A';
        }
        if ((dbVendor === 'N/A' || !dbVendor) || (dbVer === 'N/A' || !dbVer)) {
          var $gridDb = $('.wpsa-module-3-4 .wpsa-meta-card').filter(function(){
            return /DB server/i.test($(this).find('.header').text());
          }).first();
          if ($gridDb.length) {
            var txt = $gridDb.find('.value').text().trim(); // e.g. "MariaDB 11.8.3" or "N/A"
            var m   = txt.match(/^([A-Za-z0-9._-]+)\s+([\d.]+)/);
            if (m) { dbVendor = m[1]; dbVer = m[2]; }
          }
        }
      })();
    
      if (dbVendor !== 'N/A' && dbVer !== 'N/A') {
        if (/mariadb/i.test(dbVendor)) {
          bgDb = vcmp(dbVer,'10.6') >= 0 ? WPSA_COLORS.green : (vcmp(dbVer,'10.4') >= 0 ? WPSA_COLORS.amber : WPSA_COLORS.red);
        } else {
         bgDb = vcmp(dbVer,'8.0') >= 0 ? WPSA_COLORS.green : WPSA_COLORS.red;
        }
      } else {
        // make sure N/A shows neutral grey
        bgDb = WPSA_COLORS.na;
      }
    }
    
    // Paint the Summary row (4.5)
    var $p45 = $('#summary-45-plugins')
      .toggleClass('is-na', plugVal==='N/A')
      .css('background', bgPlug)
      .find('.value').text(plugVal).end();
    if (bgPlug === WPSA_COLORS.green && !$p45.find('.icon').length) {
      $p45.find('.value').append(' <span class="icon">✅</span>');
    }
    
    var $php45 = $('#summary-45-php')
      .toggleClass('is-na', phpVal==='N/A')
      .css('background', bgPhp)
      .find('.value').text(phpVal).end();
    if (bgPhp === WPSA_COLORS.green && !$php45.find('.icon').length) {
      $php45.find('.value').append(' <span class="icon">✅</span>');
    }

       // --- DB Summary tile: single checkmark, no stray icons ---
    // Strip any upstream “✅” from vendor/version first
    var cleanVendor = (dbVendor || '').replace(/✅/g, '').trim();
    var cleanVer    = (dbVer    || '').replace(/✅/g, '').trim();

    var dbText = (cleanVendor === 'N/A' || cleanVer === 'N/A')
      ? 'N/A'
      : (cleanVendor + ' ' + cleanVer);

    var $db45 = $('#summary-45-db')
      .toggleClass('is-na', dbText === 'N/A')
      .css('background', bgDb);

    // Base text (no icons)
    $db45.find('.value').text(dbText);

    // If DB tile is green, append exactly ONE ✅ at the end
    if (dbText !== 'N/A' && bgDb === WPSA_COLORS.green) {
      $db45.find('.value .icon').remove(); // safety: remove any leftovers
      $db45.find('.value').append(' <span class="icon">✅</span>');
    }






    // 3) BUILD THE RECOMMENDATIONS
    var recs = [],
        // already have ttfbRaw/ttfbNum above
        mod2S   = window._wpsa_mod2_data[_wpsa_summary_strat] || {},
        perf    = window._wpsa_perf[_wpsa_summary_strat] || {},
        ln      = parseFloat(perf.lcp) || 0,
        fn      = parseFloat(perf.fcp) || 0;
    
    if (ttfbNum > 500)                      recs.push('Improve your server response time');
    var TH = WPSA_THRESHOLDS;

    if (mod2S.requests   > TH.rec.req_bad)      recs.push('Reduce number of HTTP requests');
    if (mod2S.page_size  > TH.rec.size_bad)     recs.push('Decrease overall page size');
    if (isLocal && parseFloat(a3Val) > TH.rec.autoload_bad) recs.push('Reduce autoloaded options size');
    if (isLocal && o4Val === 'No')              recs.push('Enable persistent object cache');
    var _scoreNum = Number(perf.score);
     if (Number.isFinite(_scoreNum) && _scoreNum < 50) recs.push('Optimize critical rendering path');
    if (ln > TH.rec.lcp_bad)                    recs.push('Decrease LCP');
    if (fn > TH.rec.fcp_bad)                    recs.push('Decrease FCP');

    // ✅ add CLS / INP
    if (isFinite(clsN) && clsN > TH.rec.cls_bad) recs.push('Reduce layout shifts (CLS)');
    if (isFinite(inpM) && inpM > TH.rec.inp_bad) recs.push('Improve interaction latency (INP)');

    // Onloads
    if (jsIsNum  && onJsNum  > TH.rec.onjs_bad)  recs.push('Reduce onload JavaScript files');
    if (cssIsNum && onCssNum > TH.rec.oncss_bad) recs.push('Reduce onload CSS files');

    
    // 4.5-driven recommendations (only when same-server and not N/A)
    if (isLocal && !isNaN(plugCnt) && bgPlug !== '#c6f7c3') {
      recs.push('Reduce active plugins');
    }
    if (isLocal && !isNaN(phpMajor) && bgPhp !== '#c6f7c3') {
      recs.push('Upgrade PHP to 8.0+ (preferably 8.2/8.3)');
    }
    if (isLocal && dbText !== 'N/A' && bgDb !== '#c6f7c3') {
      if (/mariadb/i.test(dbVendor) && bgDb === '#fef6b3') {
        recs.push('Consider upgrading database to MariaDB 10.6+');
      } else {
        recs.push('Upgrade database server (' + (/mariadb/i.test(dbVendor) ? 'MariaDB 10.6+' : 'MySQL 8.0+') + ')');
      }
    }
    
    // de-dupe while preserving order
    (function dedupe(){
      var seen = new Set(), out = [];
      recs.forEach(function(r){ if(!seen.has(r)){ seen.add(r); out.push(r); } });
      recs = out;
    })();
    
       // 4) SHOW/HIDE THE RECOMMENDATIONS  (two columns, max 5 per column)
    (function renderRecommendations(){
      var $wrap = $('#summary-recommendations');
      if (!$wrap.length) return;

      // ← use the ALREADY-built `recs` from above (the one we deduped there)
      var list = Array.isArray(recs) ? recs.slice() : [];

      // final guard dedupe (in case updateSummaryCards() is called twice fast)
      (function dedupeFinal(){
        var seen = new Set(), out = [];
        list.forEach(function(r){
          if (!seen.has(r)) { seen.add(r); out.push(r); }
        });
        list = out;
      })();

      // Prepare columns (create if missing)
      if (!$wrap.find('.recom-col').length) {
        $wrap.html(
          '<ul class="recom-col col-left"></ul>' +
          '<ul class="recom-col col-right" style="display:none;"></ul>'
        );
      }
      var $left  = $wrap.find('.col-left').empty();
      var $right = $wrap.find('.col-right').empty();

      if (list.length) {
        list.forEach(function(text, i){
          var $li = $('<li/>');
          // ALWAYS prepend exactly one bulb
          $li.append('<span class="rec-icon" aria-hidden="true">💡</span>');
          $li.append(document.createTextNode(' ' + text));
          (i < 5 ? $left : $right).append($li);
        });
        $right.toggle(list.length > 5);
        $wrap.show().prev('h3').show();
        $('.wpsa-module-6 .wpsa-footnote').show();
      } else {
        $wrap.hide().prev('h3').hide();
        $('.wpsa-module-6 .wpsa-footnote').hide();
      }
    })();

  
      // Final safety: never allow more than one ✅ per half
        $('#summary-module_5 .psi-half .value').each(function(){
         $(this).find('.icon').not(':first').remove();
        });
        // Final-final: make sure each recommendation line has a 💡
        wpsa_forceRecomIcons(jQuery('#summary-recommendations'));

        
    }  // end updateSummaryCards()
    
    /* === HElper 1.16.7 - Module 6: ensure 💡 icons exist on every recommendation line === */
    function wpsa_forceRecomIcons(scope){
      var $wrap = (scope && scope.length) ? scope : jQuery('#summary-recommendations');
      if (!$wrap.length) return;
      $wrap.find('li').each(function(){
        var $li = jQuery(this);
        if (!$li.find('.rec-icon').length) {
          $li.prepend('<span class="rec-icon" aria-hidden="true">💡</span> ');
        }
      });
    }
    
    /* Auto-repair bulbs if Module 6 is re-rendered by tab switches/nav */
    (function(){
      var target = document.getElementById('summary-recommendations');
      if (!target || !window.MutationObserver) return;
    
      var obs = new MutationObserver(function(){
        // If anything in the list changes, ensure each <li> has the 💡
        wpsa_forceRecomIcons(jQuery(target));
      });
    
      obs.observe(target, { childList: true, subtree: true });
    
      // Initial pass (covers the “return from Compare” case)
      wpsa_forceRecomIcons(jQuery(target));
    })();
    
    
    /*Helper for test URL*/
     function wpsa_ensureTestedUrlEl() {
     if (!$('#wpsa-tested-url').length) {
       $('<div id="wpsa-tested-url" class="wpsa-tested-url" />')
         .insertAfter('input[name="test_url"]');
     }
   }
   
   
    // Only reveal module 6 after BOTH module 2 & module 5 finish,
    // then trigger Module 7 via AJAX
    
    function wpsa_renderTestedUrlLabel() {
      var tested = ($('input[name="test_url"]').val() || '').trim();
      var n      = window._wpsa_testNo || 0;
      var isS    = !!window._wpsa_is_scheduled;
    
      if (tested) {
        var suffix = isS ? ' S' : '';     // <-- NOTE space before S
        var prefix = n ? ('Test #' + n + suffix + ', ') : '';
        var label  = prefix + 'tested URL: ' +
                     '<a href="' + tested + '" target="_blank" rel="noopener noreferrer">' + tested + '</a>';
        $('#wpsa-tested-url').html(label).show();
      } else {
        $('#wpsa-tested-url').hide().empty();
      }
    }




    // Fill the "Test #…, tested URL: …" line immediately from Module 1 meta
    function wpsa_pickRunMetaFromModule1() {
    var $meta = jQuery('#wpsa-run-meta');
    if (!$meta.length) return;

    var preserveScheduled = false;

    // If the user just loaded a test from the log, we already know the correct S-flag.
    // Don't overwrite it here.
    try {
        var st = JSON.parse(sessionStorage.getItem('WPSA_TEST_STATE') || '{}');
        if (st && st.origin === 'load') { preserveScheduled = true; }
    } catch (e) {}

    var testNo  = parseInt($meta.attr('data-test-no'), 10) || 0;
    var finalUrl = ($meta.attr('data-tested-url') || '').trim();

    if (testNo > 0) { window._wpsa_testNo = testNo; }
    if (!preserveScheduled) { window._wpsa_is_scheduled = false; }

    if (finalUrl) {
        jQuery('input[name="test_url"]').val(finalUrl);
        if (typeof wpsa_renderTestedUrlLabel === 'function') {
            wpsa_renderTestedUrlLabel();
        }
    }

    // Module 1: Response headers
    (function () {
        var rawHeaders = '';
        var headersObj = null;

        try {
            rawHeaders = ($meta.attr('data-response-headers') || '').trim();
        } catch (e) { rawHeaders = ''; }

        if (!rawHeaders) {
            try {
                var $wrap = jQuery('#wpsa-m1-respheaders-wrap');
                rawHeaders = ($wrap.attr('data-response-headers') || '').trim();
            } catch (e) { rawHeaders = ''; }
        }

        if (rawHeaders) {
            try { headersObj = JSON.parse(rawHeaders); } catch (e) { headersObj = null; }
        }

        // Fallback: if PHP didn't embed headers, try to pull them from the log for this Test #
        if (!headersObj && testNo > 0 && window.wpsaData && wpsaData.loadTestNonce) {
            if (window._wpsa_m1_hdr_fetched_for === testNo) {
                // do nothing (already attempted)
            } else {
                window._wpsa_m1_hdr_fetched_for = testNo;

                jQuery.post(ajaxurl, {
                    action: 'wpsa_load_test',
                    nonce: wpsaData.loadTestNonce,
                    test_no: testNo
                }).done(function (r) {
                    try {
                        if (!r || !r.success || !r.data) { return; }

                        var lines = r.data.lines || [];
                        if (!Array.isArray(lines) || !lines.length) { return; }

                        // Narrow to the requested Test # block
                        var out = [];
                        var inBlock = false;
                        var reStart = new RegExp('^\\s*Test\\s+#' + testNo + '\\b');
                        var reAnyStart = /^\\s*Test\\s+#\\d+\\b/;

                        for (var i = 0; i < lines.length; i++) {
                            var ln = (lines[i] || '').toString();
                            if (!inBlock && reStart.test(ln)) { inBlock = true; }
                            if (inBlock) {
                                if (out.length && reAnyStart.test(ln) && !reStart.test(ln)) { break; }
                                out.push(ln);
                            }
                        }

                        // Try to find a JSON payload on any line that includes headers info
                        var hObj = null;
                        for (var j = 0; j < out.length; j++) {
                            var s = (out[j] || '').toString().trim();
                            if (!s) continue;

                            var hasHint =
                                /response\s*headers|respheaders|module\s*1.*headers|http\s*headers/i.test(s) ||
                                s.indexOf('"response_headers"') !== -1;

                            if (!hasHint) continue;

                            var a = s.indexOf('{');
                            var b = s.lastIndexOf('}');
                            if (a === -1 || b <= a) continue;

                            var jsonStr = s.slice(a, b + 1);

                            try {
                                var parsed = JSON.parse(jsonStr);

                                // If the line is the full Worker JSON, pull the nested headers object.
                                if (parsed && typeof parsed === 'object' && parsed.response_headers && typeof parsed.response_headers === 'object') {
                                    hObj = parsed.response_headers;
                                } else if (parsed && typeof parsed === 'object') {
                                    hObj = parsed;
                                } else {
                                    hObj = null;
                                }
                            } catch (e) {
                                hObj = null;
                            }

                            if (hObj) break;
                        }

                        if (hObj) {
                            var raw = JSON.stringify(hObj);
                            jQuery('#wpsa-run-meta').attr('data-response-headers', raw);
                            jQuery('#wpsa-m1-respheaders-wrap').attr('data-response-headers', raw);
                            wpsaRenderResponseHeaders(hObj);
                        }
                    } catch (e) {}
                });
            }
        } else {
            try { wpsaRenderResponseHeaders(headersObj); } catch (e) {}
        }
    })();

    // Mark module 1 ready (freshness / downstream listeners)
    try { jQuery(document).trigger('wpsa_module1_done'); } catch (e) {}
}

// Run once here (function is defined only at this point).
// This fixes the “called before definition (swallowed)” issue in wpsa_restoreTestStateIfEmpty().
try { wpsa_pickRunMetaFromModule1(); } catch (e) {}

        function tryUpdateSummary() {
          // 1) only run once modules 2 & 5 have logged
          if ( ! window._wpsa_perf.logged ) return;
        
          // 2) update & reveal Module 6
          updateSummaryCards();
          $('#module6-running').hide();
          $('#wpsa-module6').show();
          
          // 🔧 re-run once the panel is actually in the DOM (fixes missing 💡 on first run)
          setTimeout(function () {
            if (typeof updateSummaryCards === 'function') {
              updateSummaryCards();
            }
          }, 120);
                  
          // Force N/A cards to neutral look (overrides any inline bg)
            (function fixNA() {
              $('#wpsa-module6 .wpsa-summary-45-row .wpsa-stat-card').each(function () {
                var $card = $(this);
                var val = ($card.find('.value').text() || '').trim();
                if (/^n\/?a$/i.test(val) || val === '') {
                  $card.removeAttr('style').addClass('is-na');
                }
              });
            })();
        
            // Write "Tested URL:" line once results are on screen
            wpsa_ensureTestedUrlEl();
            wpsa_renderTestedUrlLabel();
        
            // cache the updated DOM (keeps the line after nav/refresh)
            wpsa_saveTestState();
            
          // 3) reveal Module 7 wrapper
          $('#module7-wrapper').show();
        
        // 1.16.3 (strict boolean true):
              if ( $('#module7-container').data('rendered') === true ) {
                $('#module7-running').hide();
                $('#module7-wrapper')
                  .removeClass('wpsa-hot')
                  .css('background', '')     // ← clear inline tint
                  .show();
                $('#module7-container').show();
                return;
              }
        
        
          // 5) first time: show spinner, hide container
          $('#module7-running').show();
          $('#module7-container').hide();
        
          // 6) kick off the AJAX poll
          (function loadConclusion() {
            $.post( ajaxurl, {
              action:   'wpsa_module7',
              nonce:    wpsaData.module7Nonce,
              test_url: $('input[name="test_url"]').val(),
            // pass the test number if we have one (set when you “Load test #N” or from Module 1 meta)
              test_no:  window._wpsa_testNo || 0
                })
                .done(function(r) {
              if ( r.success && r.data.html ) {
        
            // inject the 7.1–7.7 blocks, mark as rendered
            $('#module7-container')
              .html(r.data.html)
              .data('rendered', true)
              .show();
            $('#module7-running').hide();
            $('#module7-wrapper')
              .removeClass('wpsa-hot')
              .css('background', '')     // ← clear inline tint
              .show();
           
        
            // tell our report script that module 7 is now fully rendered
            $(document).trigger('wpsa_module7_done');
        
            // Refresh the Daily Limit Remaining display
            if ( typeof r.data.remaining !== 'undefined' ) {
              var rem = (typeof r.data.remaining !== 'undefined') ? parseInt(r.data.remaining, 10) : 0,
                  max = (typeof r.data.limit     !== 'undefined') ? parseInt(r.data.limit, 10)     : wpsaData.dailyLimit,
                  $el = $('#wpsa-daily-remaining strong');

              $el.text( rem + '/' + max );
              if ( rem <= 3 ) { $el.css('color','#d32f2f'); }
              else            { $el.css('color',''); }
            }
        
          } else {
            setTimeout(loadConclusion, 1000);
          }
        })
        
            .fail(function() {
              // on network error, retry in 1s
              setTimeout(loadConclusion, 1000);
            });
          })();
        }

    // hook into your existing event listeners
    $(document).on('wpsa_module2_done wpsa_module5_logged', tryUpdateSummary);
    // Persist the Tests DOM after each milestone (safe to call multiple times)
    $(document).on('wpsa_module2_done wpsa_module5_logged wpsa_module7_done', wpsa_saveTestState);
    // 1.16.3: update Load button whenever results land on screen
    $(document).on('wpsa_module2_done wpsa_module5_logged wpsa_module7_done', wpsa_updateLoadBtnState);
    // When Module 7 declares it's ready, refresh the live PDF quota label too
    $(document).on('wpsa_module7_done', wpsa_refreshPdfCounter);
    

    // Module 6 tab switching (works after restore)
    $(document)
      .off('click.wpsaTab6')
      .on('click.wpsaTab6', '.wpsa-tab6', function (e) {
        e.preventDefault();
        $('.wpsa-tab6').removeClass('active');
        $(this).addClass('active');
        _wpsa_summary_strat = $(this).data('strategy');
    
        // If this is a restored page (no fresh run), hydrate once
        if (!window._wpsa_perf.logged) wpsa_hydrateStateFromDOM();
    
        // Update tiles immediately (don’t rely on tryUpdateSummary’s guard)
        updateSummaryCards();
      });

      // 🔧 Ensure Recommendations re-render after tab switch + validation
      $(document).on('click.wpsaReRecs', '.wpsa-tab6', function () {
        // Delay to let validateAll() finish first
        setTimeout(function () {
          if (typeof updateSummaryCards === 'function') {
            updateSummaryCards();
          }
        }, 120);
      });    
      
      // Repaint Summary when navigating back to its panel
        $(document)
          .off('click.wpsaNav6')
          .on('click.wpsaNav6', '.wpsa-nav-tile', function () {
            var target = $(this).data('target');
            if (target === '#wpsa-module6') {
              // If this is a restored page (no fresh run), hydrate once
              if (!window._wpsa_perf.logged) wpsa_hydrateStateFromDOM();
        
              // Paint according to whichever Summary tab is currently active
              var $t6 = $('.wpsa-tab6.active').first();
              if ($t6.length) _wpsa_summary_strat = $t6.data('strategy');
        
              updateSummaryCards();
            }
          });
          
          // Optional micro-guard: repaint Summary if someone navigates by hash
            (function(){
              function repaintIfModule6Visible(){
                if (!$('#wpsa-module6').is(':visible')) return;
                try { if (!window._wpsa_perf.logged) wpsa_hydrateStateFromDOM(); } catch(e){}
                var $t6 = $('.wpsa-tab6.active').first();
                if ($t6.length) _wpsa_summary_strat = $t6.data('strategy');
                try { updateSummaryCards(); } catch(e){}
              }
              var t;
              $(window)
                .off('hashchange.wpsaM6')
                .on('hashchange.wpsaM6', function(){
                  clearTimeout(t);
                  t = setTimeout(repaintIfModule6Visible, 100);
                });
            })();

          
          // Keep URL/Tested-URL/Load # when returning to the Tests panel
            $(document)
              .off('click.wpsaNavHdr')
              .on('click.wpsaNavHdr', '.wpsa-nav-tile', function () {
                var target = $(this).data('target') || '';
                // Adapt these if your Tests tile uses a different data-target
                if (target === '#wpsa-test-panel' || target === '#test-results' || /test/i.test(target)) {
                  setTimeout(wpsa_applySavedHeader, 0);
                  // Also ensure Summary recommendations re-render (adds 💡 and correct list)
             setTimeout(function () {
               // If this is a restored page (no fresh run), hydrate once
               if (!window._wpsa_perf || !window._wpsa_perf.logged) { try { wpsa_hydrateStateFromDOM(); } catch(e){} }
               // Respect current Summary tab (mobile/desktop) if present
               var $t6 = $('.wpsa-tab6.active').first();
               if ($t6.length) { _wpsa_summary_strat = $t6.data('strategy'); }
               if (typeof updateSummaryCards === 'function') { updateSummaryCards(); }
         // Ensure bulbs are present even if list came from restored DOM
          wpsa_forceRecomIcons(jQuery('#summary-recommendations'));
             }, 60);
                }
              });
        
    /* ---------- FAIL-SAFE for Modules 2 & 5 (guard bogus “0 / -- / N/A”) ---------- */
    (function ($) {
      function addWarn($scope, msg) {
        if ($scope.find('.wpsa-notice-failed').length) return;
        $('<div class="notice notice-warning wpsa-notice-failed"><p><strong>Test didn’t finish properly.</strong> ' +
          msg + ' Please run it again.</p></div>').prependTo($scope);
      }
    
      // ── Module 2 (Page asset summary) ─────────────────────────
      function invalidateM2($scope) {
      addWarn($scope, 'Page asset summary is unavailable.');
      $scope.find('.wpsa-card-requests .value').text('N/A');
      $scope.find('.wpsa-card-bytes .value').text('N/A');
      
      // also mark Onload cards as N/A and remove any checkmarks
      $scope.find('.wpsa-card-onload-js .icon, .wpsa-card-onload-css .icon').remove();
      $scope.find('.wpsa-card-onload-js .value, .wpsa-card-onload-css .value').text('N/A');
    
      // Target the real card class and clear the inline background
      $scope.find('.wpsa-stat-card').each(function () {
        this.style.background = '';   // remove inline color set by applyColor()
      }).addClass('is-na');
        $scope.find('.wpsa-card-onload-js, .wpsa-card-onload-css').addClass('is-na');
    
      // also clear our cached values so Summary won’t show green
      if (window._wpsa_mod2_data) {
        var id = $scope.is('#asset-desktop') ? 'desktop' : 'mobile';
        window._wpsa_mod2_data[id] = null;
      }
    }

      function validateM2(selector) {
        var $wrap  = $(selector);
        if (!$wrap.length) return;
    
        var $cards = $wrap.find('.wpsa-stat-cards').first();
        if (!$cards.length) { invalidateM2($wrap); return; }
    
        var reqTxt  = $cards.find('.wpsa-card-requests .value').text().trim();
        var sizeTxt = $cards.find('.wpsa-card-bytes .value').text().trim();
    
        // Parse numbers (size can be "1234.5 KB" etc)
        var req  = parseInt(reqTxt, 10);
        var size = parseFloat((sizeTxt.split(/\s+/)[0] || ''));
    
        // Any non-number/NaN/zero ⇒ treat as failed (real pages never 0 req / 0 KB)
        if (!Number.isFinite(req) || req === 0 || !Number.isFinite(size) || size === 0) {
          invalidateM2($wrap);
        } else {
      // valid → ensure fail UI is cleared
      $wrap.find('.wpsa-notice-failed').hide();
      $wrap.find('.wpsa-stat-card').removeClass('is-na');
        }
      }

    // ── Module 5 (PSI performance) ────────────────────────────
         function invalidateM5($scope) {
          addWarn($scope, 'Performance metrics were not returned by PageSpeed Insights.');
          $scope.find('.perf-text').text('N/A');
        
          // LCP/FCP
          var $lf = $scope.find('.wpsa-card-lcp, .wpsa-card-fcp');
          $lf.addClass('is-na').each(function(){ this.style.background = '#eeeeee'; })
             .find('.icon').remove();
          $scope.find('.wpsa-card-lcp .value, .wpsa-card-fcp .value').text('N/A');
        
          // ✅ ALSO normalize CLS/INP here
          var $ci = $scope.find('.wpsa-card-cls, .wpsa-card-inp');
          $ci.addClass('is-na').each(function(){ this.style.background = '#eeeeee'; })
             .find('.icon').remove();
          $scope.find('.wpsa-card-cls .value, .wpsa-card-inp .value').text('N/A');
        
          // keep in-memory copy consistent
          var id = $scope.is('#perf-desktop') ? 'desktop' : 'mobile';
          if (window._wpsa_perf && window._wpsa_perf[id]) {
            Object.assign(window._wpsa_perf[id], { score:'N/A', lcp:'N/A', fcp:'N/A', cls:'N/A', inp:'N/A' });
          }
        }
     
    function validateM5(selector) {
      var $wrap = $(selector);
      if (!$wrap.length) return;
    
      var perf = parseInt($wrap.find('.perf-text').text(), 10) || 0;
      var lcp  = ($wrap.find('.wpsa-card-lcp .value').text() || '').trim();
      var fcp  = ($wrap.find('.wpsa-card-fcp .value').text() || '').trim();
    
      var lcpBad = !lcp || lcp === '--' || /^n\/a$/i.test(lcp);
      var fcpBad = !fcp || fcp === '--' || /^n\/a$/i.test(fcp);
    
      if (perf === 0 || lcpBad || fcpBad) {
        invalidateM5($wrap);
      } else {
        $wrap.find('.wpsa-notice-failed').hide();
        $wrap.find('.wpsa-card-lcp, .wpsa-card-fcp').removeClass('is-na');
      }
     }

  // ── Keep Summary (Module 6) honest ────────────────────────
  function scrubSummary() {
  // Only touch tiles we have explicitly marked as NA
  $('#summary-module_5 .psi-half.na').each(function () {
    var $tile = $(this);
    $tile.css('background', WPSA_COLORS.na);
    $tile.find('.score').text('N/A');
    // remove any lingering checkmarks
    $tile.find('.icon').remove();
  });

   // Also keep Module 2 + new Onload tiles honest if marked invalid upstream
  $('#summary-module_2.is-na .value, #summary-onload-js.is-na .value, #summary-onload-css.is-na .value').each(function () {
    $(this).text('N/A');
  });
  }

  function validateAll() {
    // Your DOM uses these exact IDs
    validateM2('#asset-mobile');
    validateM2('#asset-desktop');
    validateM5('#perf-mobile');
    validateM5('#perf-desktop');
    
    // normalize any leftover "--" in both panes
    window.wpsa_normalizePsiDashes(jQuery('#perf-mobile'));
    window.wpsa_normalizePsiDashes(jQuery('#perf-desktop'));

    
    scrubSummary();
  }

  // Run after modules finish and when Module 7 signals “ready”
  $(document).on('wpsa_module2_done wpsa_module5_logged wpsa_module7_done', validateAll);

  // Re-check when toggling any tabs
  $(document).on('click', '.wpsa-tab2, .wpsa-tab5, .wpsa-tab6, .wpsa-tabs a, .wpsa-toggle a', function () {
    setTimeout(validateAll, 0);
  });

  // One more pass once the DOM is ready
   $(validateAll);
})(jQuery);

/* ---------- /FAIL-SAFE block ---------- */

/* Copy for <details> lists (Module 2 & 4.5) with “Copied!” feedback */
jQuery(document).on('click', '.wpsa-copy-btn', function (e) {
  e.preventDefault();

  var $btn = jQuery(this);
  var sel  = $btn.data('target');
  var $ul  = jQuery(sel);
  if (!$ul.length) return;

  var text = $ul.find('li').map(function(){ return jQuery(this).text(); }).get().join('\n');

  function showCopied(){
    // cache original HTML once
    if (!$btn.data('origHtml')) $btn.data('origHtml', $btn.html());
    $btn.prop('disabled', true)
        .addClass('is-copied')
        .html('Copied!');
    setTimeout(function(){
      $btn.prop('disabled', false)
          .removeClass('is-copied')
          .html($btn.data('origHtml'));
    }, 1500);
  }

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(showCopied, showCopied);
  } else {
    var ta = jQuery('<textarea>').css({position:'fixed', top:'-9999px'}).val(text).appendTo('body');
    ta[0].select(); try { document.execCommand('copy'); } catch(e){}
    ta.remove();
    showCopied();
  }
});

    /* ====================================================================== */
    /*  Console utilities — reset to a “fresh” screen (no prior test results) */
    /*  Usage in DevTools:  WPSA.reset()   or   wpsaReset()                   */
    /* ====================================================================== */
    window.WPSA = window.WPSA || {};
    window.WPSA.reset = function(/*options*/){
      try { ['wpsa:testState:v1','wpsa:runCooldownUntil:v1','WPSA_RUN_AFTER_RELOAD']
        .forEach(function(k){ sessionStorage.removeItem(k); }); } catch(e){}
      window._wpsa_perf      = { mobile:null, desktop:null, logged:false, errors:{ mobile:false, desktop:false } };
      window._wpsa_mod2_data = { mobile:null, desktop:null };
      window._wpsa_testNo    = 0;
      window._wpsa_is_scheduled  = false;
      try { _wpsa_summary_strat = 'mobile'; } catch(e){}
      try { wpsa_clearCooldown(); } catch(e){}
      jQuery('#running-test,#module2-running,#module5-running,#module6-running,#module7-running').hide();
      jQuery('#wpsa-module5,#wpsa-module6,#module7-wrapper').hide();
      jQuery('#module7-container').removeData('rendered').data('rendered',false).empty().hide();
      jQuery('#test-results').empty();
      jQuery('#wpsa-tested-url').hide().text('');
      jQuery('.wpsa-notice-failed').remove();
      try { if (typeof wpsa_saveTestState==='function') wpsa_saveTestState(); } catch(e){}
      console.info('WPSA.reset(): front-end state cleared — ready for a fresh run or “Load test #”.');
    };
    window.sareset = window.WPSA.reset;
    
    /* === Module 1: shared helpers === */
    function wpsa_getTtfb(root){
      var $r = root ? jQuery(root) : jQuery(document);
      var $row = $r.find('.wpsa-module1-table tbody tr').first();
      if (!$row.length) return NaN;
      var ttfbText = ($row.children('td').eq(2).text() || '').trim();
      return parseInt(ttfbText.replace(/\D+/g,''),10);
    }
    function wpsa_getM1Table(root){
      var $r = root ? jQuery(root) : jQuery(document);
      return $r.find('.wpsa-module1-table').first();
    }
    
    /* === Module 1: re-color banner from current TTFB (works after Run or Load) === */
    function wpsaApplyModule1BgFromDom(root) {
      var ttfb = wpsa_getTtfb(root);
      if (!Number.isFinite(ttfb)) return;
      var TH = WPSA_THRESHOLDS;
      var cls = ttfb <= TH.ttfb.good ? 'wpsa-m1-green' : ttfb <= TH.ttfb.m1_ok ? 'wpsa-m1-yellow' : 'wpsa-m1-red';
      var $table  = wpsa_getM1Table(root);
      if (!$table.length) return;
      var $banner = $table.closest('.wpsa-module-1, .wpsa-module1, .wpsa-box, .card, .notice, .kpi-card')
                          .add($table.prev('.wpsa-module1-summary, .wpsa-status-banner, #module1-banner'))
                          .filter(':first');
      var $target = $banner.length ? $banner : $table;
      $target.removeClass('wpsa-m1-green wpsa-m1-yellow wpsa-m1-red').addClass(cls);
      var $note = $table.next('.wpsa-note, .wpsa-status, .wpsa-ttfb-msg').first();
      if ($note.length) $note.removeClass('wpsa-m1-green wpsa-m1-yellow wpsa-m1-red').addClass(cls);
    }
    
    /* === Module 1: rewrite the status box from the current TTFB (Run or Load) === */
    function wpsaRewriteModule1StatusFromDom(root) {
      var t = wpsa_getTtfb(root);
      if (!Number.isFinite(t)) return;
      var $table = wpsa_getM1Table(root);
      var $note  = $table.next();
      if (!$note.length) return;
      if (!$table.length) return;
    
      var TH = WPSA_THRESHOLDS, bg, msg;
      if (t <= TH.ttfb.good) { bg = WPSA_COLORS.green;   msg = 'This test confirms that your server TTFB is Fast ✅.'; }
      else if (t <= TH.ttfb.m1_ok) { bg = WPSA_COLORS.m1Amber; msg = 'This test confirms that your server TTFB is OK 🟡.'; }
      else { bg = WPSA_COLORS.red; msg = 'This test confirms that your server TTFB is Slow 🔴.'; }
    
      var $p = $note.find('p').first();
      if ($p.length) { $p.text(msg); } else { $note.prepend('<p>'+msg+'</p>'); }
      $note.removeClass('wpsa-m1-green wpsa-m1-yellow wpsa-m1-red is-na').css('background', bg);
    }
    
    /* Auto-run the recolor whenever Module 1 gets (re)rendered. */
    (function () {
      var target = document.getElementById('test-results');
      if (!target || !window.MutationObserver) return;
      var obs = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
          var nodes = muts[i].addedNodes || [];
          for (var j = 0; j < nodes.length; j++) {
            var n = nodes[j];
            if (n.nodeType === 1 && (n.matches?.('.wpsa-module1-table') || n.querySelector?.('.wpsa-module1-table'))) {
              setTimeout(function(){
                wpsaApplyModule1BgFromDom(target);
                wpsaRewriteModule1StatusFromDom(target);
              }, 0);
              return;
            }
          }
        }
      });
      obs.observe(target, { childList: true, subtree: true });
    })();
    
    /* === Module 5: helper — update PSI screenshot preview === */
    function wpsa_updatePsiScreenshot(strat, dataUrl) {
      var id = (strat === 'desktop')
        ? '#wpsa-psi-screenshot-img-desktop'
        : '#wpsa-psi-screenshot-img-mobile';

      var $img = jQuery(id);
      if (!$img.length) {
        return;
      }

      // If we have a data URL, show it; otherwise clear the box
      if (dataUrl && typeof dataUrl === 'string') {
        $img
          .attr('src', dataUrl)
          .attr('alt', 'PageSpeed Insights ' + (strat === 'desktop' ? 'desktop' : 'mobile') + ' screenshot')
          .addClass('has-screenshot');
      } else {
        $img
          .attr('src', '')
          .attr('alt', '')
          .removeClass('has-screenshot');
      }
    }

    // Fetch PSI screenshots from psi-diag-ss.log for a given Test #
    function wpsa_fetchPsiScreensForTest(testNo) {
      if (!testNo) return;

      ['mobile', 'desktop'].forEach(function (strat) {
        jQuery.post(ajaxurl, {
          action:      'wpsa_get_psi_screenshot',
          _ajax_nonce: wpsaData.perfNonce,
          test_no:     testNo,
          strategy:    strat
        }).done(function (r) {
          if (r && r.success && r.data && typeof r.data.psi_screenshot === 'string' && r.data.psi_screenshot) {
            wpsa_updatePsiScreenshot(strat, r.data.psi_screenshot);
          }
        });
      });
    }

}); // end jQuery
