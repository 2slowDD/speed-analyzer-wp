<?php
/**
 * Speed Analyzer  – Module 6: Summary & Recommendations
 * Version:      v0.679
 */

defined( 'ABSPATH' ) || exit;

function wpsa_module6_summary( $tested_url, $results_log ) {
    ?>
    <div id="module6-running" class="wpsa-module-running">Running summary...</div>

    <div id="wpsa-module6" class="wpsa-module-6" style="position: relative;">
        <h2 class="wpsa-module-title">6. Summary &amp; Recommendations</h2>

        <div class="wpsa-tabs6">
            <span class="wpsa-tab6 active" data-strategy="mobile">
                <span class="dashicons dashicons-smartphone"></span> Mobile
            </span>
            <span class="wpsa-tab6" data-strategy="desktop">
                <span class="dashicons dashicons-desktop"></span> Desktop
            </span>
        </div>

        <h3 class="summary-subheading">Mobile results</h3>

        <div class="wpsa-stat-cards photo">
            <div class="wpsa-stat-card" id="summary-module_1">
                <div class="header">Server TTFB / response time</div>
                <div class="value">Loading…</div>
            </div>
            <div class="wpsa-stat-card" id="summary-module_2">
                <div class="header">Requests &amp; Page Size</div>
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

            <!-- PSI metrics panel without standalone title -->
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
        </div>

        <h3 id="recom">Recommendations</h3>
        <ul id="summary-recommendations" class="wpsa-recommendations">
            <li>Loading recommendations…</li>
        </ul>

        <div class="wpsa-footnote">
            Follow the recommendations above to enhance your site’s performance.
        </div>
    </div>
    <?php
 
}
