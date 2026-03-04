<?php
/**
 * Speed Analyzer – Performance Diagnostics (Module 5 & logging)
 *diagnostics.php
 * @package   Speed_Analyzer
 * @version   v0.711
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Map a Lighthouse audit to PSI-style severity.
 * - Prefer audit["score"] (0..1)
 * - Fall back to overallSavingsMs/overallSavingsBytes for Opportunities
 */
function wpsa_map_severity( $audit ) {
    $score = isset( $audit['score'] ) && is_numeric( $audit['score'] )
        ? (float) $audit['score']
        : null;

    if ( $score !== null ) {
        if ( $score < 0.50 ) return 'high';
        if ( $score < 0.90 ) return 'moderate';
        return 'low';
    }

    // No score? Heuristic for opportunities
    $ms    = $audit['details']['overallSavingsMs']    ?? null;
    $bytes = $audit['details']['overallSavingsBytes'] ?? null;

    if ( $ms !== null ) {
        if ( $ms >= 1000 ) return 'high';
        if ( $ms >= 100  ) return 'moderate';
        return 'low';
    }
    if ( $bytes !== null ) {
        $kib = $bytes / 1024;
        if ( $kib >= 250 ) return 'high';
        if ( $kib >= 25  ) return 'moderate';
        return 'low';
    }
    return 'info';
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
        echo '3. Performance &amp; Diagnostics ';
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

        // ─── MOBILE PANEL ───────────────────────────────────────────────────────────
        echo '<div id="perf-mobile" class="metrics" style="page-break-inside:avoid;">';
          echo '<h3 class="wpsa-subheading">Mobile results</h3>';

          // Header row: performance score + PSI screenshot preview
          echo '<div class="wpsa-perf-header-row">';
            echo '<div class="performance-circle wpsa-performance-circle" id="perf-circle-mobile"><span class="perf-text wpsa-perf-text">--</span></div>';
            echo '<div class="wpsa-psi-screenshot-box wpsa-psi-screenshot-mobile">';
              echo '<img id="wpsa-psi-screenshot-img-mobile" class="wpsa-psi-screenshot-img" src="" alt="" />';
            echo '</div>';
          echo '</div>';

          echo '<h3>Lab metrics</h3>';
          echo '<div class="wpsa-stat-cards photo">';

            echo '<div class="wpsa-stat-card wpsa-card-lcp"><div class="header">LCP <span class="custom-tooltip" data-tooltip="Largest Contentful Paint marks the time at which the largest text or image is painted.">?</span></div><div class="value" id="lcp-mobile">--</div></div>';
            echo '<div class="wpsa-stat-card wpsa-card-fcp"><div class="header">FCP <span class="custom-tooltip" data-tooltip="First Contentful Paint marks the time at which the first text or image is painted.">?</span></div><div class="value" id="fcp-mobile">--</div></div>';
              echo '<div class="wpsa-stat-card wpsa-card-cls"><div class="header">CLS <span class="custom-tooltip" data-tooltip="Cumulative Layout Shift measures visual stability.">?</span></div><div class="value" id="cls-mobile">--</div></div>';
            echo '<div class="wpsa-stat-card wpsa-card-inp"><div class="header">INP <span class="custom-tooltip" data-tooltip="Interaction to Next Paint reflects overall responsiveness.  Only shown if there is available data from the lab test or the CrUX field (p75).">?</span></div><div class="value" id="inp-mobile">--</div></div>';

          echo '</div>';
          echo '<p class="wpsa-footnote">*Values are estimated and may vary. The performance score is calculated directly from PSI metrics. As on <a href="https://googlechrome.github.io/lighthouse/scorecalc/" target="_blank">PageSpeed dev</a>.</p>';
         echo '<details class="wpsa-diag-block" open>';
         
         echo '<summary class="wpsa-diag-summary"><h3 id="mobile-diag">Diagnostics & Insights</h3></summary>';
        
          // two independent columns so wrapping in one does not affect the other
          echo '<div class="wpsa-diag-two-col">';
            // LEFT: Opportunities (diagnostics-as-before)
            echo '<div class="wpsa-diag-col">';
              echo '<h4 class="wpsa-diag-col-title">Diagnostics (max top 5)</h4>';
              echo '<ul class="diagnostics" id="diag-mobile"></ul>';
            echo '</div>';
        
            // RIGHT: Insights (new)
            echo '<div class="wpsa-diag-col">';
              echo '<h4 class="wpsa-diag-col-title">Insights (max top 5)</h4>';
              echo '<ul class="insights" id="ins-mobile"></ul>';
            echo '</div>';
          echo '</div>';
        
          echo '<p class="wpsa-footnote">*These diagnostics and insights don’t directly affect the Performance score, but improving them will speed up your site.</p>';
        echo '</details>';
          echo '</div>'; // end #perf-mobile

        // ─── FORCE A NEW PDF PAGE BEFORE THE DESKTOP PANEL ────────────────────────────
        echo '<div style="page-break-before:always;"></div>';

        // ─── DESKTOP PANEL ──────────────────────────────────────────────────────────
        echo '<div id="perf-desktop" class="metrics">';
          echo '<h3 class="wpsa-subheading">Desktop results</h3>';

          // Header row: performance score + PSI screenshot preview
          echo '<div class="wpsa-perf-header-row">';
            echo '<div class="performance-circle wpsa-performance-circle" id="perf-circle-desktop"><span class="perf-text wpsa-perf-text">--</span></div>';
            echo '<div class="wpsa-psi-screenshot-box wpsa-psi-screenshot-desktop">';
              echo '<img id="wpsa-psi-screenshot-img-desktop" class="wpsa-psi-screenshot-img" src="" alt="" />';
            echo '</div>';
          echo '</div>';

          echo '<h3>Metrics</h3>';
          echo '<div class="wpsa-stat-cards photo">';
            echo '<div class="wpsa-stat-card wpsa-card-lcp"><div class="header">LCP <span class="custom-tooltip" data-tooltip="Largest Contentful Paint.">?</span></div><div class="value" id="lcp-desktop">--</div></div>';
            echo '<div class="wpsa-stat-card wpsa-card-fcp"><div class="header">FCP <span class="custom-tooltip" data-tooltip="First Contentful Paint.">?</span></div><div class="value" id="fcp-desktop">--</div></div>';
              echo '<div class="wpsa-stat-card wpsa-card-cls"><div class="header">CLS <span class="custom-tooltip" data-tooltip="Cumulative Layout Shift (lower is better).">?</span></div><div class="value" id="cls-desktop">--</div></div>';
              echo '<div class="wpsa-stat-card wpsa-card-inp"><div class="header">INP <span class="custom-tooltip" data-tooltip="Interaction to Next Paint (lower is better).">?</span></div><div class="value" id="inp-desktop">--</div></div>';

          echo '</div>';
          echo '<p class="wpsa-footnote">*Values are estimated and may vary. The performance score is calculated directly from PSI metrics. As on <a href="https://googlechrome.github.io/lighthouse/scorecalc/" target="_blank">PageSpeed dev</a>.</p>';
        echo '<details class="wpsa-diag-block" open>';
          echo '<summary class="wpsa-diag-summary"><h3 id="desktop-diag">Diagnostics & Insights — top 5 each</h3></summary>';
          echo '<div class="wpsa-diag-two-col">';
            echo '<div class="wpsa-diag-col">';
              echo '<h4 class="wpsa-diag-col-title">Diagnostics (top 5)</h4>';
              echo '<ul class="diagnostics" id="diag-desktop"></ul>';
            echo '</div>';
            echo '<div class="wpsa-diag-col">';
              echo '<h4 class="wpsa-diag-col-title">Insights (top 5)</h4>';
              echo '<ul class="insights" id="ins-desktop"></ul>';
            echo '</div>';
          echo '</div>';
          echo '<p class="wpsa-footnote">*These diagnostics and insights don’t directly affect the Performance score, but improving them will speed up your site.</p>';
        echo '</details>';


        echo '</div>'; // end #perf-desktop

      echo '</div>'; // end .wpsa-module5-container
    echo '</div>'; // end .wpsa-module-5
}


