# Speed Analyzer - WordPress Plugin

<p align="center">
  <img alt="CI Passing" src="https://img.shields.io/badge/CI-passing-brightgreen?style=for-the-badge">
  <a href="https://www.gnu.org/licenses/gpl-3.0.html"><img alt="License: GPL-3.0-or-later" src="https://img.shields.io/badge/License-GPL--3.0--or--later-green?style=for-the-badge"></a>
  <img alt="Version 1.19.0" src="https://img.shields.io/badge/Version-1.19.0-blue?style=for-the-badge">
</p>

**In-dashboard website performance auditing for WordPress.**
Version: 1.19.0 | Requires: WordPress 5.0+, PHP 7.0+ | License: GPL v3

> Official plugin page: [wpservice.pro/our-products/speed-analyzer-wp-plugin](https://wpservice.pro/our-products/speed-analyzer-wp-plugin/)

---

## What It Does

Speed Analyzer gives admins a one-click speed audit directly in the WordPress dashboard — no external account sign-up required. Results are powered by Cloudflare Workers (TTFB) and the Google PageSpeed Insights API (LCP/FCP/CLS/TBT), proxied through a managed service so no API key configuration is needed.

**Modules:**

| # | Module | Metrics |
|---|--------|---------|
| 1 | Server TTFB | Time to First Byte, cache status, response headers |
| 2 | Page Asset Summary | Request count, page size, JS/CSS onload timing |
| 3 | Performance & Diagnostics | LCP, FCP, CLS, TBT, mobile/desktop screenshots |
| 4 | Autoloaded Options | Total size, top-10 largest options |
| 5 | System Info | Active plugins, PHP version, DB server & size, persistent object cache |
| 6 | Summary & Recommendations | Color-coded recommendations |
| 7 | Conclusion | Readiness assessment |

**Additional features:**
- PDF report generation (color-coded, with explanations and guide links)
- Compare Results — A/B test any two previous results
- Schedule tests — automated audits with email delivery and alert thresholds
- Speed Analyzer column in Posts/Pages list with CWV status
- Agency branding for PDF reports (Agency plan)

---

## Installation

1. Upload the `speed-analyzer` folder to `/wp-content/plugins/`.
2. Activate via **Plugins** in the WordPress admin.
3. Go to **Tools → Speed Analyzer** to run your first audit.
4. (Optional) Activate a license at **Speed Analyzer → License** for higher quotas.

---

## Usage Tiers

All tiers include full feature access — quotas are enforced remotely by the hosted service.

| Tier | Tests / Day | PDF Reports / Day |
|------|------------|-------------------|
| Free | 10 | 1 |
| Pro | 30 | 3 |
| Business | 100 | 10 |
| Agency | 700 | 100 |

Upgrade at: [wpservice.pro/our-products/speed-analyzer-wp-plugin/#licenses](https://wpservice.pro/our-products/speed-analyzer-wp-plugin/#licenses)

---

## External Services

This plugin delegates all testing to remote services. **No personal data is collected or stored.**

| Service | Used For | Data Sent |
|---------|----------|-----------|
| Cloudflare Workers (`globalwpspeed.dalibord79.workers.dev`) | TTFB measurement, PSI proxy | Tested URL + strategy |
| Google PageSpeed Insights API | LCP/FCP/CLS/TBT diagnostics | Proxied via CF Worker |
| License service (`wpservice.pro`) | Quota enforcement, license activation | License key + site URL |

- [Privacy Policy](https://wpservice.pro/privacy-policy)
- [Terms of Service](https://wpservice.pro/terms-and-conditions/)
- [Google API Terms](https://developers.google.com/terms)

---

## Repository Structure

```
wp-speed-analyzer.php     bootstrap - constants, admin menu, asset enqueue, PDF/quota AJAX
readme.txt                WordPress.org readme (must stay at the plugin root)
README.md  CHANGELOG.md  LICENSE  license.txt
.gitattributes            freezes line endings (`* -text`) - see the note below

includes/                 all plugin logic, loaded in a fixed order by the main file
  helpers.php               shared utilities, CWV field extraction, debug-log sink
  modules.php               Modules 3/4/4.5 - autoloaded options, object cache, environment
  diagnostics.php           Module 5 - PageSpeed acquisition, results-log writer/reader
  summary.php               Module 6 - summary tables
  conclusion.php            Module 7 - conclusion and advice
  report.php                PDF report markup
  lpanel.php                license panel
  schedule.php              scheduled runs, batching, alert emails
  compare.php               Compare tab and trends chart
  editors.php               Posts/Pages speed column and row actions

assets/
  js/                     admin-scripts, admin-widgets, cwv-ui, report-scripts,
                          schedule-scripts + vendored chart.umd.min.js, html2pdf.bundle.min.js
  css/                    admin-styles.css, report-styles.css
  img/                    SAWP-logo.svg and product thumbnails

tests/                    standalone harnesses - no framework, no build step.
                          Run a single one with `node tests/<name>.js` or `php tests/<name>.php`.
```

### Two things worth knowing before you move a file

**Paths are anchored to the main file, not to the caller.** `WPSA_PLUGIN_URL` and
`WPSA_PLUGIN_DIR` are defined in `wp-speed-analyzer.php` from `WPSA_PLUGIN_FILE`. Do not
reintroduce `plugin_dir_url( __FILE__ )` - it resolves relative to the *calling* file, so a
caller in `includes/` would build `<plugin>/includes/...` and every asset URL from it would
404. `tests/plugin-path-constants-harness.php` fails if any shipped PHP does this.

**Line endings are frozen, not normalised.** The repo deliberately mixes conventions across
files (CRLF for most of `includes/`, LF for the main file and `tests/`). Every file is
internally uniform, which is what Plugin Check actually checks. `.gitattributes` sets
`* -text` so git stores the bytes on disk verbatim; without it `core.autocrlf` rewrote files
on every `git add` and buried real changes under line-ending noise.

