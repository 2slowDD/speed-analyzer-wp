<?php
/**
 * Modules.php 2–4 for Speed Analyzer 
 * 
 * @package Speed Analyzer - modules.php 
 * @version v0.729
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

    // AJAX handler for Module 2 (PageSpeed Insights) with exponential back-off and 524 error handling
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
        $strategies    = [ 'mobile', 'desktop' ];
        $max_retries   = 3;
        $delay_seconds = 1;
        $results       = [];
        $m2_logs       = []; // collect Module 2 log lines (mobile/desktop)
    
        foreach ( $strategies as $strat ) {
            $endpoint = add_query_arg(
            [
                // Fetch PSI for the tested URL…
                'psi_url'  => rawurlencode( $url ),
                'strategy' => $strat,
                // …and also fetch the HTML once so the Worker can count ONLOAD JS/CSS
                'url'      => rawurlencode( $url ),
            ],
            wpsa_get_worker_endpoint() . 'psi'
        );


        $attempt = 0;
        $delay   = $delay_seconds;
        do {
            if ( $attempt > 0 ) {
                sleep( $delay );
                $delay *= 2;
            }
            // Wait long enough to match the Worker’s 60s PSI window (+5s headroom)
            $response = wp_remote_get( $endpoint, [ 'timeout' => 65 ] );
            $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
            $attempt++;
        } while ( ( is_wp_error( $response ) || $code === 429 ) && $attempt < $max_retries );

        if ( is_wp_error( $response ) || 200 !== $code ) {
            $error_msg = is_wp_error( $response )
                       ? $response->get_error_message()
                       : 'HTTP ' . $code;
            status_header( 524 );
            wp_send_json_error( $error_msg );
        }

        $body  = wp_remote_retrieve_body( $response );
        $data  = json_decode( $body, true );
        $items = $data['lighthouseResult']['audits']['network-requests']['details']['items'] ?? null;
        $req   = is_array( $items ) ? count( $items ) : null;

        $bytes_raw = $data['lighthouseResult']['audits']['total-byte-weight']['numericValue'] ?? null;
        $bytes     = is_numeric( $bytes_raw ) ? (float) $bytes_raw : null;

        // Onload counts from Worker dual-mode payload
        $on_js  = $data['assets']['onload']['js_count']  ?? null;
        $on_css = $data['assets']['onload']['css_count'] ?? null;
        $on_js  = is_numeric( $on_js )  ? (int) $on_js  : null;
        $on_css = is_numeric( $on_css ) ? (int) $on_css : null;
        
        // 1.16.2 dropdowns: capture lists for dropdowns if the Worker provided them
        $on_js_list  = [];
        $on_css_list = [];
        
        foreach ( [ 'js_list', 'js', 'jsFiles', 'js_files' ] as $k ) {
            if ( isset( $data['assets']['onload'][ $k ] ) && is_array( $data['assets']['onload'][ $k ] ) ) {
                $on_js_list = array_values( array_map( 'strval', $data['assets']['onload'][ $k ] ) );
                break;
            }
        }
        foreach ( [ 'css_list', 'css', 'cssFiles', 'css_files' ] as $k ) {
            if ( isset( $data['assets']['onload'][ $k ] ) && is_array( $data['assets']['onload'][ $k ] ) ) {
                $on_css_list = array_values( array_map( 'strval', $data['assets']['onload'][ $k ] ) );
                break;
            }
        }
        
        // Fallback: if the Worker didn't provide onload file lists,
            // derive them from PSI's network-requests payload.
            if ( empty( $on_js_list ) || empty( $on_css_list ) ) {
                $nr_js  = [];
                $nr_css = [];
                if ( is_array( $items ) ) {
                    foreach ( $items as $row ) {
                        $u    = isset( $row['url'] ) ? (string) $row['url'] : '';
                        if ( $u === '' || preg_match( '#^(data:|blob:|about:|chrome-)#i', $u ) ) { continue; }
            
                        $mime = isset( $row['mimeType'] )     ? (string) $row['mimeType']     : '';
                        $type = isset( $row['resourceType'] ) ? (string) $row['resourceType'] : '';
            
                        $is_js  = ( stripos( $mime, 'javascript' ) !== false )
                               || ( stripos( $type, 'Script' ) !== false )
                               || preg_match( '/\.m?js(\?.*)?$/i', $u );
            
                        $is_css = ( stripos( $mime, 'css' ) !== false )
                               || ( stripos( $type, 'Stylesheet' ) !== false )
                               || preg_match( '/\.css(\?.*)?$/i', $u );
            
                        if ( $is_js )  { $nr_js[]  = $u; }
                        if ( $is_css ) { $nr_css[] = $u; }
                    }
                }
                if ( empty( $on_js_list )  && ! empty( $nr_js ) )  { $on_js_list  = array_values( array_unique( $nr_js ) ); }
                if ( empty( $on_css_list ) && ! empty( $nr_css ) ) { $on_css_list = array_values( array_unique( $nr_css ) ); }
            }

        
     // Fully-loaded counts from PSI network-requests (Worker summarizes these too)
        $ld_js  = $data['assets']['loaded']['js_count']  ?? null;
        $ld_css = $data['assets']['loaded']['css_count'] ?? null;
        $ld_js  = is_numeric( $ld_js )  ? (int) $ld_js  : null;
        $ld_css = is_numeric( $ld_css ) ? (int) $ld_css : null;

        // ✔ keep lists + counts together (don’t overwrite)
        $results[ $strat ] = [
            'requests'    => $req,        // may be null
            'bytes'       => $bytes,      // may be null
            'on_js'       => $on_js,      // may be null
            'on_css'      => $on_css,     // may be null
            'ld_js'       => $ld_js,      // may be null
            'ld_css'      => $ld_css,     // may be null
            'on_js_list'  => $on_js_list, // arrays (may be empty)
            'on_css_list' => $on_css_list,
        ];
    }

   $out = [ 'mobile' => '', 'desktop' => '' ];
    foreach ( [ 'mobile', 'desktop' ] as $s ) {
        $req   = $results[ $s ]['requests']; // may be null
        $bytes = $results[ $s ]['bytes'];    // may be null
    
        // Requests: if null or 0 → N/A
        $req_val = ($req === null || (is_numeric($req) && (int)$req === 0))
            ? 'N/A'
            : (string) $req;
    
        // Page size: if null/0 → N/A; otherwise show KB (and MB when large)
        if ($bytes === null || !is_numeric($bytes) || (float)$bytes <= 0) {
            $size_val = 'N/A';
        } else {
            $kb = round($bytes / 1024, 1);
            $size_val = ($bytes >= 1048576)   // 1 MB
                ? sprintf('%s KB (%.1f MB)', $kb, round($bytes / 1048576, 1))
                : sprintf('%s KB', $kb);
        }
    
                // Normalize onload counts from per-strategy results
        $on_js_raw  = $results[ $s ]['on_js'];
        $on_css_raw = $results[ $s ]['on_css'];

        $on_js_val  = ( is_numeric( $on_js_raw )  && (int) $on_js_raw  >= 0 ) ? (string) (int) $on_js_raw  : 'N/A';
        $on_css_val = ( is_numeric( $on_css_raw ) && (int) $on_css_raw >= 0 ) ? (string) (int) $on_css_raw : 'N/A';

        
        // Tooltips (uniform styling via custom-tooltip class)
        $tt_req = 'Total network requests during the initial page load (HTML, CSS, JS, images, fonts, etc.).';
        $tt_js  = 'JavaScript files fetched during the initial page load. Deferred/delayed scripts are not included.';
        $tt_css = 'Stylesheets applied during the initial page load. Preload→onload swaps and print styles are not included.';
        
        $out[ $s ] = '<div class="wpsa-stat-cards photo">'

          // 1) Requests
          . '<div class="wpsa-stat-card wpsa-card-requests">'
          .   '<div class="header">Requests <span class="custom-tooltip" data-tooltip="' . esc_attr( $tt_req ) . '">?</span></div>'
          .   '<div class="value">' . esc_html( $req_val ) . '</div>'
          . '</div>'
        
          // 2) Page size
          . '<div class="wpsa-stat-card wpsa-card-bytes">'
          .   '<div class="header">Page size</div>'
          .   '<div class="value">' . esc_html( $size_val ) . '</div>'
          . '</div>'
        
          // 3) Onload JS (card)
          . '<div class="wpsa-stat-card wpsa-card-onload-js">'
          .   '<div class="header">Onload JS files <span class="custom-tooltip" data-tooltip="' . esc_attr( $tt_js ) . '">?</span></div>'
          .   '<div class="value">' . esc_html( $on_js_val ) . '</div>'
          . '</div>'
        
          // 4) Onload CSS (card)
          . '<div class="wpsa-stat-card wpsa-card-onload-css">'
          .   '<div class="header">Onload CSS files <span class="custom-tooltip" data-tooltip="' . esc_attr( $tt_css ) . '">?</span></div>'
          .   '<div class="value">' . esc_html( $on_css_val ) . '</div>'
          . '</div>'
        
          // ▼ Onload JS list (placed under column 3)
          . ( ! empty( $results[ $s ]['on_js_list'] )
              ? (function() use ($results, $s) {
                  $lis = '';
                  foreach ( (array) $results[ $s ]['on_js_list'] as $it ) {
                    $lis .= '<li>' . esc_html( (string) $it ) . '</li>';
                  }
                  return '<details class="wpsa-inline-details is-js">'
                       .   '<summary>Show onload JS files</summary>'
                       .   '<div>'
                       .     '<ul id="wpsa-onjs-' . esc_attr( $s ) . '">' . $lis . '</ul>'
                       .     '<div class="wpsa-inline-copy"><button type="button" class="button button-secondary wpsa-copy-btn" data-target="#wpsa-onjs-' . esc_attr( $s ) . '"><span class="dashicons dashicons-clipboard"></span> Copy</button></div>'
                       .   '</div>'
                       . '</details>';
                })()
              : '' )
        
          // ▼ Onload CSS list (placed under column 4)
          . ( ! empty( $results[ $s ]['on_css_list'] )
              ? (function() use ($results, $s) {
                  $lis = '';
                  foreach ( (array) $results[ $s ]['on_css_list'] as $it ) {
                    $lis .= '<li>' . esc_html( (string) $it ) . '</li>';
                  }
                  return '<details class="wpsa-inline-details is-css">'
                       .   '<summary>Show onload CSS files</summary>'
                       .   '<div>'
                       .     '<ul id="wpsa-oncss-' . esc_attr( $s ) . '">' . $lis . '</ul>'
                       .     '<div class="wpsa-inline-copy"><button type="button" class="button button-secondary wpsa-copy-btn" data-target="#wpsa-oncss-' . esc_attr( $s ) . '"><span class="dashicons dashicons-clipboard"></span> Copy</button></div>'
                       .   '</div>'
                       . '</details>';
                })()
              : '' )
        
        . '</div>'; // /.wpsa-stat-cards

                   
        // Build Module 2 log line for this strategy (server-side; avoids WF blocks)

                   
        // Build Module 2 log line for this strategy (server-side; avoids WF blocks)
       // If PSI didn't give numbers, log N/A for this pane
       if ( ! is_numeric( $req ) || (int) $req === 0 || ! is_numeric( $bytes ) || (float) $bytes <= 0 ) {
           $m2_logs[] = sprintf( "Module 2 %s: N/A\n", ucfirst( $s ) );
       } else {
           // Convert bytes → KB for logging (match UI)
           $kb_num = round( (float) $bytes / 1024, 1 );

           // Loaded counts (for log only)
           // Prefer the actual lists we show in the UI; fall back to PSI counts only if needed.
            $on_js_list  = $results[ $s ]['on_js_list']  ?? null;
            $on_css_list = $results[ $s ]['on_css_list'] ?? null;
            
            $on_js_num  = is_array( $on_js_list )  ? count( $on_js_list )
                         : ( is_numeric( $on_js_raw )  ? (int) $on_js_raw  : null );
            
            $on_css_num = is_array( $on_css_list ) ? count( $on_css_list )
                         : ( is_numeric( $on_css_raw ) ? (int) $on_css_raw : null );
            
            // (optional, keep these if you want them in the log)
            $ld_js_raw  = $results[ $s ]['ld_js'];
            $ld_css_raw = $results[ $s ]['ld_css'];
            $ld_js_num  = is_numeric( $ld_js_raw )  ? (int) $ld_js_raw  : null;
            $ld_css_num = is_numeric( $ld_css_raw ) ? (int) $ld_css_raw : null;

           $parts = [
               sprintf( 'Module 2 %s: %d req, %.1f KB', ucfirst( $s ), (int) $req, $kb_num ),
               is_null( $on_js_num )  ? null : 'Onload JS: '  . $on_js_num,
               is_null( $on_css_num ) ? null : 'Onload CSS: ' . $on_css_num,
               is_null( $ld_js_num )  ? null : 'Loaded JS: '  . $ld_js_num,
               is_null( $ld_css_num ) ? null : 'Loaded CSS: ' . $ld_css_num,
           ];

           $line = array_shift( $parts );
           $tail = array_filter( $parts, static function ( $v ) { return ( null !== $v && '' !== $v ); } );
           if ( ! empty( $tail ) ) {
               $line .= '; ' . implode( '; ', $tail );
           }
           $m2_logs[] = $line . "\n";
       }
        

    }
    
        // Append Module 2 log lines once (server-side)
        if ( ! empty( $m2_logs ) ) {
            $upload = wp_upload_dir();
            $logdir = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
            if ( ! is_dir( $logdir ) ) {
                // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
                wp_mkdir_p( $logdir );
            }
            $logfile = trailingslashit( $logdir ) . 'ttfb-results-log.txt';
            // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
            @file_put_contents( $logfile, implode( '', $m2_logs ), FILE_APPEND );
        }


    wp_send_json_success( $out );
    }
    
    /**
     * Server-side Module 2 runner for scheduled tests. v1.17
     *
     * This mirrors the logic of wpsa_ajax_psi(), but:
     * - runs without AJAX / JSON output
     * - writes log lines for both strategies directly to ttfb-results-log.txt
     *
     * @param string $url Tested URL.
     * @return bool True on success, false on hard failure.
     */
    function wpsa_run_module2_scheduled( $url ) {
        $url = esc_url_raw( $url );
        if ( ! $url ) {
            error_log( '[WPSA] Module 2 scheduled: invalid URL.' );
            return false;
        }
    
        $strategies    = [ 'mobile', 'desktop' ];
        $max_retries   = 3;
        $delay_seconds = 1;
        $results       = [];
        $m2_logs       = [];
    
        foreach ( $strategies as $strat ) {
            $endpoint = add_query_arg(
                [
                    'psi_url'  => rawurlencode( $url ),
                    'strategy' => $strat,
                    'url'      => rawurlencode( $url ),
                ],
                wpsa_get_worker_endpoint() . 'psi'
            );
    
            $attempt = 0;
            $delay   = $delay_seconds;
            do {
                if ( $attempt > 0 ) {
                    sleep( $delay );
                    $delay *= 2;
                }
                $response = wp_remote_get( $endpoint, [ 'timeout' => 65 ] );
                $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
                $attempt++;
            } while ( ( is_wp_error( $response ) || 429 === $code ) && $attempt < $max_retries );
    
            if ( is_wp_error( $response ) || 200 !== $code ) {
                $err = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
                $m2_logs[] = sprintf(
                    "Module 2 %s: N/A (Worker/PSI error: %s)\n",
                    ucfirst( $strat ),
                    $err
                );
                error_log( '[WPSA] Module 2 scheduled ' . $strat . ' failed: ' . $err );
                continue;
            }
    
            $body  = wp_remote_retrieve_body( $response );
            $data  = json_decode( $body, true );
            $items = $data['lighthouseResult']['audits']['network-requests']['details']['items'] ?? null;
            $req   = is_array( $items ) ? count( $items ) : null;
    
            $bytes_raw = $data['lighthouseResult']['audits']['total-byte-weight']['numericValue'] ?? null;
            $bytes     = is_numeric( $bytes_raw ) ? (float) $bytes_raw : null;
    
            // Onload counts from Worker dual-mode payload
            $on_js  = $data['assets']['onload']['js_count']  ?? null;
            $on_css = $data['assets']['onload']['css_count'] ?? null;
            $on_js  = is_numeric( $on_js )  ? (int) $on_js  : null;
            $on_css = is_numeric( $on_css ) ? (int) $on_css : null;
    
            // Onload lists (try Worker first, then fallback to PSI payload)
            $on_js_list  = [];
            $on_css_list = [];
    
            foreach ( [ 'js_list', 'js', 'jsFiles', 'js_files' ] as $k ) {
                if ( isset( $data['assets']['onload'][ $k ] ) && is_array( $data['assets']['onload'][ $k ] ) ) {
                    $on_js_list = array_values( array_map( 'strval', $data['assets']['onload'][ $k ] ) );
                    break;
                }
            }
    
            foreach ( [ 'css_list', 'css', 'cssFiles', 'css_files' ] as $k ) {
                if ( isset( $data['assets']['onload'][ $k ] ) && is_array( $data['assets']['onload'][ $k ] ) ) {
                    $on_css_list = array_values( array_map( 'strval', $data['assets']['onload'][ $k ] ) );
                    break;
                }
            }
    
            // Fallback: derive lists from PSI network-requests payload.
            if ( ( empty( $on_js_list ) || empty( $on_css_list ) ) && is_array( $items ) ) {
                $nr_js  = [];
                $nr_css = [];
                foreach ( $items as $row ) {
                    $u = isset( $row['url'] ) ? (string) $row['url'] : '';
                    if ( '' === $u || preg_match( '#^(data:|blob:|about:|chrome-)#i', $u ) ) {
                        continue;
                    }
    
                    $mime = isset( $row['mimeType'] ) ? (string) $row['mimeType'] : '';
                    $type = isset( $row['resourceType'] ) ? (string) $row['resourceType'] : '';
    
                    $is_js  = ( false !== stripos( $mime, 'javascript' ) )
                           || ( false !== stripos( $type, 'Script' ) )
                           || preg_match( '/\.m?js(\?.*)?$/i', $u );
    
                    $is_css = ( false !== stripos( $mime, 'css' ) )
                           || ( false !== stripos( $type, 'Stylesheet' ) )
                           || preg_match( '/\.css(\?.*)?$/i', $u );
    
                    if ( $is_js ) {
                        $nr_js[] = $u;
                    }
                    if ( $is_css ) {
                        $nr_css[] = $u;
                    }
                }
                if ( empty( $on_js_list ) && ! empty( $nr_js ) ) {
                    $on_js_list = array_values( array_unique( $nr_js ) );
                }
                if ( empty( $on_css_list ) && ! empty( $nr_css ) ) {
                    $on_css_list = array_values( array_unique( $nr_css ) );
                }
            }
    
            // Fully-loaded counts (if the Worker returned them).
            $ld_js  = $data['assets']['loaded']['js_count']  ?? null;
            $ld_css = $data['assets']['loaded']['css_count'] ?? null;
            $ld_js  = is_numeric( $ld_js )  ? (int) $ld_js  : null;
            $ld_css = is_numeric( $ld_css ) ? (int) $ld_css : null;
    
            $results[ $strat ] = [
                'requests'    => $req,
                'bytes'       => $bytes,
                'on_js'       => $on_js,
                'on_css'      => $on_css,
                'ld_js'       => $ld_js,
                'ld_css'      => $ld_css,
                'on_js_list'  => $on_js_list,
                'on_css_list' => $on_css_list,
            ];
    
            // Build log line for this strategy (same wording as AJAX version).
            if ( ! is_numeric( $req ) || (int) $req === 0 || ! is_numeric( $bytes ) || (float) $bytes <= 0 ) {
                $m2_logs[] = sprintf( "Module 2 %s: N/A\n", ucfirst( $strat ) );
            } else {
                $kb_num = round( (float) $bytes / 1024, 1 );
    
                $on_js_list  = $results[ $strat ]['on_js_list']  ?? null;
                $on_css_list = $results[ $strat ]['on_css_list'] ?? null;
    
                $on_js_num  = is_array( $on_js_list )  ? count( $on_js_list )
                             : ( is_numeric( $on_js )  ? (int) $on_js  : null );
    
                $on_css_num = is_array( $on_css_list ) ? count( $on_css_list )
                             : ( is_numeric( $on_css ) ? (int) $on_css : null );
    
                $ld_js_raw  = $results[ $strat ]['ld_js'];
                $ld_css_raw = $results[ $strat ]['ld_css'];
                $ld_js_num  = is_numeric( $ld_js_raw )  ? (int) $ld_js_raw  : null;
                $ld_css_num = is_numeric( $ld_css_raw ) ? (int) $ld_css_raw : null;
    
                $parts = [
                    sprintf(
                        'Module 2 %s: %d req, %.1f KB',
                        ucfirst( $strat ),
                        (int) $req,
                        $kb_num
                    ),
                    is_null( $on_js_num )  ? null : 'Onload JS: '  . $on_js_num,
                    is_null( $on_css_num ) ? null : 'Onload CSS: ' . $on_css_num,
                    is_null( $ld_js_num )  ? null : 'Loaded JS: '  . $ld_js_num,
                    is_null( $ld_css_num ) ? null : 'Loaded CSS: ' . $ld_css_num,
                ];
    
                $line = array_shift( $parts );
                $tail = array_filter(
                    $parts,
                    static function ( $v ) {
                        return ( null !== $v && '' !== $v );
                    }
                );
                if ( ! empty( $tail ) ) {
                    $line .= '; ' . implode( '; ', $tail );
                }
                $m2_logs[] = $line . "\n";
            }
        }
    
        if ( empty( $m2_logs ) ) {
            // Nothing usable to log.
            return false;
        }
    
        $upload = wp_upload_dir();
        $logdir = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
        if ( ! is_dir( $logdir ) ) {
            // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
            wp_mkdir_p( $logdir );
        }
        $logfile = trailingslashit( $logdir ) . 'ttfb-results-log.txt';
        // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
        @file_put_contents( $logfile, implode( '', $m2_logs ), FILE_APPEND );
    
        return true;
    }

    

       // AJAX logging for Module 2 
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
     * Shared helper: append one Module-5 diagnostics row to psi-diag-log.txt
     *
     * @param int    $test_no     Base test number (e.g. 644).
     * @param string $tested_url  URL for this run.
     * @param array  $mobile      Mobile diagnostics payload.
     * @param array  $desktop     Desktop diagnostics payload.
     * @param bool   $scheduled   True if this was a scheduled run (“S” suffix).
     */
    function wpsa_log_module5_diag_row( $test_no, $tested_url, $mobile, $desktop, $scheduled = false ) {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
        if ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
            wp_mkdir_p( $dir );
        }

        $file = trailingslashit( $dir ) . 'psi-diag-log.txt';

        // Normal tests: 644; scheduled tests: "644S"
        $test_field = $scheduled ? sprintf( '%dS', (int) $test_no ) : (int) $test_no;

        $row = array(
            'test'       => $test_field,
            'tested_url' => $tested_url,
            'ts'         => gmdate( 'c' ),
            'mobile'     => is_array( $mobile )  ? $mobile  : array(),
            'desktop'    => is_array( $desktop ) ? $desktop : array(),
            'scheduled'  => $scheduled ? true : false,
        );

        // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
        @file_put_contents( $file, wp_json_encode( $row ) . "\n", FILE_APPEND | LOCK_EX );
    }

   
    
    /** 1.16.3: AJAX logger for Module-5 diagnostics (writes uploads/speed-analyzer/psi-diag-log.txt) */
    add_action( 'wp_ajax_wpsa_log_module5_diag', 'wpsa_ajax_log_module5_diag' );
    function wpsa_ajax_log_module5_diag() {
        // Reuse the performance nonce like the other Module-5 calls
        check_ajax_referer( 'wpsa_performance_nonce', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', [ 'response' => 403 ] );
        }
    
        $test_no    = intval( $_POST['test_no']    ?? 0 );
        $tested_url = esc_url_raw( wp_unslash( $_POST['tested_url'] ?? '' ) );
        $mobile_raw = (string) ( $_POST['mobile']  ?? '[]' );
        $desktop_raw= (string) ( $_POST['desktop'] ?? '[]' );
    
        $mobile  = json_decode( $mobile_raw, true );
        $desktop = json_decode( $desktop_raw, true );
    
        // Normal (manual) test – no “S” suffix here
        wpsa_log_module5_diag_row( $test_no, $tested_url, $mobile, $desktop, false );
    
        // Optional: keep logs trimmed
        if ( function_exists( 'wpsa_maybe_trim_logs' ) ) {
            wpsa_maybe_trim_logs();
        }
    
        wp_die();
    }


    
        /**
         * Modules 3 & 4: Autoload size and object cache.
         * Render “N/A” off-server and trigger summary events so Module 6 shows.
         *
         * @param wpdb   $wpdb         WP database object.
         * @param string $results_log  Path to results log.
         * @param string $tested_url   Tested URL (optional; falls back to POST when empty).
         */
        function wpsa_module3_4_autoload_cache( $wpdb, $results_log, $tested_url = '' ) {
            if ( '' === $tested_url ) {
                $raw_url    = wp_unslash( filter_input( INPUT_POST, 'test_url', FILTER_UNSAFE_RAW ) ?? '' );
                $tested_url = esc_url_raw( $raw_url );
            } else {
                $tested_url = esc_url_raw( $tested_url );
            }

    
        echo '<div class="wpsa-module-3-4">';
        
            // Ensure we have a usable log file path on every site/run
        $log_path = $results_log;
        $needs_init = false;
        if ( empty( $log_path ) ) {
            $needs_init = true;
        } else {
            $dir = dirname( $log_path );
            if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
                $needs_init = true;
            }
        }
        if ( $needs_init ) {
            $upload_dir = wp_upload_dir();
            $base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'speed-analyzer';
            if ( ! is_dir( $base_dir ) ) {
                // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
                wp_mkdir_p( $base_dir );
            }
            $log_path = trailingslashit( $base_dir ) . 'ttfb-results-log.txt';
        }
         // Defer TRACE logging until we know size is zero (to avoid noise on healthy runs).
        
    
        if ( ! wpsa_is_same_host( $tested_url ) ) {
            // Log “N/A” for Modules 3 & 4
            file_put_contents( $log_path, "Module 3: Autoloaded options size: N/A\n", FILE_APPEND );
             file_put_contents( $log_path, "Module 4: Persistent object cache: N/A\n", FILE_APPEND );
    
            // Output “N/A” UI
           echo '<div class="wpsa-stat-cards photo">';
           echo '<div>';
            echo '<h2 class="wpsa-module-title wpsa-module-3-title">4. Autoloaded options size</h2>';
            echo '<div class="wpsa-stat-card wpsa-card-autoload is-na" style="background:#dddddd;">'; 
              echo '<div class="header">Autoloaded options size <span class="custom-tooltip" data-tooltip="Try to keep this value under 800KB. N/A on external domains tests.">?</span></div>';
              echo '<div class="value">N/A</div>';
            echo '</div>';
            echo '<p class="wpsa-footnote"><strong>*N/A for off-server URLs</strong></p>';
             echo '</div>';

              echo '<div>';
            echo '<h2 class="wpsa-module-title wpsa-module-4-title">5. Persistent object cache</h2>';
            echo '<div class="wpsa-stat-card wpsa-card-objectcache is-na" style="background:#dddddd;">'; 
              echo '<div class="header">Persistent object cache <span class="custom-tooltip" data-tooltip="Persistent object cache is especially beneficial for database-heavy sites (e.g. e-commerce).">?</span></div>';
              echo '<div class="value">N/A</div>';
            echo '</div>';
            echo '<p class="wpsa-footnote"><strong>*N/A for off-server URLs</strong></p>';
          echo '</div>';
        echo '</div>';

             
            /* --- NEW: 4.5 placeholder on off-server URLs --- */
            echo '<h2 class="wpsa-module-title wpsa-module-45-title">6. Various other information</h2>';
            echo '<div class="wpsa-module45-cards wpsa-meta-cards">';
              echo '<div class="wpsa-stat-card wpsa-meta-card is-na wpsa-card-plugins">';
                echo '<div class="header">Active plugins</div>';
                echo '<div class="value">N/A</div>';
              echo '</div>';
              echo '<div class="wpsa-stat-card wpsa-meta-card is-na wpsa-card-php">';
                echo '<div class="header">PHP version</div>';
                echo '<div class="value">N/A</div>';
              echo '</div>';
              echo '<div class="wpsa-stat-card wpsa-meta-card is-na wpsa-card-db">';
                echo '<div class="header">DB server and size</div>';
                echo '<div class="value">N/A</div>';
              echo '</div>';
            echo '</div>';
            echo '<p class="wpsa-footnote"><strong>*N/A for off-server URLs</strong></p>';
            echo '</div>'; // /.wpsa-module-3-4
            return;
        }
    
       // v3 cache key to invalidate any previously cached 0.0 values on hosts that misreported
        $cache_key = 'wpsa_autoloaded_options_kb_v3';
        $kb = wp_cache_get( $cache_key, 'wpsa' );
        
        // Recompute if cache miss OR cached value is 0.0 (stale/bad on some hosts)
        if ( false === $kb || (float) $kb === 0.0 ) {
             // Summarize size of all autoloaded options (in KB) — WP core semantics: autoload='yes' only.
            $method   = 'sql_yes';
            $sql_yes  = $wpdb->prepare(
                "SELECT COALESCE(SUM(OCTET_LENGTH(option_value)),0)
                 FROM {$wpdb->options}
                 WHERE autoload = %s",
                'yes'
            );
            $bytes_raw = $wpdb->get_var( $sql_yes ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $bytes     = is_numeric( $bytes_raw ) ? (int) $bytes_raw : 0;
    
            // 2) Fallback A: wp_load_alloptions()
            if ( 0 === $bytes ) {
                $method = 'alloptions';
                $all = wp_load_alloptions();
                if ( is_array( $all ) && ! empty( $all ) ) {
                    $bytes = 0;
                    foreach ( $all as $v ) {
                        if ( ! is_string( $v ) ) {
                            $v = maybe_serialize( $v );
                        }
                        $bytes += strlen( $v );
                    }
                }
            }
    
            // 3) Fallback B: raw DB pull and sum in PHP
                $raw_count = 0;
                if ( 0 === $bytes ) {
                    // Pull autoload='yes' only and sum in PHP.
                $method = 'raw_db_yes';
                $values = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT option_value
                         FROM {$wpdb->options}
                         WHERE autoload = %s",
                        'yes'
                    )
                ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    
                if ( is_array( $values ) && ! empty( $values ) ) {
                    $raw_count = count( $values );
                    $bytes = 0;
                    foreach ( $values as $raw ) {
                        $bytes += strlen( (string) $raw );
                    }
                }
            }
    
            $kb = round( $bytes / 1024, 1 );
            wp_cache_set( $cache_key, $kb, 'wpsa', HOUR_IN_SECONDS );

      // If still zero, examine how 'autoload' is stored on this host
       if ( 0.0 === (float) $kb ) {
            // Log TRACE only in failing cases (kb==0) to keep logs clean.
            @file_put_contents( $log_path, "Module 3 TRACE: start\n", FILE_APPEND ); // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
            error_log( '[WPSA] Module 3 TRACE: start' );
            $dist = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                "SELECT COALESCE(autoload,'NULL') AS autoload_flag, COUNT(*) AS cnt
                 FROM {$wpdb->options}
                 GROUP BY autoload_flag
                 ORDER BY cnt DESC",
                ARRAY_A
            );
            $dist_json = $dist ? wp_json_encode( $dist ) : '[]';
             // If the probe failed on this host, capture the DB error for diagnostics.
                if ( empty( $dist ) && ! empty( $wpdb->last_error ) ) {
                    $db_err = sanitize_text_field( $wpdb->last_error );
                    // Mirror to both sinks so we can see it even if file writes are blocked
                    @file_put_contents( $log_path, "Module 3 SQL ERROR: {$db_err}\n", FILE_APPEND ); // phpcs:ignore
                    error_log( '[WPSA] Module 3 SQL ERROR: ' . $db_err );
                }
            // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
            @file_put_contents( $log_path, "Module 3 DEBUG: kb=0 via {$method}; raw_rows={$raw_count}; dist={$dist_json}\n", FILE_APPEND );
            // Mirror DEBUG to PHP error log as a fallback on locked FS
            error_log( '[WPSA] Module 3 DEBUG: kb=0 via ' . $method . '; raw_rows=' . (int) $raw_count . '; dist=' . $dist_json );
            
            // --- add this inside the kb==0 block, after $dist_json is built ---

        // Count "truthy" autoload rows that WordPress would NOT autoload.
        $truthy_cnt = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->options}
             WHERE LOWER(COALESCE(autoload,'no')) IN ('on','auto','1','true','y')"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        
        if ( $truthy_cnt > 0 ) {
            // (Optional) also compute their would-be size, for the log only.
            $truthy_kb = (float) $wpdb->get_var(
                "SELECT ROUND(COALESCE(SUM(OCTET_LENGTH(option_value)),0)/1024,1)
                 FROM {$wpdb->options}
                 WHERE LOWER(COALESCE(autoload,'no')) IN ('on','auto','1','true','y')"
            ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        
            // Log a clear note
            @file_put_contents(
                $log_path,
                "Module 3 NOTE: Non-standard autoload flags detected (off/auto/on/etc). ".
                "WP only autoloads 'yes'. truthy_rows={$truthy_cnt}; truthy_kb={$truthy_kb}\n",
                FILE_APPEND
            );
        
            // Add an admin-only hint to the UI
            if ( current_user_can( 'manage_options' ) ) {
                $ui_note = __( "Non-standard autoload flags detected (off/auto/on); WordPress only autoloads 'yes'.", 'speed-analyzer' );
                $ui_hint = isset( $ui_hint ) ? $ui_hint : '';
                $ui_hint .= '<p class="wpsa-footnote" style="margin-top:6px;color:#666">'
                          . esc_html( $ui_note )
                          . '</p>';
            }
            $ui_hint .= '<br><span style="color:#666">'
          . esc_html__( 'Host reports ~', 'speed-analyzer' )
          . esc_html( number_format_i18n( (float) $truthy_kb, 1 ) ) . ' KB '
          . esc_html__( 'using non-standard flags; WordPress only autoloads rows with autoload=\'yes\'.', 'speed-analyzer' )
          . '</span>';

        }

      }
}

    $bg1 = ( $kb <= 800 ) ? '#c6f7c3' : '#ffdddd';
    $oc  = ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) ? 'Yes' : 'No';
    $bg2 = ( $oc === 'Yes' ) ? '#c6f7c3' : '#ffdddd';
        
        // Also collect the Top 10 autoloaded options by size (bytes → KB) for the UI
       $top_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT option_name, OCTET_LENGTH(option_value) AS bytes_len
             FROM {$wpdb->options}
             WHERE autoload = %s
             ORDER BY bytes_len DESC
             LIMIT 10",
            'yes'
        ),
        ARRAY_A
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $top10_html = '';
        if ( is_array( $top_rows ) && ! empty( $top_rows ) ) {
            $rows_html = '';
            foreach ( $top_rows as $r ) {
                $nm = isset( $r['option_name'] ) ? (string) $r['option_name'] : '';
                $bl = isset( $r['bytes_len'] ) ? (int) $r['bytes_len'] : 0;
                $kb_row = round( $bl / 1024, 1 );
                $rows_html .= '<tr>'
                            . '<td style="padding:2px 8px;border:1px solid #ddd;max-width:460px;word-break:break-word;">' . esc_html( $nm ) . '</td>'
                            . '<td style="padding:2px 8px;border:1px solid #ddd;text-align:right;white-space:nowrap;">' . esc_html( (string) $kb_row ) . ' KB</td>'
                          . '</tr>';
            }
           $top10_html  = '<details class="wpsa-autoload-top10" style="margin-top:6px;">';
            $top10_html .= '<summary style="cursor:pointer;color:#444;">' . esc_html__( 'Top 10 autoloaded options by size', 'speed-analyzer' ) . '</summary>';
            $top10_html .= '<table id="wpsa-top10-autoload" style="margin-top:6px;border-collapse:collapse;"><thead><tr>'
             .  '<th style="padding:2px 8px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'option_name', 'speed-analyzer' ) . '</th>'
             .  '<th style="padding:2px 8px;border:1px solid #ddd;text-align:right;">' . esc_html__( 'size', 'speed-analyzer' ) . '</th>'
             .  '</tr></thead><tbody>' . $rows_html . '</tbody></table>'
             // bottom-right Copy button reusing existing style/handler
             .  '<div class="wpsa-inline-copy" style="text-align:right; margin-top:6px;">'
             .    '<button type="button" class="button button-secondary wpsa-copy-btn" data-target="#wpsa-top10-autoload">'
             .      '<span class="dashicons dashicons-clipboard"></span> ' . esc_html__( 'Copy', 'speed-analyzer' )
             .    '</button>'
             .  '</div>'
             . '</details>';

        }


    file_put_contents( $log_path, sprintf( "Module 3: Autoloaded options size: %.1f KB\n", $kb ), FILE_APPEND );
    file_put_contents( $log_path, sprintf( "Module 4: Persistent object cache: %s\n", $oc ), FILE_APPEND );  
    
    // Mirror final results to PHP error log as fallback (temporary while diagnosing Cloudways)
    error_log( '[WPSA] Module 3: Autoloaded options size: ' . sprintf( '%.1f KB', (float) $kb ) );
    error_log( '[WPSA] Module 4: Persistent object cache: ' . $oc );
    
    // --- Row with Modules 3 & 4 (single row) ---
