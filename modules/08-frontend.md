# Module 08 - Frontend per page type

> **Goal:** Full lab tests of each page type, using Chrome DevTools MCP, diagnostic snippets from `scripts/snippets/`, and WPT JSON parsing (if provided). Based on WOW course lessons 7 and 8.

---

## Execution model - MANDATORY: one sub-agent per (page_type × profile)

This module's diagnostic battery is large (navigation, trace, insights, network, console, lighthouse, 14 snippets, screenshot, pre-FCP classification, coverage). Running inline in the main agent has historically led to silent skipping ("roughly approximated", "skipped desktop", "only ran 2 snippets"). **That is no longer acceptable.**

### Main agent orchestration

The main agent's ONLY job in this module is to:

1. Determine the combos to run:
   - `combos = [(pt, "mobile") for pt in PAGE_TYPES]`
   - **If `INCLUDE_DESKTOP = true`** (set in phase 1.3b): also add `[(pt, "desktop") for pt in PAGE_TYPES]`
2. For each combo, spawn one sub-agent sequentially (NOT in parallel - Chrome DevTools MCP uses one tab, emulation state is shared)
3. Collect each sub-agent's structured result into `PT_RESULTS[pt][profile]`
4. After all combos finish, run the cross-combo steps (7c WPT waterfall per PT, per-PT consolidation)
5. Save `tmp/section-frontend.json`

The main agent MUST NOT run the diagnostic tool calls itself. All `navigate_page`, `emulate`, `performance_start_trace`, `evaluate_script`, `list_network_requests`, `list_console_messages`, `lighthouse_audit`, `take_screenshot` calls belong to the sub-agent.

### Sub-agent spec (per combo)

Spawn with:

- **description:** `"Module 08 frontend diagnostics: {{PT_NAME}} on {{PROFILE}}"`
- **prompt:** must include ALL of the following:
  - Page type name and URL
  - Profile (mobile/desktop) with exact emulation config from "Test profiles" section below
  - The absolute path to `wow-audit/scripts/snippets/` (14 files) and `wow-audit/scripts/pre-fcp-classify.js`
  - The explicit tool-call checklist below
  - Required output schema (JSON keys the main agent expects back)
  - Explicit instruction: **"Execute every item on the checklist. If a tool call fails, report the error and continue. You must NOT silently skip or 'approximate'. If you cannot complete an item, return `status: skipped` with a reason for that item. Inline-approximating a snippet by writing your own JS is forbidden - read the snippet file and pass its contents to `evaluate_script`."**

### Tool-call checklist for the sub-agent (in order)

The sub-agent executes these in this exact order. Each item is a literal tool call, not a narrative.

| # | Tool call | Purpose | Saves to |
|---|---|---|---|
| 1 | `emulate(...)` (config per profile) | Set mobile or desktop emulation | - |
| 2 | `navigate_page({ url: PT_URL })` | Open the page | - |
| 3 | `wait_for({ ... })` | Wait for content render | - |
| 4 | `performance_start_trace({ reload: true, autoStop: true })` | Cold-load trace | trace handle |
| 5 | `performance_analyze_insight({ insight: "lcp" })` | LCP breakdown | `lcp` |
| 6 | `performance_analyze_insight({ insight: "cls" })` | CLS analysis | `cls` |
| 7 | `performance_analyze_insight({ insight: "long-tasks" })` | Long task data | `longTasks` |
| 8 | `performance_analyze_insight({ insight: "render-blocking" })` | Render-blocking | `renderBlocking` |
| 9 | `performance_analyze_insight({ insight: "layout-shifts" })` | Layout shift sources | `layoutShifts` |
| 10 | `list_network_requests({ pageSize: 200, resourceTypes: [...] })` | Network breakdown | `network` |
| 11 | `list_console_messages()` | Console errors/warnings | `console` |
| 12 | `lighthouse_audit({ url, formFactor, categories })` | Lighthouse scores | `lighthouse` |
| 13-26 | For each of the 14 snippet files in `scripts/snippets/`: `evaluate_script({ function: <file contents> })` then `get_console_message()` | Diagnostic snippets | `snippets[filename]` (merge return value + console output) |
| 27 | `evaluate_script({ function: <contents of scripts/pre-fcp-classify.js> })` | Pre-FCP resource classification | `preFcp` |
| 28 | `take_screenshot({ fullPage: false })` | Above-fold screenshot | `screenshot` |

