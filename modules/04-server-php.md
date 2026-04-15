# Module 04 - Server and PHP

> **Goal:** Measure TTFB (cached vs uncached), detect web server, determine PHP version, OPcache, object cache, optionally run a load test. Based on WOW course lesson 4.

## AUTO Steps

### 1. Web server detection

```bash
curl -sI {{SITE_URL}} | grep -iE "^(server|x-powered-by|x-litespeed|x-nginx|x-cache|x-engine):"
```

Possible values:
- `Server: nginx` -> Nginx
- `Server: Apache` -> Apache
- `Server: LiteSpeed` -> LiteSpeed
- `Server: cloudflare` -> behind Cloudflare proxy (hides origin server)
- `X-Powered-By: PHP/8.x.x` -> PHP version (if the server exposes it)
- `X-LiteSpeed-Cache: hit` -> LiteSpeed Cache active

Save `WEB_SERVER` and `PHP_VERSION_HEADER` (if exposed).

### 2. TTFB curl - 5 cached attempts

Server-layer cache (Page Cache, CDN) usually catches the first request and serves the rest from memory. We measure 5 attempts and take the median.

```bash
for i in 1 2 3 4 5; do
  curl -o /dev/null -s -w "%{time_starttransfer}\n" -H "Cache-Control: no-cache" {{SITE_URL}}
done
```

Note: the `Cache-Control: no-cache` header does not always reach the origin (CDN may ignore it). That is why we also measure uncached separately (step 3).

Convert seconds to ms (e.g. `0.247` -> `247ms`). Sort the 5 values and take the middle one = median. Save `TTFB_CACHED_MS`.

### 3. TTFB curl - 5 uncached attempts (cache bust)

Add a random URL parameter that forces a cache miss on most cache layers:

```bash
for i in 1 2 3 4 5; do
  RANDOM_PARAM="wow_audit_$(date +%s)_$i"
  curl -o /dev/null -s -w "%{time_starttransfer}\n" "{{SITE_URL}}?${RANDOM_PARAM}=1"
done
```

Median = `TTFB_UNCACHED_MS`.

**Interpretation:**
- If `TTFB_UNCACHED >> TTFB_CACHED` (e.g. 2500ms vs 80ms): cache works great, but **the backend without cache is slow**. Every new URL (category, product, parameter) hits the slow backend.
- If `TTFB_UNCACHED ~ TTFB_CACHED`: cache is not working or the backend is consistently fast.
- If both are high (> 1s): there is no cache and the backend is slow - critical problem.

### 4. TTFB stability (oversold hosting?)

From the 5 cached attempts, calculate the variance. If the difference between min and max > 500ms - flag: unstable server (typical symptom of oversold shared hosting).

```bash
# Min, max, average from 5 cached attempts
```

Save `TTFB_VARIANCE_MS` (max - min).

### 5. Does the server respond with gzip/br/zstd on the HTML itself

```bash
curl -sI -H "Accept-Encoding: gzip, br, zstd" {{SITE_URL}} | grep -i "content-encoding"
```

If there is no `Content-Encoding` header - HTML is served without compression (rare but does happen). Flag.

Save `HTML_COMPRESSION` (e.g. `br`, `gzip`, `none`).

### 6. HTML size (compressed and uncompressed)

```bash
SIZE_GZ=$(curl -sH "Accept-Encoding: gzip" {{SITE_URL}} | wc -c)
SIZE_RAW=$(curl -s {{SITE_URL}} | wc -c)
echo "raw: ${SIZE_RAW}B, compressed: ${SIZE_GZ}B"
```

Save `HTML_SIZE_RAW_KB` and `HTML_SIZE_COMPRESSED_KB`. If HTML > 200KB raw - that is a lot, candidate for trimming (usually inline CSS from a page builder).

### 7. Full timing breakdown (network vs backend)

```bash
curl -o /dev/null -s -w "DNS: %{time_namelookup}s | TCP: %{time_connect}s | TLS: %{time_appconnect}s | Wait/TTFB: %{time_starttransfer}s | Download: %{time_total}s\n" {{SITE_URL}}
```

`time_starttransfer - time_appconnect` = pure backend time (after connection established, before the first byte). Save as `BACKEND_PURE_MS`.

This is the real metric for "how long the server takes to generate HTML" stripped of network overhead.

### 8. wow-audit-check.php - MANDATORY

Single diagnostic file collecting 8 sections: PHP runtime, OPcache, CPU benchmark, FPM, system, MySQL, WordPress DB, constants. File: `wow-audit/scripts/wow-audit-check.php`.

#### 8.1 Upload

