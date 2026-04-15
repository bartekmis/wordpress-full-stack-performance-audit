# Module 00 - WOW Principles (tuning fork)

> **Purpose:** This file is not a diagnostic module. It is a **tuning fork** - a set of principles and philosophy from the WOW course (Wielka Optymalizacja WordPressa, Bartlomiej Mis / Web Dev Insider) that should shape **all** your recommendations throughout the entire audit.
>
> **You read this file ONCE**, at the beginning of phase 2, before module 01. Then you keep it in memory and apply it in every module and in phase 10 (report).
>
> Every recommendation you generate should be consistent with the principles below. If any of your proposals violates a principle - stop and reformulate.

---

## 1. Course motto

> **"WordPress optimization is not another plugin. It is a process, architecture, and AI workflow."**

This means: never respond to any problem with "install plugin X and it will work". Always respond with a process (diagnose -> fix -> measure after fix). A plugin can be a tool, but it is never the answer.

## 2. Holistic approach to layers

A WordPress request passes through **seven layers** and each one can be a bottleneck:

```
DNS -> TLS/Connect -> Edge/CDN -> Server (PHP) -> WordPress runtime
                                                          |
                                             HTML response (TTFB - backend/frontend boundary)
                                                          |
                              Browser: HTML parsing -> CSS/JS -> Render -> Layout -> Paint
```

**Principle:** You must not skip any layer in the analysis. What looks like a frontend problem (slow LCP) may have its root in DNS, TLS, backend, cache, network, or code. The audit always goes **bottom-up** - because there is no point fixing CSS if TTFB is 3 seconds.

## 3. Field > Lab (RUM beats Lighthouse)

Lighthouse and WebPageTest are **lab** - one machine, one set of conditions, one network. **Field (RUM)** is hundreds/thousands of real users with their real networks and devices. **Lab lies, field tells the truth.**

**Principles:**
- Priority always: RUM p75 > CrUX > WebPageTest > local Lighthouse
- Lab is for **diagnosis** (where to look), field is for **decisions** (what to fix first)
- Without RUM you don't know if your optimization works - only after 7-14 days do you see the impact
- **Every production project should have RUM from day 1**, even a new one with no traffic (CoreDash, DebugBear, RUMvision)
- "I fixed PSI from 40 to 95" means less than "I fixed RUM LCP p75 from 4.2s to 2.1s"

## 4. Diagnosis before fix - "no blind checkboxes"

From the first course lesson: the old WP world was PSI -> recommendations -> checkbox in cache plugin -> test -> blind deployment. PSI score goes up but the site is not actually faster.

**Principle:** Every recommendation must have three things:
1. **What** - a specific change (code, setting, infrastructure)
2. **Why** - which measurement pointed to this problem (from which module, which metric)
3. **How we will measure the effect** - which metric we will observe after implementation

If you don't have all three, **do not give the recommendation**. Say instead "data is missing here, add Query Monitor / APM / phpinfo" rather than guessing.

## 5. Backend first - TTFB is the foundation, fix the source before adding cache

If the backend needs 2 seconds to generate HTML, no lazy-loading, fetchpriority, critical CSS, or CDN will fix that. **First you fix the backend, then the frontend.**

**Critical checkpoint before ANY plugin-swap or code refactor recommendation:** if TTFB uncached is deterministically high (2+ s, narrow variance) and a profiler (Code Profiler Pro, New Relic) shows a known plugin dominating time disproportionately, verify **`realpath_cache_size()` live bytes** via `wow-audit-check.php` (module 04, step 8.2a). If near-zero across multiple refreshes, the bottleneck is **worker recycling / SAPI misconfiguration**, not WordPress. At 1000+ file includes per WordPress request, a cold realpath cache + CloudLinux LVE syscall overhead can add 1-3 s that looks exactly like "plugin X is slow" in a profiler. Fix the server (hosting support, SAPI change, or migrate) before touching the application. See real case: grupastop.pl where WPForms looked like the villain at 1.9 s but the same install ran at ~400 ms on a PHP-FPM host without CloudLinux - the fingerprint was `realpath_cache_size() = 0` on LSAPI vs `9947` on FPM.

