<?php
/**
 * Modules 2–4 for Speed Analyzer 
 * 
 * @package Speed Analyzer 
 * @version v0.694
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
add_action( 'wp_ajax_wpsa_psi', 'wpsa_ajax_psi' );
function wpsa_ajax_psi() {
    check_ajax_referer( 'wpsa_psi_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $raw_url = wp_unslash( filter_input( INPUT_POST, 'test_url', FILTER_UNSAFE_RAW ) ?? '' );
    $url     = esc_url_raw( $raw_url );
    if ( ! $url ) {
        wp_send_json_error( 'No URL provided.' );
    }

    $results = [];

    foreach ( [ 'mobile', 'desktop' ] as $strat ) {
       // Proxy via our Cloudflare Worker’s PSI proxy
    $endpoint = add_query_arg(
    array(
        'psi_url'  => rawurlencode( $url ),
        'strategy' => $strat,
    ),
      WPSA_WORKER_ENDPOINT . 'psi'
    );
        $response = wp_remote_get( $endpoint, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            $results[ $strat ] = [
                'error' => is_wp_error( $response )
                    ? $response->get_error_message()
                    : 'HTTP ' . wp_remote_retrieve_response_code( $response ),
            ];
        } else {
            $body  = wp_remote_retrieve_body( $response );
            $data  = json_decode( $body, true );
            $items = $data['lighthouseResult']['audits']['network-requests']['details']['items'] ?? [];
            $req   = count( $items );
            $bytes = floatval( $data['lighthouseResult']['audits']['total-byte-weight']['numericValue'] ?? 0 );
            $results[ $strat ] = [ 'requests' => $req, 'bytes' => $bytes ];
        }
    }

    $out = [ 'mobile' => '', 'desktop' => '' ];
    foreach ( [ 'mobile', 'desktop' ] as $s ) {
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
                       .     '<div class="header">Requests <span class="custom-tooltip" data-tooltip="Total HTTP requests.">?</span></div>'
                       .     '<div class="value">' . esc_html( $req ) . '</div>'
                       .   '</div>'
                       .   '<div class="wpsa-stat-card wpsa-card-bytes">'
                       .     '<div class="header">Page size <span class="custom-tooltip" data-tooltip="Total gzip-compressed size.">?</span></div>'
                       .     '<div class="value">' . esc_html( $size ) . '</div>'
                       .   '</div>'
                       . '</div>';
        }
    }

    wp_send_json_success( $out );
}

// AJAX logging for Module 2 (bumped to v0.692)
add_action( 'wp_ajax_wpsa_log_module2', 'wpsa_ajax_log_module2' );
function wpsa_ajax_log_module2() {
    check_ajax_referer( 'wpsa_psi_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
    }

    // → build path under uploads/speed-analyzer/ttfb-results-log.txt
    $upload_dir  = wp_upload_dir();
    $base_dir    = trailingslashit( $upload_dir['basedir'] ) . 'speed-analyzer';
    if ( ! file_exists( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
    }
    $results_log = trailingslashit( $base_dir ) . 'ttfb-results-log.txt';

    if ( isset( $_POST['mobile'], $_POST['desktop'] ) ) {
        $mobile  = sanitize_text_field( wp_unslash( $_POST['mobile'] ) );
        $desktop = sanitize_text_field( wp_unslash( $_POST['desktop'] ) );
        file_put_contents( $results_log, $mobile . "\n" . $desktop . "\n", FILE_APPEND );
    }

    wp_die();
}

/**
 * Modules 3 & 4: Autoload size and object cache.
 * Render “N/A” off-server and trigger summary events so Module 6 shows.
 *
 * @param wpdb   $wpdb         WP database object.
 * @param string $results_log  Path to results log.
 */
