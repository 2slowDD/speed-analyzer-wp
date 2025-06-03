<?php
/**
 * Speed Analyzer - Module 7: Conclusion
 * Version: v0.731
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
 * Extract the latest matching test block from log lines.
 */
function wpsa_extract_matching_test_block( $lines, $tested_url ) {
    $normalized_tested = wpsa_normalize_url_for_matching( $tested_url );
    $matched_block     = [];
    $in_block          = false;

    for ( $i = 0, $len = count( $lines ); $i < $len; $i++ ) {
        if ( strpos( $lines[ $i ], 'Test #' ) === 0
          && preg_match( '/URL:\s*(https?:\/\/[^\s|]+)/', $lines[ $i ], $m )
        ) {
            if ( wpsa_normalize_url_for_matching( $m[1] ) === $normalized_tested ) {
                $matched_block = [];
                $in_block      = true;
                $matched_block[] = $lines[ $i ];
                for ( $j = $i + 1; $j < $len; $j++ ) {
                    if ( strpos( $lines[ $j ], 'Test #' ) === 0 ) {
                        break;
                    }
                    $matched_block[] = $lines[ $j ];
                }
            }
        }
    }

    return $matched_block;
}

/**
 * Check if Module 7 can be rendered (all required data collected).
 */
function wpsa_module7_is_ready( $tested_url, $results_log ) {
    if ( ! file_exists( $results_log ) ) {
        return false;
    }
    $lines = file( $results_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    $block = wpsa_extract_matching_test_block( $lines, $tested_url );
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
    ],
];
/**
 * Render the entire Conclusion section (Module 7).
 *
 * @param string $tested_url   The URL that was tested.
 * @param string $results_log  Path to the TTFB/results log file.
 */