> "Upload `wow-audit/scripts/wow-audit-check.php` to WordPress root as `wow-audit-check-{{RANDOM}}.php`. Open in browser, paste the URL."

Save `AUDIT_CHECK_URL`. Fallback if upload impossible: D1 (Site Health).

#### 8.2 Fetch and parse JSON

```bash
curl -sL "{{AUDIT_CHECK_URL}}" | sed -n '/WOW_AUDIT_JSON_START/,/WOW_AUDIT_JSON_END/p' | sed '1d;$d' > /tmp/wow-audit-check.json
```

JSON sections and key variables to save:
- `php.*`: `PHP_VERSION`, `PHP_SAPI`, `PHP_MEMORY_LIMIT`, `EXTENSIONS_LOADED` (redis/memcached/opcache/imagick/gd), `REALPATH_CACHE_CURRENT_BYTES`, `REALPATH_CACHE_ENTRIES` (live values from `realpath_cache_size()` / `realpath_cache_get()` - see "Realpath cache / SAPI fingerprint" below)
- `opcache.*`: `OPCACHE_ENABLED`, `OPCACHE_HIT_RATE`, `OPCACHE_MEMORY_USED_MB`, `OPCACHE_MEMORY_WASTED_PCT`, `OPCACHE_OOM_RESTARTS`, `OPCACHE_KEYS_USED_PCT`, `OPCACHE_CACHED_SCRIPTS`, `OPCACHE_JIT`
- `benchmark.*`: `BENCH_MD5_MS`, `BENCH_MD5_RATING`, `BENCH_IO_MS`, `BENCH_IO_RATING`
- `fpm.*`: `FPM_DETECTED`, `FPM_PM`, `FPM_MAX_CHILDREN`, `FPM_MAX_REQUESTS`, `FPM_MAX_CHILDREN_REACHED`
- `fpm_recommendation.*`: `FPM_REC_STATUS` (ok/under/over)
- `system.*`: `SYS_RAM_TOTAL_MB`, `SYS_RAM_AVAILABLE_MB`, `SYS_RAM_USED_PCT`, `SYS_CPU_CORES`, `SYS_LOAD_1M`
- `mysql.*`: `MYSQL_VERSION`, `MYSQL_BUFFER_POOL_MB`, `MYSQL_BUFFER_HIT_RATIO`, `MYSQL_TMP_DISK_RATIO`, `MYSQL_PING_MS`
- `wordpress.*`: `AUTOLOAD_SIZE_MB`, `AUTOLOAD_TOP20`, `REVISIONS_COUNT`, `PLUGINS_ACTIVE_LIST`, `WC_ACTIVE`, `WC_HPOS`, `NON_INNODB_TABLES`, `OBJECT_CACHE_EXTERNAL`
- `constants.*`: `WP_DEBUG`, `SAVE_QUERIES`, `DISABLE_WP_CRON`, `WP_POST_REVISIONS`, `WP_MEMORY_LIMIT`, `SCRIPT_DEBUG`

Note: `wordpress.*` data feeds module 05 - no separate script needed. On managed hosting `system.*`/`fpm.*` may be empty.

#### 8.2a Realpath cache / SAPI fingerprint (MANDATORY read)

After fetching `wow-audit-check.php`, inspect the "Realpath cache (SAPI fingerprint)" subsection under section 1. It contains the LIVE output of `realpath_cache_size()` and `realpath_cache_get()` for the PHP process that served the request.

**What it tells you:** PHP caches resolved absolute file paths between `include`/`require` calls to avoid expensive stat syscalls. On a well-configured host (typically PHP-FPM with sensible `pm.max_requests`), workers stay alive across hundreds/thousands of requests and the realpath cache accumulates thousands of entries. A WordPress homepage touches 500-2000+ file paths, so a healthy worker that has served a few requests should show **> 5000 bytes, tens of entries**.

**When this check is decisive:**
1. TTFB uncached is deterministically high (e.g. 2-4 s) but variance is small (± 10-15%).
2. Code Profiler Pro shows a large plugin (WPForms, WooCommerce, Spectra, Elementor) dominating wallclock time in a way that seems disproportionate to what that plugin does elsewhere.
3. **The same WordPress install runs significantly faster on a different server.**

In these conditions, if `realpath_cache_size()` returns **< 1500 bytes**, and especially if the SAPI is `litespeed`/`lsapi`, you have found the bottleneck. Every `include` call is re-statting the full path because the worker was recycled between requests. At 1000+ file loads per WordPress request and CloudLinux LVE adding 100-300 μs per syscall, this produces 1-3 s of unavoidable server-side overhead that no WordPress-level optimization can remove.

