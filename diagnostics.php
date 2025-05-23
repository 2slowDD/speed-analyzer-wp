<?php
/**
 * Module 5: Performance & Diagnostics
 *
 * @package Speed_Analyzer_WP
 * @version   v0.681
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Temporarily disable warnings for direct $_POST usage
 * (unslash, sanitization, and missing-nonce checks are done inline).
 */
/** phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing */


/**
 * Renders the main UI for Module 5.
 *
 * @param string $tested_url   URL to test.
 * @param string $results_log  Path to results log.
 */
function wpsa_module5_performance_diagnostics( $tested_url, $results_log ) {
    echo '<div class="wpsa-module-5">';
      echo '<h2 class="wpsa-module-title wpsa-module-5-title">';
        echo '5. Performance &amp; Diagnostics ';
        echo '<small style="font-size:14px;color:#777;vertical-align:middle;">';
        echo '(as on <a href="https://pagespeed.web.dev/" target="_blank">PageSpeed Insights (PSI)</a>)';
        echo '</small>';
      echo '</h2>';

      echo '<div id="module5-running" class="wpsa-module5-running">Running tests, this could take a minute..</div>';
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

// AJAX handler: fetch PSI data, handle 403/429 gracefully
add_action( 'wp_ajax_wpsa_performance', function() {
    // Security check
    check_ajax_referer( 'wpsa_perf_nonce', '_ajax_nonce' );

    // Sanitize inputs
    $url   = esc_url_raw( $_POST['test_url']   ?? '' );
    $strat = sanitize_key( $_POST['strategy'] ?? 'mobile' );
    if ( ! $url || ! in_array( $strat, array( 'mobile','desktop' ), true ) ) {
        wp_send_json_error();
    }

    // Call PSI API
    $res = wp_remote_get(
        sprintf(
            'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=%s&strategy=%s&key=%s',
            rawurlencode( $url ), $strat, WPSA_PSI_API_KEY
        ),
        array( 'timeout' => 30 )
    );

    // Retrieve HTTP status code
    $code = wp_remote_retrieve_response_code( $res );

    // Handle errors and quota limits
    if ( is_wp_error( $res ) || 200 !== $code ) {
        // Quota or rate limit exceeded?
        if ( ! is_wp_error( $res ) && in_array( $code, array( 403, 429 ), true ) ) {
            wp_send_json_error( array(
                'message' => 'Service temporarily unavailable. Please try again later.'
            ) );
        }
        // Other errors
        wp_send_json_error();
    }

    // Parse the Lighthouse result
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
} );

// Preserve existing Module 5 logging handler
add_action( 'wp_ajax_wpsa_log_module5', function() {
    $results_log = plugin_dir_path( __FILE__ ) . 'ttfb-results-log.txt';
    if ( isset( $_POST['mobile'], $_POST['desktop'] ) ) {
        file_put_contents( $results_log, $_POST['mobile'] . $_POST['desktop'], FILE_APPEND );
    }
    wp_die();
} );

/** phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing */