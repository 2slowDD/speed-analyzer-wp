=== Speed Analyzer WP ===
Contributors: dalibord
Donate link: https://wpservice.pro/donate
Tags: performance, speed, ttfb, pagespeed
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.0
Stable tag: 1.0
License:         GPL v3 or later
License URI:     https://www.gnu.org/licenses/gpl-3.0.txt

In-dashboard performance auditing of TTFB, request count, PSI core vitals, autoloaded options, and more.
== Description ==

Speed Analyzer WP gives you an in-dashboard audit of:

1. **Server TTFB**  
2. **Requests & Page Size**  
3. **Autoloaded Options**  
4. **Persistent Object Cache**  
5. **PageSpeed Insights (LCP & FCP)**
6. **Summary & Recommendations**
7. **Conclusion & Pro-service offer**

It uses Cloudflare Workers and Google PSI under the hood (your data is NOT stored on any external site). A 10-tests/day fair-use limit is enforced on the current version.  

== Installation ==

1. Upload the `speed-analyzer-wp` folder to `/wp-content/plugins/`.  
2. Activate the plugin through the “Plugins” menu in WordPress.  
3. Go to **Tools → Speed Analyzer WP** to run your first audit.  

== Frequently Asked Questions ==

= Why is there a daily limit? =  
Because we leverage external APIs with shared quotas. You get 10 free tests/day; unlimited tests require a premium license.  

= Will you collect any of my data? =  
No—everything runs on your server, and no results are stored on our site.  

== Screenshots ==

1. **Dashboard view** – TTFB, requests, autoload, cache.  
2. **PSI Diagnostics** – LCP/FCP circles and opportunities.  
3. **Mobile/Desktop** - Mobile and desktop test functionality.
4. **Summary & Conclusion** – color-coded blocks and pro-service link.  


== Changelog ==

= 1.0 =
* Bumped version for WordPress.org release.



