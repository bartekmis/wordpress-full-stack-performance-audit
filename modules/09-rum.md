# Module 09 - RUM (Real User Monitoring)

> **Goal:** Fetch field data from CoreDash RUM (or fallback from screenshots) and compare with lab results from module 08. Based on WOW course lesson 9.

## Operating modes

In phase 1 the user chose `RUM_MODE`:
- **A** - CoreDash MCP configured, min. 7 days of data
- **B** - min. 7 days of data, but sends screenshots
- **C** - preliminary data (`RUM_PRELIMINARY = true`, < 7 days) - execute like A/B, but flag metrics with badge `<span class="badge badge-info">preliminary, n={{pageviews}}, {{days}}d</span>`
- **D** - no RUM, save `RUM_SECTION = "RUM audit skipped (no RUM). Recommended: install CoreDash and wait 7 days."` and go to phase 10.

**Mode C rules:** don't draw statistical conclusions if `RUM_TOTAL_PAGEVIEWS < 100`. Use words like "preliminary observation", "signal". In the action plan add: "Repeat RUM audit after {{14 - days}} days."

---

## Mode A: CoreDash MCP

### Step A1. Check access
Call any CoreDash MCP tool. If unavailable - reset to mode B.

### Step A2. Site overview
```
coredash.query({ domain: "{{DOMAIN}}", metric: "overview", range: "{{RUM_DAYS}}d" })
```
Save: `RUM_TOTAL_PAGEVIEWS`, `RUM_LCP_P75_MOBILE/DESKTOP`, `RUM_INP_P75_MOBILE/DESKTOP`, `RUM_CLS_P75_MOBILE/DESKTOP`, `RUM_TTFB_P75_MOBILE/DESKTOP`, `RUM_FCP_P75_MOBILE/DESKTOP`.

### Step A3. Per page type breakdown
```
coredash.query({ domain: "{{DOMAIN}}", url: "{{PT_URL}}", metric: "cwv", range: "{{RUM_DAYS}}d", segment: ["device", "country", "connection"] })
```
Save per page type: `PT_RUM_LCP/INP/CLS/TTFB_P75_MOBILE/DESKTOP`, `PT_RUM_PAGEVIEWS`, `PT_RUM_TOP_COUNTRIES` (top 3), `PT_RUM_TOP_DEVICES` (top 3).

### Step A4. Top worst pages global
```
coredash.query({ domain: "{{DOMAIN}}", metric: "worst_pages", by: "lcp", limit: 10, range: "{{RUM_DAYS}}d" })
```
Repeat for `inp`, `cls`, `ttfb`. Save `RUM_TOP_WORST_LCP/INP/CLS/TTFB`. If it reveals a URL not in `PAGE_TYPES`:
> "RUM shows the worst LCP is on URL `{{url}}` - we didn't audit it in lab. Want to add it?"

### Step A5. LCP attribution
```
coredash.query({ domain: "{{DOMAIN}}", metric: "lcp_attribution", range: "{{RUM_DAYS}}d" })
```
For each slow LCP extract: `url`, `lcp_element_selector`, `lcp_element_tag`, `lcp_element_url` (if image), `lcp_p75_ms`, phase breakdown (`phase_ttfb_ms`, `phase_load_delay_ms`, `phase_load_duration_ms`, `phase_render_delay_ms`), `pageviews`, `device_breakdown`. Top 5 by `lcp_p75_ms` -> `LCP_SLOW_PAGES`.

### Step A5b. CLS attribution
```
coredash.query({ domain: "{{DOMAIN}}", metric: "cls_attribution", range: "{{RUM_DAYS}}d" })
```
For each slow CLS extract: `url`, `shift_sources` (element_selector, shift_value, prev_rect, current_rect), `top_shifting_element`, `cls_p75`, `pageviews`, `device_breakdown`. Top 5 by `cls_p75` -> `CLS_SLOW_PAGES`.

### Step A6. INP attribution
```
coredash.query({ domain: "{{DOMAIN}}", metric: "inp_attribution", range: "{{RUM_DAYS}}d" })
```
For each slow INP interaction extract: `url`, `interaction_type` (click/keydown/submit), `target_selector`, `target_dom_path` (if available), `inp_p75_ms`, phase breakdown (`phase_input_delay_ms`, `phase_processing_ms`, `phase_presentation_ms`), `pageviews_with_interaction`, `device_breakdown`. Top 5 by `inp_p75_ms` -> `INP_SLOW_INTERACTIONS`.

