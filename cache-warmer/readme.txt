=== Cache Warmer ===
Contributors: tmmtechnology
Tags: cache, cache preloading, performance, cloudflare, woocommerce
Tested up to: 7.0.0
Stable tag: 1.5.1
Requires at least: 6.5
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Warm your WordPress cache before visitors arrive. Preload pages on a schedule, refresh published content and inspect every warm-up.

== Description ==

**A fast cache only helps when the page is already in it.**

After a cache expires or is cleared, the next visitor may have to wait while WordPress builds the page again. Cache Warmer visits your pages ahead of time, giving your existing caching system the opportunity to prepare a cached response before a customer, reader or campaign visitor arrives.

Choose the pages that matter, control the pace and see what happened. Start a warm-up when you need it, run it on a schedule, or queue published content after an edit.

**Cache Warmer complements your caching setup.** It does not create a page-cache engine or change which pages your cache considers eligible. It works by making HTTP requests that can populate a properly configured plugin, server or CDN cache.

= New in 1.5: faster warming queues, clearer progress =

**Spend less time managing the queue and more time warming pages.** Version 1.5 brings a substantial queue-processing performance improvement for large warm-up configurations. It avoids repeatedly sorting large configuration payloads and handles excluded URLs in bounded batches, while preserving queued work, recovery and configured HTTP visit limits.

In one production observation on a large WooCommerce store, warming worker executions dropped from roughly 100 seconds to 1–3 seconds after the update. This measures individual background-worker executions, not page-load speed or total crawl duration. Results vary with the queue, hosting, page response times and scheduler availability.

Progress reporting now shows completion timestamps, elapsed time that includes pauses, clearly labelled average visit speed and skipped totals on every log page.

= Prepare important pages before the next visit =

Warm your homepage, landing pages, articles and public product pages without manually opening them one by one. After a deployment or cache purge, start a run to help rebuild the cache for the pages you have selected.

This is especially useful when important pages receive too little regular traffic to stay warm on their own.

= Warm the pages you choose =

Build a focused crawl or cover more of your public site:

* Start from one or more URLs.
* Include public posts, pages, custom post types and taxonomy archives.
* Discover URLs through entry-point sitemaps.
* Set how many link levels the crawler follows.
* Exclude URLs with plain-text patterns or regular expressions.
* Optionally skip pages whose canonical URL has already been warmed.

Depth 0 visits entry points only. Use a larger depth when you want to discover linked pages, and set exclusions to keep the crawl focused.

= Put repeat warming on a schedule =

Set an automatic interval in minutes, or start a run manually from WordPress. Enable post-update warming to queue published content after publication or editing, with a configurable delay.

Scheduled runs and post-triggered runs have separate histories, so you can see what ran and why. Background processing uses Action Scheduler.

= Match the crawl to your hosting =

Control the maximum pages per minute, request timeout, crawl depth and included assets. Start with a small group of pages, inspect the results and expand the crawl to suit your site's capacity.

The speed setting is a ceiling, not a promised throughput. Response times and background-worker availability also determine how quickly a run completes.

= See more than a completion message =

Inspect visited URLs, successful and failed requests, HTTP response codes, error messages and request timings. When returned by the server, logs include cache headers such as `cf-cache-status`, `x-cache` and `X-WP-Super-Cache`.

A dashboard chart summarizes request timings before and after warming. Use the evidence to investigate cache misses, redirects, slow pages and broken URLs. These are HTTP measurements from the warmer, not a visitor's browser rendering time or Core Web Vitals score.

= Include assets and request variants when needed =

Choose whether to request discovered scripts, styles, images and fonts. Configure user agents, request headers, cookies and URL parameters for the cache variants you intend to warm.

Optional second visits without custom cookies or URL parameters help cover the corresponding plain request. Request variants need to match your cache rules; additional variants also increase the work performed.

= Keep interrupted work recoverable =

Pending URLs are stored in the database, and workers coordinate processing of the queue. Interrupted background work can resume through subsequent workers; failed page requests receive a bounded retry and remain visible in the logs.

Configure warm-up log retention to manage history. Recovery information is retained where needed rather than silently discarded.

= Local warming included; external warming optional =

Run local warming from your WordPress server without an external-warmer subscription. An optional licensed service can request pages from configured external locations. See External Services below for the information shared when that integration is enabled.

**Get started with a few cacheable pages, run a warm-up and inspect their cache headers.** Then choose the schedule and crawl coverage that fit your site.

