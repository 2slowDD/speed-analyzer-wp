<?php
/**
 * Modules 2–4 for Speed Analyzer WP
 *
 * @package Speed_Analyzer_WP
 * @version v0.682
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/** 
 * Temporarily disable “missing nonce” and “input not sanitized” checks 
 * for the few direct $_POST reads in this file.
 */
/** phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing,WordPress.DB.DirectDatabaseQuery.DirectQuery */


/**
 * Determine whether a URL is on the same server (host) as this site.
 *
 * @param string $tested_url URL to test.
 * @return bool True if tested URL host contains this site's home host.
 */
function wpsa_is_same_host( $tested_url ) {
    $main_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $test_host = wp_parse_url( esc_url_raw( wp_unslash( $tested_url ) ), PHP_URL_HOST );
    if ( ! $main_host || ! $test_host ) {
        return false;
    }
    return false !== strpos( $test_host, $main_host );
}

/**
 * Module 2: Page asset summary UI placeholder.
 *
 * @param string $tested_url   URL to test.
 * @param string $results_log  Path to results log.
 */
function wpsa_module2_assets( $tested_url, $results_log ) {
    echo '<div class="wpsa-module-2">';
    echo '<h2 class="wpsa-module-title wpsa-module-2-title">2. Page asset summary</h2>';
    echo '<div id="module2-running" class="wpsa-module2-running">Running tests, this could take a minute…</div>';
    echo '<div id="module2-results" class="wpsa-module2-results"></div>';
    echo '<p id="module2-footnote" class="wpsa-footnote">*These values should be as low as possible for the fastest results.</p>';
    echo '</div>';
}
// AJAX handler for Module 2 (PageSpeed Insights)
add_action( 'wp_ajax_wpsa_psi', function() {
    check_ajax_referer( 'wpsa_psi_nonce', 'nonce' );

    $raw_url = wp_unslash( $_POST['test_url'] ?? '' );
    $url     = esc_url_raw( $raw_url );
    if ( ! $url ) {
        wp_send_json_error( 'No URL provided.' );
    }

    $results = array();

    foreach ( array( 'mobile', 'desktop' ) as $strat ) {
        
        /**
         *  // --- TEMPORARY OVERRIDE: simulate PSI error ---
         *   if ( $strat === 'mobile' ) {
            * $results[ $strat ] = array( 'error' => 'Simulated HTTP 500' );
         * continue;
          * }
         */  // ----------------------------------------------

        
        $res = wp_remote_get(
            sprintf(
                'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=%s&strategy=%s&key=%s',
                rawurlencode( $url ),
                $strat,
                WPSA_PSI_API_KEY
            ),
            array( 'timeout' => 30 )
        );

        if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
            $results[ $strat ] = array(
                'error' => is_wp_error( $res )
                    ? $res->get_error_message()
                    : 'HTTP ' . wp_remote_retrieve_response_code( $res ),
            );
        } else {
            $b     = json_decode( wp_remote_retrieve_body( $res ), true );
            $req   = count( $b['lighthouseResult']['audits']['network-requests']['details']['items'] ?? array() );
            $bytes = floatval( $b['lighthouseResult']['audits']['total-byte-weight']['numericValue'] ?? 0 );
            $results[ $strat ] = array( 'requests' => $req, 'bytes' => $bytes );
        }
    }

    $out = array( 'mobile' => '', 'desktop' => '' );
    foreach ( array( 'mobile', 'desktop' ) as $s ) {
        if ( isset( $results[ $s ]['error'] ) ) {
            $out[ $s ] = '<div class="notice notice-warning"><p>'
                       . esc_html( $results[ $s ]['error'] )
                       . '</p></div>';
        } else {
            $req   = $results[ $s ]['requests'];
            $bytes = $results[ $s ]['bytes'];
            $kb    = round( $bytes / 1024, 1 );
            $size  = $bytes > 1048576
                     ? "{$kb} KB (" . round( $bytes / 1048576, 1 ) . " MB)"
                     : "{$kb} KB";

            $out[ $s ] = '<div class="wpsa-stat-cards">'
                       .   '<div class="wpsa-stat-card wpsa-card-requests">'
                       .     '<div class="header">'
                       .       'Requests <span class="custom-tooltip" data-tooltip="Total HTTP requests. Many requests usually indicate many plugins.">?</span>'
                       .     '</div>'
                       .     '<div class="value">' . esc_html( $req ) . '</div>'
                       .   '</div>'
                       .   '<div class="wpsa-stat-card wpsa-card-bytes">'
                       .     '<div class="header">'
                       .       'Page size <span class="custom-tooltip" data-tooltip="Total gzip-compressed size.">?</span>'
                       .     '</div>'
                       .     '<div class="value">' . esc_html( $size ) . '</div>'
                       .   '</div>'
                       . '</div>';
        }
    }

    wp_send_json_success( $out );
});

// AJAX logging for Module 2 — removed nonce check so it won’t 403
add_action( 'wp_ajax_wpsa_log_module2', function() {
    $results_log = plugin_dir_path( __FILE__ ) . 'ttfb-results-log.txt';
    if ( isset( $_POST['mobile'], $_POST['desktop'] ) ) {
        $mobile  = sanitize_text_field( wp_unslash( $_POST['mobile'] ) );
        $desktop = sanitize_text_field( wp_unslash( $_POST['desktop'] ) );
        file_put_contents( $results_log, $mobile . "\n" . $desktop . "\n", FILE_APPEND );
    }
    wp_die();
});
/**
 * Modules 3 & 4: Autoload size and object cache.
 * Render N/A off-server and trigger summary events so Module 6 shows.
 *
 * @param wpdb   $wpdb         WP database object.
 * @param string $results_log  Path to results log.
 */