**Snippet files to execute as items 13-26 (in order):**
1. `LCP.js`
2. `LCP-Sub-Parts.js`
3. `CLS.js`
4. `LongTask.js`
5. `Resource-Hints.js`
6. `Fonts-Preloaded-Loaded-and-used-above-the-fold.js`
7. `First-And-Third-Party-Script-Info.js`
8. `Image-Element-Audit.js`
9. `Find-Images-With-Lazy-and-Fetchpriority.js`
10. `Find-non-Lazy-Loaded-Images-outside-of-the-viewport.js`
11. `Validate-Preload-Async-Defer-Scripts.js`
12. `Find-render-blocking-resources.js`
13. `Head-Order-Audit.js`
14. `WP-Bloat-Detection.js`

### Required sub-agent output

Return a JSON object with this shape:

```json
{
  "page_type": "homepage",
  "profile": "mobile",
  "checklist_items_completed": 28,
  "checklist_items_skipped": [],
  "lcp": { ... },
  "cls": { ... },
  "longTasks": { ... },
  "renderBlocking": { ... },
  "layoutShifts": { ... },
  "network": { ... },
  "console": { ... },
  "lighthouse": { ... },
  "snippets": {
    "LCP.js": { "returnValue": ..., "consoleOutput": ... },
    "LCP-Sub-Parts.js": { ... },
    "...": { ... }
  },
  "preFcp": { ... },
  "screenshot": "saved"
}
```

### Main agent verification (MANDATORY after each sub-agent returns)

- `checklist_items_completed` must equal 28. If not, log which items were skipped and the reasons. If any snippet is missing, name it explicitly.
- `snippets` must contain exactly 14 keys.
- If `checklist_items_completed < 26`, the sub-agent failed - report to user and offer to retry.

---

The sections below (Test profiles, per-step details, WPT waterfall analysis, findings) are the REFERENCE material the sub-agent and main agent use. The sub-agent reads them to know what to do in each step; the main agent uses them for WPT parsing and cross-combo synthesis. They are no longer a main-agent execution playbook.

---

## Test profiles

### Mobile profile

```
chrome-devtools.emulate({
  device: "Motorola G Power",  // alt: "Moto G4", "Galaxy S5"
  network: "Fast 4G",
  cpu: 4
})
```

### Desktop profile

```
chrome-devtools.emulate({
  device: null, width: 1920, height: 1080,
  network: "no-throttle", cpu: 1
})
```

---

## Per page type - AUTO steps

Execute the following **for each** page type. Keep it concise: short summary + key metrics.

### Step 1. Cache priming (mobile)

```
chrome-devtools.navigate_page({ url: "{{PT_URL}}" })
chrome-devtools.wait_for({ text: "<text from page>", timeout: 30000 })
```

### Step 2. Performance trace - cold load

```
chrome-devtools.performance_start_trace({ reload: true, autoStop: true })
// After completion:
chrome-devtools.performance_analyze_insight({ insight: "lcp" })
chrome-devtools.performance_analyze_insight({ insight: "cls" })
chrome-devtools.performance_analyze_insight({ insight: "long-tasks" })
chrome-devtools.performance_analyze_insight({ insight: "render-blocking" })
chrome-devtools.performance_analyze_insight({ insight: "layout-shifts" })
```

Extract and save:
- `PT_TTFB_MOBILE`, `PT_FCP_MOBILE`, `PT_LCP_MOBILE`
- `PT_LCP_ELEMENT_MOBILE` - CSS selector and element type
- `PT_CLS_MOBILE`, `PT_CLS_SOURCES_MOBILE` - top elements
- `PT_TBT_MOBILE` - Total Blocking Time (INP proxy in lab)
- `PT_LONG_TASKS_MOBILE` - count and time of top 3
- `PT_RENDER_BLOCKING_MOBILE` - list of render-blocking files

### Step 3. Network breakdown

```
chrome-devtools.list_network_requests({
  pageSize: 200,
  resourceTypes: ["document", "script", "stylesheet", "image", "font", "fetch", "xhr", "media"]
})
```

Save: `PT_REQUESTS_TOTAL_MOBILE`, `PT_BYTES_TOTAL_MOBILE`, `PT_TOP_5_REQUESTS_BY_BYTES`, `PT_TOP_5_REQUESTS_BY_TIME`, `PT_THIRD_PARTY_DOMAINS` (domains != HOST + total weights), `PT_FONTS` (woff/woff2, local vs Google), `PT_IMAGES` (count, total KB, webp/avif vs jpg/png).

