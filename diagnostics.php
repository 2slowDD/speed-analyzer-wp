<?php
/**
 * Speed Analyzer – Performance Diagnostics (Module 5 & logging)
 *
 * @package   Speed_Analyzer
 * @version   v0.689
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the main UI for Module 5.
 *
 * @param string $tested_url   URL to test.
 * @param string $results_log  Path to results log. (Now under /uploads/speed-analyzer/)
 */
function wpsa_module5_performance_diagnostics( $tested_url, $results_log ) {
    echo '<div class="wpsa-module-5">';
      echo '<h2 class="wpsa-module-title wpsa-module-5-title">';
        echo '5. Performance &amp; Diagnostics ';
        echo '<small style="font-size:14px;color:#777;vertical-align:middle;">';
        echo '(as on <a href="https://pagespeed.web.dev/" target="_blank">PageSpeed Insights (PSI)</a>)';
        echo '</small>';
      echo '</h2>';

      echo '<div id="module5-running" class="wpsa-module5-running">Running tests, this could take a minute...</div>';
      echo '<div id="wpsa-module5" class="wpsa-module5-container">';

        echo '<div class="wpsa-tabs5">';
          echo '<span class="wpsa-tab5 active" data-strategy="mobile"><span class="dashicons dashicons-smartphone"></span> Mobile</span>';
          echo '<span class="wpsa-tab5" data-strategy="desktop"><span class="dashicons dashicons-desktop"></span> Desktop</span>';
        echo '</div>';

        // Mobile panel
        echo '<div id="perf-mobile" class="metrics">';
          echo '<h3 class="wpsa-subheading">Mobile results</h3>';
          echo '<div class="performance-circle wpsa-performance-circle" id="perf-circle-mobile"><span class="perf-text wpsa-perf-text">--</span></div>';
          echo '<h3>Metrics</h3>';
          echo '<div class="wpsa-stat-cards">';
            echo '<div class="wpsa-stat-card wpsa-card-lcp"><div class="header">LCP <span class="custom-tooltip" data-tooltip="Largest Contentful Paint marks the time at which the largest text or image is painted.">?</span></div><div class="value" id="lcp-mobile">--</div></div>';
            echo '<div class="wpsa-stat-card wpsa-card-fcp"><div class="header">FCP <span class="custom-tooltip" data-tooltip="First Contentful Paint marks the time at which the first text or image is painted.">?</span></div><div class="value" id="fcp-mobile">--</div></div>';
          echo '</div>';
          echo '<p class="wpsa-footnote">*Values are estimated and may vary. The performance score is calculated directly from PSI metrics. See calculator (as on <a href="https://googlechrome.github.io/lighthouse/scorecalc/#FCP=1103&amp;LCP=5851&amp;TBT=0&amp;CLS=0&amp;SI=3661&amp;TTI=5866&amp;device=mobile&amp;version=12.4.0" target="_blank">PageSpeed dev</a>).</p>';
          echo '<h3 id="mobile-diag">Diagnostics – top 5 opportunities</h3>';
          echo '<ul class="diagnostics" id="diag-mobile"></ul>';
          echo '<p class="wpsa-footnote">*These diagnostics are opportunities and don’t directly affect the Performance score. However, your website speed will benefit if you improve on them.</p>';
        echo '</div>';

        // Desktop panel
        echo '<div id="perf-desktop" class="metrics">';
          echo '<h3 class="wpsa-subheading">Desktop results</h3>';
          echo '<div class="performance-circle wpsa-performance-circle" id="perf-circle-desktop"><span class="perf-text wpsa-perf-text">--</span></div>';
          echo '<h3>Metrics</h3>';
          echo '<div class="wpsa-stat-cards">';
            echo '<div class="wpsa-stat-card wpsa-card-lcp"><div class="header">LCP <span class="custom-tooltip" data-tooltip="Largest Contentful Paint.">?</span></div><div class="value" id="lcp-desktop">--</div></div>';
            echo '<div class="wpsa-stat-card wpsa-card-fcp"><div class="header">FCP <span class="custom-tooltip" data-tooltip="First Contentful Paint.">?</span></div><div class="value" id="fcp-desktop">--</div></div>';
          echo '</div>';
          echo '<p class="wpsa-footnote">*Values are estimated and may vary. The performance score is calculated directly from PSI metrics. See calculator (as on <a href="https://googlechrome.github.io/lighthouse/scorecalc/#FCP=1103&amp;LCP=5851&amp;TBT=0&amp;CLS=0&amp;SI=3661&amp;TTI=5866&amp;device=mobile&amp;version=12.4.0" target="_blank">PageSpeed dev</a>).</p>';
          echo '<h3 id="desktop-diag">Diagnostics – top 5 opportunities</h3>';
          echo '<ul class="diagnostics" id="diag-desktop"></ul>';
          echo '<p class="wpsa-footnote">*These diagnostics are opportunities and don’t directly affect the Performance score. However, your website speed will benefit if you improve on them.</p>';
        echo '</div>';

      echo '</div>';
    echo '</div>';
}