**Backend fix hierarchy (the order is sacred):**
1. **PHP version + OPcache + FPM** - free configuration wins, enable immediately
2. **wp-config hygiene** - debug OFF, SAVE_QUERIES OFF, autoload < 2MB, DISABLE_WP_CRON + system cron
3. **Code and query fixes** - this is the critical step:
   - Slow queries from APM/Query Monitor - **indexes, optimization**, custom tables instead of `wp_postmeta` ad nauseam
   - **N+1 queries** - classic: loop over posts + `get_post_meta()` inside = N requests to the database. Fix: one batched query or `update_post_meta_cache()` before the loop
   - **Hook abuse** - a plugin that hooks into `init` and makes 200 db calls on every request
   - **Plugins living in runtime on every page** - dequeue, conditional load, replace
4. **Object cache (Redis/Memcached)** - **ONLY NOW**, as a multiplier for the fixed backend
5. **Full page cache** (WP Rocket / LiteSpeed / Cache Enabler) - another multiplier
6. **CDN / edge cache** - final layer, for static assets and optionally HTML (Cloudflare APO)

**Why this order is sacred:** Cache is an amplifier, not a fix. See principle 5a below.

## 5a. Cache is an amplifier, not a band-aid - always fix the source first

The most common mistake in WordPress optimization: "site is slow -> add Redis -> add WP Rocket -> go to sleep". Result: PSI goes up, but the **real problem persists** and explodes at the first opportunity.

**Principle (ALWAYS, no exceptions):** If APM / Query Monitor / `wow-db-check.php` reveal a **specific backend bottleneck** (slow query, N+1, heavy hook, plugin generating 500 queries per request) - **fix the source first**, only then add a cache layer.

**Why cache is not a fix:**
- **Cold cache miss always hurts**: every new URL (category, product, UTM parameter, fbclid) hits the uncached backend. Slow query = 2s loading for the first user from every campaign. If you run Google Ads - you pay twice: for the click and for the user who bounced
- **Cache invalidation is painful**: every post edit, product addition, WC order change invalidates cache. Without a fixed backend, every invalidation is a return to pain, just random
- **Cache obscures diagnostics**: when someone six months later notices the site is slow, debugging starts from cache (is it working? is it a hit?), instead of the source (is the slow query still there?). The problem was only **deferred and hidden**
- **Cache increases complexity**: additional plugin, additional config, additional bug surface. Zero value if the source is not fixed
- **Cache does not scale with traffic on bad parameters**: cart, logged-in users, search results - all these typically bypass cache. The backend still has to cope

**Correct sequence:**
1. Diagnose: find specific bottlenecks (APM transaction trace, Query Monitor slow queries, WC HPOS check, theme code profiling)
2. Fix: resolve each found bottleneck at the source (index, custom table, code refactor, dequeue, plugin swap, etc.) - **do not add cache**
3. Measure: repeat the measurement without cache. Goal: TTFB **uncached** below 800ms. This is the indicator of a healthy backend.
4. **Only now add cache**: object cache -> page cache -> CDN. Each layer will be an amplifier, not a cover-up for the problem.

**Exception:** if the source requires a refactor (phase 3, several days of work) and the client is in an emergency OR has 2 days until an ad campaign - **temporary** cache as a band-aid is OK, but in the action plan you **MUST** mark it as a band-aid, and the proper fix is in phase 3 and must not be forgotten.

## 6. WP-Cron - a classic trap, always move to system cron

WP-Cron by default is a **fake cron** - it fires on every user visit and runs tasks in **the same PHP process** that serves the page to the user. This means: a random visitor sometimes pays for the fact that their request triggered a backup.

**Principle (always, no exceptions):**
1. `define('DISABLE_WP_CRON', true);` in `wp-config.php`
2. **Together with** a system cron every 1 minute (preferred) or every 5 minutes. **Use direct PHP execution, not curl/HTTP:**
   - **Preferred (direct PHP):** `* * * * * cd /path/to/wordpress && php wp-cron.php >/dev/null 2>&1`
   - **Alternative (WP-CLI):** `* * * * * cd /path/to/wordpress && wp cron event run --due-now >/dev/null 2>&1`
   - **Avoid:** `curl -s 'https://domain/wp-cron.php'` - this adds HTTP overhead (DNS, TLS, full request cycle) and exposes wp-cron.php publicly. Direct PHP skips the web server entirely, is faster, and reduces the attack surface.
3. For managed hosting without SSH - use the built-in scheduler (Kinsta, WP Engine, Cloudways have it in the UI), or cron-as-a-service (cron-job.org, EasyCron). If curl is the only option (e.g. external cron service) - block direct access to `wp-cron.php` from the public via web server rules.

**CRITICAL trap:** `DISABLE_WP_CRON true` **without** a system cron = scheduled tasks simply stop executing. Backups don't run, transactional emails get lost, WooCommerce subscriptions stall. Always both steps together, never one without the other.