### Step 4. Console messages

```
chrome-devtools.list_console_messages()
```

Extract errors/warnings: 404, mixed content, jQuery deprecated, failed-to-load. Save `PT_CONSOLE_ERRORS_MOBILE`.

### Step 5. Lighthouse audit (if available in MCP)

```
chrome-devtools.lighthouse_audit({
  url: "{{PT_URL}}", formFactor: "mobile",
  categories: ["performance", "accessibility", "best-practices"]
})
```

Save: `PT_LH_SCORE_PERF_MOBILE`, `PT_LH_TOP_OPPORTUNITIES_MOBILE` (top 5), `PT_LH_DIAGNOSTICS_MOBILE`.

### Step 6. Diagnostic snippets (reference)

Executed as items 13-26 of the sub-agent checklist (see "Execution model" at the top of this module). Two-step capture per snippet: `evaluate_script({ function: <file contents> })` then `get_console_message()`. Merge return value + console output, key by filename. The snippets are from [nucliweb/webperf-snippets](https://github.com/nucliweb/webperf-snippets) and output primarily via console messages.

Results saved per snippet, per page type, per profile as `PT_SNIPPETS`.

### Step 7. Screenshot

```
chrome-devtools.take_screenshot({ fullPage: false })
```

### Step 7b. Pre-FCP Resource Audit (waterfall analysis before rendering)

Critical frontend step. It's not enough to know "you have 5 render-blocking resources" - you need to determine **per resource**: is it critical, can it be deferred, removed, and HOW in WordPress.

#### 7b.1 Collect pre-FCP waterfall

```
chrome-devtools.evaluate_script({
  function: `() => {
    const fcp = performance.getEntriesByName('first-contentful-paint')[0];
    const fcpTime = fcp ? fcp.startTime : 0;
    const resources = performance.getEntriesByType('resource')
      .filter(r => r.startTime < fcpTime)
      .map(r => ({
        name: r.name, type: r.initiatorType,
        startTime: Math.round(r.startTime), duration: Math.round(r.duration),
        transferSize: r.transferSize, decodedBodySize: r.decodedBodySize,
        renderBlocking: r.renderBlockingStatus || 'unknown',
        domain: new URL(r.name).hostname
      }))
      .sort((a, b) => a.startTime - b.startTime);
    const nav = performance.getEntriesByType('navigation')[0];
    return {
      fcp_ms: fcpTime ? Math.round(fcpTime) : null,
      ttfb_ms: nav ? Math.round(nav.responseStart) : null,
      dom_interactive_ms: nav ? Math.round(nav.domInteractive) : null,
      pre_fcp_resources_count: resources.length,
      pre_fcp_resources_total_kb: Math.round(resources.reduce((s, r) => s + (r.transferSize || 0), 0) / 1024),
      resources
    };
  }`
})
```

#### 7b.2 Per-resource classification - 5 categories

For EACH resource in the list, classify into one of these categories:

**A. CRITICAL** - must stay on the critical path:
- Main theme CSS (`style.css`, `theme.min.css`)
- Main theme JS **if** responsible for above-fold rendering
- jQuery **if** dependency for above-fold functionality
- Preloaded font(s) used in above-fold text

**B. DEFERRABLE** - can be loaded AFTER FCP. Use the **deferral hierarchy** (best to worst):

> **Deferral hierarchy - always try the highest level first:**
>
> 1. **On interaction** (best) - load JS/CSS only when the user starts using the feature. Zero cost until then. Works when the element's resting appearance is acceptable without the plugin's assets (plain HTML form, empty map container, placeholder image).
> 2. **On visibility** - load when element enters viewport (IntersectionObserver). Good for below-fold widgets, maps, video, carousels.
> 3. **On timer / idle** - load after a delay (setTimeout 5-10s) or `requestIdleCallback`. For features that must be ready "soon" but not at render time (chat, cookie consent).
> 4. **Page-conditional** (fallback) - `is_page('contact')` / `is_checkout()`. Use when levels 1-3 are not possible (e.g. the plugin heavily transforms the DOM and the unstyled state looks broken).
> 5. **Load everywhere but defer** (last resort) - `wp_script_add_data('handle', 'strategy', 'defer')`. The script still downloads on every page but does not block rendering.

**Before choosing the level, check: does removing the plugin's CSS break the element's appearance?**
- **Form plugins (WPForms, CF7, Gravity Forms):** HTML `<form>` + `<input>` elements are styled acceptably by the browser and theme CSS without the plugin's CSS. The plugin's CSS adds polish (colors, spacing, validation states) but the form is usable without it. -> **Level 1 (on interaction)**: dequeue both JS and CSS, load them when the user focuses any field inside the form. The form appears as a styled HTML form, and the plugin's assets load instantly on first focus (imperceptible delay).
- **Maps:** Empty container is fine with a placeholder background. -> **Level 2 (on visibility)**
- **Video embeds:** Facade/thumbnail is fine. -> **Level 2 (on visibility, click to play)**
- **Chat widgets:** Invisible until loaded. -> **Level 3 (timer/idle)**
- **Payment SDKs:** Not needed until checkout flow. -> **Level 4 (page-conditional)**
- **Sliders/carousels:** If unstyled state shows all slides stacked, the flash is ugly. -> **Level 4 or 5** (or add minimal inline CSS for the resting state, then Level 2)

Deferrable resources with suggested level:
- reCAPTCHA/hCaptcha - **Level 1**: load on form field focus (see 7c.4 for code pattern)
- Form plugins (WPForms, CF7, Gravity Forms) - **Level 1**: dequeue JS+CSS, load on first `focus` event inside the form container. The form's HTML renders from the theme, plugin assets load on interaction
- Chat widgets (Tidio, Intercom, Tawk, Crisp, Drift) - **Level 3**: delay 5-10s or load on first scroll/click
- Video embeds (YouTube, Vimeo) - **Level 2**: facade pattern (lite-youtube-embed), load on click
- Google Maps - **Level 2**: IntersectionObserver, load when map container enters viewport
- Payment SDK (Stripe, PayPal) - **Level 4**: only on checkout (`is_checkout()`)
- Social embeds - **Level 2**: IntersectionObserver or static link
- Cookie consent - **Level 3**: load after DOMContentLoaded, reserve min-height for banner

**C. DEFERRABLE ANALYTICS** - must load, but NOT render-blocking:
- GTM - move to end of `<body>`, triggers on `Window Loaded`
- GA4 - ensure `async` is set
- FB Pixel - defer, consider Conversions API (server-side)
- Session recording (Hotjar, Clarity) - `requestIdleCallback` + 10-20% sampling

**D. REMOVABLE** - can be removed entirely:
- `wp-emoji-release.min.js` - `remove_action('wp_head', 'print_emoji_detection_script', 7)`
- `jquery-migrate.min.js` - WP 5.5+ can work without it
- `dashicons.min.css` - `wp_dequeue_style('dashicons')` if not used on frontend
- `wp-embed.min.js` - `wp_dequeue_script('wp-embed')` if you don't embed
- `block-library/style.min.css` - 16KB CSS, `wp_dequeue_style('wp-block-library')` if not using Gutenberg

**E. CONCATENATABLE** - many small files to combine:
- > 3 CSS/JS files from the same origin before FCP -> concatenation candidates

#### 7b.3 Automatic classification (script)

Run `scripts/pre-fcp-classify.js` via `evaluate_script`. The script automatically classifies resources into categories A-E based on known URL patterns.

```
chrome-devtools.evaluate_script({
  function: `<contents of scripts/pre-fcp-classify.js>`
})
```

Save result as `PT_PRE_FCP_AUDIT`.

#### 7b.4 WordPress-specific recommendations per resource

For deferrable/removable resources, generate recommendations with WordPress code. **Always apply the deferral hierarchy from 7b.2** - prefer on-interaction (Level 1) over page-conditional (Level 4).

Techniques by hierarchy level:

- **Level 1 (on interaction):** `wp_dequeue_script`/`wp_dequeue_style` globally, then in `wp_footer` output a small inline `<script>` that listens for `focus` on form fields (or `click`/`scroll` for other elements) and dynamically creates `<link>` + `<script>` elements to load the plugin's assets. The AI should generate the specific code per plugin found in the audit, using the correct asset handles and paths from the detected plugin.
- **Level 2 (on visibility):** IntersectionObserver on the container element. See 7c.4 for Google Maps code pattern.
- **Level 3 (timer/idle):** `setTimeout(load, 5000)` or `requestIdleCallback(load)` in `wp_footer`.
- **Level 4 (page-conditional):** `is_page()` / `is_checkout()` / `is_singular('product')` wrapping `wp_dequeue_*` in `functions.php`.
- **Level 5 (defer attribute):** WP 6.3+ `wp_script_add_data('handle', 'strategy', 'defer')`.
- **Removable:** `remove_action` / `wp_dequeue_style` / `wp_dequeue_script` in `functions.php`.
- **Concatenation:** >3 CSS/JS from same origin -> WP Rocket/Autoptimize "Combine".

**Visual safety check:** Before recommending Level 1, verify in Chrome DevTools that disabling the plugin's CSS does not break the element's appearance. Quick test: in Elements panel, uncheck the plugin's `<link>` stylesheet and observe. If the element looks acceptable with just browser defaults + theme CSS - Level 1 is safe. If it looks broken - fall back to Level 4 or add minimal inline CSS for the resting state.

### Step 7c. WPT Waterfall Analysis (if WPT available)

If `WPT_AVAILABLE = true` or the user can provide a WPT waterfall for this page type.

#### 7c.1 Get the waterfall

ASK the user:
> "For **{{PT_NAME}}**, provide the WebPageTest waterfall. Options:
> A) Place the WPT result JSON in `wow-audit/wpt/mobile/{{pt_name}}.json` (I'll parse it)
> B) Paste a screenshot of the WPT waterfall view
> C) Paste the WPT test URL (e.g. `https://www.webpagetest.org/result/...`)
> D) Skip WPT waterfall for this page type"

