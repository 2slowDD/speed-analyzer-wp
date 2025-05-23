<?php
/**
 * Helpers for Speed Analyzer WP
 *
 * @package   Speed_Analyzer_WP
 * @version   v0.738
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPSA_WORKER_ENDPOINT', 'https://globalwpspeed.dalibord79.workers.dev/' );

function sawp_decode_api_key( $key ) {
    return preg_replace_callback( '/\d/', function( $m ) {
        // subtract 1, wrap 0→9
        return ( ( intval( $m[0] ) + 9 ) % 10 );
    }, $key );
}


/**
 * Follow 3xx redirects up to $max_hops and return the final URL.
 *
 * @param string $url
 * @param int    $max_hops
 * @return string
 */
function wpsa_resolve_final_url( $url, $max_hops = 5 ) {
    $current = $url;
    $hops    = 0;

    while ( $hops < $max_hops ) {
        $args = array(
            'method'      => 'HEAD',
            'redirection' => 0, // disable auto-redirect
            'timeout'     => 10,
        );
        $resp = wp_remote_request( $current, $args );
        if ( is_wp_error( $resp ) ) {
            break;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        // 3xx? grab Location and loop
        if ( $code >= 300 && $code < 400 ) {
            $loc = wp_remote_retrieve_header( $resp, 'location' );
            if ( $loc ) {
                // resolve relative URLs
                $current = wp_http_validate_url( $loc )
                         ? $loc
                         : untrailingslashit( $current ) . '/' . ltrim( $loc, '/' );
                $hops++;
                continue;
            }
        }
        break;
    }

    return $current;
}

/**
 * Retrieve and reset (if new day) the daily usage record.
 *
 * @return array{date:string,count:int}
 */
function wpsa_get_daily_usage_record() {
    $key = 'wpsa_daily_usage';

    // pull or init record
    $data = get_option( $key, array(
        'date'  => current_time( 'Y_m_d' ),
        'count' => 0,
    ) );

    // reset if date changed
    if ( $data['date'] !== current_time( 'Y_m_d' ) ) {
        $data = array(
            'date'  => current_time( 'Y_m_d' ),
            'count' => 0,
        );
        update_option( $key, $data );
    }

    return $data;
}

/**
 * Get the daily test limit based on license tier.
 *
 * @return int
 */
function wpsa_get_daily_limit() {
    $tier = wpsa_get_license_tier();
    $map  = array(
        'free'     => 10,
        'premium1' => 30,
        'premium2' => 100,
        'premium3' => 700,
    );
    return isset( $map[ $tier ] ) ? $map[ $tier ] : $map['free'];
}

/**
 * Increment the daily usage counter.
 */
function wpsa_increment_daily_usage() {
    // allow an explicit “disable limit” filter (e.g. for QA), but
    // otherwise count usage for *all* tiers—free or paid
    if ( apply_filters( 'wpsa_disable_limit', false ) ) {
        return;
    }

    $key  = 'wpsa_daily_usage';
    $data = wpsa_get_daily_usage_record();

    $data['count']++;
    update_option( $key, $data );
}
/**
 * Module 1: TTFB test, scheme fallback, logging, cache-status probe (via Worker),
 * WP Rocket detection, and UI rendering.
 *
 * @param string $tested_url   URL to test (with or without scheme).
 * @param string $debug_log    Path to debug log.
 * @param string $results_log  Path to results log.
 * @return bool False on error, true on success.
 */
function wpsa_module1_ttfb( $tested_url, $debug_log, $results_log ) {
    // detect lock status (same logic you used for the icon)
    $is_unlocked = in_array( wpsa_get_license_tier(), [ 'premium2', 'premium3' ], true );

    // if we’re locked and they tried an external URL, abort
    if ( ! $is_unlocked && ! wpsa_is_same_host( $tested_url ) ) {
        echo
        '<div class="notice notice-error">'
          . '<p><strong>Error:</strong> Your plan only allows testing on-site URLs.<br>'
            . 'Upgrade your license to Agency plan in order to test ANY URL.'
          . '</p>'
        . '</div>';
        return false;
    }

    // Clear old debug log
    file_put_contents( $debug_log, '' );
    // New debug log
    file_put_contents(
        $debug_log,
        "== Starting test for {$tested_url} ==\n",
        FILE_APPEND
    );

    // Pre-flight URL health check: bail on 5xx/transport errors without counting
    $head = wp_remote_head( $tested_url, array( 'timeout' => 10 ) );
    $hc   = is_wp_error( $head ) ? 0 : wp_remote_retrieve_response_code( $head );
    if ( $hc === 404 ) {
        echo '<div class="notice notice-error"><p><strong>404 Error:</strong> Tested URL is redirected to a 404 page! Please check the URL.</p></div>';
        return false;
    }
    if ( is_wp_error( $head ) || ( $hc >= 500 && $hc < 600 ) ) {
        echo '<div class="notice notice-error"><p><strong>Error:</strong> Test URL returned HTTP '
             . esc_html( $hc ) . '. Aborting tests. Please check the URL and try again.</p></div>';
        return false;
    }

    // Determine test index and timestamp
    $idx = substr_count( @file_get_contents( $results_log ), 'Test #' ) + 1;
    // replaced date() with gmdate()
    $ts  = gmdate( 'Y-m-d H:i:s' );

    // 1) Resolve any 3xx redirects in PHP
    $resolved = wpsa_resolve_final_url( $tested_url );

    // Parse original vs. resolved
    $orig  = wp_parse_url( $tested_url );
    $final = wp_parse_url( $resolved );

    // Normalize paths: treat null or "/" as empty
    $orig_path  = isset( $orig['path'] )  && rtrim( $orig['path'], '/' )  !== ''
                ? rtrim( $orig['path'], '/' )
                : '';
    $final_path = isset( $final['path'] ) && rtrim( $final['path'], '/' ) !== ''
                ? rtrim( $final['path'], '/' )
                : '';

    // Detect a real redirect (host, path, or query changed)
    $host_changed  = ( $orig['host']  ?? '' ) !== ( $final['host']  ?? '' );
    $path_changed  = $orig_path  !== $final_path;
    $query_changed = ( $orig['query'] ?? '' ) !== ( $final['query'] ?? '' );

    if ( ( $host_changed || $path_changed || $query_changed ) && $resolved !== $tested_url ) {
        $display = sprintf(
            /* translators: %s: resolved URL */
            'The URL you shared is being redirected. Here’s the tested URL:<br>%s',
            esc_html( $resolved )
        );
    } else {
        $display = sprintf(
            /* translators: %s: tested URL */
            'Tested URL: %s',
            esc_html( $resolved )
        );
    }

    // Use the resolved URL for all subsequent logic
    $tested_url = $resolved;

    // 2) Echo exactly once, safely JSON-encoded
    echo sprintf(
        '<script>jQuery(function($){ $("#wpsa-tested-url").html(%s); });</script>' . "\n",
        wp_json_encode( $display )
    );

    // 2b) Update the hidden input so all subsequent AJAX calls use the final URL
    echo sprintf(
        '<script>jQuery(function($){ $("input[name=\'test_url\']").val(%s); });</script>' . "\n",
        wp_json_encode( $tested_url )
    );

    // Scheme fallback: always try HTTPS first, then HTTP
    $raw  = preg_replace( '#^https?://#i', '', trim( $tested_url ) );
    $urls = array( 'https://' . $raw, 'http://' . $raw );

    // Fetch via Worker
    $worker_data = null;
    foreach ( $urls as $url ) {
        $resp = wp_remote_get(
            add_query_arg( array( 'url' => $url, 'rand' => wp_rand() ), WPSA_WORKER_ENDPOINT ),
            array( 'timeout' => 30 )
        );
        if ( is_wp_error( $resp ) ) {
            return false;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        $body = wp_remote_retrieve_body( $resp );

        // Log every attempt, not just successes
        file_put_contents(
            $debug_log,
            sprintf( "[%s] HTTP Code: %d for %s\nResponse: %s\n", gmdate('c'), $code, $url, $body ),
            FILE_APPEND
        );

        if ( 200 !== $code ) {
            return false;
        }
        $json = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $json ) || ! isset( $json['ttfb_ms'], $json['location'] ) ) {
            return false;
        }
        $worker_data = $json;

        // capture final URL
        $final = $worker_data['finalUrl'] ?? $url;
        // decide which URL to display
        $display = $final !== $url
            ? sprintf( /* translators: %1$s: final URL */ 'Tested URL redirected to: %1$s', esc_html( $final ) )
            : sprintf( /* translators: %1$s: URL */ 'Tested URL: %1$s', esc_html( $url ) );

        // store tested_url for logging/UI
        $tested_url = $final;

        break;
    }
    if ( ! $worker_data ) {
        return false;
    }

    // Region & TTFB
    $loc = $worker_data['location'];
    $map = array(
        'FRA' => 'Frankfurt/Europe','LHR' => 'London/Europe','AMS' => 'Amsterdam/Europe',
        'IAD' => 'Virginia/USA','JFK' => 'New York/USA','ORD' => 'Chicago/USA',
        'SIN' => 'Singapore/Asia','NRT' => 'Tokyo/Asia','HKG' => 'Hong Kong/Asia',
        'SYD' => 'Sydney/Australia','MEL' => 'Melbourne/Australia','BNE' => 'Brisbane/Australia',
        'SOF' => 'Sofia/Europe','EWR' => 'Newark/USA'
    );
    $continent_map = array_map( function( $r ) { $p = explode( '/', $r ); return end( $p ); }, $map );
    $region = isset( $map[ $loc ] )
        ? $map[ $loc ]
        : ( isset( $continent_map[ $loc ] )
            ? "{$loc}/{$continent_map[$loc]}"
            : $loc
          );
    $ttfb = intval( $worker_data['ttfb_ms'] );

    // Cache-status
    $age_raw    = isset( $worker_data['age'] )       ? trim( $worker_data['age'] )       : '';
    $cfcs_raw   = strtoupper( $worker_data['cfCacheStatus'] ?? '' );
    $xcache_raw = strtoupper( $worker_data['xCache']       ?? '' );
    $xcs_raw    = strtoupper( $worker_data['xCacheStatus'] ?? '' );

    // 1) Any true cache hit → HIT
    if (
        ( $age_raw !== '' && is_numeric( $age_raw ) && intval( $age_raw ) >= 0 )
     || in_array( $cfcs_raw, [ 'HIT', 'REVALIDATED', 'STALE' ], true )
     || stripos( $xcache_raw, 'HIT' ) !== false
     || stripos( $xcs_raw,    'HIT' ) !== false
    ) {
        $cache_status = 'HIT';
    }
    // 2) DYNAMIC or BYPASS → label as-is (yellow)
    elseif ( in_array( $cfcs_raw, [ 'DYNAMIC', 'BYPASS' ], true ) ) {
        $cache_status = ucfirst( strtolower( $cfcs_raw ) );
    }
    // 3) Otherwise → MISS
    else {
        $cache_status = 'MISS';
    }

    // WP Rocket override
    $has_rocket = wpsa_is_same_host( $tested_url ) && defined( 'WP_ROCKET_VERSION' );
    if ( 'MISS' === $cache_status && $has_rocket ) {
        $cache_status = 'Handled by WP Rocket';
    }

    // BEFORE logging, calculate what this test *will* count as:
    $used_before = intval( wpsa_get_daily_usage_record()['count'] );
    $daily_count = $used_before + 1;
    $daily_limit = wpsa_get_daily_limit();

    // License tier abbreviation
    $tier     = wpsa_get_license_tier();
    $abbr_map = array(
        'free'     => 'FR',
        'premium1' => 'PR1',
        'premium2' => 'PR2',
        'premium3' => 'PR3',
    );
    $tier_abbr = isset( $abbr_map[ $tier ] ) ? $abbr_map[ $tier ] : 'FR';

    file_put_contents(
        $results_log,
        "\n" . sprintf(
            /* translators: log line */
            'Test #%d |%s| %s | Tier: %s | URL: %s | Region: %s | Cache: %s | WP Rocket: %s | TTFB: %d ms | Daily: %d/%d',
            $idx,
            SAWP_VERSION,
            gmdate( 'c' ),      // replaced date('c') with gmdate('c')
            $tier_abbr,
            $tested_url,
            $region,
            $cache_status,
            $has_rocket ? 'Yes' : 'No',
            $ttfb,
            $daily_count,
            $daily_limit
        ) . "\n",
        FILE_APPEND
    );

    // Render UI
    wpsa_display_module1( $region, $cache_status, $ttfb );
    echo '<script>jQuery(function($){ $("#running-test").hide(); });</script>';

    return true;
}
/**
 * Renders Module 1 markup.
 *
 * @param string $region       Human-readable region.
 * @param string $cache_status Computed cache status.
 * @param int    $ttfb         Measured TTFB in ms.
 */