**Verification protocol (ASK user):**
> "Odśwież `{{AUDIT_CHECK_URL}}` 3-5 razy z rzędu i podaj wartość `realpath_cache_size()` (live bytes) za każdym razem."

Interpretation of results:
- All values near zero (< 1500 B) → worker recycles per request. **Server misconfig, not a WordPress problem.** Recommend: ask hosting to raise `LSAPI_CHILDREN` / `LSAPI_MAX_REQS` / LSWS `Max Idle Time`, or switch the account to PHP-FPM, or migrate off this host. Document in report as the primary root cause.
- Values grow across refreshes (e.g. 0 → 4000 → 9000 → 14000) → worker is persistent; the first request was just cold. Not the bottleneck, move on.
- Some refreshes cold, others warm (inconsistent) → multiple workers / load-balanced, cache is per-worker. Common on FPM with high `pm.max_children`. Usually fine.

Save as `REALPATH_CACHE_LIVE_BYTES` (array of values from multiple refreshes) and `SAPI_FINGERPRINT_VERDICT` (healthy/suspected-reset/confirmed-reset).

**Why this matters for the whole audit:** without this check you can spend hours optimizing WordPress-level things (disabling plugins, switching form engines, tuning Redis) to claw back hundreds of milliseconds, when the real 2-second cost is server-side and unfixable from within WordPress. Always run this check before recommending a plugin swap or a code-level refactor as a response to slow TTFB.

#### 8.3 Delete the file

> "DELETE `{{AUDIT_CHECK_URL}}` now - it exposes full server config. Verify 404 in browser."

```bash
curl -o /dev/null -s -w "%{http_code}\n" "{{AUDIT_CHECK_URL}}"
```

## ASK Steps (fallback and supplementary)

### D1. Site Health (fallback if wow-audit-check.php cannot be uploaded)

> "Since the PHP file cannot be uploaded, let's use WordPress Site Health as a fallback. Go to **WordPress Admin -> Tools -> Site Health -> Info**. Expand the **Server**, **Database**, **WordPress constants** sections. Send a screenshot OR paste as text:
> - PHP version
> - PHP memory limit
> - Max execution time
> - Max upload size
> - MySQL/MariaDB version
> - Server type (Nginx/Apache/LiteSpeed)
> - Object cache - enabled?
> - PHP extensions - full list (it is long, but paste everything)"

Save whatever fields you can. phpinfo was better anyway, so note in the report "phpinfo unavailable, data from Site Health is less detailed".

### D3. Apache Bench load test (OPTIONAL, only if the user consented and it is NOT production at peak hour)

> "**WARNING:** Apache Bench will generate 100 requests (10 concurrent) to your site. This is a minimal load test, but if the server is weak it may slow down briefly. **Do you agree to run** `ab -n 100 -c 10 {{SITE_URL}}/?cache_bust=$(date +%s)`?"

If YES:
```bash
ab -n 100 -c 10 "{{SITE_URL}}/?wow_loadtest=$(date +%s)"
```

Extract from the result:
- `Requests per second:` -> `LOAD_RPS`
- `Time per request:` (mean) -> `LOAD_MEAN_MS`
- `Time per request:` (mean, across all concurrent requests) -> the second one
- `Failed requests:` -> if > 0, red flag
- Slowest response from `Percentage of the requests served within a certain time`

If `LOAD_RPS < 5` -> bad (server crumbles under minimal load). If `Failed > 0` -> bad (timeouts, errors).

### D4. Object cache verification
> "In WordPress Admin, check whether you have an active object cache. Quickest way:
> - Site Health -> Status -> Should say `Persistent object cache uses Redis/Memcached`
> - OR in Query Monitor (if installed), Cache tab - should show Object Cache: External
>
> Send a screenshot or say: 'Redis active / Memcached active / no object cache'."

Save `OBJECT_CACHE_TYPE`.

### D5. Database engine
> "If you have access to phpMyAdmin or Adminer, check the **Table Status** view - are all tables using **InnoDB**? MyISAM is an outdated engine without transactions. Send a screenshot of the table list with engines OR write `all InnoDB` / `there are MyISAM` / `I don't know`."

If the student does not have access - skip, write "not verified" in the report.

## Findings