If JSON: parse `data.median.firstView.requests` array. Each request has `url`, `responseCode`, `bytesIn`, `request_type`, `renderBlocking`, `startOffset` (ms from navigation start), `load_start`, `load_end`, `ttfb_start`, `ttfb_end`, `download_start`, `download_end`, `contentType`.

If screenshot: analyze the waterfall image visually. The **green vertical line** = Start Render.

#### 7c.2 Identify the Start Render line

From JSON: `data.median.firstView.render` (ms) = Start Render time.
From screenshot: the green vertical line.

Save `PT_WPT_START_RENDER_MS`.

#### 7c.3 Classify every request BEFORE Start Render

For each request where `startOffset < PT_WPT_START_RENDER_MS` (or visually before the green line), determine:

**Is this resource necessary for first render?**

| Resource type | Necessary? | Deferral level (from 7b.2 hierarchy) |
|---|---|---|
| Main HTML document | YES | - |
| Main theme CSS | YES | Keep, but check size |
| Critical above-fold JS (menu, layout) | YES | Keep, consider `defer` if not layout-critical |
| jQuery (if above-fold depends on it) | MAYBE | Check if above-fold actually needs it. If not: Level 5 (defer) |
| **Form plugins** (WPForms, CF7, Gravity) | NO | **Level 1** - dequeue JS+CSS, load on form field `focus`. Form's HTML renders fine with theme CSS |
| **Google reCAPTCHA / hCaptcha** | NO | **Level 1** - load on form field `focus` (see 7c.4 code) |
| **Google Maps** | NO | **Level 2** - IntersectionObserver on map container |
| **Video embeds** (YouTube, Vimeo) | NO | **Level 2** - facade (lite-youtube-embed), load on click |
| **Social embeds** (Twitter, Instagram) | NO | **Level 2** - IntersectionObserver or static link |
| **Chat widgets** (Tidio, Intercom, Tawk, Crisp) | NO | **Level 3** - delay 5-10s or first scroll/click |
| **Cookie consent** | NO | **Level 3** - load after DOMContentLoaded, reserve `min-height` |
| **GTM / GA4 / analytics** | NO | **Level 3** - `<head>` after CSS, `async`, trigger on `Window Loaded` |
| **Session recording** (Hotjar, Clarity) | NO | **Level 3** - `requestIdleCallback` + sampling |
| **FB Pixel / tracking** | NO | **Level 3** - defer, consider server-side Conversions API |
| **Payment SDK** (Stripe, PayPal) | NO | **Level 4** - page-conditional (`is_checkout()`) |
| **WP defaults** (emoji, jquery-migrate, dashicons, wp-embed, block-library CSS) | NO | **Remove** via `remove_action` / `wp_dequeue_*` in `functions.php` |
| Fonts not used above fold | NO | Remove preload, let browser discover naturally |
| Images below fold | NO | Add `loading='lazy'`, remove any `fetchpriority` |
| Unknown 3rd-party script | INVESTIGATE | Check what it does. Apply highest possible deferral level |

