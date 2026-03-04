<?php
/**
 * editors.php v0.09
 * Speed Analyzer — Editors UI (Posts/Pages list columns + small admin UI helpers)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Boot editor/admin helpers.
 * Register admin-only hooks immediately (no init dependency).
 */
if ( is_admin() ) {
    add_filter(
        'plugin_action_links_' . plugin_basename( WPSA_PLUGIN_FILE ),
        'wpsa_plugin_action_links'
    );

    add_action( 'admin_head-plugins.php', 'wpsa_plugins_list_icons_css' );
   
}



/**
 * Plugin “Go to tool” link
 */
function wpsa_plugin_action_links( $actions ) {
    $tool_url = admin_url( 'tools.php?page=speed-analyzer' );
    return array_merge(
        [ 'wpsa_tool' => '<a href="' . esc_url( $tool_url ) . '">Go to tool</a>' ],
        $actions
    );
}

/**
 * Insert dashicons on Plugins list screen.
 */
function wpsa_plugins_list_icons_css() {
   $file = plugin_basename( WPSA_PLUGIN_FILE );

    echo '<style>
tr[data-plugin="' . esc_attr( $file ) . '"] .plugin-version-author-uri a:first-of-type::before{
  font-family:dashicons!important;
  content:"\f338";
  display:inline-block;
  margin-right:4px;
  vertical-align:middle;
  font-size:1.3em;
  line-height:1;
}
tr[data-plugin="' . esc_attr( $file ) . '"] .open-plugin-details-modal::before{
  font-family:dashicons!important;
  content:"\f179";
  display:inline-block;
  margin-right:4px;
  vertical-align:middle;
  font-size:1.3em;
  line-height:1;
}
</style>';
}
add_action( 'admin_head-edit.php', 'wpsa_editors_list_column_css' );
function wpsa_editors_list_column_css() {
    echo '<style>

/* Speed Analyzer column cell */
.column-wpsa_speed .wpsa-admin-cell{
  display:flex;
  align-items:flex-start;
  gap:12px;
  min-height:44px;
 /* justify-content: center;*/
}

/* If the cell is narrow (<120px), stack score above metrics */
.column-wpsa_speed .wpsa-admin-cell.wpsa-narrow{
  flex-direction:column;
  align-items:flex-start;
}
.column-wpsa_speed .wpsa-admin-cell.wpsa-narrow .wpsa-admin-score{
  margin-bottom:6px;
}



/* Gauge: Rocket-like + number centered */
.column-wpsa_speed .wpsa-admin-score{
  display:inline-block;
  position:relative;
  width:34px;
  height:34px;
  flex:0 0 auto;
  margin-top:2px;
  text-decoration:none;
}
.column-wpsa_speed svg.wpsa-admin-gauge{
  width:34px;
  height:34px;
  display:block;
}
.column-wpsa_speed .wpsa-admin-score-text{
  position:absolute;
  inset:0;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:14px;
  line-height:1;
  font-weight:700;
}

/* Meta text size (bigger than before) */
.column-wpsa_speed .wpsa-admin-meta{
  font-size:14px;
  line-height:1.3;
}
.column-wpsa_speed .wpsa-admin-testno{
  font-size:13px;
  color:#666;
  margin:0 0 4px;
}
.column-wpsa_speed .wpsa-admin-metrics{
  font-size:13px;
  margin:0 0 8px;
}
.column-wpsa_speed .wpsa-admin-metrics div{ margin:0; }

/* Metric colors (force with !important so WP admin doesn’t override) */
.column-wpsa_speed .wpsa-m-good{ color:#1e8e3e !important; font-weight:600; }
.column-wpsa_speed .wpsa-m-warn{ color:#b26a00 !important; font-weight:600; }
.column-wpsa_speed .wpsa-m-bad { color:#d32f2f !important; font-weight:600; }
.column-wpsa_speed .wpsa-m-na  { color:#777 !important; }

/* Actions: one link per row */
.column-wpsa_speed .wpsa-admin-actions a{
  display:block;
  text-decoration:none;
  margin:3px 0;
  font-size:13px;
}
.column-wpsa_speed .wpsa-admin-actions .dashicons{
  font-size:16px;
  line-height:1;
  vertical-align:middle;
  margin-right:6px;
  opacity:.9;
}

/* CWV block (Posts/Pages list) */
.column-wpsa_speed .wpsa-admin-cwv-divider{
  margin: 6px 0 6px;
  border-top: 1px solid rgba(0,0,0,0.12);
}

.column-wpsa_speed .wpsa-admin-cwv{
  font-size: 12.5px;
  line-height: 1.25;
  margin: 0 0 6px;
}

.column-wpsa_speed .wpsa-admin-cwv .wpsa-cwv-line{
  margin: 0;
}

.column-wpsa_speed .wpsa-cwv-status{
  font-weight: 700;
}

/* status colors */
.column-wpsa_speed .wpsa-cwv-status[data-cwv-status="passed"]{ color:#2e7d32; }
.column-wpsa_speed .wpsa-cwv-status[data-cwv-status="failed"]{ color:#c62828; }
.column-wpsa_speed .wpsa-cwv-status[data-cwv-status="unknown"]{ color:#777; }

</style>';
}

add_action( 'admin_footer-edit.php', 'wpsa_editors_list_column_narrow_js' );
function wpsa_editors_list_column_narrow_js() {
    ?>
    <script>
    (function(){
      function applyNarrowClass(){
        try{
          var cells = document.querySelectorAll('.column-wpsa_speed .wpsa-admin-cell');
          for (var i=0; i<cells.length; i++){
            var el = cells[i];
            var w = (el.getBoundingClientRect ? el.getBoundingClientRect().width : el.offsetWidth) || 0;
            if (w && w < 120) el.classList.add('wpsa-narrow');
            else el.classList.remove('wpsa-narrow');
          }
        }catch(e){}
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyNarrowClass);
      } else {
        applyNarrowClass();
      }

      // Keep it correct if columns are toggled or viewport changes
      window.addEventListener('resize', applyNarrowClass);

      // Some WP list tables reflow after “Screen Options” etc.
      setTimeout(applyNarrowClass, 250);
      setTimeout(applyNarrowClass, 1000);
    })();
    </script>
    <?php
}


/**
 * Fetch one test block from results log by test number
 */
add_action( 'wp_ajax_wpsa_load_test', 'wpsa_ajax_load_test' );
function wpsa_ajax_load_test() {
    check_ajax_referer( 'wpsa_load_test', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $n = isset( $_POST['test_no'] ) ? absint( wp_unslash( $_POST['test_no'] ) ) : 0;
    if ( $n <= 0 ) {
        wp_send_json_error( 'Invalid test number.' );
    }

    $upload = wp_upload_dir();
    $logdir = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
    $reslog = trailingslashit( $logdir ) . 'ttfb-results-log.txt';

    if ( ! file_exists( $reslog ) ) {
        wp_send_json_error( 'Results log not found.' );
    }

   $lines = file( $reslog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( ! is_array( $lines ) ) {
        wp_send_json_error( 'Failed to read log.' );
    }

    $start  = null;
    $end    = count( $lines );
    $needle = 'Test #' . $n . ' ';

    foreach ( $lines as $i => $ln ) {
        if ( strpos( $ln, $needle ) === 0 ) { $start = $i; break; }
    }
    if ( null === $start ) {
        wp_send_json_error( 'Not found.' );
    }

    for ( $j = $start + 1; $j < count( $lines ); $j++ ) {
        if ( preg_match( '/^Test #\d+ /', $lines[ $j ] ) ) {
            $end = $j;
            break;
        }
    }

    $block = array_slice( $lines, $start, $end - $start );

    $diag = function_exists( 'wpsa_read_diag_for_test' )
        ? wpsa_read_diag_for_test( $n )
        : [ 'mobile' => [], 'desktop' => [] ];

    wp_send_json_success( [
        'lines' => array_values( $block ),
        'diag'  => $diag,
    ] );
}

/**
 * Editor list columns (Posts/Pages): Speed Analyzer column
 * - Reads last matching test line for the post/page URL from the results log.
 * - Renders a compact-style cell.
 */

function wpsa_editor_normalize_url( $url ) {
    $url = trim( (string) $url );
    if ( '' === $url ) return '';

    $url = preg_replace( '#^https?://#i', '', $url );
    $url = preg_replace( '#^www\.#i', '', $url );
    $url = rtrim( $url, '/' );

    return strtolower( $url );
}

function wpsa_editor_parse_last_result_for_url( $url ) {
    static $cache = [];
    $key = wpsa_editor_normalize_url( $url );
    if ( '' === $key ) return null;

    if ( array_key_exists( $key, $cache ) ) {
        return $cache[ $key ];
    }

    $upload = wp_upload_dir();
    $logdir = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
    $reslog = trailingslashit( $logdir ) . 'ttfb-results-log.txt';

    if ( ! file_exists( $reslog ) || ! is_readable( $reslog ) ) {
        $cache[ $key ] = null;
        return null;
    }

    // Read once per request; file can be large, but this is still cheaper than per-row reads.
    $lines = file( $reslog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    if ( ! is_array( $lines ) || empty( $lines ) ) {
        $cache[ $key ] = null;
        return null;
    }

    $found = null;

    // Scan from bottom → newest first.
    for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
        $ln = (string) $lines[ $i ];

        // Only consider “Test #…” header lines.
        if ( 0 !== strpos( $ln, 'Test #' ) ) {
            continue;
        }

        // Must contain URL:
        if ( false === stripos( $ln, ' URL:' ) ) {
            continue;
        }

        // Extract URL from the line
        if ( ! preg_match( '#\bURL:\s*(https?://\S+)#i', $ln, $murl ) ) {
            continue;
        }

        $line_url = wpsa_editor_normalize_url( $murl[1] );
        if ( '' === $line_url || $line_url !== $key ) {
            continue;
        }

               // Test number
        $test_no = 0;
        if ( preg_match( '/^Test\s*#\s*(\d+)/i', $ln, $mno ) ) {
            $test_no = absint( $mno[1] );
        }


        // Parse metrics from the whole test block (header + following lines until next Test #)
        $perf = null;
        $lcp  = null;
        $fcp  = null;
        $cls  = null;
        $inp  = null;

        // CWV (Assessment + scope)
        $cwv_m_status = null; // passed|failed|unknown (normalized later)
        $cwv_m_scope  = null; // page|origin
        $cwv_d_status = null;
        $cwv_d_scope  = null;


        // Build block lines: current header line + subsequent lines for this test
        $block_lines = [ $ln ];
        for ( $k = $i + 1; $k < count( $lines ); $k++ ) {
            $next = (string) $lines[ $k ];
            if ( preg_match( '/^Test #\d+ /', $next ) ) {
                break;
            }
            $block_lines[] = $next;
        }

        foreach ( $block_lines as $bl ) {

            // Perf score (support multiple labels / formats)
            if ( null === $perf ) {
                if ( preg_match( '#\b(?:Perf|Performance|Score)\s*:\s*([0-9]{1,3})\b#i', $bl, $m ) ) {
                    $perf = max( 0, min( 100, (int) $m[1] ) );
                } elseif ( preg_match( '#\b(?:Perf|Performance|Score)\s+([0-9]{1,3})\b#i', $bl, $m ) ) {
                    $perf = max( 0, min( 100, (int) $m[1] ) );
                }
            }

            // LCP (accept ms, s, or no unit)
            if ( null === $lcp && preg_match( '#\bLCP\s*:\s*([0-9.]+)\s*(ms|s)?\b#i', $bl, $m ) ) {
                $v = (float) $m[1];
                $unit = strtolower( (string) ( $m[2] ?? '' ) );
                $lcp = ( $unit === 'ms' ) ? ( $v / 1000 ) : $v;
            }

            // FCP (accept ms, s, or no unit)
            if ( null === $fcp && preg_match( '#\bFCP\s*:\s*([0-9.]+)\s*(ms|s)?\b#i', $bl, $m ) ) {
                $v = (float) $m[1];
                $unit = strtolower( (string) ( $m[2] ?? '' ) );
                $fcp = ( $unit === 'ms' ) ? ( $v / 1000 ) : $v;
            }

            // CLS
            if ( null === $cls && preg_match( '#\bCLS\s*:\s*([0-9.]+)\b#i', $bl, $m ) ) {
                $cls = (float) $m[1];
            }

            // INP (ms or N/A)
            if ( null === $inp && preg_match( '#\bINP\s*:\s*(N/A|[0-9.]+)\s*(ms)?\b#i', $bl, $m ) ) {
                if ( ! preg_match( '#^n/a$#i', $m[1] ) ) {
                    $inp = (int) round( (float) $m[1] );
                }
            }
            
            // CWV Page/Origin (Mobile)
            if ( null === $cwv_m_status && preg_match( '#^Module\s+5\s+CWV\s+(Page|Origin)\s*\(Mobile\)\s*:\s*.*\bAssessment:\s*(PASSED|FAILED)\b#i', $bl, $m ) ) {
                $cwv_m_scope  = strtolower( $m[1] );
                $cwv_m_status = strtolower( $m[2] ) === 'passed' ? 'passed' : 'failed';
            }

            // CWV Page/Origin (Desktop)
            if ( null === $cwv_d_status && preg_match( '#^Module\s+5\s+CWV\s+(Page|Origin)\s*\(Desktop\)\s*:\s*.*\bAssessment:\s*(PASSED|FAILED)\b#i', $bl, $m ) ) {
                $cwv_d_scope  = strtolower( $m[1] );
                $cwv_d_status = strtolower( $m[2] ) === 'passed' ? 'passed' : 'failed';
            }


            // Early exit if we have everything that matters
              if (
                null !== $perf &&
                null !== $lcp &&
                null !== $fcp &&
                null !== $cls &&
                null !== $inp &&
                null !== $cwv_m_status &&
                null !== $cwv_d_status
            ) {
                break;
            }
            
        }

        $found = [
            'test_no'       => $test_no,
            'perf'          => $perf,
            'lcp'           => $lcp, // seconds
            'fcp'           => $fcp, // seconds
            'cls'           => $cls,
            'inp'           => $inp, // ms

            'cwv_m_status'  => $cwv_m_status, // passed|failed|null
            'cwv_m_scope'   => $cwv_m_scope,  // page|origin|null
            'cwv_d_status'  => $cwv_d_status,
            'cwv_d_scope'   => $cwv_d_scope,

            'raw'           => $ln,
        ];


        break;

    }

    $cache[ $key ] = $found;
    return $found;
}

function wpsa_editor_perf_ring_color( $score ) {
    if ( ! is_int( $score ) ) return '#999';
    if ( $score < 50 ) return '#d32f2f';
    if ( $score < 90 ) return '#f9a825';
    return '#388e3c';
}

function wpsa_editor_build_gauge_svg( $score, $color ) {
    $s = is_int( $score ) ? $score : null;
    if ( null === $s ) {
        $s = 0;
        $color = '#999';
    }

    $r = 20;
    $c = 2 * M_PI * $r;
    $pct  = $s / 100;
    $dash = $c * $pct;
    $gap  = $c - $dash;

    return '' .
        '<svg class="wpsa-admin-gauge" viewBox="0 0 44 44" aria-hidden="true">' .
          '<circle cx="22" cy="22" r="' . (float) $r . '" fill="none" stroke="#eee" stroke-width="4"></circle>' .
          '<circle cx="22" cy="22" r="' . (float) $r . '" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="4" ' .
                  'stroke-linecap="round" transform="rotate(-90 22 22)" ' .
                  'stroke-dasharray="' . esc_attr( number_format( $dash, 2, '.', '' ) ) . ' ' . esc_attr( number_format( $gap, 2, '.', '' ) ) . '">' .
          '</circle>' .
        '</svg>';
}

function wpsa_editor_metric_class( $metric, $value ) {
    if ( null === $value || '' === $value ) return 'wpsa-m-na';

    $v = (float) $value;

    // CWV-style thresholds
    if ( 'lcp' === $metric ) {
        if ( $v <= 2.5 ) return 'wpsa-m-good';
        if ( $v <= 4.0 ) return 'wpsa-m-warn';
        return 'wpsa-m-bad';
    }

    if ( 'fcp' === $metric ) {
        if ( $v <= 1.8 ) return 'wpsa-m-good';
        if ( $v <= 3.0 ) return 'wpsa-m-warn';
        return 'wpsa-m-bad';
    }

    if ( 'cls' === $metric ) {
        if ( $v <= 0.10 ) return 'wpsa-m-good';
        if ( $v <= 0.25 ) return 'wpsa-m-warn';
        return 'wpsa-m-bad';
    }

    if ( 'inp' === $metric ) { // ms
        if ( $v <= 200 ) return 'wpsa-m-good';
        if ( $v <= 500 ) return 'wpsa-m-warn';
        return 'wpsa-m-bad';
    }

    return 'wpsa-m-na';
}

function wpsa_editor_fmt_sec( $v ) {
    if ( null === $v ) return 'N/A';
    return number_format( (float) $v, 1 ) . ' s';
}
function wpsa_editor_fmt_cls( $v ) {
    if ( null === $v ) return 'N/A';
    return rtrim( rtrim( number_format( (float) $v, 3 ), '0' ), '.' );
}
function wpsa_editor_fmt_inp( $v ) {
    if ( null === $v ) return 'N/A';
    return (int) $v . ' ms';
}



/**
 * Add column header
 */
add_filter( 'manage_pages_columns', 'wpsa_editor_add_speed_column', 20 );
add_filter( 'manage_posts_columns', 'wpsa_editor_add_speed_column', 20 );
function wpsa_editor_add_speed_column( $cols ) {
    $out = [];
    $inserted = false;

    foreach ( $cols as $k => $label ) {
        $out[ $k ] = $label;

        // Put it near Author if present
        if ( ! $inserted && 'author' === $k ) {
            $out['wpsa_speed'] = 'Speed Analyzer';
            $inserted = true;
        }
    }

    if ( ! $inserted ) {
        $out['wpsa_speed'] = 'Speed Analyzer';
    }

    return $out;
}

/**
 * Render column cells
 */
add_action( 'manage_pages_custom_column', 'wpsa_editor_render_speed_column', 10, 2 );
add_action( 'manage_posts_custom_column', 'wpsa_editor_render_speed_column', 10, 2 );
function wpsa_editor_render_speed_column( $col, $post_id ) {
    if ( 'wpsa_speed' !== $col ) {
        return;
    }

   $status = get_post_status( $post_id );

    // Only show “Test the page” for live, public content
    $is_live = in_array( $status, [ 'publish' ], true );
    
    $url = $is_live ? get_permalink( $post_id ) : '';
    if ( ! $is_live || ! $url ) {
        echo '—';
        return;
    }
    
    $r = wpsa_editor_parse_last_result_for_url( $url );
    
    // “Test the page” should open the plugin Tests screen with URL prefilled
    $test_page_url = add_query_arg(
        [
            'page'     => 'speed-analyzer',
            'tab'      => 'tests',
            'test_url' =>  $url,
        ],
        admin_url( 'tools.php' )
    );
    
    // Re-test is the same destination as “Test the page”
    $retest_url = $test_page_url;


   // “See report” = Compare Results for this URL + jump to this test#
    $cr_url_val = function_exists( 'wpsa_compare_canon_url' )
        ? wpsa_compare_canon_url( $url )
        : wpsa_editor_normalize_url( $url );
    
    $see_report_url = add_query_arg(
        [
            'page'     => 'speed-analyzer',
            'tab'      => 'compare',
            'cr_count' => 5,
            'cr_url'   => $cr_url_val,
            'cr_sonly' => 0,
        ],
        admin_url( 'tools.php' )
    );
    
    // Pass the selected test number (Test A) into Compare
    if ( is_array( $r ) && ! empty( $r['test_no'] ) ) {
        $see_report_url = add_query_arg(
            [ 'cr_a' => (int) $r['test_no'] ],
            $see_report_url
        );
    }



    // No match in log → minimal UI
    if ( ! is_array( $r ) ) {
    echo '<div class="wpsa-admin-cell">';
    echo '<div class="wpsa-admin-meta"><a href="' . esc_url( $test_page_url ) . '">Test the page</a></div>';
    echo '</div>';
    return;
}


    $score = is_int( $r['perf'] ) ? $r['perf'] : null;
    $ring  = wpsa_editor_perf_ring_color( is_int( $score ) ? $score : null );
    $svg   = wpsa_editor_build_gauge_svg( is_int( $score ) ? $score : null, $ring );

    $lcp_txt = wpsa_editor_fmt_sec( $r['lcp'] ?? null );
    $fcp_txt = wpsa_editor_fmt_sec( $r['fcp'] ?? null );
    $cls_txt = wpsa_editor_fmt_cls( $r['cls'] ?? null );
    $inp_txt = wpsa_editor_fmt_inp( $r['inp'] ?? null );

    $lcp_cls = wpsa_editor_metric_class( 'lcp', $r['lcp'] ?? null );
    $fcp_cls = wpsa_editor_metric_class( 'fcp', $r['fcp'] ?? null );
    $cls_cls = wpsa_editor_metric_class( 'cls', $r['cls'] ?? null );
    $inp_cls = wpsa_editor_metric_class( 'inp', $r['inp'] ?? null );

    echo '<div class="wpsa-admin-cell">';

      echo '<a class="wpsa-admin-score" href="' . esc_url( $see_report_url ) . '" aria-label="Open Speed Analyzer report">';
        echo $svg;
        echo '<span class="wpsa-admin-score-text" style="color:' . esc_attr( $ring ) . ';">' . esc_html( is_int( $score ) ? (string) $score : 'N/A' ) . '</span>';
      echo '</a>';

      echo '<div class="wpsa-admin-meta">';


       if ( ! empty( $r['test_no'] ) ) {

    $load_url = add_query_arg(
        [
            'page'         => 'speed-analyzer',
            'tab'          => 'tests',
            'test_url'     => $url,
            'load_test_no' => (int) $r['test_no'],
        ],
        admin_url( 'tools.php' )
    );

    echo '<div class="wpsa-admin-testno"><a href="' . esc_url( $load_url ) . '">Test #' . esc_html( (string) (int) $r['test_no'] ) . '</a></div>';
}


        echo '<div class="wpsa-admin-metrics">';
          echo '<div class="' . esc_attr( $lcp_cls ) . '">LCP: ' . esc_html( $lcp_txt ) . '</div>';
          echo '<div class="' . esc_attr( $fcp_cls ) . '">FCP: ' . esc_html( $fcp_txt ) . '</div>';
          echo '<div class="' . esc_attr( $cls_cls ) . '">CLS: ' . esc_html( $cls_txt ) . '</div>';
          echo '<div class="' . esc_attr( $inp_cls ) . '">INP: ' . esc_html( $inp_txt ) . '</div>';
        echo '</div>';
        
                $cwv_m_status = isset( $r['cwv_m_status'] ) && in_array( $r['cwv_m_status'], [ 'passed', 'failed' ], true )
            ? $r['cwv_m_status']
            : 'unknown';

        $cwv_d_status = isset( $r['cwv_d_status'] ) && in_array( $r['cwv_d_status'], [ 'passed', 'failed' ], true )
            ? $r['cwv_d_status']
            : 'unknown';

        $cwv_m_label = ( 'passed' === $cwv_m_status ) ? 'Passed' : ( ( 'failed' === $cwv_m_status ) ? 'Failed' : '--' );
        $cwv_d_label = ( 'passed' === $cwv_d_status ) ? 'Passed' : ( ( 'failed' === $cwv_d_status ) ? 'Failed' : '--' );

        $cwv_m_scope = isset( $r['cwv_m_scope'] ) && in_array( $r['cwv_m_scope'], [ 'page', 'origin' ], true )
            ? ( 'page' === $r['cwv_m_scope'] ? 'URL' : 'origin' )
            : '--';

        $cwv_d_scope = isset( $r['cwv_d_scope'] ) && in_array( $r['cwv_d_scope'], [ 'page', 'origin' ], true )
            ? ( 'page' === $r['cwv_d_scope'] ? 'URL' : 'origin' )
            : '--';


        echo '<div class="wpsa-admin-cwv-divider" aria-hidden="true"></div>';

        echo '<div class="wpsa-admin-cwv">';
            echo '<div class="wpsa-cwv-line">CWV m: <span class="wpsa-cwv-status" data-cwv-status="' . esc_attr( $cwv_m_status ) . '">' . esc_html( $cwv_m_label ) . '</span></div>';
            echo '<div class="wpsa-cwv-line">scope: ' . esc_html( $cwv_m_scope ) . '</div>';

            echo '<div class="wpsa-cwv-line" style="margin-top:4px;">CWV d: <span class="wpsa-cwv-status" data-cwv-status="' . esc_attr( $cwv_d_status ) . '">' . esc_html( $cwv_d_label ) . '</span></div>';
            echo '<div class="wpsa-cwv-line">scope: ' . esc_html( $cwv_d_scope ) . '</div>';
        echo '</div>';
        
        echo '<div class="wpsa-admin-cwv-divider" aria-hidden="true"></div>';

        echo '<div class="wpsa-admin-actions">';
          echo '<a href="' . esc_url( $retest_url ) . '"><span class="dashicons dashicons-update" aria-hidden="true"></span>Re-test</a>';
          echo '<a href="' . esc_url( $see_report_url ) . '"><span class="dashicons dashicons-media-text" aria-hidden="true"></span>Compare result</a>';
        echo '</div>';



      echo '</div>';

    echo '</div>';
}