== Installation ==

= Requirements =

* WordPress 6.5 or newer and PHP 7.4 or newer.
* PHP extensions: curl, dom, json, libxml and mbstring. SimpleXML is needed for XML sitemap parsing.
* An existing cache that can store eligible HTTP responses.
* Outbound HTTP access to the URLs being warmed and working WordPress background processing.

= Your first warm-up =

1. Install and activate Cache Warmer.
2. Open Cache Warmer > Settings.
3. Add a few public, cacheable URLs as entry points. For a focused first run, use depth 0 and leave additional discovery and asset loading off.
4. Choose a conservative speed limit and a timeout suitable for your hosting. Leave custom cookies, headers and URL parameters empty unless your cache setup requires them.
5. Save your settings and start a warm-up from the Cache Warmer main page.
6. Review the results and cache response headers. Confirm that the intended pages become cache hits before expanding the crawl.
7. Add your preferred schedule, sitemap discovery or post-update warming once the small run works as expected.

For WooCommerce, begin with public shop, category and product pages. Configure exclusions for cart, checkout, account pages and action URLs such as add-to-cart links. Use your actual page slugs; this is configuration guidance, not a promise of automatic exclusions.

== Frequently Asked Questions ==

= Do I still need a caching plugin or server cache? =

Yes. Cache Warmer makes requests; your existing caching system decides whether to store and serve them. Without an appropriate cache, those requests still run but may not make subsequent visits faster.

= Which caching systems can it work with? =

The approach can work with WordPress page-cache plugins, hosting caches, reverse proxies and CDNs that populate their cache from ordinary eligible requests. Compatibility depends on your cache keys, cookies, headers and exclusion rules. Test the setup using the warm-up logs and your cache's own diagnostics.

= Does it work with Cloudflare? =

It can warm eligible responses when requests pass through Cloudflare and your Cloudflare configuration caches them. The plugin does not turn on HTML caching or override bypass rules. Logs show the `cf-cache-status` header when available.

A local run does not guarantee that every Cloudflare location is warm. CDN routing and cache distribution remain under the CDN's control.

= Can I use it on a WooCommerce store? =

Yes, for public pages your cache is configured to serve safely, such as product and category pages. Keep private, personalized and transactional pages out of the crawl, and avoid session cookies unless you deliberately need a particular request variant.

For large catalogues, start with important URLs or a targeted sitemap. Deep crawls of filters, sorting and query-string combinations can generate much more work than the product count suggests.

= What is the difference between warming and clearing the cache? =

Clearing removes cached responses. Warming requests pages so eligible responses can be cached again. Start a warm-up after your normal purge process when you want to rebuild selected pages; warming alone does not guarantee removal of stale content.

= Will warming add load to my server? =

Yes. Building an uncached page consumes resources. The purpose is to do that work ahead of visitors, at a pace you control. Set appropriate speed, depth, asset and schedule limits, particularly on shared hosting or busy stores.

= Why is the run slower than the configured pages per minute? =

The setting is a maximum rate. Slow responses, retries, throttling and gaps between background workers can reduce actual throughput. Check timings and scheduled tasks before increasing the limit.

= Does a successful request prove that a page is cached? =

No. HTTP 200 means a request succeeded, not necessarily that the response was stored in a cache. Check cache-status headers and your caching configuration. Cookies, query parameters or cache-control rules may cause a bypass.

= What if a page fails or a worker stops? =

The plugin retains pending work for subsequent workers and records page failures. Failed page requests receive a bounded retry, rather than being retried forever. Persistent HTTP errors still need investigation; a crawl completing does not mean every URL succeeded.

= Does asset preloading execute JavaScript? =

No. The warmer requests discovered asset URLs. It is an HTTP crawler, not a full browser, so it does not execute scripts, interact with the page or discover every resource loaded dynamically by JavaScript.

= Can I start or stop a warm-up with WP-CLI? =

Yes. Use `wp cache-warmer start` or `wp cache-warmer stop`. These commands schedule the operation; background workers still need to run. A successful start command does not mean the crawl has already finished.

= How do I keep logs manageable? =

Set the warm-up log retention period in Settings. The plugin also cleans up older records for its warming actions in Action Scheduler. Active work and retained recovery data can outlive ordinary history, so retention is not a fixed database-size limit.

= Is the external warming service required? =

No. Local warming is included. External warming is an optional licensed integration; availability and access depend on the service configuration. Contact wp@tmm.ventures for details.

