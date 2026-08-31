<?php
/**
 * Speed Analyzer  – Module 6: Summary & Recommendations
 * Summary.php
 * Version:      v0.687
 */

defined( 'ABSPATH' ) || exit;

function wpsa_module6_summary( $tested_url, $results_log ) {
    ?>
    <div id="module6-running" class="wpsa-module-running">Running summary...</div>

    <div id="wpsa-module6" class="wpsa-module-6" style="position: relative;">
        <h2 class="wpsa-module-title">7. Summary &amp; Recommendations</h2>

        <div class="wpsa-tabs6">
            <span class="wpsa-tab6 active" data-strategy="mobile">
                <span class="dashicons dashicons-smartphone"></span> Mobile
            </span>
            <span class="wpsa-tab6" data-strategy="desktop">
                <span class="dashicons dashicons-desktop"></span> Desktop
            </span>
        </div>

        <h3 class="summary-subheading">Mobile results</h3>

       <!-- FIRST ROW (exactly 5 tiles; now has CLS/INP after LCP/FCP) -->
        <div class="wpsa-stat-cards photo">
            <div class="wpsa-stat-card" id="summary-module_1">
                <div class="header">Server TTFB / response time</div>
                <div class="value">Loading…</div>
            </div>
        
            <div class="wpsa-stat-card" id="summary-module_2">
                <div class="header">Requests &amp; Page Size</div>
                <div class="value">Loading…</div>
            </div>
        
            <!-- PSI metrics combined (LCP/FCP) -->
            <div class="wpsa-stat-card psi-metrics" id="summary-module_5">
                <div class="psi-halves">
                    <div class="psi-half lcp">
                        <div class="header">LCP</div>
                        <div class="value"><span class="score">Loading…</span></div>
                    </div>
                    <div class="psi-half fcp">
                        <div class="header">FCP</div>
                        <div class="value"><span class="score">Loading…</span></div>
                    </div>
                </div>
            </div>
        
            <!-- NEW: PSI metrics combined (CLS/INP) -->
            <div class="wpsa-stat-card psi-metrics" id="summary-module_5b">
                <div class="psi-halves">
                    <div class="psi-half cls">
                        <div class="header">CLS</div>
                        <div class="value"><span class="score">Loading…</span></div>
                    </div>
                    <div class="psi-half inp">
                        <div class="header">TBT</div>
                        <div class="value"><span class="score">Loading…</span></div>
                    </div>
                </div>
            </div>
        
            <div class="wpsa-stat-card" id="summary-onload-js">
                <div class="header">Onload JS files</div>
                <div class="value">Loading…</div>
            </div>
        </div> <!--closes first row -->


       <!-- SECOND ROW (exactly 5 tiles) -->
        <div class="wpsa-stat-cards wpsa-summary-45-row photo">
            <div class="wpsa-stat-card" id="summary-onload-css">
                <div class="header">Onload CSS files</div>
                <div class="value">Loading…</div>
            </div>
        
            <div class="wpsa-stat-card" id="summary-module_3">
                <div class="header">Autoloaded Options</div>
                <div class="value">Loading…</div>
            </div>
        
            <div class="wpsa-stat-card" id="summary-module_4">
                <div class="header">Persistent Cache</div>
                <div class="value">Loading…</div>
            </div>
        
            <div class="wpsa-stat-card" id="summary-45-plugins">
                <div class="header">Active plugins</div>
                <div class="value">Loading…</div>
            </div>
        
            <div class="wpsa-stat-card" id="summary-45-php">
                <div class="header">PHP version</div>
                <div class="value">Loading…</div>
            </div>
        
            <!-- DB stays last; if you prefer, swap PHP/DB order -->
            <div class="wpsa-stat-card" id="summary-45-db">
                <div class="header">DB server/size</div>
                <div class="value">Loading…</div>
            </div>
            </div> <!--closes second row -->
            
        <h3 id="recom">Recommendations</h3>
        
        <div id="summary-recommendations" class="wpsa-recommendations">
          <ul class="recom-col col-left"><li>Loading recommendations…</li></ul>
          <ul class="recom-col col-right" style="display:none;"></ul>
        </div>
        
        <div class="wpsa-footnote">
          Follow the recommendations above to enhance your site’s performance.
        </div>
        
    <script>
        (function () {
          // --- tiny helpers ---
          const toNum = (s) => {
            if (!s) return NaN;
            const m = String(s).replace(/[, ]/g, '').match(/-?\d+(?:\.\d+)?/);
            return m ? parseFloat(m[0]) : NaN;
          };
          const text = (sel) => {
            const n = document.querySelector(sel);
            return n ? (n.textContent || '').trim() : '';
          };
        
          // thresholds (keep in sync with Conclusion.php)
          const T = {
            req_mobile_bad:   70,
            size_mobile_bad:  3584,
            req_desktop_bad:  70,
            size_desktop_bad: 3584,
            lcp_bad:          4.0,
            fcp_bad:          3.0,
            cls_bad:          0.25,
            // TBT "poor" edge. This recs builder reads the rendered summary tile
            // and has no device in scope, so it uses the MOBILE threshold (600).
            // That is the permissive one of the pair, chosen deliberately: on a
            // desktop reading it under-warns rather than raising a recommendation
            // the PSI report would not support.
            tbt_bad:          600,
            onjs_bad:         10,
            oncss_bad:        10
          };
        
          // read a PSI half robustly (grabs any text inside the half)
          const metricHalf = (containerSel) => {
            const n = document.querySelector(containerSel);
            return n ? toNum(n.textContent) : NaN;
          };
        
          function collectRecs() {
            const recs = [];
        
            // Requests & size (expects like "106 R, 1331.9 KB")
            const m2 = text('#summary-module_2 .value');
            const reqM = m2.match(/(\d+)\s*R/i);
            const sizeM = m2.match(/([\d.]+)\s*KB/i);
            const req  = reqM ? parseInt(reqM[1], 10) : NaN;
            const size = sizeM ? parseFloat(sizeM[1]) : NaN;
        
            if (!isNaN(req)  && req  > T.req_mobile_bad)  recs.push('Reduce number of HTTP requests');
            if (!isNaN(size) && size > T.size_mobile_bad) recs.push('Decrease overall page size');
        
            // Core Web Vitals (read any text from the half containers)
            const lcp = metricHalf('#summary-module_5  .psi-half.lcp');
            const fcp = metricHalf('#summary-module_5  .psi-half.fcp');
            const cls = metricHalf('#summary-module_5b .psi-half.cls');
            const inp = metricHalf('#summary-module_5b .psi-half.inp');
        
            if (!isNaN(lcp) && lcp > T.lcp_bad) recs.push('Decrease LCP');
            if (!isNaN(fcp) && fcp > T.fcp_bad) recs.push('Decrease FCP');
            if (!isNaN(cls) && cls > T.cls_bad) recs.push('Reduce layout shifts (CLS)');
            if (!isNaN(inp) && inp > T.tbt_bad) recs.push('Reduce main-thread blocking (TBT)');
        
            // Onloads
            const onCss = toNum(text('#summary-onload-css .value'));
            const onJs  = toNum(text('#summary-onload-js .value'));
            if (!isNaN(onJs)  && onJs  > T.onjs_bad)  recs.push('Reduce onload JavaScript files');
            if (!isNaN(onCss) && onCss > T.oncss_bad) recs.push('Reduce onload CSS files');
        
            // de-dupe while keeping order
            const out = [];
            const seen = new Set();
            for (const r of recs) {
              if (!seen.has(r)) { seen.add(r); out.push(r); }
            }
            return out;
          }
        
          let lastSig = '';
        
          function renderRecs() {
            // next tick so we run after DOM swaps
            Promise.resolve().then(() => {
              const wrap = document.getElementById('summary-recommendations');
              if (!wrap) return;
        
              const left  = wrap.querySelector('.col-left');
              const right = wrap.querySelector('.col-right');
              if (!left || !right) return;
        
              const list = collectRecs();
              const sig = JSON.stringify(list);
              if (sig === lastSig) return;
              lastSig = sig;
        
              left.innerHTML = '';
              right.innerHTML = '';
        
              if (!list.length) {
                left.innerHTML = '<li>No outstanding recommendations — nice!</li>';
                right.style.display = 'none';
                return;
              }
        
              list.forEach((t, i) => {
                const li = document.createElement('li');
                li.textContent = t;
                (i < 5 ? left : right).appendChild(li);
              });
              right.style.display = (list.length > 5 ? 'block' : 'none');
            });
          }
        
          // initial
          renderRecs();
        
          // observe the whole module so SPA re-renders & Test # switches are caught
          const container = document.getElementById('wpsa-module6');
          if (container) {
            const obs = new MutationObserver(() => {
              // give the tiles a moment to finish replacing
              setTimeout(renderRecs, 50);
            });
            obs.observe(container, { childList: true, subtree: true, characterData: true });
          }
        
          // also re-run on tab change & when the tab regains focus
          document.querySelectorAll('.wpsa-tab6').forEach(btn =>
            btn.addEventListener('click', () => setTimeout(renderRecs, 60))
          );
          document.addEventListener('visibilitychange', () => { if (!document.hidden) renderRecs(); });
        })();
</script>





    
   </div><!-- /#wpsa-module6 -->

   
    <?php
}
