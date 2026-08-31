# Speed Analyzer - WordPress Plugin

<p align="center">
  <img alt="CI Passing" src="https://img.shields.io/badge/CI-passing-brightgreen?style=for-the-badge">
  <a href="https://www.gnu.org/licenses/gpl-3.0.html"><img alt="License: GPL-3.0-or-later" src="https://img.shields.io/badge/License-GPL--3.0--or--later-green?style=for-the-badge"></a>
  <img alt="Version 1.18.6" src="https://img.shields.io/badge/Version-1.18.6-blue?style=for-the-badge">
</p>

**In-dashboard website performance auditing for WordPress.**
Version: 1.18.6 | Requires: WordPress 5.0+, PHP 7.0+ | License: GPL v3

> Official plugin page: [wpservice.pro/our-products/speed-analyzer-wp-plugin](https://wpservice.pro/our-products/speed-analyzer-wp-plugin/)

---

## What It Does

Speed Analyzer gives admins a one-click speed audit directly in the WordPress dashboard — no external account sign-up required. Results are powered by Cloudflare Workers (TTFB) and the Google PageSpeed Insights API (LCP/FCP/CLS/INP), proxied through a managed service so no API key configuration is needed.

**Modules:**

| # | Module | Metrics |
|---|--------|---------|
| 1 | Server TTFB | Time to First Byte, cache status, response headers |
| 2 | Page Asset Summary | Request count, page size, JS/CSS onload timing |
| 3 | Performance & Diagnostics | LCP, FCP, CLS, INP, mobile/desktop screenshots |
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
| Google PageSpeed Insights API | LCP/FCP/CLS/INP diagnostics | Proxied via CF Worker |
| License service (`wpservice.pro`) | Quota enforcement, license activation | License key + site URL |

- [Privacy Policy](https://wpservice.pro/privacy-policy)
- [Terms of Service](https://wpservice.pro/terms-and-conditions/)
- [Google API Terms](https://developers.google.com/terms)

---

## Repository Structure

