<?php

/**
 * Speed Analyzer - schedule.php v0.64
 * Schedule tests functionality.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calculate the next run timestamp for the given frequency/time.
 *
 * @param string      $frequency 'daily'|'weekly'|'monthly'.
 * @param string      $time_str  'HH:MM' 24h format.
 * @param null|int    $from_ts   Base timestamp (defaults to "now").
 * @return int                   Unix timestamp (UTC).
 */
function wpsa_schedule_calculate_next_run( $frequency, $time_str, $from_ts = null ) {
    if ( ! $from_ts ) {
        $from_ts = time();
    }

    // Sanity for time string.
    if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time_str ) ) {
        $time_str = '07:30';
    } 

    // Work in site timezone.
    if ( function_exists( 'wp_timezone' ) ) {
        $tz = wp_timezone();
    } else {
        $tz_string = get_option( 'timezone_string' );
        $tz        = $tz_string ? new DateTimeZone( $tz_string ) : new DateTimeZone( 'UTC' );
    }

    $dt = new DateTimeImmutable( '@' . $from_ts );
    $dt = $dt->setTimezone( $tz );

    list( $h, $m ) = array_map( 'intval', explode( ':', $time_str ) );

    // Candidate: today at scheduled time.
    $candidate = $dt->setTime( $h, $m, 0 );

    if ( $candidate <= $dt ) {
        // Already passed today → bump depending on frequency.
        switch ( $frequency ) {
            case 'weekly':
                $candidate = $candidate->modify( '+1 week' );
                break;
            case 'monthly':
                $candidate = $candidate->modify( '+1 month' );
                break;
            case 'daily':
            default:
                $candidate = $candidate->modify( '+1 day' );
                break;
        }
    }

    // Return as UTC timestamp.
    $candidate_utc = $candidate->setTimezone( new DateTimeZone( 'UTC' ) );
    return (int) $candidate_utc->format( 'U' );
}

/**
 * Parse the last "Test #N" from the results log.
 *
 * @param string $results_log Path to ttfb-results-log.txt.
 * @return int|null
 */