**Key principle:** Everything before Start Render **delays what the user sees**. For each resource, always try the highest deferral level first (Level 1 = on interaction). Only fall back to lower levels if the unstyled/unloaded state looks visually broken. Use the visual safety check from 7b.4.

#### 7c.4 Generate per-resource recommendations

For each non-necessary resource before Start Render, generate a specific recommendation:

Format: `{{resource_url}} ({{size_kb}}KB, starts at {{start_ms}}ms) - {{classification}} - {{WP technique}}`

**On-demand loading patterns** (use in recommendations):

**reCAPTCHA / hCaptcha on-demand (load when user interacts with form):**
```php
// In functions.php: remove default reCAPTCHA enqueue
add_action('wp_enqueue_scripts', function() {
    wp_dequeue_script('google-recaptcha');
    wp_dequeue_script('wpcf7-recaptcha');
});

// In footer: load reCAPTCHA only when user focuses any form field
add_action('wp_footer', function() {
    if (!is_page(['contact', 'kontakt'])) return;
    ?>
    <script>
    (function() {
        var loaded = false;
        document.querySelectorAll('input, textarea, select').forEach(function(el) {
            el.addEventListener('focus', function() {
                if (loaded) return;
                loaded = true;
                var s = document.createElement('script');
                s.src = 'https://www.google.com/recaptcha/api.js?render=YOUR_SITE_KEY';
                document.head.appendChild(s);
            }, { once: true });
        });
    })();
    </script>
    <?php
});
```