## 7. Cache hierarchy - implementation order

WordPress has 5 cache layers. You need to know the order:

1. **OPcache** (PHP runtime) - PHP code in memory. Free, always enable.
2. **Object cache** (Redis/Memcached) - WP_Query results, options, transients in RAM. **Implement before page cache** - it improves performance of all plugins that use the WP cache API.
3. **Database query cache** - less commonly used today (MySQL 8 removed it), usually skip.
4. **Browser cache** - Cache-Control headers on static assets (1 year for hashed assets). **Zero cost, measurable gain** for returning visitors.
5. **Server / Full Page Cache** (WP Rocket, LiteSpeed Cache, Cache Enabler) - serves ready HTML bypassing PHP. **Most powerful** but also most fragile (issues with UTM bypass, logged-in users, WC cart).
6. **CDN / Edge cache** (Cloudflare, Fastly, Bunny) - static assets by default, HTML optionally (Cloudflare APO).

**Principle:** Implement **bottom-up**: OPcache -> object cache -> browser cache headers -> page cache -> CDN. Not the other way around. Page cache on a backend without object cache is a band-aid on a wound.

**Critical addition from principle 5a:** Before you start implementing layers 2-6, **first** fix the actual sources of slowness in the backend (slow queries, N+1, heavy hooks, fat plugins). Cache is an **amplifier for a good backend**, not a fix for a bad one. If you start an audit and see `n+1`, `slow query 800ms`, `plugin generating 200 calls per request` - **fix that FIRST**, only then add cache. Cache on a broken backend only hides the problem.

## 8. Plugins - tame them, don't throw them out

Course myth: "plugins = slow site". Not true. **Not the number of plugins, but their quality and configuration** is what matters.

**Principles:**
- Plugin slowing things down? **First diagnose** (Query Monitor, APM, profiler) before removing it. Maybe it can be configured differently, limited with conditional loading on non-essential pages, or have unnecessary assets dequeued
- Conditional loading: `if (is_page('kontakt')) wp_enqueue_script('contact-form-7-...')` - the rest of the pages don't load it
- Dequeue instead of deactivate: if a plugin leaves a footprint (assets, hooks) on every page, dequeue where not needed instead of disabling entirely
- Page builder bloat: Elementor / WPBakery / Divi load frontend on every page, even if the page was not built with the page builder - dequeue on non-builder pages
- Optimization plugins: **one solution done well** > three stacked on top of each other (Autoptimize + WP Rocket + LiteSpeed = conflict + chaos)
- **Security plugins: move to edge instead of keeping them in PHP runtime.** Wordfence is the classic - 100-500ms TTFB overhead on every request (live traffic monitoring, real-time IP scan, WAF at PHP level, custom tables growing to GB). Cloudflare WAF + bot fight + rate limiting do the same at the edge, **before** reaching your PHP. Plus a lightweight 2FA plugin (e.g., **Two Factor** by WP contributors) for wp-admin. This is a case study of the "from PHP to edge" principle. Specific migration steps in module 05.

## 9. AI in optimization - a partner, not an oracle

From pre-work lesson 6: AI changes every day, follow the documentation. Vendor lock is a risk - **build the skill, not the dependency**.

**Principles:**
- AI should supplement your knowledge and accelerate analysis, not replace understanding
- **Verify** every AI recommendation with data (RUM, profiler, post-implementation test)
- Don't apply changes without understanding - if you don't know why something works, you also don't know why it stops working
- AI Skills (CWV Superpowers, webperf) and MCP (Chrome DevTools, CoreDash) are process tools, not a replacement for diagnostics

## 10. Three fix horizons

Always group recommendations into three levels. Don't dump everything as "to do".

- **Quick wins** (today, max 1h): enable OPcache, add system cron, add `fetchpriority='high'` to LCP image, disable WP_DEBUG on production, set Cache-Control on static assets. Low risk, immediate gain.
- **Phase 2** (this week, up to 1 day each): DNS migration to Cloudflare, page cache configuration, PHP upgrade, dequeue page builder on non-builder pages, rewrite a specific template.
- **Phase 3** (refactor, more than 1 day): change page builder, rewrite custom theme, move to headless, hosting migration, architecture redesign.

The user should be able to take quick wins **this afternoon**, phase 2 **this week**, phase 3 as a **strategic plan**. Mixing all three is a recipe for "I'll leave the audit for later".

---

Read this file ONCE at audit start. Apply principles in every module.
