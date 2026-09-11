<?php
/**
 * Licence expiry warning notice (Task B5, §5.4).
 *
 * Renders only on Speed Analyzer's own admin screen (D3), reads options only —
 * no network call on page load; the panel owns the /check call.
 *
 * File shape note: the two pure helpers plus the small pure computations
 * below them are defined unconditionally so a bare `require` of this file
 * (the test harness) can exercise the real shipped functions under plain PHP
 * CLI, where WordPress does not exist. The admin_notices renderer and the
 * AJAX handler are ALSO defined unconditionally — a function definition is
 * inert until called — but their add_action() registrations are wrapped in
 * function_exists( 'add_action' ) so loading this file outside WordPress
 * never touches a missing WordPress function.
 */

if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
    exit;
}

if ( ! defined( 'WPSA_LICENSE_NOTICE_DAYS' ) ) {
    define( 'WPSA_LICENSE_NOTICE_DAYS', 10 );
}
if ( ! defined( 'WPSA_LICENSE_NOTICE_URGENT' ) ) {
    define( 'WPSA_LICENSE_NOTICE_URGENT', 3 );
}
// AC-N4: the plugin has exactly one admin screen. Reusing this literal (also
// hardcoded in wp-speed-analyzer.php's admin_enqueue_scripts hook check)
// means the two gates cannot silently diverge without both being edited.
if ( ! defined( 'WPSA_LICENSE_NOTICE_SCREEN' ) ) {
    define( 'WPSA_LICENSE_NOTICE_SCREEN', 'tools_page_speed-analyzer' );
}
if ( ! defined( 'WPSA_LICENSE_NOTICE_DAY_SECONDS' ) ) {
    // WordPress defines DAY_IN_SECONDS; this file must also work stand-alone
    // under the CLI harness, so it carries its own constant instead of
    // depending on WordPress having loaded first.
    define( 'WPSA_LICENSE_NOTICE_DAY_SECONDS', 86400 );
}

/**
 * Should the notice render?
 *
 * @param string   $state             free|unknown|sold|active|grace|expired|not_found|inactive|disabled
 * @param int|null $days_left         Days until expiry, null when unknown/perpetual.
 * @param int|null $dismissed_at_days days_left recorded when the user dismissed it.
 * @return bool
 */
function wpsa_license_notice_should_show( $state, $days_left, $dismissed_at_days ) {
    if ( 'free' === $state || 'unknown' === $state || '' === $state ) {
        return false;
    }
    if ( in_array( $state, array( 'grace', 'expired', 'not_found', 'inactive', 'disabled', 'sold' ), true ) ) {
        return true;
    }
    if ( 'active' !== $state || null === $days_left ) {
        return false;
    }
    if ( (int) $days_left > WPSA_LICENSE_NOTICE_DAYS ) {
        return false;
    }
    if ( null === $dismissed_at_days ) {
        return true;
    }
    // Re-fire once the count crosses into the urgent band.
    return ( (int) $days_left <= WPSA_LICENSE_NOTICE_URGENT
             && (int) $dismissed_at_days > WPSA_LICENSE_NOTICE_URGENT );
}

/**
 * @param string $state
 * @return bool
 */
function wpsa_license_notice_is_dismissible( $state ) {
    return ! in_array( $state, array( 'grace', 'expired', 'not_found', 'inactive', 'disabled', 'sold' ), true );
}

/**
 * Days remaining until $expiration, or null when unknown/perpetual. Pure and
 * deterministic: $now is an injectable unix timestamp so the harness can pin
 * "today" instead of racing the clock. Normalizes both sides to their UTC
 * calendar date before diffing, so a plain 'Y-m-d' value (the documented
 * shape of wpsa_license_expiration — design §5, options table) and a
 * full datetime both produce the same whole-day count.
 *
 * @param string   $expiration Stored wpsa_license_expiration option value, or ''.
 * @param int|null $now        Unix timestamp to compare against; defaults to time().
 * @return int|null
 */
function wpsa_license_notice_days_left( $expiration, $now = null ) {
    $expiration = trim( (string) $expiration );
    if ( '' === $expiration ) {
        return null;
    }
    $exp_ts = strtotime( $expiration );
    if ( false === $exp_ts ) {
        return null;
    }
    $now       = null === $now ? time() : (int) $now;
    $exp_day   = strtotime( gmdate( 'Y-m-d', $exp_ts ) . ' 00:00:00 UTC' );
    $today_day = strtotime( gmdate( 'Y-m-d', $now ) . ' 00:00:00 UTC' );
    return (int) floor( ( $exp_day - $today_day ) / WPSA_LICENSE_NOTICE_DAY_SECONDS );
}