echo '<div class="wpsa-stat-cards photo">';

  // LEFT: Module 3
  echo '<div>';
    echo '<h2 class="wpsa-module-title wpsa-module-3-title">4. Autoloaded options size</h2>';
    echo '<div class="wpsa-stat-card wpsa-card-autoload" style="background:' . esc_attr( $bg1 ) . '">';
      echo '<div class="header">Autoloaded options size <span class="custom-tooltip" data-tooltip="A server-wide test. Size of all options with autoload=\'yes\'.">?</span></div>';
      echo '<div class="value">' . esc_html( $kb ) . ' KB' . ( $kb <= 800 ? ' <span class="icon">✅</span>' : '' ) . '</div>';
    echo '</div>';
    if ( ! isset( $ui_hint ) ) { $ui_hint = ''; }
    echo '<p class="wpsa-footnote">*Try to keep this value under 800 KB</p>' . $ui_hint . $top10_html;
  echo '</div>';

  // RIGHT: Module 4
  echo '<div>';
    echo '<h2 class="wpsa-module-title wpsa-module-4-title">5. Persistent object cache</h2>';
    echo '<div class="wpsa-stat-card wpsa-card-objectcache" style="background:' . esc_attr( $bg2 ) . '">';
      echo '<div class="header">Persistent object cache <span class="custom-tooltip" data-tooltip="A server-wide test. Whether this site uses a persistent object cache.">?</span></div>';
      echo '<div class="value">' . esc_html( $oc ) . ( $oc === 'Yes' ? ' <span class="icon">✅</span>' : '' ) . '</div>';
    echo '</div>';
    echo '<p class="wpsa-footnote">*Persistent object cache is beneficial for database-heavy sites (e.g. e-commerce).</p>';
  echo '</div>';

