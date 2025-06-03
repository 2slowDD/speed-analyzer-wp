=== Speed Analyzer ===
Contributors: dalibord
Donate link: https://wpservice.pro/donate
Tags: performance, speed, ttfb, pagespeed
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 1.08
License:         GPL v3 or later
License URI:     https://www.gnu.org/licenses/gpl-3.0.txt

In-dashboard performance auditing of TTFB, request count, PSI core vitals, autoloaded options, and more.
== Description ==

Speed Analyzer gives you an in-dashboard speed audit of your website for:

1. **Server TTFB**  
2. **Requests & Page Size**  
3. **Autoloaded Options**  
4. **Persistent Object Cache**  
5. **PageSpeed Insights (LCP & FCP)**
6. **Summary & Recommendations**
7. **Conclusion & Pro-service offer**

It uses Cloudflare Workers and Google PSI API under the hood (your data is NOT stored on any external site). A 10-tests/day fair-use limit is enforced on the current version.  

== Installation ==

1. Upload the `speed-analyzer` folder to `/wp-content/plugins/`.  
2. Activate the plugin through the “Plugins” menu in WordPress.  
3. Go to **Tools → Speed Analyzer** to run your first audit.  

== External Services ==

1. **Google PageSpeed Insights API**  
    • What it is / why used: Modules 2 and 5 of the plugin ultimately fetch real‐time LCP/FCP/diagnostic data from Google’s PSI service.
    • How it’s called now: Instead of hitting www.googleapis.com directly, the plugin sends your test URL + strategy to Cloudflare Worker, which then forwards the request to Google PSI on your behalf.
    • Provider & policies: Google LLC provides PSI.
    – Terms of Service: https://developers.google.com/terms
    – Privacy Policy: https://policies.google.com/privacy

2. **Cloudflare Workers HTTP Probe**  
    • What it is / why used:
    – Module 1 (Server TTFB) calls https://globalwpspeed.dalibord79.workers.dev/?url={your‐url}&rand={random} to measure Time-to-First-Byte from a global edge.
    – Modules 2 and 5 (Page asset summary and Diagnostics) now send psi_url={your-url}&strategy={mobile|desktop} to https://globalwpspeed.dalibord79.workers.dev/psi. That Worker then forwards the same parameters to Google PSI and returns the JSON back to the plugin.
    • What data is sent and when:
    – For TTFB: just the raw URL—no other user data.
    – For PSI: the URL you enter plus “mobile” or “desktop” strategy.
    No personal information is collected or stored.
    • Provider & policies:
    Hosted by WPservice (Dalibor Druzinec).
    – Privacy Policy: https://wpservice.pro/privacy-policy
    – No PII is collected; only your tested URL and strategy are transmitted.

== Frequently Asked Questions ==

= Why is there a daily limit? =  
Because we leverage external APIs with shared quotas. You get 10 free tests/day; more or unlimited tests require a premium license.  

= Will you collect any of my data? =  
No—everything runs on your server, and no results are stored on our site.  

== Screenshots ==

1. **Dashboard view** – TTFB, requests, autoload, cache.  
2. **PSI Diagnostics** – LCP/FCP circles and opportunities.  
3. **Mobile/Desktop** - Mobile and desktop test functionality.
4. **Summary & Conclusion** – color-coded blocks and pro-service link.  


== Changelog ==

= 1.06 =
* Bumped version for avoding trademark issues for WordPress.org release.

= 1.07 =
* Slug changed, readme external service addition, and minor fixes on helpers/modules.php files.

= 1.08 =
* Daily limit counting reworked to avoid double reduction