**Google Maps on-demand (load when map enters viewport):**
```js
const mapContainer = document.querySelector('#map');
if (mapContainer) {
    new IntersectionObserver((entries, obs) => {
        if (entries[0].isIntersecting) {
            const s = document.createElement('script');
            s.src = 'https://maps.googleapis.com/maps/api/js?key=KEY&callback=initMap';
            document.head.appendChild(s);
            obs.disconnect();
        }
    }).observe(mapContainer);
}
```

#### 7c.5 Cross-validate with 7b (Pre-FCP audit)

Compare WPT waterfall findings with the browser Performance API results from step 7b:
- Resources flagged by both = high confidence
- Resources flagged only by WPT = may be timing-dependent (different network, location)
- Resources flagged only by 7b = WPT may have cached them differently

Save `PT_WPT_WATERFALL_AUDIT` with: `start_render_ms`, `requests_before_render_count`, `requests_before_render_total_kb`, `unnecessary_before_render` (list with resource, size, classification, WP technique).

#### 7b.5 Save pre-FCP audit results

Per page type save:
- `PT_PRE_FCP_TOTAL` / `PT_PRE_FCP_TOTAL_KB`
- `PT_PRE_FCP_DEFERRABLE` - list (name, service, priority, WP technique)
- `PT_PRE_FCP_REMOVABLE` - list (with WP code for `functions.php`)
- `PT_PRE_FCP_CONCAT_CANDIDATES`, `PT_PRE_FCP_UNKNOWN_3P`

Findings from this step go to report section 8 (per-resource table) and action plan in phase 10.2.

### Step 8. Desktop profile (opt-in, controlled by `INCLUDE_DESKTOP`)

Desktop is NOT run by default. Doubles audit time and rarely changes the action plan materially (CWV are mobile-weighted by Google). Run only if user opted in at phase 1.3b.

If `INCLUDE_DESKTOP = true`: the main orchestrator adds `(pt, "desktop")` combos to the sub-agent queue. No separate "step 8 repeat" here - each combo is a full sub-agent run with the desktop emulation config.

### Step 9. Coverage (unused CSS/JS)

Read from `lighthouse.audits['unused-css-rules']` and `lighthouse.audits['unused-javascript']` in the sub-agent's `lighthouse` result (step 12 of the checklist). If lighthouse didn't provide them, ASK the user:
> "Open DevTools -> More tools -> Coverage -> reload. Send a screenshot sorted by `Unused Bytes`."

---

## Parsing WPT JSON (if student provided files)

If `WPT_AVAILABLE = true`, check files in `wow-audit/wpt/mobile/` and `wow-audit/wpt/desktop/`.

For each file extract from `data.median.firstView`:
- `TTFB`, `firstContentfulPaint`, `largestContentfulPaint`, `cumulativeLayoutShift`, `TotalBlockingTime`, `SpeedIndex`
- `requests` (count), `bytesIn` (total), `domains` (third-party breakdown)

