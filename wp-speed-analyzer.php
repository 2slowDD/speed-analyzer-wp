<?php
/**
 * Plugin Name:     Speed Analyzer
 * Plugin URI:      https://wpservice.pro/our-products/speed-analyzer-wp-plugin/
 * Description:     Detect your website's speed, bottlenecks, and key performance indicators to look for.
 * Version:         1.19.0
 * Author:          Dalibor Druzinec / WPservice
 * Author URI:      https://wpservice.pro
 * License:         GPL v3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-3.0.txt
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WPSA_PLUGIN_FILE' ) ) {
    define( 'WPSA_PLUGIN_FILE', __FILE__ );
}

/*
 * Plugin-root URL and directory, anchored to the MAIN FILE rather than to whichever
 * file happens to be asking.
 *
 * The `__FILE__` forms of WordPress's plugin_dir_url()/plugin_dir_path() resolve
 * relative to the CALLING file. While every source file sits at the plugin root the
 * two forms are interchangeable, so this change is byte-identical today. They stop
 * being interchangeable the moment a file moves into a subdirectory: a caller in
 * includes/ would resolve to <plugin>/includes/, and every asset URL built from it
 * would 404 - which is exactly how the bundled Chart.js and the trends chart break.
 *
 * Anchoring to WPSA_PLUGIN_FILE keeps both values pointing at the plugin root no
 * matter where the caller lives. Must be defined before the require block below,
 * which uses WPSA_PLUGIN_DIR.
 */
if ( ! defined( 'WPSA_PLUGIN_DIR' ) ) {
    define( 'WPSA_PLUGIN_DIR', plugin_dir_path( WPSA_PLUGIN_FILE ) );
}
if ( ! defined( 'WPSA_PLUGIN_URL' ) ) {
    define( 'WPSA_PLUGIN_URL', plugin_dir_url( WPSA_PLUGIN_FILE ) );
}

define( 'SAWP_VERSION', '1.19.0' );
if ( ! defined( 'WPSA_VERSION' ) ) {
    define( 'WPSA_VERSION', SAWP_VERSION );
}


// Gatekeeper base URL 
if ( ! defined( 'WPSA_GATEKEEPER_URL' ) ) {
    define( 'WPSA_GATEKEEPER_URL', 'https://gatekeepersa.dalibord79.workers.dev' );
}

// Load core modules
require_once WPSA_PLUGIN_DIR . 'helpers.php';
require_once WPSA_PLUGIN_DIR . 'modules.php';
require_once WPSA_PLUGIN_DIR . 'diagnostics.php';
require_once WPSA_PLUGIN_DIR . 'summary.php';
require_once WPSA_PLUGIN_DIR . 'conclusion.php';
require_once WPSA_PLUGIN_DIR . 'report.php';
require_once WPSA_PLUGIN_DIR . 'lpanel.php';
require_once WPSA_PLUGIN_DIR . 'schedule.php';
require_once WPSA_PLUGIN_DIR . 'compare.php';
require_once WPSA_PLUGIN_DIR . 'editors.php';

/**
 * --- AJAX endpoint to render PDF markup on demand ---
 */