| Condition | Sev | Finding |
|---|---|---|
| `PHP_VERSION < 8.1` | bad | PHP {{ver}} - update to 8.3+. Each version = 10-30% speedup |
| `OPCACHE_LOADED = NO` | bad | OPcache not loaded. Add `zend_extension=opcache.so` |
| `OPCACHE_ENABLED = Off` | bad | OPcache disabled. Set `opcache.enable=1` - free 2-3x speedup |
| `OPCACHE_JIT = disable` (PHP 8+) | info | JIT disabled. Try `opcache.jit=tracing` on staging |
| `OPCACHE_MEM < 128MB` | warn | OPcache memory low. Set `opcache.memory_consumption=256` |
| No redis AND no memcached ext | warn | No object cache extension. Install `php-redis` |
| No imagick AND no gd | warn | No image processing extension |
| `PHP_SAPI = mod_php` | warn | mod_php is less efficient than PHP-FPM. Migrate |
| `PHP_MEMORY_LIMIT < 256M` | warn | Low memory_limit. Set 256M+ |
| `OBJECT_CACHE = none` | warn | No persistent object cache. Enable Redis/Memcached |
| `TTFB_CACHED > 800ms` | bad | TTFB cached {{ms}}ms - above CWV threshold |
| `TTFB_UNCACHED > 2500ms` | bad | Backend slow without cache. Check APM, optimize queries |
| `TTFB_VARIANCE > 500ms` | warn | Unstable TTFB - symptom of oversold shared hosting |
| `HTML_COMPRESSION = none` | bad | No HTML compression. Enable gzip/brotli |
| `HTML_SIZE_RAW > 250KB` | warn | Large HTML - likely inline CSS from page builder |
| `LOAD_RPS < 5` (if ab ran) | bad | Server crumbles under minimal load. Upgrade hosting or increase FPM workers |
| `BENCH_MD5_RATING = slow/very-slow` | bad | CPU slow ({{ms}}ms). Ref: VPS 80-120ms, managed 150-220ms, oversold 300+ms |
| `BENCH_STABILITY = unstable` | bad | CPU unstable between runs - oversold hosting, random TTFB |
| `BENCH_IO_RATING = slow` | warn | Slow disk I/O. Check if hosting uses NVMe |
| `OPCACHE_HIT_RATE < 90%` | warn | OPcache cycling. Increase memory to 256M |
| `OPCACHE_SCRIPTS near max` | warn | Near max_accelerated_files limit. Increase |
| `OPCACHE_OOM_RESTARTS > 0` | bad | OPcache OOM {{n}}x. Increase memory_consumption |
| `OPCACHE_WASTED > 10%` | warn | OPcache fragmentation. Restart FPM periodically |
| `REALPATH_CACHE_LIVE_BYTES < 1500` across 3+ refreshes | bad | Realpath cache not persisting between requests - worker recycles too often. Each include() does a fresh stat syscall. On WP with 1000+ file loads + CloudLinux LVE this adds 1-3 s of TTFB that no WP-level fix removes. Ask hosting to raise `LSAPI_CHILDREN`/`LSAPI_MAX_REQS` / LSWS `Max Idle Time`, switch to PHP-FPM, or migrate. **Server-level root cause - do NOT recommend plugin swaps / Redis / cache as the primary fix when this is triggered.** |
| `REALPATH_CACHE_LIVE_BYTES 1500-5000` inconsistent | warn | Realpath cache partially warm. Multiple workers or aggressive recycling. Investigate hosting worker config. |
| `FPM_MAX_CHILDREN_REACHED > 0` | bad | FPM worker limit hit {{n}}x. Increase max_children or optimize backend |
| `FPM_REC = under-provisioned` | bad | FPM {{current}} < recommended {{rec}}. Increase max_children |
| `FPM_REC = over-provisioned` | warn | FPM {{current}} > recommended. Risk of OOM |
| `FPM pm_max_requests = 0` | warn | No max_requests. Set 500 to prevent memory leaks |
| `SYS_RAM > 90%` | bad | RAM at limit. Add RAM or reduce max_children |
| `SYS_LOAD > 2x cores` | warn | Server overloaded. IO wait or too many processes |
| `MYSQL_BUFFER_POOL < 128MB` | warn | Low buffer pool. Set `innodb_buffer_pool_size=256M` |
| `MYSQL_BUFFER_HIT < 99%` | warn | Buffer pool hit ratio low. Increase pool size |
| `MYSQL_TMP_DISK > 25%` | warn | Temp tables on disk. Increase `tmp_table_size` to 64MB+ |
| `MYSQL_SLOW_LOG = OFF` | info | OK in prod, enable on staging for debugging |
| `MYSQL_PING > 5ms` | warn | Slow DB ping. Normal if remote, check load if local |

## Report data

Save: `WEB_SERVER`, `PHP_VERSION` + `_STATUS`, `OPCACHE_STATUS`, `OBJECT_CACHE_TYPE`, `TTFB_CACHED` + `_STATUS`, `TTFB_UNCACHED` + `_STATUS`, `AB_RESULTS`, `SERVER_FINDINGS`. Appendix: curl/ab/phpinfo output.
