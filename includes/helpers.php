<?php
/**
 * Helpers for Speed Analyzer 
 * helpers.php
 * @package   Speed Analyzer 
 * @version   v0.775
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Recursively sanitize every scalar inside a decoded payload.
 *
 * Used for JSON bodies and structured POST maps, where sanitizing the raw
 * request string before decoding would corrupt it. Decode first, then run the
 * result through this so every leaf value is sanitized.
 *
 * CAVEAT: sanitize_text_field() strips percent-encoded octets (%XX) and collapses
 * newlines. That is harmless for the current diagnostics payloads (title, value,
 * severity), but if a URL or multi-line text is ever added to one of those payloads
 * it will be mangled here - reach for a per-field sanitizer at that point.
 *
 * @param mixed $value Decoded value (array, scalar or null).
 * @return mixed Sanitized value of the same shape.
 */
if ( ! function_exists( 'wpsa_sanitize_text_deep' ) ) {
    function wpsa_sanitize_text_deep( $value ) {
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( $value as $k => $v ) {
                $clean[ sanitize_text_field( (string) $k ) ] = wpsa_sanitize_text_deep( $v );
            }
            return $clean;
        }
        if ( is_string( $value ) ) {
            return sanitize_text_field( $value );
        }
        // Numbers, booleans and null are already safe scalars.
        return $value;
    }
}

/**
 * Debug-only logger for Speed Analyzer.
 *
 * Every diagnostic message in the plugin funnels through this one function so
 * that production sites stay quiet. Output happens only when both WP_DEBUG and
 * WP_DEBUG_LOG are enabled, which is the WordPress-sanctioned way for a site
 * owner to opt in to debug output.
 *
 * @param string $message Message to record verbatim.
 * @return void
 */
if ( ! function_exists( 'wpsa_debug_log' ) ) {
    function wpsa_debug_log( $message ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }
        if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- single intentional debug sink for the whole plugin; unreachable unless the site owner has switched on both WP_DEBUG and WP_DEBUG_LOG.
        error_log( (string) $message );
    }
}

// helpers.php – disable WP emoji replacement in admin so you don't get broken <img> placeholders
add_action( 'admin_init', function() {
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'admin_print_styles',  'print_emoji_styles' );
} );

add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Only show on the main Speed Analyzer admin screen.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    
   // $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
  //  if ( $page !== 'speed-analyzer' ) {
  //      return;
  //  }

  //  $ttfb = wpsa_check_quota( 'ttfb' );
  //  $pdf  = wpsa_check_quota( 'pdf' );

  //  echo '<div class="notice notice-warning"><p><strong>WPSA quota debug</strong></p>';
  //  echo '<p>saved tier option: <code>' . esc_html( (string) get_option( 'wpsa_saved_tier', 'free' ) ) . '</code></p>';
  //  echo '<p>license key present: <code>' . esc_html( get_option( 'wpsa_license_key', '' ) ? 'yes' : 'no' ) . '</code></p>';
  //  echo '<p>gatekeeper url: <code>' . esc_html( defined( 'WPSA_GATEKEEPER_URL' ) ? (string) WPSA_GATEKEEPER_URL : '(not defined)' ) . '</code></p>';
  //  echo '<p>quota ttfb: <code>' . esc_html( wp_json_encode( $ttfb ) ) . '</code></p>';
  //  echo '<p>quota pdf: <code>' . esc_html( wp_json_encode( $pdf ) ) . '</code></p>';
  //  echo '</div>';
} );


/**
 * Round‐robin selection of Worker endpoints.
 *
 * @return string
 */
function wpsa_get_worker_endpoint() {
    $workers = array(
        'https://globalwpspeed.dalibord79.workers.dev/',
        'https://globalwpspeed1.dalibord79.workers.dev/',
        // add more endpoints here as needed
    );
    $count = count( $workers );
    if ( 0 === $count ) {
        wpsa_debug_log( 'No Speed Analyzer workers configured' );
        return '';
    }
    $opt_key = 'wpsa_next_worker_index';
    $index   = intval( get_option( $opt_key, 0 ) ) % $count;
    if ( $index < 0 ) {
        $index += $count;
    }
    $endpoint   = $workers[ $index ];
    $next_index = ( $index + 1 ) % $count;
    update_option( $opt_key, $next_index );
    return $endpoint;
}

/**
 * Return the current license tier IF it hasn't expired, otherwise 'free'.
 *
 * @return string  'free'|'premium1'|'premium2'|'premium3'
 */
