/**
 * Speed Analyzer – Schedule tab scripts v0.06
 * Handles dynamic URL rows and reuses the lock icon/tooltip behaviour
 * from the main Tests tab.
 */
jQuery(function ($) {

  /* ============================================
   * Seed data from the first schedule URL row
   * ========================================== */

  (function initScheduleSeed() {
    var $list = $('#wpsa-schedule-url-list');
    if (!$list.length) return;

    var $first = $list.find('.wpsa-schedule-url-row').first();
    if (!$first.length) return;

    var $input = $first.find('input[type="text"]').first();
    var $lock  = $first.find('.wpsa-url-lock').first();

    $list
      .attr('data-home', $input.attr('placeholder') || '')
      .attr('data-lockTooltip', $lock.data('tooltip') || '')
      .attr('data-icon', $.trim($lock.text() || ''));
  })();


  /* ============================================
   * Add new URL row
   * ========================================== */

  function wpsa_addScheduleRow() {
    var $list = $('#wpsa-schedule-url-list');
    if (!$list.length) return;

    var index          = $list.find('.wpsa-schedule-url-row').length;
    var homePlaceholder = $list.attr('data-home') || '';
    var lockTooltip     = $list.attr('data-lockTooltip') || '';
    var iconText        = $list.attr('data-icon') || '';

    function escAttr(str) {
      return String(str).replace(/"/g, '&quot;');
    }

    var rowHtml =
      '<div class="wpsa-schedule-url-row" style="margin-bottom:8px;">' +
        '<div class="wpsa-url-field" style="width:90%;display:flex;align-items:center;">' +
          '<input type="text" class="regular-text" ' +
                 'name="wpsa_schedule_urls[' + index + '][url]" ' +
                 'value="" ' +
                 'placeholder="' + escAttr(homePlaceholder) + '" ' +
                 'style="flex:1;">' +
          '<span class="wpsa-url-lock wpsa-lock-tooltip" style="margin-left:6px;" ' +
                'data-tooltip="' + escAttr(lockTooltip) + '">' +
            escAttr(iconText) +
          '</span>' +
        '</div>' +
      '</div>';

    $list.append(rowHtml);
  }


  /* ============================================
   * Bind "+ Add New" button
   * ========================================== */

  $(document).on('click', '.wpsa-schedule-add-url', function (e) {
    e.preventDefault();
    wpsa_addScheduleRow();
  });
  
    /* ============================================
   * Inline “Schedule saved” message
   * ========================================== */
  (function () {
    var $msg = $('#wpsa-schedule-inline-saved');
    if (!$msg.length) return;

    // If it’s visible, keep it for a while then fade out gently.
    if ($msg.is(':visible')) {
      setTimeout(function () {
        $msg.fadeOut(400);
      }, 8000); // 8 seconds on screen
    }
  })();
  
    /* ============================================
   * Alerts UI: mutually exclusive with summary
   * + threshold suffix/defaults
   * ========================================== */
  (function () {
    var $summaryCb = $('#wpsa_schedule_notify');

    var $regCb = $('#wpsa_schedule_alert_reg_enable');
    var $thCb  = $('#wpsa_schedule_alert_thresh_enable');

    var $regFields = $('#wpsa-schedule-alerts-wrap .wpsa-schedule-alert-reg-fields select, #wpsa-schedule-alerts-wrap .wpsa-schedule-alert-reg-fields input');
    var $thFields  = $('#wpsa-schedule-alerts-wrap .wpsa-schedule-alert-thresh-fields select, #wpsa-schedule-alerts-wrap .wpsa-schedule-alert-thresh-fields input');

    var $thMetric = $('#wpsa_schedule_alert_thresh_metric');
    var $thValue  = $('#wpsa_schedule_alert_thresh_value');
    var $thSuffix = $('#wpsa_schedule_alert_thresh_suffix');

    if (!$summaryCb.length && !$regCb.length && !$thCb.length) return;

    // Track whether user manually edited threshold value (so we don't clobber it on metric change)
    var thDirty = false;
    if ($thValue.length) {
      $thValue.on('input', function () { thDirty = true; });
    }

    function setDisabled($nodes, isDisabled) {
      if (!$nodes || !$nodes.length) return;
      $nodes.prop('disabled', !!isDisabled);
    }

    function getThresholdDefaults(metric) {
      var m = String(metric || 'lcp').toLowerCase();

      if (m === 'fcp') {
        return { defVal: '1.8', step: '0.1', suffix: 's' };
      }
      if (m === 'cls') {
        return { defVal: '0.10', step: '0.01', suffix: '' };
      }
      if (m === 'inp') {
        return { defVal: '200', step: '1', suffix: 'ms' };
      }
      // lcp default
      return { defVal: '2.5', step: '0.1', suffix: 's' };
    }

    function updateThresholdUI(applyDefaultValue) {
      if (!$thMetric.length || !$thValue.length || !$thSuffix.length) return;

      var d = getThresholdDefaults($thMetric.val());

      $thValue.attr('step', d.step);

      $thSuffix.text(d.suffix);

      // Only set a default if requested AND user hasn't edited the field yet
      if (applyDefaultValue && !thDirty) {
        $thValue.val(d.defVal);
      }

      // If empty or invalid on initial load, seed default (without marking dirty)
      if (!thDirty) {
        var v = $.trim(String($thValue.val() || ''));
        if (v === '' || isNaN(parseFloat(v))) {
          $thValue.val(d.defVal);
        }
      }
    }

    function updateLocking() {
      var alertsOn = ($regCb.length && $regCb.prop('checked')) || ($thCb.length && $thCb.prop('checked'));

      // Alerts enabled => summary disabled + unchecked
      if ($summaryCb.length) {
        $summaryCb.prop('disabled', !!alertsOn);
        if (alertsOn) {
          $summaryCb.prop('checked', false);
        }
      }

      // Summary enabled => alerts off (mutual exclusivity)
      if ($summaryCb.length && $summaryCb.prop('checked')) {
        if ($regCb.length) $regCb.prop('checked', false);
        if ($thCb.length)  $thCb.prop('checked', false);
      }

      // Enable/disable the nested controls based on each alert checkbox
      setDisabled($regFields, !($regCb.length && $regCb.prop('checked')));
      setDisabled($thFields,  !($thCb.length  && $thCb.prop('checked')));

      // Threshold suffix should still be correct even when disabled
      updateThresholdUI(false);
    }

    // Events
    if ($thMetric.length) {
      $thMetric.on('change', function () {
        updateThresholdUI(true);
        updateLocking();
      });
    }

    if ($regCb.length) $regCb.on('change', updateLocking);
    if ($thCb.length)  $thCb.on('change', updateLocking);

    if ($summaryCb.length) {
      $summaryCb.on('change', function () {
        // If user turns summary on, alerts must go off immediately
        updateLocking();
      });
    }

    // Init
    updateThresholdUI(true);
    updateLocking();
  })();

  
  /* ============================================
   * Previous scheduled batch – live refresh
   * Option A: poll every 5s until batch completes
   * ========================================== */
  (function () {
    var $wrapper = $('#wpsa-schedule-last-batch-wrapper');
    if (!$wrapper.length) return;

    var status   = String($wrapper.data('status') || 'idle');
    var ajaxUrl  = String($wrapper.data('ajaxUrl') || '');
    var nonce    = String($wrapper.data('nonce') || '');
    var $inner   = $('#wpsa-schedule-last-batch-inner');
    var $banner  = $('#wpsa-schedule-batch-running-msg');

    var pollTimer  = null;
    var pulseTimer = null;

    // Helper: is the Schedule tab actually visible right now?
    // (Avoid relying on an ID that may not exist in the markup.)
    function isScheduleVisible() {
      var $wrapper = $('#wpsa-schedule-last-batch-wrapper');
      if ($wrapper.length) {
        return $wrapper.is(':visible');
      }

      // Fallback: any schedule-specific container that exists on the tab.
      var $fallback = $('#wpsa-schedule-url-list');
      return $fallback.length && $fallback.is(':visible');
    }



    function startPulse() {
      if (!$banner.length) return;

      $banner.show();
      var dim = false;

      // Simple "pulse" by toggling opacity.
      pulseTimer = window.setInterval(function () {
        if (!$banner.is(':visible')) {
          return;
        }
        dim = !dim;
        $banner.css('opacity', dim ? 0.5 : 1);
      }, 600);
    }

    function stopPulse() {
      if (pulseTimer) {
        window.clearInterval(pulseTimer);
        pulseTimer = null;
      }
      if ($banner.length) {
        $banner.css('opacity', 1).hide();
      }
    }

    function stopPolling() {
      if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = null;
      }
    }

       function pollStatus() {
      if (!ajaxUrl || !nonce) return;

      $.post(
        ajaxUrl,
        {
          action: 'wpsa_schedule_batch_status',
          _ajax_nonce: nonce
        }
      ).done(function (resp) {
        if (!resp || !resp.success || !resp.data) {
          return;
        }

        // Always refresh the HTML from the finished-last-batch helper.
        if (typeof resp.data.html === 'string' && $inner.length) {
          $inner.html(resp.data.html);
        }

        // Track latest status from server.
        status = resp.data.running ? 'running' : 'idle';

        // Let server drive banner:
        // If running → show & pulse; if idle → hide banner but keep timer (if Schedule is visible).
        if (resp.data.running) {
          if (!pulseTimer) {
            startPulse();
          } else if ($banner.length && !$banner.is(':visible')) {
            $banner.show().css('opacity', 1);
          }
        } else {
          stopPulse();
        }
      }).fail(function () {
        // On error, just keep the current UI; next tick will try again.
      });
    }



 function startPolling() {
      if (!ajaxUrl || !nonce) return;
      if (pollTimer) return;
      if (!isScheduleVisible()) return;

      // First immediate check, then every 5 seconds.
      pollStatus();
      pollTimer = window.setInterval(pollStatus, 5000);
    }

    // Initialise from server-side status on load.
    if (isScheduleVisible()) {
      if (status === 'running') {
        startPulse();
      }
      startPolling();
    }

    // Pause polling when leaving the Schedule tab, resume when coming back
    $(document)
      .off('click.wpsaScheduleNav', '.wpsa-nav-tile')
      .on('click.wpsaScheduleNav', '.wpsa-nav-tile', function () {
        var href = String($(this).attr('href') || '');
        // crude but reliable: look for tab=schedule in the link
        var goingToSchedule = href.indexOf('tab=schedule') !== -1;

        if (goingToSchedule) {
          // After nav/toggle has applied, check visibility and restart.
          setTimeout(function () {
            if (isScheduleVisible()) {
              if (status === 'running') {
                startPulse();
              }
              startPolling();
            }
          }, 100);
        } else {
          // Leaving Schedule → stop polling + pulse for this page
          stopPulse();
          stopPolling();
        }
      });

  })();
});




