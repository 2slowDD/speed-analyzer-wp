<?php
/**
 * Speed Analyzer - Compare.php
 * Version: v0.045
 * Compact Compare Results table with URL filter
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }


function wpsa_display_metric($val, $opts = []) {
    $zero_invalid = isset($opts['zero_invalid']) ? (bool)$opts['zero_invalid'] : false;
    $suffix       = isset($opts['suffix']) ? (string)$opts['suffix'] : '';
    $precision    = isset($opts['precision']) ? (int)$opts['precision'] : null;

    if ($val === null) return 'N/A';
    if (is_string($val)) {
        $t = trim($val);
        if ($t === '' || strcasecmp($t,'n/a') === 0) return 'N/A';
        if ($zero_invalid && preg_match('/^0(?:\.0+)?$/', $t)) return 'N/A';
        return esc_html($t);
    }
    if (is_numeric($val)) {
        if ($zero_invalid && (float)$val == 0.0) return 'N/A';
        if ($precision !== null) {
            $val = number_format_i18n((float)$val, $precision);
        } else {
            $val = (intval($val) == $val) ? intval($val) : rtrim(rtrim((string)$val,'0'),'.');
        }
        return esc_html($val . $suffix);
    }
    return 'N/A';
}

/**
 * Thresholds (override with filter 'wpsa_compare_thresholds').
 *   // REMEMBER TO EDIT admin-scripts.js and report-scripts.js as well!
 */
function wpsa_compare_get_thresholds() {
    $defaults = [
        // Module 1
        'ttfb' => [ 'good' => 300, 'warn' => 500 ],     // ms

        // Module 5
        'lcp'  => [ 'good' => 2.5, 'warn' => 4.0 ],     // sec
        'fcp'  => [ 'good' => 1.8, 'warn' => 3.0 ],     // sec
        'cls'  => [ 'good' => 0.10, 'warn' => 0.25 ], // unitless (CWV)
        'inp'  => [ 'good' => 200,  'warn' => 500 ],  // ms (CWV)

        // Module 2
        'req'  => [ 'good' => 50,  'warn' => 70  ],     // requests
        'page_kb' => [ 'good' => 2048, 'warn' => 3584 ], // Page size (KB) — own limits
        'onjs'  => [ 'good' => 11, 'warn' => 17 ],     // JS files
        'oncss' => [ 'good' => 15, 'warn' => 21 ],     // CSS files

        // Module 3
        'size' => [ 'good' => 800, 'warn' => 1024 ],    // KB (Autoload)

        // Module 4.5
        'plug' => [ 'good' => 40,  'warn' => 55  ],     // active plugins
    ];
    return apply_filters( 'wpsa_compare_thresholds', $defaults );
}


/** Helpers for version coloring */
function wpsa_compare_parse_version($str) {
    if (!is_string($str) || $str==='') return [null, $str];
    if (preg_match('/(\d+\.\d+)/', $str, $m)) {
        return [floatval(str_replace(',', '.', $m[1])), $str];
    }
    return [null, $str];
}
function wpsa_compare_php_class($str) {
    [$v] = wpsa_compare_parse_version($str);
    if ($v === null) return 'cr-na';
    // Good on 8.0+, otherwise bad (no warn tier)
    return ($v >= 8.0) ? 'cr-ok' : 'cr-bad';
}
function wpsa_compare_db_class($str) {
    [$v] = wpsa_compare_parse_version($str);
    $s = strtolower((string)$str);
    if ($v === null) return 'cr-na';
    if (strpos($s, 'mariadb') !== false) {
        if ($v >= 10.6) return 'cr-ok';
        if ($v >= 10.4) return 'cr-warn';
        return 'cr-bad';
    }
    // MySQL
    return ($v >= 8.0) ? 'cr-ok' : 'cr-bad';
}

    // Treat http/https, trailing slash, and www as the same (robust for raw or canonical input)
    function wpsa_compare_canon_url( $url ) {
        $s = trim( (string) $url );
        if ( $s === '' ) return '';
    
        // Strip scheme and leading www.
        $s = preg_replace('#^\s*https?://#i', '', $s);
        $s = preg_replace('#^www\.#i', '', $s);
    
        // Split host + path from whatever remains (works for "example.com", "example.com/",
        // "example.com/foo", and fully-qualified URLs we stripped above)
        $parts = explode('/', $s, 2);
        $host  = strtolower( $parts[0] );
        $path  = isset($parts[1]) && $parts[1] !== '' ? '/' . rtrim($parts[1], '/') : '/';
    
        return $host . $path;  // e.g. "example.com/" or "example.com/foo"
    }

    // --- Compare helpers ---
    function wpsa_delta($new, $old) {
        $n = is_numeric($new) ? (float)$new : null;
        $o = is_numeric($old) ? (float)$old : null;
        if ($n === null || $o === null) return [null, null];
        $abs = $n - $o;
        $pct = ($o == 0.0) ? null : ($abs / $o * 100.0);
        return [$abs, $pct];
    }

    /**
     * Choose color class for a change. If $lower_is_better=true:
     *  - negative (got smaller) => good (green)
     *  - positive => bad (red)
     * If false (higher better), reverse.
     */
    function wpsa_delta_class($delta, $lower_is_better = true) {
        if ($delta === null || abs($delta) < 1e-9) return 'cr-na';
        $good = $lower_is_better ? ($delta < 0) : ($delta > 0);
        return $good ? 'cr-ok' : 'cr-bad';
    }
    
    /** Format a value with unit; null => N/A */
    function wpsa_fmt($val, $unit = '') {
        if ($val === null || $val === '') return 'N/A';
        if (is_numeric($val)) {
            $n = is_int($val) ? $val : rtrim(rtrim(number_format((float)$val, 2, '.', ''), '0'), '.');
            return $unit ? "{$n} {$unit}" : (string)$n;
        }
        return esc_html((string)$val);
    }


/**
 * Render Compare Results panel.
 */