add_action( 'wp_ajax_wpsa_pdf_report', 'wpsa_ajax_pdf_report' );
function wpsa_ajax_pdf_report() {
    check_ajax_referer( 'wpsa_pdf_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    // Gatekeeper check
    $quota = wpsa_check_quota( 'pdf' );
    if ( is_wp_error( $quota ) ) {
        wp_send_json_error( 'Quota check failed. Upgrade tier or wait until tomorrow.' );
    }
    if ( empty( $quota['allowed'] ) ) {
        wp_send_json_error( 'Daily PDF limit reached. Upgrade tier or wait until tomorrow.' );
    }

    $raw_test_url = wp_unslash( filter_input( INPUT_POST, 'test_url', FILTER_UNSAFE_RAW ) ?? '' );
    $tested_url   = esc_url_raw( $raw_test_url );

    $upload    = wp_upload_dir();
    $base_dir  = trailingslashit( $upload['basedir'] ) . 'speed-analyzer';
    if ( ! file_exists( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
    }

    $debug_log       = trailingslashit( $base_dir ) . 'ttfb-api-debug.log';
    $results_log_pdf = trailingslashit( $base_dir ) . 'ttfb-results-log-pdf.txt';

    if ( ! defined( 'WPSA_CONTEXT' ) ) {
        define( 'WPSA_CONTEXT', 'pdf' );
    }

    $test_no = isset( $_POST['test_no'] )
        ? absint( wp_unslash( $_POST['test_no'] ) )
        : 0;

    ob_start();
    wpsa_pdf_report_content( $tested_url, $debug_log, $results_log_pdf, $test_no );
    $html = ob_get_clean();

    // Count PDF usage only after server-side report markup was built successfully.
    wpsa_increment_pdf_usage();

    $q2 = wpsa_check_quota( 'pdf' );
    if ( is_wp_error( $q2 ) || ! is_array( $q2 ) ) {
        $q2 = [
            'remaining' => (int) wpsa_get_pdf_remaining(),
            'limit'     => (int) wpsa_get_pdf_limit(),
        ];
    }

    wp_send_json_success( [
        'html'      => $html,
        'remaining' => (int) ( $q2['remaining'] ?? 0 ),
        'limit'     => (int) ( $q2['limit'] ?? 0 ),
    ] );
}

/**
 * --- AJAX endpoint: return current PDF quota (reads Gatekeeper via wpsa_check_quota('pdf')) ---
 */
add_action( 'wp_ajax_wpsa_pdf_quota', 'wpsa_ajax_pdf_quota' );
function wpsa_ajax_pdf_quota() {
    check_ajax_referer( 'wpsa_pdf_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $q = wpsa_check_quota( 'pdf' );
    if ( is_wp_error( $q ) ) {
        wp_send_json_error( 'Quota check failed.' );
    }

    wp_send_json_success( [
        'remaining' => (int) ( $q['remaining'] ?? 0 ),
        'limit'     => (int) ( $q['limit'] ?? 0 ),
    ] );
}

  
    // AJAX: Conclusion fetch (Module 7)
    add_action( 'wp_ajax_wpsa_module7', function() {
        check_ajax_referer( 'wpsa_module7_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions.' );
        }
    
        $test_url = isset( $_POST['test_url'] )
            ? esc_url_raw( wp_unslash( $_POST['test_url'] ) )
            : '';
    
        // NEW: accept an explicit test number when the user loaded “Test #N”
        $test_no = isset( $_POST['test_no'] )
            ? absint( wp_unslash( $_POST['test_no'] ) )
            : 0;
    
        if ( ! $test_url || ! wp_http_validate_url( $test_url ) ) {
            wp_send_json_error( 'Invalid or missing test URL.' );
        }
    
        $upload_dir  = wp_upload_dir();
        $results_log = trailingslashit( $upload_dir['basedir'] ) . 'speed-analyzer/ttfb-results-log.txt';
    
        // Pass $test_no through so Module 7 uses that exact block
        if ( ! wpsa_module7_is_ready( $test_url, $results_log, $test_no ) ) {
            wp_send_json_error( 'Module 7 data not ready' );
        }
    
        ob_start();
        wpsa_module7_conclusion( $test_url, $results_log, $test_no );
        $html = ob_get_clean();

        // Return quota from the same source as the rest of the UI (Gatekeeper),
        // with a safe local fallback.
        $quota = wpsa_check_quota( 'ttfb' );
        if ( is_wp_error( $quota ) || ! is_array( $quota ) ) {
            $quota = wpsa_get_local_quota_snapshot( 'ttfb' );
        }

        wp_send_json_success( [
            'html'      => $html,
            'remaining' => (int) ( $quota['remaining'] ?? wpsa_get_daily_remaining() ),
            'limit'     => (int) ( $quota['limit'] ?? wpsa_get_daily_limit() ),
            'tier'      => (string) ( $quota['tier'] ?? wpsa_get_license_tier() ),
        ] );
    } );


    
    // Admin page
    add_action( 'admin_menu', function() {
        add_management_page(
            'Speed Analyzer ' . SAWP_VERSION,
            'Speed Analyzer',
            'manage_options',
            'speed-analyzer',
            'wpsa_render_tool_page'
        );
    } );
    
    // Admin assets
    add_action( 'admin_enqueue_scripts', function( $hook ) {
        if ( 'tools_page_speed-analyzer' !== $hook ) {
            return;
        }
    
       // Core CSS & JS
        $css = WPSA_PLUGIN_DIR . 'admin-styles.css';
        $js  = WPSA_PLUGIN_DIR . 'admin-scripts.js';
        
        wp_enqueue_style(
            'wpsa-admin-styles',
            WPSA_PLUGIN_URL . 'admin-styles.css',
            [],
            file_exists( $css ) ? filemtime( $css ) : SAWP_VERSION
        );
        
        wp_enqueue_script(
            'wpsa-admin-scripts',
            WPSA_PLUGIN_URL . 'admin-scripts.js',
            [ 'jquery' ],
            file_exists( $js ) ? filemtime( $js ) : SAWP_VERSION,
            true
        );
        
        // WP-native modal styling + script (jQuery UI Dialog) for review prompt popup
        wp_enqueue_style( 'wp-jquery-ui-dialog' );
        wp_enqueue_script( 'jquery-ui-dialog' );

        // Widgets / small UI helpers
        $widgets_js = WPSA_PLUGIN_DIR . 'admin-widgets.js';
        wp_enqueue_script(
            'wpsa-admin-widgets',
            WPSA_PLUGIN_URL . 'admin-widgets.js',
            [ 'jquery', 'jquery-ui-dialog' ],
            file_exists( $widgets_js ) ? filemtime( $widgets_js ) : SAWP_VERSION,
            true
        );
        
        // CWV small UI helper (Module 5)
        $cwv_ui_js = WPSA_PLUGIN_DIR . 'cwv-ui.js';
        wp_enqueue_script(
            'wpsa-cwv-ui',
            WPSA_PLUGIN_URL . 'cwv-ui.js',
            [ 'jquery' ],
            file_exists( $cwv_ui_js ) ? filemtime( $cwv_ui_js ) : SAWP_VERSION,
            true
        );
        
          // Schedule tab scripts (dynamic URL rows, polling UI helpers)
        $schedule_js = WPSA_PLUGIN_DIR . 'schedule-scripts.js';
        wp_enqueue_script(
            'wpsa-schedule-scripts',
            WPSA_PLUGIN_URL . 'schedule-scripts.js',
            [ 'jquery' ],
            file_exists( $schedule_js ) ? filemtime( $schedule_js ) : SAWP_VERSION,
            true
        );

    
            // in admin_enqueue_scripts:
            $quota = wpsa_check_quota( 'ttfb' );

            if ( is_wp_error( $quota ) ) {
                $quota = [ 'remaining' => 0, 'limit' => 0, 'tier' => 'free' ];
            } else {

                // Only upgrade immediately. For downgrades, defer until local grace period ends.
                if ( ! empty( $quota['tier'] ) ) {
                    $incoming = (string) $quota['tier'];
                    $current  = (string) get_option( 'wpsa_saved_tier', 'free' );

                    // Has our stored grace period expired?
                    $exp      = (string) get_option( 'wpsa_license_expiration', '' );
                    $expired  = false;
                    if ( $exp ) {
                        // treat expiration end-of-day
                        $expired = ( time() > strtotime( $exp . ' 23:59:59' ) );
                    }

                    // Upgrade now (e.g., free->premium1, premium1->premium3, etc.)
                    if ( wpsa_tier_rank( $incoming ) > wpsa_tier_rank( $current ) ) {
                        update_option( 'wpsa_saved_tier', $incoming );
                    }

                    // Downgrade only if the grace period has ended
                    if ( $expired && wpsa_tier_rank( $incoming ) < wpsa_tier_rank( $current ) ) {
                        update_option( 'wpsa_saved_tier', $incoming );
                    }

                    // IMPORTANT: if we’re deferring a downgrade (grace still valid),
                    // show the local grace quota in the UI so it matches PDFs.
                    if ( ! $expired && wpsa_tier_rank( $incoming ) < wpsa_tier_rank( $current ) ) {
                        $quota = wpsa_get_local_quota_snapshot( 'ttfb' );
                    }
                }
            }



    wp_localize_script( 'wpsa-admin-scripts', 'wpsaData', [
      'psiNonce'     => wp_create_nonce( 'wpsa_psi_nonce' ),
      'perfNonce'    => wp_create_nonce( 'wpsa_perf_nonce' ),
      'module7Nonce' => wp_create_nonce( 'wpsa_module7_nonce' ),
       'loadTestNonce'  => wp_create_nonce( 'wpsa_load_test' ), 
      'dailyRemaining' => $quota['remaining'],
      'dailyLimit'     => $quota['limit'],
      'homeUrl'      => add_query_arg(
      [ 'page' => 'speed-analyzer', 'tab' => 'tests' ],
      admin_url( 'tools.php' )),
    ] );

    // html2pdf + report scripts (only on this page)
    wp_enqueue_script(
    'wpsa-html2pdf',
    WPSA_PLUGIN_URL . 'assets/js/html2pdf.bundle.min.js',
    [],
    '0.10.3',
    true
    );

    wp_enqueue_script(
        'wpsa-report-scripts',
        WPSA_PLUGIN_URL . 'report-scripts.js',
        [ 'jquery', 'wpsa-html2pdf' ],
        SAWP_VERSION,
        true
    );
    
    // PDF-only CSS moved out of inline <style id="wpsa-pdf-only"> to external file
    wp_enqueue_style(
        'wpsa-report-styles',
        WPSA_PLUGIN_URL . 'report-styles.css',
        [],
        SAWP_VERSION
    );


    $config = wpsa_get_pdf_effective_config();

    $pdf_quota = wpsa_check_quota( 'pdf' );
    if ( is_wp_error( $pdf_quota ) || ! is_array( $pdf_quota ) ) {
        $pdf_quota = [
            'remaining' => (int) wpsa_get_pdf_remaining(),
            'limit'     => (int) wpsa_get_pdf_limit(),
        ];
    }

    wp_localize_script( 'wpsa-report-scripts', 'wpsaPdf', [
        'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'wpsa_pdf_nonce' ),
        'remaining' => (int) ( $pdf_quota['remaining'] ?? 0 ),
        'limit'     => (int) ( $pdf_quota['limit'] ?? 0 ),
        'pdf'       => [
            'headerText'   => $config['header_text'],
            'removeCta'    => (bool) $config['remove_cta'],
            'customSummary'=> [
                'enabled' => (bool) $config['custom_summary_enabled'],
                'text'    => $config['custom_summary_text'],
            ],
        ],
    ] );
} );


    /**
     * Handle Activate / Deactivate License form submission
     */
   add_action( 'admin_post_wpsa_save_license', 'wpsa_handle_license_form' );
function wpsa_handle_license_form() {
    check_admin_referer( 'wpsa_license_action', 'wpsa_license_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.' );
    }

    // ─── Deactivate branch ───
    if ( isset( $_POST['wpsa_deactivate_license'] ) ) {
        // Only remove the active key — leave tier, expiry & slots intact
        delete_option( 'wpsa_license_key' );

        add_settings_error(
            'wpsa_license',
            'deactivated',
            __( 'License deactivated successfully.', 'speed-analyzer' ),
            'updated'
        );
        set_transient(
            'wpsa_license_notices_' . get_current_user_id(),
            get_settings_errors( 'wpsa_license' ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'tools.php?page=speed-analyzer&tab=license' ) );
        exit;
    }

  // ─── Activate branch ───
if ( isset( $_POST['wpsa_activate_license'] ) ) {
    $key = sanitize_text_field( wp_unslash( $_POST['wpsa_license_key'] ?? '' ) );

    // 1) Quick prefix‐only check
    if ( empty( $key ) || ! preg_match( '/^pre/i', $key ) ) {
        // preserve the bad input…
        set_transient( 'wpsa_license_input_' . get_current_user_id(), $key, MINUTE_IN_SECONDS );
        set_transient( 'wpsa_gk_bypassed_' . get_current_user_id(), true, MINUTE_IN_SECONDS );
        add_settings_error( 'wpsa_license', 'invalid_key',
            __( 'Key invalid, please check and try again.', 'speed-analyzer' ), 'error'
        );
        set_transient( 'wpsa_license_notices_' . get_current_user_id(),
            get_settings_errors( 'wpsa_license' ), MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'tools.php?page=speed-analyzer&tab=license' ) );
        exit;
    }

    // 2) Central-tracker slots check
    $status_url = 'https://wpservice.pro/wp-json/wpsa/v1/status?license_key=' . rawurlencode( $key );
    $local      = wp_remote_get( $status_url, [ 'timeout' => 5 ] );
    $remaining  = 0;
    $activeSite = false;
    if ( ! is_wp_error( $local )
      && 200 === wp_remote_retrieve_response_code( $local )
      && ( $data = json_decode( wp_remote_retrieve_body( $local ), true ) )
    ) {
        $remaining  = intval( $data['remainingSites'] ?? 0 );
        $activeSite = (bool) $data['activeSite'] ?? false;
    }

    // if no free slots **and** not already active on this host…
    if ( $remaining < 1 && ! $activeSite ) {
        // keep the key in the text field
        set_transient( 'wpsa_license_input_' . get_current_user_id(), $key, MINUTE_IN_SECONDS );
        add_settings_error( 'wpsa_license', 'slots_full',
            __( 'All license slots used, please upgrade license.', 'speed-analyzer' ), 'error'
        );
        set_transient( 'wpsa_license_notices_' . get_current_user_id(),
            get_settings_errors( 'wpsa_license' ), MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'tools.php?page=speed-analyzer&tab=license' ) );
        exit;
    }

    // 3) Gatekeeper activation
    wp_remote_post( WPSA_GATEKEEPER_URL . '/activate', [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode([
            'license_key' => $key,
            'site_url'    => home_url(),
        ]),
        'timeout' => 15,
    ]);

    // 4) **Correct** central tracker recording call
    wp_remote_post( 'https://wpservice.pro/wp-json/wpsa/v1/activate', [
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode([
            'license_key' => $key,
            'site_url'    => home_url(),
        ]),
        'timeout' => 5,
    ]);
    
        // 5) Gatekeeper /check for tier + slot counts
        $check_url = add_query_arg( [
            'license_key' => $key,
            'operation'   => 'ttfb',
            'daily_used'  => 0,
        ], WPSA_GATEKEEPER_URL . '/check' );

    
        $resp = wp_remote_get( $check_url, [ 'timeout' => 15 ] );
        if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
            add_settings_error(
                'wpsa_license',
                'check_failed',
                __( 'License check failed, please try again.', 'speed-analyzer' ),
                'error'
            );
            set_transient(
                'wpsa_license_notices_' . get_current_user_id(),
                get_settings_errors( 'wpsa_license' ),
                MINUTE_IN_SECONDS
            );
            wp_safe_redirect( admin_url( 'tools.php?page=speed-analyzer&tab=license' ) );
            exit;
        }
    
          $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( empty( $body ) || ( $body['tier'] ?? 'free' ) === 'free' ) {
            // Prefix looked ok, but Gatekeeper did not upgrade beyond "free".
            // Treat this as a non-active subscription (expired / cancelled / not valid for this product).
            set_transient( 'wpsa_license_input_' . get_current_user_id(), $key, MINUTE_IN_SECONDS );
    
            add_settings_error(
                'wpsa_license',
                'subscription_inactive',
                __( 'Subscription is not active for this license key (expired or cancelled). Please renew/upgrade your license.', 'speed-analyzer' ),
                'error'
            );
    
            set_transient(
                'wpsa_license_notices_' . get_current_user_id(),
                get_settings_errors( 'wpsa_license' ),
                MINUTE_IN_SECONDS
            );
    
            wp_safe_redirect( admin_url( 'tools.php?page=speed-analyzer&tab=license' ) );
            exit;
        }

    
        // 6) Success → commit key, tier, expiration…
        update_option( 'wpsa_license_key',        $key );
        update_option( 'wpsa_saved_tier',         $body['tier'] );
        update_option( 'wpsa_license_expiration', gmdate( 'Y-m-d', strtotime( '+1 month' ) ) );
    
        // 7) Finish with success notice + redirect
        add_settings_error(
            'wpsa_license',
            'activated',
            __( 'License activated successfully!', 'speed-analyzer' ),
            'updated'
        );
        set_transient(
            'wpsa_license_notices_' . get_current_user_id(),
            get_settings_errors( 'wpsa_license' ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'tools.php?page=speed-analyzer&tab=license' ) );
        exit;
    }


}