function wpsa_schedule_get_last_test_number( $results_log ) {
    if ( ! file_exists( $results_log ) ) {
        return null;
    }

    $lines = @file( $results_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( ! is_array( $lines ) || empty( $lines ) ) {
        return null;
    }

    $lines = array_reverse( $lines );
    foreach ( $lines as $line ) {
        if ( preg_match( '/^Test #(\d+)\b/', $line, $m ) ) {
            return (int) $m[1];
        }
    }

    return null;
}

/**
 * Attach Module 5 lines to the latest matching "Test #N" block.
 *
 * If $test_no is provided (>0), we prefer to match by that Test # first (and
 * also confirm the URL matches). If $test_no is null/0, we fall back to
 * matching by URL only (latest test for that URL).
 *
 * If we cannot find a matching test block, falls back to appending.
 *
 * @param string   $results_log Path to ttfb-results-log.txt.
 * @param string   $tested_url  URL for which Module 5 was run.
 * @param array    $lines       Array of "Module 5 ..." strings (with trailing "\n").
 * @param int|null $test_no     Optional Test # to attach to.
 */
function wpsa_schedule_attach_module5_to_test( $results_log, $tested_url, $lines, $test_no = null ) {
    $results_log = (string) $results_log;
    $tested_url  = trim( (string) $tested_url );
    $test_no     = ( is_numeric( $test_no ) && (int) $test_no > 0 ) ? (int) $test_no : null;


    if ( '' === $results_log || '' === $tested_url ) {
        return;
    }

    if ( ! file_exists( $results_log ) || ! is_readable( $results_log ) ) {
        return;
    }

    // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
    $raw = @file_get_contents( $results_log );
    if ( false === $raw || '' === $raw ) {
        return;
    }

    // Split into blocks by 1+ blank lines (same idea as compare.php).
    $blocks = preg_split( "/(?:\r\n|\r|\n){2,}/", $raw );
    if ( ! is_array( $blocks ) || empty( $blocks ) ) {
        // Fallback – just append at the end as before.
        // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
        @file_put_contents( $results_log, implode( '', $lines ) . "\n", FILE_APPEND | LOCK_EX );
        return;
    }

        $url_norm = function_exists( 'wpsa_schedule_norm_url' )
        ? wpsa_schedule_norm_url( $tested_url )
        : preg_replace( '/#.*$/', '', preg_replace( '/\?.*$/', '', rtrim( $tested_url, '/' ) ) );


    // Walk blocks from the end to find the latest matching URL.
    for ( $i = count( $blocks ) - 1; $i >= 0; $i-- ) {
        $block = trim( (string) $blocks[ $i ] );
        if ( '' === $block ) {
            continue;
        }

        $lines_in_block = preg_split( "/(?:\r\n|\r|\n)/", $block );
        if ( ! is_array( $lines_in_block ) || empty( $lines_in_block ) ) {
            continue;
        }

        $header = trim( (string) $lines_in_block[0] );

        // Re-use the same header pattern as in wpsa_schedule_parse_tests_from_log().
        if ( ! preg_match(
            '/^Test\s+#(\d+)(?:\s+[A-Z])?\s*\|[^|]*\|\s*[^|]*\|\s*Tier:\s*[^|]*\|\s*URL:\s*([^\|]+)\|/i',
            $header,
            $m
        ) ) {
            continue;
        }

        $header_test_no = (int) $m[1];
        $header_url     = trim( $m[2] );

        $header_url_norm = function_exists( 'wpsa_schedule_norm_url' )
            ? wpsa_schedule_norm_url( $header_url )
            : preg_replace( '/#.*$/', '', preg_replace( '/\?.*$/', '', rtrim( $header_url, '/' ) ) );


        // If a specific Test # is provided, require BOTH test number and URL to match.
        if ( null !== $test_no ) {
            if ( $header_test_no !== $test_no || $header_url_norm !== $url_norm ) {
                continue;
            }
        } else {
            // Legacy behaviour: match by URL only (latest test for that URL).
            if ( $header_url_norm !== $url_norm ) {
                continue;
            }
        }


        // We have the latest block for this URL – strip any existing Module 5 lines.
        $clean = array();

        foreach ( $lines_in_block as $ln ) {
            $ln_trim = trim( (string) $ln );
            if ( '' === $ln_trim ) {
                $clean[] = $ln;
                continue;
            }

           if ( preg_match( '/^Module\s+5\s+(Mobile|Desktop):/i', $ln_trim ) ) {
                // Drop old Module 5 lines.
                continue;
            }

            if ( preg_match( '/^Module\s+5\s+CWV\s+/i', $ln_trim ) ) {
                // Drop old CWV lines too (URL/Origin/Page).
                continue;
            }

            $clean[] = rtrim( $ln, "\r\n" );

        }

        // Append the new Module 5 Mobile/Desktop lines to this block.
        foreach ( $lines as $ln ) {
            $clean[] = rtrim( (string) $ln, "\r\n" );
        }

        $blocks[ $i ] = implode( "\n", $clean );

        // Reassemble and overwrite the log file.
        $new_raw = implode( "\n\n", $blocks );

        // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
        @file_put_contents( $results_log, $new_raw . "\n", LOCK_EX );
        return;
    }

    // Fallback: if no matching block was found, append at the end.
    // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
    @file_put_contents( $results_log, implode( '', $lines ), FILE_APPEND | LOCK_EX );
}


/**
 * Server-side PSI fetch + Module 5 logging for scheduled tests.
 *
 * Writes:
 *   Module 5 Mobile: Performance: X, LCP: Y, FCP: Z, CLS: W, INP: Z
 *   Module 5 Desktop: Performance: X, LCP: Y, FCP: Z, CLS: W, INP: Z
 *
 * Also appends a compact JSONL entry per strategy to:
 *   /uploads/speed-analyzer/PSI-ss-scheduled.log
 * keeping only the last 10 mobile + 10 desktop entries (20 lines total).
 *
 * @param string   $tested_url
 * @param string   $results_log
 * @param int|null $test_no     Optional Test # to attach to.
 */
function wpsa_schedule_log_module5( $tested_url, $results_log, $test_no = null ) {
    $tested_url = esc_url_raw( $tested_url );

    if ( '' === $tested_url ) {
        return;
    }

    if ( ! function_exists( 'wpsa_get_worker_endpoint' ) ) {
        // Safety: worker helper missing.
        return;
    }

    // Prepare scheduled SS log path once (per function call).
    $scheduled_ss_log = '';
    $upload           = wp_upload_dir();
    if ( empty( $upload['error'] ) ) {
        $dir = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $scheduled_ss_log = trailingslashit( $dir ) . 'PSI-ss-scheduled.log';
    }

    $strategies    = array( 'mobile', 'desktop' );
    // Scheduled runs: one long try per strategy, then fall back to N/A.
    $max_retries   = 1;
    $delay_seconds = 0;

    // Safe PSI timeout derived from PHP max_execution_time.
    $max_exec = (int) ini_get( 'max_execution_time' );
    if ( $max_exec <= 0 ) {
        // 0 = "no limit" on some hosts, clamp to a sane default.
        $max_exec = 300;
    }

        // Start with "max_exec - 30s safety", keep within [20, 45] seconds.
        $psi_timeout = $max_exec - 30;
        if ( $psi_timeout < 20 ) {
            $psi_timeout = 20;
        } elseif ( $psi_timeout > 45 ) {
            $psi_timeout = 45;
        }
    
        /**
         * Filter the PSI timeout (in seconds) used for scheduled Module 5 runs.
         *
         * @param int $psi_timeout Calculated timeout in seconds.
         */
        $psi_timeout = (int) apply_filters( 'wpsa_schedule_psi_timeout', $psi_timeout );
    
        $out = array();
        
        // Keep raw PSI responses so we can log diagnostics JSON (psi-diag-log.txt) for scheduled runs.
        $psi_raw = array(
            'mobile'  => array(),
            'desktop' => array(),
        );

    
        foreach ( $strategies as $strat ) {
            // Defaults: everything N/A unless we get valid data.
            $out[ $strat ] = array(
                'perf' => 'N/A',
                'lcp'  => 'N/A',
                'fcp'  => 'N/A',
                'cls'  => 'N/A',
                'inp'  => 'N/A',
            );
    
            $endpoint = add_query_arg(
                array(
                    'psi_url'  => rawurlencode( $tested_url ),
                    'strategy' => $strat,
                    // also let the Worker fetch HTML once, same as Module 2.
                    'url'      => rawurlencode( $tested_url ),
                ),
                wpsa_get_worker_endpoint() . 'psi'
            );
    
            $attempt = 0;
            $delay   = $delay_seconds;
            $resp    = null;
    
            do {
                if ( $attempt > 0 ) {
                    sleep( $delay );
                    $delay *= 2;
                }
    
                $resp = wp_remote_get(
                    $endpoint,
                    array(
                        'timeout' => $psi_timeout,
                    )
                );
    
                $code   = is_wp_error( $resp ) ? 0 : wp_remote_retrieve_response_code( $resp );
                $attempt++;
            } while ( ( is_wp_error( $resp ) || 429 === $code ) && $attempt < $max_retries );
    
            if ( is_wp_error( $resp ) || 200 !== $code ) {
                // Leave this strategy as N/A.
                continue;
            }
    
            $body = wp_remote_retrieve_body( $resp );
            $data = json_decode( $body, true );
            if ( ! is_array( $data ) || empty( $data['lighthouseResult'] ) ) {
                continue;
            }

            // Keep raw Worker JSON so we can build diagnostics/insights later (psi-diag-log.txt).
            $psi_raw[ $strat ] = $data;

            $lh = $data['lighthouseResult'];


            // --- Scheduled SS log: PSI-ss-scheduled.log (last 5 mobile + 5 desktop) ---
            if ( $scheduled_ss_log ) {
                // Prefer Worker field screenshot_data_url; fall back to psi_screenshot.
                $psi_shot = '';
                if ( ! empty( $data['screenshot_data_url'] ) && is_string( $data['screenshot_data_url'] ) ) {
                    $psi_shot = $data['screenshot_data_url'];
                } elseif ( ! empty( $data['psi_screenshot'] ) && is_string( $data['psi_screenshot'] ) ) {
                    $psi_shot = $data['psi_screenshot'];
                }

                $entry = array(
                    'test'           => $test_no ? (int) $test_no : 0, // test number used by "Load test #"
                    'tested_url'     => $tested_url,
                    'strategy'       => $strat,                       // mobile / desktop
                    'scheduled'      => true,
                    'ts'             => gmdate( 'c' ),
                    'psi_metrics'    => $data['psi_metrics'] ?? null, // optional metrics blob
                    'assets'         => $data['assets']      ?? null, // optional assets blob
                    'psi_screenshot' => $psi_shot,                    // full data:image/...;base64,...
                );

                $line = wp_json_encode( $entry );
                if ( $line ) {
                    $lines = array();

                    if ( file_exists( $scheduled_ss_log ) && is_readable( $scheduled_ss_log ) ) {
                        // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
                        $old = @file( $scheduled_ss_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
                        if ( is_array( $old ) ) {
                            $lines = $old;
                        }
                    }

                    $lines[] = $line;

                    // Keep at most 10 entries total (≈10 tests: 5 mobile + 5 desktop).
                    if ( count( $lines ) > 10 ) {
                        $lines = array_slice( $lines, -10 );
                    }

                    // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
                    @file_put_contents( $scheduled_ss_log, implode( "\n", $lines ) . "\n", LOCK_EX );
                }
            }

    
            // Performance score (0–1 → 0–100)
            $perf_raw = $lh['categories']['performance']['score'] ?? null;
            if ( is_numeric( $perf_raw ) ) {
                $out[ $strat ]['perf'] = (string) (int) round( (float) $perf_raw * 100 );
            }
    
            // Helper to turn ms → seconds with 1 decimal (dot decimal for JS regex).
            $fmt_sec = static function ( $val ) {
                if ( ! is_numeric( $val ) || $val <= 0 ) {
                    return 'N/A';
                }
                $sec = (float) $val / 1000;
    
                // Use dot as decimal separator to match JS: [0-9.]+
                return sprintf( '%.1f', $sec );
            };
    
            // LCP / FCP (ms → s)
            $lcp_raw = $lh['audits']['largest-contentful-paint']['numericValue'] ?? null;
            $fcp_raw = $lh['audits']['first-contentful-paint']['numericValue'] ?? null;
    
            $out[ $strat ]['lcp'] = $fmt_sec( $lcp_raw );
            $out[ $strat ]['fcp'] = $fmt_sec( $fcp_raw );
    
            // CLS (float)
            $cls_raw = $lh['audits']['cumulative-layout-shift']['numericValue'] ?? null;
            if ( is_numeric( $cls_raw ) ) {
                // Also force dot-decimal here.
                $out[ $strat ]['cls'] = sprintf( '%.3f', (float) $cls_raw );
            }
    
            // INP (ms) – handle multiple PSI shapes (lab + field data fallbacks).
            $inp_raw = null;
    
            // 1) Primary: lab audit numericValue (most common).
            if ( isset( $lh['audits']['experimental-interaction-to-next-paint']['numericValue'] )
                && is_numeric( $lh['audits']['experimental-interaction-to-next-paint']['numericValue'] )
            ) {
                $inp_raw = $lh['audits']['experimental-interaction-to-next-paint']['numericValue'];
            } elseif ( isset( $lh['audits']['interaction-to-next-paint']['numericValue'] )
                && is_numeric( $lh['audits']['interaction-to-next-paint']['numericValue'] )
            ) {
                $inp_raw = $lh['audits']['interaction-to-next-paint']['numericValue'];
            }
    
            // 2) Fallback: audit details.items[0].metricValue (some PSI variants).
            if ( null === $inp_raw ) {
                if ( isset( $lh['audits']['experimental-interaction-to-next-paint']['details']['items'][0]['metricValue'] )
                    && is_numeric( $lh['audits']['experimental-interaction-to-next-paint']['details']['items'][0]['metricValue'] )
                ) {
                    $inp_raw = $lh['audits']['experimental-interaction-to-next-paint']['details']['items'][0]['metricValue'];
                } elseif ( isset( $lh['audits']['interaction-to-next-paint']['details']['items'][0]['metricValue'] )
                    && is_numeric( $lh['audits']['interaction-to-next-paint']['details']['items'][0]['metricValue'] )
                ) {
                    $inp_raw = $lh['audits']['interaction-to-next-paint']['details']['items'][0]['metricValue'];
                }
            }
    
            // 3) Fallback: field data (loadingExperience / originLoadingExperience).
            if ( null === $inp_raw && isset( $data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] )
                && is_numeric( $data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] )
            ) {
                $inp_raw = $data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'];
            } elseif ( null === $inp_raw && isset( $data['originLoadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] )
                && is_numeric( $data['originLoadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] )
            ) {
                $inp_raw = $data['originLoadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'];
            }
    
            if ( is_numeric( $inp_raw ) && $inp_raw > 0 ) {
                // Keep same units as manual Module 5: ms → integer ms.
                $out[ $strat ]['inp'] = (string) (int) round( (float) $inp_raw );
            }
        }
    
        // Build two log lines (Mobile / Desktop) just like interactive runs.
        $lines  = array();
        $labels = array(
            'mobile'  => 'Mobile',
            'desktop' => 'Desktop',
        );
    
                foreach ( $labels as $key => $label ) {
            $m = $out[ $key ];

            // Module 5 summary line (same format as manual runs).
            $lines[] = sprintf(
                "Module 5 %s: Performance: %s, LCP: %s, FCP: %s, CLS: %s, INP: %s\n",
                $label,
                $m['perf'],
                $m['lcp'],
                $m['fcp'],
                $m['cls'],
                $m['inp']
            );

            // CWV (field) line for scheduled runs (URL/page preferred; Origin fallback).
            if (
                function_exists( 'wpsa_build_cwv_log_lines' )
                && ! empty( $psi_raw[ $key ] )
                && is_array( $psi_raw[ $key ] )
            ) {
                $built = wpsa_build_cwv_log_lines( $psi_raw[ $key ], $label );

                if ( is_array( $built ) && ! empty( $built['summary'] ) && $built['summary'] !== 'N/A' ) {
                    $summary = (string) $built['summary'];

                    // If assessment is N/A, keep as-is.
                    if ( stripos( $summary, 'Assessment: N/A' ) === false ) {
                        $use_origin = false;

                        // Respect origin_fallback if present in builder RAW.
                        if ( isset( $built['raw'] ) && is_array( $built['raw'] ) && ! empty( $built['raw']['origin_fallback'] ) ) {
                            $use_origin = true;
                        } elseif ( function_exists( 'wpsa_cwv_should_use_origin_scope' ) ) {
                            $use_origin = wpsa_cwv_should_use_origin_scope( $psi_raw[ $key ] );
                        }

                        // We log as URL vs Origin (scheduled report expects these labels).
                        $scope_label = $use_origin ? 'Origin' : 'URL';

                        // Builder may output "Page" or "Origin" — normalize to URL/Origin.
                        $summary = preg_replace(
                            '/^\s*Module\s+5\s+CWV\s+(Page|URL|Origin)\s+\(/i',
                            'Module 5 CWV ' . $scope_label . ' (',
                            $summary
                        );
                    }

                    $lines[] = rtrim( $summary, "\r\n" ) . "\n";
                }
            }
        }

        if ( ! empty( $lines ) ) {
            // Attach Module 5 + CWV lines to the correct Test # block for this URL.
            wpsa_schedule_attach_module5_to_test( $results_log, $tested_url, $lines, $test_no );
        }



        // After summary lines are attached, also log diagnostics/insights JSON
        // for this scheduled run into psi-diag-log.txt as test "NNNS".
        if ( $test_no && function_exists( 'wpsa_log_scheduled_psi_diag' ) ) {
            $mobile_raw  = ( ! empty( $psi_raw['mobile'] )  && is_array( $psi_raw['mobile'] ) )  ? $psi_raw['mobile']  : array();
            $desktop_raw = ( ! empty( $psi_raw['desktop'] ) && is_array( $psi_raw['desktop'] ) ) ? $psi_raw['desktop'] : array();

            // Only log if at least one side returned valid PSI data.
            if ( ! empty( $mobile_raw ) || ! empty( $desktop_raw ) ) {
                wpsa_log_scheduled_psi_diag(
                    (int) $test_no,
                    $tested_url,
                    $mobile_raw,
                    $desktop_raw
                );
            }
        }
    }




/**
 * Parse ttfb-results-log.txt and return Module 1 + Module 5 data keyed by test number.
 *
 * More robust version: walks the log line-by-line and treats every "Test #N ..."
 * header as the start of a new test, instead of assuming tests are separated
 * by blank lines. This avoids situations where multiple tests end up in
 * a single block and later ones (like your Test #4) get ignored.
 *
 * @param string $results_log
 * @return array [ test_no => [ 'version' => ..., 'datetime' => ..., 'tier' => ..., 'url' => ..., ... ] ]
 */
function wpsa_schedule_parse_tests_from_log( $results_log ) {
    $tests = array();

    if ( ! $results_log || ! file_exists( $results_log ) || ! is_readable( $results_log ) ) {
        return $tests;
    }

    // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
    $raw = @file_get_contents( $results_log );
    if ( '' === $raw || false === $raw ) {
        return $tests;
    }

    $lines = preg_split( "/\R/", $raw );
    if ( ! is_array( $lines ) || empty( $lines ) ) {
        return $tests;
    }

    $current      = null;
    $current_no   = null;

    foreach ( $lines as $ln_raw ) {
        $ln = trim( (string) $ln_raw );
        if ( '' === $ln ) {
            // Blank line – nothing to do, we no longer rely on it as a separator.
            continue;
        }

        // New test header?
        if ( preg_match(
            '/^Test\s+#(\d+)(?:\s+[A-Z])?\s*\|\s*([^\|]+)\|\s*([^\|]+)\s*\|\s*Tier:\s*([^\|]+)\|\s*URL:\s*([^\|]+)\|\s*Region:\s*([^\|]+)\|\s*Cache:\s*([^\|]+)\|\s*WP Rocket:\s*([^\|]+)\|\s*TTFB:\s*([0-9]+|N\/A)\s*ms/i',
            $ln,
            $m
        ) ) {
            // If we were already collecting a test, commit it before starting a new one.
            if ( null !== $current_no && is_array( $current ) ) {
                $tests[ $current_no ] = $current;
            }

            $current_no = (int) $m[1];

            $current = array(
                'test_no'     => $current_no,
                'version'     => trim( $m[2] ),
                'datetime'    => trim( $m[3] ), // ISO-like string "2025-11-18T10:11:05+00:00"
                'tier'        => trim( $m[4] ),
                'url'         => trim( $m[5] ),
                'region'      => trim( $m[6] ),
                'cache'       => trim( $m[7] ),
                'opt_plugin'  => trim( $m[8] ), // "Yes" / "No" etc.
                'ttfb_ms'     => ( 'N/A' === strtoupper( trim( $m[9] ) ) ) ? 'N/A' : (int) $m[9],
                'daily_used'  => null,
                'daily_limit' => null,

                // Module 5 placeholders
                'm5m_perf'    => null,
                'm5m_lcp'     => null,
                'm5m_fcp'     => null,
                'm5m_cls'     => null,
                'm5m_inp'     => null,
                'm5d_perf'    => null,
                'm5d_lcp'     => null,
                'm5d_fcp'     => null,
                'm5d_cls'     => null,
                'm5d_inp'     => null,
                
                // CWV (field) placeholders (scope + assessment).
                'cwv_mobile_scope'              => null, // URL | Origin
                'cwv_desktop_scope'             => null, // URL | Origin
                'cwv_mobile_assessment'         => null, // PASSED | FAILED | N/A
                'cwv_desktop_assessment'        => null, // PASSED | FAILED | N/A
                
                // Back-compat (older email logic may still read these).
                'cwv_origin_mobile_assessment'  => null,
                'cwv_origin_desktop_assessment' => null,
            );

            // Daily usage "Daily: 10/700" if present on header.
            if ( preg_match( '/Daily:\s*([0-9]+)\/([0-9]+)/', $ln, $d ) ) {
                $current['daily_used']  = (int) $d[1];
                $current['daily_limit'] = (int) $d[2];
            }

            continue;
        }

        // If we don't have an active test yet, ignore any stray lines.
        if ( null === $current_no || ! is_array( $current ) ) {
            continue;
        }

        // Module 5 Mobile line.
        if ( preg_match(
            '/^Module\s+5\s+Mobile:\s*Performance:\s*(\d+|N\/A)\s*,\s*LCP:\s*(N\/A|[0-9.]+)\s*,\s*FCP:\s*(N\/A|[0-9.]+)\s*,\s*CLS:\s*(N\/A|[0-9.]+)\s*,\s*INP:\s*(N\/A|[0-9]+)\s*$/i',
            $ln,
            $mm
        ) ) {
            $current['m5m_perf'] = strtoupper( $mm[1] ) === 'N/A' ? 'N/A' : (int) $mm[1];
            $current['m5m_lcp']  = strtoupper( $mm[2] ) === 'N/A' ? 'N/A' : $mm[2];
            $current['m5m_fcp']  = strtoupper( $mm[3] ) === 'N/A' ? 'N/A' : $mm[3];
            $current['m5m_cls']  = strtoupper( $mm[4] ) === 'N/A' ? 'N/A' : $mm[4];
            $current['m5m_inp']  = strtoupper( $mm[5] ) === 'N/A' ? 'N/A' : (int) $mm[5];
            continue;
        }

        // Module 5 Desktop line.
        if ( preg_match(
            '/^Module\s+5\s+Desktop:\s*Performance:\s*(\d+|N\/A)\s*,\s*LCP:\s*(N\/A|[0-9.]+)\s*,\s*FCP:\s*(N\/A|[0-9.]+)\s*,\s*CLS:\s*(N\/A|[0-9.]+)\s*,\s*INP:\s*(N\/A|[0-9]+)\s*$/i',
            $ln,
            $mm
        ) ) {
            $current['m5d_perf'] = strtoupper( $mm[1] ) === 'N/A' ? 'N/A' : (int) $mm[1];
            $current['m5d_lcp']  = strtoupper( $mm[2] ) === 'N/A' ? 'N/A' : $mm[2];
            $current['m5d_fcp']  = strtoupper( $mm[3] ) === 'N/A' ? 'N/A' : $mm[3];
            $current['m5d_cls']  = strtoupper( $mm[4] ) === 'N/A' ? 'N/A' : $mm[4];
            $current['m5d_inp']  = strtoupper( $mm[5] ) === 'N/A' ? 'N/A' : (int) $mm[5];
            continue;
        }
        
       // CWV (Mobile) line (URL/Origin/Page) – keep scope + assessment.
        if ( preg_match(
            '/^Module\s+5\s+CWV\s+(URL|Origin|Page)\s+\(Mobile\):\s*Assessment:\s*(PASSED|FAILED|N\/A)\b/i',
            $ln,
            $cm
        ) ) {
            $raw_scope = strtoupper( (string) $cm[1] );
            $scope     = ( 'ORIGIN' === $raw_scope ) ? 'Origin' : 'URL'; // treat Page as URL for email consistency
            $assess    = strtoupper( (string) $cm[2] );
        
            $current['cwv_mobile_scope']      = $scope;
            $current['cwv_mobile_assessment'] = $assess;
        
            // Back-compat: only populate origin_* when scope is Origin.
            if ( 'Origin' === $scope ) {
                $current['cwv_origin_mobile_assessment'] = $assess;
            }
        
            continue;
        }



        // CWV (Desktop) line (URL/Origin/Page) – keep scope + assessment.
        if ( preg_match(
            '/^Module\s+5\s+CWV\s+(URL|Origin|Page)\s+\(Desktop\):\s*Assessment:\s*(PASSED|FAILED|N\/A)\b/i',
            $ln,
            $cd
        ) ) {
            $raw_scope = strtoupper( (string) $cd[1] );
            $scope     = ( 'ORIGIN' === $raw_scope ) ? 'Origin' : 'URL'; // treat Page as URL
            $assess    = strtoupper( (string) $cd[2] );
        
            $current['cwv_desktop_scope']      = $scope;
            $current['cwv_desktop_assessment'] = $assess;
        
            // Back-compat: only populate origin_* when scope is Origin.
            if ( 'Origin' === $scope ) {
                $current['cwv_origin_desktop_assessment'] = $assess;
            }
        
            continue;
        }



        // Other lines (Modules 2–4 etc.) are irrelevant for the schedule email and are skipped.
    }

    // Commit the final test if there is one.
    if ( null !== $current_no && is_array( $current ) ) {
        $tests[ $current_no ] = $current;
    }

    return $tests;
}

function wpsa_schedule_norm_url( $url ) {
	$url = is_string( $url ) ? trim( $url ) : '';
	if ( $url === '' ) {
		return '';
	}

	// Prefer plugin's URL normalizer if present.
	if ( function_exists( 'wpsa_normalize_url' ) ) {
		$norm = wpsa_normalize_url( $url );
		return is_string( $norm ) ? $norm : $url;
	}

	// Fallback normalizer: scheme+host+path, lowercase host, trailing slash.
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return rtrim( $url, '/' ) . '/';
	}

	$scheme = ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
	$host   = strtolower( $parts['host'] );
	$path   = ! empty( $parts['path'] ) ? $parts['path'] : '/';

	$norm = $scheme . '://' . $host . $path;
	return rtrim( $norm, '/' ) . '/';
}

function wpsa_schedule_parse_results_log_latest_prev( $log_path, $current_test_id, $target_url_norm ) {
	if ( ! is_string( $log_path ) || $log_path === '' || ! file_exists( $log_path ) || ! is_readable( $log_path ) ) {
		return array();
	}

	$current_test_id = (int) $current_test_id;
	if ( $current_test_id <= 0 ) {
		return array();
	}

	$target_url_norm = wpsa_schedule_norm_url( $target_url_norm );
	if ( $target_url_norm === '' ) {
		return array();
	}

	// Reuse the robust parser that already understands your log structure.
	$tests_map = wpsa_schedule_parse_tests_from_log( $log_path );
	if ( empty( $tests_map ) || ! is_array( $tests_map ) ) {
		return array();
	}

	// Find the latest test for the SAME normalized URL where test_no < current_test_id.
	$best_id   = 0;
	$best_test = null;

	foreach ( $tests_map as $tid => $t ) {
		$tid = (int) $tid;
		if ( $tid <= 0 || $tid >= $current_test_id ) {
			continue;
		}
		if ( ! is_array( $t ) ) {
			continue;
		}

		$u = isset( $t['url'] ) ? wpsa_schedule_norm_url( (string) $t['url'] ) : '';
		if ( '' === $u || $u !== $target_url_norm ) {
			continue;
		}

		// Keep the most recent previous test.
		if ( $tid > $best_id ) {
			$best_id   = $tid;
			$best_test = $t;
		}
	}

	if ( ! $best_id || ! is_array( $best_test ) ) {
		return array();
	}

	// Build the exact structure your alert logic expects (strings, like your old parser).
	$data = array(
		'test_id' => $best_id,
		'url'     => $target_url_norm,
		'ttfb_ms' => null,
		'mobile'  => array(),
		'desktop' => array(),
	);

	if ( isset( $best_test['ttfb_ms'] ) && 'N/A' !== $best_test['ttfb_ms'] && is_numeric( $best_test['ttfb_ms'] ) ) {
		$data['ttfb_ms'] = (float) $best_test['ttfb_ms'];
	}

	// Mobile.
	$data['mobile'] = array(
		'performance' => ( isset( $best_test['m5m_perf'] ) && null !== $best_test['m5m_perf'] ) ? (string) $best_test['m5m_perf'] : 'N/A',
		'lcp'         => ( isset( $best_test['m5m_lcp'] )  && null !== $best_test['m5m_lcp'] )  ? (string) $best_test['m5m_lcp']  : 'N/A',
		'fcp'         => ( isset( $best_test['m5m_fcp'] )  && null !== $best_test['m5m_fcp'] )  ? (string) $best_test['m5m_fcp']  : 'N/A',
		'cls'         => ( isset( $best_test['m5m_cls'] )  && null !== $best_test['m5m_cls'] )  ? (string) $best_test['m5m_cls']  : 'N/A',
		'inp'         => ( isset( $best_test['m5m_inp'] )  && null !== $best_test['m5m_inp'] )  ? (string) $best_test['m5m_inp']  : 'N/A',
	);

	// Desktop.
	$data['desktop'] = array(
		'performance' => ( isset( $best_test['m5d_perf'] ) && null !== $best_test['m5d_perf'] ) ? (string) $best_test['m5d_perf'] : 'N/A',
		'lcp'         => ( isset( $best_test['m5d_lcp'] )  && null !== $best_test['m5d_lcp'] )  ? (string) $best_test['m5d_lcp']  : 'N/A',
		'fcp'         => ( isset( $best_test['m5d_fcp'] )  && null !== $best_test['m5d_fcp'] )  ? (string) $best_test['m5d_fcp']  : 'N/A',
		'cls'         => ( isset( $best_test['m5d_cls'] )  && null !== $best_test['m5d_cls'] )  ? (string) $best_test['m5d_cls']  : 'N/A',
		'inp'         => ( isset( $best_test['m5d_inp'] )  && null !== $best_test['m5d_inp'] )  ? (string) $best_test['m5d_inp']  : 'N/A',
	);

	return $data;
}


function wpsa_schedule_metric_to_float( $raw, $metric_key ) {
	$raw = is_string( $raw ) ? trim( $raw ) : '';

	if ( $raw === '' || strtoupper( $raw ) === 'N/A' || $raw === '--' ) {
		return null;
	}

	// Strip non-numeric chars except dot.
	$val = preg_replace( '/[^0-9.]+/', '', $raw );
	if ( $val === '' ) {
		return null;
	}

	$f = (float) $val;

	// Performance is 0-100, others as numeric.
	// No unit conversion here because log is in seconds for LCP/FCP and raw for CLS, INP N/A.
	return $f;
}

function wpsa_schedule_is_worse( $metric_key, $current, $previous ) {
	$metric_key = strtolower( (string) $metric_key );

	// For performance score: lower is worse.
	if ( $metric_key === 'performance' ) {
		return $current < $previous;
	}

	// For TTFB/LCP/FCP/CLS/INP: higher is worse.
	return $current > $previous;
}

function wpsa_schedule_regression_triggered( $metric_key, $percent, $current, $previous ) {
	$percent = (float) $percent;
	if ( $percent <= 0 ) {
		return false;
	}

	$metric_key = strtolower( (string) $metric_key );

	// For performance: regression means drop by >= percent.
	if ( $metric_key === 'performance' ) {
		$threshold = $previous * ( 1 - ( $percent / 100 ) );
		return $current <= $threshold;
	}

	// For timing metrics: regression means increase by >= percent.
	$threshold = $previous * ( 1 + ( $percent / 100 ) );
	return $current >= $threshold;
}

function wpsa_schedule_threshold_triggered( $metric_key, $current, $threshold_value ) {
	$metric_key = strtolower( (string) $metric_key );
	$threshold_value = (float) $threshold_value;

	if ( $threshold_value <= 0 ) {
		return false;
	}

	// For performance: trigger if below threshold_value (if you ever add performance to dropdown).
	if ( $metric_key === 'performance' ) {
		return $current <= $threshold_value;
	}

	// Others: trigger if above threshold_value.
	return $current >= $threshold_value;
}



/**
 * Build and send a single HTML email with all scheduled tests (Module 1 + 5 only).
 *
 * @param array  $batch       Batch array from wpsa_run_scheduled_tests().
 * @param array  $settings    Schedule settings (wpsa_schedule_settings).
 * @param string $results_log Path to ttfb-results-log.txt.
 */
function wpsa_schedule_send_batch_email( $batch, $settings, $results_log ) {
    $alerts_enabled = ( ! empty( $settings['alert_reg_enable'] ) || ! empty( $settings['alert_thresh_enable'] ) );

    // Normal summary emails require "notify".
    // Alert-triggered emails should be allowed even when "notify" is intentionally disabled.
    if ( empty( $settings['notify'] ) && ! $alerts_enabled ) {
        return false;
    }

    $to = isset( $settings['notify_email'] ) ? sanitize_email( $settings['notify_email'] ) : '';
    if ( '' === $to || ! is_email( $to ) ) {
        return;
    }


       if ( empty( $batch['items'] ) || ! is_array( $batch['items'] ) ) {
        return;
    }

    $items = $batch['items'];

    // Only build screenshots for tests that belong to THIS batch.
    $allowed_test_nos = array();
    foreach ( $items as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        if ( ! empty( $row['test_no'] ) ) {
            $allowed_test_nos[ (int) $row['test_no'] ] = true;
        }
    }

    // Site timezone + date/time format.
    $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

    $ran_at = isset( $batch['ran_at'] ) ? (int) $batch['ran_at'] : time();

    if ( function_exists( 'wp_date' ) ) {
        $batch_local = wp_date( $format, $ran_at );
    } else {
        // Fallback for very old WP.
        $batch_local = date_i18n( $format, $ran_at );
    }
    
    $batch_utc = gmdate( $format, $ran_at );

    $tests_map = wpsa_schedule_parse_tests_from_log( $results_log );

    // Scheduled PSI screenshot log: map [test_no][strategy] => data:image/...;base64,... .
       $scheduled_ss_map   = array();
    $ss_slots_remaining = 5; // Only the first 5 tests in the email get screenshots.

    $upload_dir = wp_upload_dir();
    if ( empty( $upload_dir['error'] ) ) {
        $ss_dir     = trailingslashit( $upload_dir['basedir'] ) . 'speed-analyzer';
        $ss_url_dir = trailingslashit( $upload_dir['baseurl'] ) . 'speed-analyzer';
        $ss_file    = trailingslashit( $ss_dir ) . 'PSI-ss-scheduled.log';

        if ( ! is_dir( $ss_dir ) ) {
            wp_mkdir_p( $ss_dir );
        }

        if ( file_exists( $ss_file ) && is_readable( $ss_file ) ) {
            // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
            $ss_lines = @file( $ss_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
              if ( is_array( $ss_lines ) ) {
                foreach ( $ss_lines as $line ) {
                    $entry = json_decode( $line, true );
                    if ( ! is_array( $entry ) ) {
                        continue;
                    }

                    // Support both "test" and legacy "test_no".
                    $t_no = 0;
                    if ( isset( $entry['test'] ) ) {
                        $t_no = (int) $entry['test'];
                    } elseif ( isset( $entry['test_no'] ) ) {
                        $t_no = (int) $entry['test_no'];
                    }
                    if ( $t_no <= 0 ) {
                        continue;
                    }

                    // NEW: only create screenshots for tests that belong to this batch.
                    if ( empty( $allowed_test_nos[ $t_no ] ) ) {
                        continue;
                    }

                    $strategy = isset( $entry['strategy'] ) ? strtolower( (string) $entry['strategy'] ) : '';
                    if ( '' === $strategy ) {
                        continue;
                    }

                    // Find a screenshot field we recognise.
                    $shot = '';
                    if ( ! empty( $entry['psi_screenshot'] ) && is_string( $entry['psi_screenshot'] ) ) {
                        $shot = $entry['psi_screenshot'];
                    } elseif ( ! empty( $entry['screenshot_data_url'] ) && is_string( $entry['screenshot_data_url'] ) ) {
                        $shot = $entry['screenshot_data_url'];
                    } elseif ( ! empty( $entry['ss'] ) && is_string( $entry['ss'] ) ) {
                        $shot = $entry['ss'];
                    }

                    if ( '' === $shot ) {
                        continue;
                    }

                    // Convert data:image/... base64 screenshots into real files so email clients can load them.
                    if (
                        0 === strpos( $shot, 'data:image/' )
                        && preg_match( '#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#', $shot, $m )
                    ) {
                        $mime_sub = strtolower( $m[1] );
                        $data_b64 = $m[2];

                        $ext = 'jpg';
                        if ( false !== strpos( $mime_sub, 'png' ) ) {
                            $ext = 'png';
                        } elseif ( false !== strpos( $mime_sub, 'webp' ) ) {
                            $ext = 'webp';
                        }

                        $file_name = sprintf(
                            'psi-ss-scheduled-%d-%s.%s',
                            $t_no,
                            preg_replace( '/[^a-z0-9]+/i', '-', $strategy ),
                            $ext
                        );

                        $file_path = trailingslashit( $ss_dir ) . $file_name;
                        $file_url  = trailingslashit( $ss_url_dir ) . $file_name;

                        if ( ! file_exists( $file_path ) || 0 === filesize( $file_path ) ) {
                            $bin = base64_decode( $data_b64 );
                            if ( $bin ) {
                                // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
                                @file_put_contents( $file_path, $bin, LOCK_EX );
                            }
                        }

                        $shot = $file_url;
                    }

                    if ( ! isset( $scheduled_ss_map[ $t_no ] ) ) {
                        $scheduled_ss_map[ $t_no ] = array();
                    }
                    $scheduled_ss_map[ $t_no ][ $strategy ] = $shot;
                }
            }

        }
    }


    $site_url  = home_url( '/' );
    $count     = count( $items );


       // Subject: Speed Analyzer – scheduled test results (X tests on {local datetime})
    $subject = sprintf(
        /* translators: 1: number of tests in the batch, 2: local date and time the batch ran. */
        __( 'Speed Analyzer – scheduled test results (%1$d test(s) on %2$s)', 'speed-analyzer' ),
        $count,
        $batch_local
    );

        // Helper closure for performance gauge – HTML/CSS only (email-safe).
    $perf_circle = static function ( $score ) {
        if ( ! is_numeric( $score ) ) {
            return '<span style="display:inline-block;min-width:32px;text-align:center;font-size:12px;color:#666;">N/A</span>';
        }

        $score_int = (int) $score;
        if ( $score_int < 0 ) {
            $score_int = 0;
        } elseif ( $score_int > 100 ) {
            $score_int = 100;
        }

        // Colors similar to frontend Perf & diagnostics gauge.
        if ( $score_int >= 90 ) {
            $border_color = '#1a7f37'; // green
            $fill_color   = '#e6f4ea';
        } elseif ( $score_int >= 50 ) {
            $border_color = '#e3b341'; // yellow
            $fill_color   = '#fff8e1';
        } else {
            $border_color = '#d1242f'; // red
            $fill_color   = '#ffebee';
        }

        $border_color_esc = esc_attr( $border_color );
        $fill_color_esc   = esc_attr( $fill_color );
        $score_text       = (string) $score_int;

        // 44×44 circle, centered text, email-safe.
        return '<span style="display:inline-block;width:44px;height:44px;border-radius:50%;'
            . 'background-color:' . $fill_color_esc . ';'
            . 'border:3px solid ' . $border_color_esc . ';'
            . 'text-align:center;line-height:44px;'
            . 'font-size:14px;font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;'
            . 'color:' . $border_color_esc . ';vertical-align:middle;">'
            . esc_html( $score_text )
            . '</span>';
    };

    // Helper for metric value colouring (LCP/FCP/CLS/INP).
    $metric_style = static function ( $metric, $raw ) {
        if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
            return '';
        }

        $raw = trim( (string) $raw );
        if ( '' === $raw || strtoupper( $raw ) === 'N/A' ) {
            return '';
        }

        if ( ! is_numeric( $raw ) ) {
            return '';
        }

        $v = (float) $raw;

        $green  = '#1a7f37';
        $amber  = '#e3b341';
        $red    = '#d1242f';
        $color  = $green;

        switch ( $metric ) {
            case 'lcp':
                // Good <= 2.5s, NI <= 4.0s, poor > 4.0s.
                if ( $v <= 2.5 ) {
                    $color = $green;
                } elseif ( $v <= 4.0 ) {
                    $color = $amber;
                } else {
                    $color = $red;
                }
                break;

            case 'fcp':
                // Good <= 1.8s, NI <= 3.0s, poor > 3.0s.
                if ( $v <= 1.8 ) {
                    $color = $green;
                } elseif ( $v <= 3.0 ) {
                    $color = $amber;
                } else {
                    $color = $red;
                }
                break;

            case 'cls':
                // Good <= 0.1, NI <= 0.25, poor > 0.25.
                if ( $v <= 0.10 ) {
                    $color = $green;
                } elseif ( $v <= 0.25 ) {
                    $color = $amber;
                } else {
                    $color = $red;
                }
                break;

            case 'inp':
                // Good <= 200ms, NI <= 500ms, poor > 500ms.
                if ( $v <= 200 ) {
                    $color = $green;
                } elseif ( $v <= 500 ) {
                    $color = $amber;
                } else {
                    $color = $red;
                }
                break;

            default:
                $color = $green;
        }

        return 'color:' . esc_attr( $color ) . ';font-weight:bold;';
    };

    // Helper for TTFB colouring (Module 1).
    $ttfb_style = static function ( $raw_ms ) {
        if ( 'N/A' === $raw_ms || ! is_numeric( $raw_ms ) ) {
            return '';
        }

        $ms    = (int) $raw_ms;
        $green = '#1a7f37';
        $amber = '#e3b341';
        $red   = '#d1242f';
        $color = $green;

        // Thresholds aligned with frontend Module 1 gauge style.
        if ( $ms <= 300 ) {
            $color = $green;
        } elseif ( $ms <= 500 ) {
            $color = $amber;
        } else {
            $color = $red;
        }

        return 'color:' . esc_attr( $color ) . ';font-weight:bold;';
    };

    // Helper for cache status colouring (HIT/MISS/BYPASS/etc.).
    $cache_style = static function ( $status_raw ) {
        if ( ! is_string( $status_raw ) || '' === $status_raw ) {
            return '';
        }

        $status = strtoupper( trim( $status_raw ) );

        $green = '#1a7f37';
        $amber = '#e3b341';
        $red   = '#d1242f';
        $color = $amber;

        if ( false !== strpos( $status, 'HIT' ) ) {
            $color = $green;
        } elseif ( false !== strpos( $status, 'MISS' ) || false !== strpos( $status, 'BYPASS' ) ) {
            $color = $red;
        }

        return 'color:' . esc_attr( $color ) . ';font-weight:bold;';
    };

    // HTML body.
    $body  = '';
    $body .= '<html><body>';
    
    //
    // Intro line with site URL.
    //
    $body .= '<p>Website <a href="' . esc_url( $site_url ) . '" target="_blank">' . esc_html( $site_url ) . '</a> asked the Speed Analyzer plugin to run scheduled tests and send the results to this email.</p>';
    $body .= '<p>' . esc_html__( 'Here are the results for the latest scheduled batch:', 'speed-analyzer' ) . '</p>';

    $body .= '<p>';
    $body .= esc_html__( 'Batch time (site timezone): ', 'speed-analyzer' ) . esc_html( $batch_local ) . '<br />';
    $body .= esc_html__( 'Batch time (UTC): ', 'speed-analyzer' ) . esc_html( $batch_utc );
    $body .= '</p>';
    
    // Alerts header (only when triggered).
if ( ! empty( $batch['alerts_enabled'] ) && ! empty( $batch['alert_hits'] ) && is_array( $batch['alert_hits'] ) ) {
    $body .= '<div style="margin:14px 0 18px 0;padding:12px 14px;border:1px solid #f0c36d;background:#fff8e1;border-radius:8px;">';
    $body .= '<p style="margin:0 0 8px 0;font-weight:700;color:#7a5b00;">' . esc_html__( 'Alert triggered', 'speed-analyzer' ) . '</p>';
    $body .= '<p style="margin:0 0 10px 0;color:#4a3b00;font-size:13px;">' . esc_html__( 'This email was sent because at least one alert condition was met:', 'speed-analyzer' ) . '</p>';

    $body .= '<ul style="margin:0;padding-left:18px;">';

    foreach ( $batch['alert_hits'] as $hit_test_no => $hit ) {
        $hit_test_no = (int) $hit_test_no;

        $reasons = array();
        if ( is_array( $hit ) && ! empty( $hit['reasons'] ) && is_array( $hit['reasons'] ) ) {
            $reasons = $hit['reasons'];
        }

        if ( empty( $reasons ) ) {
            continue;
        }

        $body .= '<li style="margin:0 0 8px 0;">';
        /* translators: %d: test number. */
        $body .= '<strong>' . esc_html( sprintf( __( 'Test #%d', 'speed-analyzer' ), $hit_test_no ) ) . ':</strong><br />';

        foreach ( $reasons as $r ) {
            $body .= '<span style="font-size:13px;color:#4a3b00;">• ' . esc_html( (string) $r ) . '</span><br />';
        }

        $body .= '</li>';
    }

    $body .= '</ul>';
    $body .= '</div>';
}


    // Summary list.
    $body .= '<h3>' . esc_html__( 'Summary', 'speed-analyzer' ) . '</h3>';
    $body .= '<ul>';

    foreach ( $items as $item ) {
        $url     = isset( $item['url'] ) ? (string) $item['url'] : '';
        $success = ! empty( $item['success'] );
        $test_no = isset( $item['test_no'] ) ? (int) $item['test_no'] : 0;
        $message = isset( $item['message'] ) ? (string) $item['message'] : '';

        $url_safe = $url ? esc_url( $url ) : '';
        $url_text = $url ? $url : __( '(no URL logged)', 'speed-analyzer' );

        if ( $test_no > 0 && $url_safe ) {
            $label_html = sprintf(
                'Test #%1$d – <a href="%2$s" target="_blank" rel="noopener">%3$s</a>',
                $test_no,
                $url_safe,
                esc_html( $url_text )
            );
        } elseif ( $url_safe ) {
            $label_html = sprintf(
                '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
                $url_safe,
                esc_html( $url_text )
            );
        } else {
            $label_html = esc_html( $url_text );
        }

        $icon = $success ? '✅' : '❌';

        // Use red stop icon specifically for daily-limit failures.
        if ( ! $success && $message && false !== stripos( $message, 'daily limit' ) ) {
            $icon = '🛑';
        }

        $msg_full = $message
            ? $message
            : ( $success
                ? __( 'Test ran successfully. You can check the results in the Compare Results section, or load them by their test number.', 'speed-analyzer' )
                : __( 'Test failed (check details above).', 'speed-analyzer' )
            );

        $body .= '<li style="margin:0 0 8px 0;">' . $icon . ' ' . $label_html . '<br />'
            . '<span style="font-size:13px;color:#444;">' . esc_html( $msg_full ) . '</span>'
            . '</li>';
    }

    $body .= '</ul>';


    $body .= '<p>' . esc_html__( '*You can find more detailed scheduled test results in the Compare Results section of the Speed Analyzer plugin. Furthermore, you can load the test # (from the same website that scheduled this test) and generate a detailed PDF report for it.', 'speed-analyzer' ) . '</p>';

    $body .= '<hr />';

    // Per-test sections.
    foreach ( $items as $item ) {
        $url     = isset( $item['url'] ) ? $item['url'] : '';
        $success = ! empty( $item['success'] );
        $test_no = isset( $item['test_no'] ) ? (int) $item['test_no'] : null;
        $message = isset( $item['message'] ) ? $item['message'] : '';

        $test_heading = $test_no
            /* translators: %d: test number. */
            ? sprintf( __( 'Test #%d (scheduled)', 'speed-analyzer' ), $test_no )
            : __( 'Scheduled test (no test number logged)', 'speed-analyzer' );

        $icon = $success ? '✅' : '❌';
        if ( ! $success && $message && false !== stripos( $message, 'daily limit' ) ) {
            $icon = '🛑';
        }

        $status_msg = $message ? $message : ( $success ? __( 'Test ran successfully.', 'speed-analyzer' ) : __( 'Test run failed (Module 1 did not complete).', 'speed-analyzer' ) );

        $body .= '<h3 style="margin-top:30px;">' . esc_html( $test_heading ) . '</h3>';

        // Per-test time, using Module 1 datetime if available.
        $local_time = '';
        $utc_time   = '';
        $test_data  = ( $test_no && isset( $tests_map[ $test_no ] ) ) ? $tests_map[ $test_no ] : null;

        if ( $test_data && ! empty( $test_data['datetime'] ) ) {
            $ts = strtotime( $test_data['datetime'] );
            if ( $ts ) {
                if ( function_exists( 'wp_date' ) ) {
                    $local_time = wp_date( $format, $ts );
                } else {
                    $local_time = date_i18n( $format, $ts );
                }
                $utc_time = gmdate( $format, $ts );
            }
        }

        $body .= '<p>';
        if ( $url ) {
            $body .= '<strong>' . esc_html__( 'URL:', 'speed-analyzer' ) . '</strong> <a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $url ) . '</a><br />';
        } else {
            $body .= '<strong>' . esc_html__( 'URL:', 'speed-analyzer' ) . '</strong> ' . esc_html__( '(not logged)', 'speed-analyzer' ) . '<br />';
        }

        if ( $local_time || $utc_time ) {
            if ( $local_time ) {
                $body .= '<strong>' . esc_html__( 'Site time:', 'speed-analyzer' ) . '</strong> ' . esc_html( $local_time ) . '<br />';
            }
            if ( $utc_time ) {
                $body .= '<strong>' . esc_html__( 'UTC:', 'speed-analyzer' ) . '</strong> ' . esc_html( $utc_time ) . '<br />';
            }
        }

       $body .= '<strong>' . esc_html__( 'Status:', 'speed-analyzer' ) . '</strong> ' .
         esc_html( $icon ) . ' ' . esc_html( $status_msg );

        $body .= '</p>';

        // If we don't have parsed metrics for this test, skip the tables.
        if ( ! $test_data ) {
            continue;
        }

        // Helper: safe printable value with N/A fallback.
        $val = static function ( $v ) {
            if ( null === $v || '' === $v ) {
                return 'N/A';
            }
            return (string) $v;
        };

        // TTFB label + style.
        $ttfb_label        = ( 'N/A' === $test_data['ttfb_ms'] ) ? 'N/A' : $test_data['ttfb_ms'] . ' ms';
        
         // TTFB inline style.
        $ttfb_style_inline = $ttfb_style( $test_data['ttfb_ms'] );
        $ttfb_style_attr   = 'font-size:13px;';
        if ( '' !== $ttfb_style_inline ) {
            $ttfb_style_attr .= $ttfb_style_inline;
        }


        // Cache value + style.
        $cache_val          = $val( $test_data['cache'] );
        $cache_style_inline = $cache_style( $test_data['cache'] );
        $cache_style_attr   = 'font-size:13px;';
        if ( '' !== $cache_style_inline ) {
            $cache_style_attr .= $cache_style_inline;
        }

        // Metric values + styles for Module 3.
        $m_lcp_val = $val( $test_data['m5m_lcp'] );
        $d_lcp_val = $val( $test_data['m5d_lcp'] );
        $m_fcp_val = $val( $test_data['m5m_fcp'] );
        $d_fcp_val = $val( $test_data['m5d_fcp'] );
        $m_cls_val = $val( $test_data['m5m_cls'] );
        $d_cls_val = $val( $test_data['m5d_cls'] );
        $m_inp_val = $val( $test_data['m5m_inp'] );
        $d_inp_val = $val( $test_data['m5d_inp'] );

        $m_lcp_attr = 'font-size:13px;' . $metric_style( 'lcp', $m_lcp_val );
        $d_lcp_attr = 'font-size:13px;' . $metric_style( 'lcp', $d_lcp_val );
        $m_fcp_attr = 'font-size:13px;' . $metric_style( 'fcp', $m_fcp_val );
        $d_fcp_attr = 'font-size:13px;' . $metric_style( 'fcp', $d_fcp_val );
        $m_cls_attr = 'font-size:13px;' . $metric_style( 'cls', $m_cls_val );
        $d_cls_attr = 'font-size:13px;' . $metric_style( 'cls', $d_cls_val );
        $m_inp_attr = 'font-size:13px;' . $metric_style( 'inp', $m_inp_val );
        $d_inp_attr = 'font-size:13px;' . $metric_style( 'inp', $d_inp_val );

        // Resolve screenshots for this test (if any) and respect the 5-test cap.

        $mobile_ss  = '';
        $desktop_ss = '';

        if ( $ss_slots_remaining > 0 && $test_no && isset( $scheduled_ss_map[ $test_no ] ) && is_array( $scheduled_ss_map[ $test_no ] ) ) {
            $mobile_ss  = isset( $scheduled_ss_map[ $test_no ]['mobile'] ) ? $scheduled_ss_map[ $test_no ]['mobile'] : '';
            $desktop_ss = isset( $scheduled_ss_map[ $test_no ]['desktop'] ) ? $scheduled_ss_map[ $test_no ]['desktop'] : '';

            if ( ( is_string( $mobile_ss ) && '' !== $mobile_ss ) || ( is_string( $desktop_ss ) && '' !== $desktop_ss ) ) {
                $ss_slots_remaining--;
            }
        }

        $mobile_circle  = $perf_circle( $test_data['m5m_perf'] );
        $desktop_circle = $perf_circle( $test_data['m5d_perf'] );


    // Build a wide two-column layout:
    //  - left  : Module 1 (TTFB & cache)
    //  - right : Module 3 – PSI performance metrics with gauges + screenshots.
    $body .= '<table border="0" cellpadding="0" cellspacing="0" style="width:100%;max-width:720px;border-collapse:collapse;margin-bottom:24px;">';
    $body .= '<tr>';
    
    // Left side – Module 1 table (no Daily usage row).
    $body .= '<td valign="top" width="50%" style="padding:0 8px 0 0;">';
    $body .= '<table border="1" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;">';
    $body .= '<thead><tr>';
    $body .= '<th align="center" colspan="2" style="font-size:13px;">' . esc_html__( 'Module 1 – TTFB & cache', 'speed-analyzer' ) . '</th>';
    $body .= '</tr></thead><tbody>';

    // TTFB row.
    $body .= '<tr>';
    $body .= '<td style="white-space:nowrap;font-size:13px;">' . esc_html__( 'TTFB result', 'speed-analyzer' ) . '</td>';
    $body .= '<td style="' . esc_attr( $ttfb_style_attr ) . '">' . esc_html( $ttfb_label ) . '</td>';
    $body .= '</tr>';

    // Region row.
    $body .= '<tr>';
    $body .= '<td style="white-space:nowrap;font-size:13px;">' . esc_html__( 'Region', 'speed-analyzer' ) . '</td>';
    $body .= '<td style="font-size:13px;">' . esc_html( $val( $test_data['region'] ) ) . '</td>';
    $body .= '</tr>';

    // Cache row.
    $body .= '<tr>';
    $body .= '<td style="white-space:nowrap;font-size:13px;">' . esc_html__( 'Cache', 'speed-analyzer' ) . '</td>';
    $body .= '<td style="' . esc_attr( $cache_style_attr ) . '">' . esc_html( $cache_val ) . '</td>';
    $body .= '</tr>';

    $body .= '</tbody></table>';
    
   // CWV (field) Assessment lines (scope comes from parsed log) + compact N/A output.
    $scope_mobile  = isset( $test_data['cwv_mobile_scope'] ) && $test_data['cwv_mobile_scope'] ? (string) $test_data['cwv_mobile_scope'] : 'URL';
    $scope_desktop = isset( $test_data['cwv_desktop_scope'] ) && $test_data['cwv_desktop_scope'] ? (string) $test_data['cwv_desktop_scope'] : 'URL';
    
    $cwv_m = isset( $test_data['cwv_mobile_assessment'] ) && $test_data['cwv_mobile_assessment']
        ? strtoupper( (string) $test_data['cwv_mobile_assessment'] )
        : ( isset( $test_data['cwv_origin_mobile_assessment'] ) ? strtoupper( (string) $test_data['cwv_origin_mobile_assessment'] ) : '' );
    
    $cwv_d = isset( $test_data['cwv_desktop_assessment'] ) && $test_data['cwv_desktop_assessment']
        ? strtoupper( (string) $test_data['cwv_desktop_assessment'] )
        : ( isset( $test_data['cwv_origin_desktop_assessment'] ) ? strtoupper( (string) $test_data['cwv_origin_desktop_assessment'] ) : '' );
    
    $cwv_icon = static function ( $a ) {
        if ( $a === 'PASSED' ) {
            return '✅';
        }
        if ( $a === 'FAILED' ) {
            return '❌';
        }
        return '';
    };
    
    $cwv_label = static function ( $a ) {
        if ( $a === 'PASSED' ) {
            return 'PASSED';
        }
        if ( $a === 'FAILED' ) {
            return 'FAILED';
        }
        return '--';
    };
    
    // Reduce "--" noise: if BOTH are N/A/empty, show a single compact line.
    $both_na = ( $cwv_label( $cwv_m ) === '--' && $cwv_label( $cwv_d ) === '--' );
    
    $body .= '<div style="margin-top:12px;">';
    
    if ( $both_na ) {
        $body .= '<div style="font-size:13px;line-height:1.35;color:#333;">'
            . esc_html__( 'Core Web Vitals Assessment: ', 'speed-analyzer' )
            . '--'
            . '</div>';
    } else {
        // Mobile line.
        $body .= '<div style="font-size:13px;line-height:1.35;color:#333;">'
            . esc_html__( 'Core Web Vitals Assessment: ', 'speed-analyzer' )
            . esc_html( $cwv_label( $cwv_m ) );
    
        if ( $cwv_m === 'PASSED' || $cwv_m === 'FAILED' ) {
            $body .= ' ' . esc_html( $cwv_icon( $cwv_m ) );
        }
    
        $body .= ' - ' . esc_html__( 'scope ', 'speed-analyzer' ) . esc_html( $scope_mobile ) . esc_html__( ' (Mobile)', 'speed-analyzer' )
            . '</div>';

    
        // Desktop line.
        $body .= '<div style="font-size:13px;line-height:1.35;color:#333;margin-top:6px;">'
            . esc_html__( 'Core Web Vitals Assessment: ', 'speed-analyzer' )
            . esc_html( $cwv_label( $cwv_d ) );
    
        if ( $cwv_d === 'PASSED' || $cwv_d === 'FAILED' ) {
            $body .= ' ' . esc_html( $cwv_icon( $cwv_d ) );
        }
    
        $body .= ' - ' . esc_html__( 'scope ', 'speed-analyzer' ) . esc_html( $scope_desktop ) . esc_html__( ' (Desktop)', 'speed-analyzer' )
            . '</div>';

    }
    
    $body .= '</div>';


    
    // Right side – Module 3 metrics + screenshots.
    // Slightly larger left padding to visually separate from Module 1 table.
    $body .= '<td valign="top" width="50%" style="padding:0 0 0 16px;">';
    $body .= '<table border="1" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;table-layout:fixed;">';
    $body .= '<thead><tr>';
    $body .= '<th align="center" colspan="2" style="text-align:center;">' . esc_html__( 'Module 3 – PSI performance metrics', 'speed-analyzer' ) . '</th>';
    $body .= '</tr></thead><tbody>';
    
    // Optional screenshot row INSIDE the Module 3 column, aligned over mobile/desktop areas.
    if ( ( is_string( $mobile_ss ) && '' !== $mobile_ss ) || ( is_string( $desktop_ss ) && '' !== $desktop_ss ) ) {
        $body .= '<tr>';
        $body .= '<td valign="top" width="50%" style="padding:4px 4px 8px 0;text-align:center;">';
        if ( is_string( $mobile_ss ) && '' !== $mobile_ss ) {
            $body .= '<div style="display:inline-block;margin-bottom:4px;">';
            $body .= '<div style="font-size:11px;margin-bottom:2px;color:#555;">' . esc_html__( 'Mobile', 'speed-analyzer' ) . '</div>';
            $body .= '<div style="border-radius:4px;background:#ffffff;border:1px solid #d0d7de;box-shadow:0 8px 20px rgba(0,0,0,0.12);padding:4px;">';
            $body .= '<img src="' . esc_attr( $mobile_ss ) . '" alt="' . esc_attr__( 'PSI mobile screenshot', 'speed-analyzer' ) . '" style="max-height:180px;width:100%;height:auto;display:block;margin:0 auto;border-radius:3px;" />';
            $body .= '</div>';
            $body .= '</div>';
        }
        $body .= '</td>';
    
        $body .= '<td valign="top" width="50%" style="padding:4px 0 8px 4px;text-align:center;">';
        if ( is_string( $desktop_ss ) && '' !== $desktop_ss ) {
            $body .= '<div style="display:inline-block;margin-bottom:4px;">';
            $body .= '<div style="font-size:11px;margin-bottom:2px;color:#555;">' . esc_html__( 'Desktop', 'speed-analyzer' ) . '</div>';
            $body .= '<div style="border-radius:4px;background:#ffffff;border:1px solid #d0d7de;box-shadow:0 8px 20px rgba(0,0,0,0.12);padding:4px;">';
            $body .= '<img src="' . esc_attr( $desktop_ss ) . '" alt="' . esc_attr__( 'PSI desktop screenshot', 'speed-analyzer' ) . '" style="max-height:180px;width:100%;height:auto;display:block;margin:0 auto;border-radius:3px;" />';
            $body .= '</div>';
            $body .= '</div>';
        }
        $body .= '</td>';
        $body .= '</tr>';
    }
    
    // Score row – gauges only.
    $body .= '<tr>';
    
    // Mobile score cell – same padding and centering as desktop to avoid "narrow" look.
    $body .= '<td width="50%" style="vertical-align:middle;padding:8px 8px 8px 8px;font-size:13px;text-align:center;">';
    $body .= '<div style="display:inline-block;text-align:center;">';
    $body .= '<div style="font-size:13px;margin-bottom:4px;"><strong>' . esc_html__( 'Mobile score', 'speed-analyzer' ) . ':</strong></div>';
    $body .= $mobile_circle;
    $body .= '</div>';
    $body .= '</td>';
    
    // Desktop score cell – mirror mobile side.
    $body .= '<td width="50%" style="vertical-align:middle;padding:8px 8px 8px 8px;font-size:13px;text-align:center;">';
    $body .= '<div style="display:inline-block;text-align:center;">';
    $body .= '<div style="font-size:13px;margin-bottom:4px;"><strong>' . esc_html__( 'Desktop score', 'speed-analyzer' ) . ':</strong></div>';
    $body .= $desktop_circle;
    $body .= '</div>';
    $body .= '</td>';
    
    $body .= '</tr>';
    
    // LCP row – styled + 13px font, no bold label.
    $body .= '<tr>';
    $body .= '<td width="50%" style="padding:6px 8px 6px 8px;font-size:13px;">';
    $body .= 'LCP: <span style="' . esc_attr( $m_lcp_attr ) . '">' . esc_html( $m_lcp_val ) . '</span> s';
    $body .= '</td>';
    $body .= '<td width="50%" style="padding:6px 8px 6px 8px;font-size:13px;">';
    $body .= 'LCP: <span style="' . esc_attr( $d_lcp_attr ) . '">' . esc_html( $d_lcp_val ) . '</span> s';
    $body .= '</td>';
    $body .= '</tr>';

    
    // FCP row – styled + 13px font, no bold label.
    $body .= '<tr>';
    $body .= '<td width="50%" style="padding:6px 8px 6px 8px;font-size:13px;">';
    $body .= 'FCP: <span style="' . esc_attr( $m_fcp_attr ) . '">' . esc_html( $m_fcp_val ) . '</span> s';
    $body .= '</td>';
    $body .= '<td width="50%" style="padding:6px 8px 6px 8px;font-size:13px;">';
    $body .= 'FCP: <span style="' . esc_attr( $d_fcp_attr ) . '">' . esc_html( $d_fcp_val ) . '</span> s';
    $body .= '</td>';
    $body .= '</tr>';

    
   // CLS row – styled + 13px font, no bold label.
    $body .= '<tr>';
    $body .= '<td width="50%" style="padding:6px 8px 6px 8px;font-size:13px;">';
    $body .= 'CLS: <span style="' . esc_attr( $m_cls_attr ) . '">' . esc_html( $m_cls_val ) . '</span>';
    $body .= '</td>';
    $body .= '<td width="50%" style="padding:6px 8px 6px 8px;font-size:13px;">';
    $body .= 'CLS: <span style="' . esc_attr( $d_cls_attr ) . '">' . esc_html( $d_cls_val ) . '</span>';
    $body .= '</td>';
    $body .= '</tr>';
    
    // INP row – styled + 13px font, no bold label.
    $body .= '<tr>';
    $body .= '<td width="50%" style="padding:6px 8px 8px 8px;font-size:13px;">';
    $body .= 'INP: <span style="' . esc_attr( $m_inp_attr ) . '">' . esc_html( $m_inp_val ) . '</span> ms';
    $body .= '</td>';
    $body .= '<td width="50%" style="padding:6px 8px 8px 8px;font-size:13px;">';
    $body .= 'INP: <span style="' . esc_attr( $d_inp_attr ) . '">' . esc_html( $d_inp_val ) . '</span> ms';
    $body .= '</td>';
    $body .= '</tr>';

    
    $body .= '</tbody></table>';
    $body .= '</td>';
    
    $body .= '</tr></table>';


    }
    // Footer.
    $body .= '<p style="margin-top:24px;">Thank you for using <a href="https://wordpress.org/plugins/speed-analyzer" target="_blank">Speed Analyzer</a>. You just made a first step towards a faster website.</p>';

    $body .= '</body></html>';

    $headers = array( 'Content-Type: text/html; charset=UTF-8' );

    $sent = wp_mail( $to, $subject, $body, $headers );
    return (bool) $sent;
}





/**
 * Add a 5-minute interval for WPSA cron.
 */
add_filter( 'cron_schedules', 'wpsa_schedule_add_five_minute' );
function wpsa_schedule_add_five_minute( $schedules ) {
    if ( ! isset( $schedules['wpsa_five_minutes'] ) ) {
        $schedules['wpsa_five_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 5 Minutes (Speed Analyzer)', 'speed-analyzer' ),
        );
    }

    return $schedules;
}

/**
 * Ensure our cron event is properly scheduled/unscheduled.
 * Runs on every page load (front + admin).
 */
add_action( 'init', 'wpsa_schedule_register_cron' );
function wpsa_schedule_register_cron() {
    $settings = get_option( 'wpsa_schedule_settings', array() );
    $enabled  = ! empty( $settings['enabled'] );

    $hook = 'wpsa_run_scheduled_tests';

    // Clean up legacy hook from older builds (had no callback anymore).
    wp_clear_scheduled_hook( 'wpsa_cron_schedule_tests' );

    // If not enabled, clear any existing events for the current hook.
    if ( ! $enabled ) {
        wp_clear_scheduled_hook( $hook );
        return;
    }

    // Enabled: ensure an event exists.
    if ( ! wp_next_scheduled( $hook ) ) {
        wp_schedule_event( time() + 60, 'wpsa_five_minutes', $hook );
    }
}

    /**
     * Cron callback: run scheduled tests when their next_run time is due.
     */
    add_action( 'wpsa_run_scheduled_tests', 'wpsa_run_scheduled_tests' );
     function wpsa_run_scheduled_tests() {
        $settings = get_option( 'wpsa_schedule_settings', array() );
        if ( empty( $settings['enabled'] ) || empty( $settings['urls'] ) || ! is_array( $settings['urls'] ) ) {
            wpsa_debug_log( 'WPSA schedule: bail – disabled or no URLs.' );
    
            // If the schedule was disabled mid-batch, clear any stale state/lock.
            delete_option( 'wpsa_schedule_state' );
            delete_option( 'wpsa_schedule_lock' );
    
            return;
        }


    // Mark this request as a scheduled batch so Module 1 can (later) log a header per URL.
    if ( ! defined( 'WPSA_SCHEDULED_BATCH' ) ) {
        define( 'WPSA_SCHEDULED_BATCH', true );
    }

    // Normalized schedule config.
    $freq = isset( $settings['frequency'] ) ? $settings['frequency'] : 'daily';
    $time = isset( $settings['time'] ) ? $settings['time'] : '07:30';

    // Saved chunk size (URLs per cron tick): clamp to 2–5, default 3.
    $chunk_from_settings = isset( $settings['chunk_size'] ) ? (int) $settings['chunk_size'] : 3;
    if ( $chunk_from_settings < 2 || $chunk_from_settings > 5 ) {
        $chunk_from_settings = 3;
    }

    $now      = time();

    $next_run = (int) get_option( 'wpsa_schedule_next_run', 0 );

     // Batch state: persists progress across multiple cron ticks.
    $state     = get_option( 'wpsa_schedule_state', array() );
    $has_state = is_array( $state ) && ! empty( $state['urls'] );

    // If no batch state yet, decide whether to start a new batch based on next_run.
    if ( ! $has_state ) {
        if ( ! $next_run ) {
            // Safety net: initialize next_run from current settings and bail until the next tick.
            $next_run = wpsa_schedule_calculate_next_run( $freq, $time );
            update_option( 'wpsa_schedule_next_run', $next_run );
            wpsa_debug_log( 'WPSA schedule: initialized next_run at ' . gmdate( 'c', $next_run ) );
            return;
        }

        wpsa_debug_log(
            sprintf(
                'WPSA schedule: fired at %s, next_run=%s (delta=%ds)',
                gmdate( 'c', $now ),
                gmdate( 'c', $next_run ),
                $now - $next_run
            )
        );

        // Too early (more than 2 minutes before the scheduled time) → wait.
        if ( $now < ( $next_run - 120 ) ) {
            wpsa_debug_log( 'WPSA schedule: too early, skipping.' );
            return;
        }

        // Missed by more than an hour → push schedule forward without running.
        if ( $now > ( $next_run + HOUR_IN_SECONDS ) ) {
            wpsa_debug_log( 'WPSA schedule: missed by >1h, pushing forward without running.' );
            $next_run = wpsa_schedule_calculate_next_run( $freq, $time, $now );
            update_option( 'wpsa_schedule_next_run', $next_run );
            return;
        }
        
        // Before starting a fresh scheduled batch, clean up old PSI screenshot image files
        // created from previous runs. We only remove email-only exports:
        //   psi-ss-scheduled-*.jpg / *.jpeg / *.png / *.webp
        // The log files themselves (PSI-ss-scheduled.log, ttfb-results-log.txt, etc.) are left intact.
        $upload_dir_cleanup = wp_upload_dir();
        if ( empty( $upload_dir_cleanup['error'] ) ) {
            $cleanup_dir = trailingslashit( $upload_dir_cleanup['basedir'] ) . 'speed-analyzer';
            if ( is_dir( $cleanup_dir ) ) {
                $allowed_ext  = array( 'jpg', 'jpeg', 'png', 'webp' );
                $glob_pattern = trailingslashit( $cleanup_dir ) . 'psi-ss-scheduled-*.*';
                $existing     = glob( $glob_pattern );

                if ( is_array( $existing ) ) {
                    foreach ( $existing as $file_path ) {
                        if ( ! is_string( $file_path ) || '' === $file_path ) {
                            continue;
                        }
                        if ( ! is_file( $file_path ) ) {
                            continue;
                        }

                        $ext = strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) );
                        if ( ! in_array( $ext, $allowed_ext, true ) ) {
                            continue;
                        }

                        wp_delete_file( $file_path );
                    }
                }
            }
        }

        // Normalize URLs from settings.
        $urls_raw = $settings['urls'];
        $urls     = array();

        foreach ( $urls_raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $url_raw = isset( $row['url'] ) ? $row['url'] : '';
            $url     = esc_url_raw( $url_raw );
            if ( '' === $url ) {
                continue;
            }

            $urls[] = array(
                'url' => $url,
            );
        }

        if ( empty( $urls ) ) {
            wpsa_debug_log( 'WPSA schedule: no valid URLs after normalization.' );
            return;
        }

            // Normalise URLs into per-URL state rows (with phases + Module 1 flag).
        $urls = array_values( $urls );
        foreach ( $urls as $i => $row ) {
            if ( ! is_array( $row ) ) {
                $urls[ $i ] = array(
                    'url'      => '',
                    'phase'    => 3,
                    'test_no'  => null,
                    'message'  => '',
                    'success'  => false,
                    'm1_done'  => false,
                );
                continue;
            }

            $url = isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '';
            if ( '' === $url ) {
                $urls[ $i ] = array(
                    'url'      => '',
                    'phase'    => 3,
                    'test_no'  => null,
                    'message'  => '',
                    'success'  => false,
                    'm1_done'  => false,
                );
                continue;
            }

            $urls[ $i ]['url']      = $url;
            $urls[ $i ]['phase']    = isset( $row['phase'] ) ? (int) $row['phase'] : 0;
            $urls[ $i ]['test_no']  = isset( $row['test_no'] ) ? $row['test_no'] : null;
            $urls[ $i ]['message']  = isset( $row['message'] ) ? $row['message'] : '';
            $urls[ $i ]['success']  = ! empty( $row['success'] );
            $urls[ $i ]['m1_done']  = ! empty( $row['m1_done'] );
        }


        // Start a new batch state.
        $state = array(
            'started_at' => $now,
            'freq'       => $freq,
            'time'       => $time,
            'urls'       => $urls,
            // "index" kept for backwards compatibility but unused in the new loop.
            'index'      => 0,
            'items'      => array(),    // accumulated per-URL results
        );

        update_option( 'wpsa_schedule_state', $state );
        $has_state = true;


        wpsa_debug_log(
            sprintf(
                'WPSA schedule: starting new batch with %d URL(s) at %s.',
                count( $urls ),
                gmdate( 'c', $now )
            )
        );
    } else {
        wpsa_debug_log(
            sprintf(
                'WPSA schedule: resuming existing batch at index %d.',
                isset( $state['index'] ) ? (int) $state['index'] : 0
            )
        );

               // Normalise any legacy state rows (ensure we have phases, flags, and m1_done).
        if ( ! empty( $state['urls'] ) && is_array( $state['urls'] ) ) {
            foreach ( $state['urls'] as $i => $row ) {
                if ( ! is_array( $row ) ) {
                    $state['urls'][ $i ] = array(
                        'url'      => '',
                        'phase'    => 3,
                        'test_no'  => null,
                        'message'  => '',
                        'success'  => false,
                        'm1_done'  => false,
                    );
                    continue;
                }

                $state['urls'][ $i ]['url']     = isset( $row['url'] ) ? esc_url_raw( $row['url'] ) : '';
                $state['urls'][ $i ]['phase']   = isset( $row['phase'] ) ? (int) $row['phase'] : 0;
                $state['urls'][ $i ]['test_no'] = isset( $row['test_no'] ) ? $row['test_no'] : null;
                $state['urls'][ $i ]['message'] = isset( $row['message'] ) ? $row['message'] : '';
                $state['urls'][ $i ]['success'] = ! empty( $row['success'] );
                $state['urls'][ $i ]['m1_done'] = ! empty( $row['m1_done'] );
            }
        }
    }


    // Simple re-entrancy lock so overlapping cron ticks can't run the same batch.
    $lock_key      = 'wpsa_schedule_lock';
    $existing_lock = get_option( $lock_key );
    $lock_ttl      = 15 * MINUTE_IN_SECONDS; // instead of HOUR_IN_SECONDS

    if ( $existing_lock && is_numeric( $existing_lock ) && ( $now - (int) $existing_lock ) < $lock_ttl ) {
        wpsa_debug_log( 'WPSA schedule: another batch appears to be running (lock in place), skipping this tick.' );
        return;
    }
    update_option( $lock_key, $now );


    // Compute a per-tick PHP budget from max_execution_time.
    $max_exec = (int) ini_get( 'max_execution_time' );
    if ( $max_exec <= 0 ) {
        // 0 means "no limit" on some hosts; we still clamp to something sane.
        $max_exec = 300;
    }
    // Leave a safety margin so we don't bump into the hard PHP limit.
    $php_budget = max( 30, $max_exec - 15 );

    // Process URLs in small chunks per tick (filterable).
    // Base value comes from settings (2–5, default 3).
    $chunk_base  = $chunk_from_settings;
    $chunk_size  = (int) apply_filters( 'wpsa_schedule_chunk_size', $chunk_base );
    if ( $chunk_size < 1 ) {
        $chunk_size = 1;
    }


    $batch_start = microtime( true );

    $upload_dir = wp_upload_dir();
    $base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'speed-analyzer';
    if ( ! file_exists( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
    }
    $log_path    = $base_dir . '/ttfb-api-debug.log';
    $results_log = $base_dir . '/ttfb-results-log.txt';

    $urls = isset( $state['urls'] ) && is_array( $state['urls'] ) ? $state['urls'] : array();
    $total_urls = count( $urls );

    if ( ! $total_urls ) {
        wpsa_debug_log( 'WPSA schedule: batch state has no URLs, clearing.' );
        delete_option( 'wpsa_schedule_state' );
        delete_option( $lock_key );
        return;
    }

    // Option A – strict phase ordering:
    // if any URL is still in phase 0, we will only process phase 0 rows this tick.
    $phase0_remaining = false;
    foreach ( $urls as $row_phase_check ) {
        if ( is_array( $row_phase_check ) ) {
            $p = isset( $row_phase_check['phase'] ) ? (int) $row_phase_check['phase'] : 0;
            if ( 0 === $p ) {
                $phase0_remaining = true;
                break;
            }
        }
    }

    $items = isset( $state['items'] ) && is_array( $state['items'] ) ? $state['items'] : array();
  

    global $wpdb;

    $processed_this_tick = 0;
    $daily_exhausted     = false;

    // Main per-URL loop – phase based, now actually respects chunk size AND PHP time budget.
    foreach ( $urls as $idx => $row ) {

        // Stop this tick if we've hit the per-tick URL limit.
        if ( $processed_this_tick >= $chunk_size ) {
            wpsa_debug_log( sprintf( 'WPSA schedule: chunk limit reached (%d URL(s) this tick).', $chunk_size ) );
            break;
        }

        // Stop this tick if we've exhausted our PHP time budget.
        if ( ( microtime( true ) - $batch_start ) >= $php_budget ) {
            wpsa_debug_log( 'WPSA schedule: PHP time budget exhausted for this tick, stopping early.' );
            break;
        }

        $phase = isset( $row['phase'] ) ? (int) $row['phase'] : 0;


        // Phase 3 means "fully done" – skip.
        if ( $phase >= 3 ) {
            continue;
        }

        $url_raw = isset( $row['url'] ) ? $row['url'] : '';
        $url     = esc_url_raw( $url_raw );

        // If we already hit the daily limit, don't start any new Module 1 runs (phase 0).
        // Still record these URLs in the batch summary so the UI/email show them.
        if ( $daily_exhausted && 0 === $phase ) {
            $items[] = array(
                'url'     => $url,
                'success' => false,
                'test_no' => null,
                'message' => 'Daily limit reached before running this test.',
            );

            $urls[ $idx ]['phase']   = 3;
            $urls[ $idx ]['success'] = false;
            if ( empty( $urls[ $idx ]['message'] ) ) {
                $urls[ $idx ]['message'] = 'Daily limit reached before running this test.';
            }

            $processed_this_tick++;
            continue;
        }

        // Option A – strict ordering:
        // if any URL is still in phase 0, we only process phase 0 rows this tick.
        // Once the daily limit is hit, we relax this so phase-1 URLs can still finish Module 5.
        if ( $phase0_remaining && 0 !== $phase && ! $daily_exhausted ) {
            continue;
        }


        if ( '' === $url ) {
            // Nothing to do for this row.
            $urls[ $idx ]['phase'] = 3;
            continue;
        }

        // --- Phase 0: Module 1 → 2 → 3/4 ---
        if ( 0 === $phase ) {

            $m1_done = ! empty( $urls[ $idx ]['m1_done'] );

            // If we already ran Module 1 for this URL in a previous tick, skip straight to Modules 2–4.
            if ( ! $m1_done ) {

                // Same-host restriction for scheduled runs on non-Agency plans.
                $tier = function_exists( 'wpsa_get_license_tier' ) ? wpsa_get_license_tier() : '';

                if ( 'premium3' !== $tier ) {
                    $site_host   = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
                    $target_host = wp_parse_url( $url, PHP_URL_HOST );

                    if ( $site_host && $target_host && ! preg_match( '/(^|\.)' . preg_quote( $site_host, '/' ) . '$/i', $target_host ) ) {
                        $human_msg = __( 'Test skipped: this URL points to a different website and scheduled tests for external URLs are only available on the Agency plan.', 'speed-analyzer' );

                        $items[] = array(
                            'url'     => $url,
                            'success' => false,
                            'test_no' => null,
                            'message' => $human_msg,
                        );

                        $urls[ $idx ]['phase']    = 3;
                        $urls[ $idx ]['success']  = false;
                        $urls[ $idx ]['message']  = $human_msg;
                        $urls[ $idx ]['m1_done']  = false;

                        // Persist per-URL progress so partial ticks don't repeat work.
                        $state['urls']  = $urls;
                        $state['items'] = $items;
                        update_option( 'wpsa_schedule_state', $state );

                        $processed_this_tick++;
                        continue;
                    }
                }

                // Quota check – record failure but do not block already-started PSI runs.
                $quota = wpsa_check_quota( 'ttfb' );

                if ( is_wp_error( $quota ) ) {
                    $items[] = array(
                        'url'     => $url,
                        'success' => false,
                        'test_no' => null,
                        'message' => 'Quota check failed for this test.',
                    );
                    wpsa_debug_log( 'WPSA schedule: quota check failed for ' . $url );

                    $urls[ $idx ]['phase']    = 3;
                    $urls[ $idx ]['success']  = false;
                    $urls[ $idx ]['message']  = 'Quota check failed for this test.';
                    $urls[ $idx ]['m1_done']  = false;

                    // Persist per-URL progress so partial ticks don't repeat work.
                    $state['urls']  = $urls;
                    $state['items'] = $items;
                    update_option( 'wpsa_schedule_state', $state );

                    $processed_this_tick++;
                    continue;
                }

                if ( empty( $quota['allowed'] ) ) {
                    // Daily limit exhausted – record and mark this URL as done.
                    $items[] = array(
                        'url'     => $url,
                        'success' => false,
                        'test_no' => null,
                        'message' => 'Daily limit reached before running this test.',
                    );
                    wpsa_debug_log( 'WPSA schedule: daily limit reached before running ' . $url );

                    $urls[ $idx ]['phase']    = 3;
                    $urls[ $idx ]['success']  = false;
                    $urls[ $idx ]['message']  = 'Daily limit reached before running this test.';
                    $urls[ $idx ]['m1_done']  = false;
                    $daily_exhausted          = true;

                    // Persist per-URL progress so partial ticks don't repeat work.
                    $state['urls']  = $urls;
                    $state['items'] = $items;
                    update_option( 'wpsa_schedule_state', $state );

                    $processed_this_tick++;
                    continue;
                }

                // Run Module 1.
                $ok = wpsa_module1_ttfb( $url, $log_path, $results_log );

                // Handle WP_Error from Module 1.
                if ( is_wp_error( $ok ) ) {
                    $err_code = $ok->get_error_code();
                    $err_msg  = $ok->get_error_message();

                    if ( in_array( $err_code, array( 'wpsa_http_404', 'wpsa_url_404', 'wpsa_404' ), true ) ) {
                        $human_msg = __( 'Test failed because the URL appears to return HTTP 404 (page not found).', 'speed-analyzer' );
                    } elseif ( 'wpsa_off_host' === $err_code ) {
                        $human_msg = __( 'Test skipped: this URL points to a different website and scheduled tests for external URLs are only available on the Agency plan.', 'speed-analyzer' );
                    } else {
                        $human_msg = $err_msg ? $err_msg : __( 'Test run failed (Module 1 returned an error).', 'speed-analyzer' );
                    }

                    $items[] = array(
                        'url'     => $url,
                        'success' => false,
                        'test_no' => null,
                        'message' => $human_msg,
                    );

                    $urls[ $idx ]['phase']    = 3;
                    $urls[ $idx ]['success']  = false;
                    $urls[ $idx ]['message']  = $human_msg;
                    $urls[ $idx ]['m1_done']  = false;

                    wpsa_debug_log(
                        sprintf(
                            'WPSA schedule: Module 1 error for %1$s – %2$s: %3$s',
                            $url,
                            $err_code ? $err_code : '(no_code)',
                            $err_msg ? $err_msg : '(no_message)'
                        )
                    );

                    // Persist per-URL progress so partial ticks don't repeat work.
                    $state['urls']  = $urls;
                    $state['items'] = $items;
                    update_option( 'wpsa_schedule_state', $state );

                    $processed_this_tick++;
                    continue;
                }

                if ( ! $ok ) {
                    // Boolean false from Module 1 – generic failure.
                    $items[] = array(
                        'url'     => $url,
                        'success' => false,
                        'test_no' => null,
                        'message' => 'Test run failed (Module 1 did not complete).',
                    );

                    $urls[ $idx ]['phase']    = 3;
                    $urls[ $idx ]['success']  = false;
                    $urls[ $idx ]['message']  = 'Test run failed (Module 1 did not complete).';
                    $urls[ $idx ]['m1_done']  = false;

                    wpsa_debug_log( 'WPSA schedule: Module 1 failed for ' . $url );

                    // Persist per-URL progress so partial ticks don't repeat work.
                    $state['urls']  = $urls;
                    $state['items'] = $items;
                    update_option( 'wpsa_schedule_state', $state );

                    $processed_this_tick++;
                    continue;
                }

                // Module 1 succeeded – capture the Test # that was just logged for this URL.
                $test_no_for_url = wpsa_schedule_get_last_test_number( $results_log );
                if ( $test_no_for_url ) {
                    $urls[ $idx ]['test_no'] = (int) $test_no_for_url;
                }

                // Increment daily usage once, immediately after Module 1.
                wpsa_increment_daily_usage();

                // Mark Module 1 as completed for this URL and persist before Module 2.
                $urls[ $idx ]['m1_done'] = true;

                $state['urls']  = $urls;
                $state['items'] = $items;
                update_option( 'wpsa_schedule_state', $state );

                wpsa_debug_log( 'WPSA schedule: [' . $url . '] Module 1 completed and state persisted (m1_done=1).' );
            }

            // If Module 1 was just done and we're tight on time, postpone Modules 2–4 to a later tick.
            if ( ( microtime( true ) - $batch_start ) >= $php_budget ) {
                wpsa_debug_log( 'WPSA schedule: time budget reached after Module 1 for ' . $url . ', deferring Modules 2–4.' );
                // Do not increment $processed_this_tick yet; this URL will resume in Phase 0 next tick (m1_done=true).
                // Persist state again for safety.
                $state['urls']  = $urls;
                $state['items'] = $items;
                update_option( 'wpsa_schedule_state', $state );
                break;
            }

            // Run Modules 2–4 (only once per URL).
            wpsa_debug_log( 'WPSA schedule: [' . $url . '] starting Module 2' );
            try {
                if ( ! wpsa_run_module2_scheduled( $url ) ) {
                    wpsa_debug_log( 'WPSA schedule: Module 2 scheduled runner returned no data for ' . $url );
                }
            } catch ( \Throwable $e ) {
                wpsa_debug_log( 'WPSA schedule: Module 2 failed for ' . $url . ' – ' . $e->getMessage() );
            }
            wpsa_debug_log( 'WPSA schedule: [' . $url . '] finished Module 2' );

            wpsa_debug_log( 'WPSA schedule: [' . $url . '] starting Module 3/4' );
            try {
                wpsa_module3_4_autoload_cache( $wpdb, $results_log, $url );
            } catch ( \Throwable $e ) {
                wpsa_debug_log( 'WPSA schedule: Module 3/4 failed for ' . $url . ' – ' . $e->getMessage() );
            }
            wpsa_debug_log( 'WPSA schedule: [' . $url . '] finished Module 3/4' );

            // Move this URL to Phase 1 (PSI + Module 6) for a later tick.
            $urls[ $idx ]['phase']   = 1;
            $urls[ $idx ]['success'] = true; // so far, so good.

            // Persist per-URL progress so partial ticks don't repeat work.
            $state['urls']  = $urls;
            $state['items'] = $items;
            update_option( 'wpsa_schedule_state', $state );

            $processed_this_tick++;
            continue;
        }


        // --- Phase 1: Module 5 (scheduled PSI) & finalise ---
        if ( 1 === $phase ) {
            // Prefer the Test # captured right after Module 1 ran for this URL.
            $test_no_for_attach = isset( $urls[ $idx ]['test_no'] ) ? (int) $urls[ $idx ]['test_no'] : null;
            if ( $test_no_for_attach <= 0 ) {
                $test_no_for_attach = null;
            }

            wpsa_debug_log( 'WPSA schedule: [' . $url . '] starting Module 5 (scheduled)' );
            try {
                wpsa_schedule_log_module5( $url, $results_log, $test_no_for_attach );
            } catch ( \Throwable $e ) {
                wpsa_debug_log( 'WPSA schedule: Module 5 (scheduled logger) failed for ' . $url . ' – ' . $e->getMessage() );
            }
            wpsa_debug_log( 'WPSA schedule: [' . $url . '] finished Module 5 (scheduled)' );

            // Use the Test # captured right after Module 1 ran for this URL for email/summary as well.
            $test_no = isset( $urls[ $idx ]['test_no'] ) ? (int) $urls[ $idx ]['test_no'] : null;
            if ( $test_no <= 0 ) {
                // Safety fallback – keep old behaviour if no stored number is available.
                $test_no = wpsa_schedule_get_last_test_number( $results_log );
                $test_no = $test_no ? (int) $test_no : null;
            }


            $items[] = array(
                'url'     => $url,
                'success' => true,
                'test_no' => $test_no,
                'message' => 'Test ran successfully.',
            );

            $urls[ $idx ]['phase']    = 3;
            $urls[ $idx ]['test_no']  = $test_no;
            $urls[ $idx ]['success']  = true;
            $urls[ $idx ]['message']  = 'Test ran successfully.';

            // Persist per-URL progress so partial ticks don't repeat work.
            $state['urls']  = $urls;
            $state['items'] = $items;
            update_option( 'wpsa_schedule_state', $state );

            $processed_this_tick++;
            continue;

        }

        // Any unexpected phase – mark as done to avoid loops.
        $urls[ $idx ]['phase'] = 3;
    }


        // Persist updated state for the next cron tick.
    $state['urls']  = $urls;
    $state['items'] = $items;
    update_option( 'wpsa_schedule_state', $state );

    // Recalculate whether this batch is fully done (all URLs in phase 3).
    $all_done = true;
    foreach ( $urls as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $p = isset( $row['phase'] ) ? (int) $row['phase'] : 0;
        if ( $p < 3 ) {
            $all_done = false;
            break;
        }
    }

    // Only finalise once all URLs are marked done.
    if ( $all_done ) {

        // Build the batch structure expected by wpsa_schedule_send_batch_email().
        $batch = array(
            'ran_at' => $now,
            'items'  => $items,
        );

        $email_sent = false;
        $email_to   = '';

        /*
         * Alert device scopes (regression + threshold can be different).
         */
        $allowed_scopes = array( 'both', 'mobile', 'desktop' );
        
        // Regression scope.
        $reg_scope_raw = isset( $settings['alert_reg_device_scope'] ) ? (string) $settings['alert_reg_device_scope'] : 'both';
        $reg_scope_raw = sanitize_text_field( $reg_scope_raw );
        if ( ! in_array( $reg_scope_raw, $allowed_scopes, true ) ) {
            $reg_scope_raw = 'both';
        }
        $alert_devices_reg = ( 'mobile' === $reg_scope_raw )
            ? array( 'mobile' )
            : ( ( 'desktop' === $reg_scope_raw ) ? array( 'desktop' ) : array( 'mobile', 'desktop' ) );
        
        // Threshold scope.
        $th_scope_raw = isset( $settings['alert_thresh_device_scope'] ) ? (string) $settings['alert_thresh_device_scope'] : 'both';
        $th_scope_raw = sanitize_text_field( $th_scope_raw );
        if ( ! in_array( $th_scope_raw, $allowed_scopes, true ) ) {
            $th_scope_raw = 'both';
        }
        $alert_devices_thresh = ( 'mobile' === $th_scope_raw )
            ? array( 'mobile' )
            : ( ( 'desktop' === $th_scope_raw ) ? array( 'desktop' ) : array( 'mobile', 'desktop' ) );
        
        // Optional: pass into email/render helpers.
        $settings['alert_devices_reg_effective']    = $alert_devices_reg;
        $settings['alert_devices_thresh_effective'] = $alert_devices_thresh;


        // Summary / Alert email handling.
        $notify_email = ! empty( $settings['notify_email'] ) ? sanitize_email( (string) $settings['notify_email'] ) : '';

        $alerts_enabled = ( ! empty( $settings['alert_reg_enable'] ) || ! empty( $settings['alert_thresh_enable'] ) );

        // Default: do not send unless summary is enabled, OR alerts are triggered.
        $should_send_email = false;

        // Summary (always send when enabled).
        if ( ! $alerts_enabled && ! empty( $settings['notify'] ) ) {
            $should_send_email = true;
        }

// Alerts: only send when at least one condition triggers.
if ( $alerts_enabled ) {

    // Build a map of parsed tests so we can read current metrics by test_no.
    $tests_map_alert = wpsa_schedule_parse_tests_from_log( $results_log );

    $alert_triggered = false;

// Collect alert hits so the email can explain what triggered.
$alert_hits = array(); // [ test_no => array( 'reasons' => array(), 'devices' => array() ) ];

// Helper: format numeric values without destroying significant trailing zeros (e.g. 30 stays "30", 2.50 becomes "2.5").
$wpsa_fmt_num = static function ( $n ) {
    if ( ! is_numeric( $n ) ) {
        return (string) $n;
    }

    $f = (float) $n;

    // If it's effectively an integer, print as int.
    if ( abs( $f - (int) $f ) < 0.00001 ) {
        return (string) (int) $f;
    }

    // Otherwise keep up to 3 decimals and trim only decimal trailing zeros.
    return rtrim( rtrim( sprintf( '%.3f', $f ), '0' ), '.' );
};


    // Normalize alert config.
    $reg_metric  = ! empty( $settings['alert_reg_metric'] ) ? strtolower( (string) $settings['alert_reg_metric'] ) : 'all';
    $reg_percent = ! empty( $settings['alert_reg_percent'] ) ? (float) $settings['alert_reg_percent'] : 30;

    $th_metric   = ! empty( $settings['alert_thresh_metric'] ) ? strtolower( (string) $settings['alert_thresh_metric'] ) : 'lcp';
    $th_value    = isset( $settings['alert_thresh_value'] ) ? (float) $settings['alert_thresh_value'] : 0;

    // Device scopes (regression vs threshold).
$devices_reg_to_check = ( ! empty( $alert_devices_reg ) && is_array( $alert_devices_reg ) )
    ? $alert_devices_reg
    : array( 'mobile', 'desktop' );

$devices_thresh_to_check = ( ! empty( $alert_devices_thresh ) && is_array( $alert_devices_thresh ) )
    ? $alert_devices_thresh
    : array( 'mobile', 'desktop' );


    foreach ( $items as $it ) {
        if ( empty( $it['success'] ) ) {
            continue;
        }

        $test_no = ! empty( $it['test_no'] ) ? (int) $it['test_no'] : 0;
        $url_raw = ! empty( $it['url'] ) ? (string) $it['url'] : '';

        if ( $test_no <= 0 || '' === $url_raw ) {
            continue;
        }

        if ( empty( $tests_map_alert[ $test_no ] ) || ! is_array( $tests_map_alert[ $test_no ] ) ) {
            continue;
        }

        $current = $tests_map_alert[ $test_no ];

        // Previous scheduled run for same URL (not this test).
        $prev = array();

        if ( ! empty( $settings['alert_reg_enable'] ) ) {
            $prev = wpsa_schedule_parse_results_log_latest_prev(
                $results_log,
                $test_no,
                $url_raw
            );

            if ( empty( $prev ) || empty( $prev['test_id'] ) ) {
                $prev = array();
            }
        }

        foreach ( array( 'mobile', 'desktop' ) as $device ) {

        $check_reg    = in_array( $device, $devices_reg_to_check, true );
        $check_thresh = in_array( $device, $devices_thresh_to_check, true );
        
        // If this device is not eligible for either alert type, skip it.
        if ( ! $check_reg && ! $check_thresh ) {
            continue;
        }


            // Pull current metrics.
            if ( 'mobile' === $device ) {
                $cur_lcp  = $current['m5m_lcp']  ?? null;
                $cur_fcp  = $current['m5m_fcp']  ?? null;
                $cur_cls  = $current['m5m_cls']  ?? null;
                $cur_inp  = $current['m5m_inp']  ?? null;
                $prev_dev = ( ! empty( $prev['mobile'] ) && is_array( $prev['mobile'] ) ) ? $prev['mobile'] : array();
            } else {
                $cur_lcp  = $current['m5d_lcp']  ?? null;
                $cur_fcp  = $current['m5d_fcp']  ?? null;
                $cur_cls  = $current['m5d_cls']  ?? null;
                $cur_inp  = $current['m5d_inp']  ?? null;
                $prev_dev = ( ! empty( $prev['desktop'] ) && is_array( $prev['desktop'] ) ) ? $prev['desktop'] : array();
            }

            // --- Regression alert ---
            if ( $check_reg && ! empty( $settings['alert_reg_enable'] ) && ! empty( $prev ) ) {


                $metrics_to_check = array( 'lcp', 'fcp', 'inp' );
                if ( in_array( $reg_metric, $metrics_to_check, true ) ) {
                    $metrics_to_check = array( $reg_metric );
                }

                foreach ( $metrics_to_check as $mk ) {
                    $cur_raw  = null;
                    $prev_raw = null;

                    if ( 'lcp' === $mk ) {
                        $cur_raw  = $cur_lcp;
                        $prev_raw = $prev_dev['lcp'] ?? null;
                    } elseif ( 'fcp' === $mk ) {
                        $cur_raw  = $cur_fcp;
                        $prev_raw = $prev_dev['fcp'] ?? null;
                    } elseif ( 'inp' === $mk ) {
                        $cur_raw  = $cur_inp;
                        $prev_raw = $prev_dev['inp'] ?? null;
                    }

                    $cur_val  = wpsa_schedule_metric_to_float( (string) $cur_raw, $mk );
                    $prev_val = wpsa_schedule_metric_to_float( (string) $prev_raw, $mk );

                    if ( null === $cur_val || null === $prev_val ) {
                        continue;
                    }

                    if (
                        wpsa_schedule_is_worse( $mk, $cur_val, $prev_val ) &&
                        wpsa_schedule_regression_triggered( $mk, $reg_percent, $cur_val, $prev_val )
                    ) {
                        $alert_triggered = true;

                        if ( empty( $alert_hits[ $test_no ] ) ) {
                            $alert_hits[ $test_no ] = array( 'reasons' => array(), 'devices' => array() );
                        }

                        $alert_hits[ $test_no ]['devices'][ $device ] = true;

                        $alert_hits[ $test_no ]['reasons'][] = sprintf(
                        'Regression alert: %s (%s) worsened by ≥ %s%% compared to previous run.',
                        strtoupper( $mk ),
                        $device,
                        $wpsa_fmt_num( $reg_percent )
                    );

                    }
                }
            }

            // --- Absolute threshold alert ---
            if ( $check_thresh && ! empty( $settings['alert_thresh_enable'] ) && $th_value > 0 ) {


                $cur_raw = null;

                if ( 'lcp' === $th_metric ) {
                    $cur_raw = $cur_lcp;
                } elseif ( 'fcp' === $th_metric ) {
                    $cur_raw = $cur_fcp;
                } elseif ( 'cls' === $th_metric ) {
                    $cur_raw = $cur_cls;
                } elseif ( 'inp' === $th_metric ) {
                    $cur_raw = $cur_inp;
                }

                $cur_val = wpsa_schedule_metric_to_float( (string) $cur_raw, $th_metric );

                if ( null !== $cur_val && wpsa_schedule_threshold_triggered( $th_metric, $cur_val, $th_value ) ) {
                    $alert_triggered = true;

                    if ( empty( $alert_hits[ $test_no ] ) ) {
                        $alert_hits[ $test_no ] = array( 'reasons' => array(), 'devices' => array() );
                    }

                    $alert_hits[ $test_no ]['devices'][ $device ] = true;

                    $th_print = '';
                    if ( 'cls' === $th_metric ) {
                        $th_print = $wpsa_fmt_num( $th_value );
                    } elseif ( 'inp' === $th_metric ) {
                        $th_print = $wpsa_fmt_num( $th_value ) . 'ms';
                    } else {
                        // LCP / FCP are seconds in your logs.
                        $th_print = $wpsa_fmt_num( $th_value ) . 's';
                    }
                    
                    $alert_hits[ $test_no ]['reasons'][] = sprintf(
                        'Absolute threshold alert: %s (%s) is worse than %s.',
                        strtoupper( $th_metric ),
                        $device,
                        $th_print
                    );

                }
            }
        }
    }

    if ( $alert_triggered ) {
        $should_send_email = true;

        // Pass alert context into the email template.
        $batch['alerts_enabled'] = true;
        $batch['alert_hits']     = $alert_hits;
        $batch['alerts_config']  = array(
            'reg_enabled'  => ! empty( $settings['alert_reg_enable'] ),
            'reg_metric'   => $reg_metric,
            'reg_percent'  => $reg_percent,
            'th_enabled'   => ! empty( $settings['alert_thresh_enable'] ),
            'th_metric'    => $th_metric,
            'th_value'     => $th_value,
            'devices_reg'    => $devices_reg_to_check,
            'devices_thresh' => $devices_thresh_to_check,

        );
    }
}


        if ( $should_send_email && $notify_email && is_email( $notify_email ) ) {
            $email_to   = $notify_email;
            $email_sent = (bool) wpsa_schedule_send_batch_email( $batch, $settings, $results_log );
        }



    // Store "last batch" for the Schedule tab UI (it reads wpsa_schedule_last_results).
    update_option(
        'wpsa_schedule_last_results',
        array(
            'ran_at'     => $batch['ran_at'],
            'items'      => $batch['items'],
            'email_sent' => $email_sent,
            'email_to'   => $email_to,
        )
    );

    // Calculate and store the next run time.
    $freq = isset( $settings['frequency'] ) ? (string) $settings['frequency'] : 'daily';
    $time = isset( $settings['time'] ) ? (string) $settings['time'] : '07:30';
    $next_run = wpsa_schedule_calculate_next_run( $freq, $time, $now );
    update_option( 'wpsa_schedule_next_run', (int) $next_run );

    // Clear batch state now that we're done.
    delete_option( 'wpsa_schedule_state' );
    delete_option( 'wpsa_schedule_lock' );
}
    

    // Clear the re-entrancy lock once this tick is fully done.
    delete_option( $lock_key );
}

/**
 * Save Schedule Settings (from the Schedule Tests tab).
 */
add_action( 'admin_post_wpsa_save_schedule', 'wpsa_handle_schedule_form' );
function wpsa_handle_schedule_form() {
    check_admin_referer( 'wpsa_save_schedule', 'wpsa_schedule_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.' );
    }

    $enabled = ! empty( $_POST['wpsa_schedule_enabled'] );

    $freq_raw      = isset( $_POST['wpsa_schedule_frequency'] )
        ? sanitize_text_field( wp_unslash( $_POST['wpsa_schedule_frequency'] ) )
        : 'daily';
    $allowed_freqs = array( 'daily', 'weekly', 'monthly' );
    $frequency     = in_array( $freq_raw, $allowed_freqs, true ) ? $freq_raw : 'daily';

    $time_raw = isset( $_POST['wpsa_schedule_time'] )
        ? sanitize_text_field( wp_unslash( $_POST['wpsa_schedule_time'] ) )
        : '';
    if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time_raw ) ) {
        $time_raw = '07:30';
    }
    $time = $time_raw;

    // URLs per cron tick (chunk size): 2–5, default 3.
   $chunk_raw = isset( $_POST['wpsa_schedule_chunk_size'] )
    ? intval( wp_unslash( $_POST['wpsa_schedule_chunk_size'] ) )
    : 3;
    if ( $chunk_raw < 2 || $chunk_raw > 5 ) {
        $chunk_raw = 3;
    }

        // Email notifications (summary).
    $notify    = ! empty( $_POST['wpsa_schedule_notify'] );

    $email_raw = isset( $_POST['wpsa_schedule_email'] )
        ? sanitize_text_field( wp_unslash( $_POST['wpsa_schedule_email'] ) )
        : '';
    $email = sanitize_email( $email_raw );
    if ( $notify && '' === $email ) {
        $email = get_option( 'admin_email' );
    }

    // Alert emails (new): Regression vs previous run.
    $alert_reg_enable = ! empty( $_POST['wpsa_schedule_alert_reg_enable'] );
    
    // Alert emails (threshold): device scope (both/mobile/desktop).
    $alert_thresh_device_scope_raw = isset( $_POST['wpsa_schedule_alert_thresh_device_scope'] )
        ? sanitize_text_field( wp_unslash( $_POST['wpsa_schedule_alert_thresh_device_scope'] ) )
        : 'both';

    $allowed_scopes = array( 'both', 'mobile', 'desktop' );
    $alert_thresh_device_scope = in_array( $alert_thresh_device_scope_raw, $allowed_scopes, true )
        ? $alert_thresh_device_scope_raw
        : 'both';


    $reg_metric_raw = isset( $_POST['wpsa_schedule_alert_reg_metric'] )
        ? sanitize_text_field( wp_unslash( $_POST['wpsa_schedule_alert_reg_metric'] ) )
        : 'all';
    $allowed_reg_metrics = array( 'all', 'lcp', 'fcp', 'inp' );
    $alert_reg_metric    = in_array( $reg_metric_raw, $allowed_reg_metrics, true ) ? $reg_metric_raw : 'all';

    $reg_percent_raw = isset( $_POST['wpsa_schedule_alert_reg_percent'] )
        ? (int) $_POST['wpsa_schedule_alert_reg_percent']
        : 30;
    if ( $reg_percent_raw < 1 || $reg_percent_raw > 100 ) {
        $reg_percent_raw = 30;
    }
    $alert_reg_percent = $reg_percent_raw;

    $reg_device_raw = isset( $_POST['wpsa_schedule_alert_reg_device_scope'] )
        ? sanitize_text_field( wp_unslash( $_POST['wpsa_schedule_alert_reg_device_scope'] ) )
        : 'both';
    $allowed_reg_devices      = array( 'both', 'mobile', 'desktop' );
    $alert_reg_device_scope   = in_array( $reg_device_raw, $allowed_reg_devices, true ) ? $reg_device_raw : 'both';

        // Alert emails (new): Absolute threshold.
    $alert_thresh_enable = ! empty( $_POST['wpsa_schedule_alert_thresh_enable'] );

    $th_metric_raw = isset( $_POST['wpsa_schedule_alert_thresh_metric'] )
        ? sanitize_text_field( wp_unslash( $_POST['wpsa_schedule_alert_thresh_metric'] ) )
        : 'lcp';
    $allowed_thresh_metrics = array( 'lcp', 'fcp', 'cls', 'inp' );
    $alert_thresh_metric    = in_array( $th_metric_raw, $allowed_thresh_metrics, true ) ? $th_metric_raw : 'lcp';

    // Device scope for absolute threshold alert (both/mobile/desktop).
    // Note: already parsed above as $alert_thresh_device_scope, so do not re-parse/overwrite here.

       $th_value_raw = isset( $_POST['wpsa_schedule_alert_thresh_value'] )
        ? sanitize_text_field( wp_unslash( $_POST['wpsa_schedule_alert_thresh_value'] ) )
        : '';

    // Keep previous saved value if POST comes in empty/invalid (prevents unwanted fallback to defaults).
    $prev_settings = get_option( 'wpsa_schedule_settings', array() );
    $prev_value    = null;
    if ( is_array( $prev_settings ) && isset( $prev_settings['alert_thresh_value'] ) && is_numeric( $prev_settings['alert_thresh_value'] ) ) {
        $prev_value = (float) $prev_settings['alert_thresh_value'];
    }

    // Robust numeric parsing (handles "3", "3.0", "3,0", and trims stray chars).
    $alert_thresh_value = null;

    if ( is_string( $th_value_raw ) ) {
        $th_value_raw = trim( $th_value_raw );
        $th_value_raw = str_replace( ',', '.', $th_value_raw );
        $th_value_raw = preg_replace( '/[^0-9.\-]+/', '', $th_value_raw );

        if ( '' !== $th_value_raw && is_numeric( $th_value_raw ) ) {
            $alert_thresh_value = (float) $th_value_raw;
        }
    } elseif ( is_numeric( $th_value_raw ) ) {
        $alert_thresh_value = (float) $th_value_raw;
    }

    // If invalid/empty, preserve previous saved value (if any).
    if ( null === $alert_thresh_value || $alert_thresh_value < 0 ) {
        if ( null !== $prev_value && $prev_value >= 0 ) {
            $alert_thresh_value = $prev_value;
        } else {
            // Otherwise default to PSI "green" thresholds.
            if ( 'fcp' === $alert_thresh_metric ) {
                $alert_thresh_value = 1.8;
            } elseif ( 'cls' === $alert_thresh_metric ) {
                $alert_thresh_value = 0.10;
            } elseif ( 'inp' === $alert_thresh_metric ) {
                $alert_thresh_value = 200;
            } else {
                $alert_thresh_value = 2.5; // lcp
            }
        }
    }



    // Enforce mutual exclusivity:
    // - Summary checkbox is mutually exclusive with ANY alerts.
    // - Alerts can both be enabled together.
    if ( $alert_reg_enable || $alert_thresh_enable ) {
        $notify = false;
    } elseif ( $notify ) {
        $alert_reg_enable    = false;
        $alert_thresh_enable = false;
    }


    // Prevent automatic results-log trimming (checkbox).
    $prevent_trim = ! empty( $_POST['wpsa_schedule_prevent_trim'] );

    // Optional: restore default values for selected fields.
    // This resets:
    //  - URLs per cron tick → 3
    //  - notification email → admin email
    //  - log trimming → enabled (so "prevent_trim" = false)
    $restore_defaults = ! empty( $_POST['wpsa_schedule_restore_defaults'] );
    if ( $restore_defaults ) {
        $chunk_raw    = 3;
        $email        = get_option( 'admin_email' );
        $prevent_trim = false;
    }


    $urls = array();
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- structured POST map; the outer array is unslashed here and every leaf URL is sanitized with esc_url_raw() inside the loop, per the walk-and-sanitize pattern for nested request maps.
    $urls_post = isset( $_POST['wpsa_schedule_urls'] ) ? (array) wp_unslash( $_POST['wpsa_schedule_urls'] ) : array();
    if ( ! empty( $urls_post ) ) {
        foreach ( $urls_post as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $url = esc_url_raw( isset( $row['url'] ) ? (string) $row['url'] : '' );
            if ( '' === $url ) {
                continue;
            }
            $urls[] = array(
                'url' => $url,
            );
        }
    }

    if ( empty( $urls ) ) {
        $urls[] = array(
            'url' => home_url( '/' ),
        );
    }

        $settings = array(
        'enabled'      => $enabled,
        'frequency'    => $frequency,
        'time'         => $time,
        'urls'         => $urls,
        'chunk_size'   => $chunk_raw,

        // Summary email
        'notify'       => $notify,
        'notify_email' => $email ?: get_option( 'admin_email' ),

        // Alert emails (new)
        'alert_reg_enable'        => (bool) $alert_reg_enable,
        'alert_reg_metric'        => $alert_reg_metric,
        'alert_reg_percent'       => (int) $alert_reg_percent,
        'alert_reg_device_scope'  => $alert_reg_device_scope,

        'alert_thresh_enable'          => (bool) $alert_thresh_enable,
        'alert_thresh_metric'          => $alert_thresh_metric,
        'alert_thresh_value'           => (float) $alert_thresh_value,
        'alert_thresh_device_scope'    => $alert_thresh_device_scope,

        'prevent_trim' => (bool) $prevent_trim,
    );




    update_option( 'wpsa_schedule_settings', $settings );

    // Calculate the next run time from now and store it.
    $next_run = wpsa_schedule_calculate_next_run( $frequency, $time );
    update_option( 'wpsa_schedule_next_run', $next_run );

    // Reset last-results so UI shows fresh state after changes.
    delete_option( 'wpsa_schedule_last_results' );

       // Redirect back to the Schedule tab with a "saved" flag.
    $redirect_args = array(
        'wpsa_schedule_saved' => '1',
    );

    // If tests are disabled but URLs are saved, warn the user that nothing will run.
    if ( ! $enabled && ! empty( $urls ) ) {
        $redirect_args['wpsa_schedule_warn_disabled'] = '1';
    }

    $redirect_url = add_query_arg(
        $redirect_args,
        admin_url( 'tools.php?page=speed-analyzer&tab=schedule' )
    );

    wp_safe_redirect( $redirect_url );
    exit;
}