echo '</div>'; // /.wpsa-stat-cards

    // ===== FULL-WIDTH 4.5 BELOW THE ROW =====
    echo '<h2 class="wpsa-module-title wpsa-module-45-title">6. Various other information</h2>';
    
    // Gather values for 4.5
    $site_active    = (array) get_option( 'active_plugins', [] );
    $network_active = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : [];
    $active_plugins = count( array_unique( array_merge( $site_active, $network_active ) ) );
    
    // 1.16.2 dropdowns- build the plugin names list for the dropdown
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php'; // ✔ ensure get_plugins() exists
    }

    
    // 1.16.2 dropdowns- build the plugin names list for the dropdown
        $plugins_list = [];
        if ( function_exists( 'get_plugins' ) ) {
            $all_plugins = get_plugins();
            $active_slugs = array_unique( array_merge( $site_active, $network_active ) );
            foreach ( $active_slugs as $slug ) {
                if ( isset( $all_plugins[ $slug ] ) ) {
                    $nm = $all_plugins[ $slug ]['Name'] ?? $slug;
                    $vr = $all_plugins[ $slug ]['Version'] ?? '';
                    $plugins_list[] = $vr ? sprintf( '%s v%s', $nm, $vr ) : $nm;
                }
            }
        }
    
    // Active plugins thresholds: ≤40 green, 41–55 amber, >55 red
    $sev_plugins  = ( $active_plugins <= 40 ) ? 'is-green' : ( $active_plugins <= 55 ? 'is-amber' : 'is-red' );
    $icon_plugins = ( $sev_plugins === 'is-green' ) ? ' <span class="icon">✅</span>' : '';
    
    $php_ver  = PHP_VERSION;
    $sev_php  = version_compare( $php_ver, '8.0', '>=' ) ? 'is-green' : 'is-red';
    $icon_php = ( $sev_php === 'is-green' ) ? ' <span class="icon">✅</span>' : '';
    
    $server_info = method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : $wpdb->db_version();
    $vendor      = ( stripos( (string) $server_info, 'mariadb' ) !== false ) ? 'MariaDB' : 'MySQL';
    preg_match( '/(\d+\.\d+(?:\.\d+)?)/', (string) $server_info, $m );
    $db_ver      = $m[1] ?? $wpdb->db_version();

    // Approximate DB size (MB) for the current database.
    $db_size_mb = null;
    $db_name    = $wpdb->get_var( 'SELECT DATABASE()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

    if ( ! empty( $db_name ) ) {
        $size_raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1)
                 FROM information_schema.TABLES
                 WHERE table_schema = %s",
                $db_name
            )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( is_numeric( $size_raw ) ) {
            $db_size_mb = (float) $size_raw;
        }
    }
    
    // DB thresholds: MariaDB ≥10.6 green; 10.4–10.5 amber; else red. MySQL ≥8.0 green; else red.
    if ( $vendor === 'MariaDB' ) {
      $sev_db = version_compare( $db_ver, '10.6', '>=' ) ? 'is-green'
              : ( version_compare( $db_ver, '10.4', '>=' ) ? 'is-amber' : 'is-red' );
    } else {
      $sev_db = version_compare( $db_ver, '8.0', '>=' ) ? 'is-green' : 'is-red';
    }
    $icon_db = ( $sev_db === 'is-green' ) ? ' <span class="icon">✅</span>' : '';
    
    // Optional: log 4.5 values (include DB size when available)
    if ( null !== $db_size_mb && $db_size_mb > 0 ) {
        $log_line = sprintf(
            "Module 4.5: Active plugins: %d; PHP: %s; DB: %s %s (%.1f MB)\n",
            (int) $active_plugins,
            $php_ver,
            $vendor,
            $db_ver,
            $db_size_mb
        );
    } else {
        $log_line = sprintf(
            "Module 4.5: Active plugins: %d; PHP: %s; DB: %s %s\n",
            (int) $active_plugins,
            $php_ver,
            $vendor,
            $db_ver
        );
    }

    @file_put_contents( $log_path, $log_line, FILE_APPEND );
        
        // keep logs tidy when they grow large
        if ( function_exists( 'wpsa_maybe_trim_logs' ) ) {
            wpsa_maybe_trim_logs();
        }

    
    // Full-width grid under modules 3/4
    echo '<div class="wpsa-module45-cards wpsa-meta-cards photo">';
     // one grid cell that contains the Plugins card + its <details> list *under* it
     echo '<div class="wpsa-col-plugins">';
       echo '<div class="wpsa-stat-card wpsa-meta-card wpsa-card-plugins ' . esc_attr( $sev_plugins ) . '">';
         echo '<div class="header">Active plugins</div>';
         echo '<div class="value">' . esc_html( (string) $active_plugins ) . $icon_plugins . '</div>';
       echo '</div>';
       if ( ! empty( $plugins_list ) ) {
         echo '<details class="wpsa-autoload-top10" style="margin-top:8px;">'
            .   '<summary style="cursor:pointer;color:#444;">Show active plugins list</summary>'
            .   '<div style="margin-top:6px;">'
            .     '<ul id="wpsa-plugin-list" style="margin:6px 0; padding-left:18px; list-style:disc; max-height:240px; overflow:auto;">';
         foreach ( (array) $plugins_list as $pl ) {
           echo '<li>' . esc_html( (string) $pl ) . '</li>';
         }
           echo        '</ul>'
            // ▼ NEW: add a bottom-right Copy button
            .       '<div style="text-align:right; margin-top:6px;">'
            .         '<button type="button" class="button button-secondary wpsa-copy-btn" data-target="#wpsa-plugin-list"><span class="dashicons dashicons-clipboard"></span> Copy</button>'
            .       '</div>'
            .       '</div>'
            . '</details>';
       }
     echo '</div>'; // /.wpsa-col-plugins
    
    echo '<div class="wpsa-stat-card wpsa-meta-card wpsa-card-php ' . esc_attr( $sev_php ) . '">';
      echo '<div class="header">PHP version</div>';
      echo '<div class="value">' . esc_html( $php_ver ) . $icon_php . '</div>';
    echo '</div>';

    // DB server card with version + DB size (MB or GB)
    echo '<div class="wpsa-stat-card wpsa-meta-card wpsa-card-db ' . esc_attr( $sev_db ) . '">';
      echo '<div class="header">DB server and size</div>';

      // Determine display size: MB or GB
      $db_extra = '';
      if ( isset( $db_size_mb ) && $db_size_mb > 0 ) {
          if ( $db_size_mb >= 1024 ) {
              // Convert to GB with 1 decimal
              $db_gb = round( $db_size_mb / 1024, 1 );
              $db_extra = ' – ' . number_format_i18n( $db_gb, 1 ) . ' GB';
          } else {
              // Standard MB output
              $db_extra = ' – ' . number_format_i18n( $db_size_mb, 1 ) . ' MB';
          }
      }

      // Checkmark moved after version (not after size)
      echo '<div class="value">'
              . esc_html( $vendor ) . '<br>'
              . '<span class="subvalue">'
                  . esc_html( $db_ver )
                  . $icon_db              // checkmark right after version
                  . esc_html( $db_extra ) // DB size after check
              . '</span>'
          . '</div>';

    echo '</div>'; // /.wpsa-stat-card wpsa-card-db

    echo '</div>'; // /.wpsa-module45-cards  (plugins col + php + db)

    // Close the wrapper opened at the start of this function
    echo '</div>'; // /.wpsa-module-3-4
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