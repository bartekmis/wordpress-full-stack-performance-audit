# Report Placeholders Reference

> **For the AI:** Use this file instead of reading `report-template.html`. The template is pure CSS/HTML boilerplate - you only need to know the placeholder names and their types to generate report content. Read the full template ONLY at the final assembly step.

## HTML structure

The report uses these CSS classes:
- `.badge-good / .badge-warn / .badge-bad / .badge-info` - severity badges
- `.metric.good / .metric.warn / .metric.bad` - metric cards with `.label` + `.value`
- `.finding.bad / .finding.warn / .finding.good` - finding blocks with `h4` + `p` + `.evidence`
- `.action` - action plan items with `h4` + `.impact`
- `.quick-wins / .phase-2 / .phase-3` - action plan sections (left-border colored)
- `.pagetype-section` - per-page-type card
- `.cover` - cover section with `dl` grid
- `.toc` - table of contents

## Placeholders by section

### Cover
`{{LANG}}`, `{{DOMAIN}}`, `{{DATE}}`, `{{SITE_URL}}`, `{{ENVIRONMENT}}`, `{{HOSTING_TYPE}}`, `{{PAGE_TYPES_COUNT}}`, `{{PAGE_TYPES_LIST}}`, `{{RUM_STATUS}}`, `{{WPT_STATUS}}`, `{{APM_STATUS}}`

### Executive summary
`{{TOP_FINDING_1_TITLE}}`, `{{TOP_FINDING_1_DESC}}`, `{{TOP_FINDING_1_EVIDENCE}}` (repeat for 2, 3)
Metric cards: `{{TTFB_CLASS}}`, `{{TTFB_VALUE}}`, `{{LCP_CLASS}}`, `{{LCP_VALUE}}`, `{{INP_CLASS}}`, `{{INP_VALUE}}`, `{{CLS_CLASS}}`, `{{CLS_VALUE}}`

### Methodology
`{{METHODOLOGY_TEXT}}`, `{{PROBE_RUNS}}`, `{{RUM_METHOD}}`, `{{WPT_METHOD}}`, `{{APM_METHOD}}`

### DNS (section 3.1)
Table rows: `{{DNS_PROVIDER}}` + `_STATUS`, `{{DNS_ANYCAST}}` + `_STATUS`, `{{DNS_LOOKUP_MS}}` + `_STATUS`, `{{DNS_NS_COUNT}}`, `{{DNS_TTL}}`, `{{DNS_PRECONNECT_COUNT}}` + `_STATUS`
`{{DNS_FINDINGS}}` - HTML list of finding blocks

### TLS (section 3.2)
Table rows: `{{TLS_VERSION}}` + `_STATUS`, `{{HTTP_PROTOCOL}}` + `_STATUS`, `{{HSTS_PRESENT}}` + `_STATUS`, `{{HTTPS_REDIRECT}}` + `_STATUS`, `{{TLS_HANDSHAKE_FIRST}}`, `{{TLS_HANDSHAKE_REPEAT}}` + `{{TLS_RESUMPTION_STATUS}}`
`{{TLS_FINDINGS}}`

### Server (section 4)
Table rows: `{{WEB_SERVER}}`, `{{PHP_VERSION}}` + `_STATUS`, `{{OPCACHE_STATUS}}` + `{{OPCACHE_BADGE}}`, `{{OBJECT_CACHE_TYPE}}` + `_STATUS`, `{{TTFB_CACHED}}` + `_STATUS`, `{{TTFB_UNCACHED}}` + `_STATUS`, `{{AB_RESULTS}}`
`{{SERVER_FINDINGS}}`

### WordPress (section 5)
Table rows: `{{WP_MEMORY_LIMIT}}` + `_STATUS`, `{{DISABLE_WP_CRON}}` + `_STATUS`, `{{WP_POST_REVISIONS}}` + `_STATUS`, `{{WP_DEBUG}}` + `_STATUS`, `{{SAVE_QUERIES}}` + `_STATUS`, `{{AUTOLOAD_SIZE}}` + `_STATUS`, `{{REVISIONS_COUNT}}` + `_STATUS`, `{{QM_OBJECT_CACHE_RATIO}}` + `_STATUS`
`{{WP_FINDINGS}}`

### Code Review (section 6a)
`{{CR_FILES_COUNT}}`, `{{CR_DIRS_COUNT}}`, `{{CR_BAD_COUNT}}`, `{{CR_WARN_COUNT}}`
`{{CR_FINDINGS_ROWS}}` - table rows: file | line | rule | severity badge | description
`{{CR_RULE_EXAMPLES}}` - `<details>` blocks per unique rule with `<pre><code>` fix example
`{{CR_SKIPPED_PLUGINS}}`

### APM (section 6)
`{{APM_SECTION}}` - full HTML block (table + findings, or "skipped" note)

### Cache (section 7)
Table 7.1: `{{PAGE_CACHE_LAYER}}`, `{{PAGE_CACHE_HEADER}}`, `{{CACHE_PROGRESSION}}`, `{{CACHE_BYPASS_URL}}`, `{{CACHE_BYPASS_COOKIE}}`
Table 7.2: `{{STATIC_CACHE_CONTROL}}` + `_STATUS`, `{{COMPRESSION_TYPE}}` + `_STATUS`, `{{CDN_HIT_RATIO}}` + `_STATUS`
`{{CACHE_FINDINGS}}`

### Frontend (section 8)
`{{PAGETYPE_COMPARISON_ROWS}}` - table rows: page type | TTFB | FCP | LCP | CLS | Long tasks | Requests | Bytes
`{{PAGETYPE_SECTIONS}}` - one `.pagetype-section` div per page type containing: metrics, LCP element, render-blocking, top requests, 3rd party, findings

### RUM (section 9)
`{{RUM_SECTION}}` - full HTML block

### RUM Replay (section 9a)
`{{INP_REPLAY_SECTION}}` - table + collapsible details per interaction
`{{LCP_REPLAY_SECTION}}` - table + collapsible details per page
`{{CLS_REPLAY_SECTION}}` - table + collapsible details per shift

### Correlations (section 10)
TTFB table: `{{CORR_TTFB_CURL_CACHED}}`, `{{CORR_TTFB_CURL_UNCACHED}}`, `{{CORR_TTFB_LAB_MOBILE}}`, `{{CORR_TTFB_LAB_DESKTOP}}`, `{{CORR_TTFB_RUM}}` (each with `_NOTE`)
`{{CORR_TTFB_CONCLUSION}}`, `{{CORR_LAB_FIELD_TEXT}}`, `{{CORR_PAGETYPE_TEXT}}`

### Action plan (section 11)
`{{QUICK_WINS}}`, `{{PHASE_2}}`, `{{PHASE_3}}` - each contains `.action` blocks

### Appendix (section 12)
`{{RAW_NETWORK_OUTPUT}}`, `{{SCREENSHOT_LIST}}`, `{{COMMAND_LOG}}`

## Report generation procedure

Placeholder content is generated **progressively** - each module saves its section's values to `tmp/section-*.json` during phases 2-9. See the "Progressive report data" table in `WOW-AUDIT.md` for the full mapping.

1. Phase 10 reads all `tmp/section-*.json` files
2. Generates only the cross-cutting placeholders (exec summary, methodology, correlations, action plan, appendix) into `tmp/section-synthesis.json`
3. Reads `report-template.html` ONCE
4. Replaces all `{{PLACEHOLDER}}` tokens using merged values from all JSON files
5. Saves as `wow-audit-YYYY-MM-DD.html`
