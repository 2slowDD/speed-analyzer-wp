=== Speed Analyzer – WordPress Speed Test & Performance Audit ===
Contributors: dalibord
Donate link: https://wpservice.pro/donate/
Tags: speed test, pagespeed insights, core web vitals, performance audit, ttfb
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.19.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Run WordPress speed tests from wp-admin. Check PageSpeed, Core Web Vitals, TTFB, page weight, database health, and create PDF reports.

== Description ==

Speed Analyzer is a WordPress speed test and performance audit plugin that runs from wp-admin. It combines Google PageSpeed Insights data with WordPress-specific checks that browser-based testing tools cannot access, including autoloaded options, database size, active plugins, persistent object cache, and server information.

Use one dashboard to identify whether a performance bottleneck comes from the server, frontend assets, Core Web Vitals, or the WordPress database. Save results, compare before-and-after tests, schedule recurring checks, receive reports by email, and generate color-coded PDF reports.

WordPress.org reviewers highlight having PageSpeed data inside WP Admin and getting clearer direction about what to optimize.

Read the reviews:
[https://wordpress.org/support/plugin/speed-analyzer/reviews/](https://wordpress.org/support/plugin/speed-analyzer/reviews/)

= What Speed Analyzer checks =

* Server Time to First Byte (TTFB), cache status, and response headers
* Page requests, total page size, and loaded CSS and JavaScript files
* Google PageSpeed Insights performance score and Lighthouse diagnostics
* Lab metrics including LCP, FCP, CLS, and Total Blocking Time (TBT)
* Available Core Web Vitals field data, including LCP, CLS, and INP
* Autoloaded options size with a list of the largest options
* Persistent object cache status
* WordPress database size, PHP version, database server version, and active plugins

= Reports, comparisons, and monitoring =

* Compare two saved tests to measure the effect of optimization work
* Schedule recurring tests and receive the results by email
* Configure alerts for performance regressions or metric thresholds
* Generate downloadable, color-coded PDF reports with explanations and recommendations
* Add agency branding to PDF reports on the Agency plan
* View the latest performance score and Core Web Vitals from the Posts and Pages screens

Speed Analyzer is a diagnostic and reporting tool. It identifies performance problems and recommends next steps, but it does not automatically apply caching, CSS, JavaScript, image, or database optimizations.

[youtube https://www.youtube.com/watch?v=B5C8iZISJoA]

Official plugin homepage:
[https://wpservice.pro/our-products/speed-analyzer-wp-plugin/](https://wpservice.pro/our-products/speed-analyzer-wp-plugin/)

= Free and paid usage limits =

The free tier includes up to 10 tests per day and one PDF report per day. Higher limits are available through Pro, Business, and Agency plans. Your current tier and remaining usage are shown under **Speed Analyzer > License**.

View current limits and pricing:
[https://wpservice.pro/our-products/speed-analyzer-wp-plugin/#licenses](https://wpservice.pro/our-products/speed-analyzer-wp-plugin/#licenses)

= Included third-party library =

* `assets/js/html2pdf.bundle.min.js` — bundled html2pdf.js v0.10.3 for browser-based PDF generation
* Source: [https://github.com/eKoopmans/html2pdf.js](https://github.com/eKoopmans/html2pdf.js)

== External Services ==

Speed Analyzer performs WordPress-specific database and server checks locally. When you start a speed test, or when a scheduled test runs, selected modules connect to a managed WPservice analysis service and Google PageSpeed Insights.

= WPservice hosted analysis service =

Endpoint:
[https://globalwpspeed.dalibord79.workers.dev/](https://globalwpspeed.dalibord79.workers.dev/)

The hosted service is used for:

* Measuring TTFB from a Cloudflare edge location
* Fetching page asset and PageSpeed data
* Proxying PageSpeed requests to Google
* Enforcing daily usage and PDF-report limits

For TTFB tests, the tested URL is sent to the service. For PageSpeed tests, the tested URL and selected mobile or desktop strategy are sent. The service returns the test data to the plugin. No personal information is intentionally collected, and test results are not stored on the WPservice website.

Privacy policy:
[https://wpservice.pro/privacy-policy/](https://wpservice.pro/privacy-policy/)

Service terms:
[https://wpservice.pro/terms-and-conditions/](https://wpservice.pro/terms-and-conditions/)

= Google PageSpeed Insights =

The hosted WPservice endpoint forwards the tested URL and mobile or desktop strategy to the Google PageSpeed Insights API. Google returns PageSpeed, Lighthouse, diagnostics, and available Core Web Vitals field data.

Google API Terms of Service:
[https://developers.google.com/terms](https://developers.google.com/terms)

== Installation ==

1. In WordPress, go to **Plugins > Add New Plugin** and search for **Speed Analyzer**.
2. Click **Install Now**, then **Activate**.
3. Go to **Tools > Speed Analyzer**.
4. Enter or confirm the URL and run the mobile or desktop speed test.
5. Review the results and recommendations, or generate a PDF report.
6. Optionally save comparisons, schedule recurring tests, or configure alerts.

No account or API key is required for the free tier.

== Frequently Asked Questions ==

= Does Speed Analyzer make my WordPress website faster? =

Not automatically. Speed Analyzer measures performance, identifies likely bottlenecks, and explains what needs attention. You can use its results to guide manual optimization or confirm the effect of changes made by another plugin or developer.

= How is it different from running PageSpeed Insights directly? =

Speed Analyzer brings PageSpeed and Core Web Vitals data into wp-admin and combines it with WordPress-only information such as autoloaded options, database size, persistent object cache, active plugins, and server details. It also adds saved comparisons, scheduled tests, alerts, email results, and PDF reporting.

= Is the free version enough to test my website? =

Yes for ordinary use. The free tier includes up to 10 tests and one PDF report per day. Higher-volume users and agencies can choose a paid tier.

= Why is there a daily limit? =

The remote tests use hosted infrastructure and external API quotas. Limits reset at midnight UTC. When the limit is reached, wait for the reset or upgrade to a tier with a higher allowance.

= What is sent to external services? =

The tested public URL is sent for TTFB checks. PageSpeed tests also send the selected mobile or desktop strategy. WordPress-specific information such as your autoloaded-options list, active-plugins list, and database details is processed locally and is not needed by the remote PageSpeed or TTFB tests.

= Where are my saved test results stored? =

Saved comparison and scheduled-test data is stored on your WordPress website. WPservice does not store your completed report data on its website.

= Why can two PageSpeed tests return different scores? =

PageSpeed results can vary because server response time, network conditions, cache state, third-party scripts, and the tested environment change between runs. Compare several tests rather than treating one score as permanent.

= Why does Speed Analyzer show TBT and INP? =

Total Blocking Time is a Lighthouse lab metric used in PageSpeed performance tests. Interaction to Next Paint is a Core Web Vital based on real-user field data. They measure related but different aspects of responsiveness and are not interchangeable.

== Screenshots ==

1. Run a WordPress speed audit from wp-admin and review server TTFB and page asset totals
2. Inspect loaded CSS files and copy asset data for troubleshooting
3. Review PageSpeed lab metrics and diagnostics, including LCP, FCP, CLS, and TBT
4. Compare mobile and desktop PageSpeed results
5. Check autoloaded options, persistent object cache, active plugins, PHP, database server, and database size
6. See color-coded findings, explanations, and recommended next steps
7. Compare two saved tests to measure before-and-after performance changes
8. Schedule recurring tests and configure performance alerts
9. Generated PDF speed report with its summary and tested URL
10. PDF report showing measured performance results and explanations
11. PDF report with recommendations and supporting details
12. Scheduled speed-test results delivered by email
13. Latest performance score and Core Web Vitals shown in the Posts and Pages lists

== Changelog ==

= 1.19.0 =
* Total Blocking Time (TBT) replaces INP in lab measurements, matching Google PageSpeed Insights.
* TBT uses Lighthouse's device-specific thresholds: 200/600 ms on mobile and 150/350 ms on desktop.
* Core Web Vitals field data continues to report INP when sufficient real-user data is available.
* Scheduled alerts can now monitor TBT. Existing INP alerts are migrated automatically.
* Fixed missing or stale metrics in Compare Results, Core Web Vitals fields, and Posts and Pages columns.
* Fixed PageSpeed values above 1,000 ms and TBT results of exactly 0 ms being misread.

= 1.18.6 =
* Resolved Plugin Check coding-standard and security findings.
* Bundled Chart.js locally instead of loading it from a third-party CDN.
* Limited debug logging to sites with `WP_DEBUG` and `WP_DEBUG_LOG` enabled.
* Improved PDF generation with staged processing and progress feedback.
* Tested up to WordPress 7.1.

For the complete release history, see `changelog.txt` in the plugin folder.