/**
 * AJAX handler: fetch PSI data, handle 403/429 gracefully.
 *
 * Now proxies through Cloudflare Worker.
 */
add_action( 'wp_ajax_wpsa_performance', 'wpsa_ajax_performance' );
function wpsa_ajax_performance() {
    check_ajax_referer( 'wpsa_perf_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $url   = esc_url_raw( wp_unslash( $_POST['test_url'] ?? '' ) );
    $strat = sanitize_key( wp_unslash( $_POST['strategy'] ?? 'mobile' ) );
    if ( ! $url || ! in_array( $strat, array( 'mobile', 'desktop' ), true ) ) {
        wp_send_json_error();
    }

    // ── PROXY THROUGH CLOUDFLARE WORKER ──
    // The Worker endpoint is defined in helpers.php as WPSA_WORKER_ENDPOINT.
    // It expects query parameters "psi_url" and "strategy" and returns
    // a JSON payload that mirrors the Google PSI response structure.
    $endpoint = add_query_arg(
        array(
            'psi_url'  => rawurlencode( $url ),
            'strategy' => $strat,
        ),
        WPSA_WORKER_ENDPOINT . 'psi'
    );

    $res   = wp_remote_get( $endpoint, array( 'timeout' => 30 ) );
    $code  = wp_remote_retrieve_response_code( $res );

    if ( is_wp_error( $res ) || 200 !== $code ) {
        if ( ! is_wp_error( $res ) && in_array( $code, array( 403, 429 ), true ) ) {
            wp_send_json_error( array(
                'message' => 'Service temporarily unavailable. Please try again later.'
            ) );
        }
        wp_send_json_error();
    }

    // Expect the Worker to return an object with "lighthouseResult"
    $body = wp_remote_retrieve_body( $res );
    $data = json_decode( $body, true );
    $l    = $data['lighthouseResult'] ?? array();

    $score = isset( $l['categories']['performance']['score'] )
        ? intval( $l['categories']['performance']['score'] * 100 )
        : 0;
    $lcp   = $l['audits']['largest-contentful-paint']['displayValue'] ?? '--';
    $fcp   = $l['audits']['first-contentful-paint']['displayValue']   ?? '--';

    $diag = array();
    foreach ( $l['categories']['performance']['auditRefs'] as $r ) {
        if ( isset( $r['group'] ) && $r['group'] === 'diagnostics' ) {
            $a = $l['audits'][ $r['id'] ] ?? null;
            if ( $a && isset( $a['displayValue'] ) ) {
                $sev = preg_match( '/ms|KiB/', $a['displayValue'] ) ? 'high' : 'moderate';
                $diag[] = array(
                    'title'    => $a['title']   ?? $r['id'],
                    'value'    => $a['displayValue'],
                    'severity' => $sev,
                );
            }
        }
    }

    wp_send_json_success( array(
        'score'       => $score,
        'lcp'         => $lcp,
        'fcp'         => $fcp,
        'diagnostics' => $diag,
    ) );
}

/**
 * AJAX logging for Module 5 — secure, sanitized, and permission-checked.
 *
 * All log writes now go into /uploads/speed-analyzer/ttfb-results-log.txt
 * instead of the plugin folder.
 */
add_action( 'wp_ajax_wpsa_log_module5', function() {
    // 1) Verify the PSI nonce
    check_ajax_referer( 'wpsa_perf_nonce', '_ajax_nonce' );

    // 2) Capability check
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    // ── Build uploads/speed-analyzer/ path ──
    $upload_dir  = wp_upload_dir();
    $base_dir    = trailingslashit( $upload_dir['basedir'] ) . 'speed-analyzer';
    if ( ! file_exists( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
    }
    $results_log = trailingslashit( $base_dir ) . 'ttfb-results-log.txt';
    // ────────────────────────────────────────

    // 3) Unsplash & sanitize the two strings
    if ( isset( $_POST['mobile'], $_POST['desktop'] ) ) {
        $mobile  = sanitize_text_field( wp_unslash( $_POST['mobile']  ) );
        $desktop = sanitize_text_field( wp_unslash( $_POST['desktop'] ) );

        // 4) Append them to the log, each on its own line
        file_put_contents( $results_log,
            $mobile  . "\n" .
            $desktop . "\n",
            FILE_APPEND
        );
    }

    wp_die();
} );