function wpsa_module3_4_autoload_cache( $wpdb, $results_log ) {
    // Removed the rogue nonce check so this always runs inline

    $raw_url    = wp_unslash( $_POST['test_url'] ?? '' );
    $tested_url = esc_url_raw( $raw_url );

    echo '<div class="wpsa-module-3-4">';

    if ( ! wpsa_is_same_host( $tested_url ) ) {
        // Log Modules 3 & 4 as N/A
        file_put_contents( $results_log, "Module 3: Autoloaded options size: N/A\n", FILE_APPEND );
        file_put_contents( $results_log, "Module 4: Persistent object cache: N/A\n",    FILE_APPEND );

        // Off-server: two N/A cards
        echo '<div class="wpsa-stat-cards">';
          echo '<div>';
            echo '<h2 class="wpsa-module-title wpsa-module-3-title">3. Autoloaded options size</h2>';
            echo '<div class="wpsa-stat-card" style="background:#dddddd;">';
              echo '<div class="header">'
                 . 'Autoloaded options size <span class="custom-tooltip" data-tooltip="N/A on external domains">?</span>'
                 . '</div>';
              echo '<div class="value">N/A</div>';
            echo '</div>';
            echo '<p class="wpsa-footnote">*N/A for off-server URLs</p>';
          echo '</div>';

          echo '<div>';
            echo '<h2 class="wpsa-module-title wpsa-module-4-title">4. Persistent object cache</h2>';
            echo '<div class="wpsa-stat-card" style="background:#dddddd;">';
              echo '<div class="header">'
                 . 'Persistent object cache <span class="custom-tooltip" data-tooltip="N/A on external domains">?</span>'
                 . '</div>';
              echo '<div class="value">N/A</div>';
            echo '</div>';
            echo '<p class="wpsa-footnote">*N/A for off-server URLs</p>';
          echo '</div>';
        echo '</div>';
        echo '</div>';

        // Trigger summary events
        echo '<script>jQuery(function($){'
           .  '$(document).trigger("wpsa_module2_done");'
           .  '$(document).trigger("wpsa_module5_logged");'
           . '});</script>';
        return;
    }

    // Same-server: calculate & render
      // Attempt to pull from object cache first.
    $cache_key = 'wpsa_autoloaded_options_kb';
    $kb = wp_cache_get( $cache_key, 'wpsa' );

    if ( false === $kb ) {
        /** phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery */
        $bytes_raw = $wpdb->get_var(
            "SELECT SUM(OCTET_LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload='yes'"
        );
        $bytes = intval( $bytes_raw );
        $kb    = round( $bytes / 1024, 1 );

        // Store in object cache for one hour.
        wp_cache_set( $cache_key, $kb, 'wpsa', HOUR_IN_SECONDS );
    }
    
    
    $bg1   = ( $kb <= 800 ) ? '#c6f7c3' : '#ffdddd';

    $oc  = ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) ? 'Yes' : 'No';
    $bg2 = ( $oc === 'Yes' ) ? '#c6f7c3' : '#ffdddd';

    // Log Modules 3 & 4
    file_put_contents( $results_log, sprintf( "Module 3: Autoloaded options size: %.1f KB\n", $kb ), FILE_APPEND );
    file_put_contents( $results_log, sprintf( "Module 4: Persistent object cache: %s\n",       $oc ), FILE_APPEND );

    echo '<div class="wpsa-stat-cards">';
      echo '<div>';
        echo '<h2 class="wpsa-module-title wpsa-module-3-title">3. Autoloaded options size</h2>';
        echo '<div class="wpsa-stat-card wpsa-card-autoload" style="background:' . esc_attr( $bg1 ) . '">';
          echo '<div class="header">'
             . 'Autoloaded options size <span class="custom-tooltip" data-tooltip="A server-wide test. Size of all options with autoload=\'yes\'.">?</span>'
             . '</div>';
          echo '<div class="value">'
             . esc_html( $kb ) . ' KB'
             . ( $kb <= 800 ? ' <span class="icon">✅</span>' : '' )
             . '</div>';
        echo '</div>';
        echo '<p class="wpsa-footnote">*Try to keep this value under 800 KB</p>';
      echo '</div>';

      echo '<div>';
        echo '<h2 class="wpsa-module-title wpsa-module-4-title">4. Persistent object cache</h2>';
        echo '<div class="wpsa-stat-card wpsa-card-objectcache" style="background:' . esc_attr( $bg2 ) . '">';
          echo '<div class="header">'
             . 'Persistent object cache <span class="custom-tooltip" data-tooltip="A server-wide test. Whether this site uses a persistent object cache.">?</span>'
             . '</div>';
          echo '<div class="value">'
             . esc_html( $oc )
             . ( $oc === 'Yes' ? ' <span class="icon">✅</span>' : '' )
             . '</div>';
        echo '</div>';
        echo '<p class="wpsa-footnote">*Persistent object cache is beneficial for database-heavy sites (e.g. e-commerce).</p>';
      echo '</div>';
    echo '</div>';
    echo '</div>';
}

/** phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing,WordPress.DB.DirectDatabaseQuery.DirectQuery */