/**
 * Return the HTML for the "Previous scheduled test batch" block.
 *
 * Used both in the Schedule tab UI and by the AJAX refresher.
 *
 * @return string
 */
function wpsa_schedule_get_last_batch_html() {
    ob_start();

    $last_batch = get_option( 'wpsa_schedule_last_results' );
    if ( ! is_array( $last_batch ) || empty( $last_batch['items'] ) ) :
        ?>
        <p class="description">
            <?php esc_html_e( 'No scheduled test runs have been recorded yet.', 'speed-analyzer' ); ?>
        </p>
        <?php
    else :
        $ran_at = isset( $last_batch['ran_at'] )
            ? (int) $last_batch['ran_at']
            : 0;

        if ( $ran_at ) {
            echo '<p class="description">';

            $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

            if ( function_exists( 'wp_date' ) ) {
                $ran_human = wp_date( $format, $ran_at );
            } else {
                $ran_human = date_i18n( $format, $ran_at );
            }

            printf(
                /* translators: %s: date/time of last batch */
                esc_html__( 'Last batch ran on %s.', 'speed-analyzer' ),
                esc_html( $ran_human )
            );
            echo '</p>';
        }

        echo '<ul class="wpsa-schedule-last-list">';
        foreach ( $last_batch['items'] as $item ) {
            $url     = isset( $item['url'] ) ? $item['url'] : '';
            $success = ! empty( $item['success'] );
            $test_no = isset( $item['test_no'] ) ? $item['test_no'] : null;
            $msg     = isset( $item['message'] ) ? $item['message'] : '';
            $icon    = $success ? '✅' : '❌';

            echo '<li>';
            if ( $test_no ) {
                printf(
                    'Test #%1$d – %2$s %3$s',
                    (int) $test_no,
                    esc_html( $url ),
                    esc_html( $icon )
                );
            } else {
                printf(
                    '%1$s %2$s',
                    esc_html( $url ),
                    esc_html( $icon )
                );
            }
            if ( $msg ) {
                echo ' – ' . esc_html( $msg );
            }
            echo '</li>';
        }
        echo '</ul>';
        if ( ! empty( $last_batch['email_sent'] ) ) {
            $to = ! empty( $last_batch['email_to'] ) ? (string) $last_batch['email_to'] : '';
            if ( $to && is_email( $to ) ) {
                echo '<p class="description" style="margin-top:6px; font-weight:600; color: #3c434a;">' .
                    /* translators: %s: recipient email address. */
                    esc_html( sprintf( __( 'Email sent to %s.', 'speed-analyzer' ), $to ) ) .
                '</p>';
            } else {
                echo '<p class="description" style="margin-top:6px; font-weight:600; color: #3c434a;">' .
                    esc_html__( 'Email sent.', 'speed-analyzer' ) .
                '</p>';
            }
        }

    endif;

    return ob_get_clean();
}

