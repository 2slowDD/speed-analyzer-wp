<?php
/**
 * Plugin Name:     Speed Analyzer WP
 * Plugin URI:      https://wpservice.pro/our-products/speed-analyzer-wp-plugin/
 * Description:     Detect your website's speed, bottlenecks, and key performance indicators to look for.
 * Version:         1.0
 * Author:          Dalibor Druzinec / WPservice
 * Author URI:      https://wpservice.pro
 * License:         GPL v3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.txt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WPSA_LICENSE_TIER' ) ) {
    define( 'WPSA_LICENSE_TIER', '' );
}

/**
 * Get the current license tier (filterable).
 */
function wpsa_get_license_tier() {
    return apply_filters( 'wpsa_license_tier', WPSA_LICENSE_TIER );
}
//don't bother - Obfuscated key - not the real deal
define( 'SAWP_PSI_API_KEY', 'AIzaSyAs6Mxrt3MhsrlOj7S2p4I1XcIbxjqEI6w' );
// Version constant renamed
define( 'SAWP_VERSION', '1.0' );

// Maintain backward compatibility if needed
if ( ! defined( 'WPSA_VERSION' ) ) {
    define( 'WPSA_VERSION', SAWP_VERSION );
}

// Load core pieces
require_once plugin_dir_path( __FILE__ ) . 'helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'modules.php';
require_once plugin_dir_path( __FILE__ ) . 'diagnostics.php';
require_once plugin_dir_path( __FILE__ ) . 'summary.php';
require_once plugin_dir_path( __FILE__ ) . 'conclusion.php';

// Add “Go to tool” link on the Plugins page
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wpsa_add_action_links' );
function wpsa_add_action_links( $actions ) {
    $tool_url = admin_url( 'tools.php?page=speed-analyzer-wp' );
    return array_merge(
        [ 'wpsa_tool' => '<a href="' . esc_url( $tool_url ) . '">Go to tool</a>' ],
        $actions
    );
}

// Module 7 AJAX
add_action( 'wp_ajax_wpsa_module7', 'wpsa_ajax_module7' );
function wpsa_ajax_module7() {
    check_ajax_referer( 'wpsa_module7_nonce', 'nonce' );

    // Retrieve & sanitize test_url
    $raw_url    = filter_input( INPUT_POST, 'test_url', FILTER_UNSAFE_RAW );
    $unslashed  = wp_unslash( $raw_url );
    $test_url   = esc_url_raw( sanitize_text_field( $unslashed ) );

    if ( empty( $test_url ) || ! wp_http_validate_url( $test_url ) ) {
        wp_send_json_error( 'Invalid or missing test URL.' );
    }

    $results_log = plugin_dir_path( __FILE__ ) . 'ttfb-results-log.txt';
    if ( ! wpsa_module7_is_ready( $test_url, $results_log ) ) {
        wp_send_json_error( 'Module 7 data not ready' );
    }

    ob_start();
    wpsa_module7_conclusion( $test_url, $results_log );
    $html = ob_get_clean();

    // Idempotent increment
    $contents     = @file_get_contents( $results_log );
    $current_idx  = substr_count( $contents, 'Test #' );
    $option_key   = 'wpsa_last_counted_idx';
    $last_counted = intval( get_option( $option_key, 0 ) );
    if ( $current_idx > $last_counted ) {
        wpsa_increment_daily_usage();
        update_option( $option_key, $current_idx );
    }

    wp_send_json_success( [
        'html'      => $html,
        'remaining' => wpsa_get_daily_remaining(),
    ] );
}

// Register admin menu
add_action( 'admin_menu', function() {
    add_management_page(
        'Speed Analyzer WP ' . SAWP_VERSION,
        'Speed Analyzer WP',
        'manage_options',
        'speed-analyzer-wp',
        'wpsa_render_tool_page'
    );
} );

// Enqueue scripts/styles
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( 'tools_page_speed-analyzer-wp' !== $hook ) {
        return;
    }
    $css = plugin_dir_path( __FILE__ ) . 'admin-styles.css';
    $js  = plugin_dir_path( __FILE__ ) . 'admin-scripts.js';

    wp_enqueue_style(
        'wpsa-admin-styles',
        plugin_dir_url( __FILE__ ) . 'admin-styles.css',
        [],
        file_exists( $css ) ? filemtime( $css ) : SAWP_VERSION
    );
    wp_enqueue_script(
        'wpsa-admin-scripts',
        plugin_dir_url( __FILE__ ) . 'admin-scripts.js',
        [ 'jquery' ],
        file_exists( $js ) ? filemtime( $js ) : SAWP_VERSION,
        true
    );
    wp_localize_script( 'wpsa-admin-scripts', 'wpsaData', [
        'psiNonce'     => wp_create_nonce( 'wpsa_psi_nonce' ),
        'perfNonce'    => wp_create_nonce( 'wpsa_perf_nonce' ),
        'module7Nonce' => wp_create_nonce( 'wpsa_module7_nonce' ),
        'dailyLimit'   => wpsa_get_daily_limit(),
    ] );
} );

/**
 * Renders the tool page and handles the full-page POST.
 */