function wpsa_module3_4_autoload_cache( $wpdb, $results_log ) {
    $raw_url    = wp_unslash( filter_input( INPUT_POST, 'test_url', FILTER_UNSAFE_RAW ) ?? '' );
    $tested_url = esc_url_raw( $raw_url );

    echo '<div class="wpsa-module-3-4">';

    if ( ! wpsa_is_same_host( $tested_url ) ) {
        // Log “N/A” for Modules 3 & 4
        file_put_contents( $results_log, "Module 3: Autoloaded options size: N/A\n", FILE_APPEND );
        file_put_contents( $results_log, "Module 4: Persistent object cache: N/A\n", FILE_APPEND );

        // Output “N/A” UI
        echo '<div class="wpsa-stat-cards">';
          echo '<div>';
            echo '<h2 class="wpsa-module-title wpsa-module-3-title">3. Autoloaded options size</h2>';
            echo '<div class="wpsa-stat-card" style="background:#dddddd;">';
              echo '<div class="header">Autoloaded options size <span class="custom-tooltip" data-tooltip="N/A on external domains">?</span></div>';
              echo '<div class="value">N/A</div>';
            echo '</div>';
            echo '<p class="wpsa-footnote">*N/A for off-server URLs</p>';
          echo '</div>';

          echo '<div>';
            echo '<h2 class="wpsa-module-title wpsa-module-4-title">4. Persistent object cache</h2>';
            echo '<div class="wpsa-stat-card" style="background:#dddddd;">';
              echo '<div class="header">Persistent object cache <span class="custom-tooltip" data-tooltip="N/A on external domains">?</span></div>';
              echo '<div class="value">N/A</div>';
            echo '</div>';
            echo '<p class="wpsa-footnote">*N/A for off-server URLs</p>';
          echo '</div>';
        echo '</div>';
        echo '</div>';

        return;
    }

    $cache_key = 'wpsa_autoloaded_options_kb';
    $kb = wp_cache_get( $cache_key, 'wpsa' );

    if ( false === $kb ) {
        // Summarize size of all autoloaded options (in KB).
        $bytes_raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "SELECT SUM(OCTET_LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload=%s",
                'yes'
            )
        );
        $bytes = intval( $bytes_raw );
        $kb    = round( $bytes / 1024, 1 );
        wp_cache_set( $cache_key, $kb, 'wpsa', HOUR_IN_SECONDS );
    }

    $bg1 = ( $kb <= 800 ) ? '#c6f7c3' : '#ffdddd';
    $oc  = ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) ? 'Yes' : 'No';
    $bg2 = ( $oc === 'Yes' ) ? '#c6f7c3' : '#ffdddd';

    file_put_contents( $results_log, sprintf( "Module 3: Autoloaded options size: %.1f KB\n", $kb ), FILE_APPEND );
    file_put_contents( $results_log, sprintf( "Module 4: Persistent object cache: %s\n", $oc ),        FILE_APPEND );

    echo '<div class="wpsa-stat-cards">';
      echo '<div>';
        echo '<h2 class="wpsa-module-title wpsa-module-3-title">3. Autoloaded options size</h2>';
        echo '<div class="wpsa-stat-card wpsa-card-autoload" style="background:' . esc_attr( $bg1 ) . '">';
          echo '<div class="header">Autoloaded options size <span class="custom-tooltip" data-tooltip="A server-wide test. Size of all options with autoloaded=\'yes\'.">?</span></div>';
          echo '<div class="value">' . esc_html( $kb ) . ' KB' . ( $kb <= 800 ? ' <span class="icon">✅</span>' : '' ) . '</div>';
        echo '</div>';
        echo '<p class="wpsa-footnote">*Try to keep this value under 800 KB</p>';
      echo '</div>';

      echo '<div>';
        echo '<h2 class="wpsa-module-title wpsa-module-4-title">4. Persistent object cache</h2>';
        echo '<div class="wpsa-stat-card wpsa-card-objectcache" style="background:' . esc_attr( $bg2 ) . '">';
          echo '<div class="header">Persistent object cache <span class="custom-tooltip" data-tooltip="A server-wide test. Whether this site uses a persistent object cache.">?</span></div>';
          echo '<div class="value">' . esc_html( $oc ) . ( $oc === 'Yes' ? ' <span class="icon">✅</span>' : '' ) . '</div>';
        echo '</div>';
        echo '<p class="wpsa-footnote">*Persistent object cache is beneficial for database-heavy sites (e.g. e-commerce).</p>';
      echo '</div>';
    echo '</div>';
    echo '</div>';
}

/**
 * Enqueue the inline JS that replaces the previous inline <script>…</script> echoes.
 * This function hooks into admin_enqueue_scripts and appends a small snippet
 * to the already-registered handle 'wpsa-admin-scripts'.
 *
 * It triggers the two custom events that Module 3/4 needs when it outputs “N/A”:
 *      $(document).trigger('wpsa_module2_done');
 *      $(document).trigger('wpsa_module5_logged');
 *
 * Because admin_enqueue_scripts only runs on the admin page, we check the hook.
 */
add_action( 'admin_enqueue_scripts', 'wpsa_enqueue_modules_inline_scripts' );
function wpsa_enqueue_modules_inline_scripts( $hook ) {
    // Only run on our Speed Analyzer tool page
    if ( 'tools_page_speed-analyzer' !== $hook ) {
        return;
    }

    $inline_js = "
        jQuery(function($){
            // If Module 3/4 printed immediately and we’re on an external URL,
            // then we need to fire these events so Module 6 can continue.
            $(document).trigger('wpsa_module2_done');
            $(document).trigger('wpsa_module5_logged');
        });
    ";

    wp_add_inline_script( 'wpsa-admin-scripts', $inline_js );
}