= Where can I get help? =

Open a topic in the [WordPress.org support forum](https://wordpress.org/support/plugin/cache-warmer/) or email wp@tmm.ventures.

Include your WordPress and Cache Warmer versions, caching setup, the affected URL if public, and the relevant HTTP error or diagnostic message. Remove license keys, cookies, authorization headers and private URLs before sharing logs.

== Screenshots ==

1. Warm-up summary and visited URLs, with success counts and request timings.
2. Crawl coverage: entry points, exclusions, link depth and asset selection.
3. Request configuration: speed, timeout, user agents, cookies and headers. The legacy object-cache queue option shown here is no longer used.
4. Automatic schedules and warming after content updates.
5. Configurable warm-up log retention.
6. Optional external-warmer license configuration.
7. Run history with HTTP results and cache-status headers.
8. Separate history for warming triggered by content updates.

Screenshots show an earlier interface; current controls may differ. Sample timings illustrate the reports and are not performance guarantees.

== External Services ==

= Crawl destinations =

Local warming sends HTTP requests to configured entry points and discovered page or asset URLs, which may include external hosts. Those hosts receive the warmer's connection information and configured request headers, including any cookies or URL parameters you supply. Configure only destinations and credentials appropriate for the crawl.

= Optional external warmer =

Adding or changing an external-warmer license key sends the site domain and key to https://validate.cachewarmer.xyz/ for validation. When external warming is enabled, the selected service endpoints receive the domain, license key, target URLs and configured request headers, including user-agent and cookie values. Those servers then request the target URLs and return results to the plugin.

Local warming does not require this service. Review the configured servers and avoid sending sensitive session cookies or credentials unless appropriate for your intended use. Contact wp@tmm.ventures for access, service terms and privacy information.

== Changelog ==

= 1.5.1 2026-11-05 =

* Keep dashboard loading responsive by preparing warm-up statistics in the background and displaying cached results.
* Continue warming updated posts, pages and products at the configured interval after each queue drain. Preserve queued updates and delay retries when a worker fails.
* Respect interval changes and disabling during a running job, and display the enabled default correctly in Settings.

= 1.5.0 2026-11-05 =

* Performance release: bring the queue-processing improvements verified in 1.4.16 to WordPress.org users. Large warm-up configurations avoid expensive repeated payload sorting, and excluded URLs are processed in bounded batches.
* In one production observation, individual warming workers completed in 1–3 seconds instead of roughly 100 seconds. This is an observed worker-execution improvement, not a guaranteed page-load or whole-crawl speedup.
* Show accurate completion timestamps and elapsed duration, clearly label average visit speed, and retain skipped totals across log pages.
* Preserve pending URLs, existing history, crash recovery, bounded retries and configured HTTP visit limits. No additional runtime changes from 1.4.16.

= 1.4.16 2026-11-05 =

* Speed up queue selection for large warm-up configurations without changing visit priority or queued data.
* Process excluded URLs in bounded batches while preserving recovery, retries and HTTP visit limits.
* Correct elapsed duration and average visit speed, show completion timestamps, and keep skipped totals visible on every log page.

= 1.4.15 2026-11-03 =

* Recover non-positive saved warm-up speed limits from the current site setting before processing. Preserve valid run limits, queued visits and metadata recovery backups.

= 1.4.14 2026-11-03 =

* Automatically recover recognized truncated run metadata using current site settings only for missing fields. Preserve complete settings, queued URLs and an exact backup of the damaged data.

= 1.4.13 2026-11-03 =

* Explain unsupported metadata recovery with bounded structural diagnostics, without exposing stored request configuration or changing the retained queue.

= 1.4.12 2026-11-03 =

* Prevent large warm-up configurations from being truncated by widening metadata storage and rejecting failed JSON encoding or unsafe writes.
* Add guarded administrator recovery for truncated request history, preserving the original metadata and all queued visits without resetting the warm-up.
* Retain recovery backups during automatic cleanup and prevent incomplete request history from replacing a complete reusable crawl.
* Fix timeout option declarations during uninstall when the plugin is inactive.

= 1.4.11 2026-11-03 =

* Add administrator diagnostics for warm-up configuration size, checksum, JSON errors and database column capacity without exposing configuration contents.

= 1.4.10 2026-11-03 =
* Restore the documented 15-second HTTP timeout for retained warm-ups with missing or zero timeouts, preserving valid configured limits and queued work.
* Verify delayed responses and successful retries through the real WordPress HTTP transport while retaining failure history.

= 1.4.9 2026-11-03 =
* Recover missing legacy warm-up start dates from the existing run identity without resetting queued visits.
* Persist identity recovery before retrying so subsequent failures retain bounded backoff, including interrupted finalization.

= 1.4.8 2026-11-02 =
* Preserve historical zero/empty behavior for retained warm-ups with missing or null numeric settings and discovery agents.
* Add bounded, redacted active-run configuration diagnostics without exposing cookies, headers or user-agent values.

= 1.4.7 2026-11-02 =
* Normalize legacy optional warm-up settings before requests and canonical or asset discovery, preserving queued URLs and configured values.
* Keep interrupted work recoverable with delayed worker retries and preserve malformed metadata for investigation.
* Report the active warm-up instead of historical scheduler failures, and keep progress refreshing during recovery.

= 1.4.6 2026-11-02 =
* Fix queued warm-ups with missing or null optional request headers while preserving configured headers and pending visits.

= 1.4.5 2026-11-02 =
* Fix queued warm-ups failing when optional cookie metadata is missing or null, while preserving configured cookies.
* Recover missing warm-up list containers during database upgrades without deleting their logs; retain incomplete recovery data separately from runnable work.

= 1.4.4 2026-10-30 =
* Fix large legacy queue upgrades exceeding the database packet limit by storing seed manifests in bounded chunks.
* Preserve complete pending queues across interrupted transfers and verify their integrity before resuming.
* Stop retrying non-collision SQL failures and reclaim seed chunks after successful publication.

= 1.4.3 2026-10-30 =

#### Bugfixes

* Update bundled WP Plugins Core to 0.1.58 with bounded notification retries and recovery; notification fetching remains disabled in this plugin.

= 1.4.2 2026-10-29 =

#### Bugfixes

* Recover a missing database table when the saved migration checkpoint is already ahead, preserving surviving tables and retry progress.
* Keep the main administration page and migration diagnostics accessible while a database upgrade or status read is unavailable.

= 1.4.1 2026-10-13 =

#### Bugfixes

* Preserve pending warm-up work and recovery state when queue discovery, persistence, or scheduler cleanup fails.
* Update shared settings and scheduler recovery support to WP Plugins Core 0.1.57.

= 1.4.0 2026-08-17 =

#### Enhancements

* Store active warming queues in indexed database rows instead of large serialized options.
* Make warm-up processing recoverable across PHP requests and prioritize scheduled warming over post-triggered warming.
* Add a unified Codeception and wp-browser regression suite covering queues, migrations, HTTP outcomes, content discovery, scheduling, external warming, post-triggered warming, reporting, options, and AJAX authorization.

#### Bugfixes

* Prevent overlapping workers from processing the same queued page.
* Retry failed pages once and preserve scheduled and post-triggered queues independently.
* Isolate every post-triggered batch so repeated edits cannot suppress later warming requests.
* Make manual cancellation compatible with strict SQL modes and resume independent triggered queues afterward.
* Reconcile missing columns and indexes on legacy log tables.
* Keep external-warmer logging compatible with the database-backed queue.
* Correctly recognize extensionless pages inside dotted directory names.
* Parse complete CSS `@import` URLs instead of truncating them to one character.
* Record newly discovered failed URLs as failures when no pending queue row exists.

#### Security

* Require administrator capability checks in addition to nonces for every plugin AJAX action.
* Update Guzzle to the patched release covered by the current security advisories.

= 1.3.11 2026-07-15 =

#### Security

* Update Guzzle dependencies to prevent URL credentials from reaching origin servers.

= 1.3.10 2026-07-03 =

#### Bugfixes

* Fix "_load_textdomain_just_in_time was called incorrectly" notice by loading translations on the `init` hook (required since WP 6.7).

= 1.3.9 2026-06-25 =

#### Compatibility

* Added support for WordPress 7.0.0.

= 1.3.8 2024-11-18 =

#### Bugfixes

* Fix UI rendering bugs.

= 1.3.7 2024-11-02 =

#### Bugfixes

* Fix interval scheduling issue.

= 1.3.6 2024-10-21 =

#### Enhancements

* Add WP-CLI support.

= 1.3.5 2024-10-20 =

#### Bugfixes

* Fix fatal error on plugin update.

= 1.3.4 2024-10-19 =

#### Enhancements

* Add support for WP CLI.

#### Bugfixes

* Fix scheduled intervals.

= 1.3.3 2024-10-05 =

#### Enhancements

* Instead for checking for intervals in constructors on every page load, check for them only once on plugin activation. Add a button and other interval (which is scheduled in constructor) to fix any missing intervals.

= 1.3.2 2024-09-29 =

#### Bugfixes

* Warming start bug fix.

= 1.3.1 2024-09-29 =

#### Enhancements

* Add support for external warmer - simply for each page visit, and with the intervals.
* Added functionality to start / stop the warming from code (or with WP CLI with "wp eval").

#### Bugfixes

* Fix the warming interval stuck bug.

= 1.3.0 2024-06-24 =

#### Enhancements

* Speed up warmings (optimization).

#### Bugfixes

* Fix stuck object cache bug.

= 1.2.5 2024-06-23 =

#### Bugfixes

* Fix stuck object cache bug.

= 1.2.4 2024-06-23 =

#### Changes

* Do not schedule notifications fetch action.

= 1.2.3 2024-06-05 =

#### Bugfixes

* Fix Action Scheduler bug.

= 1.2.2 2024-05-26 =

#### Bugfixes

* Fix Fatal Error with explode on Windows by using DIRECTORY_SEPARATOR constant.

= 1.2.1 2024-05-02 =

#### Enhancements

* Use standard Action Scheduler interval.
* Update WP-Plugins-Core.

= 1.2.0 2024-04-23 =

#### Bugfixes

* Fix the unscheduled warming with empty data (which results in PHP Warnings).

= 1.1.9 2024-03-12 =

#### Bugfixes

* Fix the "depth" setting.

= 1.1.8 2024-03-11 =

#### Bugfixes

* Fix the sitemaps parsing (didn't visit them at all previously).
* Fix ID for "external warmer" setting fields.
* Fix "speed limit" bug.

= 1.1.7 2024-02-28 =

#### Bugfixes

* Fix settings import button color.
* Fix a bug when the scheduling time is drifting.
* Fix the wrong warming "Duration".
* Fix "speed limit" bug.

#### Enhancements

* Warm sitemaps and posts first (with prio), and only then entry points.

= 1.1.6 2024-01-14 =

#### Bugfixes

* Fix "Request: DOMDocument::loadHTML(): Argument #1 ($source) must not be empty".

= 1.1.5 2024-01-13 =

#### Enhancements

* Update default User-Agent to the newer version of Chrome.
* Increase URL params limit from 5 to 50.
* Add a setting to specify custom request headers.
* Add a setting to visit pages second time without custom cookies (when they are set).
* Improve canonicals logic, to add a log icon with the canonical to the warm-up log; and if 'skip pages with warmed canonical' setting is off, then also warm the canonical page immediately.

#### Bugfixes

* Fix table cells style (overflow: auto for all).
* Logs in the site's timezone.
* Fix a bug when resetting settings does not change posts warming enqueue.
* Fix user-agent table width when no values are set.

= 1.1.4 2023-09-23 =

#### Bugfixes

* Fix canonicals logic.
* Do not untrailingslash all URLs, but keep them as they are.
* Allow to specify "localhost" as entry points.
* Fix URL column style (overflow: auto).

= 1.1.2 2023-09-16 =

#### Enhancements

* Add an option to specify several User-Agents.
* Dynamically escape regex characters when enabling regex. And de-escape on uncheck.
* For headers-based requests classification, do not show 0, but instead show nothing; so that people could not misinterpret it as another request.
* Add a setting to skip the pages with the warmed canonical.
* Add optional "Comment" column to the warm log.
* Change number of pages in batch size from 10 to 1 to increase the robustness.
* Add a setting for logs retention time.
* New menu layout.

#### Bugfixes

* Make "Rewrite to HTTPS" to work for the "URL to warm" on page edit block, and for entry points pages exclusion.
* Fix update_failed_to_retrieve_links() and update_retrieved_links() added links check, to not add duplicates.
* Improve RegExes robustness by using "lazy" quantifiers, instead of the greedy ones.

= 1.0.54 2023-07-18 =

#### Enhancements

* Use wp_options table instead of cache for the lock of migrations.
* Set min PHP version to 7.4.

= 1.0.51 2023-07-16 =

#### Enhancements

* Cleans up Action Scheduler log after itself (older than 30 days for failed actions, and older than 2 days for completed actions).
* Added index to the table to make the post edit page open faster.
* Entry points limit lifted.
* Can specify homepage relative paths as entry points.
* Add support for "html" files warming.
* Add a notice when the plugin is being updated instead of blocking the whole logic.

#### Bugfixes

* Fix table creation error.
* Fix action scheduler scheduling (improve initialization check).
* Fix a bug to not warm "mailto:" URLs, but only the ones that start from "http://" or "https://".
* Add checks in case wp_parse_url() is false.
* Add Author metadata to the plugin main file.
* Do not add URL params to files with 'XML' extensions.
* Fix a bug when URL params to sitemap pages were not added.
* Fix a bug when "sitemaps" setting was not reset.

= 1.0.44 2023-06-11 =

#### Bugfixes

* Add pagination to blog posts.
* Support pagination for plaintext URLs structure.

= 1.0.43 2023-06-08 =

#### Bugfixes

* Fix URLs overlap over the post edit content box.

= 1.0.41 2023-06-07 =

#### Enhancements

* Add pages support for taxonomies.
* Better migration routine.

= 1.0.40 2023-06-07 =

#### Bugfixes

* Add missing libraries.

= 1.0.38 2023-06-06 =

#### Bugfixes

* Fix the plugin (didn't start previously).

= 1.0.37 2023-06-06 =

#### Enhancements

* Decrease plugin size from 8 MiB to 2.8 MiB by removing irrelevant files.

= 1.0.36 2023-06-03 =

#### Enhancements

* Rename "Interval" tab to "Autorun Interval".
* Capitalize first letters of all tab words.
* Add post URL, and with URL params (if presents) to the post edit block.
* Add option to exclude pages by regex.

#### Bugfixes

* Fix interval-based warming.

= 1.0.35 2023-05-15 =

#### Enhancements

* Add an option to skip pages (that match URL a substring).
* Add links (tag <a>) to the warm-up URL column.

= 1.0.34 2023-04-30 =

#### Bugfixes

* Fixed error when the host was undefined.

= 1.0.25 2023-02-23 =

#### Enhancements

* Check page load time pre warmer.
* Add Varnish and Cloudfront support.

= 1.0.20 2023-02-15 =

#### Bugfixes

* Redundant dashboard query deleted.

= 1.0.19 2023-02-14 =

#### Bugfixes

* Min PHP version required downgraded to 5.6.20.

= 1.0.17 2023-02-08 =

#### Optimizations

* Dashboard query to consider only the previous 30 days.

#### Bugfixes

* Default sitemap priority changed from 0 to 0.5.
* Add terms to the tree query fixed.

= 1.0.16 2023-02-08 =

#### Enhancements

* Improved accuracy for server IP address detection.

= 1.0.15 2023-02-08 =

#### Bugfixes

* Regexes fixed and improved (now work more accurately and catch more links).

= 1.0.11 2023-02-08 =

#### Enhancements

* Consider priority for sitemaps.

= 1.0.10 2023-02-08 =

#### Enhancements

* Optimized dashboard widget by using a faster query for the dashboard widget.

#### Bugfixes

* Do not get empty afterload time for the dashboard widget.

= 1.0.9 2023-02-08 =

#### Enhancements

* Add term links  and archives to the posts also.

= 1.0.8 2023-02-05 =

#### Enhancements

* Option to sitemaps of entry points as entry points

= 1.0.7 2023-02-04 =

#### Enhancements

* Option to add all public site posts (of any type) as entry points.

#### Bugfixes

* Batch size reduced from 10 to 1.

= 1.0.6 2023-02-02 =

#### Enhancements

* Post warm details.

= 1.0.5 2023-02-02 =

#### Enhancements

* Slow down when hit 429 or 500 error: first 2 times slower for 15 minutes from the previous avg speed, then 8 times slower, then pause for an hour.

#### Bugfixes

* Speed limit is now working properly.

= 1.0.4 2023-01-30 =

#### Enhancements

* Show a notification when a page is blocked by Cloudflare or other firewall (403, 502, 504 codes).

#### Bugfixes

* Infinite loop during fake tree creation when the initial passed depth is 0.

= 1.0.3 2023-01-29 =

#### Enhancements

* Changelog added.

== Upgrade Notice ==

= 1.5.1 =
Faster dashboard loading and reliable warming after content updates. Existing settings, queued URLs and history are preserved.

= 1.5.0 =
Substantial queue-processing improvements for large warm-ups, faster handling of excluded URLs and clearer progress reporting. Existing queued work is preserved.
