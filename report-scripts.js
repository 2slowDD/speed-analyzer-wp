/*
 * Speed Analyzer – report Scripts
 * report-scripts.js - Version: v0.178
 */

jQuery(function($){
  console.info('report-scripts.js v0.178 loaded');
  
  // --- PDF page sizing/tweaks ---
        const WPSA_PAGE_MAX_W = 660; // was 700px everywhere
        function wpsaNewPdfPage() {
          // Padding-top:1px blocks top-margin collapse that can cause a blank page
          return $('<div class="wpsa-pdf-page" style="page-break-before:always; padding-top:1px; margin:0;"></div>');
        }


  // v0.131 — build a safe filename from a tested URL.
    // Examples:
    //  https://wpservice.pro/                 → speed-analyzer-report-wpservice.pdf
    //  https://www.wpservice.pro/             → speed-analyzer-report-wpservice.pdf
    //  https://www.wpservice.pro/shop         → speed-analyzer-report-wpservice-shop.pdf
    //  https://sub.example.co.uk/posts/hello  → speed-analyzer-report-sub-example-co-uk-posts-hello.pdf
    function buildReportFilename(rawUrl){
      try {
        const u = new URL(rawUrl);
        // strip leading www.
        let host = u.hostname.replace(/^www\./i, '');
        // split host to labels (keep full host except www)
        const hostPart = host.split('.').join('-'); // ke/ep subdomains (except www) visible
        // path → take up to first 2 non-empty segments
        const segs = u.pathname.split('/').filter(Boolean).slice(0, 2);
        // compose parts
        const parts = ['speed-analyzer-report', hostPart].concat(segs);
        // slugify
        const slug = parts
          .join('-')
          .toLowerCase()
          .replace(/[^a-z0-9\-]+/g, '-')  // keep a–z 0–9 hyphen
          .replace(/-+/g, '-')            // collapse
          .replace(/^-|-$/g, '');         // trim
        return slug + '.pdf';
      } catch(e) {
        // fallback
        return 'speed-analyzer-report.pdf';
      }
    }

    const wpsaPdfCodeUnloaderLink = '<a href="https://wpservice.pro/our-products/code-unloader/" target="_blank" rel="noopener">Code Unloader</a>';
    const wpsaPdfPerfmattersLink = '<a href="https://wpservice.pro/tools-of-the-trade/the-guide-to-perfmatters-settings/" target="_blank" rel="noopener">Perfmatters</a>';
    const wpsaPdfUnloadToolsLinks = `${wpsaPdfCodeUnloaderLink} or ${wpsaPdfPerfmattersLink}`;
    const wpsaPdfFlyingPressWpRocketLink = '<a href="https://wpservice.pro/tools-of-the-trade/flyingpress-vs-wp-rocket-compare/" target="_blank" rel="noopener">FlyingPress or WP Rocket</a>';
    
   // --- PDF branding helpers (robust to Document or Element roots) ---
    function wpsaGetPdfConfig(root) {
      // Prefer wp_localize_script payload (set in main PHP).
      if (window.wpsaPdf && wpsaPdf.pdf) {
        return {
          headerText: String(wpsaPdf.pdf.headerText || ''),
          removeCta: !!wpsaPdf.pdf.removeCta,
          customSummary: {
            enabled: !!(wpsaPdf.pdf.customSummary && wpsaPdf.pdf.customSummary.enabled),
            text: String((wpsaPdf.pdf.customSummary && wpsaPdf.pdf.customSummary.text) || '')
          }
        };
      }

  // Fallback to JSON <script> embedded in the PDF HTML (report.php).
      try {
        var r = root || document;
        // Works for Document or Element
        var node = (r && r.querySelector) ? r.querySelector('#wpsa-pdf-config-json')
                                          : document.getElementById('wpsa-pdf-config-json');
        return node ? JSON.parse(node.textContent) :
          { headerText: '', removeCta: false, customSummary: { enabled: false, text: '' } };
      } catch (e) {
        return { headerText: '', removeCta: false, customSummary: { enabled: false, text: '' } };
      }
    }

    function wpsaApplyPdfBranding(root) {
      var r = root || document;          // root can be a detached <div> or the real document
      var cfg = wpsaGetPdfConfig(r);
    
      // 1) Header text
      var headerNode = (r && r.querySelector) ? r.querySelector('#pdf-header-text')
                                              : document.getElementById('pdf-header-text');
      if (headerNode && typeof cfg.headerText === 'string') {
        headerNode.textContent = cfg.headerText;
      }
    
        // 2) Remove CTA only if admin explicitly asked for it
      if (cfg.removeCta) {
        if (r && r.querySelectorAll) {
          r.querySelectorAll('.wpsa-cta').forEach(function (el) { el.remove(); });
        }
      }

      // 3) Append custom summary at the end (if enabled)
      if (cfg.customSummary && cfg.customSummary.enabled) {
        var target = (r && r.querySelector)
          ? (r.querySelector('#module7-container') || r.querySelector('#pdf-body') || r)
          : (document.getElementById('module7-container') || document.getElementById('pdf-body') || document.body);

        var wrap = (r.ownerDocument || document).createElement('div');
        wrap.className = 'wpsa-custom-summary';
        wrap.style.marginTop = '32px';
        wrap.style.padding = '16px';
        wrap.style.border = '1px solid #ccc';
        wrap.style.borderRadius = '8px';
        wrap.innerHTML =
          '<h2 style="margin:0 0 10px; font-size:16px;">Summary</h2>' +
          '<div style="font-size:14px; line-height:1.5;">' + (cfg.customSummary.text || '') + '</div>';

        if (target) target.appendChild(wrap);
      }
    }

    function normalizeListItems($ul){
      if (!$ul || !$ul.length) return $ul;
      $ul.children('li').each(function(){
        const $li = $(this);
    
        // find a trailing "meta" chunk (savings/size/etc.)
        // prefer explicit .meta/.value/small/em; fallback to text pattern.
        let $meta = $li.find('.meta, .value, small, em').filter(function(){
          return /savings|total size|elements|main-thread|KiB|ms|s\b/i.test($(this).text());
        }).last();
    
        // if no explicit node, try to carve out a trailing text node that looks like meta
        if (!$meta.length){
          const txt = $li.text();
          const m = txt.match(/(\s—\s.*)$/); // " — something"
          if (m){
            // split the tail into its own span
            const tail = document.createTextNode(m[1]);
            $meta = $('<span class="diag-meta"></span>').text(m[1]);
            // remove tail text from the li and append structured nodes
            $li.contents().filter(function(){ return this.nodeType === 3 && this.nodeValue.includes(m[1]); }).remove();
            $li.append($meta);
          }
        }
    
        // wrap the remaining non-icon, non-meta content as the title column
        const $icon = $li.children('svg, img, .icon, .dashicons').first();
        const keep = new Set($icon.get().concat($meta.get()));
        const $title = $('<span class="diag-title"></span>');
        $li.contents().each(function(){
          if (keep.has(this)) return;
          $title.append(this);
        });
        // ensure structure: [icon] [title] [meta?]
        if ($icon.length) $icon.prependTo($li);
        $li.prepend($title);
        if ($meta.length) $meta.addClass('diag-meta');
      });
      return $ul;
    }

    
    // Normalize admin-card "Yes/No" text (handles emojis like ✅❌ and extra text)
    function wpsaNormalizeYesNo(raw) {
      var t = String(raw || '').trim();
      if (!t) return '';
      if (/^\s*N\/A\s*$/i.test(t)) return 'N/A';
      if (/(^|\b)yes(\b|$)/i.test(t) || /✅|✔️/.test(t)) return 'Yes';
      if (/(^|\b)no(\b|$)/i.test(t)  || /❌|✖️/.test(t)) return 'No';
      // Fallback: strip common icons and trim again
      return t.replace(/[✅❌✔️✖️]/g, '').trim();
    }

    
    // ——————————————————————————
    // updatePdfButton()
    // ——————————————————————————
    function updatePdfButton(remaining, limit) {
      // Do not mutate the localized source of truth (wpsaPdf.*).
      // The server must stay the authority; JS only renders.
    
      // 1) remove old top button
      $('#report-button-2').closest('.wpsa-pdf-button-wrap').remove();


      // 2) build new buttons (no real disabled attribute)
      const tpl = `
      <div class="wpsa-pdf-button-wrap" style="text-align:center; margin:1em 0;">
        <button id="report-button-2" class="wpsa-button-pdf">
          Generate PDF report
        </button>
        <p id="wpsa-pdf-counter"
           class="wpsa-url-display"
           style="position: relative; top: -5em; font-size:0.9em; text-align:center;">
          PDFs remaining today:
          <strong${remaining <= 3 ? ' style="color:#d32f2f;"' : ''}>${remaining}/${limit}</strong>
          <span class="custom-tooltip" data-tooltip="Daily PDF export limit.">?</span>
        </p>
      </div>
      `;
      const $top = $(tpl).insertBefore('#module7-wrapper');

      // 3) clone for bottom placeholder
      const $bottom = $top.clone();
      $bottom.find('button').attr('id', 'report-button');
      $('#report-button-wrap').empty().append($bottom);

      // 4) ALSO refresh the always-visible Tests-panel counter, if present
      var $testsCounter = $('#wpsa-pdf-counter-tests');
      if ($testsCounter.length) {
        var $strong = $testsCounter.find('strong');
        if (!$strong.length) {
          $strong = $('<strong></strong>').appendTo($testsCounter);
        }
        $strong.text(remaining + '/' + limit);
        if (remaining <= 3) {
          $strong.css('color', '#d32f2f');
        } else {
          $strong.css('color', '');
        }
      }
    }

    
    // ——————————————————————————
    // only populate *after* module7 finishes
    // ——————————————————————————
    $(document).on('wpsa_module7_done', function(){
      updatePdfButton(wpsaPdf.remaining, wpsaPdf.limit);
    });
    
    
    function generatePdf(e){
      e.preventDefault();
    
      // Always call server; Gatekeeper will reject if over‐quota.   
    
      const $btn = $(this).prop('disabled', true);
      const testUrl = $('input[name="test_url"]').val();
    
      $.post(wpsaPdf.ajaxUrl, {
        action: 'wpsa_pdf_report',
        nonce:  wpsaPdf.nonce,
        test_url: testUrl,
        // 👇 pass the loaded test number (0 = not loading from log)
        test_no: (window._wpsa_testNo || 0)
      })
      .done(function(r){
        // If server returned an error (e.g. over quota), show it and bail
        if ( ! r.success ) {
          const $wrap = $btn.closest('.wpsa-pdf-button-wrap');
          // remove any old notices
          $wrap.find('.notice').remove();
          const msg = r.data || 'PDF generation failed. Please retry.';

          const $notice = $('<div class="notice notice-error2"></div>');
          const $p = $('<p></p>');
          $p.append($('<strong></strong>').text('Error:'));
          $p.append(document.createTextNode(' ' + String(msg)));
          $notice.append($p);
          $wrap.append($notice);

          $btn.prop('disabled', false);
          return;
        }

        // refresh both buttons
        updatePdfButton(r.data.remaining, r.data.limit);

        // Prepare off-DOM HTML & strip tooltips
        const $off = $('<div>').html(r.data.html);
        $off.find('.custom-tooltip, .custom-tooltip + svg').remove();
        // Also remove all <details> and inline copies from the source before cloning sections
        $off.find('details, .wpsa-inline-details, .wpsa-inline-copy').remove();
        
        // Helper: prefer the off-DOM snapshot, but fall back to live DOM if a section is missing
        function fromOffOrLive(selector) {
          const $inOff = $off.find(selector);
          return $inOff.length ? $inOff : $(selector);
        }

        // Clone CWV Assessment row (field data), strip UI bits for PDF
        function cloneCwvAssessment(strat) {
          const $row = fromOffOrLive(`#perf-${strat} .wpsa-cwv-assessment-row`).first();
          if (!$row.length) return null;

          const $c = $row.clone(true, true);

          // remove UI controls + tooltips (PDF should be static)
          $c.find('.wpsa-psi-metrics-toggle, details, summary, .custom-tooltip, .custom-tooltip + svg, .wpsa-inline-details, .wpsa-inline-copy').remove();

          // make it compact + consistent in PDF
          $c.css({
            margin: '10px 0 8px',
            fontSize: '13px',
            lineHeight: '1.25'
          });

          // slight emphasis on the status without changing your existing text/icons
          $c.find('.wpsa-psi-metrics-value').css({
            fontWeight: '700'
          });

          return $c;
        }

      
      // Read config & apply (sets header text, removes CTA if configured)
        const cfg = wpsaGetPdfConfig($off[0]);
        wpsaApplyPdfBranding($off[0]);


      // Extract header & body
      const $hdr = $off.find('#pdf-header').first().clone()
                         .removeAttr('id').css({position:'static'});
      // add the orange bottom border on each page's header clone
      $hdr.css({ borderBottom: '2px solid #ff6b35', paddingBottom: '6px' });

      // Build a pair of mobile/desktop PSI screenshots for Page 1
      function buildPage1ScreensRow($offRoot) {
        const $root = $offRoot || $off;

        function pickShot(selLive, selOff) {
          let src = '';
          let $img = $(selLive).first();
          if (!$img.length) {
            $img = $root.find(selOff).first();
          }
          if ($img.length) {
            src = $img.attr('src') || '';
            if (!src && $img[0].dataset && $img[0].dataset.src) {
              src = $img[0].dataset.src;
            }
          }
          src = (src || '').trim();
          if (!src) return '';
          if (!/^data:image\//i.test(src) && !/^https?:\/\//i.test(src)) return '';
          return src;
        }

        const mobSrc = pickShot(
          '#wpsa-psi-screenshot-img-mobile, .wpsa-psi-screenshot-mobile img.wpsa-psi-screenshot-img, .wpsa-psi-screenshot-mobile img',
          '#wpsa-psi-screenshot-img-mobile, .wpsa-psi-screenshot-mobile img.wpsa-psi-screenshot-img, .wpsa-psi-screenshot-mobile img'
        );
        const deskSrc = pickShot(
          '#wpsa-psi-screenshot-img-desktop, .wpsa-psi-screenshot-desktop img.wpsa-psi-screenshot-img, .wpsa-psi-screenshot-desktop img',
          '#wpsa-psi-screenshot-img-desktop, .wpsa-psi-screenshot-desktop img.wpsa-psi-screenshot-img, .wpsa-psi-screenshot-desktop img'
        );

        if (!mobSrc && !deskSrc) return null;

        const $row = $('<div class="wpsa-pdf-hero-screens"></div>').css({
          display: 'flex',
          alignItems: 'flex-start',
          justifyContent: 'center',
          gap: '16px',
          margin: '10px 0 18px'
        });

        if (mobSrc) {
          const $boxM = $('<div></div>').css({
            width: '110px',
            height: '180px',
            overflow: 'hidden',
            borderRadius: '10px',
            border: '1px solid #ccc',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: '#ffffff'
          });
          const $imgM = $('<img>').attr('src', mobSrc).css({
            display: 'block',
            width: '100%',
            height: '100%',
            objectFit: 'contain'
          });
          $boxM.append($imgM);
          $row.append($boxM);
        }

        if (deskSrc) {
          const $boxD = $('<div></div>').css({
            width: '160px',
            overflow: 'hidden',
            borderRadius: '10px',
            border: '1px solid #ccc',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: '#ffffff'
          });
          const $imgD = $('<img>').attr('src', deskSrc).css({
            display: 'block',
            width: '100%',
            height: 'auto'
          });
          $boxD.append($imgD);
          $row.append($boxD);
        }

        return $row;
      }

      const $body = $off.find('#pdf-body').first();


          // Ensure container is fresh
        let $c = $('#report-container');
        if (!$c.length) $c = $('<div id="report-container"></div>').appendTo('body');
        $c.empty();

        // Inject mobile/desktop PSI screenshots on Page 1 (under "Tested URL")
        function wpsaInjectHeroScreensOnPage1(mobSrc, deskSrc) {
          if (!mobSrc && !deskSrc) return;

          const $firstPage = $c.children().first();
          if (!$firstPage.length) return;

          const $row = $('<div class="wpsa-pdf-hero-screens"></div>').css({
            display: 'flex',
            alignItems: 'flex-start',
            justifyContent: 'center',
            gap: '16px',
            margin: '10px 0 18px'
          });

          if (mobSrc) {
            const $boxM = $('<div></div>').css({
              width: '110px',
              height: '180px',
              overflow: 'hidden',
              borderRadius: '10px',
              border: '1px solid #ccc',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              background: '#ffffff'
            });
            const $imgM = $('<img>').attr('src', mobSrc).css({
              display: 'block',
              width: '100%',
              height: '100%',
              objectFit: 'contain'
            });
            $boxM.append($imgM);
            $row.append($boxM);
          }

        if (deskSrc) {
            const $boxD = $('<div></div>').css({
              width: '220px',
              height: '180px',          // match mobile height
              maxWidth: '260px',
              overflow: 'hidden',
              borderRadius: '10px',
              border: '1px solid #ccc',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              background: '#ffffff'
            });
            const $imgD = $('<img>').attr('src', deskSrc).css({
              display: 'block',
              width: '100%',
              height: '100%',           // fill box height
              objectFit: 'contain'      // preserve aspect ratio
            });
            $boxD.append($imgD);
            $row.append($boxD);
          }

          // Find the "Tested URL" line on Page 1 and insert row right after it
          let $urlP = $firstPage.find('p').filter(function () {
            return $(this).text().indexOf('Tested URL') !== -1;
          }).first();

          if ($urlP.length) {
            $urlP.after($row);
          } else {
            // Fallback: before "Introduction" heading, or at the end of the page
            const $introH2 = $firstPage.find('h2').filter(function () {
              return $(this).text().indexOf('Introduction') !== -1;
            }).first();
            if ($introH2.length) {
              $introH2.before($row);
            } else {
              $firstPage.append($row);
            }
          }
        }
  
      // Tweak logo size
      const $logo = $hdr.find('img').first();

      $logo.css({
        display: 'inline-block',
        transform: 'scale(0.80)',
        transformOrigin: 'center'
      });

      const $kids  = $body.children();
      const $mod1  = $body.find('#pdf-module-1').first().clone();
      const idx    = $kids.index($body.find('#pdf-module-1'));
      const $intro = $kids.slice(0, idx).clone();

      // Insert mobile/desktop PSI screenshots between "Tested URL" and "Introduction"
      (function () {
        const $screens = buildPage1ScreensRow($off);
        if (!$screens) return;

        // In the intro chunk, find the Tested URL paragraph (first <p>)
        let $urlP = $intro.filter('p').first();
        if (!$urlP.length) {
          $urlP = $intro.find('p').first();
        }

        if ($urlP.length) {
          $urlP.after($screens);
        } else {
          // Fallback: insert before the first H2 ("Introduction"), or at the end
          const $h2 = $intro.find('h2').first();
          if ($h2.length) {
            $h2.before($screens);
          } else {
            $intro.append($screens);
          }
        }
      })();

      // before building Page 6
      const $mod1Table = $off.find('#pdf-module-1 table tbody tr').first();

      const rawTTFB    = $mod1Table.children().last().text().trim();
      const ttfbMs     = parseInt(rawTTFB.replace(/\D+/g,''), 10) || 0;

      //
      // Page 1: Header + Intro + Module 1
      $c.append(
        $('<div></div>').append($hdr.clone(), $intro, $mod1)
      );

      
    //
    // Page 2: Module 2 — Page asset summary
    //
    const $page2 = $(`<div style="max-width:${WPSA_PAGE_MAX_W}px;margin:0 auto 24px;padding-top:18px;"></div>`)
     .append('<h2 style="margin:0 0 10px;font-size:16px;">2. Page asset summary</h2>');


    // ↓↓↓ holders reused on Page 7
    let $diagMobileForP7 = null;
    let $diagDesktopForP7 = null;
    let $insMobileForP7 = null;
    let $insDesktopForP7 = null;

    // These are reused on Page 6
    let $assetMobileSummary;
    let $assetDesktopSummary;
    // Also reuse Onload JS/CSS guidance in the Conclusion
     let $onloadMobileLine;   // NEW
    let $onloadDesktopLine;  // NEW

    // helper to read a metric that might be "N/A" or "0"
    function readMetric($el, asInt = true, zeroInvalid = true) {
      const raw = String($el.text() || '').trim();
      if (!raw || /^N\/A$/i.test(raw)) return null;
      const num = asInt ? parseInt(raw, 10) : parseFloat(raw.split(/\s+/)[0]);
      if (Number.isNaN(num)) return null;
      if (zeroInvalid && num === 0) return null;
      return num;
    }
    
    function shrinkDiagIcons($root){
      // normalize inline attrs on SVG/IMG
      $root.find('li svg, li img, .icon svg, .icon img').each(function(){
        if (this.removeAttribute) { this.removeAttribute('width'); this.removeAttribute('height'); }
        // set inline style to ensure it sticks even without our stylesheet
        this.style.width = '10px';
        this.style.height = '10px';
      });
    
      // dashicons / icon-fonts
      $root.find('li .dashicons, li i[class*="icon"], li span[class*="icon"]').each(function(){
        this.style.fontSize  = '10px';
        this.style.width     = '10px';
        this.style.height    = '10px';
        this.style.lineHeight= '10px';
        this.style.display   = 'inline-block';
        this.style.marginRight = '6px';
        this.style.verticalAlign = '-1px';
      });
    
      // let ::before solutions use a common size
      $root.find('li').each(function(){
        this.style.setProperty('--wpsa-diag-icon-size', '10px');
      });
    }
    
        function cloneDiagList(selector){
      // Prefer the $off snapshot, fall back to live DOM
      let $src = fromOffOrLive(selector).first();
      if (!$src.length) return null;
    
      let $node = $src.clone(true, true);
    
      // If diagnostics are inside <details>, open it and extract the first UL/OL
      if ($node.is('details')) {
        $node.prop('open', true);
        const $list = $node.find('ul,ol,.wpsa-diagnostics').first().clone(true, true);
        if ($list.length) $node = $list;
      }
    
      // Strip summary/tooltips and minor UI bits
      $node.find('summary, .custom-tooltip, .custom-tooltip + svg').remove();
      $node.css({ width: '100%', marginTop: 0 });
     $node = normalizeListItems($node);
      return $node;
    }




    ['mobile','desktop'].forEach(function (strat, i) {
      const num       = i + 1;
      const label     = strat.charAt(0).toUpperCase() + strat.slice(1);
      const topMargin = i === 1 ? 29 : 10; // 29 is top margin

      $page2.append(
          `<h3 style="margin:${topMargin}px 0 8px !important; font-size:14px;">2.${num}. ${label} results</h3>`
        );
        
        // Prefer the snapshot; fall back to live DOM. Be tolerant to minor ID variations.
        const $sec = fromOffOrLive(`#asset-${strat}, #assets-${strat}, #asset_${strat}`);
        
        if (!$sec.length) {
          console.warn('[WPSA PDF] Could not locate Module 2 section for', strat, 'in $off or live DOM.');
        }
        
        // strip leftover tooltips/icons from the source section (NOTE: we only remove on clones below)
        $sec.find('.custom-tooltip, .custom-tooltip + svg').remove();


      // 1) show any warning/error banner (but don't bail out)
      const $banner = $sec.find('.wpsa-asset-warning, .wpsa-asset-error, .notice-warning, .notice-error').first();
      if ($banner.length) {
        let msg = $banner.text().trim();
        if (msg === 'HTTP 429') msg = 'HTTP 429, please run again';
        if (msg) {
          $page2.append(
            `<p style="font-size:1.05em;color:#d32f2f;margin:10px 0 14px;"><strong>${msg}</strong></p>`
          );
        }
      }

      // 2) clone and render the stat cards for this section
      const $cardsWrap = $sec.find('.wpsa-stat-cards').first();
      if ($cardsWrap.length) {
      const $cards = $cardsWrap.clone().css('width', '100%');
        $cards.find('.custom-tooltip, .custom-tooltip + svg').remove();
        // remove tooltips and the expandable lists for the PDF
        $cards.find('.custom-tooltip, .custom-tooltip + svg, details, .wpsa-inline-details, .wpsa-inline-copy').remove();
        
        // enforce a 4-column grid like frontend
        $cards.css({
          display: 'grid',
          gridTemplateColumns: 'repeat(4, minmax(0, 1fr))',
          columnGap: '16px',
          rowGap: '16px',
          margin: '8px 0'
        });
        
        $cards.find('.label').css('font-size','12px');
        $cards.find('.value').css('font-size','14px');
        $cards.find('.wpsa-stat-card').css('min-height','72px');

        
        // NEW: footnote "*These values..." → full width, no wrap
        $cards.find('p, .wpsa-note, .note').filter(function () {
          const t = ($(this).text() || '').trim().toLowerCase();
          return t.startsWith('*these values') || t.startsWith('* these values') ||
                 $(this).hasClass('wpsa-note') || $(this).hasClass('note');
        }).first().css({
          gridColumn: '1 / -1',
          margin: '6px 0 0',
          fontSize: '.84em',
          lineHeight: '1.15',
          whiteSpace: 'nowrap'
        });
        
        // same height for all four cards in the PDF
        $cards.find('.wpsa-stat-card').css({
          minHeight: '86px',
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'center',
          boxSizing: 'border-box',
          width: 'auto'
        });

        
        $page2.append($cards);


        // Try to read Requests and Bytes from the cloned cards
        const req    = readMetric($cards.find('.wpsa-card-requests .value'), true,  true);
        const sizeKb = readMetric($cards.find('.wpsa-card-bytes .value'),   false, true);

        if (req !== null && sizeKb !== null) {
          const sizeMb = Math.round((sizeKb / 1024) * 10) / 10;
          const reqTh  = [50, 70], sizeTh = [2048, 3584];
          const rLv    = req    <= reqTh[0]  ? 0 : req    <= reqTh[1]  ? 1 : 2;
          const sLv    = sizeKb <= sizeTh[0] ? 0 : sizeKb <= sizeTh[1] ? 1 : 2;
          const cols   = ['#388e3c','#f9a825','#d32f2f'];
          
        let reqMsg;
          if (rLv === 0) {
            reqMsg = `Your request count is ${req} — no action needed.`;
          } else if (rLv === 1) {
            reqMsg = `You have ${req} requests; consider unloading/deferring assets with the tools like ${wpsaPdfUnloadToolsLinks}, and delay/defer the JS code with ${wpsaPdfFlyingPressWpRocketLink}.`;
          } else {
            reqMsg = `You have ${req} requests — minimize HTTP calls (bundle, lazy-load), unload unused resources with tools like ${wpsaPdfUnloadToolsLinks}, and delay/defer the JS code with ${wpsaPdfFlyingPressWpRocketLink}.`;
          }

          let sizeMsg;
          if (sLv === 0) {
            sizeMsg = `Your page size is ${sizeKb.toFixed(1)} KB — no action necessary.`;
          } else if (sLv === 1) {
            sizeMsg = `Your page size is ${sizeKb.toFixed(1)} KB (${sizeMb} MB); compress images and defer non-critical assets.`;
          } else {
            sizeMsg = `Your page size is ${sizeKb.toFixed(1)} KB (${sizeMb} MB) — minimize HTTP calls (bundle, lazy-load), unload unused resources with tools like ${wpsaPdfUnloadToolsLinks}, and delay/defer the JS code with ${wpsaPdfFlyingPressWpRocketLink}.`;
          }

        const $p1 = $(`<p style="font-size:1.1em;color:${cols[rLv]};margin-top:12px;">${reqMsg}</p>`);
        const $p2 = $(`<p style="font-size:1.1em;color:${cols[sLv]};">${sizeMsg}</p>`);
        $page2.append($p1, $p2);
        
        // store non-bold clones for Conclusion
        const pair = $([$p1[0].cloneNode(true), $p2[0].cloneNode(true)]);
        pair.each(function(){ $(this).find('strong').contents().unwrap(); });
        if (i === 0) $assetMobileSummary = pair; else $assetDesktopSummary = pair;
        }
          } else {
            // no stat cards -> test probably didn’t finish
            $page2.append(
              `<p style="font-size:1.05em;color:#d32f2f;"><strong>Test didn't finish properly, please run it again.</strong></p>`
            );
          }
     
        // 3) Onload guidance (JS/CSS) — counts + recommendations, split per-type
        (function(){
          const onJsRaw  = $sec.find('.wpsa-card-onload-js .value').first().text().trim();
          const onCssRaw = $sec.find('.wpsa-card-onload-css .value').first().text().trim();
        
          const nJs  = (onJsRaw.replace(/[^\d]+/g,'') || '') ? parseInt(onJsRaw.replace(/[^\d]+/g,''),10) : null;
          const nCss = (onCssRaw.replace(/[^\d]+/g,'') || '') ? parseInt(onCssRaw.replace(/[^\d]+/g,''),10) : null;
        
          // per-type severity (0=green, 1=amber, 2=red) — thresholds from compare.php
          function sevJs(n){
            if (n === null) return -1;
            if (n <= 11) return 0;
            if (n <= 16) return 1;
            return 2;
          }
          function sevCss(n){
            if (n === null) return -1;
            if (n <= 15) return 0;
            if (n <= 20) return 1;
            return 2;
          }
          const cols = ['#388e3c','#f9a825','#d32f2f','#666']; // last = neutral
        
          function line(label, n, isCss){
            const s = isCss ? sevCss(n) : sevJs(n);
            const color = cols[s === -1 ? 3 : s];
            let text;
            if (n === null)      text = `${label}: N/A — counts weren’t available for this run.`;
            else if (n === 0)    text = `${label}: 0 — no action needed.`;
            else if (isCss ? n <= 10 : n <= 10)
                                text = `${label}: ${n} — review and defer/unload where possible.`;
            else                 text = `${label}: ${n} — many onload files; unload when not needed and defer/delay scripts/styles.`;
            return $(`<p style="margin:6px 0 0; font-size:1.1em; line-height:1.35em; color:${color};"><em>${text}</em></p>`);
          }
        
          const $jsP  = line('Onload JS files',  nJs, false);
          const $cssP = line('Onload CSS files', nCss, true);
        
          // shared recommendation
         const $rec = $('<p style="margin:6px 0 0; font-size:0.95em; color:#444;">' +
            'Recommended tools: ' + wpsaPdfCodeUnloaderLink + ' or ' + wpsaPdfPerfmattersLink + '.' +
          '</p>');
        
          $page2.append($jsP, $cssP, $rec);
        })();


   
                  
        // --- concise Onload line for Page 6 (severity color, no bold) ---
        (function(){
              const onJsRaw  = $sec.find('.wpsa-card-onload-js .value').first().text().trim();
              const onCssRaw = $sec.find('.wpsa-card-onload-css .value').first().text().trim();
            
              const nJsStr  = onJsRaw.replace(/[^\d]+/g, '');
              const nCssStr = onCssRaw.replace(/[^\d]+/g, '');
              const nJs  = nJsStr ? parseInt(nJsStr, 10) : null;
              const nCss = nCssStr ? parseInt(nCssStr, 10) : null;
            
              // Use worst-of(JS) per compare.php thresholds (≤10 green, ≤15 yellow, >15 red)
            function sevOverall(n){
              if (n === null) return -1;
              if (n <= 11) return 0;
              if (n <= 16) return 1;
              return 2;
            }
            
            // Thresholds: CSS ≤10 green, 11–17 yellow, ≥18 red
             // Use worst-of(JS, CSS) per compare.php thresholds (≤10 green, ≤15 yellow, >15 red)
            function sevOverallCSS(n){
              if (n === null) return -1;
              if (n <= 15) return 0;
              if (n <= 20) return 1;
              return 2;
            }
            
            const sJs  = (nJs  === null) ? -1 : sevOverall(nJs);
            const sCss = (nCss === null) ? -1 : sevOverallCSS(nCss);
            
            let overall;
            if (sJs === -1 && sCss === -1)      overall = -1;
            else                                overall = Math.max(sJs, sCss);
            
            const colors = ['#388e3c','#f9a825','#d32f2f','#666'];
            let color = colors[overall === -1 ? 3 : overall];
            
            const a = (n)=> (typeof n === 'number' ? n : 'N/A');
            
            let line;
            if (overall === -1) {
              line = 'Onload JS/CSS counts are not available (N/A).';
            } else if (overall === 0) {
              line = 'No or few onload files detected — no action needed.';
            } else if (overall === 1) {
              line = `Some onload files detected (JS: ${a(nJs)}, CSS: ${a(nCss)}). Recommended tools: ${wpsaPdfUnloadToolsLinks}.`;
            } else {
              line = `Many onload files detected (JS: ${a(nJs)}, CSS: ${a(nCss)}). This often increases main-thread work and render delay. Prefer unloading non-critical assets on pages where they aren’t needed, and defer/delay scripts where possible. Recommended tools: ${wpsaPdfUnloadToolsLinks}.`;
            }
            
              const $concise = $(`<p style="font-size:1.1em;color:${color};margin-top:6px;">${line}</p>`);
              if (i === 0) $onloadMobileLine = $concise; else $onloadDesktopLine = $concise;
            })();
            
        });
                
       

    // FAQ for Module 2
    $page2.append(
          '<div style="margin:18px 0;font-size:0.92em;font-style:italic;line-height:1.3em;color:#333;">' +
            '<p style="margin:0 0 6px;">The Page Asset Summary measures the total number of HTTP requests your page makes (scripts, styles, images, etc.) and the combined gzip-compressed size of all assets in KB. Lower request counts and smaller payloads load faster and use less bandwidth.</p>' +
            '<p style="margin:0;">To improve these values, combine or defer non-critical assets, minify and compress files, lazy-load offscreen images, and remove unused resources whenever possible.</p>' +
          '</div>'
        );


    // finally append Page 2 into the PDF container
    $c.append(
     wpsaNewPdfPage().append($hdr.clone(), $page2)
    );
    
    
    // Build a simple SVG gauge for the PDF (used when the live widget isn't painted)
        function wpsaGauge(score){
          const s = (typeof score === 'number' && score >= 0 && score <= 100) ? score : null;
          const color = s === null ? '#999' : (s < 50 ? '#d32f2f' : (s < 90 ? '#f9a825' : '#388e3c'));
          const r = 45, c = 2 * Math.PI * r;
          const pct = s === null ? 0 : s / 100;
          const dash = (c * pct).toFixed(1), gap = (c - dash).toFixed(1);
          const $wrap = $('<div style="width:120px; margin:8px auto;"></div>');
          $wrap.html(
            `<svg width="120" height="120" viewBox="0 0 120 120" role="img" aria-label="Performance score">
               <circle cx="60" cy="60" r="${r}" fill="none" stroke="#eee" stroke-width="7"/>
               <circle cx="60" cy="60" r="${r}" fill="none" stroke="${color}" stroke-width="7"
                       stroke-linecap="round" transform="rotate(-90 60 60)"
                       stroke-dasharray="${dash} ${gap}"/>
               <text x="60" y="68" text-anchor="middle" font-family="Helvetica, Arial, sans-serif"
                     font-size="28" fill="${color}">${s === null ? '--' : s}</text>
             </svg>`
          );
          return $wrap;
        }
        
        // Remember PSI screenshots for re-use on Page 1
        let _wpsaMobShotSrcForPage1 = '';
        let _wpsaDeskShotSrcForPage1 = '';

         //
        // Page 3: Module 5 — Performance & Diagnostics (MOBILE only)
        // 
        const $page3 = $(`<div class="page--perf" style="max-width:${WPSA_PAGE_MAX_W}px;margin:0 auto 20px;padding-top:10px;"></div>`)
          .append('<h2 style="margin:0 0 12px;font-size:16px;">3. Performance & Diagnostics</h2>')
          .append(
            '<h3 style="margin:10px 0 8px;font-size:14px;">' +
              '3.1. Mobile results <span class="dashicons dashicons-smartphone" style="vertical-align:middle;"></span> — ' +
              '<a href="https://pagespeed.web.dev/" target="_blank" rel="noopener">Google PSI test</a> score' +
            '</h3>'
          );
      
        // Build an SVG gauge for the PDF instead of cloning the CSS widget
        const $rawMNode = $('#perf-circle-mobile .perf-text').first();
        const rawM = $rawMNode.length ? $rawMNode.text().trim() : '';
        const scoreM = rawM.match(/\d+/) ? parseInt(rawM, 10) : null;
        const $circleM = wpsaGauge(scoreM).css({
          margin: '2px 0 2px',
          transform: 'scale(0.80)',
          transformOrigin: 'center'
        });

        // Build a fresh screenshot box from the data:image URL (mobile)
        let screenshotSrcM = '';

        // 1) Prefer the live admin DOM (original PSI widget)
        let $shotSrcM = $('#wpsa-psi-screenshot-img-mobile');

        if (!$shotSrcM.length) {
          // Fallback: other live selectors on the admin page
          $shotSrcM = $('.wpsa-psi-screenshot-mobile img.wpsa-psi-screenshot-img, .wpsa-psi-screenshot-mobile img').first();
        }

        if (!$shotSrcM.length) {
          // 2) Last resort: look in the off-DOM snapshot used for PDF
          $shotSrcM = $off.find(
            '#wpsa-psi-screenshot-img-mobile, ' +
            '.wpsa-psi-screenshot-mobile img.wpsa-psi-screenshot-img, ' +
            '.wpsa-psi-screenshot-mobile img'
          ).first();
        }
        if ($shotSrcM.length) {
          screenshotSrcM = $shotSrcM.attr('src') || '';

          // Some setups may stash base64 in data-src instead of src
          if (!screenshotSrcM && $shotSrcM[0].dataset && $shotSrcM[0].dataset.src) {
            screenshotSrcM = $shotSrcM[0].dataset.src;
          }

          _wpsaMobShotSrcForPage1 = screenshotSrcM;
          console.log('[WPSA PDF] mobile screenshot src length:', screenshotSrcM.length);
        } else {
          console.warn('[WPSA PDF] mobile screenshot IMG not found for PDF.');
        }

        let $shotMNode = null;
        if (screenshotSrcM && /^data:image\//i.test(screenshotSrcM)) {
          // Wrapper: fixed box so the page doesn’t spill; inner <img> uses object-fit:contain
          $shotMNode = $('<div class="wpsa-psi-screenshot-box wpsa-psi-screenshot-mobile"></div>').css({
            margin: '0 0 2px',
            width: '120px',
            height: '180px',
            maxHeight: '180px',
            overflow: 'hidden',
            borderRadius: '10px',
            border: '1px solid #ccc',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: '#ffffff'
          });

          const $imgM = $('<img>').attr('src', screenshotSrcM).css({
            display: 'block',
            width: '100%',
            height: '100%',
            objectFit: 'contain'
          });

          $shotMNode.append($imgM);
        }

               // Header row: perf circle + mobile screenshot, centered on the page
         const $rowM = $('<div style="display:flex;align-items:center;justify-content:center;gap:18px;margin:2px 0 8px;"></div>');

        // Track CWV failure for this page
        let cwvFailedM = false;


        $rowM.append($circleM);
        if ($shotMNode) {
          $rowM.append($shotMNode);
        }

        $page3.append($rowM);

        // CWV field assessment (Mobile)
        (function(){
          const $cwvM = cloneCwvAssessment('mobile');
          if ($cwvM) {
            $page3.append($cwvM);

            const isFailed = /failed/i.test(($cwvM.text() || ''));
            if (isFailed) {
              $page3.append(
                '<p style="margin:6px 0 0;font-size:1.0em;color:#d32f2f;">' +
                  '<strong>Core Web Vitals are failing — you should work on improving them.</strong>' +
                '</p>'
              );
            }
          }
        })();




        if (scoreM === null) {


          $page3.append(
            '<p style="font-size:1.1em;color:#c33;margin-top:30px;">' +
              '<strong>The test didn\'t finish properly, please run it again.</strong>' +
            '</p>'
          );
        } else {
          // Feedback line (wording/colors exactly as before)
          let fbColorM, fbMsgM;
          if (scoreM < 50)      fbColorM = '#d32f2f', fbMsgM = "That's a bad score. Plenty to improve, see Diagnostics.";
          else if (scoreM < 90) fbColorM = '#f9a825', fbMsgM = "That's mediocre — address Diagnostics to boost it.";
          else                  fbColorM = '#388e3c', fbMsgM = "That's an excellent score!";
        
          $page3.append(
            `<p style="font-size:1.1em;color:${fbColorM};margin-top:10px; margin-bottom:0px;"><strong>The performance score is <b>${scoreM}</b>. ${fbMsgM}</strong></p>`
          );
        
          // Metric cards from the mobile panel (clone + light scale)
          const $metM = $('#perf-mobile .wpsa-stat-cards').first()
          .clone()
          .css({ width:'100%', marginTop:'10px', transform:'scale(0.80)', transformOrigin:'center' });


          $metM.find('.custom-tooltip').remove();
          $page3.append($metM);
        
          // LCP / FCP extraction and guidance (unchanged)
          const lcpRawM = $metM.find('.wpsa-card-lcp .value').text().trim();
          const fcpRawM = $metM.find('.wpsa-card-fcp .value').text().trim();
        
          const lcpNumM = parseFloat(lcpRawM);
          const fcpNumM = parseFloat(fcpRawM);
        
          const lcpMsM = Number.isNaN(lcpNumM) ? null : Math.round(lcpNumM * 1000);
          const fcpMsM = Number.isNaN(fcpNumM) ? null : Math.round(fcpNumM * 1000);
        
          if (lcpMsM === null || fcpMsM === null) {
            $page3.append(
              `<p style="font-size:1.1em;color:#d32f2f;margin-top:30px;"><strong>LCP/FCP are not available (N/A) — please run the test again.</strong></p>`
            );
          } else {
            const lTh = [2500, 4000], fTh = [1800, 3000];
            const lMsgs = [
              `Your LCP is ${lcpRawM} — no action needed.`,
              `Your LCP is ${lcpRawM}; moderate — <a href="https://web.dev/articles/optimize-lcp#summary" target="_blank" rel="noopener">optimize critical elements</a>.`,
              `Your LCP is ${lcpRawM}; slow — <a href="https://web.dev/articles/optimize-lcp#summary" target="_blank" rel="noopener">optimize critical elements</a>. Reduce render-blocking CSS/JS, remove unused CSS, preload LCP, defer JS.`
            ];
            const fMsgs = [
              `Your FCP is ${fcpRawM} — no action needed.`,
              `Your FCP is ${fcpRawM}; moderate — <a href="https://developer.chrome.com/docs/lighthouse/performance/first-contentful-paint/#how_to_improve_your_fcp_score" target="_blank" rel="noopener">optimize first-paint elements</a>.`,
              `Your FCP is ${fcpRawM}; slow — optimize <a href="https://developer.chrome.com/docs/lighthouse/performance/first-contentful-paint/#how_to_improve_your_fcp_score" target="_blank" rel="noopener">first-paint elements</a>. Watch font load time.`
            ];
            const lLvM = lcpMsM <= lTh[0] ? 0 : lcpMsM <= lTh[1] ? 1 : 2;
            const fLvM = fcpMsM <= fTh[0] ? 0 : fcpMsM <= fTh[1] ? 1 : 2;
            const statusCols = ['#388e3c','#f9a825','#d32f2f'];
        
            $page3.append(`<p style="font-size:1em;color:${statusCols[lLvM]};margin-top:20px;"><strong>${lMsgs[lLvM]}</strong></p>`);
            $page3.append(`<p style="font-size:1em;color:${statusCols[fLvM]};"><strong>${fMsgs[fLvM]}</strong></p>`);
            
            //1.16.5: show CLS and INP immediately after FCP (all combinations handled)
            (function(){
              // read raw values from the metric cards
              const clsRawM = $metM.find('.wpsa-card-cls .value').text().trim();
              const inpRawM = $metM.find('.wpsa-card-inp .value').text().trim();
            
              // helpers: parse CLS and INP
              function parseCls(raw){
                if (!raw || /^N\/A$/i.test(raw)) return null;
                const n = parseFloat(String(raw).replace(',', '.'));
                return Number.isNaN(n) ? null : n;
              }
              function parseInpMs(raw){
                if (!raw || /^N\/A$/i.test(raw)) return null;
                const m = String(raw).match(/([\d.,]+)\s*(ms|s)?/i);
                if (!m) return null;
                const num  = parseFloat(m[1].replace(',', '.'));
                const unit = (m[2] || 'ms').toLowerCase();
                if (!Number.isFinite(num)) return null;
                return unit === 's' ? Math.round(num * 1000) : Math.round(num);
              }
            
              // thresholds (Core Web Vitals)
              function clsLevel(v){               // 0=good, 1=needs-improvement, 2=poor
                if (v == null) return -1;
                if (v <= 0.10) return 0;
                if (v <= 0.25) return 1;
                return 2;
              }
              function inpLevel(ms){              // 0=good, 1=needs-improvement, 2=poor
                if (ms == null) return -1;
                if (ms <= 200) return 0;
                if (ms <= 500) return 1;
                return 2;
              }
            
              const clsValM = parseCls(clsRawM);
              const inpMsM  = parseInpMs(inpRawM);
            
              const clsLvM  = clsLevel(clsValM);
              const inpLvM  = inpLevel(inpMsM);
            
              // messages (mirror LCP/FCP tone; add ✅ + “— no action needed.” for green)
              const clsMsgsM = [
                `Your CLS is ${clsRawM} — no action needed.`,
                `Your CLS is ${clsRawM} — reduce layout shift: set width/height for images & iframes, reserve space for ads/embeds, avoid late font swaps.`,
                `Your CLS is ${clsRawM} — large layout shifts. Reserve space for media/ads, pre-size containers, avoid inserting content above existing content.`
              ];
              const inpMsgsM = [
                `Your INP is ${inpMsM} ms — no action needed.`,
                `Your INP is ${inpMsM} ms — reduce main-thread work and long tasks; optimize input handlers and third-party scripts.`,
                `Your INP is ${inpMsM} ms — poor input responsiveness. Break up long tasks, defer non-critical JS, and optimize event listeners.`
              ];
            
              // CLS line (falls back to neutral if metric is N/A)
              if (clsLvM === -1){
                $page3.append(`<p style="font-size:1em;color:#666;"><strong>Your CLS is N/A — metric not available for this run.</strong></p>`);
              } else {
                $page3.append(`<p style="font-size:1em;color:${statusCols[clsLvM]};"><strong>${clsMsgsM[clsLvM]}</strong></p>`);
              }
            
              // INP line (falls back to neutral if metric is N/A)
              if (inpLvM === -1){
                $page3.append(`<p style="font-size:1em;color:#666;margin-bottom:20px;"><strong>Your INP is N/A — metric not available for this run.</strong></p>`);
              } else {
                $page3.append(`<p style="font-size:1em;color:${statusCols[inpLvM]};"><strong>${inpMsgsM[inpLvM]}</strong></p>`);
              }
            })();

     
     

            
         $page3.append('<div style="margin-top:22px;"></div>');
        $page3.append('<h4 style="margin-top:0;font-size:14px;">Diagnostics &amp; Insights — top 5 each</h4>');
        
        const $wrap3 = $('<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;"></div>');
        
        const $diagM = robustCloneList('mobile','diag') || $('<ul></ul>').append('<li style="color:#666">No diagnostics returned for this run.</li>');
        const $insM  = robustCloneList('mobile','ins')  || $('<ul></ul>').append('<li style="color:#666">No insights returned for this run.</li>');
        
        $wrap3
          .append($('<div></div>')
            .append('<div style="font-weight:600;margin:0 0 6px;">Diagnostics (top 5)</div>', $diagM))
          .append($('<div></div>')
            .append('<div style="font-weight:600;margin:0 0 6px;">Insights (top 5)</div>', $insM));
        
        $page3.append($wrap3);
        
        // keep stripped copies for Page 7
        $diagMobileForP7 = $diagM.clone(true,true);
        $diagMobileForP7.find('summary, .custom-tooltip, .custom-tooltip + svg').remove();
        
        $insMobileForP7 = $insM.clone(true,true);
        $insMobileForP7.find('summary, .custom-tooltip, .custom-tooltip + svg').remove();
            
            $page3.append(
              '<p style="font-size:0.85em;color:#666;margin-top:10px;">' +
                'The diagnostic section outlines some opportunities, and you need to concentrate your efforts there. Usually, you can\'t get them all, but you should get as many as possible.' +
              '</p>'
            );
          }
        }
        
       
        $c.append(
          wpsaNewPdfPage().append($hdr.clone(), $page3)
        );

        
              //
        // Page 4: Module 5 — Performance & Diagnostics (DESKTOP only)
        // (reverted to v0.157a behavior)
        //
        const $page4 = $(`<div style="max-width:${WPSA_PAGE_MAX_W}px;margin:0 auto 24px;padding-top:16px;"></div>`)
          .append(
            '<h3 style="margin:10px 0 8px;font-size:14px;">' +
              '3.2. Desktop results <span class="dashicons dashicons-desktop" style="vertical-align:middle;"></span> — ' +
              '<a href="https://pagespeed.web.dev/" target="_blank" rel="noopener">Google PSI test</a> score' +
            '</h3>'
          );
        
        // Build an SVG gauge for the PDF instead of cloning the CSS widget
        const $rawDNode = $('#perf-circle-desktop .perf-text').first();
        const rawD = $rawDNode.length ? $rawDNode.text().trim() : '';
        const scoreD = rawD.match(/\d+/) ? parseInt(rawD, 10) : null;

        const $circleD = wpsaGauge(scoreD).css({
          margin: '2px 0 2px',
          transform: 'scale(0.90)',
          transformOrigin: 'center'
        });

        // Build a fresh screenshot box from the data:image URL (desktop)
        let screenshotSrcD = '';

        // 1) Prefer the live admin DOM (original PSI widget)
        let $shotSrcD = $('#wpsa-psi-screenshot-img-desktop');

        if (!$shotSrcD.length) {
          // Fallback: other live selectors on the admin page
          $shotSrcD = $('.wpsa-psi-screenshot-desktop img.wpsa-psi-screenshot-img, .wpsa-psi-screenshot-desktop img').first();
        }

        if (!$shotSrcD.length) {
          // 2) Last resort: look in the off-DOM snapshot used for PDF
          $shotSrcD = $off.find(
            '#wpsa-psi-screenshot-img-desktop, ' +
            '.wpsa-psi-screenshot-desktop img.wpsa-psi-screenshot-img, ' +
            '.wpsa-psi-screenshot-desktop img'
          ).first();
        }

        if ($shotSrcD.length) {
          screenshotSrcD = $shotSrcD.attr('src') || '';

          // Some setups may stash base64 in data-src instead of src
          if (!screenshotSrcD && $shotSrcD[0].dataset && $shotSrcD[0].dataset.src) {
            screenshotSrcD = $shotSrcD[0].dataset.src;
          }

          _wpsaDeskShotSrcForPage1 = screenshotSrcD;
          console.log('[WPSA PDF] desktop screenshot src length:', screenshotSrcD.length);
        } else {
          console.warn('[WPSA PDF] desktop screenshot IMG not found for PDF.');
        }
        
        // PSI desktop size
        let $shotDNode = null;
        if (screenshotSrcD && /^data:image\//i.test(screenshotSrcD)) {
          // Desktop: wider preview, let the image control the height so it fully fills the frame
          $shotDNode = $('<div class="wpsa-psi-screenshot-box wpsa-psi-screenshot-desktop"></div>').css({
            margin: '0 0 2px',
            width: '180px',          
            overflow: 'hidden',
            borderRadius: '12px',
            border: '1px solid #ccc',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: '#ffffff'
          });

          const $imgD = $('<img>').attr('src', screenshotSrcD).css({
            display: 'block',
            width: '100%',
            height: 'auto'           // keep aspect ratio, no extra white bands
          });

          $shotDNode.append($imgD);
        }




        const $rowD = $('<div style="display:flex;align-items:center;justify-content:center;gap:24px;margin:6px 0 10px;"></div>');

        // Track CWV failure for this page
        let cwvFailedD = false;

        $rowD.append($circleD);
        if ($shotDNode) {
          $rowD.append($shotDNode);
        }

        $page4.append($rowD);

        // CWV field assessment (Desktop)
        (function(){
          const $cwvD = cloneCwvAssessment('desktop');
          if ($cwvD) {
            $page4.append($cwvD);

            const isFailed = /failed/i.test(($cwvD.text() || ''));
            if (isFailed) {
              $page4.append(
                '<p style="margin:6px 0 0;font-size:1.0em;color:#d32f2f;">' +
                  '<strong>Core Web Vitals are failing — you should work on improving them.</strong>' +
                '</p>'
              );
            }
          }
        })();




        if (scoreD === null) {

          $page4.append(
            '<p style="font-size:1em;color:#c33;margin-top:30px;">' +
              '<strong>The test didn\'t finish properly, please run it again.</strong>' +
            '</p>'
          );
        } else {
          let fbColorD, fbMsgD;
          if (scoreD < 50)      fbColorD = '#d32f2f', fbMsgD = "That's a bad score. Plenty to improve.";
          else if (scoreD < 90) fbColorD = '#f9a825', fbMsgD = "That's mediocre — see Diagnostics.";
          else                  fbColorD = '#388e3c', fbMsgD = "That's great!";
        
          $page4.append(
            `<p style="font-size:1em;color:${fbColorD};margin-top:10px; margin-bottom:0px;"><strong>The performance score is <b>${scoreD}</b>. ${fbMsgD}</strong></p>`
          );
        
          const $metD = $('#perf-desktop .wpsa-stat-cards').first()
            .clone()
            .css({ width:'100%', marginTop:'20px', transform:'scale(0.90)', transformOrigin:'center' });
          $metD.find('.custom-tooltip').remove();
          $page4.append($metD);
        
          const lcpRawD = $metD.find('.wpsa-card-lcp .value').text().trim();
          const fcpRawD = $metD.find('.wpsa-card-fcp .value').text().trim();
          const lcpNumD = parseFloat(lcpRawD);
          const fcpNumD = parseFloat(fcpRawD);
          const lcpMsD  = Number.isNaN(lcpNumD) ? null : Math.round(lcpNumD * 1000);
          const fcpMsD  = Number.isNaN(fcpNumD) ? null : Math.round(fcpNumD * 1000);
        
          if (lcpMsD === null || fcpMsD === null) {
            $page4.append(
              `<p style="font-size:1.1em;color:#d32f2f;margin-top:30px;"><strong>LCP/FCP are not available (N/A) — please run the test again.</strong></p>`
            );
          } else {
            const lThD = [2500,4000], fThD = [1800,3000];
            const lMsgsD = [
              `Your LCP is ${lcpRawD} — no action needed.`,
              `Your LCP is ${lcpRawD}; moderate — <a href="https://web.dev/articles/optimize-lcp#summary" target="_blank" rel="noopener">optimize critical elements</a>.`,
              `Your LCP is ${lcpRawD}; slow — <a href="https://web.dev/articles/optimize-lcp#summary" target="_blank" rel="noopener">optimize critical elements</a>. Reduce render-blocking CSS/JS, remove unused CSS, preload LCP, defer JS.`
            ];
            const fMsgsD = [
              `Your FCP is ${fcpRawD} — no action needed.`,
              `Your FCP is ${fcpRawD}; moderate — <a href="https://developer.chrome.com/docs/lighthouse/performance/first-contentful-paint/#how_to_improve_your_fcp_score" target="_blank" rel="noopener">optimize first-paint elements</a>.`,
              `Your FCP is ${fcpRawD}; slow — optimize <a href="https://developer.chrome.com/docs/lighthouse/performance/first-contentful-paint/#how_to_improve_your_fcp_score" target="_blank" rel="noopener">first-paint elements</a>. Watch font load time.`
            ];
            const lLvD = lcpMsD <= lThD[0] ? 0 : lcpMsD <= lThD[1] ? 1 : 2;
            const fLvD = fcpMsD <= fThD[0] ? 0 : fcpMsD <= fThD[1] ? 1 : 2;
            const statusColsD = ['#388e3c','#f9a825','#d32f2f'];
        
            $page4.append(`<p style="font-size:1em;color:${statusColsD[lLvD]};margin-top:20px;"><strong>${lMsgsD[lLvD]}</strong></p>`);
            $page4.append(`<p style="font-size:1em;color:${statusColsD[fLvD]};"><strong>${fMsgsD[fLvD]}</strong></p>`);
            
            // 1.16.5: show CLS and INP immediately after FCP (all combinations handled)
            (function(){
              const clsRawD = $metD.find('.wpsa-card-cls .value').text().trim();
              const inpRawD = $metD.find('.wpsa-card-inp .value').text().trim();
            
              function parseCls(raw){
                if (!raw || /^N\/A$/i.test(raw)) return null;
                const n = parseFloat(String(raw).replace(',', '.'));
                return Number.isNaN(n) ? null : n;
              }
              function parseInpMs(raw){
                if (!raw || /^N\/A$/i.test(raw)) return null;
                const m = String(raw).match(/([\d.,]+)\s*(ms|s)?/i);
                if (!m) return null;
                const num  = parseFloat(m[1].replace(',', '.'));
                const unit = (m[2] || 'ms').toLowerCase();
                if (!Number.isFinite(num)) return null;
                return unit === 's' ? Math.round(num * 1000) : Math.round(num);
              }
            
              function clsLevel(v){ if (v == null) return -1; if (v <= 0.10) return 0; if (v <= 0.25) return 1; return 2; }
              function inpLevel(ms){ if (ms == null) return -1; if (ms <= 200) return 0; if (ms <= 500) return 1; return 2; }
            
              const clsValD = parseCls(clsRawD);
              const inpMsD  = parseInpMs(inpRawD);
            
              const clsLvD  = clsLevel(clsValD);
              const inpLvD  = inpLevel(inpMsD);
            
              const clsMsgsD = [
                `Your CLS is ${clsRawD} — no action needed.`,
                `Your CLS is ${clsRawD} — reduce layout shift: set width/height for images & iframes, reserve space for ads/embeds, avoid late font swaps.`,
                `Your CLS is ${clsRawD} — large layout shifts. Reserve space for media/ads, pre-size containers, avoid inserting content above existing content.`
              ];
              const inpMsgsD = [
                `Your INP is ${inpMsD} ms — no action needed.`,
                `Your INP is ${inpMsD} ms — reduce main-thread work and long tasks; optimize input handlers and third-party scripts.`,
                `Your INP is ${inpMsD} ms — poor input responsiveness. Break up long tasks, defer non-critical JS, and optimize event listeners.`
              ];
            
              if (clsLvD === -1){
                $page4.append(`<p style="font-size:1em;color:#666;"><strong>Your CLS is N/A — metric not available for this run.</strong></p>`);
              } else {
                $page4.append(`<p style="font-size:1em;color:${statusColsD[clsLvD]};"><strong>${clsMsgsD[clsLvD]}</strong></p>`);
              }
            
              if (inpLvD === -1){
                $page4.append(`<p style="font-size:1em;color:#666;margin-bottom:20px;"><strong>Your INP is N/A — metric not available for this run.</strong></p>`);
              } else {
                $page4.append(`<p style="font-size:1em;color:${statusColsD[inpLvD]};"><strong>${inpMsgsD[inpLvD]}</strong></p>`);
              }
            })();
            
            


        
            $page4.append('<div style="margin-top:22px;"></div>');
            $page4.append('<h4 style="margin-top:0;font-size:14px;">Diagnostics &amp; Insights — top 5 each</h4>');
            
            const $wrap4 = $('<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;"></div>');
            
            const $diagD = robustCloneList('desktop','diag') || $('<ul></ul>').append('<li style="color:#666">No diagnostics returned for this run.</li>');
            const $insD  = robustCloneList('desktop','ins')  || $('<ul></ul>').append('<li style="color:#666">No insights returned for this run.</li>');
            
            $wrap4
              .append($('<div></div>')
                .append('<div style="font-weight:600;margin:0 0 6px;">Diagnostics (top 5)</div>', $diagD))
              .append($('<div></div>')
                .append('<div style="font-weight:600;margin:0 0 6px;">Insights (top 5)</div>', $insD));
            
            $page4.append($wrap4);
            
            // keep stripped copies for Page 7
            $diagDesktopForP7 = $diagD.clone(true,true);
            $diagDesktopForP7.find('summary, .custom-tooltip, .custom-tooltip + svg').remove();
            
            $insDesktopForP7 = $insD.clone(true,true);
            $insDesktopForP7.find('summary, .custom-tooltip, .custom-tooltip + svg').remove();


            
            $page4.append(
              '<p style="font-size:0.85em;color:#666;margin-top:10px;">' +
                'The diagnostic section outlines some opportunities, and you need to concentrate your efforts there. Usually, you can\'t get them all, but you should get as many as possible.' +
              '</p>'
            );
          }
        }
        
        $c.append(
          wpsaNewPdfPage().append($hdr.clone(), $page4)
        );

        
        // After both mobile & desktop screenshots are known, place them on Page 1
        wpsaInjectHeroScreensOnPage1(_wpsaMobShotSrcForPage1, _wpsaDeskShotSrcForPage1);
     

    //
    // Page 5: Autoloaded, 4.5 Various, & Persistent-cache
    //
    // TEMP: force Top-10 table to render even when autoload size <= 800 KB (for visual testing).
    // Set to false to restore the normal behavior (only when > 800 KB).
    const FORCE_TOP10_FOR_TEST = false;  //switch with true/false
    const $page5 = $(`<div style="max-width:${WPSA_PAGE_MAX_W}px;margin:0 auto 24px;padding-top:20px;"></div>`);
    
    // small HTML escaper for safe text injection
    function esc(s){return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));}
    
    // 4. Autoloaded options size heading
           $page5.append(
          '<h2 style="margin:0 0 10px;font-size:16px;">4. Autoloaded options size</h2>' +
          '<p style="font-size:1em;margin:8px 0 16px;">-server-wide metric</p>'
        );

    
    // 4.1) Clone the admin card, remove tooltip
    const $sum3 = $('#summary-module_3')
      .clone()
      .css({ display:'block', width:'360px', margin:'12px auto 12px' })
      .find('.custom-tooltip').remove().end();
    
    // normalize background later; detach any “yellow” class
    $sum3.removeClass('wpsa-card-autoload');
    
    // 4.2) Extract KB value and decide color
    const text3 = $sum3.find('.value').text().trim();
    let kb = 0;
    let bg;
    if ( text3 === 'N/A' ) {
      bg = '#dddddd';
    } else {
      kb = parseFloat(text3) || 0;
      bg = kb <= 800 ? '#c6f7c3' : '#ffdddd';
    }
    $sum3.css('background-color', bg);
    
    // 4.3) Red-case: try to place Top-10 autoloaded table BESIDE the card
       function findAutoloadTop10() {
      // 1) Expand any <details> or toggles that hold the Top-10 label.
      $('details, .details, .toggle').each(function () {
        const txt = $(this).text();
        if (/Top\s*10\s*autoloaded options by size/i.test(txt)) { this.open = true; }
      });
      $('summary, a, button, .toggle, .disclosure')
        .filter(function(){ return /Top\s*10\s*autoloaded options by size/i.test($(this).text()); })
        .each(function(){ try { this.click(); } catch(e){} });
    
      // 2) Try direct, likely selectors first.
      const candidates = $('#wpsa-autoload-top10, #autoload-top10, #autoloadTop10, [data-autoload-top10], .wpsa-autoload-top10, .autoload-top10');
      let $tbl = null;
      if (candidates.length) {
        candidates.each(function(){
          const t = $(this).is('table') ? $(this) : $(this).find('table').first();
          if (t.length) { $tbl = t; return false; }
        });
      }
    
      // 3) Fallback: look near Module 3, then anywhere.
      if (!$tbl) {
        let $scope = $('#summary-module_3, #module-3, .wpsa-module-3-4').first();
        if (!$scope.length) $scope = $('body');
        $scope.find('table').each(function(){
          const head = $(this).find('th').map(function(){return $(this).text().toLowerCase();}).get().join('|');
          const txt  = head || $(this).text().toLowerCase();
          if (txt.includes('option_name') && txt.includes('size')) { $tbl = $(this); return false; }
        });
      }
      return $tbl ? $tbl.clone() : null;
    }

    
    // 4.4) Append Module 3 section
    const showTop10 = FORCE_TOP10_FOR_TEST || (kb > 800);
    
    if (text3 === 'N/A') {
      $page5.append($sum3);
      $page5.append(
        '<p style="font-size:1.1em;margin:6px 0 8px;">' +
          '<strong>Not available. You can test Autoloaded options size only on the same server.</strong>' +
        '</p>'
      );
    } else if (showTop10) {
     // --- COMPACT Top-10 beside the card (no transform → no blank pages) ---
    const $top10 = findAutoloadTop10();
    if ($top10) {
      const CARD_W = 300;   // px for the autoload card
      const TBL_W  = 220;   // px for the table block (~half size). Tweak 220–260.
    
      // Compact table styling (real smaller layout)
      $top10
        .css({
          width: '100%',
          borderCollapse: 'collapse',
          tableLayout: 'fixed',
          fontSize: '8.5px',
          lineHeight: '1.2'
        })
        .find('th,td').css({
          border: '1px solid #ccc',
          padding: '2px 3px',
          wordBreak: 'break-word'
        }).end()
        .find('th').css({ background: '#f7f7f7', fontWeight: '600' }).end()
        // make "size" column narrow so we can keep overall width small
        .find('tr').each(function () {
          $(this).children().last().css({ width: '56px' });
        });
    
      // One-line row: card + table
      const $row = $('<div style="display:flex;gap:14px;align-items:flex-start;justify-content:flex-start;margin-bottom:6px;"></div>');
    
      // Fixed-width card
      $sum3.css({ flex: `0 0 ${CARD_W}px`, maxWidth: `${CARD_W}px`, margin: '0' });
    
      // Fixed-width table wrapper (real width, no transforms)
      const $tblWrap = $('<div>').css({ flex: `0 0 ${TBL_W}px`, maxWidth: `${TBL_W}px` })
        .append(
          '<div style="font-size:11px;margin:0 0 6px;font-weight:600;">Top 10 autoloaded options by size</div>',
          $top10
        );
    
      $row.append($sum3, $tblWrap);
      $page5.append($row);
    } else {
      // fallback: just the card
      $page5.append($sum3);
    }

  // status message (truthful regardless of FORCE_TOP10_FOR_TEST)
  $page5.append(
    kb <= 800
      ? `<p style="font-size:1.1em;color:#388e3c;margin:6px 0 8px;">
           <strong>Your autoloaded options size is ${esc(kb.toFixed(1))} KB — no action needed.</strong>
         </p>`
      : `<p style="font-size:1.1em;color:#d32f2f;margin:6px 0 8px;">
           <strong>Your autoloaded options size is ${esc(kb.toFixed(1))} KB; too large — remove unused autoloaded options. Read more <a href="https://wpservice.pro/maintenance/wordpress-database-optimization-how-to-keep-your-site-fast-and-healthy/#autoload" target="_blank" rel="noopener">here</a>.</strong>
         </p>`
  );

    
    } else {
      // No Top-10 shown (normal behavior when ≤800 and not forcing)
      $page5.append($sum3);
      $page5.append(
        kb <= 800
          ? `<p style="font-size:1.1em;color:#388e3c;margin:6px 0 8px;">
               <strong>Your autoloaded options size is ${esc(kb.toFixed(1))} KB — no action needed.</strong>
             </p>`
          : `<p style="font-size:1.1em;color:#d32f2f;margin:6px 0 8px;">
               <strong>Your autoloaded options size is ${esc(kb.toFixed(1))} KB; too large — remove unused autoloaded options. Read more <a href="https://wpservice.pro/maintenance/wordpress-database-optimization-how-to-keep-your-site-fast-and-healthy/#autoload" target="_blank" rel="noopener">here</a>.</strong>
             </p>`
      );
    }
    
    // short footnote (kept tight to fit on one page)
    $page5.append('<p style="font-size:0.95em;font-style:italic;color:#666;margin:0 0 14px;">*Try to keep this value under 800 KB.</p>');
    
    // ———————————————————————————————————————————————————————————————
    // 4.5) Various other information (one row). Only render non-N/A items.
    // Logic: show green/red items. Grey/N/A are skipped.
    // ———————————————————————————————————————————————————————————————
    function classifyVariousItem(kind, valueText) {
      // returns {state:'green'|'red'|'skip', message:string}
      const v = String(valueText || '').trim();
      if (!v || v.toUpperCase() === 'N/A') return { state:'skip', message:'' };
    
      if (kind === 'plugins') {
        const num = parseInt(v, 10);
        if (isNaN(num)) return { state:'skip', message:'' };
        return (num <= 40)
          ? { state:'green', message:`Active plugins: ${num} — reasonable count; no action needed.` }
          : { state:'red',   message:`Active plugins: ${num} — too many active plugins. Deactivate/merge unused functionality.` };
      }
    
      if (kind === 'php') {
        const m = v.match(/(\d+)\.(\d+)(?:\.(\d+))?/);
        if (!m) return { state:'skip', message:'' };
        const major = parseInt(m[1],10), minor = parseInt(m[2],10);
        if (major > 8 || (major === 8 && minor >= 1)) {
          return { state:'green', message:`PHP ${v} — up to date. Keep it patched.` };
        }
        return { state:'red', message:`PHP ${v} — outdated. Update to PHP 8.1+ (ideally 8.2/8.3).` };
      }
    
      if (kind === 'db') {
        // examples: "MariaDB 10.11.10", "MySQL 8.0.36"
        const vendor = /mariadb/i.test(v) ? 'MariaDB' : (/mysql/i.test(v) ? 'MySQL' : '');
        const m = v.match(/(\d+)\.(\d+)(?:\.(\d+))?/);
        if (!vendor || !m) return { state:'skip', message:'' };
        const major = parseInt(m[1],10), minor = parseInt(m[2],10);
    
        if (vendor === 'MariaDB') {
          return (major > 10 || (major === 10 && minor >= 5))
            ? { state:'green', message:`${v} — modern DB engine detected.` }
            : { state:'red',   message:`${v} — older DB engine. Ask host for MariaDB 10.5+.` };
        } else { // MySQL
          return (major > 8 || (major === 8 && minor >= 0))
            ? { state:'green', message:`${v} — modern DB engine detected.` }
            : { state:'red',   message:`${v} — older DB engine. Ask host for MySQL 8.0+.` };
        }
      }
      return { state:'skip', message:'' };
    }
    
    // attempt to locate the 4.5 cards in admin UI
    function getVariousValues(){
  // 1) Try known IDs first (if you add IDs later, this picks them up).
  const byId = (sel) => {
    const $el = $(sel);
    if (!$el.length) return null;
    const v = $el.find('.value').first().text().trim();
    return v || null;
  };
  let activePlugins = byId('#summary-active-plugins') || byId('#wpsa-active-plugins') || null;
  let phpVersion    = byId('#summary-php-version')    || byId('#wpsa-php-version')    || null;
  let dbServer      = byId('#summary-db-server')      || byId('#wpsa-db-server')      || null;

  // 2) Fallback: fuzzy match ANYWHERE on the page for cards that contain a label.
  function pick(labelRegex){
    let value = null;

    // Search common “card” patterns first
    const $candidates = $('.wpsa-card, .wpsa-summary-card, .wpsa-stat-card, .wpsa-stat-cards .card, .card, .kpi-card, .metric-card, .wpsa-box');
    $candidates.each(function(){
      const txt = $(this).text();
      if (!labelRegex.test(txt)) return;
      // Prefer explicit .value
      let v = $(this).find('.value').first().text().trim();
      if (!v) {
        // Fallback: pull a number/version-ish token from the card text
        const m = txt.match(/([A-Za-z]*\s*\d[\d\.]*[A-Za-z]*)/);
        if (m) v = (m[1] || '').trim();
      }
      if (v) { value = v; return false; }
    });

    if (value) return value;

    // Last resort: scan all elements looking for the label, then grab a nearby value
    $('*').each(function(){
      const t = $(this).text();
      if (!labelRegex.test(t)) return;
      // Look in siblings for a number/version
      const sib = ($(this).next().text() || '').trim();
      let v2 = (sib.match(/([A-Za-z]*\s*\d[\d\.]*[A-Za-z]*)/) || [])[1];
      if (!v2) {
        const group = $(this).parent();
        const vv = group.find('.value').first().text().trim();
        if (vv) v2 = vv;
      }
      if (v2) { value = v2.trim(); return false; }
    });

    return value;
  }

      if (!activePlugins) activePlugins = pick(/Active plugins/i);
      if (!phpVersion)    phpVersion    = pick(/PHP version/i);
      if (!dbServer)      dbServer      = pick(/DB server|MariaDB|MySQL/i);
    
      return { activePlugins, phpVersion, dbServer };
    }

    const varVals = getVariousValues();
    if (varVals) {
    const items = [];

      // classify each
      const p = classifyVariousItem('plugins', varVals.activePlugins);
      if (p.state !== 'skip') items.push({ label:'Active plugins', value:varVals.activePlugins, state:p.state, msg:p.message });
    
      const ph = classifyVariousItem('php', varVals.phpVersion);
      if (ph.state !== 'skip') items.push({ label:'PHP version', value:varVals.phpVersion, state:ph.state, msg:ph.message });
    
      const db = classifyVariousItem('db', varVals.dbServer);
      if (db.state !== 'skip') items.push({ label:'DB server/size', value:varVals.dbServer, state:db.state, msg:db.message });
    
      if (items.length) {
    // heading
    $page5.append('<h3 style="margin:14px 0 8px;font-size:14px;">4.5. Various other information</h3>');
    
    // helper: split trailing emoji icon (✅/❌/⚠️ etc.) off the value
    function splitValueIcon(s) {
      const str = String(s || '').trim();
      const m = str.match(/\s*([✅❌⚠️✔️✖️])\s*$/);
      if (!m) return { text: str, icon: '' };
      return { text: str.slice(0, m.index).trim(), icon: m[1] };
    }
    
    // one-line row of compact cards (height close to your other cards)
    const $row = $('<div style="display:flex;gap:10px;justify-content:space-between;align-items:center;"></div>');
    
    items.forEach(function(it){
      const bgc = it.state === 'green' ? '#c6f7c3' : '#ffdddd';
    
      // compact card: reduced padding & fixed min-height ~50px; vertically centered
      const $card = $('<div style="flex:1 1 0;min-width:0;border-radius:10px;padding:10px 10px;border:1px solid #ccc;min-height:50px;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;"></div>')
        .css('background-color', bgc);
    
      // title: slightly smaller + lighter
      $card.append(`<div style="font-size:13.5px;font-weight:600;line-height:1.15;margin-bottom:4px;color:#3c434a;">${esc(it.label)}</div>`);
    
      // value: small, dark-ish, but not heavy; tiny icon
      const parts = splitValueIcon(it.value);
      // value line: full-width flex, centered
      const $valWrap = $('<div style="font-size:14px;font-weight:700;color:#111;line-height:1.2;display:flex;justify-content:center;align-items:center;gap:6px;width:100%;"></div>');
      $valWrap.append($('<span>').text(parts.text));
      if (parts.icon) {
        $valWrap.append($('<span>').text(parts.icon).css({ fontSize:'11px', lineHeight:'1', display:'inline-block' }));
      }
      $card.append($valWrap);
    
      $row.append($card);
    });
    
    $page5.append($row);
    
    // compact messages under the row
    const $msgs = $('<div style="margin-top:6px;"></div>');
    items.forEach(function(it){
      const col = it.state === 'green' ? '#388e3c' : '#d32f2f';
      $msgs.append(`<p style="font-size:0.9em;color:${col};margin:2px 0 0;"><strong>${esc(it.msg)}</strong></p>`);
    });
    $page5.append($msgs);

      }
    }

    
    
    // 5. Persistent object cache heading
         $page5.append(
         '<h2 style="margin:18px 0 10px;font-size:16px;">5. Persistent object cache</h2>' +
         '<p style="font-size:1em;margin:6px 0 16px;">-server-wide metric</p>'
        );

    
    // clone module-4 card and strip tooltip
    const $sum4 = $('#summary-module_4')
      .clone()
      .css({ display:'block', width:'360px', margin:'12px auto 12px' })
      .find('.custom-tooltip').remove().end();
    
    $page5.append($sum4);
    
        // dynamic feedback for module 4 (with N/A fallback)
        const pocText = wpsaNormalizeYesNo($sum4.find('.value').text());
        let html4;
        if (pocText === 'N/A') {
          html4 = `
            <p style="font-size:1.1em;margin:0 0 6px;">
              <strong>Not available. You can test the Persistent object cache only on the same server.</strong>
            </p>`;
        } else {
          const isOn = /^Yes$/i.test(pocText);
          html4 = isOn
            ? `<p style="font-size:1.1em;color:#388e3c;margin:0 0 6px;">
                 <strong>Persistent object cache is enabled — no action needed.</strong>
               </p>`
            : `<p style="font-size:1.1em;color:#d32f2f;margin:0 0 6px;">
                 <strong>No persistent object cache — consider enabling for database-heavy sites. Read more <a href="https://wpservice.pro/website-optimization/wordpress-performance-enhancement-tips/#persistent-object-cache" target="_blank" rel="noopener">here</a>.</strong>
               </p>`;
        }
        $page5.append(html4);

    
    // tight footnote & FAQ
    $page5.append(
      '<p style="font-size:0.95em;font-style:italic;color:#666;margin:0 0 10px;">*Persistent object cache speeds up repeated database queries.</p>'
    ).append(
      '<div style="margin-top:18px;font-size:0.95em;font-style:italic;line-height:1.35em;color:#333;">' +
        '<p style="margin:0 0 6px;"><strong>What is persistent object cache?</strong> A storage layer that retains query results across requests, cutting down database load.</p>' +
        '<p style="margin:0;"><strong>Why enable it?</strong> It speeds up sites with many repeated or complex database queries (e-commerce, membership, etc.).</p>' +
      '</div>'
    );
    
    // insert Page 5
        $c.append(
         wpsaNewPdfPage().append($hdr.clone(), $page5)
        );

    //
    // Page 6: Conclusion
    //
    const $page6 = $(`<div style="max-width:${WPSA_PAGE_MAX_W}px;margin:0 auto 28px;padding-top:5px;"></div>`)
      // 6. Conclusion title + intro
      .append('<h2 style="margin:0 0 4px;font-size:16px;">6. Conclusion</h2>')
      .append(`
          <p style="font-size:1em; margin-top:2px; margin-bottom:6px; line-height:1.4;">
            Here is a summary of the speed analysis for the tested URL:
            <a href="${esc(testUrl)}" target="_blank" rel="noopener">
              <strong>${esc(testUrl)}</strong>
            </a>, as well as the server’s overall performance.
          </p>
        `)
    
    // 6.1.1. Server performance — TTFB
    $page6.append('<h3 style="margin:20px 0 4px;font-size:14px;">6.1.1. Server performance — TTFB</h3>');
    
    let srvHtml;
    if      (ttfbMs <= 300) {
      srvHtml = `<p style="font-size:1.1em;color:#388e3c;margin-top:6px;">
                   Your server response time was fast (${ttfbMs} ms) — no action needed.
                 </p>`;
    }
    else if (ttfbMs <= 700) {
      srvHtml = `<p style="font-size:1.1em;color:#f9a825;margin-top:6px;">
                   Your server response time was moderate (${ttfbMs} ms) — consider reviewing your caching and/or hosting. 
                   You may need to “warm up” your cache and rerun the test. If you’re on slow/shared hosting, consider 
                   <a href="https://wpservice.pro/tools-of-the-trade/wordpress-hosting-services-compared/" target="_blank" rel="noopener">
                     replacing your web host
                   </a>, or adding a caching plugin such as 
                   <a href="https://wpservice.pro/tools-of-the-trade/wp-rocket-review-and-case-study/" target="_blank" rel="noopener">
                     WP Rocket
                   </a> or 
                   <a href="https://wpservice.pro/flyingpress" target="_blank" rel="noopener">
                     FlyingPress
                   </a>.
                     </p>`;
    }
    else {
      srvHtml = `<p style="font-size:1.1em;color:#d32f2f;margin-top:6px;">
                   Your server response time was slow (${ttfbMs} ms) — either a slow host, insufficient caching, or both. 
                   Try “warming up” your cache and rerunning the test. If you’re on slow/shared hosting, consider 
                   <a href="https://wpservice.pro/tools-of-the-trade/wordpress-hosting-services-compared/" target="_blank" rel="noopener">
                     replacing your web host
                   </a>, or adding a caching plugin such as 
                   <a href="https://wpservice.pro/tools-of-the-trade/wp-rocket-review-and-case-study/" target="_blank" rel="noopener">
                     WP Rocket
                   </a> or 
                   <a href="https://wpservice.pro/flyingpress" target="_blank" rel="noopener">
                     FlyingPress
                   </a>.
                   
                 </p>`;
    };
    
    $page6.append(srvHtml);
    
    //
    // 6.1.2. Server performance — Autoloaded options & Persistent cache
    //
    $page6.append('<h3 style="margin:20px 0 0;font-size:14px;">6.1.2. Server performance — Autoloaded options &amp; Persistent object cache</h3>');
    
    const _autoloadText = $sum3.find('.value').text().trim();
    if (_autoloadText === 'N/A') {
      $page6.append(`
        <p style="font-size:1em;color:#666;margin-top:0;margin-bottom:4px;line-height:1.32;">
          <strong>Autoloaded options size is not available on external servers.</strong>
        </p>
      `);

    } else if (!/^\d+/.test(_autoloadText)) {
      $page6.append(`
        <p style="font-size:1em;color:#d32f2f;margin-bottom:8px;">
          <strong>Test didn’t finish properly for autoloaded options size, please run it again.</strong>
        </p>
      `);
    } else {
      const _kb = parseFloat(_autoloadText);
      $page6.append(
        _kb <= 800
          ? `<p style="font-size:1em;color:#388e3c;margin-bottom:12px;">
               Tested website database autoloaded options size is ${_kb} KB — no action needed.
             </p>`
          : `<p style="font-size:1em;color:#d32f2f;margin-bottom:12px;">
               Tested website database autoloaded options size is ${_kb} KB; too large — remove unused autoloaded options. You can read more <a href="https://wpservice.pro/maintenance/wordpress-database-optimization-how-to-keep-your-site-fast-and-healthy/#autoload" target="_blank" rel="noopener">here</a>.
             </p>`
      );
    }
    
    const _cacheText = wpsaNormalizeYesNo($sum4.find('.value').text());
       if (_cacheText === 'N/A') {
      $page6.append(`
        <p style="font-size:1em;color:#666;margin-top:0;margin-bottom:8px;line-height:1.32;">
          <strong>Persistent object cache status is not available on external servers.</strong>
        </p>
      `);

    } else if (!/^(Yes|No)$/i.test(_cacheText)) {
      $page6.append(`
        <p style="font-size:1.1em;color:#d32f2f;margin-bottom:12px;">
          <strong>Test didn’t finish properly for persistent object cache, please run it again.</strong>
        </p>
      `);
    } else if (/^Yes$/i.test(_cacheText)) {
      $page6.append(`
        <p style="font-size:1em;color:#388e3c;margin-bottom:12px;">
          Persistent object cache is enabled — no action needed.
        </p>
      `);
    } else {
      $page6.append(`
        <p style="font-size:1em;color:#d32f2f;margin-bottom:12px;">
          No persistent object cache — consider enabling for database-heavy sites. Read more <a href="https://wpservice.pro/website-optimization/wordpress-performance-enhancement-tips/#persistent-object-cache" target="_blank" rel="noopener">here</a>.
        </p>
      `);
    }
    
    // separator
    $page6.append('<div style="border-top:1px solid #ccc;margin:20px 0;"></div>');
    
    //
    // 6.2.1. Page asset summary — mobile
    //
    $page6
      .append('<h3 style="margin:20px 0 0;font-size:14px;">6.2.1. Page asset summary — mobile <span class="dashicons dashicons-smartphone" style="vertical-align:middle;"></span></h3>')
      .append($assetMobileSummary);
      if ($onloadMobileLine) $page6.append($onloadMobileLine);
    
    //
    // 6.2.2. Page asset summary — desktop
    //
    $page6.append('<h3 style="margin:20px 0 0 !important;font-size:14px;">6.2.2. Page asset summary — desktop <span class="dashicons dashicons-desktop" style="vertical-align:middle;"></span></h3>');
    $page6.append($assetDesktopSummary);
    if ($onloadDesktopLine) $page6.append($onloadDesktopLine);
    
    // footnote (updated wording)
    $page6.append(`
      <div style="margin:14px 0;font-size:0.95em;font-style:italic;line-height:1em;color:#333;">
        <p style="margin:0 0 8px;">*These values should be as low as possible for the fastest results.</p>
      </div>
    `);
      
     
      // separator
    $page6.append('<div style="border-top:1px solid #ccc;margin:14px 0;"></div>');
    
    //
    // 6.3.1. Google PSI performance — mobile
    $page6
      .append('<h3 style="margin:12px 0 12px; font-size:14px;">6.3.1. Google PSI performance — mobile <span class="dashicons dashicons-smartphone" style="vertical-align:middle;"></span></h3>')
      .append(
        (function () {
          const $pBlockM = $page3
            .find('h3').first()        // “3.1. Mobile results…”
            .nextUntil('h4')           // pick score + LCP + FCP + CLS + INP (all <p>s)
            .filter('p')
            .clone(true, true);
    
          if ($pBlockM.length) {
            // --- Tighten vertical rhythm with !important so nothing overrides it ---
            // General compact spacing for all paragraphs in this block
             $pBlockM.each(function(){
              // enforce compact margins everywhere
              this.style.setProperty('margin-top', '4px', 'important');
              this.style.setProperty('margin-bottom', '4px', 'important');
              // slightly tighter but still readable
              this.style.setProperty('line-height', '1.28', 'important');
              // tiny shrink to prevent last-line spillovers (Page 6 only)
              this.style.setProperty('font-size', '0.98em', 'important');
            });

    
            // Extra tightening between:
            // [0] "The performance score is ..."  →  [1] "Your LCP is ..."
            if ($pBlockM[0]) {
              $pBlockM[0].style.setProperty('margin-bottom', '2px', 'important');
            }
            if ($pBlockM[1]) {
              $pBlockM[1].style.setProperty('margin-top', '2px', 'important');
            }
    
            // (Optional) also tighten between LCP → FCP if you want even more room:
            // if ($pBlockM[2]) { $pBlockM[2].style.setProperty('margin-top','4px','important'); }
    
            return $pBlockM;
          }
    
          // Fallback: previous behavior (first 3 paragraphs), also tightened
          const $fallback = $page3.find('h3').first().nextAll('p').slice(0,3).clone(true, true);
          $fallback.each(function(){
            this.style.setProperty('margin-top', '6px', 'important');
            this.style.setProperty('margin-bottom', '6px', 'important');
            this.style.setProperty('line-height', '1.32', 'important');
          });
          if ($fallback[0]) $fallback[0].style.setProperty('margin-bottom', '2px', 'important');
          if ($fallback[1]) $fallback[1].style.setProperty('margin-top', '2px', 'important');
          return $fallback;
        })()
      );


    
    
    // finally, stick Page 6 into the PDF workflow
    $c.append(
      wpsaNewPdfPage().append($hdr.clone(), $page6)
        );

    
        
        // PAGE 7 — 6.3.2–6.3.4
        const $page7 = $(`<div style="max-width:${WPSA_PAGE_MAX_W}px;margin:0 auto 8px;padding-top:16px;"></div>`);
        
        // 6.3.2. Performance — desktop
        $page7
          .append(
            '<h3 style="margin:0 0 20px;font-size:14px;">' +
              '6.3.2. Google PSI performance — desktop ' +
              '<span class="dashicons dashicons-desktop" style="vertical-align:middle;"></span>' +
            '</h3>'
          )
           .append(
            (function () {
              const $pBlockD = $page4
                .find('h3').first()         // “3.2. Desktop results…”
                .nextUntil('h4')            // up to its first Diagnostics heading
                .filter('p')                // only paragraphs
                .clone(true, true);
        
              if (!$pBlockD.length) {
                return $page4.find('h3').first().nextAll('p').slice(0,3).clone(true, true);
              }
              return $pBlockD;
            })()
          )

          .append('<div style="border-top:1px solid #ccc;margin:20px 0;"></div>');
        
       
        // Clone diagnostics/insights list for a given strategy ("mobile"|"desktop").
        // kind = "diag" (diagnostics) | "ins" (insights)
        function robustCloneList(strategy, kind){
          const isMobile = strategy === 'mobile';
          const selSets = (kind === 'diag')
            ? (isMobile
                ? ['#diag-mobile','#perf-mobile #diag-mobile','#perf-mobile .diagnostics','.wpsa-diagnostics-mobile']
                : ['#diag-desktop','#perf-desktop #diag-desktop','#perf-desktop .diagnostics','.wpsa-diagnostics-desktop'])
            : (isMobile
                ? ['#ins-mobile','#perf-mobile #ins-mobile','#perf-mobile .insights','.wpsa-insights-mobile']
                : ['#ins-desktop','#perf-desktop #ins-desktop','#perf-desktop .insights','.wpsa-insights-desktop']);
        
          let $node = $();
          for (const s of selSets) {
            const $try = fromOffOrLive(s).first();
            if ($try.length) { $node = $try.clone(true,true); break; }
          }
          if (!$node.length) return null;
        
          // If wrapped in <details>, open + extract the first list.
          if ($node.is('details')) {
            $node.prop('open', true);
            const $list = $node.find('ul,ol').first();
            if ($list.length) $node = $list.clone(true,true);
          } else if (!$node.is('ul,ol')) {
            const $list = $node.find('ul,ol').first();
            if ($list.length) $node = $list.clone(true,true);
          }
        
          // Clean tiny UI bits, normalize size, shrink icons.
         $node.find('summary, .custom-tooltip, .custom-tooltip + svg').remove();
        $node.css({ width:'100%', marginTop:0 });
        $node.find('small, .meta, .value, em').css({ fontSize:'0.95em', lineHeight:'1.2' });
        shrinkDiagIcons($node);
        
        /* NEW: enforce consistent structure so CSS can align title+meta inline */
        $node = normalizeListItems($node);
        
        return $node;
            
        }


        
        // 6.3.3. Diagnostics — mobile
           $page7.append(
          '<h3 style="margin:20px 0 12px;font-size:14px;">' +
            '6.3.3. Google PSI diagnostics &amp; insights — mobile ' +
            '<span class="dashicons dashicons-smartphone" style="vertical-align:middle;"></span>' +
          '</h3>'
        );
        
        const $dM = ($diagMobileForP7 ? $diagMobileForP7.clone(true,true) : robustCloneList('mobile','diag'));
        const $iM = ($insMobileForP7  ? $insMobileForP7.clone(true,true)  : robustCloneList('mobile','ins'));
        
        if (($dM && $dM.length) || ($iM && $iM.length)) {
          const $wrap = $('<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;"></div>');
          $wrap.append(
            $('<div></div>').append('<div style="font-weight:600;margin:0 0 6px;">Diagnostics (top 5)</div>', ($dM && $dM.length) ? $dM : $('<p style="color:#666;margin:0;">No diagnostics returned.</p>')),
            $('<div></div>').append('<div style="font-weight:600;margin:0 0 6px;">Insights (top 5)</div>',   ($iM && $iM.length) ? $iM : $('<p style="color:#666;margin:0;">No insights returned.</p>'))
          );
          $page7.append($wrap);
        } else {
          $page7.append('<p style="color:#666">Diagnostics & insights (mobile) not available — please run the test again.</p>');
        }

        
        $page7.append('<div style="border-top:1px solid #ccc;margin:20px 0;"></div>');
        
        // 6.3.4. Diagnostics — desktop
       $page7.append(
          '<h3 style="margin:20px 0 12px;font-size:14px;">' +
            '6.3.4. Google PSI diagnostics &amp; insights — desktop ' +
            '<span class="dashicons dashicons-desktop" style="vertical-align:middle;"></span>' +
          '</h3>'
        );
        
        const $dD = ($diagDesktopForP7 ? $diagDesktopForP7.clone(true,true) : robustCloneList('desktop','diag'));
        const $iD = ($insDesktopForP7  ? $insDesktopForP7.clone(true,true)  : robustCloneList('desktop','ins'));
        
        if (($dD && $dD.length) || ($iD && $iD.length)) {
          const $wrap = $('<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;"></div>');
          $wrap.append(
            $('<div></div>').append('<div style="font-weight:600;margin:0 0 6px;">Diagnostics (top 5)</div>', ($dD && $dD.length) ? $dD : $('<p style="color:#666;margin:0;">No diagnostics returned.</p>')),
            $('<div></div>').append('<div style="font-weight:600;margin:0 0 6px;">Insights (top 5)</div>',   ($iD && $iD.length) ? $iD : $('<p style="color:#666;margin:0;">No insights returned.</p>'))
          );
          $page7.append($wrap);
        } else {
          $page7.append('<p style="color:#666">Diagnostics & insights (desktop) not available — please run the test again.</p>');
        }

        
        // *** divider line between 6.3.4 and the concluding paragraph ***
        $page7.append('<div style="border-top:1px solid #ccc;margin:20px 0;"></div>');
        
        // concluding paragraph (only when Custom summary is disabled/empty)
        const _cfg7 = wpsaGetPdfConfig($off[0]);
        const _customOn  = !!(_cfg7.customSummary && _cfg7.customSummary.enabled);
        const _customTxt = _customOn ? String(_cfg7.customSummary.text || '').trim() : '';
        
        if (!_customOn || !_customTxt) {
          $page7.append(`
            <p class="wpsa-default-conclusion" style="font-size:1em;line-height:1.2em;color:#333;margin-top:12px; margin-bottom:4px;">
              Usually, the most significant gains can be achieved by optimizing the image size, format, and lazy loading with an image
              optimization tool like
              <a href="https://wpservice.pro/tools-of-the-trade/ewww-image-optimizer-plugin-an-extensive-review/" target="_blank" rel="noopener">EWWW IO</a>,
              unloading as much code as possible with plugins like ${wpsaPdfUnloadToolsLinks},
              removing unused CSS and properly utilizing the delay/defer of the remaining .js code with
              <a href="https://wpservice.pro/tools-of-the-trade/wp-rocket-review-and-case-study/" target="_blank" rel="noopener">WP Rocket</a> or
              <a href="https://wpservice.pro/flyingpress" target="_blank" rel="noopener">FlyingPress</a>. Those two should also provide proper caching.
            </p>
          `);
        }
        
        // mount Page 7
        $c.append(
          wpsaNewPdfPage().append($hdr.clone(), $page7)
        );

        // --- HOISTED page size math (must be above any call to pageMaxHeight / spillTailToNewPage) ---
        var PAGE7_MAX_H; // cached on first use

        function measureHeaderHeight() {
          // mount a throwaway page so layout is identical to real pages
          const $probeHeader  = $hdr.clone();
          const $probeContent = $(`<div style="max-width:${WPSA_PAGE_MAX_W}px;margin:0 auto;"></div>`);
          const $probePage    = wpsaNewPdfPage()
            .css({ visibility:'hidden', position:'absolute', inset:'-9999px auto auto -9999px' })
            .append($probeHeader, $probeContent)
            .appendTo('body');

          const h = $probeHeader.outerHeight(true) || 0;
          $probePage.remove();
          return h || 60; // sane fallback
        }

        function computePageMaxHeight() {
          const LETTER_H    = 11 * 96;  // 1056 px
          const PT_TO_PX    = 96 / 72;
          const M_TOP_PX    = 20 * PT_TO_PX;
          const M_BOTTOM_PX = 80 * PT_TO_PX;
          const hdrH        = measureHeaderHeight();
          const PAGE_FUDGE_PX = 28;
          return LETTER_H - M_TOP_PX - M_BOTTOM_PX - hdrH - PAGE_FUDGE_PX;
        }

        function pageMaxHeight() {
          if (PAGE7_MAX_H == null) PAGE7_MAX_H = computePageMaxHeight();
          return PAGE7_MAX_H;
        }
        
        // Move the tail of $content to a brand new PDF page if it already overflows.
        // Returns the page (content node) that should be used for *further* appends.
        function spillTailToNewPage($content, selectorsCsv){
          try {
            if ($content.outerHeight(true) <= pageMaxHeight()) return $content;
        
            // Find a split point near the end (use provided selectors, else last child)
            const sel = String(selectorsCsv || '')
              .split(',')
              .map(s => s.trim())
              .filter(Boolean)
              .join(', ');
            let $split = sel ? $content.find(sel).last() : $();
        
            if (!$split.length) $split = $content.children().last();
            if (!$split.length) return $content; // nothing to move
        
            // Everything from the split onward gets moved to a new page
            const $tail = $split.nextAll().addBack();
            if (!$tail.length) return $content;
        
            const $newPage = newPdfContentPage();
            $tail.appendTo($newPage);
            return $newPage; // this is the new "host" for more content
          } catch(e){
            console.warn('[WPSA PDF] spillTailToNewPage failed:', e);
            return $content;
          }
        }
        
        // --- Row-aware helpers for the Custom Summary --------------------------------

        // How many *rendered rows* does an element occupy?
        function countRenderedRows(el) {
          const cs = window.getComputedStyle(el);
          let lineH = parseFloat(cs.lineHeight);
          if (!lineH || cs.lineHeight === 'normal') {
            lineH = 1.2 * parseFloat(cs.fontSize || 12); // heuristic
          }
          // Use scrollHeight so wrapped lines are counted.
          return Math.ceil(el.scrollHeight / lineH);
        }
        
        // Return how many rows still fit on this page content node.
        function remainingRows($content) {
          const cs = window.getComputedStyle($content[0]);
          let lineH = parseFloat(cs.lineHeight);
          if (!lineH || cs.lineHeight === 'normal') {
            lineH = 1.2 * parseFloat(cs.fontSize || 12);
          }
          const remPx = pageMaxHeight() - $content.outerHeight(true);
          return Math.max(0, Math.floor(remPx / lineH));
        }
        
        // Split a <pre class="chunk"> so that at most `rowsLimit` rows remain on the current page.
        // Returns {fit: <pre>, rest: <pre|null>} — both new nodes (original not mutated).
        function splitPreAtRows($pre, rowsLimit) {
          const fullText = $pre.text();
          if (!rowsLimit || rowsLimit === Infinity) {
            return { fit: $pre.clone(true, true), rest: null };
          }
        
          // quick exit if it already fits
          const $probe = $pre.clone(true, true).css({visibility:'hidden',position:'absolute',left:'-9999px',top:'-9999px'});
          $('body').append($probe);
          if (countRenderedRows($probe[0]) <= rowsLimit) {
            $probe.remove();
            return { fit: $pre.clone(true, true), rest: null };
          }
          $probe.remove();
        
          // Prefer splitting by existing paragraphs (blank lines)
          const paras = fullText.split(/\n{2,}/);
          const joinParas = (arr) => arr.join('\n\n');
        
          // Try to accumulate paragraphs until we exceed rowsLimit, then fall back to line/word split.
          let low = 0, high = paras.length, best = 0;
          const $tmp = $('<pre class="chunk" style="visibility:hidden;position:absolute;left:-9999px;top:-9999px;"></pre>').appendTo('body');
          while (low <= high) {
            const mid = Math.floor((low + high) / 2);
            $tmp.text(joinParas(paras.slice(0, mid)));
            const rows = countRenderedRows($tmp[0]);
            if (rows <= rowsLimit) { best = mid; low = mid + 1; } else { high = mid - 1; }
          }
          $tmp.remove();
        
          let headText = joinParas(paras.slice(0, best));
          let tailText = joinParas(paras.slice(best));
        
          if (!headText) {
            // Single gigantic "paragraph". Split by lines, then by words.
            const lines = fullText.split('\n');
            let lLow = 0, lHigh = lines.length, lBest = 0;
            const $t = $('<pre class="chunk" style="visibility:hidden;position:absolute;left:-9999px;top:-9999px;"></pre>').appendTo('body');
            while (lLow <= lHigh) {
              const mid = Math.floor((lLow + lHigh) / 2);
              $t.text(lines.slice(0, mid).join('\n'));
              const rows = countRenderedRows($t[0]);
              if (rows <= rowsLimit) { lBest = mid; lLow = mid + 1; } else { lHigh = mid - 1; }
            }
            $t.remove();
        
            headText = lines.slice(0, lBest).join('\n');
            tailText = lines.slice(lBest).join('\n');
        
            if (!headText) {
              // Last resort: split by words
              const words = fullText.split(/\s+/);
              const $w = $('<pre class="chunk" style="visibility:hidden;position:absolute;left:-9999px;top:-9999px;"></pre>').appendTo('body');
              let wLow = 0, wHigh = words.length, wBest = 0;
              while (wLow <= wHigh) {
                const mid = Math.floor((wLow + wHigh) / 2);
                $w.text(words.slice(0, mid).join(' '));
                const rows = countRenderedRows($w[0]);
                if (rows <= rowsLimit) { wBest = mid; wLow = mid + 1; } else { wHigh = mid - 1; }
              }
              $w.remove();
              headText = words.slice(0, wBest).join(' ');
              tailText = words.slice(wBest).join(' ');
            }
          }
        
          const $fit  = $('<pre class="chunk"></pre>').text(headText.replace(/\n?$/,'\n'));
          const $rest = tailText && tailText.trim().length
                          ? $('<pre class="chunk"></pre>').text(tailText.replace(/\n?$/,'\n'))
                          : null;
        
          return { fit: $fit, rest: $rest };
        }



        // Preflight: spill tail if already overflowing
        let $page7Host = spillTailToNewPage(
          $page7,
          'div[style*="border-top"], h3:contains("Google PSI diagnostics — desktop"), ul, ol, .wpsa-default-conclusion, p, div'
        );
        
        
        // ---------- Custom summary + CTA: true pagination, no overspill ----------
        function makeCta(marginTopPx, compact){
          const pad = compact ? 12 : 15;
          const lh  = compact ? 1.32 : 1.4;
          const fs  = compact ? '0.98em' : '1em';
          const h3Mb = compact ? 8 : 12;
          const pMb  = compact ? 10 : 16;
          const btnPad = compact ? '4px 9px' : '5px 10px';

          return $(`
            <div class="wpsa-cta" style="
              background:#234897;padding:${pad}px;margin-top:${marginTopPx}px;
              border-top:1px solid transparent;font-size:${fs};line-height:${lh};color:#efefd0;border-radius:10px;">
              <h3 style="margin:0 0 ${h3Mb}px;font-size:1.02em;font-weight:600;color:#efefd0;">
                Need an even deeper audit? Or would you like an expert to take care of the speed optimization for you?
              </h3>
              <p style="margin:0 0 ${pMb}px;">Contact us for a personalized site-speed audit or professional speed optimization:</p>
              <p style="margin:0;">
                <a href="https://wpservice.pro/pricing-speed-optimization/" target="_blank" rel="noopener"
                   style="display:inline-block;background:transparent;color:#efefd0;border:1px solid #efefd0;
                          padding:${btnPad};text-decoration:none;border-radius:3px;font-weight:600;">
                  View Prices →
                </a>
              </p>
            </div>
          `);
        }

        
        function makeCustomSummary(htmlOrText){
          // Treat content as TEXT; preserve whitespace and manual line breaks.
          const $wrap = $(`<div class="wpsa-custom-summary"></div>`);
          const parts = String(htmlOrText || '').split(/\n{2,}/); // split on blank lines
          parts.forEach(p => {
            const $pre = $(`<pre class="chunk"></pre>`).text(p.replace(/\n?$/,'\n'));
            $wrap.append($pre);
          });
          return $wrap;
        }
        
        // Create a new PDF “content page” (with header) and return its content node
        function newPdfContentPage(){
          const $content = $(
            `<div style="max-width:${WPSA_PAGE_MAX_W}px;margin:0 auto 28px;padding-top:16px;"></div>`
          );
          $c.append( wpsaNewPdfPage().append($hdr.clone(), $content) );
          return $content;
        }

        // Maximum rows of Custom Summary allowed per page (tweak to taste)
        // broji redove
        const CS_ROWS_PER_PAGE = 34;

        // Flow a multi-<pre> Custom Summary across pages without splitting mid-row,
        // and split oversized chunks as needed.
        function flowSummaryAcrossPages($startContent, $summary){
          let $cur = $startContent;
          const $chunks = $summary.find('.chunk');
        
          if (!$chunks.length) return $cur;
        
          // Holder per page so styles stay consistent
          let $holder = $('<div class="wpsa-custom-summary"></div>');
          $cur.append($holder);
        
          $chunks.each(function(){
            let $part = $(this).clone(true, true);
        
            // Greedy append; if it fits, keep going.
            $holder.append($part);
            if ($cur.outerHeight(true) <= pageMaxHeight()) return;
        
            // It didn't fit — remove and split it by rows.
            $part.detach();
        
            // Work with what's left on this page
            let rowsLeft = Math.min(remainingRows($cur), CS_ROWS_PER_PAGE);
        
            if (rowsLeft <= 0) {
              // Nothing fits on this page → start a fresh page + holder.
              $holder = $('<div class="wpsa-custom-summary"></div>');
              $cur = newPdfContentPage().append($holder);
              rowsLeft = CS_ROWS_PER_PAGE;
            }
        
            // Split the current <pre> to fit remaining rows on this page
            let { fit, rest } = splitPreAtRows($part, rowsLeft);
            if (fit) $holder.append(fit);
        
            // If there's remainder, keep paginating it until exhausted
            while (rest) {
              // Start new page + holder
              $holder = $('<div class="wpsa-custom-summary"></div>');
              $cur = newPdfContentPage().append($holder);
        
              const rowsOnNew = Math.min(CS_ROWS_PER_PAGE, remainingRows($cur) || CS_ROWS_PER_PAGE);
              const split = splitPreAtRows(rest, rowsOnNew);
              $holder.append(split.fit);
              rest = split.rest;
            }
          });
        
          return $cur;
        }
        
        // Measure an element's rendered height reliably (even if it's not mounted yet)
        function measureOuterHeight($el){
          if (!$el || !$el.length) return 0;

          const $probe = $('<div></div>').css({
            position: 'absolute',
            left: '-9999px',
            top: '-9999px',
            visibility: 'hidden',
            width: `${WPSA_PAGE_MAX_W}px`
          }).appendTo('body');

          const $cl = $el.clone(true, true).appendTo($probe);
          const h = $cl.outerHeight(true) || 0;

          $probe.remove();
          return h;
        }

        
        // ---------- Build & paginate ----------
        const cfg2 = wpsaGetPdfConfig($off[0]);
        const customEnabled = !!(cfg2.customSummary && cfg2.customSummary.enabled);
        const customText    = customEnabled ? String(cfg2.customSummary.text || '') : '';
        const $ctaBlock     = (!cfg2.removeCta) ? makeCta(14, true) : null;


        if (customEnabled && customText.trim()){
          // Build summary content
          const $summary = makeCustomSummary(customText);

          // force the Summary to start on a brand-new page (this becomes Page 8)
          // Add the "Summary" title and then flow/paginate the content across pages.
          const $summaryTitle = $('<h2 style="margin:0 0 10px;font-size:16px;">Summary</h2>');
          let $p8 = newPdfContentPage();     // this ensures the Summary begins on the next page after Page 7
          $p8.append($summaryTitle);

          // Row-aware paginator will add additional pages as needed
          let $lastPage = flowSummaryAcrossPages($p8, $summary);

          // CTA — append on the last summary page if it fits, otherwise move to a new page
          if ($ctaBlock) {
            const fitsHere = ($lastPage.outerHeight(true) + $ctaBlock.outerHeight(true)) <= pageMaxHeight();
            if (fitsHere) {
              $lastPage.append($ctaBlock);
            } else {
              $lastPage = newPdfContentPage().append($ctaBlock);
            }
          }

        } else if ($ctaBlock) {
          // No custom summary → keep CTA on Page 7 ONLY if it truly fits (real DOM measurement)
          const ctaH = measureOuterHeight($ctaBlock);

          // Ensure the default conclusion paragraph margins are honored (html2pdf can be picky)
          $page7.find('p.wpsa-default-conclusion').each(function(){
            this.style.setProperty('margin-top', '10px', 'important');
            this.style.setProperty('margin-bottom', '4px', 'important');
            this.style.setProperty('line-height', '1.2em', 'important');
            this.style.setProperty('font-size', '1em', 'important');
          });

          const fitsHere = ($page7.outerHeight(true) + ctaH) <= pageMaxHeight();

          if (fitsHere) {
            $page7.append($ctaBlock);
          } else {
            newPdfContentPage().append($ctaBlock);
          }
        }

        
        // ---- prune any empty page wrappers (not just the last) ----
        (function pruneEmptyPages(){
          $c.children().each(function(){
            const $wrap = $(this);
            if (!$wrap.is('[style*="page-break"]')) return;
        
            const $content = $wrap.children().last(); // page content node (after header)
            const textLen = ($content.text() || '').replace(/\s+/g,'').length;
            const blocks = $content.find('p,div,ul,ol,table,pre,img,svg,canvas,section,article').length;
        
            // If the page's content box is truly empty (no blocks, no text), drop it — 
            // regardless of padding/margins height.
            if (textLen === 0 && blocks === 0) {
              $wrap.remove();
            }
          });
        })();

        // Before we hand it off to html2pdf, make sure everything is laid out and visible
        $c.children().css('display','block');
        $c.show();

        // v0.131 — dynamic filename from tested URL
        const pdfName = buildReportFilename(testUrl);

        

        // — render PDF —
        html2pdf()
          .set({
            margin:      [20,20,76,20], // bottom page margin 80 → 76
            filename:    pdfName,
            image:       { type:'jpeg', quality:0.98 },
            html2canvas: { scale:2, useCORS:true, scrollY:0 },
            jsPDF:       { unit:'pt', format:'letter', orientation:'portrait' },
            pagebreak:   { mode:['css','legacy'] },
            enableLinks: true
          })
          .from($c.get(0))
          .toPdf()
          .get('pdf')
          .then(function (pdf) {
            const total = pdf.internal.getNumberOfPages();
            const pw    = pdf.internal.pageSize.getWidth();
            const ph    = pdf.internal.pageSize.getHeight();
            const now   = new Date().toLocaleString();
            for (let i = 1; i <= total; i++) {
              pdf.setPage(i)
                 .setDrawColor('#ff6b35').setLineWidth(1).line(20, ph - 50, pw - 20, ph - 50)
                 .setFont('helvetica','normal').setFontSize(8).setTextColor(100)
                 .text(now, 20, ph - 30)
                 .text(`Page ${i} of ${total}`, pw - 20, ph - 30, { align:'right' });
            }
          })
          .save()
          .then(() => {
            $c.hide();
            // Clean up the head-injected PDF-only CSS so it never leaks to the UI
            $('#wpsa-pdf-only').remove();
          });


        
        }) // <— closes .done(function(r){ ... )
        .fail(function (xhr, status, err) {
              // Better console output
              console.groupCollapsed('[WPSA] PDF AJAX fail');
              console.log('status:', status);
              console.log('xhr.status:', xhr && xhr.status);
              console.log('xhr.responseText:', xhr && xhr.responseText);
              console.groupEnd();
            
              // Try to pull a readable message out of the response
              var msg = 'PDF generation failed. Please try again.';
              try {
                if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                  msg = xhr.responseJSON.data;
                } else if (xhr && xhr.responseText) {
                  // extract first line of any PHP fatal/notice
                  msg = (xhr.responseText.match(/<body[^>]*>([\s\S]*?)<\/body>/i) || [,''])[1]
                          .replace(/<[^>]+>/g,'').trim() || xhr.responseText.trim();
                  msg = msg.split('\n').slice(0,2).join(' ');
                }
              } catch(e){}
            
              // Friendly mapping based on HTTP code / keywords
              if (xhr && xhr.status === 403 || /nonce/i.test(msg)) {
                msg = 'Your security token (nonce) expired. Please refresh the PDF button (or switch tabs) and try again.';
              } else if (xhr && xhr.status === 500) {
                msg = 'The server hit an error building the PDF. If this persists, run a fresh test and try again.';
              }
            
            const $wrap = $btn.closest('.wpsa-pdf-button-wrap');
              $wrap.find('.notice').remove();

              const $notice = $('<div class="notice notice-error2"></div>');
              const $p = $('<p></p>');
              $p.append($('<strong></strong>').text('Error:'));
              $p.append(document.createTextNode(' ' + String(msg || 'PDF generation failed. Please try again.')));
              $notice.append($p);
              $wrap.append($notice);
            })

        .always(function () {
          $btn.prop('disabled', false);
        });
    }
  // Delegated handler that survives DOM rebuilds/tab switches/restores
    $(document)
      .off('click.wpsaPDF', '.wpsa-button-pdf')
      .on('click.wpsaPDF', '.wpsa-button-pdf', function (e) {
        e.preventDefault();
        if ($(this).prop('disabled')) return;   // no double-fires
        generatePdf.call(this, e);
      });
      
});
    
