# Module 07 - Cache (page, object, CDN, compression)

> **Goal:** Verify the full cache stack: page cache (header HIT/MISS), static asset cache (Cache-Control), compression (gzip/br/zstd), CDN, object cache, background AJAX. Based on WOW course lesson 7.

## AUTO Steps

### 1. Detect the full page cache layer (3 consecutive requests)

A cache plugin or CDN sets a header like `x-cache`, `x-cf-cache`, `x-litespeed-cache` etc. The first visit = MISS, the second is already a HIT.

```bash
for i in 1 2 3; do
  echo "--- Request $i ---"
  curl -sI {{SITE_URL}} | grep -iE "^(x-cache|cf-cache-status|x-litespeed-cache|x-wp-super-cache|x-cache-status|x-rocket-cache|wp-rocket|x-cache-handler|x-edge-cache|x-cdn-cache|via|age|x-served-by):"
  sleep 1
done
```

Possible headers and their meaning:
- `cf-cache-status: HIT` -> Cloudflare cache hit
- `cf-cache-status: MISS` -> miss (not cached or bypass)
- `cf-cache-status: DYNAMIC` -> Cloudflare does not cache (typical for HTML without APO)
- `x-litespeed-cache: hit` -> LiteSpeed Cache HIT
- `x-cache: HIT, HIT` (Varnish) or `x-cache: HIT cache.example.net` (CDN)
- `x-rocket-cache: cached` -> WP Rocket
- `x-cache-handler: cache enabler` -> Cache Enabler
- `age: 1234` -> CDN cache, present for X seconds in cache
- no cache header at all -> no page cache

Save `PAGE_CACHE_LAYER` (e.g. "Cloudflare APO + WP Rocket") and `PAGE_CACHE_PROGRESSION` (e.g. "MISS -> HIT -> HIT").

### 2. Test bypass on URL parameters

Many cache plugins bypass cache if the URL has parameters (UTM, fbclid, gclid). This is a problem - ad campaigns hit the uncached backend.

```bash
echo "=== Without parameter ==="
curl -sI {{SITE_URL}} | grep -iE "^(cf-cache-status|x-cache|x-litespeed-cache):"

echo "=== With UTM ==="
curl -sI "{{SITE_URL}}?utm_source=test&utm_medium=email" | grep -iE "^(cf-cache-status|x-cache|x-litespeed-cache):"

echo "=== With fbclid ==="
curl -sI "{{SITE_URL}}?fbclid=test123" | grep -iE "^(cf-cache-status|x-cache|x-litespeed-cache):"

echo "=== With gclid ==="
curl -sI "{{SITE_URL}}?gclid=test123" | grep -iE "^(cf-cache-status|x-cache|x-litespeed-cache):"
```

If cache breaks with parameters (MISS/DYNAMIC/BYPASS) - flag it.

Save `CACHE_BYPASS_URL_PARAMS` as a map: `{utm: HIT/MISS, fbclid: HIT/MISS, gclid: HIT/MISS}`.

### 3. Test bypass on logged-in user cookie

Cache plugins bypass cache for logged-in users - that is OK. Check whether the bypass is limited to the appropriate cookies.

```bash
echo "=== Without cookie ==="
curl -sI {{SITE_URL}} | grep -iE "^(cf-cache-status|x-cache):"

echo "=== With fake login cookie ==="
curl -sI -H "Cookie: wordpress_logged_in_test=1" {{SITE_URL}} | grep -iE "^(cf-cache-status|x-cache):"

echo "=== With fake WC cart cookie ==="
curl -sI -H "Cookie: woocommerce_items_in_cart=1" {{SITE_URL}} | grep -iE "^(cf-cache-status|x-cache):"
```

Save `CACHE_BYPASS_COOKIES`.

### 4. Basic "smoke" cache check on static files

Fetch the HTML, find the first CSS and JS file, check their headers.

```bash
HTML=$(curl -sL {{SITE_URL}})
# First CSS
CSS_URL=$(echo "$HTML" | grep -oE '<link[^>]*rel="stylesheet"[^>]*href="[^"]*\.css[^"]*"' | head -1 | grep -oE 'href="[^"]*"' | sed 's/href="//;s/"//')
# First JS
JS_URL=$(echo "$HTML" | grep -oE '<script[^>]*src="[^"]*\.js[^"]*"' | head -1 | grep -oE 'src="[^"]*"' | sed 's/src="//;s/"//')
# First image
IMG_URL=$(echo "$HTML" | grep -oE '<img[^>]*src="[^"]*\.(jpg|jpeg|png|webp|avif)[^"]*"' | head -1 | grep -oE 'src="[^"]*"' | sed 's/src="//;s/"//')

# Prepend domain if relative
[[ "$CSS_URL" =~ ^// ]] && CSS_URL="https:$CSS_URL"
[[ "$CSS_URL" =~ ^/ ]] && CSS_URL="{{SITE_URL}}${CSS_URL}"

echo "=== CSS: $CSS_URL ==="
curl -sI "$CSS_URL" | grep -iE "^(cache-control|expires|content-encoding|content-type|age|cf-cache-status):"

echo "=== JS: $JS_URL ==="
curl -sI "$JS_URL" | grep -iE "^(cache-control|expires|content-encoding|content-type|age|cf-cache-status):"

echo "=== IMG: $IMG_URL ==="
curl -sI "$IMG_URL" | grep -iE "^(cache-control|expires|content-encoding|content-type|age|cf-cache-status):"
```

