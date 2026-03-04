<?php
/**
 * Speed Analyzer - Module 7: Conclusion
 * Version: v0.746
 *
 * Defines explanation and advice for each module outcome,
 * provides a readiness check, and renders the final conclusion section.
 */
defined( 'ABSPATH' ) || exit;

    /**
     * Normalize a URL by removing protocol and trailing slashes.
     */
    function wpsa_normalize_url_for_matching( $url ) {
        // replaced parse_url() with wp_parse_url()
        $parts = wp_parse_url( trim( $url ) );
        $host  = $parts['host'] ?? '';
        $path  = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
        return strtolower( $host . $path );
    }

    /**
     * Check if a URL is external compared to this site.
     */
    function wpsa_is_external_url( $url ) {
        // replaced parse_url() with wp_parse_url()
        $site_host = wp_parse_url( site_url(), PHP_URL_HOST );
        $test_host = wp_parse_url( $url,      PHP_URL_HOST );
        return ! $site_host || ! $test_host || strtolower( $site_host ) !== strtolower( $test_host );
    }

    /**
     * Extract a single test block from the results log.
     * - If $test_no > 0: return exactly that block.
     * - Else: return the last block whose header URL matches $tested_url.
     */
    function wpsa_extract_matching_test_block( $lines, $tested_url, $test_no = 0 ) {
        if ( ! is_array( $lines ) || ! $lines ) {
            return [];
        }
    
        $count = count( $lines );
        $norm  = wpsa_normalize_url_for_matching( $tested_url );
    
        // helper: slice from header at $start to the next header (or EOF)
        $slice_block = static function( $start ) use ( $lines, $count ) {
            $end = $count;
            for ( $j = $start + 1; $j < $count; $j++ ) {
                if ( strpos( $lines[ $j ], 'Test #' ) === 0 ) {
                    $end = $j;
                    break;
                }
            }
            return array_slice( $lines, $start, $end - $start );
        };
    
        // 1) Prefer an explicit Test # if provided
        if ( $test_no > 0 ) {
            $needle = 'Test #' . (int) $test_no . ' ';
            for ( $i = 0; $i < $count; $i++ ) {
                if ( strpos( $lines[ $i ], $needle ) === 0 ) {
                    return $slice_block( $i );
                }
            }
            // If not found, fall through to URL match.
        }
    
        // 2) Find the last block for this URL (normalized)
        $last_start = null;
        for ( $i = 0; $i < $count; $i++ ) {
            if ( strpos( $lines[ $i ], 'Test #' ) === 0 ) {
                if ( preg_match( '/URL:\s*(https?:\/\/[^\s|]+)/', $lines[ $i ], $m ) ) {
                    if ( wpsa_normalize_url_for_matching( $m[1] ) === $norm ) {
                        $last_start = $i; // keep the most recent match
                    }
                }
            }
        }
    
        if ( null !== $last_start ) {
            return $slice_block( $last_start );
        }
    
        // No match
        return [];
    }



    /**
     * Check if Module 7 can be rendered (all required data collected).
     */
        function wpsa_module7_is_ready( $tested_url, $results_log, $test_no = 0 ) {
        if ( ! file_exists( $results_log ) ) {
            return false;
        }
        $lines = file( $results_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        $block = wpsa_extract_matching_test_block( $lines, $tested_url, $test_no );
        if ( empty( $block ) ) {
            return false;
        }
    
        $joined = implode( "\n", $block );
        if ( ! preg_match( '/Cache:\s*([^|]+)/', $joined )
          || ! preg_match( '/TTFB:\s*(\d+)/',     $joined )
        ) {
            return false;
        }
    
        $is_external = wpsa_is_external_url( $tested_url );
        $needed      = [
          'Module 2 Mobile:',
          'Module 2 Desktop:',
          'Module 5 Mobile:',
          'Module 5 Desktop:'
        ];
        if ( ! $is_external ) {
            $needed[] = 'Module 3:';
            $needed[] = 'Module 4:';
        }
        foreach ( $needed as $needle ) {
            $found = false;
            foreach ( $block as $line ) {
                if ( strpos( $line, $needle ) === 0 ) {
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                return false;
            }
        }
    
        return true;
    }
    // === MAPPING: explanation + advice for each module outcome ===
    $wpsa_conclusion_map = [
        'cache_status' => [
            'HIT' => [
                'explanation' => 'Your page was delivered from cache, which is optimal.',
                'advice'      => 'No action needed—your caching configuration is working.'
            ],
            'MISS' => [
                'explanation' => 'The cache was missed; the request was served directly from the server.',
                'advice'      => 'Ensure your cache plugin or server caching is properly configured.'
            ],
            'Dynamic' => [
                'explanation' => 'A cache layer was detected but marked non-cacheable or bypassed.',
                'advice'      => 'Review your cache rules (headers, cookies, query strings) to allow caching.'
            ],
            'Bypass' => [
                'explanation' => 'A cache layer was detected but marked non-cacheable or bypassed.',
                'advice'      => 'Review your cache rules (headers, cookies, query strings) to allow caching.'
            ],
            'Handled by WP Rocket' => [
                'explanation' => 'Page served from WP Rocket’s cache plugin layer.',
                'advice'      => 'No action needed—WP Rocket is handling your caching.'
            ],
            'N/A' => [
                'explanation' => 'Cache status not detected.',
                'advice'      => 'Verify that a caching layer is in place (WP Rocket, Redis, Varnish).'
            ],
            'No cache' => [
                  'explanation' => 'No caching layer was detected on this site.',
                  'advice'      => 'Enable a caching plugin (e.g., WP Rocket; FlyingPress) or server-side cache (Redis, Varnish, Nginx).'
                ],
    
        ],
    
        'module_1' => [
            'ranges' => [
                'good' => [
                    'max'         => 300,
                    'explanation' => 'Your server responded very quickly (TTFB ≤ 300 ms), giving visitors an instant start.',
                    'advice'      => 'No action needed—just keep monitoring your server performance regularly.'
                ],
                'medium' => [
                    'max'         => 500,
                    'explanation' => 'Your TTFB is acceptable (300 ms < TTFB ≤ 500 ms) but could be improved for a snappier experience.',
                    'advice'      => 'Consider enabling server-side caching, upgrading your hosting plan, or optimizing backend processes.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'Your TTFB is slow (TTFB > 500 ms), which can frustrate users and impact SEO.',
                    'advice'      => 'Upgrade to faster hosting, optimize database queries, and enable a CDN to reduce latency.'
                ],
            ],
        ],
    
           'module_2_mobile' => [
            'requests' => [
                'good' => [
                    'max'         => 50,
                    'explanation' => 'Only ≤ 50 requests on mobile—a lightweight page structure.',
                    'advice'      => 'No action needed here; your request count is optimal.'
                ],
                'medium' => [
                    'max'         => 70,
                    'explanation' => 'Request count is moderate (50 < requests ≤ 70).',
                    'advice'      => 'Combine or defer non-critical assets, and unload unnecessary code.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'High number of requests (> 70) slows down mobile load times.',
                    'advice'      => 'Minimize plugins, inline critical CSS, and lazy-load below-the-fold assets.'
                ],
            ],
            'page_size' => [
                'good' => [
                    'max'         => 2048,
                    'explanation' => 'Page size ≤ 2 MB—excellent for mobile.',
                    'advice'      => 'No action needed.'
                ],
                'medium' => [
                    'max'         => 3584,
                    'explanation' => 'Page size is moderate (2 MB < size ≤ 3.5 MB).',
                    'advice'      => 'Compress images further and remove unused CSS.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'Page size > 3.5 MB—too heavy for mobile users.',
                    'advice'      => 'Optimize and lazy-load images; prune unused assets.'
                ],
            ],
            // --- NEW: onload thresholds for Module 2 (mobile) ---
            'onload_js' => [
                'good'   => ['max' => 10,
                             'explanation' => 'Minimal onload JavaScript keeps main thread work low.',
                             'advice' => 'No action needed. Keep third-party JS lean.'],
                'medium' => ['max' => 15,
                             'explanation' => 'Moderate number of onload JS files.',
                             'advice' => 'Defer/async non-critical scripts and bundle where possible.'],
                'bad'    => ['max' => null,
                             'explanation' => 'High number of onload JS files can delay interactivity.',
                             'advice' => 'Eliminate unused libraries, defer third-party widgets, and split non-critical code.'],
            ],
            'onload_css' => [
                'good'   => ['max' => 10,
                             'explanation' => 'Lean onload CSS reduces render-blocking work.',
                             'advice' => 'No action needed. Keep critical CSS small.'],
                'medium' => ['max' => 17,
                             'explanation' => 'Moderate number of onload CSS files.',
                             'advice' => 'Combine/simplify stylesheets and remove unused rules.'],
                'bad'    => ['max' => null,
                             'explanation' => 'Too many onload CSS files increase blocking time.',
                             'advice' => 'Consolidate CSS, load non-critical styles late, and audit theme/plugin styles.'],
            ],
        ],
    
        'module_2_desktop' => [
            'requests' => [
                'good' => [
                    'max'         => 50,
                    'explanation' => 'Only ≤ 50 requests on desktop—very efficient.',
                    'advice'      => 'No action needed.'
                ],
                'medium' => [
                    'max'         => 70,
                    'explanation' => 'Request count is moderate (50 < requests ≤ 70).',
                    'advice'      => 'Defer or async-load non-essential scripts to reduce HTTP calls.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'High number of requests (> 70) can slow down desktop loads.',
                    'advice'      => 'Bundle scripts/styles and eliminate redundant plugins.'
                ],
            ],
            'page_size' => [
                'good' => [
                    'max'         => 2048,
                    'explanation' => 'Page size ≤ 2 MB—great for desktop.',
                    'advice'      => 'No action needed.'
                ],
                'medium' => [
                    'max'         => 3584,
                    'explanation' => 'Page size is moderate (2 MB < size ≤ 3.5 MB).',
                    'advice'      => 'Optimize images/videos and enable caching headers.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'Page size > 3.5 MB—too large for fast desktop loads.',
                    'advice'      => 'Compress assets and defer non-critical resources.'
                ],
            ],
            // --- 1.16.2: onload thresholds for Module 2 (desktop) ---
            'onload_js' => [
                'good'   => ['max' => 10,
                             'explanation' => 'Minimal onload JavaScript keeps main thread work low.',
                             'advice' => 'No action needed. Keep third-party JS lean.'],
                'medium' => ['max' => 15,
                             'explanation' => 'Moderate number of onload JS files.',
                             'advice' => 'Defer/async non-critical scripts and bundle where possible.'],
                'bad'    => ['max' => null,
                             'explanation' => 'High number of onload JS files can delay interactivity.',
                             'advice' => 'Eliminate unused libraries, defer third-party widgets, and split non-critical code.'],
            ],
            'onload_css' => [
                'good'   => ['max' => 10,
                             'explanation' => 'Lean onload CSS reduces render-blocking work.',
                             'advice' => 'No action needed. Keep critical CSS small.'],
                'medium' => ['max' => 17,
                             'explanation' => 'Moderate number of onload CSS files.',
                             'advice' => 'Combine/simplify stylesheets and remove unused rules.'],
                'bad'    => ['max' => null,
                             'explanation' => 'Too many onload CSS files increase blocking time.',
                             'advice' => 'Consolidate CSS, load non-critical styles late, and audit theme/plugin styles.'],
            ],
        ],
    
    
        'module_3' => [
            'ranges' => [
                'good' => [
                    'max'         => 800,
                    'explanation' => 'Autoload size ≤ 800 KB—your database is lean.',
                    'advice'      => 'No action needed.'
                ],
                'medium' => [
                    'max'         => 1600,
                    'explanation' => 'Autoload size is moderate (800 KB < size ≤ 1.6 MB).',
                    'advice'      => 'Review and delete obsolete options.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'Autoload size > 1.6 MB—can bloat your site and slow queries.',
                    'advice'      => 'Use a database-optimization plugin to remove unused autoloaded options.'
                ],
            ],
        ],
    
        'module_4' => [
            'yes' => [
                'explanation' => 'Persistent object cache is enabled—great for repeated queries.',
                'advice'      => 'No action needed.'
            ],
            'no' => [
                'explanation' => 'Persistent object cache is not enabled.',
                'advice'      => 'Install and configure Redis or Memcached to speed up database calls.'
            ],
        ],
    
        'module_5' => [
            'lcp' => [
                'good' => [
                    'max'         => 2.5,
                    'explanation' => 'LCP ≤ 2.5 s—fast largest contentful paint.',
                    'advice'      => 'No action needed.'
                ],
                'medium' => [
                    'max'         => 4,
                    'explanation' => 'LCP is moderate (2.5 s < LCP ≤ 4 s).',
                    'advice'      => 'Prioritize critical CSS, defer non-essential scripts, and optimize images.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'LCP > 4 s—your site is slow to render main content.',
                    'advice'      => 'Inline critical CSS, use preload for hero images, and audit render-blocking resources.'
                ],
            ],
            'fcp' => [
                'good' => [
                    'max'         => 1.8,
                    'explanation' => 'FCP ≤ 1.8 s—fast first contentful paint.',
                    'advice'      => 'No action needed.'
                ],
                'medium' => [
                    'max'         => 3,
                    'explanation' => 'FCP is moderate (1.8 s < FCP ≤ 3 s).',
                    'advice'      => 'Defer non-critical JS and optimize server response.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'FCP > 3 s—visitors see a blank screen too long.',
                    'advice'      => 'Minimize JS, inline critical styles, and leverage browser caching.'
                ],
            ],
            
                        'cls' => [
                'good' => [
                    'max'         => 0.1,
                    'explanation' => 'CLS ≤ 0.1 — layout is stable while the page loads.',
                    'advice'      => 'No action needed.'
                ],
                'medium' => [
                    'max'         => 0.25,
                    'explanation' => 'CLS is moderate (0.1 < CLS ≤ 0.25) — some unexpected layout shifts may occur.',
                    'advice'      => 'Reserve space for images/ads, avoid inserting content above existing content, and use CSS aspect-ratio/placeholders.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'CLS > 0.25 — users are likely seeing disruptive layout shifts.',
                    'advice'      => 'Set width/height or aspect-ratio for media, pre-allocate space for ads/embeds, and avoid late-loading fonts/styles that reflow text.'
                ],
            ],
            'inp' => [
                'good' => [
                    'max'         => 200,
                    'explanation' => 'INP ≤ 200 ms — interactions feel instant.',
                    'advice'      => 'No action needed.'
                ],
                'medium' => [
                    'max'         => 500,
                    'explanation' => 'INP is moderate (200 ms < INP ≤ 500 ms) — some interactions may feel sluggish.',
                    'advice'      => 'Reduce main-thread work, split long JS tasks, and defer non-critical scripts.'
                ],
                'bad' => [
                    'max'         => null,
                    'explanation' => 'INP > 500 ms — interactions feel slow.',
                    'advice'      => 'Eliminate heavy JS on interaction, use requestIdleCallback, code-split, and minimize third-party widgets.'
                ],
            ],

         ],
        ];
    /**
     * Render the entire Conclusion section (Module 7).
     *
     * @param string $tested_url   The URL that was tested.
     * @param string $results_log  Path to the TTFB/results log file.
     */
        function wpsa_module7_conclusion( $tested_url, $results_log, $test_no = 0  ) {
        global $wpsa_conclusion_map;
    
        // Read & normalize the log lines
        $raw_lines = file( $results_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        $lines     = array_map( function( $l ) {
            return preg_replace( '/\x{00A0}/u', ' ', $l );
        }, $raw_lines );
    
        // Extract the exact test block (by # when provided; else most recent by URL)
        $block = wpsa_extract_matching_test_block( $lines, $tested_url, $test_no );
    
        // Parse Cache/TTFB from the header line of *this block*
        $last_test = '';
        foreach ( $block as $line ) {
            if ( strpos( $line, 'Test #' ) === 0 ) { $last_test = $line; break; }
        }
    
        // Parse cache status & TTFB
        if ( preg_match( '/Cache:\s*([^|]+)/', $last_test, $m ) ) {
            $cache = trim( $m[1] );
        } else {
            $cache = 'N/A';
        }
        if ( preg_match( '/TTFB:\s*(\d+)/', $last_test, $m2 ) ) {
            $ttfb = intval( $m2[1] );
        } else {
            $ttfb = null;
        }
    
        $cache_class = in_array( $cache, [ 'HIT', 'Handled by WP Rocket' ], true )
            ? 'good'
            : ( in_array( $cache, [ 'Dynamic', 'Bypass' ], true ) ? 'medium' : ( $cache === 'MISS' ? 'bad' : 'neutral' ) );
    
        // Helper to classify numeric metrics against a range map
        $classify = function( $val, $map ) {
            foreach ( $map as $key => $cfg ) {
                if ( is_null( $cfg['max'] ) || $val <= $cfg['max'] ) {
                    return $key;
                }
            }
            return 'bad';
        };
    
        // Module 2: requests & size (+ NEW onload counts)
        $req_m = $sz_m = $req_d = $sz_d = null;
        $onjs_m = $oncss_m = $onjs_d = $oncss_d = null;
    
        // Valid Module-2 numbers only if both req & size are positive (real pages never 0/0)
        $valid_assets = static function( $req, $kb ) {
            return is_numeric( $req ) && $req > 0 && is_numeric( $kb ) && $kb > 0;
        };
    
        foreach ( $block as $l ) {
            if ( strpos( $l, 'Module 2 Mobile:' ) === 0 ) {
                if ( preg_match( '/Mobile:\s*(\d+)\s*req,\s*([\d\.]+)\s*KB/i', $l, $m3 ) ) {
                    $r = intval( $m3[1] );
                    $s = (float) $m3[2];
                    if ( $valid_assets( $r, $s ) ) {
                        $req_m = $r;
                        $sz_m  = $s;
                    } else {
                        $req_m = $sz_m = null;
                    }
                } else {
                    $req_m = $sz_m = null;
                }
                // NEW: try to read onload JS/CSS (support several label variants)
                if ( preg_match( '/Onload\s*JS(?:\s*files)?\s*:\s*(\d+)/i',  $l, $mj ) ||
                     preg_match( '/OnloadJS\s*:\s*(\d+)/i',                $l, $mj ) ) {
                    $onjs_m = intval( $mj[1] );
                }
                if ( preg_match( '/Onload\s*CSS(?:\s*files)?\s*:\s*(\d+)/i', $l, $mc ) ||
                     preg_match( '/OnloadCSS\s*:\s*(\d+)/i',                 $l, $mc ) ) {
                    $oncss_m = intval( $mc[1] );
                }
            }
    
            if ( strpos( $l, 'Module 2 Desktop:' ) === 0 ) {
                if ( preg_match( '/Desktop:\s*(\d+)\s*req,\s*([\d\.]+)\s*KB/i', $l, $m4 ) ) {
                    $r = intval( $m4[1] );
                    $s = (float) $m4[2];
                    if ( $valid_assets( $r, $s ) ) {
                        $req_d = $r;
                        $sz_d  = $s;
                    } else {
                        $req_d = $sz_d = null;
                    }
                } else {
                    $req_d = $sz_d = null;
                }
                // NEW: onload for desktop
                if ( preg_match( '/Onload\s*JS(?:\s*files)?\s*:\s*(\d+)/i',  $l, $dj ) ||
                     preg_match( '/OnloadJS\s*:\s*(\d+)/i',                $l, $dj ) ) {
                    $onjs_d = intval( $dj[1] );
                }
                if ( preg_match( '/Onload\s*CSS(?:\s*files)?\s*:\s*(\d+)/i', $l, $dc ) ||
                     preg_match( '/OnloadCSS\s*:\s*(\d+)/i',                 $l, $dc ) ) {
                    $oncss_d = intval( $dc[1] );
                }
            }
        }
    
    
        // Module 3 & 4 (internal only)
        $a3 = $o4 = null;
        $is_external = wpsa_is_external_url( $tested_url );
        if ( ! $is_external ) {
            foreach ( $block as $l ) {
                if ( strpos( $l, 'Module 3:' ) === 0
                  && preg_match( '/size:\s*([\d\.]+)\s*KB/', $l, $m5 )
                ) {
                    $a3 = floatval( $m5[1] );
                }
                if ( strpos( $l, 'Module 4:' ) === 0 ) {
                    $o4 = ( false !== strpos( $l, ': Yes' ) );
                }
            }
        }
    
        // Module 5: LCP, FCP, CLS, INP
        $lcp_m = $fcp_m = $cls_m = $inp_m = null;
        $lcp_d = $fcp_d = $cls_d = $inp_d = null;

        foreach ( $block as $l ) {
            // Accepts numbers or N/A, optional " s", optional CLS/INP tail
            $re_m5 = '/LCP:\s*(N\/A|[\d\.]+)(?:\s*s)?\s*,\s*FCP:\s*(N\/A|[\d\.]+)(?:\s*s)?(?:\s*,\s*CLS:\s*(N\/A|[\d\.]+))?(?:\s*,\s*INP:\s*(N\/A|[\d]+))?/i';

            // Mobile
            if ( strpos( $l, 'Module 5 Mobile:' ) === 0 && preg_match( $re_m5, $l, $p1 ) ) {
                $lcp_m = (strcasecmp($p1[1],'N/A')===0) ? null : (float)$p1[1];
                $fcp_m = (strcasecmp($p1[2],'N/A')===0) ? null : (float)$p1[2];
                $cls_m = (isset($p1[3]) && strcasecmp($p1[3],'N/A')!==0) ? (float)$p1[3] : null;
                $inp_m = (isset($p1[4]) && strcasecmp($p1[4],'N/A')!==0) ? (int)$p1[4]   : null;
            }

            // Desktop
            if ( strpos( $l, 'Module 5 Desktop:' ) === 0 && preg_match( $re_m5, $l, $p2 ) ) {
                $lcp_d = (strcasecmp($p2[1],'N/A')===0) ? null : (float)$p2[1];
                $fcp_d = (strcasecmp($p2[2],'N/A')===0) ? null : (float)$p2[2];
                $cls_d = (isset($p2[3]) && strcasecmp($p2[3],'N/A')!==0) ? (float)$p2[3] : null;
                $inp_d = (isset($p2[4]) && strcasecmp($p2[4],'N/A')!==0) ? (int)$p2[4]   : null;
            }
        }

    
        // ─────────────────────────────────────────────────────────────────────
        // NEW: Module 4.5 (internal only) — Active plugins / PHP / DB vendor+ver
        // ─────────────────────────────────────────────────────────────────────
                $m45_plugins   = null;   // int
        $m45_php       = null;   // string "8.2.28"
        $m45_dbv       = null;   // "MariaDB" / "MySQL"
        $m45_dbver     = null;   // string version
        $m45_dbsize_mb = null;   // float size in MB (optional)
        if ( ! $is_external ) {
            foreach ( $block as $l ) {
                if (
                    strpos( $l, 'Module 4.5:' ) === 0
                    && preg_match(
                        '/Active plugins:\s*(\d+);\s*PHP:\s*([0-9\.]+);\s*DB:\s*([A-Za-z]+)\s+([0-9\.]+)(?:\s*\(([\d\.]+)\s*MB\))?/i',
                        $l,
                        $m45
                    )
                ) {
                    $m45_plugins = intval( $m45[1] );
                    $m45_php     = sanitize_text_field( $m45[2] );
                    $m45_dbv     = sanitize_text_field( $m45[3] );
                    $m45_dbver   = sanitize_text_field( $m45[4] );

                    // Optional DB size (MB) if present in the log line.
                    if ( isset( $m45[5] ) && is_numeric( $m45[5] ) ) {
                        $m45_dbsize_mb = (float) $m45[5];
                    }
                    break;
                }
            }
        }

    
        // Helper: DB severity
        $db_sev = 'neutral';
        if ( $m45_dbv && $m45_dbver ) {
            if ( stripos( $m45_dbv, 'mariadb' ) !== false ) {
                $db_sev = version_compare( $m45_dbver, '10.6', '>=' ) ? 'good'
                       : ( version_compare( $m45_dbver, '10.4', '>=' ) ? 'medium' : 'bad' );
            } else { // assume MySQL
                $db_sev = version_compare( $m45_dbver, '8.0', '>=' ) ? 'good' : 'bad';
            }
        }
        $php_sev  = ( $m45_php && version_compare( $m45_php, '8.0', '>=' ) ) ? 'good' : ( $m45_php ? 'bad' : 'neutral' );
        $plug_sev = 'neutral';
        if ( is_int( $m45_plugins ) ) {
            $plug_sev = ( $m45_plugins <= 40 ) ? 'good' : ( $m45_plugins <= 55 ? 'medium' : 'bad' );
        }
    
        // Build a simple recommendations list
        $recs = [];
        if ( $ttfb !== null && $ttfb > 500 ) {
            $recs[] = 'Improve your server response time.';
        }
        if ( $req_m > 70 ) {
            $recs[] = 'Reduce number of HTTP requests on mobile.';
        }
        if ( $sz_m > 3584 ) {
            $recs[] = 'Decrease overall page size on mobile.';
        }
        if ( $req_d > 70 ) {
            $recs[] = 'Reduce number of HTTP requests on desktop.';
        }
        if ( $sz_d > 3584 ) {
            $recs[] = 'Decrease overall page size on desktop.';
        }
        if ( ! is_null( $a3 ) && $a3 > 1600 ) {
            $recs[] = 'Reduce autoloaded options size.';
        }
        if ( $o4 === false ) {
            $recs[] = 'Install and configure Redis or Memcached to speed up database calls.';
        }
        if ( $lcp_m > 4 ) {
            $recs[] = 'Decrease mobile LCP.';
        }
        if ( $fcp_m > 3 ) {
            $recs[] = 'Decrease mobile FCP.';
        }
         if ( $lcp_d > 4 ) {
            $recs[] = 'Decrease desktop LCP.';
        }
        if ( $fcp_d > 3 ) {
            $recs[] = 'Decrease desktop FCP.';
        }
        
        // CLS/INP-driven recs
        if ( !is_null($cls_m) && $cls_m > 0.25 ) { $recs[] = 'Reduce mobile layout shifts (CLS).'; }
        if ( !is_null($cls_d) && $cls_d > 0.25 ) { $recs[] = 'Reduce desktop layout shifts (CLS).'; }
        if ( !is_null($inp_m) && $inp_m > 500 )  { $recs[] = 'Improve mobile interaction latency (INP).'; }
        if ( !is_null($inp_d) && $inp_d > 500 )  { $recs[] = 'Improve desktop interaction latency (INP).'; }

    
        // NEW: Onload assets (recommend when above green thresholds)
        if ( is_numeric( $onjs_m ) && $onjs_m > 10 )  { $recs[] = 'Reduce onload JavaScript files (mobile).'; }
        if ( is_numeric( $oncss_m ) && $oncss_m > 10 ){ $recs[] = 'Reduce onload CSS files (mobile).'; }
        if ( is_numeric( $onjs_d ) && $onjs_d > 10 )  { $recs[] = 'Reduce onload JavaScript files (desktop).'; }
        if ( is_numeric( $oncss_d ) && $oncss_d > 10 ){ $recs[] = 'Reduce onload CSS files (desktop).'; }
    
        // NEW: 4.5-driven recs
        if ( ! $is_external ) {
            if ( $plug_sev !== 'good' && is_int( $m45_plugins ) ) {
                $recs[] = ( $m45_plugins > 55 ) ? 'Reduce active plugins (very high count).' : 'Trim the number of active plugins.';
            }
            if ( $php_sev === 'bad' && $m45_php ) {
                $recs[] = 'Upgrade PHP to 8.0+ (preferably 8.2/8.3).';
            }
            if ( $db_sev !== 'good' && $m45_dbv && $m45_dbver ) {
                if ( stripos( $m45_dbv, 'mariadb' ) !== false ) {
                    $recs[] = ( $db_sev === 'medium' )
                        ? 'Consider upgrading database to MariaDB 10.6+.'
                        : 'Upgrade database server to MariaDB 10.6+.';
                } else {
                    $recs[] = 'Upgrade database server to MySQL 8.0+.';
                }
            }
        }
    
        // 7.1 Cache Status
        echo '<div class="wpsa-concl-section">';
          echo '<h3>8.1 Cache Status</h3>';
          echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $cache_class ) . '">';
            echo '<p>Your tested URL cache status was <strong>' . esc_html( $cache ) . '</strong>' . ( in_array( $cache, [ 'HIT', 'Handled by WP Rocket' ], true ) ? ' ✅' : '' ) . '.</p>';
            echo '<p>' . esc_html( $wpsa_conclusion_map['cache_status'][ $cache ]['explanation'] ?? 'Cache status not detected.' ) . '</p>';
            echo '<p><strong>💡 Advice: ' . esc_html( $wpsa_conclusion_map['cache_status'][ $cache ]['advice'] ?? 'Verify your caching layer (WP Rocket, Redis, Varnish).' ) . '</strong></p>';
          echo '</div>';
        echo '</div>';
    
        // 7.2 Server TTFB
        echo '<div class="wpsa-concl-section">';
          echo '<h3>8.2 Server TTFB</h3>';
          if ( null !== $ttfb ) {
              $k1 = $classify( $ttfb, $wpsa_conclusion_map['module_1']['ranges'] );
              echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $k1 ) . '">';
                echo '<p>Your tested URL’s TTFB was <strong>' . esc_html( $ttfb ) . ' ms</strong>' . ( $k1 === 'good' ? ' ✅' : '' ) . '.</p>';
                echo '<p>' . esc_html( $wpsa_conclusion_map['module_1']['ranges'][ $k1 ]['explanation'] ) . '</p>';
                echo '<p><strong>💡 Advice: ' . esc_html( $wpsa_conclusion_map['module_1']['ranges'][ $k1 ]['advice'] ) . '</strong></p>';
              echo '</div>';
          }
        echo '</div>';
    
        // 7.3 Page asset summary
        echo '<div class="wpsa-concl-section">';
          echo '<h3>8.3 Page asset summary</h3>';
        
        /**
         * Helper: print one Module-2 card.
         * $device    = 'Mobile' | 'Desktop'
         * $metricKey = 'requests' | 'page_size' | 'onload_js' | 'onload_css'
         * $value     = int|float|null
         */
        $print_m73_card = function( string $device, string $metricKey, $value ) use ( $wpsa_conclusion_map, $classify ) {
            $isMobile = ( $device === 'Mobile' );
            $mapRoot  = $isMobile ? 'module_2_mobile' : 'module_2_desktop';
        
            // Label + display value
            switch ($metricKey) {
                case 'requests':
                    $label = 'Requests';
                    $disp  = is_null($value) ? 'N/A' : (int)$value;
                    break;
                case 'page_size':
                    $label = 'Page size';
                    $disp  = is_null($value) ? 'N/A' : (float)$value . ' KB';
                    break;
                case 'onload_js':
                    $label = 'Onload JS files';
                    $disp  = is_null($value) ? 'N/A' : (int)$value;
                    break;
                default: // 'onload_css'
                    $label = 'Onload CSS files';
                    $disp  = is_null($value) ? 'N/A' : (int)$value;
                    break;
            }
        
            // Severity (neutral if N/A)
            $sev = is_null($value) ? 'neutral' : $classify( $value, $wpsa_conclusion_map[$mapRoot][$metricKey] );
        
            echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr($sev) . '">';
              echo '<p><strong>' . esc_html($device . ' ' . $label . ': ' . $disp) . '</strong>' . ( $sev === 'good' ? ' ✅' : '' ) . '</p>';
        
              if ( is_null($value) ) {
                echo '<p>' . esc_html($label) . ' not available for ' . strtolower(esc_html($device)) . '.</p>';
              } else {
                echo '<p>' . esc_html( $wpsa_conclusion_map[$mapRoot][$metricKey][$sev]['explanation'] ) . '</p>';
                echo '<p><strong>💡 Advice:</strong> ' . esc_html( $wpsa_conclusion_map[$mapRoot][$metricKey][$sev]['advice'] ) . '</p>';
              }
            echo '</div>';
        };
        
          // ── Mobile ───────────────────────────────────────────────
          echo '<h4>Mobile:</h4>';
          $print_m73_card('Mobile', 'requests',  $req_m);
          $print_m73_card('Mobile', 'page_size', $sz_m);
          $print_m73_card('Mobile', 'onload_js', $onjs_m);
          $print_m73_card('Mobile', 'onload_css', $oncss_m);
        
          // ── Desktop ──────────────────────────────────────────────
          echo '<h4>Desktop:</h4>';
          $print_m73_card('Desktop', 'requests',  $req_d);
          $print_m73_card('Desktop', 'page_size', $sz_d);
          $print_m73_card('Desktop', 'onload_js', $onjs_d);
          $print_m73_card('Desktop', 'onload_css', $oncss_d);
        
        echo '</div>'; // end 7.3
        
         // old 7.7 Performance & Diagnostics  (renumbered)
    echo '<div class="wpsa-concl-section">';
      echo '<h3>8.4 Performance & Diagnostics</h3>';
    
    /**
     * Helper: print one metric card.
     * $device = 'Mobile' | 'Desktop'
     * $metric = 'lcp' | 'fcp' | 'cls' | 'inp'
     * $val    = float|int|null
     */
    $print_m77_card = function( string $device, string $metric, $val ) use ( $wpsa_conclusion_map, $classify ) {
        // Display value + unit
        if ( is_null($val) ) {
            $disp = 'N/A';
        } else {
            if ( $metric === 'inp' ) {
                // ms
                $disp = esc_html( $val ) . ' ms';
            } elseif ( $metric === 'cls' ) {
                // unitless
                $disp = esc_html( $val );
            } else {
                // seconds for LCP/FCP
                $disp = esc_html( $val ) . ' s';
            }
        }
    
        // Severity class
        $sev = is_null($val) ? 'neutral' : $classify( $val, $wpsa_conclusion_map['module_5'][ $metric ] );
    
        // Heading label (LCP/FCP/CLS/INP uppercase)
        $label = strtoupper($metric);
    
        echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr($sev) . '">';
          echo '<p><strong>' . esc_html($device . ' ' . $label . ': ' . $disp) . '</strong>' . ( $sev === 'good' ? ' ✅' : '' ) . '</p>';
    
          if ( !is_null($val) ) {
            echo '<p>' . esc_html( $wpsa_conclusion_map['module_5'][ $metric ][ $sev ]['explanation'] ) . '</p>';
            echo '<p><strong>💡 Advice:</strong> ' . esc_html( $wpsa_conclusion_map['module_5'][ $metric ][ $sev ]['advice'] ) . '</p>';
          } else {
            echo '<p>' . esc_html( $label ) . ' data not available for ' . esc_html( strtolower($device) ) . '.</p>';
          }
        echo '</div>';
    };
    
      // ── Mobile ───────────────────────────────────────────────
      echo '<h4>Mobile:</h4>';
      $print_m77_card('Mobile', 'lcp', $lcp_m);
      $print_m77_card('Mobile', 'fcp', $fcp_m);
      $print_m77_card('Mobile', 'cls', $cls_m);
      $print_m77_card('Mobile', 'inp', $inp_m);
    
      // ── Desktop ──────────────────────────────────────────────
      echo '<h4>Desktop:</h4>';
      $print_m77_card('Desktop', 'lcp', $lcp_d);
      $print_m77_card('Desktop', 'fcp', $fcp_d);
      $print_m77_card('Desktop', 'cls', $cls_d);
      $print_m77_card('Desktop', 'inp', $inp_d);
    
    echo '</div>'; //end of 7.7.

    
        // OLD 7.4 Autoloaded options size
        echo '<div class="wpsa-concl-section">';
          echo '<h3>8.5 Autoloaded options size</h3>';
          if ( $is_external ) {
              echo '<div class="wpsa-concl-block wpsa-concl-neutral">';
                echo '<p><strong>N/A</strong></p>';
                echo '<p><strong>💡 Advice:</strong> This metric is only available for internal tests.</p>';
              echo '</div>';
          } else {
              if ( preg_match( '/Module 3: Autoloaded options size:\s*([\d\.]+\s*KB)/i', implode( "\n", $block ), $m ) ) {
                  $autoload_str = sanitize_text_field( $m[1] );
                  $value        = floatval( $m[1] );
                  $is_good      = ( $value <= 800 );
                  echo '<div class="wpsa-concl-block wpsa-concl-' . ( $is_good ? 'good' : 'bad' ) . '">';
                    echo '<p><strong>Database autoload size: ' . esc_html( $autoload_str ) . '</strong>' . ( $is_good ? ' ✅' : '' ) . '</p>';
                    echo '<p><strong>💡 Advice:</strong> ' . ( $is_good
                       ? 'All ok. No action needed.'
                       : 'Keep this under 800 KB for best performance.'
                    ) . '</p>';
                  echo '</div>';
              } else {
                  echo '<div class="wpsa-concl-block wpsa-concl-bad">';
                    echo '<p><strong>N/A</strong></p>';
                    echo '<p><strong>💡 Advice:</strong> Unable to determine autoload size for this URL.</p>';
                  echo '</div>';
              }
          }
        echo '</div>';
    
        // 7.5 Persistent object cache
        echo '<div class="wpsa-concl-section">';
          echo '<h3>8.6 Persistent object cache</h3>';
          if ( $is_external ) {
              echo '<div class="wpsa-concl-block wpsa-concl-neutral"><p><strong>N/A</strong></p><p>This metric is only available for internal tests.</p></div>';
          } else {
              $cls4 = $o4 ? 'good' : 'bad';
              echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $cls4 ) . '">';
                if ( $o4 ) {
                  echo '<p><strong>Yes</strong> ✅</p>';
                  echo '<p>' . esc_html( $wpsa_conclusion_map['module_4']['yes']['explanation'] ) . '</p>';
                  echo '<p><strong>💡 Advice:</strong> ' . esc_html( $wpsa_conclusion_map['module_4']['yes']['advice'] ) . '</p>';
                } else {
                  echo '<p>There is <strong>NO</strong> persistent object cache detected.</p>';
                  echo '<p>' . esc_html( $wpsa_conclusion_map['module_4']['no']['explanation'] ) . '</p>';
                  echo '<p><strong>💡 Advice:</strong> ' . esc_html( $wpsa_conclusion_map['module_4']['no']['advice'] ) . '</p>';
                }
              echo '</div>';
          }
        echo '</div>';
    
        // 7.6 Various other information (Active plugins / PHP / DB)
        echo '<div class="wpsa-concl-section">';
          echo '<h3>8.7 Various other information</h3>';
          if ( $is_external ) {
              echo '<div class="wpsa-concl-block wpsa-concl-neutral">';
                echo '<p><strong>N/A</strong></p>';
                echo '<p><em>*N/A for off-server URLs*</em> — environment checks require an internal test.</p>';
              echo '</div>';
          } else {
              // Active plugins
              $plug_cls = 'neutral';
              if ( 'good' === $plug_sev || 'medium' === $plug_sev || 'bad' === $plug_sev ) {
                  $plug_cls = $plug_sev;
              }
              echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $plug_cls ) . '">';
                echo '<p><strong>Active plugins: ' . esc_html( is_null( $m45_plugins ) ? 'N/A' : (string) $m45_plugins ) . '</strong>' . ( $plug_cls === 'good' ? ' ✅' : '' ) . '</p>';
                if ( 'good' === $plug_cls ) {
                    echo '<p>A reasonable plugin count helps keep request overhead down.</p>';
                    echo '<p><strong>💡 Advice:</strong> No action needed.</p>';
                } elseif ( 'medium' === $plug_cls ) {
                    echo '<p>Moderate plugin count may add overhead.</p>';
                    echo '<p><strong>💡 Advice:</strong> Audit and deactivate unused plugins; consolidate overlapping features.</p>';
                } else { // bad or neutral
                    echo '<p>High plugin count can slow down requests and complicate maintenance.</p>';
                    echo '<p><strong>💡 Advice:</strong> Remove unnecessary plugins and consider must-use (MU) plugins for essentials.</p>';
                }
              echo '</div>';
    
              // PHP version
              $php_cls = ( 'good' === $php_sev || 'bad' === $php_sev ) ? $php_sev : 'neutral';
              echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $php_cls ) . '">';
                echo '<p><strong>PHP version: ' . esc_html( $m45_php ?: 'N/A' ) . '</strong>' . ( $php_cls === 'good' ? ' ✅' : '' ) . '</p>';
                if ( 'good' === $php_cls ) {
                    echo '<p>Your PHP version meets modern performance and security baselines.</p>';
                    echo '<p><strong>💡 Advice:</strong> No action needed.</p>';
                } else {
                    echo '<p>Older PHP versions reduce performance and lose security support.</p>';
                    echo '<p><strong>💡 Advice:</strong> Upgrade to PHP 8.0+ (preferably 8.2/8.3).</p>';
                }
              echo '</div>';
    
            // DB server
              $db_cls   = ( in_array( $db_sev, [ 'good', 'medium', 'bad' ], true ) ) ? $db_sev : 'neutral';
              $db_label = trim( ( $m45_dbv ? $m45_dbv : 'DB' ) . ( $m45_dbver ? ' ' . $m45_dbver : '' ) );

              // Optional DB size suffix (MB or GB) for display.
              $db_size_suffix = '';
              if ( is_numeric( $m45_dbsize_mb ) && $m45_dbsize_mb > 0 ) {
                  if ( $m45_dbsize_mb >= 1024 ) {
                      // Show GB with one decimal.
                      $db_gb = round( $m45_dbsize_mb / 1024, 1 );
                      $db_size_suffix = ' – ' . number_format_i18n( $db_gb, 1 ) . ' GB';
                  } else {
                      // Show MB with one decimal.
                      $db_size_suffix = ' – ' . number_format_i18n( $m45_dbsize_mb, 1 ) . ' MB';
                  }
              }

              echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $db_cls ) . '">';
               echo '<p><strong>DB server/size: ' . esc_html( $db_label ?: 'N/A' ) . '</strong>'
                 . ( $db_cls === 'good' ? ' <span class="icon">✅</span>' : '' )
                 . '<strong>' . esc_html( $db_size_suffix ) . '</strong>'
                 . '</p>';
                if ( 'good' === $db_cls ) {
                    echo '<p>Your database engine/version is modern and performant.</p>';
                    echo '<p><strong>💡 Advice:</strong> No action needed.</p>';

                } elseif ( 'medium' === $db_cls ) {
                    echo '<p>Database version is acceptable but nearing recommended minimums.</p>';
                    echo '<p><strong>💡 Advice:</strong> Plan an upgrade (MariaDB 10.6+ recommended).</p>';
                } else {
                    echo '<p>Outdated database versions can slow queries and miss optimizer improvements.</p>';
                    echo '<p><strong>💡 Advice:</strong> Upgrade the database (MariaDB 10.6+ or MySQL 8.0+).</p>';
                }
              echo '</div>';
          }
        echo '</div>';
    
        // 7.8 Summary & Recommendations  (renumbered)
        echo '<div class="wpsa-concl-section">';
          echo '<h3>8.8 Summary & Recommendations</h3>';
          if ( empty( $recs ) ) {
            if ( ! $is_external && ! $o4 ) {
              echo '<ul><li><strong>💡 Advice: Install and configure Redis or Memcached to speed up database calls.</strong></li></ul>';
            } else {
              echo '<div class="wpsa-concl-block wpsa-concl-good"><p>No outstanding recommendations—your site meets all performance benchmarks.</p></div>';
            }
          } else {
            echo '<ul>';
            foreach ( $recs as $r ) {
              echo '<li><strong>💡 Advice: ' . esc_html( $r ) . '</strong></li>';
            }
            echo '</ul>';
          }
    
          // PDF generation button host
          echo '<div id="report-button-wrap" class="wpsa-pdf-button-wrap" style="text-align:center; margin:30px 0;"></div>';
    
          // Pro-Service CTA
          echo '<div class="wpsa-cta" style="
                background: #234897;
                padding: 12px;
                font-size: 1em;
                line-height: 1.4em;
                color: #efefd0;
                border-radius: 10px;
            ">';
              echo '<h3 style="
                  margin: 0 0 12px;
                  font-size: 1.05em;
                  font-weight: 600;
                  color: #efefd0;
              ">Need an even deeper audit? Or would you like an expert to take care of the speed optimization for you?</h3>';
              echo '<p style="margin: 0 0 16px;">
                  Contact us for a personalized site-speed audit or professional speed optimization:
              </p>';
              echo '<p style="margin: 0;">
                  <a href="https://wpservice.pro/pricing-speed-optimization/"
                     target="_blank" rel="noopener"
                     style="
                       display: inline-block;
                       background: transparent;
                       color: #efefd0;
                       border: 1px solid #efefd0;
                       padding: 5px 10px;
                       text-decoration: none;
                       border-radius: 3px;
                       font-weight: 600;
                     "
                  >View Prices &rarr;</a>
              </p>';
          echo '</div>';
    
    } // end wpsa_module7_conclusion
    