/**
 * Chooses which notice copy template and action button to use. Pure — no
 * WordPress calls, no translation functions (those need literal msgids, so
 * they are called at the render site, keyed off the 'template' this returns)
 * — so the state → copy → button mapping is directly assertable without
 * loading WordPress. Sold gets Contact and never Renew.
 *
 * Mirrors includes/lpanel.php's status-line selection: 'not_found' is a typo,
 * not a lapse, so it does not carry the "no longer active" wording.
 *
 * @param string $state free|unknown|sold|active|grace|expired|not_found|inactive|disabled
 * @return array{template:string,button:string} button is 'renew'|'contact'|'none'.
 */
function wpsa_license_notice_template( $state ) {
    if ( 'sold' === $state ) {
        return array( 'template' => 'sold', 'button' => 'contact' );
    }
    if ( 'not_found' === $state ) {
        // A typo is not a lapse: no Renew button, matching the panel, which gives
        // this row no button either.
        return array( 'template' => 'invalid_not_found', 'button' => 'none' );
    }
    if ( 'inactive' === $state || 'disabled' === $state ) {
        return array( 'template' => 'invalid', 'button' => 'renew' );
    }
    if ( 'grace' === $state ) {
        return array( 'template' => 'grace', 'button' => 'renew' );
    }
    if ( 'expired' === $state ) {
        return array( 'template' => 'expired', 'button' => 'renew' );
    }
    // 'active', days_left <= 10 — wpsa_license_notice_should_show() already gated this.
    return array( 'template' => 'active', 'button' => 'renew' );
}

/**
 * The plan name and the days number that both the licence panel
 * (includes/lpanel.php) and this notice print, so the two can never disagree.
 * Pure and deterministic: $now is an injectable unix timestamp.
 *
 * Days are counted with wpsa_license_notice_days_left(), never taken from the
 * licence service's days_left, which stays at 0 for the whole grace week:
 *   - grace: the days until the service's grace date, never below 0 (null when
 *     there is no grace date);
 *   - any other state: the days until the expiry date, the same count that
 *     decides whether this notice shows (null when there is no expiry).
 *
 * Once a licence has expired the service reports the free tier, so the plan
 * name is the plan that lapsed ($last_paid_tier), or the current tier when no
 * lapsed plan is stored.
 *
 * @param string   $state          free|unknown|sold|active|grace|expired|not_found|inactive|disabled
 * @param string   $tier           Current tier: free|premium1|premium2|premium3.
 * @param string   $last_paid_tier Stored wpsa_last_paid_tier value, or ''.
 * @param string   $expiration     Stored wpsa_license_expiration value, or ''.
 * @param string   $grace_until    Stored wpsa_license_grace_until value, or ''.
 * @param int|null $now            Unix timestamp to count from; defaults to time().
 * @return array{label:string,days:int|null}
 */
function wpsa_license_display( $state, $tier, $last_paid_tier, $expiration, $grace_until, $now = null ) {
    $labels = array(
        'free'     => 'Free',
        'premium1' => 'PRO',
        'premium2' => 'BUSINESS',
        'premium3' => 'AGENCY',
    );
    $plan = (string) $tier;
    if ( 'expired' === $state && '' !== (string) $last_paid_tier ) {
        $plan = (string) $last_paid_tier;
    }

    if ( 'grace' === $state ) {
        $days = wpsa_license_notice_days_left( $grace_until, $now );
        $days = null === $days ? null : max( 0, $days );
    } else {
        $days = wpsa_license_notice_days_left( $expiration, $now );
    }

    return array(
        'label' => isset( $labels[ $plan ] ) ? $labels[ $plan ] : ucfirst( $plan ),
        'days'  => $days,
    );
}

if ( function_exists( 'add_action' ) ) {
    add_action( 'admin_notices', 'wpsa_render_license_notice' );
    add_action( 'wp_ajax_wpsa_dismiss_license_notice', 'wpsa_ajax_dismiss_license_notice' );
}

/**
 * Renders the 10-day licence-expiry warning. Screen- and capability-gated,
 * reads options only (no network call on page load — the panel's /check call
 * remains the panel's alone, §5.4).
 */