Extract and save:
- `STATIC_CACHE_CONTROL_CSS`
- `STATIC_CACHE_CONTROL_JS`
- `STATIC_CACHE_CONTROL_IMG`

**Threshold:**
- `max-age >= 31536000` (1 year) on static assets with hash in the name -> good
- `max-age 86400-2592000` (1 day - 30 days) -> ok (for non-hashed)
- `max-age < 3600` or no Cache-Control -> bad
- `no-cache, no-store` -> critical gap

### 5. Compression (gzip / brotli / zstd)

Check per type what compression methods are supported.

```bash
echo "=== HTML gzip ==="
curl -sI -H "Accept-Encoding: gzip" {{SITE_URL}} | grep -i "content-encoding"

echo "=== HTML brotli ==="
curl -sI -H "Accept-Encoding: br" {{SITE_URL}} | grep -i "content-encoding"

echo "=== HTML zstd ==="
curl -sI -H "Accept-Encoding: zstd" {{SITE_URL}} | grep -i "content-encoding"

echo "=== CSS gzip ==="
curl -sI -H "Accept-Encoding: gzip" "$CSS_URL" | grep -i "content-encoding"

echo "=== CSS brotli ==="
curl -sI -H "Accept-Encoding: br" "$CSS_URL" | grep -i "content-encoding"
```

Save `COMPRESSION_HTML`, `COMPRESSION_CSS`. Brotli > gzip (better compression). Zstd > brotli (even better, but less browser support until recently).

### 6. Background AJAX audit (via Chrome DevTools MCP)

During module 08 (frontend) use `list_network_requests` with a Fetch/XHR filter. Here just note that this needs to be done - you actually execute it in module 08, but results feed back into this report section.

In this step you can fetch the raw HTML and check if there are inline AJAX endpoints defined:
```bash
curl -sL {{SITE_URL}} | grep -oE 'admin-ajax\.php|wc-ajax|wp-json' | sort -u
```

If you see `admin-ajax.php` - there is a good chance cart fragments or other background polling is present. Save `AJAX_ENDPOINTS_DETECTED`.

## ASK Steps

### F1. Cloudflare cache hit ratio
> "If you use Cloudflare:
> 1. Go to **Cloudflare dashboard -> Your domain -> Analytics & Logs -> Traffic**
> 2. Select 7-day time range
> 3. Find the **Cached vs Uncached requests** or **Bandwidth saved** section
> 4. Send a screenshot
>
> Tell me: what % of requests are cached by CF (target: > 80%)?"

Save `CLOUDFLARE_HIT_RATIO`.

### F2. Cache plugin configuration
> "If you use **WP Rocket / W3 Total Cache / LiteSpeed Cache / WP-Super-Cache** - go to its settings and take screenshots of the main tabs (Cache, Static Files, Database, CDN). I am interested in:
> - Is object cache enabled?
> - Is CSS/JS minify enabled?
> - Is preload enabled?
> - Are there exclusions (URL/cookies) - which ones?
> - What TTL does the page cache have?"

Save `CACHE_PLUGIN_SETTINGS_SCREENS`.

### F3. APO (Cloudflare Automatic Platform Optimization)
> "Do you have Cloudflare APO for WordPress enabled? Check in the panel **Cloudflare -> Speed -> Optimization -> WordPress -> APO**. APO moves the entire HTML to edge - a dramatic TTFB improvement globally. Cost: $5/month/site, but usually worth it."

Save `CF_APO_ENABLED`.

## Findings

| Condition | Sev | Finding |
|---|---|---|
| `CACHE_PROGRESSION = MISS,MISS,MISS` | bad | Page cache not working. Check config/exclusions |
| `PAGE_CACHE_LAYER = none` | bad | No page cache. Implement WP Rocket / LiteSpeed / APO |
| UTM/fbclid/gclid = MISS | bad | Ad campaigns hit uncached backend. Enable "Ignore query strings" |
| `STATIC_CC_CSS < 7d` | warn | Short CSS cache. Set 1y for hashed, 30d for others |
| `STATIC_CC_IMG = none` | bad | No Cache-Control on images. Add `max-age=31536000` |
| `COMPRESSION_HTML = none` | bad | No compression - 5-10x larger transfer. Enable gzip/brotli |
| `COMPRESSION = gzip` only | info | Brotli gives ~20% better compression. Enable in Cloudflare |
| `CF_HIT_RATIO < 60%` | warn | Low hit ratio. Check exclusions, params, cookies |
| `CF_APO = NO` + CF active | info | APO moves HTML to edge ($5/mo). Worth it for global traffic |
| `admin-ajax.php` + WC active | info | Likely WC cart fragments. Disable or replace |

## Report data

Save: `PAGE_CACHE_LAYER`, `PAGE_CACHE_HEADER`, `CACHE_PROGRESSION`, `CACHE_BYPASS_URL`, `CACHE_BYPASS_COOKIE`, `STATIC_CACHE_CONTROL` + `_STATUS`, `COMPRESSION_TYPE` + `_STATUS`, `CDN_HIT_RATIO` + `_STATUS`, `CACHE_FINDINGS`.