add_action( 'admin_post_wpsa_save_pdf_custom', function() {
    check_admin_referer( 'wpsa_save_pdf_custom', 'wpsa_pdf_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

    $redirect = admin_url( 'tools.php?page=speed-analyzer&tab=customize' );

    // Always allow switching mode (even non-Agency)
    if ( isset( $_POST['wpsa_pdf_apply_mode'] ) || isset( $_POST['wpsa_pdf_save'] ) ) {
        $mode_raw = sanitize_key( wp_unslash( $_POST['wpsa_pdf_mode'] ?? 'factory' ) );
        $mode     = in_array( $mode_raw, [ 'factory', 'saved' ], true ) ? $mode_raw : 'factory';
        update_option( 'wpsa_pdf_mode', $mode );
    }

    // Restore factory defaults
    if ( isset( $_POST['wpsa_pdf_restore_factory'] ) ) {
        // Clear all saved branding so we truly fall back to factory
        delete_option( 'wpsa_pdf_header_text' );
        delete_option( 'wpsa_pdf_remove_cta' );
        delete_option( 'wpsa_pdf_custom_summary_enabled' );
        delete_option( 'wpsa_pdf_custom_summary_text' );
    
        // Force factory mode
        update_option( 'wpsa_pdf_mode', 'factory' );
    
        // Optional: admin notice on next load
        add_settings_error(
            'wpsa_pdf',
            'restored',
            __( 'PDF template restored to factory defaults.', 'speed-analyzer' ),
            'updated'
        );
        set_transient(
            'wpsa_pdf_notices_' . get_current_user_id(),
            get_settings_errors( 'wpsa_pdf' ),
            MINUTE_IN_SECONDS
        );
    
        wp_safe_redirect( $redirect ); exit;
    }


    // Saving template values is Agency-only
    if ( wpsa_get_license_tier() !== 'premium3' ) {
        wp_safe_redirect( $redirect ); exit;
    }

   if ( isset( $_POST['wpsa_pdf_save'] ) ) {
    // 1) Sanitize + length-limit the submitted header text. Blankness is decided
    //    from the sanitized value below, so no raw copy is needed.
    $header = sanitize_text_field( wp_unslash( $_POST['wpsa_pdf_header_text'] ?? '' ) );
    $header = mb_substr( $header, 0, 80 );

    // Pattern that accepts versions like 1, 1.2.3, 1.16c, 2.0-beta, etc.
    $gen_pattern = '/^Generated by Speed Analyzer v[0-9][0-9.a-z-]*$/i';

    // 3) If user left it blank → delete the option so we fall back to factory default
    if ( '' === trim( $header ) ) {
        delete_option( 'wpsa_pdf_header_text' );
    } else {
        // 4) If it “looks like the default”, always sync to current plugin version
        if ( preg_match( $gen_pattern, $header ) ) {
            $header = 'Generated by Speed Analyzer v' . SAWP_VERSION;
        }
        update_option( 'wpsa_pdf_header_text', $header );
    }

    // Other fields
    $remove_cta  = ! empty( $_POST['wpsa_pdf_remove_cta'] ) ? 1 : 0;
    $summary_on  = ! empty( $_POST['wpsa_pdf_custom_summary_enabled'] ) ? 1 : 0;
    $summary_txt = wp_kses_post( wp_unslash( $_POST['wpsa_pdf_custom_summary_text'] ?? '' ) );

    update_option( 'wpsa_pdf_remove_cta', $remove_cta );
    update_option( 'wpsa_pdf_custom_summary_enabled', $summary_on );
    update_option( 'wpsa_pdf_custom_summary_text', $summary_txt );

    // Ensure we’re using the saved template going forward
    update_option( 'wpsa_pdf_mode', 'saved' );
}


    wp_safe_redirect( $redirect ); exit;
} );


// ===== Customize PDF (helpers + panel) =====
function wpsa_get_pdf_factory_defaults() : array {
    return [
        'header_text'             => 'Generated by Speed Analyzer v' . SAWP_VERSION,
        'remove_cta'              => false,
        'custom_summary_enabled'  => false,
        'custom_summary_text'     => '',
    ];
}

function wpsa_get_pdf_effective_config() : array {
    $factory = wpsa_get_pdf_factory_defaults();
    $tier    = get_option( 'wpsa_saved_tier', 'free' );
    $mode    = get_option( 'wpsa_pdf_mode', 'factory' );

    // If user selected Factory, always use factory defaults regardless of tier
    if ( $mode === 'factory' ) {
        return $factory;
    }

    // Only Agency (premium3) can use saved branding
    if ( $tier !== 'premium3' ) {
        return $factory;
    }

    // Otherwise return saved values with fallback to factory
    $saved_header = get_option( 'wpsa_pdf_header_text', '' );

    // Keep default-style header in sync with current plugin version
    $pattern_default = '/^Generated by Speed Analyzer v[0-9.]+$/i';
    if ( preg_match( $pattern_default, (string) $saved_header ) ) {
        $saved_header = 'Generated by Speed Analyzer v' . SAWP_VERSION;
    }

    $header_text = ( '' !== trim( (string) $saved_header ) )
        ? (string) $saved_header
        : $factory['header_text'];

    return [
        'header_text'            => $header_text,
        'remove_cta'             => (bool) get_option( 'wpsa_pdf_remove_cta', $factory['remove_cta'] ),
        'custom_summary_enabled' => (bool) get_option( 'wpsa_pdf_custom_summary_enabled', $factory['custom_summary_enabled'] ),
        'custom_summary_text'    => (string) get_option( 'wpsa_pdf_custom_summary_text', $factory['custom_summary_text'] ),
    ];
}


function wpsa_render_customize_pdf_panel() {
    $is_agency = ( get_option( 'wpsa_saved_tier', 'free' ) === 'premium3' );
    $mode      = get_option( 'wpsa_pdf_mode', 'factory' );
    $factory   = wpsa_get_pdf_factory_defaults();
    $saved     = [
        'header_text'            => get_option( 'wpsa_pdf_header_text', $factory['header_text'] ),
        'remove_cta'             => (bool) get_option( 'wpsa_pdf_remove_cta', $factory['remove_cta'] ),
        'custom_summary_enabled' => (bool) get_option( 'wpsa_pdf_custom_summary_enabled', $factory['custom_summary_enabled'] ),
        'custom_summary_text'    => get_option( 'wpsa_pdf_custom_summary_text', $factory['custom_summary_text'] ),
    ];
    
    // Prefill logic: if saved header is blank OR looks like the default
    // “Generated by Speed Analyzer vX”, display the current version.
    $generated_default = 'Generated by Speed Analyzer v' . SAWP_VERSION;
    $pattern_default   = '/^Generated by Speed Analyzer v[0-9.]+$/i';
    
    $input_header = (
        '' === trim( (string) $saved['header_text'] ) ||
        preg_match( $pattern_default, (string) $saved['header_text'] )
    ) ? $generated_default : (string) $saved['header_text'];

    
    $disabled_attr = $is_agency ? '' : ' disabled aria-disabled="true" ';
    $lock_tip      = 'Only available on Agency license.';
    ?>
  <div class="wrap" style="max-width:750px;margin:0 auto;">
      <!-- Brand PDF header -->
      <h1 class="header-brand">
        <img class="wpsa-logo"
             src="<?php echo esc_url( WPSA_PLUGIN_URL . 'SAWP-logo.svg' ); ?>"
             alt="Speed Analyzer Logo">
        <div class="wpsa-header-info">
          <span class="wpsa-name">Speed Analyzer</span>
          <small class="wpsa-version">v<?php echo esc_html( SAWP_VERSION ); ?></small>
          <p class="wpsa-header-subtitle">A first step towards a faster website</p>
          <p id="wpsa-header-credit">
            Developed by Dalibor Druzinec /
            <a href="https://wpservice.pro/" target="_blank">WPservice.pro</a>
          </p>
        </div>
      </h1>

  <h2 class="wpsa-module-title">Customize PDF report (Agency plan only)</h2>


      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'wpsa_save_pdf_custom', 'wpsa_pdf_nonce' ); ?>
        <input type="hidden" name="action" value="wpsa_save_pdf_custom">

        <h3>Active template</h3>
        <p>
          <label style="margin-right:18px;">
            <input type="radio" name="wpsa_pdf_mode" value="factory" <?php checked( $mode, 'factory' ); ?>>
            Use <strong>Factory defaults</strong>
          </label>
          <label>
            <input type="radio" name="wpsa_pdf_mode" value="saved" <?php checked( $mode, 'saved' ); ?>>
            Use <strong>My saved template</strong>
          </label>
        </p>
        <hr>

        <h3>Saved template (edit below)</h3>
        <table class="form-table" role="presentation"><tbody>
          <tr>
            <th scope="row"><label for="wpsa_pdf_header_text">Header text override</label></th>
            <td>
              <input type="text" id="wpsa_pdf_header_text" name="wpsa_pdf_header_text"
                     value="<?php echo esc_attr( $input_header ); ?>"
                     maxlength="80" class="regular-text"
                     <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute fragment ('' or ' disabled aria-disabled="true" '); escaping it would break the attribute. ?>
                     <?php echo $disabled_attr; ?>
                     <?php if ( ! $is_agency ) echo ' title="' . esc_attr( $lock_tip ) . '"'; ?>>
              <p class="description">Appears in the PDF header (max 80 chars). Leave blank to fall back to factory default.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Remove last-page CTA</th>
            <td>
              <label>
                <input type="checkbox" name="wpsa_pdf_remove_cta" value="1"
                       <?php checked( $saved['remove_cta'] ); ?>
                       <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute fragment ('' or ' disabled aria-disabled="true" '); escaping it would break the attribute. ?>
                       <?php echo $disabled_attr; ?>
                       <?php if ( ! $is_agency ) echo ' title="' . esc_attr( $lock_tip ) . '"'; ?>>
                Remove the “Need an even deeper audit?” box.
              </label>
            </td>
          </tr>
          <tr>
            <th scope="row">Custom summary</th>
            <td>
              <label style="display:block;margin-bottom:8px;">
                <input type="checkbox" name="wpsa_pdf_custom_summary_enabled" value="1"
                       <?php checked( $saved['custom_summary_enabled'] ); ?>
                       <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute fragment ('' or ' disabled aria-disabled="true" '); escaping it would break the attribute. ?>
                       <?php echo $disabled_attr; ?>
                       <?php if ( ! $is_agency ) echo ' title="' . esc_attr( $lock_tip ) . '"'; ?>>
                Append a custom summary at the very end.
              </label>
              <textarea name="wpsa_pdf_custom_summary_text" rows="6" style="width:100%;max-width:520px;"
                        <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute fragment ('' or ' disabled aria-disabled="true" '); escaping it would break the attribute. ?>
                        <?php echo $disabled_attr; ?>
                        <?php if ( ! $is_agency ) echo ' title="' . esc_attr( $lock_tip ) . '"'; ?>><?php
                  echo esc_textarea( $saved['custom_summary_text'] );
              ?></textarea>
            </td>
          </tr>
        </tbody></table>

        <p>
          <button type="submit" name="wpsa_pdf_save" class="button button-primary" <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute fragment; see note above. ?><?php echo $disabled_attr; ?>
            <?php if ( ! $is_agency ) echo ' title="' . esc_attr( $lock_tip ) . '"'; ?>>Save template</button>
          <button type="submit" name="wpsa_pdf_restore_factory" class="button">Restore factory defaults</button>
        </p>
      </form>
    </div>
    <?php
}


