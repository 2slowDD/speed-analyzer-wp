# Changelog

All notable changes to Speed Analyzer are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.19.0]
### Changed
- **Total Blocking Time replaces INP on every lab measurement**, matching what Google PageSpeed Insights now reports. Affects the score tiles, the Compare tab and its trends chart, the PDF report, the scheduled email and the editor column.
- TBT is graded on Lighthouse's per-device thresholds - mobile 200/600 ms, desktop 150/350 ms - taken from the audit's own scoring curves. Every other metric keeps a single threshold pair; TBT is the only one Lighthouse scores differently per form factor.
- The scheduled-alert metric dropdown now offers TBT. An alert configured on INP before this release keeps working: the stored value is accepted and migrated on read, so no re-saving is needed.
- Advice text for this metric now describes main-thread blocking and long tasks rather than interaction latency.

### Fixed
- **The Compare tab showed N/A for LCP, FCP, CLS and TBT on tests run with 1.19.0.** Total Blocking Time is written on its own line in the results log, but the writer sanitised the two-line payload with a filter that collapses newlines, welding both lines into one that neither parser could read. Scheduled runs were unaffected. Rows already recorded keep the welded line and still read N/A; re-running the test records it correctly.
- Re-logging a test no longer leaves a stale Total Blocking Time line behind in that block.
- The note explaining that pre-1.19 interaction data was measured as INP now aligns with the results table instead of the page edge.
- Section 8.4 (Performance & Diagnostics) in the Conclusion showed an empty `💡 Advice:` for Total Blocking Time, and no threshold sentence. The copy existed but was read under the wrong key — TBT is graded per device, so its text lives under device-specific entries. LCP, FCP and CLS were unaffected.
### Fixed
- **The Core Web Vitals (field) block showed `--` for every metric on pages where real user data existed.** When CrUX publishes a page-level record that carries LCP, CLS, FCP and TTFB but no INP, the three-of-three assessment cannot be computed and is reported as N/A - but the p75 values are real. The results-log reader matched only PASSED or FAILED, so it discarded the whole line, including four measurements it had just written. The assessment itself still reads N/A in that case; no verdict is invented.
- The Posts/Pages speed column could not read the Core Web Vitals scope label written for page-scope results, so the column stayed blank for passing pages.
- Millisecond values above 1000 were parsed as decimals when PageSpeed Insights formatted them with a thousands separator, so "1,910 ms" was read as 1.91 ms. This affected the results log as well as the on-screen colour coding.
- A Total Blocking Time of exactly 0 ms is now recorded rather than reported as N/A. Zero is a normal result on a well-optimised page.

### Internal
- Plugin URLs and paths now resolve from `WPSA_PLUGIN_URL` / `WPSA_PLUGIN_DIR`, anchored to the main plugin file, instead of `plugin_dir_url( __FILE__ )` which resolved relative to whichever file happened to call it.
- Source files moved out of the plugin root: PHP into `includes/`, scripts into `assets/js/`, stylesheets into `assets/css/`, the logo into `assets/img/`. `wp-speed-analyzer.php` and `readme.txt` stay at the root.
- `.gitattributes` freezes line endings (`* -text`), and `admin-styles.css` - which had mixed CRLF/LF since before 1.18.6 - is now internally uniform.

### Notes
- **The Core Web Vitals (field) assessment still reports INP.** That block shows real-user CrUX data, where Google defines the assessment as LCP + INP + CLS, and no field equivalent of TBT exists. TBT is Google's lab *proxy* for INP, not a replacement for it.
- Results recorded before 1.19.0 keep their INP values in the raw log. They are not shown in the TBT column, because the two are different metrics on different scales; the Compare tab explains this inline.
- The results-log format stays readable by 1.18.6, so downgrading does not lose earlier rows.

## [1.18.6]
### Added
- Chart.js 4.4.1 is now bundled at `assets/js/chart.umd.min.js`; no admin script is loaded from a third-party CDN any more.
- `wpsa_debug_log()` in `helpers.php` — a single WP_DEBUG + WP_DEBUG_LOG gated sink that all plugin diagnostics route through.
- `wpsa_sanitize_text_deep()` in `helpers.php` — recursive sanitizer applied to decoded JSON and structured POST payloads.
- `tests/debug-log-gate-harness.php` — proves the debug sink stays silent unless the site owner opts in.

### Changed
- PDF report generation is now staged (container -> canvas -> PDF) with a repaint yield and progress text between steps, so the browser stays responsive while the report is built.
- The PDF activity indicator animates transform/opacity only, so it keeps moving on the compositor even while the main thread is busy rendering.
- html2canvas scale now adapts to report length, so long reports no longer allocate an oversized canvas. The budget is computed against the width html2pdf actually rasterises (the page inner width, 763px) rather than the admin container width, so reports up to roughly eight pages still render at full scale and are byte-identical to 1.18.5; only longer reports step down.
- All `error_log()` calls in `schedule.php` and `modules.php` now route through `wpsa_debug_log()`.
- The version-lockstep test derives the version from the plugin header instead of pinning a literal, so it no longer needs editing on every release.

### Fixed
- Every Plugin Check error resolved: output escaping, input sanitization and unslashing, `$wpdb->prepare()` visibility, `translators:` comments, direct-file-access protection in `lpanel.php`, `unlink()` -> `wp_delete_file()`, and `date()` -> `gmdate()`.
- Restored consistent line endings in files that had mixed CRLF/LF.