function wpsa_render_license_notice() {
    if ( ! function_exists( 'get_current_screen' ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( ! $screen || WPSA_LICENSE_NOTICE_SCREEN !== $screen->id ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $state          = (string) get_option( 'wpsa_license_state', '' );
    $expiration     = (string) get_option( 'wpsa_license_expiration', '' );
    $grace_until    = (string) get_option( 'wpsa_license_grace_until', '' );
    $tier           = (string) get_option( 'wpsa_saved_tier', 'free' );
    $last_paid_tier = (string) get_option( 'wpsa_last_paid_tier', '' );

    $days_left = wpsa_license_notice_days_left( $expiration );

    $uid               = get_current_user_id();
    $dismissed_raw     = $uid ? get_user_meta( $uid, 'wpsa_license_notice_dismissed_' . $uid, true ) : '';
    $dismissed_at_days = ( '' === $dismissed_raw || null === $dismissed_raw ) ? null : (int) $dismissed_raw;

    if ( ! wpsa_license_notice_should_show( $state, $days_left, $dismissed_at_days ) ) {
        return;
    }

    // Copy is fixed by the spec's §5.3 table (mirrored from lpanel.php); do not improvise.
    // The plan name and the days number come from the helper the panel shares.
    $display = wpsa_license_display( $state, $tier, $last_paid_tier, $expiration, $grace_until );
    $label   = $display['label'];

    $tpl = wpsa_license_notice_template( $state );

    switch ( $tpl['template'] ) {
        case 'sold':
            $text = __( 'Your licence has been paid for but not yet delivered. Please contact support.', 'speed-analyzer' );
            break;
        case 'invalid_not_found':
            $text = __( "We don't recognise that licence key — check it for typos", 'speed-analyzer' );
            break;
        case 'invalid':
            $text = __( 'This licence is no longer active', 'speed-analyzer' );
            break;
        case 'grace':
            $text = sprintf(
                /* translators: 1: plan name, 2: days of access remaining */
                __( '%1$s — licence expired; %2$d days of access remaining', 'speed-analyzer' ),
                $label,
                (int) $display['days']
            );
            break;
        case 'expired':
            $text = sprintf(
                /* translators: %s: plan name */
                __( 'Licence expired — renew to restore %s', 'speed-analyzer' ),
                $label
            );
            break;
        default: // 'active'
            $text = sprintf(
                /* translators: 1: plan name, 2: days until expiry */
                __( '%1$s — expires in %2$d days', 'speed-analyzer' ),
                $label,
                (int) $display['days']
            );
            break;
    }

    $button_label = '';
    $button_url   = '';
    if ( 'renew' === $tpl['button'] ) {
        $button_label = __( 'Renew', 'speed-analyzer' );
        $button_url   = 'https://wpservice.pro/our-products/speed-analyzer-wp-plugin/#licenses';
    } elseif ( 'contact' === $tpl['button'] ) {
        $button_label = __( 'Contact', 'speed-analyzer' );
        $button_url   = 'https://wpservice.pro/contact/';
    }

    $dismiss_ok = wpsa_license_notice_is_dismissible( $state );

    $classes = 'notice notice-warning wpsa-license-notice';
    if ( $dismiss_ok ) {
        $classes .= ' is-dismissible';
    }
    ?>
    <div id="wpsa-license-notice" class="<?php echo esc_attr( $classes ); ?>" data-dismiss-nonce="<?php echo esc_attr( wp_create_nonce( 'wpsa_license_notice_dismiss' ) ); ?>">
        <p>
            <?php echo esc_html( $text ); ?>
            <?php if ( '' !== $button_label ) : ?>
                <a href="<?php echo esc_url( $button_url ); ?>" target="_blank" rel="noopener noreferrer" class="button"><?php echo esc_html( $button_label ); ?></a>
            <?php endif; ?>
        </p>
    </div>
    <?php if ( $dismiss_ok ) : ?>
    <script>
    ( function () {
        var notice = document.getElementById( 'wpsa-license-notice' );
        if ( ! notice ) {
            return;
        }
        notice.addEventListener( 'click', function ( e ) {
            if ( ! e.target || ! e.target.classList || ! e.target.classList.contains( 'notice-dismiss' ) ) {
                return;
            }
            var nonce = notice.getAttribute( 'data-dismiss-nonce' );
            var xhr = new XMLHttpRequest();
            xhr.open( 'POST', window.ajaxurl, true );
            xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
            xhr.send( 'action=wpsa_dismiss_license_notice&nonce=' + encodeURIComponent( nonce ) );
        } );
    } )();
    </script>
    <?php endif;
}

/**
 * AJAX: records that the current user dismissed the licence notice, keyed to
 * the days-left value in effect right now. Nonce- and capability-gated. The
 * days-left value is recomputed server-side from options rather than trusted
 * from the client, so there is no client-supplied value to sanitize beyond
 * the nonce itself (already validated by check_ajax_referer()) — this also
 * makes the AJAX payload a bare action+nonce, nothing else to spoof.
 */
function wpsa_ajax_dismiss_license_notice() {
    check_ajax_referer( 'wpsa_license_notice_dismiss', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $uid = get_current_user_id();
    if ( ! $uid ) {
        wp_send_json_error( 'No user.' );
    }

    $expiration = (string) get_option( 'wpsa_license_expiration', '' );
    $days_left  = wpsa_license_notice_days_left( $expiration );

    update_user_meta( $uid, 'wpsa_license_notice_dismissed_' . $uid, null === $days_left ? '' : (int) $days_left );

    wp_send_json_success();
}