/**
 * Render the Speed Analyzer tool page.
 */
function wpsa_render_tool_page() {
  // — Decide which tab to show —
    $allowed    = [ 'tests', 'compare', 'schedule', 'license', 'customize' ];
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch on an admin screen; renders no state change, so a nonce would add nothing.
    $requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    $active_tab    = in_array( $requested_tab, $allowed, true ) ? $requested_tab : 'tests';

    
    global $wpdb;
    
    $upload_dir  = wp_upload_dir();
    $base_dir    = trailingslashit( $upload_dir['basedir'] ) . 'speed-analyzer';
    $log_path    = $base_dir . '/ttfb-api-debug.log';
    $results_log = $base_dir . '/ttfb-results-log.txt';

    if ( ! file_exists( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
    }

    // Default URL and form variables
    $tested_url  = home_url();
    $submitted   = '';
    $run_test    = '';
    $invalid_url = false;

    // Allow prefill from query string when opening from Posts/Pages list (“Re-test”, “See report”)
    if ( isset( $_GET['test_url'] ) ) {
        $prefill = esc_url_raw( wp_unslash( $_GET['test_url'] ) );
        if ( $prefill && wp_http_validate_url( $prefill ) ) {
            $tested_url = $prefill;
        }
    }

    if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        check_admin_referer( 'wpsa_speed_test', 'wpsa_speed_test' );

        $run_test  = sanitize_text_field( wp_unslash( $_POST['run_test'] ?? '' ) );
        $submitted = esc_url_raw( wp_unslash( $_POST['test_url'] ?? '' ) );

        if ( '1' === $run_test ) {
            if ( ! $submitted || ! filter_var( $submitted, FILTER_VALIDATE_URL ) ) {
                echo '<div class="notice notice-error"><p><strong>Error:</strong> Please enter a valid URL to test.</p></div>';
                $invalid_url = true;
            } else {
                $tested_url = $submitted;
            }
        }
    }

    $is_unlocked  = in_array( wpsa_get_license_tier(), [ 'premium3' ], true );
    $lock_tooltip = $is_unlocked
        ? 'Unlocked: you may test any URL'
        : 'Locked: Only same-host URLs are allowed on this plan. Only the Agency plan has this unlocked.
';
    $icon         = $is_unlocked ? '🔓' : '🔒';
    $remaining    = wpsa_get_daily_remaining();
    $limit        = wpsa_get_daily_limit();
    ?>
              <div class="wpsa-layout">

    <div class="wpsa-sidebar-options"
         aria-label="<?php esc_attr_e( 'Sidebar display options', 'speed-analyzer' ); ?>">
        <label class="wpsa-sidebar-top-toggle" for="wpsa-sidebars-top-toggle">
            <input id="wpsa-sidebars-top-toggle" type="checkbox" value="1">
            <span><?php esc_html_e( 'Place sidebars on top', 'speed-analyzer' ); ?></span>
        </label>
    </div>

    <div class="wpsa-sidebar-nav">
        <a
          href="<?php echo esc_url( add_query_arg(
            [ 'page' => 'speed-analyzer', 'tab'  => 'tests'   ],
            admin_url( 'tools.php' )
          ) ); ?>"
          class="wpsa-nav-tile tile-tests<?php echo $active_tab === 'tests' ? ' active' : ''; ?>">
          <span class="dashicons dashicons-dashboard"></span>
          Tests
        </a>
        <a
          href="<?php echo esc_url( add_query_arg(
            [ 'page' => 'speed-analyzer', 'tab'  => 'compare' ],
            admin_url( 'tools.php' )
          ) ); ?>"
          class="wpsa-nav-tile tile-compare<?php echo $active_tab === 'compare' ? ' active' : ''; ?>">
          <span class="dashicons dashicons-chart-line"></span>
          Compare Results
        </a>
        <a
          href="<?php echo esc_url( add_query_arg(
            [ 'page' => 'speed-analyzer', 'tab'  => 'schedule' ],
            admin_url( 'tools.php' )
          ) ); ?>"
          class="wpsa-nav-tile tile-schedule<?php echo $active_tab === 'schedule' ? ' active' : ''; ?>">
          <span class="dashicons dashicons-calendar-alt"></span>
          Schedule tests
        </a>
        <a
          href="<?php echo esc_url( add_query_arg(
            [ 'page' => 'speed-analyzer', 'tab'  => 'license' ],
            admin_url( 'tools.php' )
          ) ); ?>"
          class="wpsa-nav-tile tile-license<?php echo $active_tab === 'license' ? ' active' : ''; ?>">
          <span class="dashicons dashicons-admin-network"></span>
          License
        </a>
        <a
          href="<?php echo esc_url( add_query_arg(
            [ 'page' => 'speed-analyzer', 'tab'  => 'customize' ],
            admin_url( 'tools.php' )
          ) ); ?>"
          class="wpsa-nav-tile tile-customize<?php echo $active_tab === 'customize' ? ' active' : ''; ?>">
          <span class="dashicons dashicons-admin-customizer"></span>
          Brand PDF
        </a>
      </div><!-- .wpsa-sidebar-nav -->


      <div class="wpsa-main-content">

        <!-- ================= -->
        <!-- Tests Panel (active by default) -->
        <!-- ================= -->
        <div
          id="wpsa-test-panel"
          class="wpsa-panel<?php echo $active_tab === 'tests' ? ' active' : ''; ?>"
          style="display:<?php echo $active_tab === 'tests' ? 'block' : 'none'; ?>;"
        >
          <div class="wrap">
            <h1 class="wpsa-header">
              <img class="wpsa-logo"
                   src="<?php echo esc_url( WPSA_PLUGIN_URL . 'SAWP-logo.svg' ); ?>"
                   alt="Speed Analyzer Logo">
              <div class="wpsa-header-info">
                <span class="wpsa-name">Speed Analyzer</span>
                <small class="wpsa-version">v<?php echo esc_html( SAWP_VERSION ); ?></small>
                <p class="wpsa-header-subtitle">A first step towards a faster website</p>
                <p id="wpsa-header-credit">
                  Developed by Dalibor Druzinec /
                  <a href="https://wpservice.pro/" target="_blank">WPservice.pro</a>
                </p>
              </div>
            </h1>

            <form id="speed-test-form"
                  method="POST"
                  action="<?php echo esc_url( admin_url( 'tools.php?page=speed-analyzer' ) ); ?>"
                  class="wpsa-form">
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
                <span class="wpsa-url-lock wpsa-lock-tooltip"
                      data-tooltip="<?php echo esc_attr( $lock_tooltip ); ?>">
                  <?php echo esc_html( $icon ); ?>
                </span>
              </div>

              <button
                type="submit"
                class="button button-primary wpsa-button-run">
                Run Speed Audit
              </button>

              <p id="wpsa-daily-remaining"
                 class="wpsa-url-display"
                 style="flex-basis:100%; margin-top:0.5em; text-align:right;">
                Daily limit remaining:
                <strong<?php if ( $remaining <= 3 ) echo ' style="color:#d32f2f;"'; ?>>
                  <?php echo esc_html( "{$remaining}/{$limit}" ); ?>
                </strong>
                <span class="custom-tooltip" style="z-index: 1001"
                      data-tooltip="Your daily fair-use limit. Upgrade license for an increased limit.">?</span>
              </p>
            <!-- Load test # (top-right control) -->
              <div id="wpsa-loadtest" class="wpsa-loadtest">
                <label for="wpsa-loadtest-input" style="margin-right:6px;">Load test #</label>
                <input id="wpsa-loadtest-input" type="number" min="1" step="1" style="width:110px;">
                <button type="button" class="button-primary" id="wpsa-loadtest-btn">Load</button>
                <span id="wpsa-loadtest-msg" class="msg" aria-live="polite" style="margin-left:8px;"></span>
              </div>
              
              <!-- Generate PDF on the start added in 1.17.3 -->
              <div class="wpsa-pdf-button-wrap wpsa-pdf-tests pdf-main" style="margin-top:8px; text-align:left;">
                <button
                    id="report-button-tests"
                    class="button button-primary wpsa-button-pdf"
                    type="button"
                    disabled="disabled"
                >
                    Generate PDF report
                </button>
                <span class="custom-tooltip"
                          data-tooltip="Generate a PDF report from the currently loaded test. Run or load a test first.">
                      ?
                    </span>
            
                <p id="wpsa-pdf-counter-tests"
                   class="pdf-daily-main"
                   style="display:flex; font-size:0.9em;">
                    PDFs remaining today:
                    <strong> 0/0</strong>
                </p>
            </div>


            </form>

          </div><!-- .wrap -->

          <div id="running-test"
     class="wpsa-module-running"
     style="display:none;">Running test…</div>

        <!-- Always render these so JS can restore previous state -->
        <p id="wpsa-tested-url" class="wpsa-url-display" style="display:none;"></p>
               <div id="test-results" data-run="<?php echo ( '1' === $run_test && ! $invalid_url ) ? '1' : '0'; ?>">
        <?php
        // Only populate the container when a new run was posted
        if ( '1' === $run_test && ! $invalid_url ) {
        
            $quota = wpsa_check_quota( 'ttfb' );
            if ( is_wp_error( $quota ) ) {
                echo '<div class="notice notice-error2"><p>'
                     . esc_html__( 'Quota check failed. Please retry.', 'speed-analyzer' )
                     . '</p></div>';
            } elseif ( ! $quota['allowed'] ) {
                echo '<div class="notice notice-error3 notice-warning wpsa-notice-limit"><p><strong>'
                     . esc_html__( 'Fair usage limit.', 'speed-analyzer' )
                     . '</strong> '
                     . sprintf(
                         /* translators: %1$d: number of tests allowed per day. */
                         esc_html__( 'You’ve used all %1$d tests for today.', 'speed-analyzer' ),
                         (int) $quota['limit']
                     )
                     . '</p></div>';
            } else {
                if ( wpsa_module1_ttfb( $tested_url, $log_path, $results_log ) ) {
                    wpsa_increment_daily_usage();

                    // 2. Page asset summary (unchanged)
                    wpsa_module2_assets( $tested_url, $results_log );

                    // 3. Performance & Diagnostics (PSI) – move this up
                    wpsa_module5_performance_diagnostics( $tested_url, $results_log );

                    // 4, 5, 6 – Autoloaded options, object cache, various other info
                    wpsa_module3_4_autoload_cache( $wpdb, $results_log, $tested_url );

                    // 7. Summary stays after all above modules
                    wpsa_module6_summary( $tested_url, $results_log );
                   
                    // 8. Conclusion ?>
                      <div id="module7-wrapper" class="wpsa-module-7">
                      <h2 class="wpsa-module-title">8. Conclusion</h2>
                      <div id="module7-running" class="wpsa-module-running">Loading conclusion…</div>
                      <div id="module7-container" data-rendered="false"></div>
                    </div>
                    <?php
                }
            }
        }
        ?>
        </div><!-- /#test-results -->
           </div><!-- #wpsa-test-panel -->
        
        <!-- ================= -->
        <!-- Compare Results Panel -->
        <!-- ================= -->
        <div
          id="wpsa-compare-panel"
          class="wpsa-panel<?php echo $active_tab === 'compare' ? ' active' : ''; ?>"
          style="display:<?php echo $active_tab === 'compare' ? 'block' : 'none'; ?>;"
        >
          <?php wpsa_render_compare_results_panel_ui(); ?>
        </div><!-- #wpsa-compare-panel -->

        <!-- ================= -->
        <!-- Schedule Tests Panel -->
        <!-- ================= -->
        <div
          id="wpsa-schedule-panel"
          class="wpsa-panel<?php echo $active_tab === 'schedule' ? ' active' : ''; ?>"
          style="display:<?php echo $active_tab === 'schedule' ? 'block' : 'none'; ?>;"
        >
          <?php wpsa_render_schedule_panel_ui(); ?>
        </div><!-- #wpsa-schedule-panel -->

        <!-- ================= -->
        <!-- License Panel -->
        <!-- ================= -->

        <?php if ( $active_tab === 'license' ) : ?>
          <div class="wrap" style="margin:0 auto;">
            <h1 class="wpsa-header">
              <img class="wpsa-logo"
                   src="<?php echo esc_url( WPSA_PLUGIN_URL . 'SAWP-logo.svg' ); ?>"
                   alt="<?php esc_attr_e( 'Speed Analyzer Logo', 'speed-analyzer' ); ?>">
              <div class="wpsa-header-info">
                <span class="wpsa-name"><?php esc_html_e( 'License', 'speed-analyzer' ); ?></span>
                <small class="wpsa-version">v<?php echo esc_html( SAWP_VERSION ); ?></small>
                <p class="wpsa-header-subtitle">
                  <?php esc_html_e( 'Manage your Speed Analyzer license', 'speed-analyzer' ); ?>
                </p>
              </div>
            </h1>
          </div>
        <?php endif; ?>

        <div
          id="wpsa-license-panel"
          class="wpsa-panel<?php echo $active_tab === 'license' ? ' active' : ''; ?>"
          style="display:<?php echo $active_tab === 'license' ? 'block' : 'none'; ?>;"
        >
          <?php wpsa_render_license_panel_ui(); ?>
        </div><!-- #wpsa-license-panel -->

        
        <!-- ================= -->
        <!-- Customize PDF Panel -->
        <!-- ================= -->
        <div
          id="wpsa-customize-panel"
          class="wpsa-panel<?php echo $active_tab === 'customize' ? ' active' : ''; ?>"
          style="display:<?php echo $active_tab === 'customize' ? 'block' : 'none'; ?>;"
        >
          <?php wpsa_render_customize_pdf_panel(); ?>
        </div><!-- Customize PDF-panel -->


      </div><!-- .wpsa-main-content -->

      <div class="wpsa-feedback-stack" role="complementary"
           aria-label="<?php esc_attr_e( 'Speed Analyzer Feedback', 'speed-analyzer' ); ?>">
        <div class="wpsa-feedback-panel">
        <div class="wpsa-feedback-section">
          <h2><?php esc_html_e( 'Ratings & Reviews', 'speed-analyzer' ); ?></h2>
          <p>
            <?php esc_html_e( 'If you like Speed Analyzer please consider leaving a', 'speed-analyzer' ); ?>
            <span class="wpsa-stars">
              <?php for ( $i = 0; $i < 5; $i++ ) : ?>
                <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
              <?php endfor; ?>
            </span>
            <?php esc_html_e( 'rating.', 'speed-analyzer' ); ?>
          </p>
          <a href="https://wordpress.org/support/plugin/speed-analyzer/reviews/#new-post"
             target="_blank"
             rel="noopener noreferrer"
             class="wpsa-button-feedback">
            <?php esc_html_e( 'Leave a rating', 'speed-analyzer' ); ?>
          </a>
        </div>

        <div class="wpsa-feedback-section">
          <h2><?php esc_html_e( 'Having Issues?', 'speed-analyzer' ); ?></h2>
          <p><?php esc_html_e( 'I’m always happy to help out! Support is handled exclusively through WordPress.org.', 'speed-analyzer' ); ?></p>
          <a href="https://wordpress.org/support/plugin/speed-analyzer/"
             target="_blank"
             rel="noopener noreferrer"
             class="wpsa-button-feedback">
            <?php esc_html_e( 'Get Support', 'speed-analyzer' ); ?>
          </a>
        </div>
        </div>

        <div class="wpsa-feedback-panel wpsa-product-card">
          <h2><?php esc_html_e( 'Code Unloader', 'speed-analyzer' ); ?></h2>
          <p><?php esc_html_e( 'Unload scripts and styles per page after Speed Analyzer shows what needs attention.', 'speed-analyzer' ); ?></p>
          <a href="https://wpservice.pro/our-products/code-unloader/"
             target="_blank"
             rel="noopener noreferrer"
             class="wpsa-product-link">
            <img src="<?php echo esc_url( WPSA_PLUGIN_URL . 'assets/img/code-unloader-100x100.png' ); ?>"
                 alt="<?php esc_attr_e( 'Code Unloader', 'speed-analyzer' ); ?>"
                 class="wpsa-product-icon">
          </a>
          <a href="https://wpservice.pro/our-products/code-unloader/"
             target="_blank"
             rel="noopener noreferrer"
             class="wpsa-button-feedback">
            <?php esc_html_e( 'Get Code Unloader', 'speed-analyzer' ); ?>
          </a>
        </div>

        <div class="wpsa-feedback-panel wpsa-product-card">
          <h2><?php esc_html_e( 'AI Assets Scanner', 'speed-analyzer' ); ?></h2>
          <p><?php esc_html_e( 'Improve your speed with the groundbreaking automatic AI Assets Scanner unloading.', 'speed-analyzer' ); ?></p>
          <a href="https://wpservice.pro/our-products/ai-assets-scanner/"
             target="_blank"
             rel="noopener noreferrer"
             class="wpsa-product-link">
            <img src="<?php echo esc_url( WPSA_PLUGIN_URL . 'assets/img/ai-assets-scanner-100x100.png' ); ?>"
                 alt="<?php esc_attr_e( 'AI Assets Scanner', 'speed-analyzer' ); ?>"
                 class="wpsa-product-icon">
          </a>
          <a href="https://wpservice.pro/our-products/ai-assets-scanner/"
             target="_blank"
             rel="noopener noreferrer"
             class="wpsa-button-feedback">
            <?php esc_html_e( 'Get AI Assets Scanner', 'speed-analyzer' ); ?>
          </a>
        </div>
      </div><!-- .wpsa-feedback-stack -->

    </div><!-- .wpsa-layout -->
<?php
} // end wpsa_render_tool_page()
