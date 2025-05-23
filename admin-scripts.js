/**
 * Speed Analyzer WP – Admin Scripts
 * Version: v0.730
 */

jQuery(function($){
  // Global state for Module 5
  window._wpsa_perf = {
    mobile: null,
    desktop: null,
    logged: false,
    errors: { mobile: false, desktop: false }
  };
  // Will hold Module 2 results
  window._wpsa_mod2_data = { mobile: null, desktop: null };
  // Current summary strategy
  var _wpsa_summary_strat = 'mobile';

  // Helper: colorize stat cards (Module 2)
  var applyColor = function($c){
    var cards = $c.find('.wpsa-stat-card'),
        req   = parseInt(cards.eq(0).find('.value').text(),10) || 0,
        sz    = parseFloat(cards.eq(1).find('.value').eq(0).text())   || 0,
        rc    = req <= 50     ? '#c6f7c3'
              : req <= 70     ? '#fef6b3'
              :                  '#ffdddd',
        sc    = sz  <= 2048   ? '#c6f7c3'
              : sz  <= 3584   ? '#fef6b3'
              :                  '#ffdddd';
    cards.eq(0).css('background', rc);
    cards.eq(1).css('background', sc);
    // append checkmark only once on “good” (green)
    if (rc === '#c6f7c3' && !cards.eq(0).find('.icon').length) {
      cards.eq(0).find('.value').append(' <span class="icon">✅</span>');
    }
    if (sc === '#c6f7c3' && !cards.eq(1).find('.icon').length) {
      cards.eq(1).find('.value').append(' <span class="icon">✅</span>');
    }
  };

  // Helper: render performance & diagnostics (Module 5)
  var renderPerf = function(data, prefix){
    if (!data || !Array.isArray(data.diagnostics)) {
      console.warn('No diagnostics for', prefix);
      return;
    }
    // Build list of diagnostics, filter out zero entries
    var items = [];
    data.diagnostics.forEach(function(item){
      if (/0\s*(ms|KiB|resources)/i.test(item.value)) return;
      var num = parseFloat(item.value.replace(/[\, ]/g,'')) || 0;
      items.push({ title: item.title, value: item.value, severity: item.severity, num: num });
    });
    // Sort: high severity first, then by numeric descending
    items.sort(function(a,b){
      if (a.severity !== b.severity) return a.severity === 'high' ? -1 : 1;
      return b.num - a.num;
    });
    var top = items.slice(0,5);

    // Performance circle
    var circ = $('#perf-circle-' + prefix).removeClass('na'),
        sc   = data.score;
    if (sc === 'N/A' || isNaN(sc)) {
      circ.addClass('na').find('.perf-text').text('N/A');
    } else {
      var col, bg;
      if      (sc < 50) { col = '#d32f2f'; bg = '#ffebee'; }
      else if (sc < 90) { col = '#f9a825'; bg = '#fff7ed'; }
      else              { col = '#388e3c'; bg = '#e8f5e9'; }
      circ.css({ borderColor: col, background: bg })
          .find('.perf-text').css('color', col).text(sc);
      // intentionally no ✅ on the circle
    }

    // LCP & FCP cards
    var lcpN = parseFloat(data.lcp)||0,
        fcpN = parseFloat(data.fcp)||0,
        lcpBg = data.lcp==='N/A'? '#dddddd'
               : (lcpN<=2.5? '#c6f7c3' : lcpN<=4? '#fef6b3' : '#ffdddd'),
        fcpBg = data.fcp==='N/A'? '#dddddd'
               : (fcpN<=1.8? '#c6f7c3' : fcpN<=3? '#fef6b3' : '#ffdddd');

    var $lcpCard = $('#perf-'+prefix+' .wpsa-card-lcp');
    $lcpCard.css('background', lcpBg)
            .find('.value').text(data.lcp);
    if (lcpBg === '#c6f7c3' && !$lcpCard.find('.icon').length) {
      $lcpCard.find('.value').append(' <span class="icon">✅</span>');
    }

    var $fcpCard = $('#perf-'+prefix+' .wpsa-card-fcp');
    $fcpCard.css('background', fcpBg)
            .find('.value').text(data.fcp);
    if (fcpBg === '#c6f7c3' && !$fcpCard.find('.icon').length) {
      $fcpCard.find('.value').append(' <span class="icon">✅</span>');
    }

    // Top-5 diagnostics list
    var ul = $('#diag-' + prefix).empty();
    top.forEach(function(item){
      var cls  = item.severity==='high'?'severe':'moderate',
          icon = item.severity==='high'?'▲':'■';
      ul.append('<li class="'+cls+'"><span class="icon">'+icon+'</span>'
                +item.title+(item.value?' — '+item.value:'')+'</li>');
    });
  };

  // On form submit: reset UI & state, show spinners
  $('#speed-test-form').on('submit', function(){
    $('#test-results').empty();
    $('#running-test').show();
    $('#module2-running').show();
    $('#module5-running').show();
    $('#perf-mobile .wpsa-subheading').show();
    $('#perf-desktop .wpsa-subheading').hide();
    $('#module6-running').show();
    $('#wpsa-module6').hide();
    // show Module 7 spinner, hide its container
    $('#module7-wrapper').hide();       // reset module 7 entirely
  $('#module7-running').hide();       // ensure spinner starts hidden
  $('#module7-container').hide();     // hide old content if any
    window._wpsa_perf.logged = false;
    window._wpsa_perf.mobile = null;
    window._wpsa_perf.desktop = null;
    window._wpsa_mod2_data  = { mobile: null, desktop: null };
    _wpsa_summary_strat     = 'mobile';
    // ————————————————————————————————
    // clear the old “Tested URL:” line
    $('#test-results > #wpsa-tested-url').hide().text('');
    // ————————————————————————————————
  });

  // Module 2: Page asset summary
  if ($('#module2-running').length) {
    $('#module2-running').show();
    $.post(ajaxurl, {
      action:      'wpsa_psi',
      _ajax_nonce: wpsaData.psiNonce,
      test_url:    $('input[name="test_url"]').val()
    }).done(function(r){
      $('#module2-running').hide();
      var tabs =
        '<div class="wpsa-tabs2">'+
          '<span class="wpsa-tab2 active" data-strategy="mobile">'+
            '<span class="dashicons dashicons-smartphone"></span> Mobile</span>'+
          '<span class="wpsa-tab2" data-strategy="desktop">'+
            '<span class="dashicons dashicons-desktop"></span> Desktop</span>'+
        '</div>',
        mHtml = '<h3>Mobile results</h3>'+r.data.mobile,
        dHtml = '<h3>Desktop results</h3>'+r.data.desktop;

      $('#module2-results').html(tabs+
        '<div id="asset-mobile">'+mHtml+'</div>'+
        '<div id="asset-desktop">'+dHtml+'</div>'
      );
      applyColor($('#asset-mobile'));
      applyColor($('#asset-desktop'));

      // Log and store data
      var reqM = parseInt($('#asset-mobile .value').eq(0).text(),10),
          szM  = parseFloat($('#asset-mobile .value').eq(1).text()),
          reqD = parseInt($('#asset-desktop .value').eq(0).text(),10),
          szD  = parseFloat($('#asset-desktop .value').eq(1).text()),
          logM, logD;

      if (isNaN(reqM)||isNaN(szM)) {
        window._wpsa_mod2_data.mobile = null;
        logM = "Module 2 Mobile: N/A\n";
      } else {
        window._wpsa_mod2_data.mobile = { requests:reqM, page_size:szM };
        logM = sprintf("Module 2 Mobile: %d req, %.1f KB\n", reqM, szM);
      }
      if (isNaN(reqD)||isNaN(szD)) {
        window._wpsa_mod2_data.desktop = null;
        logD = "Module 2 Desktop: N/A\n";
      } else {
        window._wpsa_mod2_data.desktop = { requests:reqD, page_size:szD };
        logD = sprintf("Module 2 Desktop: %d req, %.1f KB\n", reqD, szD);
      }
      $.post(ajaxurl,{ action:'wpsa_log_module2', mobile:logM, desktop:logD });

      $('.wpsa-tab2').click(function(){
        $('.wpsa-tab2').removeClass('active');
        $(this).addClass('active');
        var s = $(this).data('strategy');
        $('#asset-mobile,#asset-desktop').hide();
        $('#asset-'+s).show();
      });

      $('#module2-footnote').show();
      $(document).trigger('wpsa_module2_done');
    });
  }

  // Module 5: Performance & Diagnostics
  if ($('#module5-running').length) {
    $('#module5-running').show();
    $('#perf-mobile .wpsa-subheading').show();
    $('#perf-desktop .wpsa-subheading').hide();

    ['mobile','desktop'].forEach(function(strat){
      $.post(ajaxurl,{
        action:      'wpsa_performance',
        _ajax_nonce: wpsaData.perfNonce,
        test_url:    $('input[name="test_url"]').val(),
        strategy:    strat
      }).done(function(r){
        window._wpsa_perf[strat] = (r.success && r.data) ? r.data : {
          score:'N/A', lcp:'N/A', fcp:'N/A',
          diagnostics:[{title:'Failed to fetch diagnostics. Please retry.',value:'',severity:'high',num:0}]
        };

        if (window._wpsa_perf.mobile && window._wpsa_perf.desktop && !window._wpsa_perf.logged) {
          window._wpsa_perf.logged = true;
          $('#module5-running').hide();
          $('#wpsa-module5').show();

          $('#perf-mobile .wpsa-subheading').show();
          $('#perf-desktop .wpsa-subheading').hide();

          renderPerf(window._wpsa_perf.mobile,'mobile');
          renderPerf(window._wpsa_perf.desktop,'desktop');

          $.post(ajaxurl,{
            action:'wpsa_log_module5',
            mobile: sprintf("Module 5 Mobile: Performance: %s, LCP: %s, FCP: %s\n",
                            window._wpsa_perf.mobile.score,
                            window._wpsa_perf.mobile.lcp,
                            window._wpsa_perf.mobile.fcp),
            desktop:sprintf("Module 5 Desktop: Performance: %s, LCP: %s, FCP: %s\n",
                            window._wpsa_perf.desktop.score,
                            window._wpsa_perf.desktop.lcp,
                            window._wpsa_perf.desktop.fcp)
          });

          $(document).trigger('wpsa_module5_logged');
        }
      });
    });

    $(document).on('click','.wpsa-tab5',function(){
      $('.wpsa-tab5').removeClass('active');
      $(this).addClass('active');
      var s = $(this).data('strategy');
      $('#perf-mobile,#perf-desktop').hide();
      $('#perf-'+s).show();
      $('#perf-mobile .wpsa-subheading,#perf-desktop .wpsa-subheading').hide();
      $('#perf-'+s+' .wpsa-subheading').show();
    });
  }

// Module 6: Summary & Recommendations
  function updateSummaryCards(){
    // 🔄 first remove any existing checkmarks in summary cards
    $('#summary-module_1 .value .icon,' +
      '#summary-module_2 .value .icon,' +
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
        homeDom = window.location.hostname,
        isLocal = testUrl.indexOf(homeDom)!==-1;

    // Card 1: Server TTFB
    var ttfbRaw = $('.wpsa-module-1 table tbody tr td').last().text().trim()||'N/A',
        ttfbNum = parseInt(ttfbRaw,10)||0,
        bg1     = ttfbNum<=300?'#c6f7c3':ttfbNum<=500?'#fef6b3':'#ffdddd';
    $('#summary-module_1').css('background',bg1)
                          .find('.value').text(ttfbRaw);
    if (bg1==='#c6f7c3') {
     // ✅ already coming from Module 1 output, so no extra append here
    }

    // Card 2: Requests & Page Size
    var mod2 = window._wpsa_mod2_data[strat],
        txt2 = !mod2 ? 'N/A' : mod2.requests+' R, '+mod2.page_size+' KB',
        bg2  = !mod2 ? '#dddddd'
             : mod2.requests<=50 ? '#c6f7c3'
             : mod2.requests<=70 ? '#fef6b3'
             :                     '#ffdddd';
    $('#summary-module_2').css('background',bg2)
                          .find('.value').text(txt2);
    if (bg2==='#c6f7c3') {
      $('#summary-module_2 .value')
        .append(' <span class="icon">✅</span>');
    }

    // Card 3: Autoloaded Options
    var a3Val='N/A', bg3='#dddddd';
    if (isLocal){
      a3Val = $('.wpsa-card-autoload .value').text()||'N/A';
      var a3n = parseFloat(a3Val)||0;
      bg3 = a3n<=800   ? '#c6f7c3'
          : a3n<=1600  ? '#fef6b3'
          :             '#ffdddd';
    }
    $('#summary-module_3').css('background',bg3)
                          .find('.value').text(a3Val);
     // ✅ already coming from Module 3 output, so no extra append here

    // Card 4: Persistent Cache
    // — strip out any trailing “✅” so we match exactly “Yes” or “No”
    var raw   = $('.wpsa-card-objectcache .value').text() || 'N/A',
        m     = raw.match(/^(Yes|No)/),
        o4Val = m ? m[1] : 'N/A',
        bg4   = (o4Val === 'Yes') ? '#c6f7c3'
               : (o4Val === 'No')  ? '#ffdddd'
                                  : '#dddddd';

    $('#summary-module_4')
      .css('background', bg4)
      .find('.value').text(o4Val);

    if ( bg4 === '#c6f7c3' && !$('#summary-module_4 .value .icon').length ) {
      $('#summary-module_4 .value')
        .append(' <span class="icon">✅</span>');
    }

    // Card 5: LCP & FCP
    var perf = window._wpsa_perf[strat]||{},
        lcp  = perf.lcp||'N/A', fcp = perf.fcp||'N/A',
        ln   = parseFloat(lcp)||0, fn = parseFloat(fcp)||0,
        bgL  = lcp==='N/A'? '#dddddd'
             : ln<=2.5   ? '#c6f7c3'
             : ln<=4     ? '#fef6b3'
             :             '#ffdddd',
        bgF  = fcp==='N/A'? '#dddddd'
             : fn<=1.8   ? '#c6f7c3'
             : fn<=3     ? '#fef6b3'
             :             '#ffdddd';

    $('#summary-module_5 .psi-half.lcp')
      .css('background',bgL)
      .find('.score').text(lcp);
    if (bgL==='#c6f7c3') {
      $('#summary-module_5 .psi-half.lcp')
        .append(' <span class="icon">✅</span>');
    }

    $('#summary-module_5 .psi-half.fcp')
      .css('background',bgF)
      .find('.score').text(fcp);
    if (bgF==='#c6f7c3') {
      $('#summary-module_5 .psi-half.fcp')
        .append(' <span class="icon">✅</span>');
    }

    // 3) BUILD THE RECOMMENDATIONS
  var recs = [],
      ttfbRaw = $('.wpsa-module-1 table tbody tr td').last().text().trim() || 'N/A',
      ttfbNum = parseInt(ttfbRaw, 10) || 0,
      mod2    = window._wpsa_mod2_data[_wpsa_summary_strat] || {},
      perf    = window._wpsa_perf[_wpsa_summary_strat] || {},
      ln      = parseFloat(perf.lcp) || 0,
      fn      = parseFloat(perf.fcp) || 0;

  if (ttfbNum > 500)                    recs.push('Improve your server response time');
  if (mod2.requests   > 70)             recs.push('Reduce number of HTTP requests');
  if (mod2.page_size  > 3584)           recs.push('Decrease overall page size');
  if (isLocal && parseFloat(a3Val) > 800) recs.push('Reduce autoloaded options size');
  if (isLocal && o4Val === 'No')          recs.push('Enable persistent object cache');
  if (perf.score < 50)                 recs.push('Optimize critical rendering path');
  if (ln > 4)                          recs.push('Decrease LCP');
  if (fn > 3)                          recs.push('Decrease FCP');

  // 4) SHOW/HIDE THE RECOMMENDATIONS
  if (recs.length) {
    var $ul = $('#summary-recommendations').empty();
    recs.forEach(function(r){
      $ul.append('<li><span class="icon">💡</span> '+r+'</li>');
    });
    $('#summary-recommendations').show().prev('h3').show();
    $('.wpsa-module-6 .wpsa-footnote').show();
  } else {
    $('#summary-recommendations').hide().prev('h3').hide();
    $('.wpsa-module-6 .wpsa-footnote').hide();
  }
}  // end updateSummaryCards()

// Only reveal module 6 after BOTH module 2 & module 5 finish,
// then trigger Module 7 via AJAX


function tryUpdateSummary() {
  // 1) only run once modules 2 & 5 have logged
  if ( ! window._wpsa_perf.logged ) return;

  // 2) update & reveal Module 6
  updateSummaryCards();
  $('#module6-running').hide();
  $('#wpsa-module6').show();

  // 3) reveal Module 7 wrapper
  $('#module7-wrapper').show();

  // 4) if already fetched once, just show it
  if ( $('#module7-container').data('rendered') ) {
    $('#module7-running').hide();
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
      test_url: $('input[name="test_url"]').val()
    })
    .done(function(r) {
      if ( r.success && r.data.html ) {
          
        // inject the 7.1–7.7 blocks, mark as rendered
        $('#module7-container')
          .html(r.data.html)
          .data('rendered', true)
          .show();
        $('#module7-running').hide();
        
         // Refresh the Daily Limit Remaining display
         if ( typeof r.data.remaining !== 'undefined' ) {
  var rem = r.data.remaining,
      max = wpsaData.dailyLimit,
      $el = $('#wpsa-daily-remaining strong');

  $el.text( rem + '/' + max );
  if ( rem <= 3 ) {
    $el.css('color','#d32f2f');
  } else {
    $el.css('color','');
  }
}
        
      } else {
        // not ready yet—try again in 1s
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




  // Module 6 tab switching
  $(document).on('click','.wpsa-tab6',function(){
    $('.wpsa-tab6').removeClass('active');
    $(this).addClass('active');
    _wpsa_summary_strat = $(this).data('strategy');
    tryUpdateSummary();
  });

  // Listen for module-done events
  $(document).on('wpsa_module2_done',    tryUpdateSummary);
  $(document).on('wpsa_module5_logged', tryUpdateSummary);

}); // end jQuery