function wpsa_module7_conclusion( $tested_url, $results_log ) {
    global $wpsa_conclusion_map;

    // Read & normalize the log lines
    $raw_lines = file( $results_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
    $lines     = array_map( function( $l ) {
        return preg_replace( '/\x{00A0}/u', ' ', $l );
    }, $raw_lines );

    // Extract just the block for this URL’s most recent test
    $block = wpsa_extract_matching_test_block( $lines, $tested_url );

    // Find the last “Test # … URL:” line for TTFB & Cache
    $normalized_tested = wpsa_normalize_url_for_matching( $tested_url );
    $last_test         = '';
    foreach ( $lines as $line ) {
        if ( strpos( $line, 'Test #' ) !== false
          && preg_match( '/URL:\s*(https?:\/\/[^\s|]+)/', $line, $m )
          && wpsa_normalize_url_for_matching( $m[1] ) === $normalized_tested
        ) {
            $last_test = $line;
        }
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

    // Module 2: requests & size
    $req_m = $sz_m = $req_d = $sz_d = null;
    foreach ( $block as $l ) {
    if ( strpos( $l, 'Module 2 Mobile:' ) === 0 ) {
        if ( preg_match( '/Mobile:\s*(\d+)\s*req,\s*([\d\.]+)\s*KB/', $l, $m3 ) ) {
            $req_m = intval( $m3[1] );
            $sz_m  = floatval( $m3[2] );
        } else {
            $req_m = null;
            $sz_m  = null;
        }
    }

    if ( strpos( $l, 'Module 2 Desktop:' ) === 0 ) {
        if ( preg_match( '/Desktop:\s*(\d+)\s*req,\s*([\d\.]+)\s*KB/', $l, $m4 ) ) {
            $req_d = intval( $m4[1] );
            $sz_d  = floatval( $m4[2] );
        } else {
            $req_d = null;
            $sz_d  = null;
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

    // Module 5: LCP & FCP
    $lcp_m = $fcp_m = $lcp_d = $fcp_d = null;
    foreach ( $block as $l ) {
        if ( strpos( $l, 'Module 5 Mobile:' ) === 0
          && preg_match( '/LCP:\s*([\d\.]+)\s*s,\s*FCP:\s*([\d\.]+)\s*s/', $l, $p1 )
        ) {
            $lcp_m = floatval( $p1[1] );
            $fcp_m = floatval( $p1[2] );
        }
        if ( strpos( $l, 'Module 5 Desktop:' ) === 0
          && preg_match( '/LCP:\s*([\d\.]+)\s*s,\s*FCP:\s*([\d\.]+)\s*s/', $l, $p2 )
        ) {
            $lcp_d = floatval( $p2[1] );
            $fcp_d = floatval( $p2[2] );
        }
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
    // 7.1 Cache Status
    echo '<div class="wpsa-concl-section">';
      echo '<h3>7.1 Cache Status</h3>';
      echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $cache_class ) . '">';
        echo '<p>Your tested URL cache status was <strong>' . esc_html( $cache ) . '</strong>' . ( in_array( $cache, [ 'HIT', 'Handled by WP Rocket' ], true ) ? ' ✅' : '' ) . '.</p>';
        echo '<p>' . esc_html( $wpsa_conclusion_map['cache_status'][ $cache ]['explanation'] ) . '</p>';
        echo '<p><strong>💡 Advice: ' . esc_html( $wpsa_conclusion_map['cache_status'][ $cache ]['advice'] ) . '</strong></p>';
      echo '</div>';
    echo '</div>';

    // 7.2 Server TTFB
    echo '<div class="wpsa-concl-section">';
      echo '<h3>7.2 Server TTFB</h3>';
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
      echo '<h3>7.3 Page asset summary</h3>';

      // -- Mobile --
      echo '<h4>Mobile — Requests & Page Size:</h4>';
      if ( is_null( $req_m ) || is_null( $sz_m ) ) {
          echo '<div class="wpsa-concl-block wpsa-concl-neutral"><p><strong>N/A</strong></p></div>';
      } else {
          $kr1  = $classify( $req_m, $wpsa_conclusion_map['module_2_mobile']['requests'] );
          $ks1  = $classify( $sz_m,  $wpsa_conclusion_map['module_2_mobile']['page_size'] );
          $lvl1 = max( ['good'=>0,'medium'=>1,'bad'=>2][$kr1], ['good'=>0,'medium'=>1,'bad'=>2][$ks1] );
          $blk1 = $lvl1 === 2 ? 'bad' : ( $lvl1 === 1 ? 'medium' : 'good' );
          echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $blk1 ) . '">';
            echo '<p><strong>' . esc_html( "{$req_m} Requests, and a page size of {$sz_m} KB" ) . '</strong>' . ( $blk1 === 'good' ? ' ✅' : '' ) . '</p>';
            echo '<p>' . esc_html( $wpsa_conclusion_map['module_2_mobile']['requests'][ $kr1 ]['explanation'] ) . '</p>';
            echo '<p><strong>💡 Advice: ' . esc_html( $wpsa_conclusion_map['module_2_mobile']['requests'][ $kr1 ]['advice'] ) . '</strong></p>';
            echo '<p>' . esc_html( $wpsa_conclusion_map['module_2_mobile']['page_size'][ $ks1 ]['explanation'] ) . '</p>';
            echo '<p><strong>💡 Advice: ' . esc_html( $wpsa_conclusion_map['module_2_mobile']['page_size'][ $ks1 ]['advice'] ) . '</strong></p>';
          echo '</div>';
      }

      // -- Desktop --
      echo '<h4>Desktop — Requests & Page Size:</h4>';
      if ( is_null( $req_d ) || is_null( $sz_d ) ) {
          echo '<div class="wpsa-concl-block wpsa-concl-neutral"><p><strong>N/A</strong></p></div>';
      } else {
          $kr2  = $classify( $req_d, $wpsa_conclusion_map['module_2_desktop']['requests'] );
          $ks2  = $classify( $sz_d,  $wpsa_conclusion_map['module_2_desktop']['page_size'] );
          $lvl2 = max( ['good'=>0,'medium'=>1,'bad'=>2][$kr2], ['good'=>0,'medium'=>1,'bad'=>2][$ks2] );
          $blk2 = $lvl2 === 2 ? 'bad' : ( $lvl2 === 1 ? 'medium' : 'good' );
          echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $blk2 ) . '">';
            echo '<p><strong>' . esc_html( "{$req_d} Requests, and a page size of {$sz_d} KB" ) . '</strong>' . ( $blk2 === 'good' ? ' ✅' : '' ) . '</p>';
            echo '<p>' . esc_html( $wpsa_conclusion_map['module_2_desktop']['requests'][ $kr2 ]['explanation'] ) . '</p>';
            echo '<p><strong>💡 Advice: ' . esc_html( $wpsa_conclusion_map['module_2_desktop']['requests'][ $kr2 ]['advice'] ) . '</strong></p>';
            echo '<p>' . esc_html( $wpsa_conclusion_map['module_2_desktop']['page_size'][ $ks2 ]['explanation'] ) . '</p>';
            echo '<p><strong>💡 Advice: ' . esc_html( $wpsa_conclusion_map['module_2_desktop']['page_size'][ $ks2 ]['advice'] ) . '</strong></p>';
          echo '</div>';
      }
    echo '</div>';

    // 7.4 Autoloaded options size
    echo '<div class="wpsa-concl-section">';
      echo '<h3>7.4 Autoloaded options size</h3>';
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
      echo '<h3>7.5 Persistent object cache</h3>';
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

    // 7.6 Performance & Diagnostics
    echo '<div class="wpsa-concl-section">';
      echo '<h3>7.6 Performance & Diagnostics</h3>';

      // Mobile
      echo '<h4>Mobile:</h4>';
      if ( is_null( $lcp_m ) || is_null( $fcp_m ) ) {
          $blk_m    = 'neutral';
          $disp_lcp = 'N/A';
          $disp_fcp = 'N/A';
      } else {
          $klm = $classify( $lcp_m, $wpsa_conclusion_map['module_5']['lcp'] );
          $kfm = $classify( $fcp_m, $wpsa_conclusion_map['module_5']['fcp'] );
          $lvl = max( ['good'=>0,'medium'=>1,'bad'=>2][$klm], ['good'=>0,'medium'=>1,'bad'=>2][$kfm] );
          $blk_m = $lvl === 2 ? 'bad' : ( $lvl === 1 ? 'medium' : 'good' );
          $disp_lcp = esc_html( $lcp_m . ' s' );
          $disp_fcp = esc_html( $fcp_m . ' s' );
      }
      echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $blk_m ) . '">';
        echo '<p><strong>Mobile LCP: ' . esc_html( $disp_lcp ) . '</strong>' . ( $blk_m === 'good' ? ' ✅' : '' ) . '</p>';
        if ( ! is_null( $lcp_m ) ) {
          echo '<p>' . esc_html( $wpsa_conclusion_map['module_5']['lcp'][ $klm ]['explanation'] ) . '</p>';
          echo '<p><strong>💡 Advice:</strong> ' . esc_html( $wpsa_conclusion_map['module_5']['lcp'][ $klm ]['advice'] ) . '</p>';
        }
        echo '<p><strong>Mobile FCP: ' . esc_html( $disp_fcp ) . '</strong>' . ( $blk_m === 'good' ? ' ✅' : '' ) . '</p>';
        if ( ! is_null( $fcp_m ) ) {
          echo '<p>' . esc_html( $wpsa_conclusion_map['module_5']['fcp'][ $kfm ]['explanation'] ) . '</p>';
          echo '<p><strong>💡 Advice:</strong> ' . esc_html( $wpsa_conclusion_map['module_5']['fcp'][ $kfm ]['advice'] ) . '</p>';
        }
      echo '</div>';

      // Desktop
      echo '<h4>Desktop:</h4>';
      if ( is_null( $lcp_d ) || is_null( $fcp_d ) ) {
          $blk_d    = 'neutral';
          $disp_lcp = 'N/A';
          $disp_fcp = 'N/A';
      } else {
          $kld = $classify( $lcp_d, $wpsa_conclusion_map['module_5']['lcp'] );
          $kfd = $classify( $fcp_d, $wpsa_conclusion_map['module_5']['fcp'] );
          $lvl = max( ['good'=>0,'medium'=>1,'bad'=>2][$kld], ['good'=>0,'medium'=>1,'bad'=>2][$kfd] );
          $blk_d = $lvl === 2 ? 'bad' : ( $lvl === 1 ? 'medium' : 'good' );
          $disp_lcp = esc_html( $lcp_d . ' s' );
          $disp_fcp = esc_html( $fcp_d . ' s' );
      }
      echo '<div class="wpsa-concl-block wpsa-concl-' . esc_attr( $blk_d ) . '">';
        echo '<p><strong>Desktop LCP: ' . esc_html( $disp_lcp ) . '</strong>' . ( $blk_d === 'good' ? ' ✅' : '' ) . '</p>';
        if ( ! is_null( $lcp_d ) ) {
          echo '<p>' . esc_html( $wpsa_conclusion_map['module_5']['lcp'][ $kld ]['explanation'] ) . '</p>';
          echo '<p><strong>💡 Advice:</strong> ' . esc_html( $wpsa_conclusion_map['module_5']['lcp'][ $kld ]['advice'] ) . '</p>';
        }
        echo '<p><strong>Desktop FCP: ' . esc_html( $disp_fcp ) . '</strong>' . ( $blk_d === 'good' ? ' ✅' : '' ) . '</p>';
        if ( ! is_null( $fcp_d ) ) {
          echo '<p>' . esc_html( $wpsa_conclusion_map['module_5']['fcp'][ $kfd ]['explanation'] ) . '</p>';
          echo '<p><strong>💡 Advice:</strong> ' . esc_html( $wpsa_conclusion_map['module_5']['fcp'][ $kfd ]['advice'] ) . '</p>';
        }
      echo '</div>';
    echo '</div>';

    // 7.7 Summary & Recommendations
    echo '<div class="wpsa-concl-section">';
      echo '<h3>7.7 Summary & Recommendations</h3>';
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

      // Pro-Service CTA
      echo '<div class="wpsa-cta" style="margin-top:30px;padding:20px;border:1px solid #ccc;">';
        echo '<h2>Need an even deeper audit? Or would you like an expert to take care of the speed optimization for you?</h2>';
        echo '<p>Contact us for a personalized site-speed audit or professional speed optimization:</p>';
        echo '<p><a href="https://wpservice.pro/our-products/speed-analyzer-wp-plugin/#licenses" target="_blank" class="button button-primary">View Prices &rarr;</a></p>';
      echo '</div>';
    echo '</div>';

} // end wpsa_module7_conclusion