Save `WPT_RESULTS_BY_PT` as a map `{ "homepage": { "mobile": {...}, "desktop": {...} }, ... }`.

---

## Per page type correlation

Comparison table per page type:

| Profile | Source | TTFB | FCP | LCP | CLS | TBT/INP | Requests | KB | Pre-FCP reqs | Pre-FCP KB | Deferrable | Removable |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Mobile | Lab (Chrome DT MCP) | ... | ... | ... | ... | ... | ... | ... | ... | ... | ... | ... |
| Mobile | WPT median | ... | ... | ... | ... | ... | ... | ... | - | - | - | - |
| Mobile | RUM p75 | ... | ... | ... | ... | ... | - | - | - | - | - | - |
| Desktop | Lab / WPT / RUM | ... | ... | ... | ... | ... | ... | ... | ... | ... | ... | ... |

Conclusions: lab vs field correlation, WPT vs lab, mobile vs desktop.

## Findings per page type

CWV thresholds: LCP <2.5s, INP/TBT <200ms, CLS <0.1, TTFB <800ms.

| Condition | Sev | Finding |
|---|---|---|
| `PRE_FCP_REMOVABLE > 0` | bad | {{n}} removable resources. Generate `functions.php` snippets |
| `PRE_FCP_DEFERRABLE > 0` (high) | bad | {{n}} high-priority deferrable: {{list}} |
| `PRE_FCP_DEFERRABLE > 0` (medium/analytics) | warn | Analytics render-blocking. GTM to body, defer, Partytown |
| `PRE_FCP_UNKNOWN_3P > 0` | warn | {{n}} unknown 3P before FCP. Check manually |
| `PRE_FCP_TOTAL > 30` | bad | {{n}} resources before FCP (target: 8-15) |
| `PRE_FCP_TOTAL_KB > 500` | bad | {{kb}}KB before FCP (target: <200KB) |
| Chat/reCAPTCHA/Maps on homepage | bad | {{service}} loading unnecessarily. Conditional loading |
| LCP no fetchpriority/preload | bad | Quick fix: `fetchpriority='high'` + preload |
| LCP load_delay >500ms | bad | LCP discovered late. Preload + fetchpriority |
| CLS from images without dimensions | bad | {{n}} images without width/height |
| Non-lazy outside viewport >3 | warn | Add `loading='lazy'` |
| Font preloaded but unused above fold | warn | Remove unnecessary preloads |
| Font used above fold but not preloaded | bad | Add `<link rel='preload' as='font'>` |
| Preload + async/defer conflict | bad | Priority conflict. Remove preload or add fetchpriority='low' |
| Blocking script before CSS in head | bad | Move after CSS or add async/defer |
| Render-blocking >5 | warn | {{n}} render-blocking resources |
| WPT: unnecessary before Start Render >3 | bad | {{n}} non-critical resources before Start Render ({{kb}}KB). Each one delays first paint |
| WPT: reCAPTCHA/hCaptcha before render | bad | reCAPTCHA loads before render on {{PT_NAME}}. Load on-demand on form field focus |
| WPT: chat/maps/video before render | bad | {{service}} before Start Render. Defer: interaction trigger / IntersectionObserver / facade |
| WPT: 7b+7c both flag same resource | bad | {{resource}} confirmed unnecessary by both lab + WPT. High-confidence deferral candidate |
| WP-Bloat-Detection: presentCount >= 3 | warn | {{n}} standard WP bloat items in <head> ({{list}}). **Quick win:** drop `wow-audit/scripts/recommendations/wp-cleanup.php` as mu-plugin (`wp-content/mu-plugins/wow-wp-cleanup.php`). Removes all in one file. |
| WP-Bloat-Detection: emoji present | warn | wp-emoji-release.min.js loaded. ~17KB JS + inline detection script. Not needed unless you rely on emoji fallback. See wp-cleanup.php. |
| WP-Bloat-Detection: heartbeat_frontend present | warn | Heartbeat script polls every 15s on frontend. Deregister unless you have a feature depending on it. |
| WP-Bloat-Detection: dashicons_frontend present | warn | Dashicons CSS loaded for non-logged-in users. Dequeue when admin bar not showing. |

## Report data

Section 8: page type comparison table + per-page-type section (metrics, LCP element, pre-FCP audit, WPT waterfall audit with per-resource classification, render blocking, top requests, 3P, findings).
