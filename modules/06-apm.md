# Module 06 - APM (Application Performance Monitoring)

> **Goal:** Collect backend runtime data - which SQL queries are slow, which plugins/hooks are slowing things down, what is the time distribution (PHP / DB / external). Based on WOW course lesson 6.

## Operating modes

`APM_MODE` from phase 1: **A** (New Relic MCP), **B** (dashboard screenshots), **C** (Query Monitor), **D** (skip).

---

## Mode A: New Relic MCP

### Step A1. Verify MCP

Check if New Relic MCP is available. If not - suggest install (should already be configured in phase 0.3). Fall back to Mode B if unavailable.

### Step A2. Query New Relic

Once configured, for **each page type** execute NRQL queries through MCP:

#### Q1. Average response time per URL
```nrql
SELECT average(duration) FROM Transaction
WHERE host = '{{HOST}}'
AND request.uri LIKE '%{{PT_URL_PATH}}%'
SINCE 7 days ago
```

#### Q2. Top slow transactions
```nrql
SELECT max(duration), average(duration), count(*)
FROM Transaction
WHERE host = '{{HOST}}'
SINCE 7 days ago
FACET request.uri
LIMIT 20
```

#### Q3. Slow database queries
```nrql
SELECT count(*), average(databaseDuration)
FROM Transaction
WHERE host = '{{HOST}}'
SINCE 7 days ago
FACET databaseCallCount
```

#### Q4. Time breakdown (PHP / DB / external)
```nrql
SELECT
  average(duration) as 'total',
  average(databaseDuration) as 'db',
  average(externalDuration) as 'external',
  average(duration - databaseDuration - externalDuration) as 'php'
FROM Transaction
WHERE host = '{{HOST}}'
SINCE 7 days ago
FACET name
```

From the results extract for the report:
- `APM_TOP_SLOW_URLS` - 5 slowest URLs with median and p95 time
- `APM_DB_SHARE_PCT` - what % of total time is consumed by DB (average)
- `APM_EXTERNAL_SHARE_PCT` - how much is external calls (cron, API)
- `APM_PHP_SHARE_PCT` - the rest = PHP time

### Step A3. Slow queries breakdown

In New Relic query for the slowest queries:
```nrql
SELECT count(*), average(duration)
FROM Slow_sql
WHERE appName = '{{APP_NAME}}'
SINCE 7 days ago
LIMIT 20
```

Or use the New Relic UI (Browser -> Database) - ask for a screenshot if MCP does not return this.

---

## Mode B: APM Dashboard screenshots

Ask for per-page-type **transaction screenshots** showing Time Breakdown (PHP/DB/External) + **slow queries top 10** (table, time, component). Kinsta: APM -> Transactions. Cloudways: Monitoring -> APM.

Extract: time breakdown per page type, 5 slowest transactions, 5 slowest queries with table/component.

---

## Mode C: Query Monitor

Ask user to install QM (free) and send per-page-type screenshots of: **Overview** (gen time, query count), **Queries by Component**, **Slow Queries**, **Hooks by time**. Optionally: Code Profiler Pro flame graph.

---

## Mode D: Skip

Save `APM_SECTION = "APM audit skipped (no access / user's choice). This is a data gap: we do not know what exactly is slowing down the backend at runtime. Recommended: Query Monitor as a minimum free tool for further diagnostics."`. Move to the next module.

---

## Findings (all modes) - apply principle 5a: root-cause first, NOT cache

| Condition | Sev | Finding |
|---|---|---|
| `DB_SHARE > 60%` | bad | DB consumes {{pct}}% of request time. Fix: top 3 slow queries, indexes, custom tables. Object cache ONLY AFTER |
| `EXTERNAL_SHARE > 20%` | warn | External calls {{pct}}%. Find plugin, switch to async or disable |
| Slow query >500ms on `wp_options` | bad | Bloated autoload. Clean orphaned options (15-min fix) |
| Slow query >500ms on `wp_postmeta` | bad | Fix: WC HPOS / ACF custom table / missing index on meta_key |
| N+1 pattern (many similar queries) | bad | Fix: `update_post_meta_cache()` before loop. Cache won't help |
| Top slow = page builder | warn | Conditional loading, dequeue on non-builder pages |
| Top slow hook = `init`/`plugins_loaded` | bad | Move heavy logic to later hook. Identify plugin |
| Object Cache: External NO | warn | Enable Redis ONLY AFTER fixing root causes above |
| Cold TTFB >2.5s (module 04) | bad | Every cache miss hits slow backend. Root-cause fix is key |

## Report data

Section 6: table (page type | median/p95 | DB/PHP share) + top 5 slow transactions + top 5 slow queries + findings. If skipped: data gap note.