/**
 * AJAX handler: fetch PSI data, with exponential back-off retry.
 */
add_action( 'wp_ajax_wpsa_performance', 'wpsa_ajax_performance' );
function wpsa_ajax_performance() {
    check_ajax_referer( 'wpsa_perf_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $url     = esc_url_raw( wp_unslash( $_POST['test_url']  ?? '' ) );
    $strat   = sanitize_key( wp_unslash( $_POST['strategy'] ?? 'mobile' ) );
    $test_no = isset( $_POST['test_no'] ) ? absint( wp_unslash( $_POST['test_no'] ) ) : 0;

    if ( ! $url || ! in_array( $strat, array( 'mobile', 'desktop' ), true ) ) {
        wp_send_json_error();
    }

    // Build the Cloudflare Worker PSI proxy URL
    // - psi_url → used by PSI
    // - url     → used by the Worker for TTFB + asset parsing (dual mode)
    $endpoint = add_query_arg(
        array(
            'psi_url'  => rawurlencode( $url ),
            'strategy' => $strat,
            'url'      => $url, // plain URL (not rawurlencode)
        ),
        wpsa_get_worker_endpoint() . 'psi'
    );


    // ── EXPONENTIAL BACK-OFF ──
    $max_retries   = 3;
    $delay_seconds = 1;
    $attempt       = 0;

    do {
        if ( $attempt > 0 ) {
            // wait: 1s → 2s → 4s
            sleep( $delay_seconds );
            $delay_seconds *= 2;
        }
        
    // ⬅️ increase timeout to 65s to allow Worker’s 60s PSI wait to complete
        $res  = wp_remote_get( $endpoint, array( 'timeout' => 65 ) );
        $code = is_wp_error( $res )
              ? 0
              : wp_remote_retrieve_response_code( $res );
        $attempt++;
    } while ( ( is_wp_error( $res ) || $code === 429 ) && $attempt < $max_retries );

    if ( is_wp_error( $res ) || 200 !== $code ) {
        // final error message
        $msg = is_wp_error( $res )
             ? $res->get_error_message()
             : ( $code === 429
                 ? 'Service temporarily unavailable. Please try again later.'
                 : 'HTTP ' . $code
               );
        wp_send_json_error( array( 'message' => $msg ) );
    }

    // ── parse PSI JSON ──
    $body = wp_remote_retrieve_body( $res );
    $data = json_decode( $body, true );
    $l    = isset( $data['lighthouseResult'] ) ? $data['lighthouseResult'] : array();

    // Store CWV (field) subset for later log attachment (Page has priority, Origin fallback).
    // We store only the small CrUX structures to avoid huge option/transient payloads (screenshots, audits, etc).
    if ( $test_no > 0 && is_array( $data ) ) {
        $cwv_subset = array();

        if ( isset( $data['loadingExperience'] ) && is_array( $data['loadingExperience'] ) ) {
            $cwv_subset['loadingExperience'] = $data['loadingExperience'];
        }

        if ( isset( $data['originLoadingExperience'] ) && is_array( $data['originLoadingExperience'] ) ) {
            $cwv_subset['originLoadingExperience'] = $data['originLoadingExperience'];
        }

        // Only write a transient if we have at least one of them.
        if ( ! empty( $cwv_subset ) ) {
            $tkey = 'wpsa_cwv_' . absint( $test_no ) . '_' . sanitize_key( (string) $strat );
            set_transient( $tkey, $cwv_subset, 15 * MINUTE_IN_SECONDS );
        }
    }


    // ── DEBUG LOG: screenshots per test (rolling JSONL, last 10 entries) ──
    if ( is_array( $data ) ) {
        $upload = wp_upload_dir();
        if ( empty( $upload['error'] ) ) {
            $dir = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            // New SS log name
            $ss_log = trailingslashit( $dir ) . 'psi-diag-ss.log';

            // Prefer new Worker field screenshot_data_url; fall back to psi_screenshot
            $psi_shot = '';
            if ( ! empty( $data['screenshot_data_url'] ) && is_string( $data['screenshot_data_url'] ) ) {
                $psi_shot = $data['screenshot_data_url'];
            } elseif ( ! empty( $data['psi_screenshot'] ) && is_string( $data['psi_screenshot'] ) ) {
                $psi_shot = $data['psi_screenshot'];
            }

            $entry = array(
                'test'           => $test_no,           // Test # to match “Load test #”
                'tested_url'     => $url,
                'strategy'       => $strat,            // mobile / desktop
                'ts'             => gmdate( 'c' ),
                'psi_metrics'    => $data['psi_metrics'] ?? null,
                'assets'         => $data['assets']      ?? null,
                'psi_screenshot' => $psi_shot,          // full data:image/...;base64,...
            );

            $line = wp_json_encode( $entry );

            $lines = array();
            if ( file_exists( $ss_log ) && is_readable( $ss_log ) ) {
                $old = @file( $ss_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
                if ( is_array( $old ) ) {
                    $lines = $old;
                }
            }

            $lines[] = $line;

            // keep only last 20 entries (10 mobile and 10 desktop)
            if ( count( $lines ) > 20 ) {
                $lines = array_slice( $lines, -20 );
            }

            // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
            @file_put_contents( $ss_log, implode( "\n", $lines ) . "\n", LOCK_EX );
        }
    }



    // convenient locals
    $audits  = $l['audits']  ?? array();
    $metrics = $audits['metrics']['details']['items'][0] ?? array();

    
    // score
    $score = isset( $l['categories']['performance']['score'] )
        ? intval( $l['categories']['performance']['score'] * 100 )
        : 0;
    
    // LCP/FCP as returned (displayValue already carries units)
    $lcp   = $l['audits']['largest-contentful-paint']['displayValue'] ?? '--';
    $fcp   = $l['audits']['first-contentful-paint']['displayValue']   ?? '--';
    $cls   = $l['audits']['cumulative-layout-shift']['displayValue']  ?? '--';

    // --- INP: try lab first (3 possible locations), then fall back to CrUX field p75 ---
    $inp = '--';

    // 1) Lab numeric (preferred)
    $inp_lab = null;
    if (isset($l['audits']['experimental-interaction-to-next-paint']['numericValue']) &&
        is_numeric($l['audits']['experimental-interaction-to-next-paint']['numericValue'])) {
        $inp_lab = (float) $l['audits']['experimental-interaction-to-next-paint']['numericValue'];
    } elseif (isset($l['audits']['interaction-to-next-paint']['numericValue']) &&
              is_numeric($l['audits']['interaction-to-next-paint']['numericValue'])) {
        $inp_lab = (float) $l['audits']['interaction-to-next-paint']['numericValue'];
    } elseif (!empty($l['audits']['metrics']['details']['items'][0]['observedInteractionToNextPaint']) &&
              is_numeric($l['audits']['metrics']['details']['items'][0]['observedInteractionToNextPaint'])) {
        $inp_lab = (float) $l['audits']['metrics']['details']['items'][0]['observedInteractionToNextPaint'];
    }

    if (is_numeric($inp_lab)) {
        // lab numeric is in ms
        $inp = round($inp_lab) . ' ms';
    } else {
        // 2) Field data fallback (CrUX p75) – try page, then origin
        $crux = $data['loadingExperience']['metrics']['INP']
             ?? $data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']
             ?? $data['originLoadingExperience']['metrics']['INP']
             ?? $data['originLoadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']
             ?? null;

        if (is_array($crux) && isset($crux['percentile']) && is_numeric($crux['percentile'])) {
            $inp = intval($crux['percentile']) . ' ms'; // also ms
        }
    }


        // Build Opportunities + Insights (PSI-style, split)
    $audits = $l['audits'] ?? array();
    $refs   = $l['categories']['performance']['auditRefs'] ?? array();

      // PSI 2025: diagnostics are now the primary actionable list; "insights" are secondary.
    $diagnostics = array(); // ← replaces old $opportunities
    $insights    = array(); // remains right column
    
    $wanted_refs = array_filter( $refs, function( $r ) {
        $grp = $r['group'] ?? '';
       // accept all PSI group names we've seen
         return in_array( $grp, array( 'diagnostics', 'insights', 'load-opportunities', 'opportunities' ), true );
            });
    
        foreach ( $wanted_refs as $ref ) {
        $id  = $ref['id'];
        $grp = $ref['group'] ?? '';
        $a   = $audits[ $id ] ?? null;
        if ( ! $a ) continue;
    
        if ( ( $a['scoreDisplayMode'] ?? '' ) === 'notApplicable' ) continue;
    
        $value = $a['displayValue'] ?? '';

        // Normalise Opportunities to show meaningful KiB/ms, similar to PSI UI
        if ( ( $a['details']['type'] ?? '' ) === 'opportunity' ) {
            $ms    = ( isset( $a['details']['overallSavingsMs'] ) && is_numeric( $a['details']['overallSavingsMs'] ) )
                ? (float) $a['details']['overallSavingsMs']
                : null;
            $bytes = ( isset( $a['details']['overallSavingsBytes'] ) && is_numeric( $a['details']['overallSavingsBytes'] ) )
                ? (float) $a['details']['overallSavingsBytes']
                : null;

            // Prefer bytes when PSI exposes real byte savings (e.g. Reduce unused CSS/JS)
            if ( $bytes && $bytes > 0 ) {
                $kib = round( $bytes / 1024 );
                // Only override if at least 1 KiB; otherwise keep PSI's displayValue
                if ( $kib >= 1 ) {
                    $value = 'Est savings of ' . $kib . ' KiB';
                }
            } elseif ( $ms && $ms > 0 ) {
                // Fall back to ms when there is a meaningful time saving
                $value = 'Est savings of ' . round( $ms ) . ' ms';
            }
            // If both are zero / missing, keep $value as PSI's displayValue
        }
    
        $sev = wpsa_map_severity( $a );
        if ( $sev === 'low' ) continue;
    
        $item = array(
            'title'    => $a['title'] ?? $id,
            'value'    => $value,
            'severity' => $sev,
        );

    
        // PSI "diagnostics" are now the key list; old "opportunities" go to insights
        if ( $grp === 'diagnostics' ) {
            $diagnostics[] = $item; // ← LEFT column
        } else {
            $insights[] = $item;    // RIGHT (covers 'insights' and 'load-opportunities')
        }
    }


    // Sort both lists (red→amber, then by numeric value desc)
    $sorter = function( $A, $B ) {
        $wt = array( 'high' => 2, 'moderate' => 1, 'medium' => 1, 'low' => 0, 'info' => 0 );
        $aw = $wt[ $A['severity'] ] ?? 0;
        $bw = $wt[ $B['severity'] ] ?? 0;
        if ( $aw !== $bw ) return $bw - $aw;
        $na = (float) preg_replace( '/[^\d.]/', '', $A['value'] ?? '0' );
        $nb = (float) preg_replace( '/[^\d.]/', '', $B['value'] ?? '0' );
        return $nb <=> $na;
    };
    usort( $diagnostics, $sorter );
    usort( $insights,    $sorter );
    
    $diagnostics = array_slice( $diagnostics, 0, 5 );
    $insights    = array_slice( $insights,    0, 5 );

    $inp_source = is_numeric( $inp_lab ) ? 'lab' : ( strpos( $inp, 'ms' ) !== false ? 'field' : 'na' );

    // Optional PSI screenshot from the Worker (data URL)
    // Prefer new screenshot_data_url, fall back to legacy psi_screenshot
    $psi_screenshot = '';
    if ( ! empty( $data['screenshot_data_url'] ) && is_string( $data['screenshot_data_url'] ) ) {
        $psi_screenshot = $data['screenshot_data_url'];
    } elseif ( ! empty( $data['psi_screenshot'] ) && is_string( $data['psi_screenshot'] ) ) {
        $psi_screenshot = $data['psi_screenshot'];
    }
    
    $psi_metrics = isset( $data['psi_metrics'] ) && is_array( $data['psi_metrics'] )
        ? $data['psi_metrics']
        : null;

    
    $psi_assets = isset( $data['assets'] ) && is_array( $data['assets'] )
        ? $data['assets']
        : null;
    
    wp_send_json_success( array(
        'score'          => $score,
        'lcp'            => $lcp,
        'fcp'            => $fcp,
        'cls'            => $cls,
        'inp'            => $inp,
        'inp_source'     => $inp_source,
        'diagnostics'    => $diagnostics,
        'insights'       => $insights,
        'psi_screenshot' => $psi_screenshot,
        'psi_metrics'    => $psi_metrics, // optional
        'assets'         => $psi_assets,  // optional
    ) );

    }
    
 /**
 * Decide whether CWV should be treated as Origin fallback.
 * - If URL-level (loadingExperience) is missing or has NO_DATA, prefer Origin.
 * - Otherwise prefer URL-level.
 *
 * @param array $cwv_subset transient payload with loadingExperience/originLoadingExperience
 * @return bool true => use Origin scope
 */
function wpsa_cwv_should_use_origin_scope( $cwv_subset ) {
    if ( ! is_array( $cwv_subset ) ) {
        return false;
    }

    $le  = isset( $cwv_subset['loadingExperience'] ) && is_array( $cwv_subset['loadingExperience'] )
        ? $cwv_subset['loadingExperience']
        : array();

    $ole = isset( $cwv_subset['originLoadingExperience'] ) && is_array( $cwv_subset['originLoadingExperience'] )
        ? $cwv_subset['originLoadingExperience']
        : array();

    $le_id  = isset( $le['id'] )  ? trim( (string) $le['id'] )  : '';
    $ole_id = isset( $ole['id'] ) ? trim( (string) $ole['id'] ) : '';

    // Strong PSI signal: when PSI falls back to Origin, these IDs often become identical.
    if ( $le_id !== '' && $ole_id !== '' && $le_id === $ole_id ) {
        return true;
    }

    $le_cat  = strtoupper( (string) ( $le['overall_category'] ?? '' ) );
    $ole_cat = strtoupper( (string) ( $ole['overall_category'] ?? '' ) );

    $le_has_data  = ( $le_cat !== '' && $le_cat !== 'NO_DATA' );
    $ole_has_data = ( $ole_cat !== '' && $ole_cat !== 'NO_DATA' );

    // If URL has NO_DATA but origin has data, use Origin.
    if ( ! $le_has_data && $ole_has_data ) {
        return true;
    }

    // Otherwise prefer URL
    return false;
}


// -----------------------------------------------------------
// AJAX: append Module 5 score/LCP/FCP lines to results log
//       (safely attach to matching Test #/URL when provided)
// -----------------------------------------------------------
add_action( 'wp_ajax_wpsa_log_module5', function () {
    // Accept the default _ajax_nonce param your JS sends
    check_ajax_referer( 'wpsa_perf_nonce', '_ajax_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $mobile  = isset( $_POST['mobile'] )  ? sanitize_text_field( wp_unslash( $_POST['mobile'] ) )  : '';
    $desktop = isset( $_POST['desktop'] ) ? sanitize_text_field( wp_unslash( $_POST['desktop'] ) ) : '';

    $ok = function( $line ) {
        return (bool) preg_match(
            '/^\s*Module\s+5\s+(Mobile|Desktop):\s*Performance:\s*[0-9NA\/]+/i',
            (string) $line
        );
    };

    // Optional correlation params from JS for safer attachment.
    // These should come from #wpsa-run-meta (data-test-no + data-tested-url)
    $test_no    = isset( $_POST['test_no'] ) ? absint( wp_unslash( $_POST['test_no'] ) ) : 0;
    $tested_url = isset( $_POST['tested_url'] ) ? esc_url_raw( wp_unslash( $_POST['tested_url'] ) ) : '';

    // Build output lines in a stable order:
    // - Module 5 Mobile line
    // - CWV line(s) for Mobile (Page priority; Origin fallback; else N/A)
    // - Module 5 Desktop line
    // - CWV line(s) for Desktop (Page priority; Origin fallback; else N/A)
    $out = array();


    $append_cwv_for = static function( $test_no, $strategy, $device_label ) use ( &$out ) {
        if ( $test_no <= 0 ) {
            $out[] = 'Module 5 CWV (' . $device_label . '): N/A';
            return;
        }

        $tkey = 'wpsa_cwv_' . absint( $test_no ) . '_' . sanitize_key( (string) $strategy );
        $cwv  = get_transient( $tkey );

        if ( ! is_array( $cwv ) || empty( $cwv ) || ! function_exists( 'wpsa_build_cwv_log_lines' ) ) {
            $out[] = 'Module 5 CWV (' . $device_label . '): N/A';
            return;
        }

        $built = wpsa_build_cwv_log_lines( $cwv, $device_label );

        if ( ! is_array( $built ) || empty( $built['summary'] ) || $built['summary'] === 'N/A' ) {
            $out[] = 'Module 5 CWV (' . $device_label . '): N/A';
            return;
        }

     $summary = (string) $built['summary'];

    // If assessment is N/A, don't force a scope label.
    if ( stripos( $summary, 'Assessment: N/A' ) !== false ) {
        $out[] = $summary;
        return;
    }
    
    // Highest priority: if the CWV builder already marked origin fallback, respect it.
    $use_origin = false;
    
    if ( isset( $built['raw'] ) && is_array( $built['raw'] ) && ! empty( $built['raw']['origin_fallback'] ) ) {
        $use_origin = true;
    } elseif ( preg_match( '/^\s*Module\s+5\s+CWV\s+Origin\s+\(/i', $summary ) ) {
        $use_origin = true;
    } elseif ( function_exists( 'wpsa_cwv_should_use_origin_scope' ) ) {
        $use_origin = wpsa_cwv_should_use_origin_scope( $cwv );
    }
    
    $scope_label = $use_origin ? 'Origin' : 'URL';
    
    $summary = preg_replace(
        '/^\s*Module\s+5\s+CWV\s+(Page|URL|Origin)\s+\(/i',
        'Module 5 CWV ' . $scope_label . ' (',
        $summary
    );
    
    $out[] = $summary;

    };



    if ( $ok( $mobile ) ) {
        $out[] = trim( $mobile );
        $append_cwv_for( $test_no, 'mobile', 'Mobile' );
    }

    if ( $ok( $desktop ) ) {
        $out[] = trim( $desktop );
        $append_cwv_for( $test_no, 'desktop', 'Desktop' );
    }

    if ( empty( $out ) ) {
        wp_send_json_success( array( 'written' => 0 ) );
    }


    $upload = wp_upload_dir();
    $dir    = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    $results_log = trailingslashit( $dir ) . 'ttfb-results-log.txt';

    // Tail-guard: if the last test block already has Module 5 lines, skip logging again.
    if ( file_exists( $results_log ) && filesize( $results_log ) > 0 ) {
        $lines = @file( $results_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( is_array( $lines ) && ! empty( $lines ) ) {
            $has_module5 = false;
            $total       = count( $lines );

            // Walk upwards from the end, but only within a reasonable window.
            for ( $i = $total - 1; $i >= 0; $i-- ) {
                $ln = (string) $lines[ $i ];

                // Stop once we hit the last Test # header.
                if ( preg_match( '/^\s*Test\s+#\d+\b/i', $ln ) ) {
                    break;
                }

            // If we see Module 5 or CWV lines before a new Test #, this test already logged Module 5.
                if (
                    stripos( $ln, 'Module 5 Mobile:' ) !== false ||
                    stripos( $ln, 'Module 5 Desktop:' ) !== false ||
                    stripos( $ln, 'Module 5 CWV' ) !== false
                ) {
                    $has_module5 = true;
                    break;
                }


                // Safety: don't scan more than the last 40 lines.
                if ( $total - $i > 40 ) {
                    break;
                }
            }

            if ( $has_module5 ) {
                wp_send_json_success(
                    array(
                        'written'  => 0,
                        'attached' => false,
                        'reason'   => 'module5_already_logged_for_last_test',
                    )
                );
            }
        }
    }

    // Optional correlation params from JS for safer attachment.
    // These should come from #wpsa-run-meta (data-test-no + data-tested-url)
    $test_no    = isset( $_POST['test_no'] ) ? absint( wp_unslash( $_POST['test_no'] ) ) : 0;
    $tested_url = isset( $_POST['tested_url'] ) ? esc_url_raw( wp_unslash( $_POST['tested_url'] ) ) : '';

    $attached = false;

    if ( $test_no > 0 && $tested_url && file_exists( $results_log ) && is_readable( $results_log ) ) {
        $lines = file( $results_log, FILE_IGNORE_NEW_LINES );
        if ( is_array( $lines ) && ! empty( $lines ) ) {

            $canonical = static function( $url ) {
                $url = trim( strtolower( (string) $url ) );
                if ( '' === $url ) {
                    return '';
                }
                // strip scheme
                $url = preg_replace( '#^https?://#', '', $url );
                // drop query string
                $parts = explode( '?', $url, 2 );
                $url   = rtrim( $parts[0], '/' );
                return $url;
            };

            $target_canon = $canonical( $tested_url );
            if ( '' !== $target_canon ) {
                $target_header_index = null;

                // Find the matching "Test #X | ... | URL: ..." header
                foreach ( $lines as $i => $line ) {
                    if ( preg_match(
                        '/^\s*Test\s+#(\d+)\b.*\bURL:\s*([^|]+)/',
                        $line,
                        $m
                    ) ) {
                        $header_no    = (int) $m[1];
                        $header_url   = trim( $m[2] );
                        $header_canon = $canonical( $header_url );

                        if ( $header_no === $test_no && $header_canon === $target_canon ) {
                            $target_header_index = $i;
                            break;
                        }
                    }
                }

                if ( null !== $target_header_index ) {
                    $total       = count( $lines );
                    $block_start = $target_header_index + 1;
                    $block_end   = $total;

                    // Find end of this test block (next "Test #..." or EOF)
                    for ( $j = $block_start; $j < $total; $j++ ) {
                        if ( preg_match( '/^\s*Test\s+#\d+\b/', $lines[ $j ] ) ) {
                            $block_end = $j;
                            break;
                        }
                    }

                    $new_lines = array();

                    // Copy everything up to and including the header line
                    for ( $i = 0; $i <= $target_header_index; $i++ ) {
                        $new_lines[] = $lines[ $i ];
                    }

                    $inserted = false;

                    // Walk the block, drop old Module 5 lines, and inject new ones
                    for ( $i = $block_start; $i < $block_end; $i++ ) {
                        $line = $lines[ $i ];

                        // Remove any existing Module 5 + CWV lines for this block
                        if (
                            preg_match( '/^\s*Module\s+5\s+(Mobile|Desktop):/i', $line ) ||
                            preg_match( '/^\s*Module\s+5\s+CWV\b/i', $line )
                        ) {
                            continue;
                        }

                        // Insert just before first Module 6/7 line to keep order
                        if ( ! $inserted && preg_match( '/^\s*Module\s+[67]\b/', $line ) ) {
                            foreach ( $out as $m5_line ) {
                                $new_lines[] = $m5_line;
                            }
                            $inserted = true;
                        }

                        $new_lines[] = $line;
                    }

                    // If we never hit Module 6/7, append at the end of this block
                    if ( ! $inserted ) {
                        foreach ( $out as $m5_line ) {
                            $new_lines[] = $m5_line;
                        }
                    }

                    // Copy the remainder of the file (after this block)
                    for ( $i = $block_end; $i < $total; $i++ ) {
                        $new_lines[] = $lines[ $i ];
                    }

                    // Overwrite log with updated content
                    // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
                    @file_put_contents(
                        $results_log,
                        implode( "\n", $new_lines ) . "\n",
                        LOCK_EX
                    );

                    $attached = true;
                }
            }
        }
    }

    if ( ! $attached ) {
        // Legacy behaviour: simply append at the end, same as before.
        // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
        @file_put_contents(
            $results_log,
            implode( "\n", $out ) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    if ( function_exists( 'wpsa_maybe_trim_logs' ) ) {
        wpsa_maybe_trim_logs();
    }

    wp_send_json_success(
        array(
            'written'  => count( $out ),
            'attached' => $attached,
        )
    );
} );


// -----------------------------------------------------------
// AJAX: append Module 5 diagnostics (top-5 per strategy)
//       to uploads/speed-analyzer/psi-diag-log.txt (JSONL)
// -----------------------------------------------------------
remove_all_actions( 'wp_ajax_wpsa_log_module5_diag' ); // in case a previous declaration exists
add_action( 'wp_ajax_wpsa_log_module5_diag', function () {
    // ---- Nonce (manual) ----
    $nonce = isset( $_REQUEST['_ajax_nonce'] ) ? $_REQUEST['_ajax_nonce'] : ( $_REQUEST['nonce'] ?? '' );
    $nonce = is_string( $nonce ) ? $nonce : '';
    $nonce_ok =
        wp_verify_nonce( $nonce, 'wpsa_perf_nonce' ) ||
        wp_verify_nonce( $nonce, 'wpsa_psi_nonce' ); // accept either, both are yours

    if ( ! $nonce_ok ) {
        // Don’t hard-fail with a 403 — just no-op. This avoids WAF/403 noise for a best-effort logger.
        wp_send_json_success( array( 'ok' => false, 'reason' => 'bad_nonce' ) );
    }

    // ---- Auth (keep it, but avoid 403 header) ----
    if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
        wp_send_json_success( array( 'ok' => false, 'reason' => 'no_caps' ) );
    }

    // ---- Inputs ----
    $test_no    = isset( $_POST['test_no'] )    ? absint( wp_unslash( $_POST['test_no'] ) ) : 0;
    $tested_url = isset( $_POST['tested_url'] ) ? esc_url_raw( wp_unslash( $_POST['tested_url'] ) ) : '';
    
    // Arrays arrive as JSON-encoded strings; decode defensively.
    $decode_json = static function( $s ) {
        $arr = json_decode( (string) $s, true );
        if ( is_array( $arr ) ) return $arr;
        $maybe = base64_decode( (string) $s, true );
        if ( $maybe !== false ) {
            $arr = json_decode( $maybe, true );
            if ( is_array( $arr ) ) return $arr;
        }
        return array();
    };
    
    // New (split) payloads
    $mob_op_raw  = wp_unslash( $_POST['mobile_op']  ?? '[]' );
    $mob_in_raw  = wp_unslash( $_POST['mobile_in']  ?? '[]' );
    $desk_op_raw = wp_unslash( $_POST['desktop_op'] ?? '[]' );
    $desk_in_raw = wp_unslash( $_POST['desktop_in'] ?? '[]' );
    
    // Back-compat (legacy mixed arrays)
    $mobile_legacy_raw  = wp_unslash( $_POST['mobile']  ?? '[]' );
    $desktop_legacy_raw = wp_unslash( $_POST['desktop'] ?? '[]' );
    
    $mobile = [
        'opportunities' => array_values( array_slice( $decode_json( $mob_op_raw ),  0, 10 ) ),
        'insights'      => array_values( array_slice( $decode_json( $mob_in_raw ),  0, 10 ) ),
    ];
    $desktop = [
        'opportunities' => array_values( array_slice( $decode_json( $desk_op_raw ), 0, 10 ) ),
        'insights'      => array_values( array_slice( $decode_json( $desk_in_raw ), 0, 10 ) ),
    ];
    
    // If new fields are empty but legacy is present, keep the old behavior
    if ( empty( $mobile['opportunities'] ) && empty( $mobile['insights'] ) ) {
        $mobile = [ 'opportunities' => $decode_json( $mobile_legacy_raw ), 'insights' => [] ];
    }
    if ( empty( $desktop['opportunities'] ) && empty( $desktop['insights'] ) ) {
        $desktop = [ 'opportunities' => $decode_json( $desktop_legacy_raw ), 'insights' => [] ];
    }
    
    // Nothing to write? Exit quietly.
    if ( empty( $mobile['opportunities'] ) && empty( $mobile['insights'] ) &&
         empty( $desktop['opportunities'] ) && empty( $desktop['insights'] ) ) {
        wp_send_json_success( array( 'ok' => false, 'reason' => 'empty' ) );
    }
    
    // ---- Path & write ---- (unchanged)
    $upload = wp_upload_dir();
    $dir    = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
    if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
    $diag_log = trailingslashit( $dir ) . 'psi-diag-log.txt';
    
    $row = array(
        'test'       => $test_no,
        'tested_url' => $tested_url,
        'ts'         => gmdate( 'c' ),
        'mobile'     => $mobile,
        'desktop'    => $desktop,
    );
    
    // phpcs:ignore WordPress.VIP.FileSystemWritesDisallow.file_ops_write
    $ok = @file_put_contents( $diag_log, wp_json_encode( $row ) . "\n", FILE_APPEND | LOCK_EX );
    
    if ( function_exists( 'wpsa_maybe_trim_logs' ) ) {
        wpsa_maybe_trim_logs();
    }
    
    wp_send_json_success( array( 'ok' => ( $ok !== false ) ) );

} );

// -----------------------------------------------------------
// Helper: log scheduled PSI diagnostics into psi-diag-log.txt
//         as "Test #<n>S" (e.g. 644S)
// -----------------------------------------------------------
if ( ! function_exists( 'wpsa_log_scheduled_psi_diag' ) ) {
    /**
     * Build "diagnostics" + "insights" arrays from raw PSI Worker JSON
     * and log them as a scheduled run (test "644S") into psi-diag-log.txt.
     *
     * @param int   $test_no        Base test number (e.g. 644).
     * @param string $tested_url    Tested URL (same as Module 1 header).
     * @param array  $mobile_raw    Full PSI JSON for mobile (Worker response).
     * @param array  $desktop_raw   Full PSI JSON for desktop (Worker response).
     */
    function wpsa_log_scheduled_psi_diag( $test_no, $tested_url, $mobile_raw, $desktop_raw ) {
        if ( ! function_exists( 'wpsa_log_module5_diag_row' ) ) {
            // Safety: writer not available
            return;
        }

        $build_side = static function( $raw ) {
            if ( ! is_array( $raw ) ) {
                return array(
                    'opportunities' => array(),
                    'insights'      => array(),
                );
            }

            $l       = isset( $raw['lighthouseResult'] ) && is_array( $raw['lighthouseResult'] )
                ? $raw['lighthouseResult']
                : array();
            $audits  = $l['audits']  ?? array();
            $refs    = $l['categories']['performance']['auditRefs'] ?? array();

            $diagnostics = array();
            $insights    = array();

            $wanted_refs = array();
            foreach ( $refs as $ref ) {
                $grp = $ref['group'] ?? '';
                if ( in_array( $grp, array( 'diagnostics', 'insights', 'load-opportunities', 'opportunities' ), true ) ) {
                    $wanted_refs[] = $ref;
                }
            }

            foreach ( $wanted_refs as $ref ) {
                $id  = $ref['id']    ?? '';
                $grp = $ref['group'] ?? '';
                if ( '' === $id || ! isset( $audits[ $id ] ) ) {
                    continue;
                }

                $a = $audits[ $id ];

                if ( ( $a['scoreDisplayMode'] ?? '' ) === 'notApplicable' ) {
                    continue;
                }

                $value = $a['displayValue'] ?? '';

                // Normalise Opportunities similar to the main AJAX path
                if ( ( $a['details']['type'] ?? '' ) === 'opportunity' ) {
                    $ms    = ( isset( $a['details']['overallSavingsMs'] ) && is_numeric( $a['details']['overallSavingsMs'] ) )
                        ? (float) $a['details']['overallSavingsMs']
                        : null;
                    $bytes = ( isset( $a['details']['overallSavingsBytes'] ) && is_numeric( $a['details']['overallSavingsBytes'] ) )
                        ? (float) $a['details']['overallSavingsBytes']
                        : null;

                    if ( $bytes && $bytes > 0 ) {
                        $kib = round( $bytes / 1024 );
                        if ( $kib >= 1 ) {
                            $value = 'Est savings of ' . $kib . ' KiB';
                        }
                    } elseif ( $ms && $ms > 0 ) {
                        $value = 'Est savings of ' . round( $ms ) . ' ms';
                    }
                }

                $sev = wpsa_map_severity( $a );
                if ( 'low' === $sev ) {
                    continue;
                }

                $item = array(
                    'title'    => $a['title'] ?? $id,
                    'value'    => $value,
                    'severity' => $sev,
                );

                if ( 'diagnostics' === $grp ) {
                    $diagnostics[] = $item;
                } else {
                    $insights[] = $item;
                }
            }

            // Sort high→moderate→low/info, then by numeric value desc, slice top 10
            $sorter = static function( $A, $B ) {
                $wt = array( 'high' => 2, 'moderate' => 1, 'medium' => 1, 'low' => 0, 'info' => 0 );
                $aw = $wt[ $A['severity'] ] ?? 0;
                $bw = $wt[ $B['severity'] ] ?? 0;
                if ( $aw !== $bw ) {
                    return $bw - $aw;
                }
                $na = (float) preg_replace( '/[^\d.]/', '', $A['value'] ?? '0' );
                $nb = (float) preg_replace( '/[^\d.]/', '', $B['value'] ?? '0' );
                if ( $na === $nb ) {
                    return 0;
                }
                return ( $na < $nb ) ? 1 : -1;
            };

            usort( $diagnostics, $sorter );
            usort( $insights,    $sorter );

            $diagnostics = array_slice( $diagnostics, 0, 10 );
            $insights    = array_slice( $insights,    0, 10 );

            return array(
                'opportunities' => $diagnostics,
                'insights'      => $insights,
            );
        };

        $mobile  = $build_side( $mobile_raw );
        $desktop = $build_side( $desktop_raw );

        // Mark as scheduled → logs as "644S"
        wpsa_log_module5_diag_row(
            $test_no,
            $tested_url,
            $mobile,
            $desktop,
            true
        );
    }
}


// -----------------------------------------------------------
// Helper: search a given SS log file for a Test # / strategy pair
// -----------------------------------------------------------
if ( ! function_exists( 'wpsa_find_psi_screenshot_in_log' ) ) {
    /**
     * Find a PSI screenshot data URL in a given SS log (JSONL) file.
     *
     * @param string $file     Full path to the log file.
     * @param int    $test_no  Test number.
     * @param string $strategy 'mobile' or 'desktop'.
     * @return string          data:image/...;base64,... or '' if not found.
     */
    function wpsa_find_psi_screenshot_in_log( $file, $test_no, $strategy ) {
        $file   = (string) $file;
        $test_no = (int) $test_no;
        $strategy = strtolower( (string) $strategy );

        if ( $test_no <= 0 || '' === $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
            return '';
        }

        // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
        $lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! is_array( $lines ) || empty( $lines ) ) {
            return '';
        }

        // Search from newest to oldest.
        for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
            $entry = json_decode( $lines[ $i ], true );
            if ( ! is_array( $entry ) ) {
                continue;
            }

            // Support both "test" and legacy "test_no" keys.
            $e_test = 0;
            if ( isset( $entry['test'] ) ) {
                $e_test = (int) $entry['test'];
            } elseif ( isset( $entry['test_no'] ) ) {
                $e_test = (int) $entry['test_no'];
            }

            $e_str = isset( $entry['strategy'] ) ? strtolower( (string) $entry['strategy'] ) : '';

            if ( $e_test === $test_no && $e_str === $strategy ) {
                // Support all possible screenshot keys:
                //  - psi_screenshot (current)
                //  - ss (short key used in some logs)
                //  - screenshot_data_url (direct Worker field)
                $shot = '';
                if ( ! empty( $entry['psi_screenshot'] ) && is_string( $entry['psi_screenshot'] ) ) {
                    $shot = $entry['psi_screenshot'];
                } elseif ( ! empty( $entry['ss'] ) && is_string( $entry['ss'] ) ) {
                    $shot = $entry['ss'];
                } elseif ( ! empty( $entry['screenshot_data_url'] ) && is_string( $entry['screenshot_data_url'] ) ) {
                    $shot = $entry['screenshot_data_url'];
                }

                return is_string( $shot ) ? $shot : '';
            }
        }

        return '';
    }
}


// -----------------------------------------------------------
// AJAX: fetch stored PSI screenshot for a given Test # / strategy
//       - primary: psi-diag-ss.log (interactive tests)
//       - fallback: PSI-ss-scheduled.log (scheduled tests)
// -----------------------------------------------------------
add_action( 'wp_ajax_wpsa_get_psi_screenshot', function () {
    check_ajax_referer( 'wpsa_perf_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $test_no = isset( $_POST['test_no'] ) ? absint( wp_unslash( $_POST['test_no'] ) ) : 0;
    $strat   = isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : 'mobile';

    if ( $test_no <= 0 || ! in_array( $strat, array( 'mobile', 'desktop' ), true ) ) {
        wp_send_json_error( array( 'message' => 'bad_params' ) );
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        wp_send_json_success( array( 'psi_screenshot' => '' ) );
    }

    $dir          = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
    $main_file    = trailingslashit( $dir ) . 'psi-diag-ss.log';
    $scheduled_file = trailingslashit( $dir ) . 'PSI-ss-scheduled.log';

    $shot = '';

    // 1) Try the main interactive SS log.
    if ( file_exists( $main_file ) && is_readable( $main_file ) ) {
        $shot = wpsa_find_psi_screenshot_in_log( $main_file, $test_no, $strat );
    }

    // 2) If nothing found, try the scheduled SS log.
    if ( '' === $shot && file_exists( $scheduled_file ) && is_readable( $scheduled_file ) ) {
        $shot = wpsa_find_psi_screenshot_in_log( $scheduled_file, $test_no, $strat );
    }

    wp_send_json_success( array( 'psi_screenshot' => $shot ) );
} );


function wpsa_parse_cwv_p75_from_line( $line ) {
    $out = array(
        'lcp_ms'   => null,
        'inp_ms'   => null,
        'fcp_ms'   => null,
        'ttfb_ms'  => null,
        'cls'      => null,

        // Optional distributions: [good, ni, poor]
        'lcp_dist'  => null,
        'inp_dist'  => null,
        'fcp_dist'  => null,
        'ttfb_dist' => null,
        'cls_dist'  => null,
    );

    $line = (string) $line;

    // Examples:
    // LCP: FAST (p75: 1954ms; 85/9/6)
    // INP: FAST (p75: 99ms; 93/5/2)
    // FCP: FAST (p75: 1728ms; 78/17/5)
    // TTFB: AVERAGE (p75: 1400ms; 49/40/11)
    // CLS: FAST (p75: 0.00; 94/2/4)

    $grab_dist = static function( $m ) {
        if ( ! isset( $m[2], $m[3], $m[4] ) ) {
            return null;
        }
        $a = is_numeric( $m[2] ) ? (int) round( (float) $m[2] ) : null;
        $b = is_numeric( $m[3] ) ? (int) round( (float) $m[3] ) : null;
        $c = is_numeric( $m[4] ) ? (int) round( (float) $m[4] ) : null;
        if ( $a === null || $b === null || $c === null ) {
            return null;
        }
        return array( $a, $b, $c );
    };

    if ( preg_match( '/\bLCP:\s*[A-Z_]+\s*\(p75:\s*([0-9.]+)\s*ms\b(?:;\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+))?/i', $line, $m ) ) {
        $out['lcp_ms'] = (int) round( (float) $m[1] );
        $out['lcp_dist'] = $grab_dist( $m );
    }

    if ( preg_match( '/\bINP:\s*[A-Z_]+\s*\(p75:\s*([0-9.]+)\s*ms\b(?:;\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+))?/i', $line, $m ) ) {
        $out['inp_ms'] = (int) round( (float) $m[1] );
        $out['inp_dist'] = $grab_dist( $m );
    }

    if ( preg_match( '/\bFCP:\s*[A-Z_]+\s*\(p75:\s*([0-9.]+)\s*ms\b(?:;\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+))?/i', $line, $m ) ) {
        $out['fcp_ms'] = (int) round( (float) $m[1] );
        $out['fcp_dist'] = $grab_dist( $m );
    }

    if ( preg_match( '/\bTTFB:\s*[A-Z_]+\s*\(p75:\s*([0-9.]+)\s*ms\b(?:;\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+))?/i', $line, $m ) ) {
        $out['ttfb_ms'] = (int) round( (float) $m[1] );
        $out['ttfb_dist'] = $grab_dist( $m );
    }

    if ( preg_match( '/\bCLS:\s*[A-Z_]+\s*\(p75:\s*([0-9.]+)\b(?:;\s*([0-9.]+)\/([0-9.]+)\/([0-9.]+))?/i', $line, $m ) ) {
        $out['cls'] = (float) $m[1];
        $out['cls_dist'] = $grab_dist( $m );
    }

    return $out;
}



// -----------------------------------------------------------
// AJAX: get CWV Assessment line for a given Test # / strategy
// Reads from uploads/speed-analyzer/ttfb-results-log.txt
// -----------------------------------------------------------
add_action( 'wp_ajax_wpsa_get_cwv_assessment', function () {
    check_ajax_referer( 'wpsa_perf_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $test_no = isset( $_POST['test_no'] ) ? absint( wp_unslash( $_POST['test_no'] ) ) : 0;
    $strat   = isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( $_POST['strategy'] ) ) : 'mobile';

    if ( $test_no <= 0 || ! in_array( $strat, array( 'mobile', 'desktop' ), true ) ) {
        wp_send_json_success( array( 'assessment' => '', 'scope' => '' ) );
    }

    $upload = wp_upload_dir();
    if ( ! empty( $upload['error'] ) ) {
        wp_send_json_success( array( 'assessment' => '', 'scope' => '' ) );
    }

    $dir         = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
    $results_log = trailingslashit( $dir ) . 'ttfb-results-log.txt';

    if ( ! file_exists( $results_log ) || ! is_readable( $results_log ) ) {
        wp_send_json_success( array( 'assessment' => '', 'scope' => '' ) );
    }

    $label = ( $strat === 'mobile' ) ? 'Mobile' : 'Desktop';

    // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
    $lines = @file( $results_log, FILE_IGNORE_NEW_LINES );
    if ( ! is_array( $lines ) || empty( $lines ) ) {
        wp_send_json_success( array( 'assessment' => '', 'scope' => '' ) );
    }

    $start = null;
    $end   = count( $lines );

    // Match Test #742 or Test #742S
    $header_re = '/^\s*Test\s+#' . preg_quote( (string) $test_no, '/' ) . 'S?\b/i';

    // IMPORTANT: scan from bottom so we pick the newest matching block
    for ( $i = $end - 1; $i >= 0; $i-- ) {
        if ( preg_match( $header_re, (string) $lines[ $i ] ) ) {
            $start = $i;
            break;
        }
    }

    if ( $start === null ) {
        wp_send_json_success( array( 'assessment' => '', 'scope' => '' ) );
    }

    // Find block end (next Test #... after $start), otherwise EOF
    for ( $j = $start + 1; $j < $end; $j++ ) {
        if ( preg_match( '/^\s*Test\s+#\d+\b/i', (string) $lines[ $j ] ) ) {
            $end = $j;
            break;
        }
    }

    $assessment = '';
    $scope      = 'URL';
    $raw_json   = '';

    // Accept old logs ("Page") + new logs ("URL") + Origin.
    $line_re = '/^\s*Module\s+5\s+CWV\s+(Page|URL|Origin)\s+\(' . preg_quote( $label, '/' ) . '\):\s*Assessment:\s*(PASSED|FAILED)\b/i';
    $raw_re  = '/^\s*Module\s+5\s+CWV\s+(Page|URL|Origin)\s+\(' . preg_quote( $label, '/' ) . '\)\s+RAW:\s*(\{.*\})\s*$/i';



        for ( $k = $start; $k < $end; $k++ ) {
        $ln = (string) $lines[ $k ];

        if ( '' === $assessment && preg_match( $line_re, $ln, $m ) ) {
            $which      = strtolower( (string) $m[1] ); // page|origin
            $assessment = strtoupper( (string) $m[2] );

            // If the line itself says Origin, reflect that immediately
            $scope = ( $which === 'origin' ) ? 'Origin' : 'URL';
        }

        if ( '' === $raw_json && preg_match( $raw_re, $ln, $m2 ) ) {
            $raw_json = (string) $m2[2];
        }

        if ( $assessment && $raw_json ) {
            break;
        }
    }


    if ( $raw_json ) {
        $raw = json_decode( $raw_json, true );
        if ( is_array( $raw ) && ! empty( $raw['origin_fallback'] ) ) {
            $scope = 'Origin';
        }
    }

       // Always return a stable shape (same keys), even if values are missing.
    $p75 = wpsa_parse_cwv_p75_from_line( '' );

    if ( $assessment ) {
        // Find the CWV summary line inside the same test block and parse p75
        for ( $k = $start; $k < $end; $k++ ) {
            $ln = (string) $lines[ $k ];

            // Match the summary line for the same strategy/device
            if ( preg_match( $line_re, $ln ) ) {
                $p75 = wpsa_parse_cwv_p75_from_line( $ln );
                break;
            }
        }
    }

    // If everything is still null, return null instead of a useless object (optional but cleaner).
    $all_null = true;
    foreach ( array( 'lcp_ms', 'inp_ms', 'fcp_ms', 'ttfb_ms', 'cls' ) as $k ) {
        if ( array_key_exists( $k, $p75 ) && $p75[ $k ] !== null ) {
            $all_null = false;
            break;
        }
    }
    if ( $all_null ) {
        $p75 = null;
    }

    wp_send_json_success( array(
        'assessment' => $assessment,
        'scope'      => $scope,
        'p75'        => $p75,
    ) );


} );