function wpsa_get_license_tier() {
    // Tier/limits authority is Gatekeeper.
    // This is only the locally saved display tier (used for UI and local grace cases).
    return (string) get_option( 'wpsa_saved_tier', 'free' );
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
        $resp = wp_remote_request( $current, array(
            'method'      => 'HEAD',
            'redirection' => 0,
            'timeout'     => 10,
        ) );
        if ( is_wp_error( $resp ) ) {
            break;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code >= 300 && $code < 400 ) {
            $loc = wp_remote_retrieve_header( $resp, 'location' );
            if ( $loc ) {
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
 * Get response headers for a URL as seen from the WordPress server.
 * Used for Module 1 "Response headers" so it matches browser/KeyCDN-style results.
 *
 * @param string $url
 * @return array<string,string>
 */
function wpsa_get_origin_response_headers( $url ) {
    $out = array();

    $url = is_string( $url ) ? trim( $url ) : '';
    if ( $url === '' ) {
        return $out;
    }

    $resp = wp_safe_remote_head(
        $url,
        array(
            'timeout'     => 15,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (compatible; Speed Analyzer; +https://wpservice.pro/our-products/speed-analyzer-wp-plugin/)',
        )
    );

    if ( is_wp_error( $resp ) ) {
        return $out;
    }

    $code = (int) wp_remote_retrieve_response_code( $resp );
    if ( $code > 0 ) {
        $out['status'] = (string) $code;
    }

    $headers = wp_remote_retrieve_headers( $resp );

    // WP can return array OR Requests_Utility_CaseInsensitiveDictionary
    if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
        $headers = $headers->getAll();
    }

    if ( is_array( $headers ) ) {
        foreach ( $headers as $k => $v ) {
            $key = strtolower( (string) $k );
            if ( $key === '' ) {
                continue;
            }

            // Don’t ever expose sensitive headers
            if ( in_array( $key, array( 'set-cookie', 'cookie', 'authorization', 'proxy-authorization' ), true ) ) {
                continue;
            }

            if ( is_array( $v ) ) {
                $out[ $key ] = implode( ', ', array_map( 'strval', $v ) );
            } else {
                $out[ $key ] = (string) $v;
            }
        }
    }

    return $out;
}

    /**
     * Read diagnostics for a given test # from uploads/speed-analyzer/psi-diag-log.txt.
     * Returns ['mobile' => [...], 'desktop' => [...]] or empty arrays if not found.
     */
    function wpsa_read_diag_for_test( $test_no ) {
        $upload = wp_upload_dir();
        $file   = trailingslashit( $upload['basedir'] ) . 'speed-analyzer/psi-diag-log.txt';
        $empty  = [
            'mobile'  => [ 'opportunities' => [], 'insights' => [] ],
            'desktop' => [ 'opportunities' => [], 'insights' => [] ],
        ];
        if ( ! file_exists( $file ) ) return $empty;
    
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming line-by-line read with an early break over an append-only diagnostics log at a fixed, plugin-owned path; WP_Filesystem has no incremental reader and get_contents_array() would load an unbounded file into memory.
        $fh = fopen( $file, 'r' );
        if ( ! $fh ) return $empty;
    
        $row = null;
        while ( ! feof( $fh ) ) {
            $line = trim( (string) fgets( $fh ) );
            if ( $line === '' ) { continue; }
            $obj = json_decode( $line, true );
            if ( isset( $obj['test'] ) && intval( $obj['test'] ) === intval( $test_no ) ) {
                $row = $obj; break;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the streaming handle opened above.
        fclose( $fh );
        if ( ! $row ) return $empty;
    
        $normalize = function( $side ) {
            if ( isset( $side['opportunities'] ) || isset( $side['insights'] ) ) {
                return [
                    'opportunities' => is_array( $side['opportunities'] ?? [] ) ? $side['opportunities'] : [],
                    'insights'      => is_array( $side['insights']      ?? [] ) ? $side['insights']      : [],
                ];
            }
            if ( is_array( $side ) ) {
                // legacy (mixed list) → show as opportunities only
                return [ 'opportunities' => $side, 'insights' => [] ];
            }
            return [ 'opportunities' => [], 'insights' => [] ];
        };
    
        return [
            'mobile'  => $normalize( $row['mobile']  ?? [] ),
            'desktop' => $normalize( $row['desktop'] ?? [] ),
        ];
    }
    
    
    /**
     * Extract CWV (field) data from PSI response.
     *
     * Priority rule:
     * - If URL (page) data exists, use it.
     * - Else if origin data exists, use it.
     * - Else return empty.
     *
     * @param array $psi PSI/Lighthouse response array.
     * @return array{
     *   scope:string, id:string, overall_category:string, origin_fallback:bool|null,
     *   metrics:array<string,array{percentile:mixed,category:string,distributions:array<int,array<string,mixed>>}>
     * }
     */
    function wpsa_extract_cwv_field_data( array $psi ) {
        $page   = ( isset( $psi['loadingExperience'] ) && is_array( $psi['loadingExperience'] ) ) ? $psi['loadingExperience'] : array();
        $origin = ( isset( $psi['originLoadingExperience'] ) && is_array( $psi['originLoadingExperience'] ) ) ? $psi['originLoadingExperience'] : array();
    
        $pick = array();
        $scope = '';
    
        if ( ! empty( $page ) ) {
            $pick  = $page;
            $scope = 'page';
        } elseif ( ! empty( $origin ) ) {
            $pick  = $origin;
            $scope = 'origin';
        } else {
            return array();
        }
    
        $id = '';
        if ( isset( $pick['id'] ) && is_string( $pick['id'] ) ) {
            $id = $pick['id'];
        }
    
        $overall = '';
        if ( isset( $pick['overall_category'] ) && is_string( $pick['overall_category'] ) ) {
            $overall = strtoupper( $pick['overall_category'] );
        }
    
        $origin_fallback = null;
        if ( array_key_exists( 'origin_fallback', $pick ) ) {
            $origin_fallback = (bool) $pick['origin_fallback'];
        }
    
        $metrics = array();
        if ( isset( $pick['metrics'] ) && is_array( $pick['metrics'] ) ) {
            // Keep only the metrics object (we’ll normalize per-metric later).
            $metrics = $pick['metrics'];
        }
    
        return array(
            'scope'          => $scope,
            'id'             => $id,
            'overall_category'=> $overall,
            'origin_fallback'=> $origin_fallback,
            'metrics'        => $metrics,
        );
    }
    
    /**
     * Normalize a single CWV metric structure (percentile/category/distributions).
     *
     * @param array $m Raw PSI metric array.
     * @return array{percentile:mixed,category:string,distributions:array<int,array<string,mixed>>}
     */
    function wpsa_normalize_cwv_metric( $m ) {
        $out = array(
            'percentile'    => 'N/A',
            'category'      => 'N/A',
            'distributions' => array(),
        );
    
        if ( ! is_array( $m ) ) {
            return $out;
        }
    
        if ( array_key_exists( 'percentile', $m ) ) {
            $out['percentile'] = $m['percentile'];
        }
    
        if ( isset( $m['category'] ) && is_string( $m['category'] ) ) {
            $out['category'] = strtoupper( $m['category'] );
        }
    
        if ( isset( $m['distributions'] ) && is_array( $m['distributions'] ) ) {
            // Preserve as-is (min/max/proportion). We’ll format later.
            $out['distributions'] = $m['distributions'];
        }
    
        return $out;
    }
    
    /**
     * Format distributions into "good/ni/poor" percentages like PSI UI.
     *
     * PSI returns proportions (0..1). We round to whole percentages.
     *
     * @param array $dists
     * @return string Either "85/9/6" or "N/A"
     */
    function wpsa_format_cwv_distributions( $dists ) {
        if ( ! is_array( $dists ) || count( $dists ) < 1 ) {
            return 'N/A';
        }
    
        $pct = array();
        foreach ( $dists as $d ) {
            if ( ! is_array( $d ) || ! isset( $d['proportion'] ) ) {
                continue;
            }
            $p = $d['proportion'];
            if ( ! is_numeric( $p ) ) {
                continue;
            }
            $pct[] = (int) round( (float) $p * 100 );
        }
    
        if ( empty( $pct ) ) {
            return 'N/A';
        }
    
        // Typical ordering is 3 buckets; if fewer exist, still join what we have.
        return implode( '/', $pct );
    }
    
    /**
     * Compute "CWV Assessment: PASSED/FAILED/N/A" based on LCP+CLS+INP categories.
     *
     * NOTE - this function deliberately still uses INP, not TBT.
     *
     * Release 1.19.0 replaced INP with Total Blocking Time on every LAB surface.
     * This is the FIELD lane (CrUX real-user data) and it must NOT follow:
     *
     *   1. CrUX publishes no total_blocking_time metric. Its field set is LCP,
     *      INP, CLS, FCP, TTFB and RTT, so there is nothing to swap in.
     *   2. Google defines the Core Web Vitals assessment as LCP + INP + CLS.
     *      Removing INP here would turn a three-of-three check into a
     *      two-of-three one that can report PASSED for a site Google marks
     *      FAILED - on a customer-facing report.
     *   3. Google's own guidance is that TBT is "a proxy metric for INP in the
     *      lab (where INP cannot usually be accurately measured)". The lab proxy
     *      does not replace the field metric it proxies for.
     *
     * Guarded by tests/tbt-harness.js (AC-9), which goes red if this function or
     * cwv-ui.js is converted to TBT. See tasks/2026-08-31-inp-to-tbt-design.md,
     * decision D5.
     *
     * @param array $metrics Normalized map keyed by PSI metric ids.
     * @return string
     */
    function wpsa_cwv_assessment_from_metrics( array $metrics ) {
        $need = array(
            'LARGEST_CONTENTFUL_PAINT_MS',
            'CUMULATIVE_LAYOUT_SHIFT_SCORE',
            'INTERACTION_TO_NEXT_PAINT',
        );
    
        foreach ( $need as $k ) {
            if ( ! isset( $metrics[ $k ] ) || ! is_array( $metrics[ $k ] ) ) {
                return 'N/A';
            }
            $cat = $metrics[ $k ]['category'] ?? 'N/A';
            if ( ! is_string( $cat ) || $cat === 'N/A' ) {
                return 'N/A';
            }
        }
    
    $lcp = strtoupper( (string) ( $metrics['LARGEST_CONTENTFUL_PAINT_MS']['category'] ?? '' ) );
    $cls = strtoupper( (string) ( $metrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']['category'] ?? '' ) );
    $inp = strtoupper( (string) ( $metrics['INTERACTION_TO_NEXT_PAINT']['category'] ?? '' ) );

    // PSI field data commonly uses FAST/AVERAGE/SLOW (CrUX),
    // but some responses may use GOOD/NEEDS_IMPROVEMENT/POOR.
    $is_pass = function( $cat ) {
        $cat = strtoupper( (string) $cat );
        return in_array( $cat, array( 'FAST', 'GOOD' ), true );
    };

    if ( $is_pass( $lcp ) && $is_pass( $cls ) && $is_pass( $inp ) ) {
        return 'PASSED';
    }

    return 'FAILED';

    }
    
    /**
     * Build a single-line CWV summary suitable for ttfb-results-log.txt.
     * Includes as much field data as PSI provides.
     *
     * @param array  $psi PSI response array.
     * @param string $device_label "Mobile" or "Desktop"
     * @return array{summary:string,raw:string,scope:string} summary/raw can be "N/A"
     */
    function wpsa_build_cwv_log_lines( array $psi, $device_label ) {
        $device_label = is_string( $device_label ) ? $device_label : '';
    
        $fd = wpsa_extract_cwv_field_data( $psi );
        if ( empty( $fd ) ) {
            return array(
                'summary' => 'N/A',
                'raw'     => 'N/A',
                'scope'   => '',
            );
        }
    
        $metrics_raw = is_array( $fd['metrics'] ?? array() ) ? $fd['metrics'] : array();
    
        // Normalize only the metrics we care about (and keep everything else for RAW).
        $wanted = array(
            'LARGEST_CONTENTFUL_PAINT_MS'      => array( 'label' => 'LCP',  'unit' => 'ms' ),
            'INTERACTION_TO_NEXT_PAINT'        => array( 'label' => 'INP',  'unit' => 'ms' ),
            'CUMULATIVE_LAYOUT_SHIFT_SCORE'    => array( 'label' => 'CLS',  'unit' => ''   ),
            'FIRST_CONTENTFUL_PAINT_MS'        => array( 'label' => 'FCP',  'unit' => 'ms' ),
            'EXPERIMENTAL_TIME_TO_FIRST_BYTE'  => array( 'label' => 'TTFB', 'unit' => 'ms' ),
        );
    
        $normalized = array();
        foreach ( $wanted as $key => $meta ) {
            if ( isset( $metrics_raw[ $key ] ) ) {
                $normalized[ $key ] = wpsa_normalize_cwv_metric( $metrics_raw[ $key ] );
            }
        }
    
        $assessment = wpsa_cwv_assessment_from_metrics( $normalized );
        $overall    = (string) ( $fd['overall_category'] ?? 'N/A' );
        $scope      = (string) ( $fd['scope'] ?? '' );
    
        $parts = array();
        $parts[] = 'Assessment: ' . $assessment;
        $parts[] = 'Overall: ' . ( $overall !== '' ? $overall : 'N/A' );
    
        foreach ( $wanted as $key => $meta ) {
            if ( ! isset( $normalized[ $key ] ) ) {
                continue;
            }
    
            $m = $normalized[ $key ];
            $cat = (string) ( $m['category'] ?? 'N/A' );
    
        $p75 = $m['percentile'] ?? 'N/A';
        if ( is_numeric( $p75 ) ) {
            // CLS is typically reported in hundredths in CrUX structures (e.g. 6 => 0.06).
            if ( $key === 'CUMULATIVE_LAYOUT_SHIFT_SCORE' ) {
                $p75 = number_format( ( (float) $p75 ) / 100, 2, '.', '' );
            } else {
                $p75 = (string) $p75;
            }
        } else {
            $p75 = 'N/A';
        }

        $dist = wpsa_format_cwv_distributions( $m['distributions'] ?? array() );

        $unit = (string) $meta['unit'];
        $p75_str = ( $p75 !== 'N/A' ) ? ( $p75 . $unit ) : 'N/A';

    
            $parts[] = sprintf(
                '%s: %s (p75: %s; %s)',
                $meta['label'],
                $cat !== '' ? $cat : 'N/A',
                $p75_str,
                $dist
            );
        }
    
        $scope_label = ( $scope === 'page' ) ? 'Page' : 'Origin';
    
        // RAW: keep the selected scope only (page has priority if both exist).
        $raw_payload = array(
            'scope'          => $scope,
            'id'             => (string) ( $fd['id'] ?? '' ),
            'overall_category'=> (string) ( $fd['overall_category'] ?? '' ),
            'origin_fallback'=> $fd['origin_fallback'],
            'metrics'        => $metrics_raw,
        );
    
        return array(
            'summary' => sprintf( 'Module 5 CWV %s (%s): %s', $scope_label, $device_label, implode( ' | ', $parts ) ),
            'raw'     => sprintf( 'Module 5 CWV %s (%s) RAW: %s', $scope_label, $device_label, wp_json_encode( $raw_payload ) ),
            'scope'   => $scope,
        );
    }

    
    
    


/**
 * Keep log sizes sane: if main log reaches 1000 tests, keep last 100 and renumber 1..100.
 * Applies to both ttfb-results-log.txt and psi-diag-log.txt when present.
 */
    function wpsa_maybe_trim_logs() {

    // Allow disabling automatic log trimming from the Schedule settings.
    // If "Prevent automatic results log trimming" is enabled, skip all trimming.
    $schedule_settings = get_option( 'wpsa_schedule_settings', array() );
    if ( is_array( $schedule_settings ) && ! empty( $schedule_settings['prevent_trim'] ) ) {
        return;
    }

    $upload = wp_upload_dir();
    $dir    = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
    if ( ! is_dir( $dir ) ) { return; }


    // Helper to trim/renumber a “per-test” log file.
    $trim_file = function( $path, $is_json_lines = false ) {
        if ( ! file_exists( $path ) ) return;

        $lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! is_array( $lines ) || empty( $lines ) ) return;

        // Count max test number present
        $max = 0;
        if ( $is_json_lines ) {
            foreach ( $lines as $ln ) {
                $o = json_decode( $ln, true );
                if ( isset( $o['test'] ) ) { $max = max( $max, intval( $o['test'] ) ); }
            }
        } else {
            foreach ( $lines as $ln ) {
                if ( preg_match( '/^\s*Test\s+#(\d+)\b/', $ln, $m ) ) {
                    $max = max( $max, intval( $m[1] ) );
                }
            }
        }
        if ( $max < 1000 ) return; // nothing to do

        // Keep last 100 tests only (max-99 .. max)
        $keep_from = $max - 99;

        $out = [];
        if ( $is_json_lines ) {
            foreach ( $lines as $ln ) {
                $o = json_decode( $ln, true );
                if ( ! $o || ! isset( $o['test'] ) ) continue;
                $t = intval( $o['test'] );
                if ( $t < $keep_from ) continue;
                $o['test'] = $t - $keep_from + 1; // renumber to 1..100
                $out[] = wp_json_encode( $o );
            }
        } else {
            // group plain log lines by Test #, then rewrite the kept block with renumbered headers
            $blocks = [];
            $current = null;
            foreach ( $lines as $ln ) {
                if ( preg_match( '/^\s*Test\s+#(\d+)\b/', $ln, $m ) ) {
                    $current = intval( $m[1] );
                    $blocks[ $current ] = [];
                }
                if ( $current !== null ) {
                    $blocks[ $current ][] = $ln;
                }
            }
            foreach ( $blocks as $t => $blk ) {
                if ( $t < $keep_from ) continue;
                $new_no = $t - $keep_from + 1; // 1..100
                foreach ( $blk as $ln ) {
                    $out[] = preg_replace( '/^\s*Test\s+#\d+/', 'Test #'.$new_no, $ln );
                }
            }
        }

        // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
        @file_put_contents( $path, implode( "\n", $out ) . "\n" );
    };

    $trim_file( trailingslashit( $dir ) . 'ttfb-results-log.txt', false );
    $trim_file( trailingslashit( $dir ) . 'psi-diag-log.txt',     true );
}


    /**
     * Render a capped (10-per-window) dropdown with slider + copy.
     *
     * @param string $id     Unique DOM id
     * @param string $label  Button label
     * @param array  $items  List of strings OR arrays with keys you prefer (name/url/size/etc)
     */
    function wpsa_render_cap_dropdown( $id, $label, array $items ) {
        // normalize to strings; keep raw arrays too (JS can prettify if you want)
        $display_items = array_map( function( $it ) {
            if ( is_array( $it ) ) {
                // try some common keys; fall back to json
                $name = $it['name'] ?? $it['handle'] ?? $it['file'] ?? '';
                $meta = array_filter([
                    $it['type']   ?? null,
                    $it['size']   ?? null,
                    $it['source'] ?? $it['url'] ?? null,
                ]);
                $line = trim( $name ) !== '' ? $name : '';
                if ( $meta ) { $line .= $line ? ' — ' . implode(' · ', $meta) : implode(' · ', $meta); }
                return $line !== '' ? $line : wp_json_encode( $it );
            }
            return (string) $it;
        }, $items );
    
        printf(
            '<div class="wpsa-dd" id="%1$s" data-items="%2$s">
                <button type="button" class="button wpsa-dd-toggle" aria-expanded="false" aria-controls="%1$s-menu">%3$s</button>
                <div class="wpsa-dd-menu" id="%1$s-menu" aria-hidden="true">
                    <div class="wpsa-dd-controls">
                        <button type="button" class="button button-secondary wpsa-dd-copy" data-target="%1$s">
                            <span class="dashicons dashicons-clipboard"></span> Copy
                        </button>
                        <input type="range" class="wpsa-dd-slider" min="0" value="0" step="10">
                        <span class="wpsa-dd-window" aria-live="polite"></span>
                    </div>
                    <ul class="wpsa-dd-list"></ul>
                </div>
            </div>',
            esc_attr( $id ),
            esc_attr( wp_json_encode( array_values( array_filter( $display_items ) ) ) ),
            esc_html( $label )
        );
    }


/**
 * Retrieve and reset (if new day) the daily usage record.
 *
 * @return array{date:string,count:int}
 */
function wpsa_get_daily_usage_record() {
    $key = 'wpsa_daily_usage';
    $data = get_option( $key, array(
        'date'  => current_time( 'Y_m_d' ),
        'count' => 0,
    ) );
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
    $snap = wpsa_check_quota( 'ttfb' );
    if ( is_array( $snap ) && isset( $snap['limit'] ) ) {
        return (int) $snap['limit'];
    }
    return 10;
}

/**
 * Maximum PDF reports per plan, per day.
 *
 * @return int
 */
function wpsa_get_pdf_limit() {
    $snap = wpsa_check_quota( 'pdf' );
    if ( is_array( $snap ) && isset( $snap['limit'] ) ) {
        return (int) $snap['limit'];
    }
    return 1;
}

/**
 * Retrieve today’s PDF‐usage count, resetting at midnight GMT.
 *
 * @return int
 */
function wpsa_get_pdf_usage() {
    $usage = get_option( 'wpsa_pdf_usage', array( 'date' => '', 'count' => 0 ) );
    $today = gmdate( 'Y-m-d' );
    if ( $usage['date'] !== $today ) {
        $usage = array( 'date' => $today, 'count' => 0 );
        update_option( 'wpsa_pdf_usage', $usage );
    }
    return intval( $usage['count'] );
}
/**
 * Increment today’s PDF‐usage counter.
 */
function wpsa_increment_pdf_usage() {
    $usage = get_option( 'wpsa_pdf_usage', array(
        'date'  => gmdate( 'Y-m-d' ),
        'count' => 0,
    ) );
    $today = gmdate( 'Y-m-d' );
    if ( $usage['date'] !== $today ) {
        $usage = array(
            'date'  => $today,
            'count' => 0,
        );
    }
    $usage['count']++;
    update_option( 'wpsa_pdf_usage', $usage );
}

/**
 * How many PDF reports remain for today?
 *
 * @return int
 */
function wpsa_get_pdf_remaining() {
    $snap = wpsa_check_quota( 'pdf' );
    if ( is_array( $snap ) && isset( $snap['remaining'] ) ) {
        return max( 0, (int) $snap['remaining'] );
    }

    $limit = wpsa_get_pdf_limit();
    $used  = (int) wpsa_get_pdf_usage();
    return max( 0, $limit - $used );
}

/**
 * Increment the daily usage counter.
 */
function wpsa_increment_daily_usage() {
    // allow an explicit “disable limit” filter (e.g. for QA)
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
    // ask Gatekeeper for your tier/limits
    $quota = wpsa_check_quota( 'ttfb' );
    if ( is_wp_error( $quota ) ) {
        echo '<div class="notice notice-error"><p><strong>Error:</strong> Quota check failed. Please retry.</p></div>';
        return false;
    }
    $is_unlocked = ( 'premium3' === $quota['tier'] );
    if ( ! $is_unlocked && ! wpsa_is_same_host( $tested_url ) ) {
        echo '<div class="notice notice-error"><p><strong>Error:</strong> Your plan only allows testing on-site URLs.<br>Upgrade to the Agency plan to test any URL.</p></div>';
        return false;
    }

    // Clear and start debug log
    file_put_contents( $debug_log, '' );
    file_put_contents( $debug_log, "== Starting test for {$tested_url} ==\n", FILE_APPEND );

    // Pre-flight URL health check: skip when generating PDF
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $post_action = sanitize_text_field( wp_unslash( $_POST['action'] ?? '' ) );
    if ( ! ( defined( 'DOING_AJAX' ) && $post_action === 'wpsa_pdf_report' ) ) {
        $head = wp_remote_head( $tested_url, array( 'timeout' => 10 ) );
        $hc   = is_wp_error( $head ) ? 0 : wp_remote_retrieve_response_code( $head );
        if ( $hc === 404 ) {
            echo '<div class="notice notice-error"><p><strong>404 Error:</strong> Tested URL returned 404. Please check URL.</p></div>';
            return false;
        }
        if ( is_wp_error( $head ) || ( $hc >= 500 && $hc < 600 ) ) {
            echo '<div class="notice notice-error"><p><strong>Error:</strong> Test URL returned HTTP '
                 . esc_html( $hc ) . '. Aborting tests.</p></div>';
            return false;
        }
    }

    // Determine test index & timestamp.
    $header_count   = substr_count( @file_get_contents( $results_log ), 'Test #' );
    $idx            = $header_count + 1;           // next index if we log a header
    $ts             = gmdate( 'Y-m-d H:i:s' );
    $now            = time();
    $last_header_ts = (int) get_option( 'wpsa_last_header_ts', 0 );

    // By default, allow only 1 new header per minute (protects against double-fires in the UI).
    $can_log_header = ( $now - $last_header_ts ) >= 60;

    // But for scheduled batches we want *every* URL to be its own test,
    // even if they run back-to-back in the same minute.
    if ( defined( 'WPSA_SCHEDULED_BATCH' ) && WPSA_SCHEDULED_BATCH ) {
        $can_log_header = true;
    }


    // Resolve redirects
    $resolved = wpsa_resolve_final_url( $tested_url );

    // parse and compare original vs resolved for display
    $orig  = wp_parse_url( $tested_url );
    $final = wp_parse_url( $resolved );
    $orig_path  = isset( $orig['path'] )  && rtrim( $orig['path'], '/' )  !== '' ? rtrim( $orig['path'], '/' )  : '';
    $final_path = isset( $final['path'] ) && rtrim( $final['path'], '/' ) !== '' ? rtrim( $final['path'], '/' ) : '';
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
    // use resolved for all
    $tested_url = $resolved;

    // inline JS to update UI
    $js_display = sprintf(
        'jQuery(function($){ $("#wpsa-tested-url").html(%s); });',
        wp_json_encode( $display )
    );
    wp_add_inline_script( 'wpsa-admin-scripts', $js_display );

        // update hidden input for AJAX
    $js_input = sprintf(
        'jQuery(function($){ $("input[name=\'test_url\']").val(%s); });',
        wp_json_encode( $tested_url )
    );
    wp_add_inline_script( 'wpsa-admin-scripts', $js_input );

    // ────────────────────────────────────────────────────────────────
    // If we’re generating the PDF report, reuse the last Worker data
    if ( defined( 'DOING_AJAX' )
         && filter_input( INPUT_POST, 'action', FILTER_SANITIZE_STRING ) === 'wpsa_pdf_report'
    ) {
        $worker_data = get_transient( 'wpsa_last_worker_data' );
        if ( ! is_array( $worker_data ) ) {
            return false; // no cached data yet
        }
    }
    // ────────────────────────────────────────────────────────────────
    else {
        // prepare fallback URLs
        $raw  = preg_replace( '#^https?://#i', '', trim( $tested_url ) );
        $urls = array( 'https://' . $raw, 'http://' . $raw );

        $worker_data = null;
        foreach ( $urls as $url ) {
            $resp = wp_remote_get(
                add_query_arg( array( 'url' => $url, 'rand' => wp_rand() ), wpsa_get_worker_endpoint() ),
                array( 'timeout' => 30 )
            );
            if ( is_wp_error( $resp ) ) {
                return false;
            }
            $code = wp_remote_retrieve_response_code( $resp );
            $body = wp_remote_retrieve_body( $resp );

            // log attempt
            file_put_contents(
                $debug_log,
                sprintf( "[%s] HTTP Code: %d for %s\nResponse: %s\n", gmdate('c'), $code, $url, $body ),
                FILE_APPEND
            );

            if ( 200 !== $code ) {
                return false;
            }
            $json = json_decode( $body, true );
            if ( ! is_array( $json ) || ! isset( $json['ttfb_ms'], $json['location'] ) ) {
                return false;
            }

            // cache payload for PDF
            set_transient( 'wpsa_last_worker_data', $json, HOUR_IN_SECONDS );
            $worker_data = $json;

            // capture final URL
            $final   = $worker_data['finalUrl'] ?? $url;
            $display = ( $final !== $url )
                ? sprintf( 'Tested URL redirected to: %1$s', esc_html( $final ) )
                : sprintf( 'Tested URL: %1$s', esc_html( $url ) );
            $tested_url = $final;

            break;
        }
        if ( ! $worker_data ) {
            return false;
        }
    }
    
    // Response headers for UI/logs: prefer WordPress-server HEAD headers (matches browser/KeyCDN).
    $resp_headers = wpsa_get_origin_response_headers( $tested_url );
    
    // Fallback: if WP HEAD failed, use Worker-captured headers.
    if ( empty( $resp_headers ) && isset( $worker_data['response_headers'] ) && is_array( $worker_data['response_headers'] ) ) {
        $resp_headers = $worker_data['response_headers'];
    }
    
    // Final safety filter (in case fallback headers contain sensitive keys)
    if ( ! empty( $resp_headers ) ) {
        foreach ( array_keys( $resp_headers ) as $hk ) {
            $lk = strtolower( (string) $hk );
            if ( in_array( $lk, array( 'set-cookie', 'cookie', 'authorization', 'proxy-authorization' ), true ) ) {
                unset( $resp_headers[ $hk ] );
            }
        }
    }
    
    $resp_headers_json = ! empty( $resp_headers ) ? wp_json_encode( $resp_headers ) : '';

        if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            printf(
                '<div id="wpsa-run-meta" data-test-no="%1$d" data-tested-url="%2$s" data-response-headers="%3$s" style="display:none"></div>',
                (int) ( $can_log_header ? $idx : 0 ),
                esc_url( $tested_url ),
                esc_attr( $resp_headers_json )
            );
        }

   

    // Region & TTFB
    // Prefer Worker-provided edge metadata; fall back to static colo map; finally raw colo code.
    $loc  = strtoupper( (string) ( $worker_data['location'] ?? '' ) );
    $edge = ( isset( $worker_data['edge'] ) && is_array( $worker_data['edge'] ) ) ? $worker_data['edge'] : array();

    $edge_city      = isset( $edge['city'] )       ? sanitize_text_field( $edge['city'] )       : '';
    $edge_region    = isset( $edge['regionCode'] ) && $edge['regionCode'] !== ''
                        ? sanitize_text_field( $edge['regionCode'] )
                        : ( isset( $edge['region'] ) ? sanitize_text_field( $edge['region'] ) : '' );
    $edge_country   = isset( $edge['country'] )    ? sanitize_text_field( strtoupper( $edge['country'] ) ) : '';
    $edge_continent = isset( $edge['continent'] )  ? sanitize_text_field( $edge['continent'] )  : '';

    // Tiny normalization so common cases look nice.
    if ( 'US' === $edge_country ) { $edge_country = 'USA'; }
    if ( 'GB' === $edge_country ) { $edge_country = 'UK';  }

    $region = '';
    if ( $edge_city || $edge_region || $edge_country ) {
        // Format: City–Region/COUNTRY (or City/COUNTRY if region not present).
        $label_city = ( $edge_city !== '' ) ? $edge_city : $loc;
        $first_part = $label_city . ( $edge_region ? '–' . $edge_region : '' );
        $second     = $edge_country ? $edge_country : ( $edge_continent ?: '' );
        $region     = $second ? ( $first_part . '/' . $second ) : $first_part;
    }

    // Fallback to existing static map when Worker edge data is missing.
    if ( '' === $region ) {
        $map = array(
            'FRA' => 'Frankfurt/Europe','LHR' => 'London/Europe','AMS' => 'Amsterdam/Europe',
            'IAD' => 'Virginia/USA','JFK' => 'New York/USA','ORD' => 'Chicago/USA',
            'SIN' => 'Singapore/Asia','NRT' => 'Tokyo/Asia','HKG' => 'Hong Kong/Asia',
            'SYD' => 'Sydney/Australia','MEL' => 'Melbourne/Australia','BNE' => 'Brisbane/Australia',
            'SOF' => 'Sofia/Europe','EWR' => 'Newark/USA'
        );
        $continent_map = array_map( function( $r ) {
            $p = explode( '/', $r );
            return end( $p );
        }, $map );
        $region = isset( $map[ $loc ] )
            ? $map[ $loc ]
            : ( isset( $continent_map[ $loc ] )
                ? "{$loc}/{$continent_map[$loc]}"
                : $loc
              );
    }

    $ttfb = intval( $worker_data['ttfb_ms'] );
    $age_raw     = $worker_data['age'] ?? '';
    $cfcs_raw    = strtoupper( $worker_data['cfCacheStatus'] ?? '' );
    $xcache_raw  = strtoupper( $worker_data['xCache'] ?? '' );
    $xcs_raw     = strtoupper( $worker_data['xCacheStatus'] ?? '' );

    // --- Compute cache status -------------------------------------------------
    $age_raw    = (string) ( $resp_headers['age'] ?? ( $worker_data['age'] ?? '' ) );
    $cfcs_raw   = strtoupper( (string) ( $resp_headers['cf-cache-status'] ?? ( $worker_data['cfCacheStatus'] ?? '' ) ) );
    $xcache_raw = strtoupper( (string) ( $resp_headers['x-cache'] ?? ( $worker_data['xCache'] ?? '' ) ) );
    $xcs_raw    = strtoupper( (string) ( $resp_headers['x-cache-status'] ?? ( $worker_data['xCacheStatus'] ?? '' ) ) );
    $sg_raw     = strtoupper( (string) ( $resp_headers['sg-f-cache'] ?? ( $worker_data['sgCache'] ?? '' ) ) );
    $xproxy_raw = strtoupper( (string) ( $resp_headers['x-proxy-cache'] ?? ( $worker_data['xProxyCache'] ?? '' ) ) );
    $cc_raw     = strtolower( (string) ( $resp_headers['cache-control'] ?? ( $worker_data['cacheControl'] ?? '' ) ) );
    $pragma_raw = strtolower( (string) ( $resp_headers['pragma'] ?? ( $worker_data['pragma'] ?? '' ) ) );
    
    $no_cache_directive = (
        ( $cc_raw    !== '' && ( strpos($cc_raw,'no-store') !== false || strpos($cc_raw,'no-cache') !== false ) ) ||
        ( $pragma_raw !== '' && strpos($pragma_raw,'no-cache') !== false )
    );
    
    // Any cache-ish header seen at all?
    $has_any_cache_hdr = (
        $age_raw !== '' || $cfcs_raw !== '' || $xcache_raw !== '' || $xcs_raw !== '' ||
        $sg_raw !== ''  || $xproxy_raw !== '' || $cc_raw !== '' || $pragma_raw !== ''
    );
    
    // 1) HIT if: Age>0 OR any vendor header contains HIT/REVALIDATED/STALE
    if (
        ( $age_raw !== '' && is_numeric($age_raw) && intval($age_raw) > 0 ) ||
        in_array( $cfcs_raw, [ 'HIT', 'REVALIDATED', 'STALE' ], true ) ||
        ( $xcache_raw  !== '' && strpos($xcache_raw,  'HIT') !== false ) ||
        ( $xcs_raw     !== '' && strpos($xcs_raw,     'HIT') !== false ) ||
        ( $sg_raw      === 'HIT' ) ||
        ( $xproxy_raw  === 'HIT' )
    ) {
        $cache_status = 'HIT';
    
    // 2) BYPASS/DYNAMIC if a cache layer is present but explicitly bypassed
    } elseif (
        in_array( $cfcs_raw, [ 'DYNAMIC', 'BYPASS' ], true ) ||
        in_array( $sg_raw,   [ 'BYPASS' ], true ) ||
        in_array( $xproxy_raw, [ 'BYPASS' ], true )
    ) {
        // Keep the user-visible label “Bypass” for anything explicitly bypassed;
        // only show “Dynamic” when Cloudflare literally says DYNAMIC.
        $cache_status = ( $cfcs_raw === 'DYNAMIC' ) ? 'Dynamic' : 'Bypass';
    
    // 3) NO CACHE if there’s no evidence of a caching layer, or explicit no-cache directives
    } elseif ( ! $has_any_cache_hdr || $no_cache_directive ) {
        $cache_status = 'No cache';
    
    // 4) Otherwise: layer exists but this response was not cached
    } else {
        $cache_status = 'MISS';
    }



      // WP Rocket override
    $has_rocket = wpsa_is_same_host( $tested_url ) && defined( 'WP_ROCKET_VERSION' );
    if ( 'MISS' === $cache_status && $has_rocket ) {
        $cache_status = 'Handled by WP Rocket';
    }

    // Logging line (guard: only one header per minute)
    if ( $can_log_header ) {
        $used_before  = intval( wpsa_get_daily_usage_record()['count'] );
        $daily_count  = $used_before + 1;
        $daily_limit  = wpsa_get_daily_limit();
        $tier_abbr    = array(
            'free'     => 'FR',
            'premium1' => 'PR1',
            'premium2' => 'PR2',
            'premium3' => 'PR3',
        )[ wpsa_get_license_tier() ] ?? 'FR';

        // Optional " S" marker for scheduled-batch runs.
        $sf_suffix = ( defined( 'WPSA_SCHEDULED_BATCH' ) && WPSA_SCHEDULED_BATCH ) ? ' S' : '';

        // If the log already has content, prepend a single extra newline
        // so that there is always a blank row between test blocks.
        $prepend = '';
        if ( file_exists( $results_log ) && filesize( $results_log ) > 0 ) {
            $prepend = "\n";
        }

        file_put_contents(
            $results_log,
            $prepend . sprintf(
                'Test #%d%s |%s| %s | Tier: %s | URL: %s | Region: %s | Cache: %s | WP Rocket: %s | TTFB: %d ms | Daily: %d/%d',
                $idx,
                $sf_suffix,
                SAWP_VERSION,
                gmdate( 'c' ),
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
        
        if ( $resp_headers_json !== '' ) {
            file_put_contents(
                $results_log,
                'Module 1 Response headers: ' . $resp_headers_json . "\n",
                FILE_APPEND
            );
        }


        // remember last write moment (UTC)
        update_option( 'wpsa_last_header_ts', $now );
    } else {
        // optional: note in debug log that the header was skipped by the guard
        file_put_contents( $debug_log, sprintf( "[%s] Header skipped by 60s guard\n", gmdate( 'c' ) ), FILE_APPEND );
    }
    // Render UI
    wpsa_display_module1( $region, $cache_status, $ttfb );

    // Hide "Running test…" indicator
    wp_add_inline_script( 'wpsa-admin-scripts',
        'jQuery(function($){ $("#running-test").hide(); });'
    );

    return true;
}

/**
 * Renders Module 1 markup.
 *
 * @param string $region
 * @param string $cache_status
 * @param int    $ttfb
 */
function wpsa_display_module1( $region, $cache_status, $ttfb ) {
    // Determine color, emoji, label
    $col = '#ffdddd'; $emo = '🔴'; $lab = 'Slow';
    if ( $ttfb <= 300 )     { $col = '#c6f7c3'; $emo = '✅'; $lab = 'Fast'; }
    elseif ( $ttfb <= 500 ) { $col = '#fef6a3'; $emo = '🟡'; $lab = 'Acceptable'; }
    elseif ( $ttfb <= 700 ) { $col = '#ffe6a7'; $emo = '🟠'; $lab = 'Moderate'; }
    
    $show_warmup_note = ($ttfb > 300); // show on yellow/red only

    // Tooltips
    $base_tooltip  = 'Cache status based on HTTP headers.';
    if ( 'HIT' === $cache_status ) {
        $cache_tooltip = $base_tooltip . "\nCache: HIT delivers content faster, reduces origin load.";
    } elseif ( in_array( $cache_status, [ 'Dynamic', 'Bypass' ], true ) ) {
        $cache_tooltip = $base_tooltip . "\nCache: Dynamic indicates a cache was detected but bypassed.";
    } elseif ( 'Handled by WP Rocket' === $cache_status ) {
        $cache_tooltip = 'Page served from WP Rocket’s cache plugin layer.';
    } else { // MISS
        $cache_tooltip = $base_tooltip
            . "\nCache: MISS requires full origin round-trip; try warming up the cache.";
    }
    $ttfb_tooltip = 'Time to First Byte measures the time from request to first byte received.' . "&#10;Under 300 ms is fast.";


    // --- normalize cache status (avoid silent fallback to "Dynamic") ---
    $cache_status = trim((string) $cache_status);
    if ($cache_status === '' || strcasecmp($cache_status, 'unknown') === 0) {
        $cache_status = 'No cache';
    }

    // Cache icon
    // Cache icon  (and implicitly the meaning)
    // 🟢 = cached; 🟡 = cache layer present but bypassed; 🔴 = not cached
    if ( $cache_status === 'HIT' || $cache_status === 'Handled by WP Rocket' ) {
        $cache_icon = '🟢';
    } elseif ( in_array( $cache_status, [ 'Dynamic', 'Bypass' ], true ) ) {
        $cache_icon = '🟡';
    } else { // MISS or No cache or anything unknown
        $cache_icon = '🔴';
    }


    echo '<div class="pdf-section" id="pdf-module-1" style="page-break-inside: avoid;">'
       . '<div class="wpsa-module-1">'
       . '<h2 class="wpsa-module-title wpsa-module-1-title">1. Server performance test</h2>'
       . "<table class='widefat wpsa-module1-table'>"
       . '<thead><tr>'
         . '<th>🌍 Server Location <span class="custom-tooltip" data-tooltip="This refers to the Cloudflare edge test server location. The one that handled the test request, and NOT your server location. However, this is usually the closest location to your actual web host server">?</span></th>'
         . '<th>Cache Status <span class="custom-tooltip" data-tooltip="' . esc_attr( $cache_tooltip ) . '">?</span></th>'
         . '<th>TTFB <span class="custom-tooltip" data-tooltip="' . esc_attr( $ttfb_tooltip ) . '">?</span></th>'
       . '</tr></thead>'
       . "<tbody><tr style='background:". esc_attr($col) ."'>"
         . '<td>' . esc_html( $region ) . '</td>'
         . '<td>' . esc_html( $cache_status ) . ( $cache_icon ? ' ' . esc_html( $cache_icon ) : '' ) . '</td>'
         . '<td>' . esc_html( $ttfb ) . ' ms ' . esc_html( $emo ) . '</td>'
       . '</tr></tbody>'
       . '</table>'
       . '<div style="background:'. esc_attr($col).';padding:16px;border:1px solid #ccc;border-top:none;border-radius:0 0 15px 15px;">'
         . '<p>This test confirms that your server TTFB is <strong>' . esc_html( $lab ) . ' ' . esc_html( $emo ) . '</strong>.</p>'
        . ( $show_warmup_note ? '<p class="wpsa-footnote">*Run the test twice to warm up cache, if needed.</p>' : '' )

        . '<details class="wpsa-inline-details is-headers" id="wpsa-m1-respheaders-wrap" style="display:none;">'
          . '<summary>Show response headers</summary>'
          . '<div>'
            . '<ul id="wpsa-m1-respheaders"></ul>'
            . '<div class="wpsa-inline-copy">'
              . '<button type="button" class="button button-secondary wpsa-copy-btn" data-target="#wpsa-m1-respheaders">'
                . '<span class="dashicons dashicons-clipboard"></span> Copy'
              . '</button>'
            . '</div>'
          . '</div>'
        . '</details>'

       . '</div>'
       . '</div>'
       . '</div>';
    }

    /**
     * How many tests you have *left* today.
     *
     * @return int
     */
function wpsa_get_daily_remaining() {
    $snap = wpsa_check_quota( 'ttfb' );
    if ( is_array( $snap ) && isset( $snap['remaining'] ) ) {
        return max( 0, (int) $snap['remaining'] );
    }

    $limit = wpsa_get_daily_limit();
    $used  = (int) wpsa_get_daily_usage_record()['count'];
    return max( 0, $limit - $used );
}
    
    /**
     * Local quota snapshot based on saved tier + expiration (grace period).
     *
     * Used when Gatekeeper is unreachable OR when we intentionally keep grace
     * benefits active (e.g. subscription lapsed but local grace not ended).
     *
     * @param string $operation Either 'ttfb' or 'pdf'.
     * @return array {
     *     @type bool   $allowed
     *     @type string $tier
     *     @type int    $limit
     *     @type int    $remaining
     * }
     */
    function wpsa_get_local_quota_snapshot( $operation ) {
        $tier = wpsa_get_license_tier(); // respects saved tier + expiration

        $limit_maps = array(
            'ttfb' => array( 'free' => 10, 'premium1' => 30, 'premium2' => 100, 'premium3' => 700 ),
            'pdf'  => array( 'free' => 1,  'premium1' => 3,  'premium2' => 10,  'premium3' => 100 ),
        );

        $map   = isset( $limit_maps[ $operation ] ) ? $limit_maps[ $operation ] : $limit_maps['ttfb'];
        $limit = isset( $map[ $tier ] ) ? (int) $map[ $tier ] : (int) $map['free'];

        if ( 'pdf' === $operation ) {
            $used = (int) wpsa_get_pdf_usage();
        } else {
            $used = (int) wpsa_get_daily_usage_record()['count'];
        }

        $remaining = max( 0, $limit - $used );

        return array(
            'allowed'   => ( $remaining > 0 ),
            'tier'      => $tier,
            'limit'     => $limit,
            'remaining' => $remaining,
        );
    }
    
    function wpsa_get_conservative_quota_snapshot( $operation ) {
    $tier = 'free';

    $limits = array(
        'ttfb' => 10,
        'pdf'  => 1,
    );

    $limit = isset( $limits[ $operation ] ) ? (int) $limits[ $operation ] : (int) $limits['ttfb'];

    $used = ( 'pdf' === $operation )
        ? (int) wpsa_get_pdf_usage()
        : (int) wpsa_get_daily_usage_record()['count'];

    $remaining = max( 0, $limit - $used );

    return array(
        'allowed'   => ( $remaining > 0 ),
        'tier'      => $tier,
        'limit'     => $limit,
        'remaining' => $remaining,
    );
}


 
function wpsa_check_quota( $operation ) {
    $license_key = sanitize_text_field( (string) get_option( 'wpsa_license_key', '' ) );
    $daily_used  = ( 'pdf' === $operation )
        ? (int) wpsa_get_pdf_usage()
        : (int) wpsa_get_daily_usage_record()['count'];

    // No key (user deactivated) → allow local grace behavior.
    if ( '' === $license_key ) {
        return wpsa_get_local_quota_snapshot( $operation );
    }

    $cache_key = 'wpsa_gk_quota_' . sanitize_key( (string) $operation );

    // We have a key → ask Gatekeeper.
    $url = esc_url_raw( add_query_arg( array(
        'license_key' => $license_key,
        'operation'   => $operation,
        'daily_used'  => $daily_used,
    ), WPSA_GATEKEEPER_URL . '/check' ) );

    $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

    // If Gatekeeper fails and we DO have a key, never grant premium via local fallback.
    // Use cached last-good Gatekeeper response, otherwise conservative "free".
    if ( is_wp_error( $response ) ) {
    $cached = get_transient( $cache_key );
    if (
        is_array( $cached )
        && isset( $cached['allowed'], $cached['tier'], $cached['limit'], $cached['remaining'] )
        && (string) $cached['tier'] === 'free'
    ) {
        return $cached;
    }
    return wpsa_get_conservative_quota_snapshot( $operation );
}

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( 200 !== $code ) {
    $cached = get_transient( $cache_key );
    if (
        is_array( $cached )
        && isset( $cached['allowed'], $cached['tier'], $cached['limit'], $cached['remaining'] )
        && (string) $cached['tier'] === 'free'
    ) {
        return $cached;
    }
    return wpsa_get_conservative_quota_snapshot( $operation );
}

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $body )
        || ! isset( $body['allowed'], $body['tier'], $body['limit'], $body['remaining'] )
    ) {
        $cached = get_transient( $cache_key );
        if (
            is_array( $cached )
            && isset( $cached['allowed'], $cached['tier'], $cached['limit'], $cached['remaining'] )
            && (string) $cached['tier'] === 'free'
        ) {
            return $cached;
        }
        return wpsa_get_conservative_quota_snapshot( $operation );
    }

    $out = array(
        'allowed'   => (bool) $body['allowed'],
        'tier'      => (string) $body['tier'],
        'limit'     => (int) $body['limit'],
        'remaining' => (int) $body['remaining'],
    );

    // Cache last-known-good Gatekeeper result briefly to prevent UI flip-flops.
    set_transient( $cache_key, $out, 15 * MINUTE_IN_SECONDS );

    // Keep local tier in sync with Gatekeeper so UI matches reality.
    if ( isset( $out['tier'] ) && is_string( $out['tier'] ) ) {
        $incoming = (string) $out['tier'];
        $current  = (string) get_option( 'wpsa_saved_tier', 'free' );

    if ( $incoming !== $current ) {
    
        // If Gatekeeper confirms a premium tier, remember it as the last known paid tier.
        // This allows the License panel to show “expired/renew” later, even after saved tier is synced to free.
        if ( $incoming !== 'free' ) {
            update_option( 'wpsa_last_paid_tier', $incoming, false );
            delete_option( 'wpsa_last_paid_downgrade_ts' );
        } elseif ( $current !== 'free' ) {
            // Downgrading from paid -> free: remember what it used to be.
            update_option( 'wpsa_last_paid_tier', $current, false );
            update_option( 'wpsa_last_paid_downgrade_ts', time(), false );
        }
    
        update_option( 'wpsa_saved_tier', $incoming, false );
    }
    
    // If Gatekeeper says "free", drop local expiration so local grace doesn't resurrect premium.
    if ( $incoming === 'free' ) {
        delete_option( 'wpsa_license_expiration' );
    }
    }

    return $out;
}


    
    /**
     * Get the saved expiration date (one-month), or “N/A” for free.
     *
     * @return string
     */
    function wpsa_get_license_expiration_date() {
        $tier = wpsa_get_license_tier();
        if ( 'free' === $tier ) {
            return 'N/A';
        }
        $exp = get_option( 'wpsa_license_expiration', '' );
        return $exp ? date_i18n( get_option( 'date_format' ), strtotime( $exp ) )
                    : 'N/A';
    }
    
/**
 * Get total slot limit for a given tier.
 *
 * @param string $tier
 * @return int
 */
function wpsa_get_license_slots_limit( $tier ) {
    $map = [
        'free'     => 0,
        'premium1' => 1,
        'premium2' => 2,
        'premium3' => 100,
    ];
    return isset( $map[ $tier ] ) ? $map[ $tier ] : 0;
}

/**
 * Get remaining activation slots for a given tier.
 *
 * This calls Gatekeeper’s `/slots` endpoint, or defaults to the full limit.
 *
 * @param string $tier
 * @return int
 */
function wpsa_get_license_slots_remaining( $tier ) {
    $limit = wpsa_get_license_slots_limit( $tier );

    // Attempt to fetch used count from Gatekeeper
    $key = get_option( 'wpsa_license_key', '' );
    if ( ! $key || $limit < 1 ) {
        return 0;
    }

    $url = esc_url_raw( add_query_arg( [
        'license_key' => $key,
    ], WPSA_GATEKEEPER_URL . '/slots' ) );
    $resp = wp_remote_get( $url, [ 'timeout' => 10 ] );
    if ( is_wp_error( $resp ) ) {
        return $limit;
    }
    $code = wp_remote_retrieve_response_code( $resp );
    $data = json_decode( wp_remote_retrieve_body( $resp ), true );
    if ( 200 !== $code || ! isset( $data['used'], $data['limit'] ) ) {
        return $limit;
    }

    return max( 0, intval( $data['limit'] ) - intval( $data['used'] ) );
}

/**
 * Get total database size (data + indexes) for the current site, as a human string.
 *
 * Returns values like "123.4 MB" or "N/A" on error.
 *
 * IMPORTANT:
 * - Call this only for same-site tests (e.g. when wpsa_is_same_host() is true),
 *   so you don’t leak any local DB details when testing external URLs.
 *
 * @return string
 */
function wpsa_get_db_size_human() {
    global $wpdb;

    if ( ! ( $wpdb instanceof wpdb ) ) {
        return 'N/A';
    }

    // Single aggregate query against information_schema for the current DB only.
    // The SQL is a fixed literal with no interpolation and no user input, so there is
    // nothing to prepare. It is inlined into the call because the static sniff cannot
    // follow a query held in a variable and reports NotPrepared on the indirection.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed literal aggregate over information_schema, no user input; single scalar read on an admin screen, not worth a cache entry.
    $size_mb = $wpdb->get_var( "SELECT ROUND( SUM( data_length + index_length ) / 1024 / 1024, 1 ) FROM information_schema.TABLES WHERE table_schema = DATABASE()" );

    if ( ! is_numeric( $size_mb ) ) {
        return 'N/A';
    }

    return $size_mb . ' MB';
}

// Compare tiers by rank (free=0, premium1=1, premium2=2, premium3=3)
if ( ! function_exists( 'wpsa_tier_rank' ) ) {
    function wpsa_tier_rank( $tier ) {
        switch ( $tier ) {
            case 'premium3': return 3;
            case 'premium2': return 2;
            case 'premium1': return 1;
            default:         return 0; // free/unknown
        }
    }
}