### Step A7. Trend over time
```
coredash.query({ domain: "{{DOMAIN}}", metric: "trend", metric_name: "lcp", range: "30d", granularity: "day" })
```
Repeat for INP, CLS, TTFB. Check for regression in the last 30 days. Save `RUM_TRENDS`.

---

## Mode B: CoreDash screenshots

Ask for screenshots in this order:
1. **Overview**: CWV metrics (LCP/INP/CLS) last 7 days, mobile + desktop
2. **Top worst pages**: sorted by LCP desc (top 10), repeat for INP and CLS
3. **Per page type**: filtered by URL, LCP/INP/CLS p75
4. **LCP attribution**: top 5 slow pages with element selector, p75, 4-phase breakdown (TTFB/LoadDelay/LoadDuration/RenderDelay). Save as `LCP_SLOW_PAGES`
5. **CLS attribution**: top 5 with shifting element, shift value, CLS p75. Save as `CLS_SLOW_PAGES`
6. **INP attribution**: top 5 with URL, interaction type, target selector, INP ms. Save as `INP_SLOW_INTERACTIONS`

Extract same fields as Mode A from screenshots.

---

## Step 5 - RUM Root Cause Replay (lab repro of slow CWV)

RUM tells WHAT/WHERE, trace tells WHY. RUM attribution = hypothesis, performance trace = proof. Replay top 3-5 per metric. Skip if all lists empty.

### Common setup
```
chrome-devtools.emulate({ device: "Motorola G Power", network: "Fast 4G", cpu: 4 })
```

### Replay template (applied to INP, LCP, CLS below)
1. **Navigate + stabilize**: `navigate_page` + `wait_for` (2s for late scripts)
2. **Verify element**: `evaluate_script` with `querySelector({{selector}})`, check visibility
3. **Trace**: `performance_start_trace` -> trigger -> `performance_stop_trace` -> `performance_analyze_insight`
4. **Cross-check**: compare lab element/selector with RUM. Match = high confidence, mismatch = cache/A-B/viewport
5. **Diagnose**: identify bottleneck phase + culprit from trace call stack
6. **Save result**: `{ rum: {...}, lab: {...}, diagnosis: { bottleneck_phase, culprit, confidence } }`

---

### 5a - INP Replay

Per interaction from `INP_SLOW_INTERACTIONS`:
- **Trace config**: `reload: false, autoStop: false`
- **Trigger**: click -> `chrome-devtools.click()`, keydown -> `evaluate_script` with `dispatchEvent`, submit -> `requestSubmit()`
- **Insights**: `interaction`, `long-tasks`
- **Cross-check**: RUM target may be misleading (event bubbling/delegation). Extract actual handler from trace call stack. Save both `rum_target_selector` and `actual_handler_location` if mismatch
- **Phases**: `input_delay > 200ms` = 3P blocking main thread, `processing > 200ms` = app handler (from trace), `presentation > 100ms` = DOM mutations

**Script-to-recommendation mapping:**

| Script pattern | Fix |
|---|---|
| `jquery` | Migrate to vanilla JS |
| `elementor-frontend` | Dequeue on non-builder pages |
| `gtm.js` / `analytics` / `gtag` | Defer tags, Partytown |
| `hotjar` / `clarity` | `requestIdleCallback` + 10% sampling |
| `facebook` / `fbevents` | Defer, Conversions API |
| `recaptcha` | Conditional `is_page('contact')` |
| theme custom JS | Code-split, defer, rAF/setTimeout |

---

### 5b - LCP Replay

Per page from `LCP_SLOW_PAGES`:
- **Trace config**: `reload: true, autoStop: true` (LCP is a loading metric)
- **Insights**: `lcp`, `render-blocking`, `lcp-discovery`
- **Extract**: lab_lcp_ms, element, 4-phase breakdown, discovery_late, priority_hint, preload, render_blocking_scripts

**Phase-to-fix mapping:**