function wpsa_display_module1( $region, $cache_status, $ttfb ) {
    // Choose color & emoji for TTFB result
    $col = '#ffdddd'; $emo = '🔴'; $lab = 'Slow';
    if ( $ttfb <= 300 )     { $col = '#c6f7c3'; $emo = '✅'; $lab = 'Fast'; }
    elseif ( $ttfb <= 500 ) { $col = '#fef6a3'; $emo = '🟡'; $lab = 'Acceptable'; }
    elseif ( $ttfb <= 700 ) { $col = '#ffe6a7'; $emo = '🟠'; $lab = 'Moderate'; }

    // Prepare tooltips
    $base_tooltip  = 'Cache status based on HTTP headers.';
    if ( 'HIT' === $cache_status ) {
        $cache_tooltip = $base_tooltip . "\nA “Cache: HIT” delivers content faster and reduces origin load.";
    } elseif ( 'Dynamic' === $cache_status ) {
        $cache_tooltip = $base_tooltip
            . "\nA “Cache: Dynamic” indicates that a cache was detected but was marked non-cacheable or bypassed.";
    } elseif ( 'Handled by WP Rocket' === $cache_status ) {
        $cache_tooltip = 'Page served from WP Rocket’s cache plugin layer.';
    } else {
        $cache_tooltip = $base_tooltip
            . "\nA “Cache: MISS”—Misses are slower than hits because they require a full round-trip to the origin."
            . "\nTry running one more TTFB test to see if you only need to warm up your cache.";
    }

    $ttfb_tooltip = 'Time to First Byte (TTFB) measures the time between the request being made and the first byte of the response being received.'
                  . "&#10;Under 300 ms TTFB is considered fast.";

    // Icon for cache
    if ( 'HIT' === $cache_status ) {
        $cache_icon = '🟢';
    } elseif ( in_array( $cache_status, [ 'Dynamic', 'Bypass' ], true ) ) {
        $cache_icon = '🟡';
    } elseif ( 'Handled by WP Rocket' === $cache_status ) {
        $cache_icon = '';
    } else {
        $cache_icon = '🔴';
    }

    echo '<div class="wpsa-module-1">';
      echo '<h2 class="wpsa-module-title wpsa-module-1-title">1. Server performance test</h2>';
      echo "<table class='widefat wpsa-module1-table'>";
        echo '<thead><tr>'
           . '<th>🌍 Server Location <span class="custom-tooltip" data-tooltip="This refers to the Cloudflare edge server location. The one that handled the request, and NOT your server location.">?</span></th>'
           . '<th>Cache Status <span class="custom-tooltip" data-tooltip="' . esc_attr( $cache_tooltip ) . '">?</span></th>'
           . '<th>TTFB <span class="custom-tooltip" data-tooltip="' . esc_attr( $ttfb_tooltip ) . '">?</span></th>'
           . '</tr></thead>';
        echo '<tbody><tr style="background:' . esc_attr( $col ) . ';">'
           . '<td>' . esc_html( $region ) . '</td>'
           . '<td>' . esc_html( $cache_status ) . ( $cache_icon ? ' ' . esc_html( $cache_icon ) : '' ) . '</td>'
           . '<td>' . esc_html( $ttfb ) . ' ms ' . esc_html( $emo ) . '</td>'
           . '</tr></tbody>';
      echo '</table>';
      echo '<div style="background:' . esc_attr( $col ) . ';padding:16px;border:1px solid #ccc;border-top:none;border-radius:0 0 15px 15px;">'
         . '<p>This test confirms that your server TTFB is <strong>' . esc_html( $lab ) . ' ' . esc_html( $emo ) . '</strong>.</p>'
         . '<p class="wpsa-footnote">*You may need to <strong>run this test twice</strong> to warm up your cache.</p>'
      . '</div>';
    echo '</div>';
}

/**
 * How many tests you have *left* today.
 *
 * @return int
 */
function wpsa_get_daily_remaining() {
    $limit = wpsa_get_daily_limit();
    $used  = intval( wpsa_get_daily_usage_record()['count'] );
    return max( 0, $limit - $used );
}
