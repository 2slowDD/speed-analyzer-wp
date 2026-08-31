/**
 * Speed Analyzer — Admin UI widgets v0.01
 * - Cap dropdown (10-per-window) slider + copy
 * - 101+ tests review prompt (WP-native jQuery UI Dialog)
 *
 * Requires: jQuery (+ jQuery UI dialog on this admin page)
 */
(function ($) {
  "use strict";

  // ────────────────────────────────────────────────────────────────
  // Cap dropdown helpers
  function parseItems($dd) {
    var raw = $dd.attr("data-items") || "[]";
    try {
      var arr = JSON.parse(raw);
      return Array.isArray(arr) ? arr : [];
    } catch (e) {
      return [];
    }
  }

  function setExpanded($dd, expanded) {
    var $btn = $dd.find(".wpsa-dd-toggle").first();
    var $menu = $dd.find(".wpsa-dd-menu").first();

    $btn.attr("aria-expanded", expanded ? "true" : "false");
    $menu.attr("aria-hidden", expanded ? "false" : "true");

    if (expanded) {
      $menu.addClass("is-open");
    } else {
      $menu.removeClass("is-open");
    }
  }

  function renderWindow($dd, startIndex) {
    var items = parseItems($dd);
    var $list = $dd.find(".wpsa-dd-list").first();
    var $slider = $dd.find(".wpsa-dd-slider").first();
    var $window = $dd.find(".wpsa-dd-window").first();

    var perPage = 10;
    var total = items.length;

    if (!total) {
      $list.empty().append($("<li/>").text("No items."));
      $window.text("0–0 of 0");
      $slider.prop("disabled", true);
      return;
    }

    // Clamp startIndex to 0..(last page start)
    var maxStart = Math.max(0, (Math.ceil(total / perPage) - 1) * perPage);
    startIndex = Math.max(0, Math.min(startIndex, maxStart));

    // Slider setup
    $slider.prop("disabled", false);
    $slider.attr("min", 0);
    $slider.attr("max", maxStart);
    $slider.attr("step", perPage);
    $slider.val(startIndex);

    var endIndex = Math.min(startIndex + perPage, total);
    $window.text(startIndex + 1 + "–" + endIndex + " of " + total);

    // Render items in current window
    $list.empty();
    for (var i = startIndex; i < endIndex; i++) {
      $list.append($("<li/>").text(String(items[i])));
    }
  }

  function copyTextToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }

    return new Promise(function (resolve, reject) {
      try {
        var ta = document.createElement("textarea");
        ta.value = text;
        ta.setAttribute("readonly", "readonly");
        ta.style.position = "fixed";
        ta.style.left = "-9999px";
        document.body.appendChild(ta);
        ta.select();
        var ok = document.execCommand("copy");
        document.body.removeChild(ta);
        ok ? resolve() : reject(new Error("Copy failed"));
      } catch (e) {
        reject(e);
      }
    });
  }

  function initCapDropdown($dd) {
    if ($dd.data("wpsa-dd-initialized")) return;
    $dd.data("wpsa-dd-initialized", true);

    var $btn = $dd.find(".wpsa-dd-toggle").first();
    var $menu = $dd.find(".wpsa-dd-menu").first();
    var $slider = $dd.find(".wpsa-dd-slider").first();
    var $copy = $dd.find(".wpsa-dd-copy").first();

    // Initial render (page 1)
    renderWindow($dd, 0);
    setExpanded($dd, false);

    $btn.on("click", function () {
      var expanded = $btn.attr("aria-expanded") === "true";
      setExpanded($dd, !expanded);
    });

    $slider.on("input change", function () {
      renderWindow($dd, parseInt($slider.val(), 10) || 0);
    });

    $copy.on("click", function () {
      var items = parseItems($dd);
      var text = items.join("\n");

      copyTextToClipboard(text)
        .then(function () {
          var $window = $dd.find(".wpsa-dd-window").first();
          var original = $window.text();
          $window.text("Copied!");
          setTimeout(function () {
            $window.text(original);
          }, 900);
        })
        .catch(function () {
          // silent fail
        });
    });

    // Close on outside click
    $(document).on("click", function (e) {
      if (!$dd.is(e.target) && $dd.has(e.target).length === 0) {
        setExpanded($dd, false);
      }
    });

    // Close on ESC when focused in dropdown
    $menu.on("keydown", function (e) {
      if (e.key === "Escape") {
        setExpanded($dd, false);
        $btn.trigger("focus");
      }
    });
  }

  // ────────────────────────────────────────────────────────────────
  // Review prompt (101+ tests) — WP-native modal (jQuery UI Dialog)
  // Rules:
  // - Show when max test # >= 101
  // - "Leave a review" => opens wp.org reviews + saves voted flag
  // - "Already voted"  => saves voted flag
  // - "Maybe next time" => closes only (NO flag)
  function wpsaInitReviewPrompt101() {
    var VOTED_KEY = "wpsa_review_voted";
    var LEGACY_DISMISS_KEY = "wpsa_review_prompt_dismissed_max_testno"; // legacy / buggy

    // If user already voted/confirmed, never show again
    if (localStorage.getItem(VOTED_KEY) === "1") {
      return;
    }

    // Remove legacy key so old behavior doesn't suppress the dialog forever
    if (localStorage.getItem(LEGACY_DISMISS_KEY) !== null) {
      localStorage.removeItem(LEGACY_DISMISS_KEY);
    }

    // Require jQuery UI dialog
    if (!$.ui || !$.ui.dialog) {
      return;
    }

    function getMaxTestNo() {
      // 1) Prefer hidden run meta if present
      var $meta = $("#wpsa-run-meta");
      var metaNo = parseInt($meta.attr("data-test-no"), 10);
      if (!isNaN(metaNo) && metaNo > 0) return metaNo;

      // 2) Parse from the UI line: "Test #736, tested URL: ..."
      var txt = $("#wpsa-tested-url").text() || "";
      var m = txt.match(/Test\s*#\s*(\d+)/i);
      if (m && m[1]) return parseInt(m[1], 10);

      // 3) Fallback: scan the page for "Test #N"
      var body = document.body && document.body.innerText ? document.body.innerText : "";
      var m2 = body.match(/Test\s*#\s*(\d+)/i);
      if (m2 && m2[1]) return parseInt(m2[1], 10);

      return 0;
    }

    var maxTestNo = getMaxTestNo();
    if (!maxTestNo || maxTestNo < 101) {
      return;
    }

    // Build dialog node (once)
    var $dlg = $("#wpsa-review-prompt-101");
    if (!$dlg.length) {
      $dlg = $(
        '<div id="wpsa-review-prompt-101" title="Quick favor?">' +
          "<p><strong>You’ve made " + maxTestNo + " tests.</strong></p>" +
          "<p>If you like Speed Analyzer, can you please leave a quick review? It would mean a world to me.</p>" +
          "<p style='margin-top:10px; opacity:0.85;'>It takes ~30 seconds and helps the plugin grow. Thank you!</p>" +
        "</div>"
      ).appendTo("body");
    } else {
      $dlg.find("strong").first().text("You’ve saved " + maxTestNo + " tests.");
    }

    $dlg.dialog({
      modal: true,
      width: 620,
      resizable: false,
      closeOnEscape: true,
      dialogClass: "wpsa-review-dialog",
      buttons: {
        "Leave a review": function () {
          localStorage.setItem(VOTED_KEY, "1");
          window.open(
            "https://wordpress.org/support/plugin/speed-analyzer/reviews/#new-post",
            "_blank",
            "noopener"
          );
          $(this).dialog("close");
        },
        "Already voted": function () {
          localStorage.setItem(VOTED_KEY, "1");
          $(this).dialog("close");
        },
        "Maybe next time": function () {
          // IMPORTANT: no flags, just close
          $(this).dialog("close");
        }
      },
      open: function () {
        // Style buttons WP-admin-ish
        var $pane = $(this).parent().find(".ui-dialog-buttonpane");
        var $btns = $pane.find("button");

        $btns.addClass("button");
        $btns.eq(0).addClass("button-primary");     // Leave a review
        $btns.eq(1).addClass("button-secondary");   // Already voted
        $btns.eq(2).addClass("button-secondary");   // Maybe next time
      }
    });
  }

  // ────────────────────────────────────────────────────────────────
  // Init
  $(function () {
    $(".wpsa-dd").each(function () {
      initCapDropdown($(this));
    });

    wpsaInitReviewPrompt101();
  });

})(jQuery);
