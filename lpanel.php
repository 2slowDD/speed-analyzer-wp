<?php
/**
 * Renders the inner contents of the License panel. v1.21
 */

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

    // 3) remember saved tier & expiration (untouched by invalid attempts)
    $saved_tier     = get_option( 'wpsa_saved_tier', 'free' );
    $expiry_date    = get_option( 'wpsa_license_expiration', '' );
    $last_paid_tier = get_option( 'wpsa_last_paid_tier', '' );

    // ── Days remaining ──
    $now  = time();
    $days = $expiry_date
      ? max( 0, ceil( ( strtotime( $expiry_date ) - $now ) / DAY_IN_SECONDS ) )
      : 0;

        // ── Decide tier ──
    // IMPORTANT: a stored key does not always mean an active subscription.
    // If Gatekeeper returns tier=free for a stored key, treat it as "expired".
    $license_state = 'free'; // 'active' | 'expired' | 'free'

    $gk_tier = '';
    $gk_ok   = false;
    $tier = 'free';

   if ( $option_key ) {

    // Ask Gatekeeper via the shared quota function so we also sync options (saved tier / last paid tier).
    $quota = wpsa_check_quota( 'ttfb' );

    if ( is_array( $quota ) && isset( $quota['tier'] ) ) {
        $gk_ok   = true;
        $gk_tier = (string) $quota['tier'];
    }

    // Gatekeeper says free for a stored key that previously had premium → expired subscription messaging.
    if ( $gk_ok && $gk_tier === 'free' && $last_paid_tier !== '' ) {
        $tier          = 'free';
        $license_state = 'expired';

    } elseif ( $gk_ok && $gk_tier !== '' ) {
        // Gatekeeper is the source of truth for the License panel display.
        $tier          = $gk_tier;
        $license_state = ( $tier !== 'free' ) ? 'active' : 'free';

    } else {
        // Gatekeeper unreachable: fall back to local tier logic (offline/grace/etc.)
        $tier          = wpsa_get_license_tier();
        $license_state = ( $tier !== 'free' ) ? 'active' : 'free';
    }

} elseif ( $days > 0 ) {
    // Deactivated but still within local grace period
    $tier          = $saved_tier;
    $license_state = ( $tier !== 'free' ) ? 'active' : 'free';

} else {
    $tier          = 'free';
    $license_state = 'free';
}


    // ── Map to labels ──
    $labels = [
      'free'     => 'Free',
      'premium1' => 'PRO',
      'premium2' => 'BUSINESS',
      'premium3' => 'AGENCY',
    ];
    $label = $labels[ $tier ] ?? ucfirst( $tier );

       // ── Build status text ──
        if ( isset( $license_state ) && $license_state === 'expired' ) {
            $status_text = 'License expired – Free (please renew).';
            $icon        = '<span class="icon" style="color:#d32f2f;">⚠️</span>';
        
        } elseif ( $tier !== 'free' ) {
        
            // Premium is active (Gatekeeper tier is source of truth).
            // Only show “days remaining” if you actually have local expiry populated.
            if ( $days > 0 ) {
                if ( $option_key ) {
                    $status_text = sprintf( '%s (%d days remaining)', $label, $days );
                } else {
                    $status_text = sprintf(
                        '<strong>%s</strong> plan functionality for the remaining <strong>%d</strong> days, then reverting to the <strong>FREE</strong> plan.',
                        $label,
                        $days
                    );
                }
            } else {
                $status_text = $label;
            }
        
            $icon = '<span class="icon" style="color:#388e3c;">✅</span>';
        
        } else {
            $status_text = 'Free';
            $icon        = '';
        }


         // ── License slots (via your central tracker on wpservice.pro) ──
    // For Free tier we don't care about slots at all.
    if ( $display_key && 'free' !== $tier ) {

        // build a real status URL against your central tracker
       $args = array(
            'license_key' => $display_key,
            'site_url'    => home_url(),
        );
        $status_url = 'https://wpservice.pro/wp-json/wpsa/v1/status?' . http_build_query( $args );
                $resp       = wp_remote_get(
                    $status_url,
                    array(
                        'timeout' => 5,
                    )
                );

       if (
            ! is_wp_error( $resp )
            && 200 === (int) wp_remote_retrieve_response_code( $resp )
            && ( $data = json_decode( wp_remote_retrieve_body( $resp ), true ) )
        ) {
            $slots_limit     = (int) ( $data['maxSites']       ?? wpsa_get_license_slots_limit( $tier ) );
            $slots_remaining = (int) ( $data['remainingSites'] ?? $slots_limit );
        } else {
            // fallback: assume full allotment on error
            $slots_limit     = wpsa_get_license_slots_limit( $tier );
            $slots_remaining = $slots_limit;
        }
    } else {
        // Free tier or no key → no applicable slots
        $slots_limit     = 0;
        $slots_remaining = 0;
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
                <?php echo $icon;?>
              </td>
            </tr>

                   <tr valign="top">
              <th scope="row"><?php esc_html_e( 'License Slots','speed-analyzer' );?></th>
              <td>
                <?php
                if ( 'free' === $tier ) {
                    esc_html_e( 'N/A for Free plan', 'speed-analyzer' );
                } else {
                    printf(
                        /* translators: 1: total slots, 2: remaining slots */
                        esc_html__( 'Slots: %1$s, Remaining: %2$s', 'speed-analyzer' ),
                        esc_html( $slots_limit ),
                        esc_html( $slots_remaining )
                    );
                }
                ?>
              </td>
            </tr>


          <tr valign="top">
            <th scope="row"><?php esc_html_e( 'Expiration Date','speed-analyzer' );?></th>
            <td><?php echo esc_html( wpsa_get_license_expiration_date() );?></td>
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
