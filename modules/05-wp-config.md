# Module 05 - WordPress wp-config and database

> **Goal:** Verify key `wp-config.php` constants, check autoload size, revision count, debug mode. Based on WOW course lesson 5.

## AUTO Steps

### 1. Read wp-config.php

Read the entire file:
```
Read: ./wp-config.php
```

Extract all `define(...)` occurrences. Specifically, you are looking for:

| Constant | What to check |
|---|---|
| `WP_MEMORY_LIMIT` | value, e.g. `'256M'` |
| `WP_MAX_MEMORY_LIMIT` | value, e.g. `'512M'` |
| `DISABLE_WP_CRON` | true/false |
| `WP_CRON_LOCK_TIMEOUT` | value |
| `WP_POST_REVISIONS` | value or `false` |
| `AUTOSAVE_INTERVAL` | value in seconds |
| `EMPTY_TRASH_DAYS` | value |
| `WP_DEBUG` | true/false |
| `WP_DEBUG_LOG` | true/false |
| `WP_DEBUG_DISPLAY` | true/false |
| `SAVE_QUERIES` | true/false |
| `SCRIPT_DEBUG` | true/false |
| `WP_CACHE` | true/false |
| `CONCATENATE_SCRIPTS` | true/false |
| `COMPRESS_CSS` | true/false |
| `COMPRESS_SCRIPTS` | true/false |
| `WP_REDIS_HOST` | if present - Redis is configured |
| `WP_AUTO_UPDATE_CORE` | value |
| `DISALLOW_FILE_EDIT` | true/false (security) |
| `FORCE_SSL_ADMIN` | true/false |
| `WP_HOME`, `WP_SITEURL` | values - are they hardcoded? |

Save all values as a map. If a constant does not exist, save `default` (with a default explanation).

### 2. Table prefix

In `wp-config.php` find `$table_prefix`. If `wp_` - default (security warn, but not performance). Save `TABLE_PREFIX`.

### 3. Check for advanced-cache.php

```
ls wp-content/advanced-cache.php 2>/dev/null
```

If it exists - some page cache plugin is active. Open the first 20 lines:
```
Read: wp-content/advanced-cache.php (limit 20 lines)
```

The first lines usually reveal which plugin created it (`WP Rocket`, `W3 Total Cache`, `Breeze`, `LiteSpeed Cache`, etc.). Save `PAGE_CACHE_PLUGIN`.

### 4. object-cache.php (drop-in)

```
ls wp-content/object-cache.php 2>/dev/null
```

If it exists - the object cache drop-in is active. Open the header:
```
Read: wp-content/object-cache.php (limit 30 lines)
```

`Redis Object Cache` (Till Kruss), `W3 Total Cache`, `LiteSpeed`, `Memcached Redux` - each has its own file. Save `OBJECT_CACHE_DROPIN`.

### 5. wp-cli (optional, if the student has it in PATH)

If `wp` (WP-CLI) is on the machine, some things can be checked locally from the remote database (if `wp-config.php` has correct credentials):

```bash
which wp 2>/dev/null
```

If available, **ask the user**:
> "I have WP-CLI in PATH. I can run a few commands (`wp option`, `wp post list`) - they operate on the database from `wp-config.php`. If the database is on a remote host and you don't have a tunnel here, the commands won't work. Should I try?"

If YES, run:
```bash
wp doctor check 2>&1 | head -50
wp option get db_version 2>&1
```

If you get a "could not connect" error - the database is remote, skip it. Go to fallback ASK (D6/D7/D8).

### 6. Data from wow-audit-check.php (ALREADY COLLECTED in module 04)

**This step does NOT require a separate file.** The `wow-audit-check.php` script uploaded in module 04 (step 8) returned ALL DB data in the `wordpress.*` and `constants.*` sections of the JSON. Use them:

- `wordpress.autoload.mb` -> `AUTOLOAD_SIZE_MB`, `wordpress.autoload.count` -> `AUTOLOAD_OPTIONS_COUNT`
- `wordpress.autoload_top20` -> `AUTOLOAD_TOP_OPTIONS`
- `wordpress.revisions` -> `REVISIONS_COUNT`
- `wordpress.posts` -> `POSTS_BREAKDOWN`
- `wordpress.comments` -> `COMMENTS_BREAKDOWN`
- `wordpress.tables` -> `DB_TABLES` (with ENGINE per table)
- `wordpress.non_innodb` -> `NON_INNODB_TABLES`
- `wordpress.transients` -> `TRANSIENTS_TOTAL`, `TRANSIENTS_EXPIRED`
- `wordpress.plugins` -> `PLUGINS_ACTIVE_COUNT`, `PLUGINS_ACTIVE_LIST`, `MU_PLUGINS`
- `wordpress.woocommerce` -> `WC_ACTIVE`, `WC_HPOS`, `WC_ORDERS`
- `wordpress.object_cache` -> `OBJECT_CACHE_EXTERNAL`, `OBJECT_CACHE_DROPIN`
- `constants.*` -> `WP_DEBUG`, `DISABLE_WP_CRON`, `WP_POST_REVISIONS`, `WP_MEMORY_LIMIT` etc.

If wow-audit-check.php **was not run** (user could not upload the file in module 04, went the Site Health fallback) - then go to section 6.1 fallback below.

#### 6.1 Fallback - SQL queries (only if wow-audit-check.php was not run)

Ask the user to run SQL queries manually (via phpMyAdmin, Adminer, or WP-CLI):


> "Open phpMyAdmin or Adminer and run the following queries, paste the results:
>
> **Q1 - Autoload size:**
> ```sql
> SELECT
>   COUNT(*) AS option_count,
>   ROUND(SUM(LENGTH(option_value)) / 1024 / 1024, 2) AS autoload_mb
> FROM wp_options
> WHERE autoload IN ('yes', 'on', 'auto-on', 'auto');
> ```
>
> **Q2 - Top 20 autoload options:**
> ```sql
> SELECT option_name, LENGTH(option_value) AS bytes
> FROM wp_options
> WHERE autoload IN ('yes', 'on', 'auto-on', 'auto')
> ORDER BY bytes DESC LIMIT 20;
> ```
>
> **Q3 - Posts breakdown:**
> ```sql
> SELECT post_type, post_status, COUNT(*) AS n
> FROM wp_posts
> GROUP BY post_type, post_status
> ORDER BY n DESC;
> ```
>
> **Q4 - Revisions count:**
> ```sql
> SELECT COUNT(*) FROM wp_posts WHERE post_type = 'revision';
> ```
>
> **Q5 - Comments:**
> ```sql
> SELECT comment_approved, COUNT(*) FROM wp_comments GROUP BY comment_approved;
> ```
>
> **Q6 - Database tables (size + engine):**
> ```sql
> SELECT TABLE_NAME, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb, table_rows, ENGINE
> FROM information_schema.tables
> WHERE table_schema = DATABASE()
> ORDER BY (data_length + index_length) DESC;
> ```
>
> **Q7 - WooCommerce orders (if WC is used):**
> ```sql
> SELECT post_status, COUNT(*) FROM wp_posts WHERE post_type = 'shop_order' GROUP BY post_status;
> ```
> (if you use HPOS, query: `SELECT status, COUNT(*) FROM wp_wc_orders GROUP BY status;`)
>
> Paste the results one by one - I will recognize them by column names."

Save the same variables as in step 6 above (AUTOLOAD_SIZE_MB, REVISIONS_COUNT etc.).

## ASK Steps (supplementary)

### E1. Query Monitor cache hit ratio

> "Install the **Query Monitor** plugin (if not already installed) - it is free, works on production. After activation, refresh any page and click the QM menu in the admin toolbar (at the top). Select the **Cache** or **Object Cache** tab. Send a screenshot - I want to see:
> - Object Cache: external? persistent?
> - Hit ratio (%)
> - Number of misses
> - Backend type (Redis/Memcached/none)"

Save `QM_OBJECT_CACHE_HIT_RATIO`, `QM_OBJECT_CACHE_BACKEND`. Query Monitor data is live (from a specific runtime), so it gives a more accurate picture than just `wp_using_ext_object_cache()` from `wow-db-check.php`.

### E2. Slow queries (from Query Monitor)

