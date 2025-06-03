<?php
/**
 * Plugin Name:     Speed Analyzer
 * Plugin URI:      https://wpservice.pro/our-products/speed-analyzer-wp-plugin/
 * Description:     Detect your website's speed, bottlenecks, and key performance indicators to look for.
 * Version:         1.08
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

// Bump version
define( 'SAWP_VERSION', '1.08' );
if ( ! defined( 'WPSA_VERSION' ) ) {
    define( 'WPSA_VERSION', SAWP_VERSION );
}

// Include modules
require_once plugin_dir_path( __FILE__ ) . 'helpers.php';
require_once plugin_dir_path( __FILE__ ) . 'modules.php';
require_once plugin_dir_path( __FILE__ ) . 'diagnostics.php';
require_once plugin_dir_path( __FILE__ ) . 'summary.php';
require_once plugin_dir_path( __FILE__ ) . 'conclusion.php';

// “Go to tool” link
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wpsa_add_action_links' );
function wpsa_add_action_links( $actions ) {
    $tool_url = admin_url( 'tools.php?page=speed-analyzer' );
    return array_merge(
        [ 'wpsa_tool' => '<a href="' . esc_url( $tool_url ) . '">Go to tool</a>' ],
        $actions
    );
}

// Module 7 AJAX: performance conclusion
add_action( 'wp_ajax_wpsa_module7', 'wpsa_ajax_module7' );
function wpsa_ajax_module7() {
    check_ajax_referer( 'wpsa_module7_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $test_url = isset( $_POST['test_url'] )
        ? esc_url_raw( wp_unslash( $_POST['test_url'] ) )
        : '';
    if ( ! $test_url || ! wp_http_validate_url( $test_url ) ) {
        wp_send_json_error( 'Invalid or missing test URL.' );
    }

    // Point at uploads/speed-analyzer/ttfb-results-log.txt instead of plugin folder.
    $upload_dir  = wp_upload_dir();
    $results_log = trailingslashit( $upload_dir['basedir'] ) . 'speed-analyzer/ttfb-results-log.txt';

    if ( ! wpsa_module7_is_ready( $test_url, $results_log ) ) {
        wp_send_json_error( 'Module 7 data not ready' );
    }

    ob_start();
    wpsa_module7_conclusion( $test_url, $results_log );
    $html = ob_get_clean();

    wp_send_json_success( [
        'html'      => $html,
        'remaining' => wpsa_get_daily_remaining(),
    ] );
    
}

// Admin menu
add_action( 'admin_menu', function() {
    add_management_page(
        'Speed Analyzer' . SAWP_VERSION,
        'Speed Analyzer',
        'manage_options',
        'speed-analyzer',
        'wpsa_render_tool_page'
    );
} );

// Enqueue assets
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( 'tools_page_speed-analyzer' !== $hook ) {
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
 * Render the Speed Analyzer tool page.
 */
function wpsa_render_tool_page() {
    global $wpdb;

    // Determine uploads directory and ensure our subfolder exists
    $upload_dir = wp_upload_dir();
    $base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'speed-analyzer';

    if ( ! file_exists( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
    }

    // Use uploads/speed-analyzer/ for logs instead of plugin folder
    $log_path    = trailingslashit( $base_dir ) . 'ttfb-api-debug.log';
    $results_log = trailingslashit( $base_dir ) . 'ttfb-results-log.txt';

    // Default test URL
    $tested_url = home_url();

    // 1) Sanitize POST inputs if submitted
    $run_test     = '';
    $invalid_url  = false; // new flag
    $submitted    = '';

    if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'wpsa_speed_test', 'wpsa_speed_test' );

        $run_test  = sanitize_text_field( wp_unslash( $_POST['run_test'] ?? '' ) );
        $submitted = esc_url_raw( wp_unslash( $_POST['test_url'] ?? '' ) );

        if ( '1' === $run_test ) {
            // If they clicked “Run Speed Audit” but DID NOT give a valid URL,
            // show an error notice (no return).
            if ( ! $submitted || ! filter_var( $submitted, FILTER_VALIDATE_URL ) ) {
                echo '<div class="notice notice-error"><p><strong>Error:</strong> Please enter a valid URL to test.</p></div>';
                $invalid_url = true;
            } else {
                // Valid URL—override default
                $tested_url = $submitted;
            }
        }
    }

    $is_unlocked  = in_array( wpsa_get_license_tier(), [ 'premium2', 'premium3' ], true );
    $lock_tooltip = $is_unlocked
        ? 'Unlocked: you may test any URL'
        : 'Locked: only same-host URLs allowed';
    $icon = $is_unlocked ? '🔓' : '🔒';
    ?>
    <div class="wrap">
      <h1 class="wpsa-header">
        <img class="wpsa-logo" src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'SAWP-logo.svg' ); ?>" alt="Speed Analyzer Logo">
        <div class="wpsa-header-info">
          <span class="wpsa-name">Speed Analyzer</span>
          <small class="wpsa-version"><?php echo esc_html( SAWP_VERSION ); ?></small>
          <p class="wpsa-header-subtitle">A first step towards a faster website</p>
          <p id="wpsa-header-credit">
            Developed by Dalibor Druzinec / <a href="https://wpservice.pro/" target="_blank">WPservice.pro</a>
          </p>
        </div>
      </h1>

      <form id="speed-test-form" method="POST" action="<?php echo esc_url( admin_url( 'tools.php?page=speed-analyzer' ) ); ?>" class="wpsa-form">
        <?php wp_nonce_field( 'wpsa_speed_test', 'wpsa_speed_test' ); ?>
        <input type="hidden" name="page" value="speed-analyzer">
        <input type="hidden" name="run_test" value="1">

        <div class="wpsa-url-field">
          <input
            type="text"
            name="test_url"
            class="wpsa-input-url"
            value="<?php echo esc_attr( $tested_url ); ?>"
            placeholder="Enter URL to test…"
          >
          <span class="wpsa-url-lock wpsa-lock-tooltip" data-tooltip="<?php echo esc_attr( $lock_tooltip ); ?>">
            <?php echo esc_html( $icon ); ?>
          </span>
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

          <?php if ( ! $invalid_url ) : ?>
            <div id="test-results">

              <?php
              if ( ! wpsa_module1_ttfb( $tested_url, $log_path, $results_log ) ) {
         return;
        }

       wpsa_increment_daily_usage();
      wpsa_module2_assets( $tested_url, $results_log );
     wpsa_module3_4_autoload_cache( $wpdb, $results_log );
     wpsa_module5_performance_diagnostics( $tested_url, $results_log );
    wpsa_module6_summary( $tested_url, $results_log );
    ?>

              <div id="module7-wrapper" class="wpsa-module-7">
                <h2 class="wpsa-module-title">7. Conclusion</h2>
                <div id="module7-running" class="wpsa-module-running">Loading conclusion…</div>
                <div id="module7-container" data-rendered="false"></div>
              </div>

            </div><!-- end #test-results -->
          <?php endif; // end if not $invalid_url ?>

        <?php endif; // end if run_test ?>

      <?php endif; // end if daily remaining ?>

    </div><!-- end .wrap -->
    <?php
}