function wpsa_render_tool_page() {
    global $wpdb;

    // Default URL to home_url()
    $tested_url = home_url();

    // Retrieve & sanitize form inputs
    $raw_run       = filter_input( INPUT_POST, 'run_test',      FILTER_UNSAFE_RAW );
    $run_test      = sanitize_text_field( wp_unslash( $raw_run ) );

    $raw_nonce     = filter_input( INPUT_POST, 'wpsa_speed_test', FILTER_UNSAFE_RAW );
    $nonce         = sanitize_text_field( wp_unslash( $raw_nonce ) );

    $raw_url       = filter_input( INPUT_POST, 'test_url',      FILTER_UNSAFE_RAW );
    $unslashed_url = wp_unslash( $raw_url );
    $submitted_url = esc_url_raw( sanitize_text_field( $unslashed_url ) );

    // If form submitted and nonce & URL valid, override default URL
    if (
        '1' === $run_test
        && wp_verify_nonce( $nonce, 'wpsa_speed_test' )
        && $submitted_url
        && wp_http_validate_url( $submitted_url )
    ) {
        $tested_url = $submitted_url;
    }

    $log_path    = plugin_dir_path( __FILE__ ) . 'ttfb-api-debug.log';
    $results_log = plugin_dir_path( __FILE__ ) . 'ttfb-results-log.txt';

    // Determine lock state
    $is_unlocked  = in_array( wpsa_get_license_tier(), [ 'premium2', 'premium3' ], true );
    $lock_tooltip = $is_unlocked
                    ? 'Unlocked: you may test any URL'
                    : 'Locked: only same-host URLs allowed';
    $icon         = $is_unlocked ? '🔓' : '🔒';
    ?>
    <div class="wrap">
      <h1 class="wpsa-header">
        <img class="wpsa-logo" src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'SAWP-logo.svg' ); ?>" alt="Speed Analyzer WP Logo">
        <div class="wpsa-header-info">
          <span class="wpsa-name">Speed Analyzer WP</span>
          <small class="wpsa-version"><?php echo esc_html( SAWP_VERSION ); ?></small>
          <p class="wpsa-header-subtitle">A first step towards a faster website</p>
          <p id="wpsa-header-credit">
            Developed by Dalibor Druzinec / <a href="https://wpservice.pro/" target="_blank">WPservice.pro</a>
          </p>
        </div>
      </h1>

      <form
        id="speed-test-form"
        method="POST"
        action="<?php echo esc_url( admin_url( 'tools.php?page=speed-analyzer-wp' ) ); ?>"
        class="wpsa-form"
      >
        <?php wp_nonce_field( 'wpsa_speed_test', 'wpsa_speed_test' ); ?>
        <input type="hidden" name="page"     value="speed-analyzer-wp">
        <input type="hidden" name="run_test" value="1">

        <div class="wpsa-url-field">
          <input
            type="text"
            name="test_url"
            class="wpsa-input-url"
            value="<?php echo esc_attr( $tested_url ); ?>"
            placeholder="Enter URL to test…"
          >
          <span
            class="wpsa-url-lock wpsa-lock-tooltip"
            data-tooltip="<?php echo esc_attr( $lock_tooltip ); ?>"
          ><?php echo esc_html( $icon ); ?></span>
        </div>

        <button type="submit" class="button button-primary wpsa-button-run">
          Run Speed Audit
        </button>

        <p id="wpsa-daily-remaining" class="wpsa-url-display" style="flex-basis:100%; margin-top:0.5em; text-align:right;">
          Daily limit remaining:
          <?php
            $remaining = wpsa_get_daily_remaining();
            $limit     = wpsa_get_daily_limit();
          ?>
          <strong<?php if ( $remaining <= 3 ) echo ' style="color:#d32f2f;"'; ?>>
            <?php echo esc_html( "{$remaining}/{$limit}" ); ?>
          </strong>
          <span class="custom-tooltip" data-tooltip="Your daily fair-use limit. Upgrades available.">?</span>
        </p>
      </form>

      <?php if ( wpsa_get_daily_remaining() === 0 ) : ?>
        <div class="notice notice-warning wpsa-notice-limit">
          <p><strong>Fair usage limit.</strong> You’ve used all <?php echo esc_html( wpsa_get_daily_limit() ); ?> tests for today. Please wait until tomorrow or upgrade your license.</p>
        </div>
      <?php else : ?>

        <div id="running-test" class="wpsa-module-running" style="display:none;">Running test…</div>

        <?php if ( '1' === $run_test ) : ?>

          <p id="wpsa-tested-url" class="wpsa-url-display"></p>
          <div id="test-results">

            <?php
            // Module 1: TTFB
            if ( ! wpsa_module1_ttfb( $tested_url, $log_path, $results_log ) ) {
                return;
            }
            // Modules 2–6
            wpsa_module2_assets( $tested_url, $results_log );
            wpsa_module3_4_autoload_cache( $wpdb, $results_log );
            wpsa_module5_performance_diagnostics( $tested_url, $results_log );
            wpsa_module6_summary( $tested_url, $results_log );

            // Increment usage once per full test
            wpsa_increment_daily_usage();

            // Module 7 placeholder
            echo '<div id="module7-wrapper" class="wpsa-module-7">';
              echo '<h2 class="wpsa-module-title">7. Conclusion</h2>';
              echo '<div id="module7-running" class="wpsa-module-running">Loading conclusion…</div>';
              echo '<div id="module7-container" data-rendered="false"></div>';
            echo '</div>';
            ?>

          </div><!-- end #test-results -->

        <?php endif; ?>

      <?php endif; ?>

    </div><!-- end .wrap -->
    <?php
}