> "In the same Query Monitor, click the **Queries by Component** or **Slow Queries** tab. Send a screenshot of the top 10 slowest. I am interested in which component (`core`, `theme`, `plugin: <name>`) generates the slowest queries."

Save `QM_SLOW_QUERIES_TOP` - list of plugins/components responsible for slow queries.

> **Note:** AAAA Option Optimizer as a separate plugin is **unnecessary** if `wow-db-check.php` returned `autoload_top25` - we already have the list of the largest autoload options without installing an additional plugin. Skip this step unless the user could not run the script.

## Findings

| Condition | Sev | Finding |
|---|---|---|
| `WP_MEMORY_LIMIT < 256M` | warn | Low memory limit. Set 256M+ (512M for WC/Elementor) |
| `DISABLE_WP_CRON = false` | bad | WP-Cron on every visit. Fix: (1) `define('DISABLE_WP_CRON', true)` + (2) system cron `* * * * * cd /path/to/wordpress && php wp-cron.php` (direct PHP, not curl - avoids HTTP overhead and public exposure). Both steps mandatory - see principle 6 |
| `WP_POST_REVISIONS missing or >10` | warn | No revision limit. Set `WP_POST_REVISIONS = 5` |
| `WP_DEBUG = true` on prod | bad | Debug ON slows production. Disable |
| `SAVE_QUERIES = true` | bad | Stores ALL queries in memory. Disable on prod |
| `SCRIPT_DEBUG = true` | warn | Loads unminified JS/CSS. Disable on prod |
| `AUTOLOAD_SIZE > 2MB` | bad | Autoload {{mb}}MB - bloated. Clean orphaned options |
| `REVISIONS_COUNT > posts*5` | warn | {{n}} revisions. Clean old ones (backup first) |
| `object-cache.php missing` + Redis configured | warn | Redis configured but drop-in missing. Install Redis Object Cache plugin |
| `QM_HIT_RATIO < 80%` | warn | Low object cache hit ratio. Check anti-spam/WC |
| `WP_HOME/SITEURL missing` | info | Hardcode in wp-config for free DB-skip optimization |
| `DISALLOW_FILE_EDIT != true` | info | Security standard - enable |

## Known plugins with high performance cost

After parsing the active plugin list from `wow-db-check.php` (`PLUGINS_ACTIVE_LIST`), check for the presence of the following plugins. These are **known performance offenders** - for each one we have a specific recommendation aligned with the WOW process (principle: move logic from PHP runtime to edge / to lightweight alternatives).

### Wordfence Security

**Detection:** `PLUGINS_ACTIVE_LIST` contains `wordfence/wordfence.php`. **Severity: bad.**

**Performance costs:** Live traffic monitoring (DB writes every request), PHP-level WAF (attacker already reached server), custom tables grow to GB (`wp_wfHits`), real-time IP scanning, blocks page cache.

**Root-cause fix - move security to edge (Phase 2, ~2-4h + 24-48h observation):**
1. Deploy **Cloudflare** (free): WAF, Bot Fight Mode, Rate Limiting, DDoS protection - all at edge, zero PHP cost
2. Install lightweight **Two Factor** plugin (WP contributors, ~50KB) for wp-admin 2FA
3. Migration: enable Cloudflare first -> install 2FA -> backup DB -> disable Wordfence -> observe 24-48h -> delete -> `DROP` orphaned `wp_wf*` tables -> clean autoload options

**Expected:** TTFB uncached -100-500ms, DB shrinks by GB, cache hit ratio increases. Security does NOT decrease - moved from PHP runtime to edge.

## Report data

Save: `WP_MEMORY_LIMIT` + `_STATUS`, `DISABLE_WP_CRON` + `_STATUS`, `WP_POST_REVISIONS` + `_STATUS`, `WP_DEBUG` + `_STATUS`, `SAVE_QUERIES` + `_STATUS`, `AUTOLOAD_SIZE` + `_STATUS`, `REVISIONS_COUNT` + `_STATUS`, `QM_OBJECT_CACHE_RATIO` + `_STATUS`, `WP_FINDINGS`. Appendix: all defines + QM screenshots.
