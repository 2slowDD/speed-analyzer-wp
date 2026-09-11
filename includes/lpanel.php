<?php
/**
 * Renders the inner contents of the License panel. v1.21
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wpsa_render_license_panel_ui() {
     
    // ── Stored values ──
    $current_user = get_current_user_id();

    // 1) grab the real stored key (option)…
    $option_key = get_option( 'wpsa_license_key', '' );

    // 2) …and any just‐entered (but invalid) key flashed via transient
    $flash_key  = get_transient( 'wpsa_license_input_' . $current_user );
    if ( false !== $flash_key ) {
        // use the bad input in the text field, but don't treat it as "active"
        $display_key = $flash_key;
        delete_transient( 'wpsa_license_input_' . $current_user );
    } else {
        $display_key = $option_key;
    }

    // ── Licence state (single source of truth) ──
    // F4: $gk_ok / $gk_tier were also true for the local conservative
    // snapshot, so a Gatekeeper outage rendered "License expired". Drive
    // everything from one wpsa_check_quota() call instead.
    $quota  = wpsa_check_quota( 'ttfb' );
    $tier   = isset( $quota['tier'] ) ? (string) $quota['tier'] : 'free';
    $state  = isset( $quota['state'] ) ? (string) $quota['state'] : '';
    $status = isset( $quota['status'] ) ? (string) $quota['status'] : '';
    $sites  = isset( $quota['sites'] ) && is_array( $quota['sites'] ) ? $quota['sites'] : array();
    $expires_at = isset( $quota['expires_at'] ) ? (string) $quota['expires_at'] : '';

    // ── Plan name and days number, shared with the licence notice ──
    // The stored values are read after the check above, because that check may
    // just have rewritten them: the expiry and its grace date on a renewal, and
    // the plan that lapsed on the first answer that reports it. The days never
    // come from the service's days_left, which stays at 0 for the whole grace week.
    $last_paid_tier = (string) get_option( 'wpsa_last_paid_tier', '' );
    $expiration     = (string) get_option( 'wpsa_license_expiration', '' );
    $grace_until    = (string) get_option( 'wpsa_license_grace_until', '' );
    $display        = wpsa_license_display( $state, $tier, $last_paid_tier, $expiration, $grace_until );
    $label          = $display['label'];
    $days           = $display['days'];

    // ── Build status text ──
    // Copy is fixed by the spec's §5.3 table; do not improvise.
    if ( 'unknown' === $state ) {
        $status_text = $label; // post-upgrade, pre-first-check: no date, no warning
    } elseif ( 'sold' === $state ) {
        $status_text = __( 'Your licence has been paid for but not yet delivered. Please contact support.', 'speed-analyzer' );
    } elseif ( 'not_found' === $state ) {
        $status_text = __( "We don't recognise that licence key — check it for typos", 'speed-analyzer' );
    } elseif ( in_array( $state, array( 'inactive', 'disabled' ), true ) ) {
        $status_text = __( 'This licence is no longer active', 'speed-analyzer' );
    } elseif ( 'grace' === $state ) {
        $status_text = sprintf(
            /* translators: 1: plan name, 2: days of access remaining */
            __( '%1$s — licence expired; %2$d days of access remaining', 'speed-analyzer' ),
            $label, (int) $days
        );
    } elseif ( 'expired' === $state ) {
        $status_text = sprintf(
            /* translators: %s: plan name */
            __( 'Licence expired — renew to restore %s', 'speed-analyzer' ),
            $label
        );
    } elseif ( 'active' === $state && null !== $days && $days <= 10 ) {
        $status_text = sprintf(
            /* translators: 1: plan name, 2: days until expiry */
            __( '%1$s — expires in %2$d days', 'speed-analyzer' ),
            $label, (int) $days
        );
    } else {
        $status_text = $label;
    }

    if ( 'stale' === $status ) {
        $status_text .= ' ' . __( '(recently verified)', 'speed-analyzer' );
    } elseif ( 'unverified' === $status ) {
        $status_text .= ' ' . __( '— could not reach the licence service; showing your last known plan', 'speed-analyzer' );
    }

    // Icon mirrors the same state: a warning for anything that needs the
    // customer's attention, a check for a confirmed paid licence, none for
    // free/unknown. Not specified by the brief; kept for visual continuity
    // with the pre-B4 panel and driven only by $state, never by copy.
    if ( in_array( $state, array( 'sold', 'not_found', 'inactive', 'disabled', 'grace', 'expired' ), true ) ) {
        $icon = '<span class="icon" style="color:#d32f2f;">⚠️</span>';
    } elseif ( 'active' === $state ) {
        $icon = '<span class="icon" style="color:#388e3c;">✅</span>';
    } else {
        $icon = '';
    }

    // D16: a site beyond the licence's cap must be told why, not silently blocked.
    $over_cap_notice = wpsa_license_over_cap_notice( $quota );

    // ── License slots (from the same /check response — the tracker call is retired) ──
    if ( 'free' !== $tier && isset( $sites['max'], $sites['remaining'] ) ) {
        $slots_limit     = (int) $sites['max'];
        $slots_remaining = (int) $sites['remaining'];
    } else {
        $slots_limit     = null;
        $slots_remaining = null;
    }

    // ── Which button? ──
    // We only want to show “Deactivate” when there’s a _real_ saved key,
    // not merely a flash‐transient of an invalid input.
    $stored_key     = get_option( 'wpsa_license_key', '' );
    $show_deactivate = ! empty( $stored_key );

    $button_label = $show_deactivate ? 'Deactivate License' : 'Activate License';
    $button_name  = $show_deactivate ? 'wpsa_deactivate_license' : 'wpsa_activate_license';
    $button_class = $show_deactivate ? 'button-link-delete'      : 'button-primary';
    ?>

     <div class="wrap" style="margin:0 auto;">    
      <h2 class="wpsa-module-title"><?php esc_html_e( 'License Information','speed-analyzer' );?></h2>

      <form id="wpsa-license-form"
            method="POST"
            action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
          
          <?php
        // Show notices *inside* the card
        $transient_key = 'wpsa_license_notices_' . get_current_user_id();
        $msgs          = get_transient( $transient_key );
        
        if ( is_array( $msgs ) ) {
            echo '<div class="wpsa-license-notices">';
            foreach ( $msgs as $m ) {
                $cls = $m['type'] === 'error'
                    ? 'wpsa-license-error-headline'
                    : 'wpsa-license-success-headline';
        
                printf(
                    '<div class="%1$s">%2$s</div>',
                    esc_attr( $cls ),
                    esc_html( $m['message'] )
                );
            }
            echo '</div>';
        
            delete_transient( $transient_key );
        }
        ?>

          
          
        <?php
        // D16: a site beyond the licence's cap must be told why, not silently blocked.
        if ( '' !== $over_cap_notice ) {
            printf(
                '<div class="notice notice-warning inline"><p>%s</p></div>',
                esc_html( $over_cap_notice )
            );
        }
        ?>
        <input type="hidden" name="action" value="wpsa_save_license">
        <?php wp_nonce_field( 'wpsa_license_action','wpsa_license_nonce' );?>

        <table class="form-table" style="max-width:600px;">
          <tr valign="top">
            <th scope="row">
              <label for="wpsa_license_key"><?php esc_html_e( 'License Key','speed-analyzer' );?></label>
              <span class="custom-tooltip"
                    data-tooltip="<?php esc_attr_e(
                      'Enter your purchased Speed Analyzer license key here.',
                      'speed-analyzer'
                    );?>">?</span>
            </th>
            <td>
              <input id="wpsa_license_key"
                       name="wpsa_license_key"
                       class="regular-text"
                       value="<?php echo esc_attr( $display_key );?>"
                     placeholder="<?php esc_attr_e(
                       'XXXX-XXXXXXXXXXXX','speed-analyzer'
                     );?>">
            </td>
          </tr>
          <tr valign="top">
            <th scope="row"><?php esc_html_e( 'License','speed-analyzer' );?></th>
            <td>
              <button type="submit"
                      name="<?php echo esc_attr( $button_name );?>"
                      class="button <?php echo esc_attr( $button_class );?>">
                <?php echo esc_html( $button_label );?>
              </button>
            </td>
          </tr>
          <tr valign="top">
              <th scope="row"><?php esc_html_e( 'License Status','speed-analyzer' );?></th>
              <td>
                <?php
                  // Allow only <strong> tags inside our status line
                  echo wp_kses(
                    $status_text,
                    [
                      'strong' => [],
                    ]
                  );
                ?>
                <?php echo wp_kses( $icon, array( 'span' => array( 'class' => array(), 'style' => array() ) ) ); ?>
                <?php if ( 'sold' === $state ) : ?>
                <a href="<?php echo esc_url( 'https://wpservice.pro/contact/' ); ?>" target="_blank" rel="noopener noreferrer" class="button"><?php esc_html_e( 'Contact', 'speed-analyzer' ); ?></a>
                <?php endif; ?>
              </td>
            </tr>

                   <tr valign="top">
              <th scope="row"><?php esc_html_e( 'License Slots','speed-analyzer' );?></th>
              <td>
                <?php
                if ( 'free' === $tier ) {
                    esc_html_e( 'N/A for Free plan', 'speed-analyzer' );
                } elseif ( null !== $slots_limit ) {
                    printf(
                        /* translators: 1: total slots, 2: remaining slots */
                        esc_html__( 'Slots: %1$s, Remaining: %2$s', 'speed-analyzer' ),
                        esc_html( $slots_limit ),
                        esc_html( $slots_remaining )
                    );
                } else {
                    esc_html_e( 'N/A', 'speed-analyzer' );
                }
                ?>
              </td>
            </tr>


          <tr valign="top">
            <th scope="row"><?php esc_html_e( 'Expiration Date','speed-analyzer' );?></th>
            <td><?php
              if ( '' !== $expires_at ) {
                  echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $expires_at ) ) );
              } else {
                  esc_html_e( 'No expiry', 'speed-analyzer' );
              }
            ?></td>
          </tr>
        </table>

        <div style="text-align:center;margin-top:1em; padding-bottom: 10px;">
          <button type="button"
                  id="wpsa-upgrade-license"
                  class="button button-primary wpsa-button-upgrade"
                  onclick="window.open('https://wpservice.pro/our-products/speed-analyzer-wp-plugin/#licenses','_blank')">
            <?php esc_html_e( 'Upgrade License', 'speed-analyzer' );?>
          </button>
        </div>

      </form>
    </div><!-- .wrap -->

    <?php
}

/**
 * D16: a site beyond its licence's cap must be told why, not silently
 * blocked. Pure function so the mutation check (AC-P14) can exercise it
 * directly — no WordPress globals, no output.
 *
 * @param array $quota The array returned by wpsa_check_quota().
 * @return string The finished notice sentence, or '' when the site is not over cap.
 */
function wpsa_license_over_cap_notice( array $quota ) {
    $sites = isset( $quota['sites'] ) && is_array( $quota['sites'] ) ? $quota['sites'] : array();

    if ( empty( $quota['allowed'] ) && ! empty( $sites ) && empty( $sites['active'] )
         && isset( $sites['used'], $sites['max'] ) && $sites['used'] >= $sites['max'] && $sites['max'] > 0 ) {
        return sprintf(
            /* translators: %d: number of sites the plan allows */
            _n(
                'This licence is already in use on its %d allowed site. Deactivate it on another site, or upgrade to run more.',
                'This licence is already in use on its %d allowed sites. Deactivate it on another site, or upgrade to run more.',
                (int) $sites['max'], 'speed-analyzer'
            ),
            (int) $sites['max']
        );
    }

    return '';
}