function wpsa_render_compare_results_panel_ui() {
    if ( ! current_user_can( 'manage_options' ) ) {
        echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
        return;
    }
    
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        if ($active_tab !== 'compare') {
            // Bail early if somehow invoked on a different tab
            return;
        }


// Chart.js for trends (safe to enqueue in admin)
    wp_enqueue_script(
        'wpsa-chartjs',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
        array(),
        '4.4.1',
        true
    );


    // Prefer /uploads/speed-analyzer/
    $upload       = wp_upload_dir();
    $dir_sa       = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
    $log_pref     = trailingslashit( $dir_sa ) . 'ttfb-results-log.txt';
    $log_fallback = trailingslashit( $upload['basedir'] ) . 'ttfb-results-log.txt';
    $log_path     = file_exists( $log_pref ) ? $log_pref : $log_fallback;
    $log_path     = wp_normalize_path( $log_path );

    $has_data = ( file_exists( $log_path ) && is_readable( $log_path ) && filesize( $log_path ) > 0 );

    // Inline styles (v0.018 + requested tweaks)
    echo '<style>
      /* Logo: absolute, top-left, within 750px context */
      #wpsa-compare-panel .wpsa-logo-fixed{
        position:absolute;display:block;height:50px;margin:0 auto 6px;
        left:0;top:30px;max-width:750px
      }

      /* Chips */
      #wpsa-compare-panel .cr-chip{display:inline-block;padding:2px 7px;border-radius:12px;font-size:12px;color:#1d2327;line-height:1}
      #wpsa-compare-panel .cr-ok{background:#d7ffd9;}
      #wpsa-compare-panel .cr-warn{background:#fff5cc;}
      #wpsa-compare-panel .cr-bad{background:#ffd9d7;}
      #wpsa-compare-panel .cr-na{background:#eceff1;color:#5f6b73;}
      
    /* Scheduled run badge (S) */
      #wpsa-compare-panel .wpsa-badge-s{
        display:inline-block;
        margin-left:3px;
        padding:1px 5px;
        border-radius:999px;
        font-size:10px;
        font-weight:600;
        background:#e5ecff;
        color:#1d4ed8;
        vertical-align:middle;
      }


      /* Controls row: one line, perfectly aligned */
     #wpsa-compare-panel .wpsa-compare-form{display:flex;align-items:center;gap:8px;margin:0 auto 10px;max-width:950px;flex-wrap:nowrap;white-space:nowrap}
     #wpsa-compare-panel .wpsa-compare-form select{height:28px}
     #wpsa-compare-panel .wpsa-compare-form label,.wpsa-compare-form span{line-height:28px}
     #wpsa-compare-panel .wpsa-compare-form select[name="cr_url"]{min-width:340px;flex:1 1 auto}
     #wpsa-compare-panel .wpsa-compare-form label{white-space:nowrap}

      /* Title sits ~30px above controls */
      .wpsa-compare-title{max-width:750px;margin:0 auto 30px;position:relative}

      /* Table wrapper + taller viewport */
            #wpsa-compare-panel .cr-table{
        overflow:auto;
        max-height:720px;
        border:1px solid #ccd0d4;
        border-radius:10px;
        max-width:980px;
        margin:0 auto;
        position:relative; /* needed for row URL banner positioning */
      }

      #wpsa-compare-panel table.widefat{min-width:980px;border-collapse:separate;border-spacing:0;}
      #wpsa-compare-panel table.widefat th,
      #wpsa-compare-panel table.widefat td{vertical-align:middle;text-align:center;padding:4px 6px;}
      #wpsa-compare-panel table.widefat tr:nth-child(even){background:#f3f4f6;}
      #wpsa-compare-panel table.widefat thead th{padding:6px 6px;line-height:1.1;font-size:14px;background-color:gainsboro;}

      .h-sub{display:block;font-size:12px;color:#6b7280;font-weight:400;margin-top:2px}

      th.col-test,     td.col-test     {width:55px}
      th.col-datetime, td.col-datetime {width:70px}
      th.col-ttfb,     td.col-ttfb     {width:30px}
      th.col-cache,    td.col-cache    {width:50px}
      th.col-lcp,      td.col-lcp      {width:70px}
      th.col-fcp,      td.col-fcp      {width:70px}
      th.col-autokb,   td.col-autokb   {width:70px}
      th.col-req,      td.col-req      {width:70px}
      th.col-psize,   td.col-psize     {width:75px}
      th.col-onjs,     td.col-onjs     {width:55px}
      th.col-oncss,    td.col-oncss    {width:55px}
      th.col-plugins,  td.col-plugins  {width:55px}
      th.col-poc,      td.col-poc      {width:55px}
      th.col-php,      td.col-php      {width:40px}
      th.col-db,       td.col-db       {width:55px}
      
      th.col-cls,      td.col-cls      {width:70px}
      th.col-inp,      td.col-inp      {width:80px}
      
      
            /* Hover highlight for compare rows (only used in All S runs mode) */
      #wpsa-compare-panel tr.wpsa-compare-row-hover td{
        background:#fff7e6 !important;
      }

      /* URL banner shown over/under the hovered row */
      #wpsa-compare-panel .wpsa-hover-url-banner{
        position:absolute;
        left:16px;
        right:16px;
        padding:4px 8px;
        background:rgba(255,248,235,0.96);
        border:1px solid #fbbf24;
        border-radius:6px;
        font-size:12px;
        color:#92400e;
        box-shadow:0 1px 3px rgba(0,0,0,0.06);
        pointer-events:none;
        z-index:20;
        display:none;
      }
      #wpsa-compare-panel .wpsa-hover-url-label{
        font-weight:600;
        margin-right:4px;
      }


      
      /* header wrapping control */
         #wpsa-compare-panel table.widefat th.col-datetime{
           white-space: nowrap !important;     /* keep Date/Time on one line */
           overflow-wrap: normal !important;
           word-break: keep-all !important;
           hyphens: none !important;
         }
         #wpsa-compare-panel table.widefat th.col-poc{
           white-space: normal !important;     /* allow break between words */
           overflow-wrap: normal !important;   /* but never split “Persistent” */
           word-break: keep-all !important;
           hyphens: none !important;
         }
         
      .md-wrap{display:flex;flex-direction:column;gap:3px;align-items:center}
      .md-row{display:flex;align-items:center;gap:6px}
      .md-tag{font-size:11px;color:#6b7280}

      .dt-stack{font-size:12px;line-height:1.15}
      .dt-stack .d{display:block}
      .dt-stack .t{display:block;opacity:.9}
      
      td.col-test{font-size:12px;}
      #wpsa-compare-panel .wpsa-trends{max-width:980px;margin:27px auto 0;}
      #wpsa-compare-panel .wpsa-trends .chart-card{background:#fff;border:1px solid #ccd0d4;border-radius:10px;padding:12px;margin-bottom:12px;position:relative}
        #wpsa-compare-panel .wpsa-trends h3{margin:0 0 10px;font-size:15px;color:#222}
        #wpsa-compare-panel .wpsa-trends .chart-wrap{position:relative;height:260px}
        
        /* NEW: small pill copy button anchored to bottom-right of the card */
        #wpsa-compare-panel .wpsa-copy-chip{
          position:absolute; right:12px; bottom:12px;
          display:inline-flex; align-items:center; gap:6px;
          padding:6px 10px; font-size:12px; line-height:1;
          border:1px solid #d0d7de; border-radius:8px;
          background:#f6f8fa; color:#1f2328; cursor:pointer;
        }
        #wpsa-compare-panel .wpsa-copy-chip:hover{background:#eef1f4}
        #wpsa-compare-panel .wpsa-copy-chip .dashicons{font-size:14px; width:14px; height:14px}

        
        /* controls above/inside cards */
       #wpsa-compare-panel .wpsa-trends .controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 8px}
       #wpsa-compare-panel .wpsa-trends .controls select{height:28px}

        
        /* Mini table under Requests chart */
      #wpsa-compare-panel  .wpsa-mini{width:100%;border-collapse:separate;border-spacing:0;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0}
      #wpsa-compare-panel  .wpsa-mini th, #wpsa-compare-panel .wpsa-mini td{padding:6px 8px;text-align:center;font-size:12px;border-bottom:1px solid #eef2f7}
      #wpsa-compare-panel  .wpsa-mini thead th{background:#f3f4f6;font-weight:600}
        .wpsa-mini tr:last-child td{border-bottom:none}
        
         /* section/title rows inside the mini table */
          #wpsa-compare-panel  .wpsa-mini .section-title{
            text-align:left;font-weight:600;background:#e9ecef;
            padding:8px 10px;border-bottom:1px solid #dde3ea;
          }
        
        /* reuse chip colors for cells */
      #wpsa-compare-panel   .wpsa-mini .cr-ok{background:#d7ffd9}
      #wpsa-compare-panel  .wpsa-mini .cr-warn{background:#fff5cc}
      #wpsa-compare-panel  .wpsa-mini .cr-bad{background:#ffd9d7}
      #wpsa-compare-panel  .wpsa-mini .cr-na{background:#eceff1;color:#5f6b73}
     #wpsa-compare-panel   .wpsa-mini .txt-right{text-align:right}
     #wpsa-compare-panel .wpsa-mini tr.group-alt td{ background:#f7f8fa; }   /* light gray */
     
    /* PSI score badge: black circle, same color as text */
        #wpsa-compare-panel .psi-badge{
          display:inline-flex; align-items:center; justify-content:center;
          width:28px; height:28px; border-radius:50%;
          border:2px solid currentColor;           /* border matches text color */
          color:inherit; font-weight:600; font-size:12px; line-height:1;
        }


    </style>';
    
    // Logo + Title
    echo '<div id="wpsa-compare-panel">';
    
    echo '<h1 class="wpsa-header">'
    . '<img class="wpsa-logo"'
    . '     src="' . esc_url( plugin_dir_url( __FILE__ ) . 'SAWP-logo.svg' ) . '"'
    . '     alt="Speed Analyzer Logo">'
    . '<div class="wpsa-header-info">'
    . '  <span class="wpsa-name">Speed Analyzer</span>'
    . '  <small class="wpsa-version">v' . esc_html( SAWP_VERSION ) . '</small>'
    . '  <p class="wpsa-header-subtitle">A first step towards a faster website</p>'
    . '  <p id="wpsa-header-credit">'
    . '    Developed by Dalibor Druzinec / '
    . '    <a href="https://wpservice.pro/" target="_blank">WPservice.pro</a>'
    . '  </p>'
    . '</div>'
    . '</h1>';

    
    echo '<h2 class="wpsa-module-title wpsa-compare-title">Compare Results</h2>';
    
    if ( ! $has_data ) {
        echo '<p class="description" style="max-width:750px;margin:0 auto;font-size: 15px;color: #555555;">No previous test results found. Please run some tests.</p>';
        echo '</div>'; // ← CLOSE #wpsa-compare-panel (was missing)
        return;
    }


        // Rows count
    $allowed_counts = [5, 10, 20, 50];
    $count = isset($_GET['cr_count']) ? intval($_GET['cr_count']) : 5;
    if ( ! in_array( $count, $allowed_counts, true ) ) {
        $count = 5;
    }

    // Scheduled-runs filter:
    //  cr_sonly = '0'   → all runs for this URL
    //  cr_sonly = '1'   → S runs only (this URL)
    //  cr_sonly = 'all' → All S runs only (any URL)
    $selected_s = isset($_GET['cr_sonly']) ? sanitize_text_field( wp_unslash( $_GET['cr_sonly'] ) ) : '0';
    if ( ! in_array( $selected_s, ['0', '1', 'all'], true ) ) {
        $selected_s = '0';
    }
    $show_s_for_url = ( $selected_s === '1' );
    $show_s_all     = ( $selected_s === 'all' );


    // Read file → blocks
    $blocks = [];
    $raw = (string) @file_get_contents( $log_path );
    if ( $raw !== '' ) {
        $parts = preg_split( "/\R{2,}/", $raw );
        foreach ( $parts as $p ) {
            $p = trim( $p );
            if ( $p !== '' ) $blocks[] = $p;
        }
    }

    // Parse blocks
    $rows = [];
    foreach ( $blocks as $b ) {
        $lines = preg_split( "/\R/", $b );
        if ( empty( $lines ) ) continue;

                $h = $lines[0];
        if ( preg_match(
            '/^Test\s+#(\d+)(?:\s+([A-Z]))?\s*\|\s*([^\|]+)\|\s*([^\|]+)\s*\|\s*Tier:\s*([^\|]+)\|\s*URL:\s*([^\|]+)\|\s*Region:\s*([^\|]+)\|\s*Cache:\s*([^\|]+)\|\s*WP Rocket:\s*([^\|]+)\|\s*TTFB:\s*([0-9]+|N\/A)\s*ms/i',
            $h, $m
        ) ) {
            $entry = [
                'test'        => intval($m[1]),
                'flag'        => isset($m[2]) ? trim($m[2]) : '',   // "S" for scheduled, "" for manual
                'version'     => trim($m[3]),
                'datetime'    => trim($m[4]),
                'tier'        => trim($m[5]),
                'url'         => trim($m[6]),

                'region'      => trim($m[7]),
                'cache'       => trim($m[8]),
                'wprocket'    => trim($m[9]),
                'ttfb_ms'     => (strtoupper(trim($m[10]))==='N/A') ? null : intval($m[10]),
                'm3_autoload_kb' => null,
                'm4_poc'         => null,
                'm45_plugins'    => null,
                'm45_php'        => null,
                'm45_db'         => null,
                'm2m_req' => null, 'm2d_req' => null,
                'm2m_size_kb' => null, 'm2d_size_kb' => null,
                'm2m_onjs' => null, 'm2d_onjs' => null,
                'm2m_oncss'=> null, 'm2d_oncss'=> null,
                'm5m_perf'=> null, 'm5m_lcp'=> null, 'm5m_fcp'=> null,
                'm5d_perf'=> null, 'm5d_lcp'=> null, 'm5d_fcp'=> null,
                'm5m_cls'=> null, 'm5m_inp'=> null,
                'm5d_cls'=> null, 'm5d_inp'=> null,
            ];
            
            // Precompute timestamp once; reuse later for table + charts
            $entry['ts'] = strtotime( $entry['datetime'] );
            if ( ! is_int( $entry['ts'] ) ) {
                $entry['ts'] = 0;
            }

            
            // after filling the entry:
            $entry['canon'] = wpsa_compare_canon_url( $entry['url'] );

            $nbsp   = "\xC2\xA0";
            $sp_any = "(?:\s|$nbsp)";

            foreach ( array_slice( $lines, 1 ) as $ln ) {
                $ln = trim($ln);

                if ( preg_match('/^Module\s+3:\s*Autoloaded options size:\s*(.+)$/i', $ln, $mm) ) {
                    $val = trim($mm[1]);
                    $entry['m3_autoload_kb'] = ( stripos($val,'N/A')!==false ) ? null : floatval( str_replace(['KB',' ', ','], ['', '', '.'], $val) );
                }
                elseif ( preg_match('/^Module\s+4:\s*Persistent object cache:\s*(.+)$/i', $ln, $mm) ) {
                    $entry['m4_poc'] = trim($mm[1]);
                }
                elseif ( preg_match('/^Module\s+4\.5:\s*Active plugins:\s*([0-9]+);\s*PHP:\s*([^;]+);\s*DB:\s*(.+)$/i', $ln, $mm) ) {
                    $entry['m45_plugins'] = intval($mm[1]);
                    $entry['m45_php']     = trim($mm[2]);
                    $entry['m45_db']      = trim($mm[3]);
                }
                
                elseif ( preg_match(
                '/^Module\s+5\s+Mobile:\s*Performance:\s*([0-9]+|N\/A)\s*,\s*'
               .'LCP:\s*(N\/A|[0-9]+(?:\.[0-9]+)?)(?:' . $sp_any . 's)?\s*,\s*'
               .'FCP:\s*(N\/A|[0-9]+(?:\.[0-9]+)?)(?:' . $sp_any . 's)?'
               .'(?:\s*,\s*CLS:\s*(N\/A|[0-9]+(?:\.[0-9]+)?))?'
               .'(?:\s*,\s*INP:\s*(N\/A|[0-9]+))?'
               .'\s*$/iu', $ln, $mm) ) {
            
                $entry['m5m_perf'] = (strcasecmp($mm[1],'N/A')===0) ? null : intval($mm[1]);
                $entry['m5m_lcp']  = (strcasecmp($mm[2],'N/A')===0) ? null : floatval(str_replace(',', '.', $mm[2]));
                $entry['m5m_fcp']  = (strcasecmp($mm[3],'N/A')===0) ? null : floatval(str_replace(',', '.', $mm[3]));
                $entry['m5m_cls']  = (isset($mm[4]) && strcasecmp($mm[4],'N/A')!==0) ? floatval(str_replace(',', '.', $mm[4])) : null;
                $entry['m5m_inp']  = (isset($mm[5]) && strcasecmp($mm[5],'N/A')!==0) ? intval($mm[5]) : null;
            }
            elseif ( preg_match(
                '/^Module\s+5\s+Desktop:\s*Performance:\s*([0-9]+|N\/A)\s*,\s*'
               .'LCP:\s*(N\/A|[0-9]+(?:\.[0-9]+)?)(?:' . $sp_any . 's)?\s*,\s*'
               .'FCP:\s*(N\/A|[0-9]+(?:\.[0-9]+)?)(?:' . $sp_any . 's)?'
               .'(?:\s*,\s*CLS:\s*(N\/A|[0-9]+(?:\.[0-9]+)?))?'
               .'(?:\s*,\s*INP:\s*(N\/A|[0-9]+))?'
               .'\s*$/iu', $ln, $mm) ) {
            
                $entry['m5d_perf'] = (strcasecmp($mm[1],'N/A')===0) ? null : intval($mm[1]);
                $entry['m5d_lcp']  = (strcasecmp($mm[2],'N/A')===0) ? null : floatval(str_replace(',', '.', $mm[2]));
                $entry['m5d_fcp']  = (strcasecmp($mm[3],'N/A')===0) ? null : floatval(str_replace(',', '.', $mm[3]));
                $entry['m5d_cls']  = (isset($mm[4]) && strcasecmp($mm[4],'N/A')!==0) ? floatval(str_replace(',', '.', $mm[4])) : null;
                $entry['m5d_inp']  = (isset($mm[5]) && strcasecmp($mm[5],'N/A')!==0) ? intval($mm[5]) : null;
            }
                
                elseif ( preg_match('/^Module\s+2\s+Mobile:\s*([0-9]+|N\/A)\s*req,\s*([0-9\.]+|N\/A)\s*KB(?:;\s*Onload\s+JS:\s*([0-9]+))?(?:;\s*Onload\s+CSS:\s*([0-9]+))?(?:;.*)?$/i', $ln, $mm) ) {
                    $entry['m2m_req']   = (strcasecmp($mm[1], 'N/A') === 0) ? null : intval($mm[1]);
                    $entry['m2m_size_kb'] = (strcasecmp($mm[2], 'N/A') === 0) ? null : floatval(str_replace(',', '.', $mm[2]));
                    $entry['m2m_onjs']  = isset($mm[3]) && $mm[3] !== '' ? intval($mm[3]) : null;
                    $entry['m2m_oncss'] = isset($mm[4]) && $mm[4] !== '' ? intval($mm[4]) : null;
                }
                elseif ( preg_match('/^Module\s+2\s+Desktop:\s*([0-9]+|N\/A)\s*req,\s*([0-9\.]+|N\/A)\s*KB(?:;\s*Onload\s+JS:\s*([0-9]+))?(?:;\s*Onload\s+CSS:\s*([0-9]+))?(?:;.*)?$/i', $ln, $mm) ) {
                    $entry['m2d_req']   = (strcasecmp($mm[1], 'N/A') === 0) ? null : intval($mm[1]);
                     $entry['m2d_size_kb'] = (strcasecmp($mm[2], 'N/A') === 0) ? null : floatval(str_replace(',', '.', $mm[2]));
                    $entry['m2d_onjs']  = isset($mm[3]) && $mm[3] !== '' ? intval($mm[3]) : null;
                    $entry['m2d_oncss'] = isset($mm[4]) && $mm[4] !== '' ? intval($mm[4]) : null;
                }

            }

            $rows[] = $entry;
        }
    }

    if ( empty( $rows ) ) {
        echo '<p class="description" style="max-width:750px;margin:0 auto;">No previous test results found. Please run some tests.</p>';
        return;
    }

    // newest first
    usort( $rows, fn($a,$b) => $b['test'] <=> $a['test'] );
    
    // Build label-per-canonical from newest row first
    $canon_to_label = [];
    foreach ($rows as $row) {
        if (!isset($canon_to_label[$row['canon']])) {
            // Use the first (newest) original URL as the label
            $canon_to_label[$row['canon']] = $row['url'];
        }
    }
    
    // Selected canonical key (accepts either raw URL or canonical in the query)
    $raw_selected = isset($_GET['cr_url']) ? trim( wp_unslash( $_GET['cr_url'] ) ) : '';
    $selected_key = $raw_selected !== '' ? wpsa_compare_canon_url($raw_selected)
                                         : ( $rows[0]['canon'] ?? '' );

        // Filter rows by canonical + S-runs options
    $rows_all_for_url = array_values(
        array_filter(
            $rows,
            function ( $r ) use ( $selected_key, $show_s_for_url, $show_s_all ) {
                $flag = isset( $r['flag'] ) ? strtoupper( $r['flag'] ) : '';

                // "All S runs only" → ignore URL filter, return ALL scheduled runs
                if ( $show_s_all ) {
                    return ( $flag === 'S' );
                }

                // Otherwise we are scoped to a single URL
                if ( $r['canon'] !== $selected_key ) {
                    return false;
                }

                // "S runs only (this URL)"
                if ( $show_s_for_url && $flag !== 'S' ) {
                    return false;
                }

                return true;
            }
        )
    );


    // Table/charts still respect the "Show last X runs" limit
    $rows = array_slice($rows_all_for_url, 0, $count);


    $t = wpsa_compare_get_thresholds();

    // chips
      $chip = function( $val, $type='na' ) use ( $t ) {
        $cls = 'cr-na';
    
        if ( $type === 'bool' ) {
            $s = is_string($val) ? trim($val) : '';
            if ( $val === null || $s === '' || strcasecmp($s, 'n/a') === 0 || strcasecmp($s, 'na') === 0 ) {
                $cls = 'cr-na';                          // N/A / empty → gray
            } elseif ( stripos($s, 'yes') !== false || stripos($s, 'hit') !== false ) {
                $cls = 'cr-ok';                          // yes / HIT → green
            } elseif ( stripos($s, 'dynamic') !== false || stripos($s, 'bypass') !== false ) {
                $cls = 'cr-warn';                        // dynamic / bypass → amber
            } else {
                $cls = 'cr-bad';                         // no / miss / anything else → red
            }
        } elseif ( $type === 'ttfb' && is_numeric($val) ) {
            $cls = ($val <= $t['ttfb']['good']) ? 'cr-ok' : ( ($val <= $t['ttfb']['warn']) ? 'cr-warn' : 'cr-bad' );
        } elseif ( $type === 'size' && is_numeric($val) ) {
            $cls = ($val <  $t['size']['good']) ? 'cr-ok' : ( ($val <= $t['size']['warn']) ? 'cr-warn' : 'cr-bad' );
        } elseif ( ($type === 'lcp' || $type === 'fcp') && is_numeric($val) ) {
        $key = ($type === 'lcp') ? 'lcp' : 'fcp';
        $cls = ($val <= $t[$key]['good']) ? 'cr-ok' : ( ($val <= $t[$key]['warn']) ? 'cr-warn' : 'cr-bad' );
        } elseif ( $type === 'req' ) {
            if (!is_numeric($val) || (int)$val === 0) {
                $cls = 'cr-na'; // treat 0 or missing as N/A
        } else {
                $cls = ($val <= $t['req']['good']) ? 'cr-ok' : ( ($val <= $t['req']['warn']) ? 'cr-warn' : 'cr-bad' );
            }
        } elseif ( $type === 'pagekb' ) {
           if (!is_numeric($val)) {
               $cls = 'cr-na';
           } else {
               $cls = ((float)$val <= $t['page_kb']['good']) ? 'cr-ok'
                    : ( ((float)$val <= $t['page_kb']['warn']) ? 'cr-warn' : 'cr-bad' );
              }
        } elseif ( $type === 'onjs' || $type === 'oncss' ) {
               // Own thresholds for Onload files
               $key = $type; // 'onjs' or 'oncss'
               if (!is_numeric($val) || (int)$val === 0) {
                   $cls = 'cr-na';
        } else {
                   $cls = ((int)$val <= $t[$key]['good']) ? 'cr-ok'
                        : ( ((int)$val <= $t[$key]['warn']) ? 'cr-warn' : 'cr-bad' );
               }
               
        } elseif ( $type === 'cls' ) {
            if (!is_numeric($val)) { $cls='cr-na'; }
            else {
                $cls = ($val <= $t['cls']['good']) ? 'cr-ok' : ( ($val <= $t['cls']['warn']) ? 'cr-warn' : 'cr-bad' );
            }
        
        } elseif ( $type === 'inp' ) {
            if (!is_numeric($val)) { $cls='cr-na'; }
            else {
                $cls = ($val <= $t['inp']['good']) ? 'cr-ok' : ( ($val <= $t['inp']['warn']) ? 'cr-warn' : 'cr-bad' );
            }
       

        } elseif ( $type === 'plug' && is_numeric($val) ) {
            $cls = ($val <= $t['plug']['good']) ? 'cr-ok' : ( ($val <= $t['plug']['warn']) ? 'cr-warn' : 'cr-bad' );
        } elseif ( $type === 'phpv' ) {
            $cls = wpsa_compare_php_class((string)$val);
        } elseif ( $type === 'dbv' ) {
            $cls = wpsa_compare_db_class((string)$val);
        }

        if ($type === 'req') {
            $txt = wpsa_display_metric($val, ['zero_invalid' => true]);
        } else {
            // no units inside chips; headings already show them (e.g., “Autoload (KB)”)
            $txt = wpsa_display_metric($val);
        }

        return '<span class="cr-chip '.$cls.'">'.$txt.'</span>';
    };
    
    // Render a PSI badge if numeric; otherwise show N/A/plain text
        $psi_badge = function($val) {
            // treat "N/A", "", null as N/A
            if ($val === null) return 'N/A';
            if (is_string($val) && (trim($val)==='' || strcasecmp(trim($val),'n/a')===0)) return 'N/A';
            if (!is_numeric($val)) return wpsa_display_metric($val);
            $n = (int) round($val);
            return '<span class="psi-badge" aria-label="PSI score">'.esc_html($n).'</span>';
        };
        
        // PSI rows: higher is better (so delta coloring is reversed)
        $render_row_psi = function($label, $old, $new, $shade) use ($psi_badge) {
            [$d_abs, $d_pct] = wpsa_delta($new, $old);
            $cls     = wpsa_delta_class($d_abs, /* lower_is_better = */ false);
            $pct_txt = ($d_pct === null) ? 'N/A'
                      : (( $d_pct > 0 ? '+' : '' ) . rtrim(rtrim(number_format($d_pct, 1, '.', ''), '0'), '.') . '%');
        
            echo '<tr'.($shade?' class="group-alt"':'').'>';
            echo '  <td style="text-align:left">'.esc_html($label).'</td>';
            echo '  <td>'.$psi_badge($old).'</td>';
            echo '  <td>'.$psi_badge($new).'</td>';
            echo '  <td><span class="cr-chip '.$cls.'">'.wpsa_display_metric($d_abs).'</span></td>';
            echo '  <td><span class="cr-chip '.$cls.'">'.$pct_txt.'</span></td>';
            echo '</tr>';
        };


    // Controls (one line)
    echo '<form method="get" class="wpsa-compare-form">';
    echo '<input type="hidden" name="page" value="speed-analyzer">';
    echo '<input type="hidden" name="tab" value="compare">';

    echo '<label class="wpsa-form-label">Show last&nbsp;';
    echo '<select name="cr_count">';
    foreach ( $allowed_counts as $opt ) {
        printf('<option value="%1$d" %2$s>%1$d</option>', $opt, selected( $count, $opt, false ));
    }
    echo '</select>&nbsp;runs</label>';

    echo '<span style="margin-left:12px;">Showing tests for:&nbsp;</span>';
    echo '<select name="cr_url">';
    
    // When "All S runs only" is selected, show a blank option so the field looks empty.
    if ( $show_s_all ) {
        echo '<option value="" selected="selected"></option>';
    }
    
    foreach ( $canon_to_label as $canon => $label ) {
        printf(
            '<option value="%1$s" %3$s>%2$s</option>',
            esc_attr( $canon ),        // value = canonical key
            esc_html( $label ),        // label = readable original URL
            selected( $selected_key, $canon, false )
        );
    }
    echo '</select>';


        // Scheduled-runs selector
    echo '<label style="margin-left:12px;display:inline-flex;align-items:center;gap:4px;">';
    echo '  <span>Runs:</span>';
    echo '  <select name="cr_sonly" title="Filter by scheduled runs">';
    echo '    <option value="0"'   . selected( $selected_s, '0',   false ) . '>All runs (this URL)</option>';
    echo '    <option value="1"'   . selected( $selected_s, '1',   false ) . '>S runs only (this URL)</option>';
    echo '    <option value="all"' . selected( $selected_s, 'all', false ) . '>All S runs only (any URL)</option>';
    echo '  </select>';
    echo '</label>';


    echo '</form>';


    // HEAD echo '<th class="col-datetime">Date/Time</th>';
    echo '<div class="cr-table"><table class="widefat striped fixed">';
    echo '<thead><tr>';
    echo '<th class="col-test">Test # <span class="wpsa-badge-s" title="S = Scheduled test (run automatically)">S</span></th>';

    echo '<th class="col-datetime"><span class="h-main">Date/Time</span><span class="h-sub">UTC time</span></th>';
    echo '<th class="col-ttfb">TTFB</th>';
    echo '<th class="col-cache">Cache</th>';
    echo '<th class="col-lcp"><span class="h-main">LCP (s)</span><span class="h-sub">Mobile / Desktop</span></th>';
    echo '<th class="col-fcp"><span class="h-main">FCP (s)</span><span class="h-sub">Mobile / Desktop</span></th>';
    echo '<th class="col-cls"><span class="h-main">CLS</span><span class="h-sub">Mobile / Desktop</span></th>';
    echo '<th class="col-inp"><span class="h-main">INP (ms)</span><span class="h-sub">Mobile / Desktop</span></th>';
    echo '<th class="col-req"><span class="h-main">Requests</span><span class="h-sub">Mobile / Desktop</span></th>';
    echo '<th class="col-psize"><span class="h-main">Page size</span><span class="h-sub">(KB) — Mobile / Desktop</span></th>';
    echo '<th class="col-onjs"><span class="h-main">Onload JS</span></th>';
        echo '<th class="col-oncss"><span class="h-main">Onload CSS</span></th>';
    echo '<th class="col-plugins">Active plugins</th>';
    echo '<th class="col-autokb"><span class="h-main">Autoload</span><span class="h-sub">(KB)</span></th>';
    echo '<th class="col-poc">Persistent object cache</th>';
    echo '<th class="col-php">PHP</th>';
    echo '<th class="col-db">DB</th>';
    echo '</tr></thead><tbody>';

    foreach ( $rows as $r ) {
        // Date + Time split into two lines
        $date_part = $r['datetime'];
        $time_part = '';
        $ts = isset( $r['ts'] ) ? (int) $r['ts'] : 0;
        if ( $ts ) {
            $date_part = date( 'Y-m-d', $ts );
            $time_part = date( 'H:i',   $ts );
        } else {
            if ( preg_match('/^([^T ]+)[T ](.+)$/', $r['datetime'], $mm) ) {
                $date_part = $mm[1];
                $time_part = $mm[2];
            }
        }
        $dt_stacked = '<span class="dt-stack"><span class="d">'.esc_html($date_part).'</span><span class="t">'.esc_html($time_part).'</span></span>';

        // M/D stacks
       $lcp_md = '<div class="md-wrap">'
        . '<div class="md-row"><span class="md-tag">M</span>'.$chip($r['m5m_lcp'],'lcp').'</div>'
        . '<div class="md-row"><span class="md-tag">D</span>'.$chip($r['m5d_lcp'],'lcp').'</div>'
        . '</div>';

        $fcp_md = '<div class="md-wrap">'
        . '<div class="md-row"><span class="md-tag">M</span>'.$chip($r['m5m_fcp'],'fcp').'</div>'
        . '<div class="md-row"><span class="md-tag">D</span>'.$chip($r['m5d_fcp'],'fcp').'</div>'
        . '</div>';
        
        $cls_md = '<div class="md-wrap">'
        . '<div class="md-row"><span class="md-tag">M</span>'.$chip($r['m5m_cls'],'cls').'</div>'
        . '<div class="md-row"><span class="md-tag">D</span>'.$chip($r['m5d_cls'],'cls').'</div>'
        . '</div>';

        $inp_md = '<div class="md-wrap">'
        . '<div class="md-row"><span class="md-tag">M</span>'.$chip($r['m5m_inp'],'inp').'</div>'
        . '<div class="md-row"><span class="md-tag">D</span>'.$chip($r['m5d_inp'],'inp').'</div>'
        . '</div>';


        // Requests – SAME M/D styling order (tag LEFT, chip RIGHT)
        $req_md = '<div class="md-wrap">'
                . '<div class="md-row"><span class="md-tag">M</span>'.$chip($r['m2m_req'],'req').'</div>'
                . '<div class="md-row"><span class="md-tag">D</span>'.$chip($r['m2d_req'],'req').'</div>'
                . '</div>';
        
        $psize_md = '<div class="md-wrap">'
             . '<div class="md-row"><span class="md-tag">M</span>'.$chip($r['m2m_size_kb'],'pagekb').'</div>'
             . '<div class="md-row"><span class="md-tag">D</span>'.$chip($r['m2d_size_kb'],'pagekb').'</div>'
             . '</div>';
        
        // Onload (single value) — use max(M, D) so we never under-report
        $onjs_val  = (is_numeric($r['m2m_onjs'])  || is_numeric($r['m2d_onjs']))
                   ? max( (int)($r['m2m_onjs'] ?? 0),  (int)($r['m2d_onjs'] ?? 0) )
                   : null;
        $oncss_val = (is_numeric($r['m2m_oncss']) || is_numeric($r['m2d_oncss']))
                   ? max( (int)($r['m2m_oncss'] ?? 0), (int)($r['m2d_oncss'] ?? 0) )
                   : null;
        $onjs_md  = $chip($onjs_val,  'onjs');
        $oncss_md = $chip( $oncss_val, 'oncss' );

        $is_sched    = ! empty( $r['flag'] ) && strtoupper( $r['flag'] ) === 'S';
        $row_classes = 'wpsa-compare-row';
        if ( $is_sched ) {
            $row_classes .= ' wpsa-compare-row-sched';
        }
    
        echo '<tr class="' . esc_attr( $row_classes ) . '" data-url="' . esc_attr( $r['url'] ) . '">';
        echo '<td class="col-test"><strong>' . esc_html( $r['test'] ) . '</strong>';

        if ( $is_sched ) {
            echo ' <span class="wpsa-badge-s" title="Scheduled test (run automatically)">S</span>';
        }
        echo '</td>';
        echo '<td class="col-datetime">'.$dt_stacked.'</td>';

        echo '<td class="col-ttfb">'.$chip( $r['ttfb_ms'], 'ttfb' ).' <small>ms</small></td>';
        echo '<td class="col-cache">'.$chip( $r['cache'], 'bool' ).'</td>';
        echo '<td class="col-lcp">'.$lcp_md.'</td>';
        echo '<td class="col-fcp">'.$fcp_md.'</td>';
        echo '<td class="col-cls">'.$cls_md.'</td>';
        echo '<td class="col-inp">'.$inp_md.'</td>';
        echo '<td class="col-req">'.$req_md.'</td>';
        echo '<td class="col-psize">'.$psize_md.'</td>';
        echo '<td class="col-onjs">'.$onjs_md.'</td>';
        echo '<td class="col-oncss">'.$oncss_md.'</td>';
        echo '<td class="col-plugins">'.$chip( $r['m45_plugins'], 'plug' ).'</td>';
        echo '<td class="col-autokb">'.$chip( $r['m3_autoload_kb'], 'size' ).'</td>';
        echo '<td class="col-poc">'.$chip( $r['m4_poc'], 'bool' ).'</td>';
        echo '<td class="col-php">'.$chip( $r['m45_php'], 'phpv' ).'</td>';
        echo '<td class="col-db">'.$chip( $r['m45_db'],  'dbv' ).'</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    // Hover URL banner (positioned over/near the hovered row)
    echo '<div id="wpsa-compare-hover-url" class="wpsa-hover-url-banner" aria-hidden="true">'
        . '<span class="wpsa-hover-url-label">URL:</span> '
        . '<span class="wpsa-hover-url-value"></span>'
        . '</div>';
    
    echo '</div>';
    
    
        // ------- Compare two tests (A vs B) =======

    // Build helpers from ALL rows for this URL / filter (not limited by "Show last X runs")
    $by_test       = [];
    $test_ids      = [];
    $canon_by_test = [];

    foreach ( $rows_all_for_url as $r ) {
        $tid = $r['test'];
        $by_test[ $tid ]       = $r;
        $test_ids[]            = $tid;
        $canon_by_test[ $tid ] = isset( $r['canon'] ) ? $r['canon'] : wpsa_compare_canon_url( $r['url'] ?? '' );
    }

    // newest first already; keep it that way for dropdown order
    $test_ids = array_values( array_unique( $test_ids ) );

    echo '<div class="wpsa-trends" id="wpsa-ab" style="margin-top:18px">';
    echo '  <div class="chart-card">';
    echo '    <div class="controls">';
    echo '      <h3 style="margin:0">Compare two tests — same URL</h3>';
    echo '      <span style="flex:1"></span>';

    if ( count( $test_ids ) === 0 ) {
        // no tests at all (shouldn’t happen because we returned earlier, but belt & suspenders)
        echo '      <span style="color:#666">No tests available for comparison.</span>';
        echo '    </div>'; // .controls
        echo '  </div>';   // .chart-card
        echo '</div>';     // .wpsa-trends
    } else {

              // --- Defaults: newest vs previous (or same if only one) ---
        if ( $show_s_all && ! empty( $test_ids ) ) {
            // All S runs (any URL):
            // A = newest S-run overall, B = previous S-run *with same URL* if possible.
            $default_a = $test_ids[0];
            $canonA    = $canon_by_test[ $default_a ] ?? null;

            $same_url_for_a = array_values(
                array_filter(
                    $test_ids,
                    function ( $tid ) use ( $canon_by_test, $canonA, $default_a ) {
                        return isset( $canon_by_test[ $tid ] )
                            && $canon_by_test[ $tid ] === $canonA
                            && $tid !== $default_a;
                    }
                )
            );

            $default_b = $same_url_for_a[0] ?? $default_a;
        } else {
            // Normal modes (URL already filtered above)
            $default_a = $test_ids[0];
            $default_b = $test_ids[1] ?? $test_ids[0];
        }

        // Apply user selection, fall back to sane defaults if invalid
        $cmp_a = isset( $_GET['cr_cmp_a'] ) ? intval( $_GET['cr_cmp_a'] ) : $default_a;
        if ( ! isset( $by_test[ $cmp_a ] ) ) {
            $cmp_a = $default_a;
        }

        // Always allow all tests in B; JS will filter by URL in "All S runs only" mode.
        $cmp_b = isset( $_GET['cr_cmp_b'] ) ? intval( $_GET['cr_cmp_b'] ) : $default_b;
        if ( ! isset( $by_test[ $cmp_b ] ) ) {
            $cmp_b = $default_b;
        }

        // Safety: on submit, still force B to share the same canonical URL as A in All-S mode.
        if ( $show_s_all ) {
            $canonA = $canon_by_test[ $cmp_a ] ?? null;
            if ( $canonA && isset( $canon_by_test[ $cmp_b ] ) && $canon_by_test[ $cmp_b ] !== $canonA ) {
                foreach ( $test_ids as $tid ) {
                    if ( isset( $canon_by_test[ $tid ] ) && $canon_by_test[ $tid ] === $canonA ) {
                        $cmp_b = $tid;
                        break;
                    }
                }
            }
        }

        // Form submits to same admin URL + anchor so we land back on this section
        echo '      <form method="get" action="#wpsa-ab" style="display:flex;gap:8px;align-items:center;margin:0">';
        echo '        <input type="hidden" name="page" value="speed-analyzer">';
        echo '        <input type="hidden" name="tab" value="compare">';
        echo '        <input type="hidden" name="cr_url" value="' . esc_attr( $selected_key ) . '">';
        echo '        <input type="hidden" name="cr_count" value="' . esc_attr( $count ) . '">';
        // Preserve current S-filter selection (0 / 1 / all) when submitting A/B compare form.
        echo '        <input type="hidden" name="cr_sonly" value="' . esc_attr( $selected_s ) . '">';

        // Test A options (with data-canon for JS)
        echo '        <label>Test A:&nbsp;<select name="cr_cmp_a">';
        foreach ( $test_ids as $tid ) {
            $flag  = isset( $by_test[ $tid ]['flag'] ) ? strtoupper( $by_test[ $tid ]['flag'] ) : '';
            $label = '#' . $tid . ( $flag === 'S' ? ' S' : '' );
            $canon = isset( $by_test[ $tid ]['canon'] ) ? $by_test[ $tid ]['canon'] : '';
            $attrs = $canon !== '' ? ' data-canon="' . esc_attr( $canon ) . '"' : '';

            printf(
                '<option value="%1$d"%4$s %2$s>%3$s</option>',
                $tid,
                selected( $cmp_a, $tid, false ),
                esc_html( $label ),
                $attrs
            );
        }
        echo '        </select></label>';

        // Test B options (full set; JS hides other URLs in All-S mode)
        echo '        <label>Test B:&nbsp;<select name="cr_cmp_b">';
        foreach ( $test_ids as $tid ) {
            $flag  = isset( $by_test[ $tid ]['flag'] ) ? strtoupper( $by_test[ $tid ]['flag'] ) : '';
            $label = '#' . $tid . ( $flag === 'S' ? ' S' : '' );
            $canon = isset( $by_test[ $tid ]['canon'] ) ? $by_test[ $tid ]['canon'] : '';
            $attrs = $canon !== '' ? ' data-canon="' . esc_attr( $canon ) . '"' : '';

            printf(
                '<option value="%1$d"%4$s %2$s>%3$s</option>',
                $tid,
                selected( $cmp_b, $tid, false ),
                esc_html( $label ),
                $attrs
            );
        }
        echo '        </select></label>';

        echo '        <button class="button button-primary">Compare</button>';
        echo '      </form>';
        echo '    </div>'; // .controls
            
           
        
            // Note text + single-test notice
            if (count($test_ids) < 2) {
                echo '    <p style="margin:6px 0 10px;color:#555">Only one test found. Showing A vs A; deltas will be zero.</p>';
            } else {
                echo '    <p style="margin:6px 0 10px;color:#555">Comparing <strong>#'.esc_html($cmp_a).'</strong> (new) vs <strong>#'.esc_html($cmp_b).'</strong> (old). Percentages show change from B → A.</p>';
            }
        
            $A = $by_test[$cmp_a];
            $B = $by_test[$cmp_b];
            
            $A_onjs  = (is_numeric($A['m2m_onjs'])  || is_numeric($A['m2d_onjs']))  ? max((int)($A['m2m_onjs']??0),  (int)($A['m2d_onjs']??0))  : null;
            $A_oncss = (is_numeric($A['m2m_oncss']) || is_numeric($A['m2d_oncss'])) ? max((int)($A['m2m_oncss']??0), (int)($A['m2d_oncss']??0)) : null;
            $B_onjs  = (is_numeric($B['m2m_onjs'])  || is_numeric($B['m2d_onjs']))  ? max((int)($B['m2m_onjs']??0),  (int)($B['m2d_onjs']??0))  : null;
            $B_oncss = (is_numeric($B['m2m_oncss']) || is_numeric($B['m2d_oncss'])) ? max((int)($B['m2m_oncss']??0), (int)($B['m2d_oncss']??0)) : null;

        
            $metrics = [
              ['ttfb_ms',        'TTFB',                 'ms', true, false],
              ['m5m_lcp',        'LCP Mobile',           's',  true, false],
              ['m5d_lcp',        'LCP Desktop',          's',  true, false],
              ['m5m_fcp',        'FCP Mobile',           's',  true, false],
              ['m5d_fcp',        'FCP Desktop',          's',  true, false],
              ['m2m_req',        'Requests Mobile',      '',   true, true ], // zero invalid
              ['m2d_req',        'Requests Desktop',     '',   true, true ],
              // NEW: page size (KB)
              ['m2m_size_kb',    'Page size Mobile',     'KB', true, false],
              ['m2d_size_kb',    'Page size Desktop',    'KB', true, false],
              // existing
              ['m3_autoload_kb', 'Autoload',             'KB', true, false],
              ['m45_plugins',    'Active plugins',       '',   true, false],
            ];

        
            echo '    <table id="wpsaCompareABTable" class="wpsa-mini" style="margin-top:6px">';
            echo '      <thead><tr>';
            echo '        <th style="text-align:left">Metric</th>';
            echo '        <th>#'.esc_html($cmp_b).'</th>';
            echo '        <th>#'.esc_html($cmp_a).'</th>';
            echo '        <th>Δ (abs)</th>';
            echo '        <th>Δ (%)</th>';
            echo '      </tr></thead><tbody>';
        
      // ---- helpers for grouped zebra + consistent rounding on Δ(abs) ----
            $fmt_delta_abs = function($d_abs, $unit){
                if ($d_abs === null) return 'N/A';
                if ($unit === 'KB' || $unit === 's') {
                    $d_abs = round((float)$d_abs, 1);
                } elseif ($unit === 'ms') {
                    $d_abs = (int)round((float)$d_abs);
                }
                return wpsa_display_metric($d_abs, ['suffix' => ($unit ? ' '.$unit : '')]);
            };
            
            $render_row = function($label, $old, $new, $unit, $lower_better, $zero_invalid, $shade) use ($fmt_delta_abs){
                // treat zero as invalid when asked (Module 2 requests)
                if ($zero_invalid) {
                    if ($old !== null && is_numeric($old) && (float)$old == 0.0) $old = null;
                    if ($new !== null && is_numeric($new) && (float)$new == 0.0) $new = null;
                }
                [$d_abs, $d_pct] = wpsa_delta($new, $old);
                $cls     = wpsa_delta_class($d_abs, $lower_better);
                $pct_txt = ($d_pct === null) ? 'N/A'
                          : (( $d_pct > 0 ? '+' : '' ) . rtrim(rtrim(number_format($d_pct, 1, '.', ''), '0'), '.') . '%');
            
                echo '<tr'.($shade?' class="group-alt"':'').'>';
                echo '  <td style="text-align:left">'.esc_html($label).'</td>';
                echo '  <td>'. wpsa_display_metric($old, ['suffix'=>$unit, 'zero_invalid'=>$zero_invalid]) .'</td>';
                echo '  <td>'. wpsa_display_metric($new, ['suffix'=>$unit, 'zero_invalid'=>$zero_invalid]) .'</td>';
                echo '  <td><span class="cr-chip '.$cls.'">'.$fmt_delta_abs($d_abs, $unit).'</span></td>';
                echo '  <td><span class="cr-chip '.$cls.'">'.$pct_txt.'</span></td>';
                echo '</tr>';
            };
            
            $shade = false; // toggles after each GROUP
             
            // helper: section title row
            $render_title = function($title){
                echo '<tr><td class="section-title" colspan="5">'.esc_html($title).'</td></tr>';
            };

            $shade = false; // toggles after each GROUP

            // --- URL row (always shown, single URL from Test A/new) ---
            $urlA = isset( $A['url'] ) ? trim( (string) $A['url'] ) : '';
            echo '<tr>';
            echo '  <td style="text-align:left">URL</td>';
            echo '  <td></td>'; // old column intentionally left blank (no placeholder)
            echo '  <td>' . ( $urlA !== '' ? esc_html( $urlA ) : 'N/A' ) . '</td>';
            echo '  <td colspan="2"></td>'; // no Δ chip for URL
            echo '</tr>';


            /* ========== Server Performance ========== */
            $render_title('Server Performance');

            // TTFB (has deltas; lower is better)
            $render_row('TTFB (server response time)', $B['ttfb_ms'], $A['ttfb_ms'], 'ms', true, false, $shade);
            $shade = !$shade;
            // Cache (no deltas)
            echo '<tr'.($shade?' class="group-alt"':'').'>';
            echo '  <td style="text-align:left">Cache status</td>';
            echo '  <td>'.wpsa_fmt($B['cache']).'</td>';
            echo '  <td>'.wpsa_fmt($A['cache']).'</td>';
            echo '  <td colspan="2"><span class="cr-chip cr-na">—</span></td>';
            echo '</tr>';
            $shade = !$shade;

            /* ========== Page asset summary (Mobile) ========== */
            $render_title('Page asset summary — Mobile');
            $render_row('Requests #',      $B['m2m_req'],      $A['m2m_req'],      '',  true, true,  $shade);
            $render_row('Page size',     $B['m2m_size_kb'],  $A['m2m_size_kb'],  'KB',true, false, $shade);
            $render_row('Onload JS files',     $B['m2m_onjs'],     $A['m2m_onjs'],     '',  true, false, $shade);
            $render_row('Onload CSS files',    $B['m2m_oncss'],    $A['m2m_oncss'],    '',  true, false, $shade);
            $shade = !$shade;

            /* ========== Page asset summary (Desktop) ========== */
            $render_title('Page asset summary — Desktop');
            $render_row('Requests #',     $B['m2d_req'],      $A['m2d_req'],      '',  true, true,  $shade);
            $render_row('Page size',    $B['m2d_size_kb'],  $A['m2d_size_kb'],  'KB',true, false, $shade);
            $render_row('Onload JS files',    $B['m2d_onjs'],     $A['m2d_onjs'],     '',  true, false, $shade);
            $render_row('Onload CSS files',   $B['m2d_oncss'],    $A['m2d_oncss'],    '',  true, false, $shade);
            $shade = !$shade;

            /* ========== PSI metrics (Mobile) ========== */
            $render_title('PSI metrics — Mobile');
            // Higher is better here (delta coloring reversed is handled inside $render_row_psi)
            $render_row_psi('Performance score',  $B['m5m_perf'], $A['m5m_perf'], $shade);
            // LCP, FCP (lower is better)
            $render_row('LCP',              $B['m5m_lcp'],  $A['m5m_lcp'],  's', true,  false, $shade);
            $render_row('FCP',              $B['m5m_fcp'],  $A['m5m_fcp'],  's', true,  false, $shade);
            $shade = !$shade;
            $render_row('CLS', $B['m5m_cls'], $A['m5m_cls'], '',  true,  false, $shade);
            $render_row('INP', $B['m5m_inp'], $A['m5m_inp'], 'ms', true,  false, $shade);


            /* ========== PSI metrics (Desktop) ========== */
            $render_title('PSI metrics — Desktop');
            $render_row_psi('Performance score', $B['m5d_perf'], $A['m5d_perf'], $shade);
            $render_row('LCP',             $B['m5d_lcp'],  $A['m5d_lcp'],  's', true,  false, $shade);
            $render_row('FCP',             $B['m5d_fcp'],  $A['m5d_fcp'],  's', true,  false, $shade);
            $shade = !$shade;
            $render_row('CLS', $B['m5d_cls'], $A['m5d_cls'], '',  true,  false, $shade);
            $render_row('INP', $B['m5d_inp'], $A['m5d_inp'], 'ms', true,  false, $shade);


            /* ========== Other info ========== */
            $render_title('Other info');
            $render_row('DB Autoloaded options size', $B['m3_autoload_kb'], $A['m3_autoload_kb'], 'KB', true, false, $shade);
            // Re-render POC as value-only row (consistent with Cache, no deltas)
            echo '<tr'.($shade?' class="group-alt"':'').'>';
            echo '  <td style="text-align:left">Persistent object cache</td>';
            echo '  <td>'.wpsa_fmt($B['m4_poc']).'</td>';
            echo '  <td>'.wpsa_fmt($A['m4_poc']).'</td>';
            echo '  <td colspan="2"><span class="cr-chip cr-na">—</span></td>';
            echo '</tr>';
            $shade = !$shade;
            $render_row('Active plugins',              $B['m45_plugins'],     $A['m45_plugins'],     '',   true, false, $shade);
            $shade = !$shade;

            /* ========== Server related ========== */
            $render_title('Server related');
            // PHP (value-only, no deltas)
            echo '<tr'.($shade?' class="group-alt"':'').'>';
            echo '  <td style="text-align:left">PHP</td>';
            echo '  <td>'.wpsa_fmt($B['m45_php']).'</td>';
            echo '  <td>'.wpsa_fmt($A['m45_php']).'</td>';
            echo '  <td colspan="2"><span class="cr-chip cr-na">—</span></td>';
            echo '</tr>';
            $shade = !$shade;
            // DB (value-only, no deltas)
            echo '<tr'.($shade?' class="group-alt"':'').'>';
            echo '  <td style="text-align:left">DB</td>';
            echo '  <td>'.wpsa_fmt($B['m45_db']).'</td>';
            echo '  <td>'.wpsa_fmt($A['m45_db']).'</td>';
            echo '  <td colspan="2"><span class="cr-chip cr-na">—</span></td>';
            echo '</tr>';
            $shade = !$shade;
            
            echo '      </tbody></table>';
            
            /* bottom-right copy chip button inside the same card */
            echo '      <button type="button" id="wpsaCopyAB" class="wpsa-copy-chip" title="Copy the table below" aria-label="Copy table">';
            echo '        <span class="dashicons dashicons-clipboard"></span> Copy table';
            echo '      </button>';

            echo '  </div>'; // .chart-card
            echo '</div>';   // .wpsa-trends
        }
    
    
    
   // ------- Trends (build datasets from $rows) -------
    $rows_chron = array_reverse( $rows ); // oldest → newest
    
    // Build raw arrays (keep timestamps for filtering)
    $labels_raw = [];
    $timestamps = [];
    $ttfb=[]; $lcp_m=[]; $lcp_d=[]; $fcp_m=[]; $fcp_d=[];
    $cls_m=[]; $cls_d=[]; $inp_m=[]; $inp_d=[];
    $req_m=[]; $req_d=[]; $plugins=[]; $autoload=[];
    
    // NEW: page size (KB) and onload file counts (max of M/D)
    $page_m = []; $page_d = [];
    $onjs   = []; $oncss  = [];
    
        foreach ($rows_chron as $r) {
        $ts = isset( $r['ts'] ) ? (int) $r['ts'] : 0;
        $timestamps[] = $ts;
        $labels_raw[] = $ts ? date('Y-m-d', $ts) : (string)$r['datetime']; // date-only

    
        $ttfb[]  = is_numeric($r['ttfb_ms'])       ? (int)$r['ttfb_ms']       : null;
        $lcp_m[] = is_numeric($r['m5m_lcp'])       ? (float)$r['m5m_lcp']     : null;
        $lcp_d[] = is_numeric($r['m5d_lcp'])       ? (float)$r['m5d_lcp']     : null;
        $fcp_m[] = is_numeric($r['m5m_fcp'])       ? (float)$r['m5m_fcp']     : null;
        $fcp_d[] = is_numeric($r['m5d_fcp'])       ? (float)$r['m5d_fcp']     : null;
        $cls_m[] = is_numeric($r['m5m_cls']) ? (float)$r['m5m_cls'] : null;
        $cls_d[] = is_numeric($r['m5d_cls']) ? (float)$r['m5d_cls'] : null;
        $inp_m[] = is_numeric($r['m5m_inp']) ? (int)$r['m5m_inp']   : null;
        $inp_d[] = is_numeric($r['m5d_inp']) ? (int)$r['m5d_inp']   : null;
        $req_m[] = (is_numeric($r['m2m_req']) && (int)$r['m2m_req'] !== 0) ? (int)$r['m2m_req'] : null;
        $req_d[] = (is_numeric($r['m2d_req']) && (int)$r['m2d_req'] !== 0) ? (int)$r['m2d_req'] : null;
    
        $plugins[]  = is_numeric($r['m45_plugins'])    ? (int)$r['m45_plugins']     : null;
        $autoload[] = is_numeric($r['m3_autoload_kb']) ? (float)$r['m3_autoload_kb']: null;
    
        // NEW:
        $page_m[] = is_numeric($r['m2m_size_kb']) ? (float)$r['m2m_size_kb'] : null;
        $page_d[] = is_numeric($r['m2d_size_kb']) ? (float)$r['m2d_size_kb'] : null;
    
        $onjs[]  = (is_numeric($r['m2m_onjs'])  || is_numeric($r['m2d_onjs']))
                 ? max( (int)($r['m2m_onjs'] ?? 0),  (int)($r['m2d_onjs'] ?? 0) ) : null;
        $oncss[] = (is_numeric($r['m2m_oncss']) || is_numeric($r['m2d_oncss']))
                 ? max( (int)($r['m2m_oncss'] ?? 0), (int)($r['m2d_oncss'] ?? 0) ) : null;
    }

        
        // Trends container
        echo '<div class="wpsa-trends">';
        
        // 1) SPEED
        echo '<div class="chart-card">';
        echo '  <div class="controls">';
        echo '    <h3 style="margin:0">Speed over time (TTFB, LCP, FCP, CLS, INP) — less is better</h3>';
        echo '    <span style="flex:1"></span>';
        echo '    <label>Timeframe:&nbsp;';
        echo '      <select id="wpsaRangeTimes">';
        echo '        <option value="all">All</option>';
        echo '        <option value="1m">Last month</option>';
        echo '        <option value="3m">3 months</option>';
        echo '        <option value="6m">6 months</option>';
        echo '        <option value="1y">1 year</option>';
        echo '      </select>';
        echo '    </label>';
        echo '    <label>Device:&nbsp;<select id="wpsaDeviceTimes"><option value="mobile" selected>Mobile</option><option value="desktop">Desktop</option><option value="all">All</option></select></label>';
        echo '    <label>Display:&nbsp;<select id="wpsaTypeTimes"><option value="line">Line</option><option value="bar">Columns</option></select></label>';
        echo '  </div>';
        echo '  <div class="chart-wrap"><canvas id="wpsaChartTimes"></canvas></div>';
        echo '</div>';
        
        // 2) REQUESTS / PLUGINS / AUTOLOAD
        echo '<div class="chart-card">';
        echo '  <div class="controls">';
        echo '    <h3 style="margin:0">Requests, Page size, Onloads, Plugins &amp; Autoload — less is better</h3>';
        echo '    <span style="flex:1"></span>';
        echo '    <label>Timeframe:&nbsp;';
        echo '      <select id="wpsaRangeReq">';
        echo '        <option value="all">All</option>';
        echo '        <option value="1m">Last month</option>';
        echo '        <option value="3m">3 months</option>';
        echo '        <option value="6m">6 months</option>';
        echo '        <option value="1y">1 year</option>';
        echo '      </select>';
        echo '    </label>';
        echo '    <label>Device:&nbsp;<select id="wpsaDeviceReq"><option value="mobile" selected>Mobile</option><option value="desktop">Desktop</option><option value="all">All</option></select></label>';
        echo '    <label>Display:&nbsp;<select id="wpsaTypeReq"><option value="line">Line</option><option value="bar">Columns</option></select></label>';
        echo '  </div>';
        echo '  <div class="chart-wrap"><canvas id="wpsaChartReq"></canvas></div>';
        echo '</div>';

        
     $js = '
        (function () {
          // Colors
          const GREEN="rgba(22,163,74,1)",   GREENA="rgba(22,163,74,0.15)";
          const BLUE="rgba(37,99,235,1)",    BLUEA="rgba(37,99,235,0.12)";
          const ORANGE="rgba(234,88,12,1)",  ORANGEA="rgba(234,88,12,0.12)";
          const PURPLE="rgba(124,58,237,1)", PURPLEA="rgba(124,58,237,0.12)";
          const YELLOW="rgba(202,138,4,1)",  YELLOWA="rgba(202,138,4,0.12)";
          const TEAL="rgba(13,148,136,1)",   TEALA="rgba(13,148,136,0.12)";
          const GRAY="rgba(107,114,128,1)",  GRAYA="rgba(107,114,128,0.12)";
          const PINK="rgba(236,72,153,1)",   PINKA="rgba(236,72,153,0.12)";
          const CYAN="rgba(6,182,212,1)",    CYANA="rgba(6,182,212,0.12)";
          const BLACK  = "rgba(17,24,39,1)", BLACKA="rgba(17,24,39,0.12)";
          const RED   = "rgba(220,38,38,1)",   REDA   = "rgba(220,38,38,0.12)";   // CLS Mobile
            const BROWN = "rgba(120,53,15,1)",   BROWNA = "rgba(120,53,15,0.12)";   // CLS Desktop
            const LIME  = "rgba(101,163,13,1)",  LIMEA  = "rgba(101,163,13,0.12)";  // INP Mobile (distinct from GREEN)
            const NAVY  = "rgba(30,58,138,1)",   NAVYA  = "rgba(30,58,138,0.12)";   // INP Desktop


        
          const labelsRaw = ' . wp_json_encode($labels_raw) . ';
          const stamps    = ' . wp_json_encode($timestamps) . ';
          const dataAll   = {
            ttfb: ' . wp_json_encode($ttfb) . ',
            lcp_m: ' . wp_json_encode($lcp_m) . ',
            lcp_d: ' . wp_json_encode($lcp_d) . ',
            fcp_m: ' . wp_json_encode($fcp_m) . ',
            fcp_d: ' . wp_json_encode($fcp_d) . ',
            cls_m: ' . wp_json_encode($cls_m) . ',
            cls_d: ' . wp_json_encode($cls_d) . ',
            inp_m: ' . wp_json_encode($inp_m) . ',
            inp_d: ' . wp_json_encode($inp_d) . ',

            req_m: ' . wp_json_encode($req_m) . ',
            req_d: ' . wp_json_encode($req_d) . ',
            plugins: ' . wp_json_encode($plugins) . ',
            autoload: ' . wp_json_encode($autoload) . ',
            page_m: ' . wp_json_encode($page_m) . ',
            page_d: ' . wp_json_encode($page_d) . ',
            onjs:   ' . wp_json_encode($onjs)   . ',
            oncss:  ' . wp_json_encode($oncss)  . '
          };
    
      const allNull = (a)=>!a || a.every(v=>v===null);
    
      function cutoffFor(r){
        if (r==="all") return -Infinity;
        const now=Date.now(), D=86400000;
        if (r==="1m") return now-31*D;
        if (r==="3m") return now-92*D;
        if (r==="6m") return now-183*D;
        if (r==="1y") return now-366*D;
        return -Infinity;
      }
    
      function filterRange(range){
        const cut = cutoffFor(range);
        const L=[], idx=[];
        for (let i=0;i<stamps.length;i++){
          if ((stamps[i]*1000||0) >= cut){ L.push(labelsRaw[i]); idx.push(i); }
        }
        const pick = arr => idx.map(i=>arr[i]);
        return {
            labels: L,
            ttfb: pick(dataAll.ttfb),
            lcp_m: pick(dataAll.lcp_m), lcp_d: pick(dataAll.lcp_d),
            fcp_m: pick(dataAll.fcp_m), fcp_d: pick(dataAll.fcp_d),
        
            // add these four:
            cls_m: pick(dataAll.cls_m), cls_d: pick(dataAll.cls_d),
            inp_m: pick(dataAll.inp_m), inp_d: pick(dataAll.inp_d),
        
            req_m: pick(dataAll.req_m), req_d: pick(dataAll.req_d),
            plugins: pick(dataAll.plugins), autoload: pick(dataAll.autoload),
            page_m: pick(dataAll.page_m), page_d: pick(dataAll.page_d),
            onjs: pick(dataAll.onjs), oncss: pick(dataAll.oncss)
          };

      }
    
      function xTicksFont(ctx){
        const lbl = ctx.tick && ctx.tick.label ? String(ctx.tick.label) : "";
        return { weight: lbl.slice(-2)==="01" ? "bold" : "normal" };
      }
    
      const ds = (label, data, color, fill, extra={}) => Object.assign({
        label, data, borderColor: color, backgroundColor: fill, pointBackgroundColor: color,
        borderWidth:2, pointRadius:2, spanGaps:true, tension:0.25
      }, extra);
    
      let chartTimes=null, chartReq=null;
    
      function buildTimes(range, type, device){
          const d = filterRange(range);
          const cfg = {
            type: type || "line",
            data: {
              labels: d.labels,
              datasets: [
                // dotted for TTFB
                ds("TTFB (ms)",       d.ttfb,  BLACK,   BLACKA,   { yAxisID:"yms" }),
                // solid = mobile
                ds("LCP Mobile (s)",  d.lcp_m, ORANGE, ORANGEA, { yAxisID:"ys"  }),
                ds("FCP Mobile (s)",  d.fcp_m, GREEN,  GREENA,  { yAxisID:"ys"  }),
                // dashed = desktop
                ds("LCP Desktop (s)", d.lcp_d, PURPLE, PURPLEA, { yAxisID:"ys", borderDash:[6,4] }),
                ds("FCP Desktop (s)", d.fcp_d, TEAL,   TEALA,   { yAxisID:"ys", borderDash:[6,4] }),
                
                // CLS (unitless)
                ds("CLS Mobile",         d.cls_m, YELLOW, YELLOWA,   { yAxisID:"ycls" }),
                ds("CLS Desktop",        d.cls_d, BROWN, BROWNA, { yAxisID:"ycls", borderDash:[6,4] }),
                
                // INP (ms)
                ds("INP Mobile (ms)",    d.inp_m, LIME,  LIMEA,  { yAxisID:"yms"  }),
                ds("INP Desktop (ms)",   d.inp_d, NAVY,  NAVYA,  { yAxisID:"yms",  borderDash:[6,4] }),

              ]
            },

          options: {
            responsive:true, maintainAspectRatio:false,
            interaction:{mode:"index", intersect:false},
            scales:{
              x:{ ticks:{ callback:(v,i)=> d.labels[i], font: xTicksFont } },
              yms:{ position:"left",  title:{display:true, text:"ms"} },
            ys: { position:"right", title:{display:true, text:"seconds"}, grid:{drawOnChartArea:false} },
            ycls:{ position:"right", title:{display:true, text:"CLS"}, grid:{drawOnChartArea:false}, offset:true, suggestedMin:0, suggestedMax:0.4 }
            },
            plugins:{ legend:{position:"bottom"} }
          }
        };
        cfg.data.datasets.forEach(s=>{ if(allNull(s.data)) s.hidden=true; });
        const dev = (device || "mobile").toLowerCase();
        if (dev === "mobile") {
          cfg.data.datasets.forEach(s => { if (/Desktop/.test(s.label)) s.hidden = true; });
        } else if (dev === "desktop") {
          cfg.data.datasets.forEach(s => { if (/Mobile/.test(s.label)) s.hidden = true; });
        }

        const el = document.getElementById("wpsaChartTimes");
        if (!el) return;
        if (chartTimes) chartTimes.destroy();
        chartTimes = new Chart(el, cfg);
      }
    
     function buildReq(range, type, device){
        const d = filterRange(range);
        const cfg = {
          type: type || "line",
          data: {
            labels: d.labels,
            datasets: [
              // solid = mobile
              ds("Requests Mobile",        d.req_m,   BLUE,    BLUEA,   { yAxisID:"yCount" }),
              ds("Page size Mobile (KB)",  d.page_m,  ORANGE,  ORANGEA, { yAxisID:"yKB" }),
              // dashed = desktop
              ds("Requests Desktop",       d.req_d,   PURPLE,  PURPLEA, { yAxisID:"yCount", borderDash:[6,4] }),
              ds("Page size Desktop (KB)", d.page_d,  TEAL,    TEALA,   { yAxisID:"yKB",    borderDash:[6,4] }),
              // onload files (solid)
              ds("Onload JS (files)",      d.onjs,    PINK,    PINKA,   { yAxisID:"yCount" }),
              ds("Onload CSS (files)",     d.oncss,   CYAN,    CYANA,   { yAxisID:"yCount" }),
              // plugins solid; autoload dotted
              ds("Active plugins",         d.plugins, YELLOW,  YELLOWA, { yAxisID:"yCount" }),
              ds("Autoload (KB)",          d.autoload,GRAY,    GRAYA,   { yAxisID:"yKB",    borderDash:[1,3] })
            ]

          },
          options: {
            responsive:true, maintainAspectRatio:false,
            interaction:{mode:"index", intersect:false},
            scales:{
          x:{ ticks:{ callback:(v,i)=> d.labels[i], font: xTicksFont } },
          yCount:{ position:"left", beginAtZero:true, title:{display:true,text:"count"}, ticks:{precision:0} },
          yKB:{ position:"right", beginAtZero:true, title:{display:true,text:"KB"}, grid:{drawOnChartArea:false}, ticks:{precision:0} }
        },
        plugins:{
          legend:{position:"bottom"},
          tooltip:{ callbacks:{ label(ctx){
            const label = ctx.dataset.label || "";
            const v = ctx.parsed.y;
            // Show KB for page size & autoload datasets
            return ctx.dataset.yAxisID==="yKB" ? `${label}: ${v} KB` : `${label}: ${v}`;
          } } }
        }
      }
    };
    cfg.data.datasets.forEach(s=>{ if(allNull(s.data)) s.hidden=true; });
    const dev = (device || "mobile").toLowerCase();
    if (dev === "mobile") {
      cfg.data.datasets.forEach(s => { if (/Desktop/.test(s.label)) s.hidden = true; });
    } else if (dev === "desktop") {
      cfg.data.datasets.forEach(s => { if (/Mobile/.test(s.label)) s.hidden = true; });
    }

        const el = document.getElementById("wpsaChartReq");
        if (!el) return;
        if (chartReq) chartReq.destroy();
        chartReq = new Chart(el, cfg);
      }
    
      // Per-chart selectors
      const selRangeT = document.getElementById("wpsaRangeTimes");
      const selTypeT  = document.getElementById("wpsaTypeTimes");
      const selRangeR = document.getElementById("wpsaRangeReq");
      const selTypeR  = document.getElementById("wpsaTypeReq");
      const selDeviceT = document.getElementById("wpsaDeviceTimes");
      const selDeviceR = document.getElementById("wpsaDeviceReq");

    
      function refreshTimes(){ buildTimes(selRangeT ? selRangeT.value : "all", selTypeT ? selTypeT.value : "line", selDeviceT ? selDeviceT.value : "mobile"); }
      function refreshReq(){   buildReq(  selRangeR ? selRangeR.value : "all", selTypeR ? selTypeR.value : "line", selDeviceR ? selDeviceR.value : "mobile"); }

    
      if (selRangeT) selRangeT.addEventListener("change", refreshTimes);
        if (selTypeT)  selTypeT.addEventListener("change",  refreshTimes);
        if (selDeviceT) selDeviceT.addEventListener("change", refreshTimes);
        
        if (selRangeR) selRangeR.addEventListener("change", refreshReq);
        if (selTypeR)  selTypeR.addEventListener("change",  refreshReq);
        if (selDeviceR) selDeviceR.addEventListener("change", refreshReq);

    
      // initial render
      refreshTimes();
      refreshReq();
    })();
    ';
        
         wp_add_inline_script( 'wpsa-chartjs', $js, 'after' );

        // Second inline JS block: copy A/B table + live filters + hover URL banner
        $js_helpers = <<<'WPSA_COPY'
    (function(){
      // --- Copy A/B table helper ------------------------------------------------
      function tableToPretty(tbl){
        if(!tbl) return "";
        const rows = [...tbl.rows].map(tr =>
          [...tr.cells].map(td => (td.innerText || "")
            .replace(/\u00A0/g, " ")        // NBSP -> space
            .replace(/\s+/g, " ")           // collapse whitespace
            .replace(/^\s+|\s+$/g, "")      // trim
          )
        );
        const cols = rows.reduce((m,r)=>Math.max(m, r.length), 0);
        const widths = Array(cols).fill(0);
        rows.forEach(r => r.forEach((c,i)=>{ widths[i] = Math.max(widths[i], c.length); }));
        const pad = (s,w) => s + " ".repeat(Math.max(0, w - s.length));
        const lines = rows.map(r => r.map((c,i)=>pad(c,widths[i])).join("  "));
        if(lines.length > 1){
          const sep = widths.map(w => "-".repeat(Math.max(3,w))).join("  ");
          lines.splice(1, 0, sep);
        }
        return lines.join("\n");
      }
    
      async function copyText(text){
        try{
          await navigator.clipboard.writeText(text);
        }catch(e){
          const ta=document.createElement("textarea");
          ta.value=text; document.body.appendChild(ta);
          ta.select(); document.execCommand("copy"); document.body.removeChild(ta);
        }
      }
    
      const btn = document.getElementById("wpsaCopyAB");
      if(btn){
        btn.addEventListener("click", async function(){
          const tbl = document.getElementById("wpsaCompareABTable");
          const text = tableToPretty(tbl);
          await copyText(text);
          const old = btn.textContent; btn.textContent = "Copied!";
          setTimeout(()=>btn.textContent = old, 1200);
        });
      }
    
      // --- changed in v1.17: Live filters for Compare form (auto-apply on change) ---
      (function(){
        const compareForm = document.querySelector("#wpsa-compare-panel form.wpsa-compare-form");
        if (!compareForm) return;
    
        const selCount   = compareForm.querySelector('select[name="cr_count"]');
        const selUrl     = compareForm.querySelector('select[name="cr_url"]');
        const selSfilter = compareForm.querySelector('select[name="cr_sonly"]');
    
        function applyFilters() {
          try {
            const params = new URLSearchParams(new FormData(compareForm));
    
            // Remove stale A/B params
            params.delete("cr_cmp_a");
            params.delete("cr_cmp_b");
    
            const url = new URL(window.location.href);
            url.search = params.toString();
            window.location.assign(url.toString());
          } catch(e) {
            compareForm.submit();
          }
        }
    
        if (selCount)   selCount.addEventListener("change", applyFilters);
        if (selUrl)     selUrl.addEventListener("change", applyFilters);
        if (selSfilter) selSfilter.addEventListener("change", applyFilters);
      })(); // end inner IIFE
    
      // --- Hover URL banner for "All S runs only" view -------------------------
      (function(){
        var wrapper   = document.querySelector('#wpsa-compare-panel .cr-table');
        var table     = wrapper ? wrapper.querySelector('table.widefat') : null;
        var banner    = document.getElementById('wpsa-compare-hover-url');
        if (!wrapper || !table || !banner) return;
    
        var runsSelect = document.querySelector('#wpsa-compare-panel select[name="cr_sonly"]');
        var rows       = table.querySelectorAll('tbody tr.wpsa-compare-row');
        var valueNode  = banner.querySelector('.wpsa-hover-url-value');
    
        function hoverEnabled() {
          return runsSelect && runsSelect.value === 'all';
        }
    
        function clearHighlight() {
          rows.forEach(function(tr){
            tr.classList.remove('wpsa-compare-row-hover');
          });
          banner.style.display = 'none';
        }
    
        rows.forEach(function(tr){
          tr.addEventListener('mouseenter', function(){
            if (!hoverEnabled()) return;
    
            var url = tr.getAttribute('data-url') || '';
            if (!url) {
              clearHighlight();
              return;
            }
    
            rows.forEach(function(node){
              node.classList.remove('wpsa-compare-row-hover');
            });
            tr.classList.add('wpsa-compare-row-hover');
    
         // Position banner just below this row, inside the scrollable wrapper
        var rowRect  = tr.getBoundingClientRect();
        var wrapRect = wrapper.getBoundingClientRect();
        var top      = rowRect.bottom - wrapRect.top + wrapper.scrollTop + 4;
        
        banner.style.top = top + 'px';
        valueNode.textContent = url;
        banner.style.display  = 'block';

          });
    
          tr.addEventListener('mouseleave', function(){
            if (!hoverEnabled()) return;
            clearHighlight();
          });
        });
    
               // If Runs filter changes away from "All S runs only", hide banner.
        if (runsSelect) {
          runsSelect.addEventListener('change', function(){
            if (!hoverEnabled()) {
              clearHighlight();
            }
          });
        }
      })();

      // --- Limit Compare "Test B" selector to same URL as "Test A" in All S runs mode ---
      (function(){
        var runsSelect = document.querySelector('#wpsa-compare-panel select[name="cr_sonly"]');
        if (!runsSelect || runsSelect.value !== 'all') return; // only in "All S runs only (any URL)" mode

        var compareCard = document.getElementById('wpsa-ab');
        if (!compareCard) return;

        var selA = compareCard.querySelector('select[name="cr_cmp_a"]');
        var selB = compareCard.querySelector('select[name="cr_cmp_b"]');
        if (!selA || !selB) return;

        function applySameUrlFilter() {
          var optA   = selA.options[ selA.selectedIndex ];
          if (!optA) return;

          var canonA = optA.getAttribute('data-canon') || '';
          var firstValidIndex = -1;

          for (var i = 0; i < selB.options.length; i++) {
            var opt   = selB.options[i];
            var canon = opt.getAttribute('data-canon') || '';
            var match = !canonA || canon === canonA;

            opt.disabled = !match;
            opt.hidden   = !match;

            if (match && firstValidIndex === -1) {
              firstValidIndex = i;
            }
          }

          if (firstValidIndex !== -1) {
            var current = selB.options[ selB.selectedIndex ];
            if (!current || current.disabled) {
              selB.selectedIndex = firstValidIndex;
            }
          }
        }

        selA.addEventListener('change', applySameUrlFilter);

        // Initial sync on load
        applySameUrlFilter();
      })();

    })(); // closes outer IIFE
WPSA_COPY;
 

    wp_add_inline_script( 'wpsa-chartjs', $js_helpers, 'after' );


     
    echo '</div>'; // #wpsa-compare-panel  ← CLOSE WRAPPER


    }