/**
 * Render Schedule Tests Panel (UI).
 */
function wpsa_render_schedule_panel_ui() {
    $admin_email = get_option( 'admin_email' );
        $defaults    = array(
        'enabled'      => true,
        'frequency'    => 'daily',
        'time'         => '07:30',
        'urls'         => array(
            array(
                'url' => home_url( '/' ),
            ),
        ),

        // Email options
        'notify'       => false,
        'notify_email' => $admin_email,

        // Alert options (new)
        'alert_reg_enable'        => false,
        'alert_reg_metric'        => 'all',   // all|lcp|fcp|inp (no CLS here)
        'alert_reg_percent'       => 30,      // integer %
        'alert_reg_device_scope'  => 'both',  // both|mobile|desktop

        'alert_thresh_enable'     => false,
        'alert_thresh_metric'     => 'lcp',   // lcp|fcp|cls|inp
        'alert_thresh_value'      => 2.5,     // numeric (unit depends on metric)
         'alert_thresh_device_scope'  => 'both',  // both|mobile|desktop

        // Schedule extras: defaults
        'chunk_size'   => 3,
        'prevent_trim' => false,
    );



    $saved = get_option( 'wpsa_schedule_settings', array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }
    $settings = wp_parse_args( $saved, $defaults );

    // URLs per cron tick (chunk size) – UI normalization.
    $chunk_size_ui = isset( $settings['chunk_size'] ) ? (int) $settings['chunk_size'] : 3;
    if ( $chunk_size_ui < 2 || $chunk_size_ui > 5 ) {
        $chunk_size_ui = 3;
    }

    // Server max_execution_time info for UI.
    $max_exec_raw = (int) ini_get( 'max_execution_time' );
    if ( $max_exec_raw <= 0 ) {
        $max_exec_label = __( 'Not limited (0 reported by PHP)', 'speed-analyzer' );
    } else {
        $max_exec_label = sprintf(
            /* translators: %d: max_execution_time in seconds */
            _n( '%d second', '%d seconds', $max_exec_raw, 'speed-analyzer' ),
            $max_exec_raw
        );
    }

    $max_exec_tip = __(
        'This is the maximum time a single PHP request is allowed to run on your hosting. Scheduled tests are split into smaller batches so they stay within this limit.',
        'speed-analyzer'
    );


    // Normalize URLs list.
    $urls = array();
    if ( ! empty( $settings['urls'] ) && is_array( $settings['urls'] ) ) {
        foreach ( $settings['urls'] as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $url = isset( $row['url'] ) ? (string) $row['url'] : '';
            $url = esc_url_raw( $url );

            if ( '' === $url ) {
                continue;
            }
            $urls[] = array(
                'url' => $url,
            );
        }
    }

    if ( empty( $urls ) ) {
        $urls = $defaults['urls'];
    }

    // Render exactly the number of stored URLs; extra rows are added via JS.
    $max_rows  = count( $urls );
    $tz        = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : get_option( 'timezone_string' );
    $site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

    // Same lock icon behavior as main Tests tab.
    $is_unlocked  = in_array( wpsa_get_license_tier(), array( 'premium3' ), true );

    $lock_tooltip = $is_unlocked
        ? __( 'Unlocked: you may test any URL', 'speed-analyzer' )
        : __( 'Locked: Only same-host URLs are allowed on this plan. Only the Agency plan has this unlocked.', 'speed-analyzer' );
    $icon         = $is_unlocked ? '🔓' : '🔒';

    // Example cron command for reliability hint.
    $cron_example = '*/5 * * * * curl -s ' . home_url( '/wp-cron.php?doing_wp_cron=1' ) . ' >/dev/null 2>&1';

         ?>
    <!-- Header: same layout as Tests tab, outside the white card -->
    <h1 class="wpsa-header">
        <img class="wpsa-logo"
             src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'SAWP-logo.svg' ); ?>"
             alt="Speed Analyzer Logo">
        <div class="wpsa-header-info">
            <span class="wpsa-name">Speed Analyzer</span>
            <small class="wpsa-version">v<?php echo esc_html( SAWP_VERSION ); ?></small>
            <p class="wpsa-header-subtitle"><?php esc_html_e( 'A first step towards a faster website', 'speed-analyzer' ); ?></p>
            <p id="wpsa-header-credit">
                <?php esc_html_e( 'Developed by Dalibor Druzinec /', 'speed-analyzer' ); ?>
                <a href="https://wpservice.pro/" target="_blank">WPservice.pro</a>
            </p>
        </div>
    </h1>

       <div id="wpsa-schedule-panel" class="wrap wpsa-schedule-wrap" style="max-width:900px;margin:0 auto;">

        <h2 class="wpsa-module-title"><?php esc_html_e( 'Schedule tests', 'speed-analyzer' ); ?></h2>


        <p class="description">
            <?php esc_html_e( 'Configure automatic Speed Analyzer test runs for one or more URLs.', 'speed-analyzer' ); ?>
        </p>

        <div class="wpsa-schedule-card" style="position:relative;z-index:100;background:#ffffff;border-radius:15px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.06);">

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wpsa_save_schedule', 'wpsa_schedule_nonce' ); ?>
                <input type="hidden" name="action" value="wpsa_save_schedule">

                <h3><?php esc_html_e( 'Schedule settings', 'speed-analyzer' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row">
                            <label for="wpsa_schedule_enabled"><?php esc_html_e( 'Enable scheduled tests', 'speed-analyzer' ); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       id="wpsa_schedule_enabled"
                                       name="wpsa_schedule_enabled"
                                       value="1"
                                    <?php checked( ! empty( $settings['enabled'] ) ); ?> />
                                <?php esc_html_e( 'Run tests automatically according to the schedule below.', 'speed-analyzer' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e( 'Frequency', 'speed-analyzer' ); ?></th>
                        <td>
                            <label style="margin-right:16px;">
                                <input type="radio"
                                       name="wpsa_schedule_frequency"
                                       value="daily"
                                    <?php checked( $settings['frequency'], 'daily' ); ?> />
                                <?php esc_html_e( 'Daily', 'speed-analyzer' ); ?>
                            </label>
                            <label style="margin-right:16px;">
                                <input type="radio"
                                       name="wpsa_schedule_frequency"
                                       value="weekly"
                                    <?php checked( $settings['frequency'], 'weekly' ); ?> />
                                <?php esc_html_e( 'Weekly', 'speed-analyzer' ); ?>
                            </label>
                            <label>
                                <input type="radio"
                                       name="wpsa_schedule_frequency"
                                       value="monthly"
                                    <?php checked( $settings['frequency'], 'monthly' ); ?> />
                                <?php esc_html_e( 'Monthly', 'speed-analyzer' ); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wpsa_schedule_time"><?php esc_html_e( 'Time of day', 'speed-analyzer' ); ?></label>
                        </th>
                        <td>
                            <input type="time"
                                   id="wpsa_schedule_time"
                                   name="wpsa_schedule_time"
                                   value="<?php echo esc_attr( $settings['time'] ); ?>"
                                   class="regular-text"
                                   step="60"
                                   style="max-width: 150px;">
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: timezone string */
                                    esc_html__( 'Uses the site timezone (%s).', 'speed-analyzer' ),
                                    esc_html( $tz ?: 'UTC' )
                                );
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'URLs to test', 'speed-analyzer' ); ?>
                        </th>
                        <td>
                            <p class="description">
                                <?php esc_html_e( 'Schedule multiple URLs. You are still limited by your plan\'s daily limit.', 'speed-analyzer' ); ?>
                            </p>

                                                            <div id="wpsa-schedule-url-list">
                                <?php for ( $i = 0; $i < $max_rows; $i++ ) : ?>
                                    <?php
                                    $row  = $urls[ $i ] ?? array( 'url' => '' );
                                    $uval = $row['url'];

                                    if ( 0 === $i && '' === $uval ) {
                                        $uval = home_url( '/' );
                                    }

                                    // Highlight off-host URLs for non-Agency plans (UI only).
                                    $is_off_host = false;
                                    if ( $site_host && ! empty( $uval ) ) {
                                        $target_host = wp_parse_url( $uval, PHP_URL_HOST );
                                        if ( $target_host && ! preg_match( '/(^|\.)' . preg_quote( $site_host, '/' ) . '$/i', $target_host ) ) {
                                            $is_off_host = true;
                                        }
                                    }

                                    $row_style = 'margin-bottom:8px;';
                                    if ( ! $is_unlocked && $is_off_host ) {
                                        // light red background to highlight off-host URLs
                                        $row_style .= 'background:#ffecec;border-radius:4px;padding:4px 6px;';
                                    }

                                    // Native tooltip on the input itself for off-host URLs.
                                    $input_title = ( ! $is_unlocked && $is_off_host ) ? $lock_tooltip : '';
                                    ?>
                                    <div class="wpsa-schedule-url-row" style="<?php echo esc_attr( $row_style ); ?>">
                                        <div class="wpsa-url-field" style="width:100%;display:flex;align-items:center;">

                                            <input type="text"
                                                   class="regular-text"
                                                   name="wpsa_schedule_urls[<?php echo (int) $i; ?>][url]"
                                                   value="<?php echo esc_attr( $uval ); ?>"
                                                   placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
                                                   style="flex:1;"
                                                   title="<?php echo esc_attr( $input_title ); ?>">
                                            <span class="wpsa-url-lock wpsa-lock-tooltip"
                                                  style="margin-left:6px;"
                                                  data-tooltip="<?php echo esc_attr( $lock_tooltip ); ?>">
                                                <?php echo esc_html( $icon ); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>


                            <p>
                                <button type="button" class="button wpsa-schedule-add-url">
                                    + <?php esc_html_e( 'Add New', 'speed-analyzer' ); ?>
                                </button>
                            </p>
                        </td>
                    </tr>

                                        <tr>
                        <th scope="row"><?php esc_html_e( 'Email notifications', 'speed-analyzer' ); ?></th>
                        <td>
                            <label style="display:block;margin-bottom:8px;">
                                <input
                                    type="checkbox"
                                    id="wpsa_schedule_notify"
                                    name="wpsa_schedule_notify"
                                    value="1"
                                    <?php checked( ! empty( $settings['notify'] ) ); ?>
                                />
                                <?php esc_html_e( 'Send an email summary after each scheduled batch run.', 'speed-analyzer' ); ?>
                            </label>

                            <input
                                type="email"
                                name="wpsa_schedule_email"
                                value="<?php echo esc_attr( $settings['notify_email'] ); ?>"
                                class="regular-text"
                                style="max-width: 280px;"
                            />

                            <p class="description" style="margin-top:6px;">
                                <?php
                                printf(
                                    /* translators: %s: admin email */
                                    esc_html__( 'Default is the site admin email (%s).', 'speed-analyzer' ),
                                    esc_html( $admin_email )
                                );
                                ?>
                            </p>

                            <div id="wpsa-schedule-alerts-wrap" style="margin-top:18px;padding-top:14px;border-top:1px solid #e6e6e6;">
                                <p style="margin:0 0 10px 0;font-weight:600;">
                                    <?php esc_html_e( 'Alert emails (only when something is off)', 'speed-analyzer' ); ?>
                                </p>

                                <p class="description" style="margin-top:0;">
                                    <?php esc_html_e( 'Alerts send the usual email report, but only when one of these conditions is met. If alerts are enabled, the “email summary” option above is disabled.', 'speed-analyzer' ); ?>
                                </p>

                                <div class="wpsa-schedule-alert-box" style="margin:12px 0 16px 0;">
                                    <label style="display:block;margin-bottom:10px;">
                                        <input
                                            type="checkbox"
                                            id="wpsa_schedule_alert_reg_enable"
                                            name="wpsa_schedule_alert_reg_enable"
                                            value="1"
                                            <?php checked( ! empty( $settings['alert_reg_enable'] ) ); ?>
                                        />
                                        <?php esc_html_e( 'Regression alert (compared to previous scheduled run)', 'speed-analyzer' ); ?>
                                    </label>

                                    <div class="wpsa-schedule-alert-reg-fields" style="padding-left:22px;">
                                        <div style="margin-bottom:8px;">
                                            <label for="wpsa_schedule_alert_reg_metric" style="display:inline-block;min-width:160px;">
                                                <?php esc_html_e( 'Metric to watch', 'speed-analyzer' ); ?>
                                            </label>
                                            <select id="wpsa_schedule_alert_reg_metric" name="wpsa_schedule_alert_reg_metric">
                                                <option value="all" <?php selected( $settings['alert_reg_metric'], 'all' ); ?>>
                                                    <?php esc_html_e( 'All metrics', 'speed-analyzer' ); ?>
                                                </option>
                                                <option value="lcp" <?php selected( $settings['alert_reg_metric'], 'lcp' ); ?>>LCP</option>
                                                <option value="fcp" <?php selected( $settings['alert_reg_metric'], 'fcp' ); ?>>FCP</option>
                                                <option value="inp" <?php selected( $settings['alert_reg_metric'], 'inp' ); ?>>INP</option>
                                            </select>
                                        </div>

                                        <div style="margin-bottom:8px;">
                                            <label for="wpsa_schedule_alert_reg_percent" style="display:inline-block;min-width:160px;">
                                                <?php esc_html_e( 'Trigger when worsened by', 'speed-analyzer' ); ?>
                                            </label>
                                            <input
                                                type="number"
                                                id="wpsa_schedule_alert_reg_percent"
                                                name="wpsa_schedule_alert_reg_percent"
                                                value="<?php echo esc_attr( (int) $settings['alert_reg_percent'] ); ?>"
                                                min="1"
                                                max="100"
                                                step="1"
                                                style="max-width:90px;"
                                            />
                                            <span style="margin-left:4px;">%</span>
                                        </div>

                                        <div style="margin-bottom:6px;">
                                            <label for="wpsa_schedule_alert_reg_device_scope" style="display:inline-block;min-width:160px;">
                                                <?php esc_html_e( 'Device scope', 'speed-analyzer' ); ?>
                                            </label>
                                            <select id="wpsa_schedule_alert_reg_device_scope" name="wpsa_schedule_alert_reg_device_scope">
                                                <option value="both" <?php selected( $settings['alert_reg_device_scope'], 'both' ); ?>>
                                                    <?php esc_html_e( 'Both (mobile + desktop)', 'speed-analyzer' ); ?>
                                                </option>
                                                <option value="mobile" <?php selected( $settings['alert_reg_device_scope'], 'mobile' ); ?>>
                                                    <?php esc_html_e( 'Mobile only', 'speed-analyzer' ); ?>
                                                </option>
                                                <option value="desktop" <?php selected( $settings['alert_reg_device_scope'], 'desktop' ); ?>>
                                                    <?php esc_html_e( 'Desktop only', 'speed-analyzer' ); ?>
                                                </option>
                                            </select>
                                        </div>

                                        <p class="description" style="margin-top:6px;">
                                            <?php esc_html_e( 'Compared to the last successful scheduled run for the same URL and device.', 'speed-analyzer' ); ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="wpsa-schedule-alert-box" style="margin:12px 0 6px 0;">
                                    <label style="display:block;margin-bottom:10px;">
                                        <input
                                            type="checkbox"
                                            id="wpsa_schedule_alert_thresh_enable"
                                            name="wpsa_schedule_alert_thresh_enable"
                                            value="1"
                                            <?php checked( ! empty( $settings['alert_thresh_enable'] ) ); ?>
                                        />
                                        <?php esc_html_e( 'Absolute threshold alert', 'speed-analyzer' ); ?>
                                    </label>

                                    <div class="wpsa-schedule-alert-thresh-fields" style="padding-left:22px;">
                                        <div style="margin-bottom:8px;">
                                            <label for="wpsa_schedule_alert_thresh_metric" style="display:inline-block;min-width:160px;">
                                                <?php esc_html_e( 'Metric', 'speed-analyzer' ); ?>
                                            </label>
                                            <select id="wpsa_schedule_alert_thresh_metric" name="wpsa_schedule_alert_thresh_metric">
                                                <option value="lcp" <?php selected( $settings['alert_thresh_metric'], 'lcp' ); ?>>LCP</option>
                                                <option value="fcp" <?php selected( $settings['alert_thresh_metric'], 'fcp' ); ?>>FCP</option>
                                                <option value="cls" <?php selected( $settings['alert_thresh_metric'], 'cls' ); ?>>CLS</option>
                                                <option value="inp" <?php selected( $settings['alert_thresh_metric'], 'inp' ); ?>>INP</option>
                                            </select>
                                        </div>
                                        
                                        <div style="margin-bottom:8px;">
                                            <label for="wpsa_schedule_alert_thresh_device_scope" style="display:inline-block;min-width:160px;">
                                                <?php esc_html_e( 'Device scope', 'speed-analyzer' ); ?>
                                            </label>
                                            <select id="wpsa_schedule_alert_thresh_device_scope" name="wpsa_schedule_alert_thresh_device_scope">
                                                <option value="both" <?php selected( $settings['alert_thresh_device_scope'], 'both' ); ?>>
                                                    <?php esc_html_e( 'Both (mobile + desktop)', 'speed-analyzer' ); ?>
                                                </option>
                                                <option value="mobile" <?php selected( $settings['alert_thresh_device_scope'], 'mobile' ); ?>>
                                                    <?php esc_html_e( 'Mobile only', 'speed-analyzer' ); ?>
                                                </option>
                                                <option value="desktop" <?php selected( $settings['alert_thresh_device_scope'], 'desktop' ); ?>>
                                                    <?php esc_html_e( 'Desktop only', 'speed-analyzer' ); ?>
                                                </option>
                                            </select>
                                        </div>


                                        <div style="margin-bottom:6px;">
                                            <label for="wpsa_schedule_alert_thresh_value" style="display:inline-block;min-width:160px;">
                                                <?php esc_html_e( 'Alert if worse than', 'speed-analyzer' ); ?>
                                            </label>

                                           <input
                                                type="number"
                                                id="wpsa_schedule_alert_thresh_value"
                                                name="wpsa_schedule_alert_thresh_value"
                                                value="<?php echo esc_attr( (string) $settings['alert_thresh_value'] ); ?>"
                                                data-saved="<?php echo esc_attr( (string) $settings['alert_thresh_value'] ); ?>"
                                                step="0.1"
                                                min="0"
                                                style="max-width:110px;"
                                            />
                                            <span id="wpsa_schedule_alert_thresh_suffix" style="margin-left:6px;"></span>

                                        </div>

                                        <p class="description" style="margin-top:6px;">
                                            <?php esc_html_e( 'Defaults match PSI “green” thresholds (LCP 2.5s, FCP 1.8s, CLS 0.10, INP 200ms).', 'speed-analyzer' ); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>

                    
                 <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'URLs per cron tick', 'speed-analyzer' ); ?>
                        </th>
                        <td>
                            <p class="description" style="margin-bottom:6px;">
                                <?php esc_html_e( 'How many scheduled URLs to process on each cron tick. Default value 3, lower to 2 if your server has low max execution time.', 'speed-analyzer' ); ?>
                                <span
                                    class="custom-tooltip"
                                    data-tooltip="<?php echo esc_attr( __( 'Lower values are safer on slower hosts; higher values finish large batches faster but use more PHP time per tick.', 'speed-analyzer' ) ); ?>"
                                    style="margin-left:6px;"
                                >
                                    ?
                                </span>
                            </p>
                            <select name="wpsa_schedule_chunk_size">
                                <?php for ( $i = 2; $i <= 5; $i++ ) : ?>
                                    <option value="<?php echo (int) $i; ?>" <?php selected( $chunk_size_ui, $i ); ?>>
                                        <?php
                                        printf(
                                            /* translators: %d: urls per cron tick */
                                            esc_html__( '%d URL(s) per cron tick', 'speed-analyzer' ),
                                            (int) $i
                                        );
                                        ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Your server max execution time', 'speed-analyzer' ); ?>
                        </th>
                        <td>
                            <p class="description">
                                <?php echo esc_html( $max_exec_label ); ?>
                                <span
                                    class="custom-tooltip"
                                    data-tooltip="<?php echo esc_attr( $max_exec_tip ); ?>"
                                    style="margin-left:6px;"
                                >
                                    ?
                                </span>
                            </p>
                        </td>
                    </tr>


                    <tr>
                    <th scope="row"><?php esc_html_e( 'Currently scheduled test batch', 'speed-analyzer' ); ?></th>
                    <td>
                        <?php
                        // Detect if a batch is currently in progress (any URL with phase < 3).
                        $state_for_flag   = get_option( 'wpsa_schedule_state', array() );
                        $has_running_batch = false;
                
                        if ( ! empty( $state_for_flag['urls'] ) && is_array( $state_for_flag['urls'] ) ) {
                            foreach ( $state_for_flag['urls'] as $row_flag ) {
                                if ( ! is_array( $row_flag ) ) {
                                    continue;
                                }
                                $phase_flag = isset( $row_flag['phase'] ) ? (int) $row_flag['phase'] : 0;
                                if ( $phase_flag < 3 ) {
                                    $has_running_batch = true;
                                    break;
                                }
                            }
                        }
                
                        $ajax_nonce = wp_create_nonce( 'wpsa_schedule_status' );
                        ?>
                        <div
                            id="wpsa-schedule-last-batch-wrapper"
                            data-status="<?php echo $has_running_batch ? 'running' : 'idle'; ?>"
                            data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
                            data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>"
                        >
                            <div id="wpsa-schedule-last-batch-inner">
                                <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpsa_schedule_get_last_batch_html() returns markup whose dynamic parts (url, message, icon, recipient) all pass through esc_html(). ?>
                                <?php echo wpsa_schedule_get_last_batch_html(); ?>
                            </div>
                
                            <p
                                id="wpsa-schedule-batch-running-msg"
                                style="<?php echo $has_running_batch ? 'margin-top:4px;color:#d0832c;font-style:italic;' : 'display:none;margin-top:4px;color:#d0832c;font-style:italic;'; ?>"
                            >
                                <?php esc_html_e( 'Currently running scheduled tests…', 'speed-analyzer' ); ?>
                            </p>
                        </div>
                    </td>
                </tr>

                    
                       <tr>
            <th scope="row"><?php esc_html_e( 'Next scheduled run', 'speed-analyzer' ); ?></th>
            <td>
                <?php
                $next_run_ts = (int) get_option( 'wpsa_schedule_next_run', 0 );

                                if ( $next_run_ts > 0 ) {
                    echo '<p class="description">';

                    $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

                    // Render using the site timezone (stored value is UTC).
                    if ( function_exists( 'wp_date' ) ) {
                        $scheduled_human = wp_date( $format, $next_run_ts );
                        $next_tick_utc   = (int) ceil( $next_run_ts / 300 ) * 300; // 5-min ticks
                        $tick_human      = wp_date( $format, $next_tick_utc );
                    } else {
                        // Fallback for very old WP – still better than nothing.
                        $scheduled_human = date_i18n( $format, $next_run_ts );
                        $next_tick_utc   = (int) ceil( $next_run_ts / 300 ) * 300;
                        $tick_human      = date_i18n( $format, $next_tick_utc );
                    }

                    printf(
                        /* translators: 1: date and time the next run is scheduled for, 2: date and time of the next cron tick. */
                        wp_kses_post( __(
                            'Next run is scheduled for %1$s. The cron system runs <strong>every 5 minutes</strong>, so it will execute at the next cron tick: %2$s.',
                            'speed-analyzer'
                        ) ),
                        '<strong>' . esc_html( $scheduled_human ) . '</strong>',
                        '<strong>' . esc_html( $tick_human ) . '</strong>'
                    );

                    echo '</p>';

                } else {
                    ?>
                    <p class="description">
                        <?php esc_html_e( 'No next run has been scheduled yet. Save your schedule to set one.', 'speed-analyzer' ); ?>
                    </p>
                    <?php
                }
                ?>
            </td>
        </tr>
        
               <tr>
                        <th scope="row"><?php esc_html_e( '*Reliability tip', 'speed-analyzer' ); ?></th>
                        <td>
                            <p class="description">
                                <?php esc_html_e( 'WordPress cron only runs when someone visits your site. For fully reliable schedules, configure a real server cron job to call wp-cron.php.', 'speed-analyzer' ); ?>
                            </p>
                            <code><?php echo esc_html( $cron_example ); ?></code>
                             <p class="description">
                                <?php esc_html_e( 'If you do not receive results emails, please check your spam folder.', 'speed-analyzer' ); ?>
                            </p>
                        </td>
                    </tr>
                    
                <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Prevent automatic results log trimming', 'speed-analyzer' ); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="wpsa_schedule_prevent_trim"
                                       value="1"
                                    <?php checked( ! empty( $settings['prevent_trim'] ) ); ?> />
                                <?php esc_html_e( 'Do not automatically trim older entries from the test results log.', 'speed-analyzer' ); ?>
                                    <span
                                    class="custom-tooltip"
                                    data-tooltip="<?php echo esc_attr( __( 'When the results log reaches 1000 tests, older entries are purged so only the newest 100 results remain. Turning this off disables that safety net and the log file may become very large over time.', 'speed-analyzer' ) ); ?>"
                                    style="margin-left:6px;"
                                >
                                    ?
                                </span>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'By default, Speed Analyzer trims the results log when it grows very large, keeping only the most recent tests. Enabling this option keeps the full history but can make the log file grow without limit.', 'speed-analyzer' ); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Restore default values', 'speed-analyzer' ); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="wpsa_schedule_restore_defaults"
                                       value="1" />
                                <?php esc_html_e( 'Restore the default schedule settings for this page when saving.', 'speed-analyzer' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'This resets the URLs-per-cron-tick value to 3, uses the site admin email for notifications, and re-enables automatic results log trimming. Other fields, like frequency and time of day, stay as you have set them.', 'speed-analyzer' ); ?>
                            </p>
                        </td>
                    </tr>
                    
                     <tr>
                        <td colspan="2">
                            <p class="description">
                                <?php
                                printf(
                                    wp_kses_post(
                                        /* translators: %s: URL of the Compare Results screen. */
                                        __(
                                            'You can find all of the scheduled test results under the <a href="%s">Compare Results</a> section of the plugin.',
                                            'speed-analyzer'
                                        )
                                    ),
                                    esc_url( admin_url( 'admin.php?page=speed-analyzer&tab=compare' ) ) 
                                );
                                ?>
                            </p>
                        </td>
                    </tr>



                    
                    </tbody>
                </table>

             <?php
              // Inline “saved” flag (from ?wpsa_schedule_saved=1).
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only post-redirect notice flag; the screen is registered via add_management_page( ..., 'manage_options', ... ) so the capability is enforced before this renders, and the flag only decides whether a success notice is shown.
                $wpsa_schedule_saved = isset( $_GET['wpsa_schedule_saved'] ) && '1' === $_GET['wpsa_schedule_saved'];

                // Warning flag (from ?wpsa_schedule_warn_disabled=1).
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only post-redirect notice flag; see note above.
                $wpsa_schedule_warn_disabled = isset( $_GET['wpsa_schedule_warn_disabled'] ) && '1' === $_GET['wpsa_schedule_warn_disabled'];
                ?>
                <p style="margin-top:18px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save schedule', 'speed-analyzer' ); ?>
                    </button>

                    <span
                        id="wpsa-schedule-inline-saved"
                        class="wpsa-schedule-inline-saved"
                        style="<?php echo $wpsa_schedule_saved ? 'color:#22863a;font-weight:500;' : 'display:none;color:#22863a;font-weight:500;'; ?>"
                    >
                        <?php esc_html_e( 'Schedule saved successfully.', 'speed-analyzer' ); ?>
                    </span>

                    <span
                        id="wpsa-schedule-inline-warn-disabled"
                        class="wpsa-schedule-inline-warn-disabled"
                        style="<?php echo $wpsa_schedule_warn_disabled ? 'color:#b32d2e;font-weight:600;' : 'display:none;color:#b32d2e;font-weight:600;'; ?>"
                    >
                        <?php esc_html_e( 'Scheduled tests are currently disabled. Enable the scheduled tests option above, and save again, to run the schedule you’ve set.', 'speed-analyzer' ); ?>
                    </span>
                </p>
            </form>
               </div><!-- .wpsa-schedule-card -->
    </div><!-- .wpsa-schedule-wrap -->
    
    <script>
    (function () {
      function setSuffixAndStep(metricSel, valueInput, suffixEl) {
        var metric = (metricSel && metricSel.value) ? metricSel.value : 'lcp';
    
        if (!suffixEl || !valueInput) return;
    
        if (metric === 'inp') {
          suffixEl.textContent = 'ms';
          valueInput.step = '1';
        } else if (metric === 'cls') {
          suffixEl.textContent = '';
          valueInput.step = '0.01';
        } else {
          suffixEl.textContent = 's';
          valueInput.step = '0.1';
        }
      }
    
      document.addEventListener('DOMContentLoaded', function () {
        var metricSel  = document.getElementById('wpsa_schedule_alert_thresh_metric');
        var valueInput = document.getElementById('wpsa_schedule_alert_thresh_value');
        var suffixEl   = document.getElementById('wpsa_schedule_alert_thresh_suffix');
    
        if (!valueInput) return;
    
        // Let any existing schedule JS run first, then restore the saved value.
        window.setTimeout(function () {
          var saved = valueInput.getAttribute('data-saved');
          if (saved !== null && saved !== '') {
            valueInput.value = saved;
          }
          setSuffixAndStep(metricSel, valueInput, suffixEl);
        }, 0);
    
        if (metricSel) {
          metricSel.addEventListener('change', function () {
            // On metric change, only adjust suffix/step; do NOT overwrite user value.
            setSuffixAndStep(metricSel, valueInput, suffixEl);
          });
        }
      });
    })();
    </script>
    
    <?php
} // end wpsa_render_schedule_panel_ui()

/**
 * AJAX: return current batch status + refreshed "Previous scheduled test batch" HTML.
 */
add_action( 'wp_ajax_wpsa_schedule_batch_status', 'wpsa_schedule_ajax_batch_status' );
function wpsa_schedule_ajax_batch_status() {
    check_ajax_referer( 'wpsa_schedule_status' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error(
            array(
                'message' => __( 'You do not have permission to view this data.', 'speed-analyzer' ),
            )
        );
    }

    // Determine if a batch is still running: any URL with phase < 3.
    $state   = get_option( 'wpsa_schedule_state', array() );
    $running = false;

    if ( ! empty( $state['urls'] ) && is_array( $state['urls'] ) ) {
        foreach ( $state['urls'] as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $phase = isset( $row['phase'] ) ? (int) $row['phase'] : 0;
            if ( $phase < 3 ) {
                $running = true;
                break;
            }
        }
    }

    // Always return the latest *completed* batch summary.
    $html = wpsa_schedule_get_last_batch_html();

    wp_send_json_success(
        array(
            'running' => $running,
            'html'    => $html,
        )
    );
}