| Dominant phase | Fix |
|---|---|
| `ttfb > 800ms` | Backend problem - modules 04/05/06 |
| `load_delay > 500ms` | `<link rel="preload">` + `fetchpriority="high"` |
| `load_duration > 1000ms` | webp/avif, responsive srcset, CDN |
| `render_delay > 200ms` | defer/async non-critical, inline critical CSS |

Quick wins: `discovery_late + !fetchpriority`, legacy format >200KB, LCP from different domain = preconnect.

---

### 5c - CLS Replay

Per page from `CLS_SLOW_PAGES`:
- **Trace config**: `reload: true, autoStop: false` + wait 8s + optionally scroll slowly
- **Scroll script**: `evaluate_script` with `setInterval(scrollBy(0,200), 300)` until page bottom
- **Insights**: `cls`, `layout-shifts`

**Trigger-to-fix mapping:**

| Trace pattern | Fix |
|---|---|
| Shift after font load | `font-display: optional` or `size-adjust` |
| `<img>` without width/height | Add `width`/`height` |
| iframe without dimensions | Container with `aspect-ratio: 16/9` |
| Dynamically added element | `min-height` on container or `position: fixed` |
| Lazy image without dimensions | width/height + lazy |
| JS hydration shift | Match server/client layout |

---

## Step 6 - Lab vs Field correlation

For each page type:

| Metric | Lab mobile | RUM p75 mobile | Divergence | Comment |
|---|---|---|---|---|
| TTFB | ... | ... | ... | ... |
| LCP | ... | ... | ... | ... |
| INP / TBT | ... | ... | ... | ... |
| CLS | ... | ... | ... | ... |

**Interpretation:**
- **Lab << RUM** (lab better): typical, lab doesn't simulate real user network conditions
- **Lab >> RUM** (lab worse): rare - lab triggered something users don't (cookie consent, pop-up)
- **CLS lab > 0, RUM = 0**: cookie banner accepted in cache
- **LCP lab different element than RUM**: different viewport/cache state

Save `CORR_LAB_FIELD_TEXT`.

## Findings

| Condition | Sev | Finding |
|---|---|---|
| `RUM_LCP_P75 > 4000` | bad | LCP p75 {{ms}}ms (good: 2500ms) |
| `RUM_INP_P75 > 500` | bad | INP p75 {{ms}}ms (good: 200ms) |
| `RUM_CLS_P75 > 0.25` | bad | CLS p75 {{val}} |
| `RUM_TTFB_P75 > 1800` | bad | TTFB p75 {{ms}}ms |
| 30-day regression | bad | {{metric}} degraded {{pct}}% in last 30 days |
| Mobile >>2x desktop | info | Optimize mobile-first |
| Worst URL not in PAGE_TYPES | info | Consider adding to audit |
| INP replay: input_delay >200ms | bad | 3P blocking. Defer/Partytown |
| INP replay: processing >200ms | bad | App handler. Refactor {{script}}:{{fn}} |
| INP replay: presentation >100ms | bad | DOM mutations / layout thrashing |
| INP replay: confidence low | info | Lab didn't reproduce. Retry with longer throttle |
| INP replay: bubbling/delegation | info | RUM target != actual handler |
| LCP replay: load_delay + no fetchpriority | bad | Quick fix: fetchpriority + preload |
| LCP replay: legacy format >200KB | bad | Convert to webp/avif |
| LCP replay: render_delay | bad | Defer/async blocking scripts |
| LCP replay: ttfb >800ms | info | TTFB problem - fix backend first |
| LCP replay: element mismatch | info | Cache/A-B/viewport difference |
| CLS replay: no dimensions | bad | Add width/height |
| CLS replay: font swap | warn | `font-display: optional` / `size-adjust` |
| CLS replay: late injection | bad | Reserve min-height or position:fixed |
| CLS replay: lab << RUM | info | EU cookie banner / ad / personalization |

## Report data

Section 9: CWV p75 overview, per page type, top 5 worst, attribution, trend, findings.
Section 9a: `{{INP_REPLAY_SECTION}}` + `{{LCP_REPLAY_SECTION}}` + `{{CLS_REPLAY_SECTION}}` (RUM vs Lab tables + diagnosis). If list empty: "No slow {{metric}} detected".
Section 10: `{{CORR_LAB_FIELD_TEXT}}` correlation table.