### Security
- Plugin debug output is no longer written to production error logs by default.

### Compatibility
- Tested up to WordPress 7.1.

---

## [1.18.5]
### Changed
- Replaced Perfmatters mentions in the generated PDF report with AI Assets Scanner and linked the AI Assets Scanner product page.

---

## [1.18.3]
### Added
- PDF generation now shows a progress notice with an animated activity indicator while the report is being built before the browser save prompt appears.

### Fixed
- PDF quota accounting now waits until server-side PDF report markup is built successfully before consuming the daily PDF limit.

---
## [1.18.2]
### Added
- Code Unloader and AI Assets Scanner product cards below the admin ratings and reviews panel.
- Public README badges for CI, license, and version.

### Changed
- Updated WordPress compatibility metadata to `Tested up to: 7.0`.

---
## [1.18.1]
### Changed
- Replaced text with wpsa-admin-cwv dahicons 
- Added the Code Unloader reference

### Fixed
- Removed wpsa_speed column-wpsa_speed from the product pages

---

## [1.18] - 2025
### Added
- Response headers functionality in the Module 1 dropdown section

### Changed
- License manager reworked

### Fixed
- Load test # bug where N/A values in Module 2 loaded former test data instead of displaying N/A

---

## [1.17.9]
### Added
- CWV status column to Posts/Pages list
- CWV status section to PDF report
- Schedule tests alert emails: regression and absolute threshold functions

### Fixed
- Bug where CWV scope was returned as URL even when scope was `origin`

---

## [1.17.8]
### Fixed
- CWV display on the main test screen

---

## [1.17.7]
### Added
- Core Web Vitals (CWV) test metrics
- 100+ tests info message

### Fixed
- Schedule tests "Add another" button

---

## [1.17.6]
### Added
- Speed Analyzer column in the Posts and Pages list showing the latest performance score, CWV metrics, and direct links to re-test or open reports

### Changed
- Minor UI tweaks

---

## [1.17.5]
### Added
- Module 3 — mobile and desktop screenshot images of the tested URL
- Diagnostics for Module 3 added to scheduled test emails

### Changed
- Scheduled test email redesigned
- Module order rearranged
- PDF report updated to include screenshot images

---

## [1.17.3]
### Added
- DB size to the tested metrics
- Second "Generate PDF report" button on the main screen

### Fixed
- Module 5: Diagnostics section style was misaligned

---

## [1.17.2]
### Fixed
- Module 5: Diagnostics section was not loading
- Minor styling improvements

---

## [1.17.1]
### Added
- `S` badge on `#wpsa-tested-url` when loading results from Scheduled tests
- Direct link from Scheduled tests view to the Compare Results section

---

## [1.17]
### Added
- Schedule tests functionality — run audits on a timetable
- Test results delivery via email
- Compare Results filtering reworked for scheduled (`S`) tests

---

## [1.16.6]
### Fixed
- Module 6 Recommendations section icons
- PDF report page 6 tweaks
- Prevent running a new test before the current one finishes

---

## [1.16.5]
### Added
- CLS (Cumulative Layout Shift) metric
- INP (Interaction to Next Paint) metric

### Changed
- Module 7 Conclusion section UI tweaked
- PDF report updated

---

## [1.16.4]
### Added
- PSI Insights panel to Module 2 and 3

### Changed
- Compare two tests chart-card reorganized
- PDF report styling tweaked

### Removed
- External admin notices from the main plugin page (CSS suppression)

---

## [1.16.3]
### Added
- Load test by # functionality
- Diagnostics section expanded to show up to top 10 items

### Changed
- Compare Results charts rearranged
- PDF report tweaked
- Other minor tweaks

---

## [1.16.2]
### Added
- Onload JS and Onload CSS metrics

---

## [1.16.1]
### Fixed
- N/A now shown instead of 0 in Compare Results when data is absent
- Bug where each test counted as two toward the daily limit

---

## [1.16]
### Added
- Compare Results (A/B testing) functionality

### Fixed
- PDF report: Persistent Object Cache not showing as present on some tests

---

## [1.15.1]
### Changed
- Minor visual change on Module 1 (green notice only shown when relevant)

---

## [1.15]
### Added
- Module 3 point 4.5: number of active plugins, PHP version, and DB server version
- Above data included in PDF report

### Changed
- Cloudflare Workers updated for different server region collection

---

## [1.14]
### Added
- Top 10 autoloaded options by size in Module 3
- Agency plan: branding options for PDF report (custom header, optional CTA box)

### Fixed
- Minor bugs

---

## [1.13]
### Fixed
- Modules 2 and 5 not finishing properly on slower websites

---

## [1.12]
### Added
- Premium plan perks
- Additional Cloudflare Workers for redundancy

---

## [1.11]
### Added
- Additional worker and API key for redundancy

### Fixed
- Minor bug fixes

---

## [1.10]
### Added
- PDF reporting with color coding, explanations, and guide links

### Changed
- More robust Cloudflare Workers code
- Minor tweaks and style changes

---

## [1.09]
### Added
- Left and right admin sidebars
- License panel outline

---

## [1.08]
### Fixed
- Daily limit counting reworked to prevent double reduction

---

## [1.07]
### Changed
- Plugin slug changed
- External service disclosure added to readme
- Minor fixes in `helpers.php` and `modules.php`

---

## [1.06]
### Changed
- Version bumped to avoid trademark issues for WordPress.org release
