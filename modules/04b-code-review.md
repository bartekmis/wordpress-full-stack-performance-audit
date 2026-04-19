# Module 04b - Code Review (custom WordPress code)

> **Goal:** Scan theme code, mu-plugins, and custom plugins for the 10 most dangerous anti-patterns from the `wordpress-performance-best-practices` skill (Bartlomiej Mis, https://github.com/bartekmis/wordpress-performance-best-practices). Identify specific files and lines with issues, map them to rule names, and add them to the action plan as root-cause fixes (following the 5/5a tuning fork principle).

## Prerequisite

For effective use of this module, **the `wordpress-performance-best-practices` skill is required** (should be installed in phase 0.3). If it is not available - the module still works (pattern scanning), but recommendations will be brief (without canonical fix examples). In that case, ask the user to install the skill and restart the AI client.

## Scope

### What we scan

- `wp-content/themes/{ACTIVE_THEME}/` - the entire theme, with special attention to:
  - `functions.php`
  - `inc/`, `includes/`, `lib/`, `src/` (if present)
  - `template-parts/`, `parts/`, `templates/`, `partials/`
  - all `.php` files in the theme root directory
- `wp-content/themes/{ACTIVE_THEME}-child/` - child theme, if it exists
- `wp-content/mu-plugins/` - all .php files (mu-plugins load ALWAYS)
- `wp-content/plugins/{custom_plugins}/` - **only** custom plugins (written by the client or their developer). You identify them by:
  - not on wp.org (usually the name contains the company/client name)
  - **DO NOT scan** large well-known plugins (recognized by directory names):
    - `woocommerce`, `woocommerce-*`, `elementor`, `elementor-pro`, `wpbakery`, `divi-builder`, `oxygen`
    - `wordpress-seo`, `seo-by-rank-math`, `all-in-one-seo-pack`
    - `wp-rocket`, `w3-total-cache`, `wp-super-cache`, `litespeed-cache`, `wp-fastest-cache`, `breeze`
    - `updraftplus`, `backupbuddy`, `duplicator`, `all-in-one-wp-migration`
    - `contact-form-7`, `wpforms-lite`, `wpforms`, `gravityforms`
    - `akismet`, `jetpack`, `wordfence`, `sucuri-scanner`
    - `advanced-custom-fields`, `advanced-custom-fields-pro`
    - `polylang`, `wpml-*`
  - These large plugins are diagnosed through **APM/Query Monitor** (module 06), not code review

### What we do NOT do

- We do not modify code (audit is read-only)
- We do not generate ready-made diffs (too easy to hallucinate, left to the student's decision)
- We do not analyze minified JS (wp-content/themes/.../assets/js/*.min.js)
- We do not analyze CSS (that is module 08 - frontend)

## AUTO Steps

### 1. Determine the list of files to scan

```bash
# Active theme (from module 01 we have the ACTIVE_THEME variable)
THEME_DIR="wp-content/themes/{{ACTIVE_THEME}}"
ls -la $THEME_DIR/ 2>/dev/null

# Child theme if it exists
CHILD_DIR="wp-content/themes/{{ACTIVE_THEME}}-child"
[ -d "$CHILD_DIR" ] && ls -la $CHILD_DIR/

# Mu-plugins
ls -la wp-content/mu-plugins/ 2>/dev/null

# Plugins - list all, filter out the large well-known ones
ls wp-content/plugins/ 2>/dev/null
```

From the plugin list, remove those on the "large wp.org plugins" list (section "What we do NOT do"). What remains is **custom code to scan**.

Save `CODE_REVIEW_TARGETS` as a list of directories.

### 2. Scanning - 10 grep patterns

For each directory in `CODE_REVIEW_TARGETS`, use `Grep` (playbook tool) with the patterns below. Work **per pattern** and record results in an internal table.

#### W1. `db-limit-query-results` - posts_per_page = -1 (critical)

```
Pattern: posts_per_page['"]?\s*=>\s*-1
Glob: **/*.php
```

What it means: query without a limit = potentially thousands of posts in memory. Classic OOM with large databases. Severity: **bad**.

#### W2. `db-avoid-post-not-in` - post__not_in (warn)

```
Pattern: post__not_in
Glob: **/*.php
```

What it means: `post__not_in` forces MySQL to scan the entire table. Better to filter in PHP after the result. Severity: **warn**.

#### W3. `theme-loop-optimization` - N+1 query in a loop (critical)

```
Pattern: get_post_meta\s*\(\s*get_the_ID\s*\(
Glob: **/*.php
```

What it means: `get_post_meta(get_the_ID(), ...)` inside a post loop = N+1 (each iteration is a new SQL query). Fix: `update_post_meta_cache($posts_array)` before the loop OR `WP_Query` with `update_post_meta_cache => true`. Severity: **bad**.

Additionally check the pattern for terms:
```
Pattern: (the_category|the_tags|wp_get_post_terms)\s*\(
Glob: **/*.php
```
If the file contains a `while.*have_posts` loop and the above calls - flag as potential N+1.

#### W4. `cache-remote-requests` - wp_remote_get/post without transient cache (bad)

```
Pattern: wp_remote_(get|post|head|request)\s*\(
Glob: **/*.php
```

For each finding: check whether within **10 lines** above/below there is `set_transient` or `wp_cache_set`. If not - flag: external HTTP call without cache. Severity: **bad** (every page request makes an external call = potentially seconds).

```bash
# Workflow - Grep with context, then manual verification
Pattern: wp_remote_(get|post|head|request)
Glob: **/*.php
output_mode: content
-C: 10
```

Review the results - if within the 20-line context there is no `set_transient`, `get_transient`, `wp_cache_set`, `wp_cache_get` - flag it.

#### W5. `db-prepared-statements` - SQL injection / unprepared queries (critical security + bad performance)

```
Pattern: \$wpdb->(query|get_results|get_var|get_row)\s*\(\s*["'][^"']*\$
Glob: **/*.php
```

What it means: a query like `$wpdb->query("SELECT ... $variable")` without `prepare()`. This is a **double problem**: SQL injection security risk + no prepared statement cache in MySQL. Fix: `$wpdb->prepare("... %s", $variable)`. Severity: **bad** (security + perf).

#### W6. `asset-conditional-loading` - enqueue assets without is_page/is_single condition (warn)

```
Pattern: wp_enqueue_(script|style)\s*\(
Glob: **/*.php
output_mode: content
-C: 5
```

For each finding check the context - is there any condition nearby (10 lines) (`is_page`, `is_single`, `is_front_page`, `is_archive`, `is_singular`, `wp_script_is`, `function_exists`)? If not - the asset loads **on every page**, flag it.

Severity: **warn** (informational - not every asset has to be conditional, but a large number of assets on every page is a red flag).

#### W7. `theme-avoid-queries-in-templates` - WP_Query directly in a template (warn)

```
Pattern: new WP_Query\s*\(
Glob: wp-content/themes/**/template-parts/**/*.php
```
Plus:
```
Pattern: new WP_Query\s*\(
Glob: wp-content/themes/**/templates/**/*.php
```
Plus:
```
Pattern: new WP_Query\s*\(
Glob: wp-content/themes/**/parts/**/*.php
```

What it means: a query in a template file instead of in `functions.php` or a separate data file. Makes the template hard to cache and debug. Severity: **warn**.

#### W8. `db-meta-query-indexing` - meta_query with NOT EXISTS / != (warn)

```
Pattern: ['"]compare['"]?\s*=>\s*['"](NOT EXISTS|NOT IN|!=|NOT LIKE)
Glob: **/*.php
```

What it means: meta query with negation conditions does not use MySQL indexes = full table scan. Severity: **warn**. Fix: consider a taxonomy instead of meta for frequently filtered fields.

#### W9. `theme-hooks-placement` - heavy logic on the init / wp_loaded / plugins_loaded hook (warn)

```
Pattern: add_action\s*\(\s*['"](init|wp_loaded|plugins_loaded|muplugins_loaded)['"]
Glob: **/*.php
output_mode: content
-C: 3
```

For each finding: the callback function name. Then check the body of that function - if it contains `WP_Query`, `wp_remote_get`, `get_posts`, `$wpdb` - flag it (heavy logic on a hook that fires on **every** request, including REST API and cron).

Severity: **warn**.

#### W10. `cache-invalidation` - update_option / delete_transient in a loop or in an action callback without control (warn)

```
Pattern: update_option\s*\(
Glob: **/*.php
output_mode: content
-C: 5
```

Check the context: is `update_option` inside a loop or in a callback on a hook that fires on every request (e.g. `init`)? If so - that is a write on every request = heavy autoload + no cache invalidation logic. Severity: **warn**.

Additionally:
```
Pattern: delete_transient\s*\(
Glob: **/*.php
```
If you see `delete_transient` in a callback on a hook like `save_post`, `wp_insert_post`, `updated_post_meta` - that is OK (precise invalidation). If on `init` or without context - flag it.

#### W11. `wp-head-cleanup` - partial/stale WP bloat removal

```
Pattern: remove_action\s*\(\s*['"]wp_head['"]
Glob: **/*.php
```

What it means: theme/mu-plugin already has partial cleanup of wp_head (e.g., only `wp_generator` removed but not `rsd_link`, `wlwmanifest_link`, feed links, emoji, etc.). Check against the canonical list in `wow-audit/scripts/recommendations/wp-cleanup.php`.

- If **no cleanup exists** and `WP-Bloat-Detection.js` from module 08 found items: recommend adding the full canonical file as mu-plugin
- If **partial cleanup exists**: list which items from wp-cleanup.php are missing, recommend replacing with the canonical version (or extending the existing cleanup)
- Severity: **warn** (not a bug, just suboptimal hygiene)

### 3. Consolidate findings

After going through all 10 patterns you have a list. Organize them into a table:

| File | Line | Pattern | Severity | Rule (skill) |
|---|---|---|---|---|
| `wp-content/themes/foo/inc/widgets.php` | 234 | get_post_meta in loop | bad | `theme-loop-optimization` |
| `wp-content/themes/foo/functions.php` | 87 | wp_enqueue_script without condition | warn | `asset-conditional-loading` |
| `wp-content/plugins/custom-x/api.php` | 45 | wp_remote_get without transient | bad | `cache-remote-requests` |
| ... | | | | |

**Limit:** If there are **more than 30 findings**, limit to the top 20 (sorted by severity bad > warn). Mention the rest numerically in the report ("plus 14 additional medium-priority warnings - full list in appendix").

### 4. Extract canonical fix examples

For **each unique rule** that appeared, if the `wordpress-performance-best-practices` skill is available, read the corresponding rule file:

```
Read: ~/.claude/skills/wordpress-performance-best-practices/rules/{rule-name}.md
```

From each rule extract:
- 1-2 sentences of **justification** (from the "## ..." section)
- **Correct code example** (section "Correct (...)") - copy 5-15 lines of the canonical solution

If the skill **is not available**, write a placeholder in the report: "Full description and fix patterns: https://github.com/bartekmis/wordpress-performance-best-practices/blob/main/rules/{rule-name}.md".

## Findings

| bad count | Sev | Finding |
|---|---|---|
| 0 | good | No critical anti-patterns found |
| 1-3 | warn | {{n}} critical patterns. Each in action plan with file:line + rule |
| 4-10 | bad | {{n}} critical patterns. Code needs refactoring - Phase 2 |
| >10 | bad | {{n}} patterns. Inherited/unreviewed code - Phase 2-3 |

Key pattern findings (generate verbose text in report only):
- W3 N+1 (`get_post_meta` in loop) -> root-cause fix with `update_post_meta_cache()`
- W4 uncached HTTP calls -> wrap in `set_transient`
- W1 `posts_per_page = -1` -> set realistic limit
- W5 unprepared SQL -> `$wpdb->prepare()` (security + perf)

## Report data

Section 6a: table (file | line | rule | severity | description) + canonical fix `<pre>` per unique rule. Summary: "{{n}} findings, {{bad}} critical, {{warn}} warnings".

## Action plan impact

Code review findings are **root-cause fix candidates** - they go BEFORE cache recommendations (principle 5a). Format: `[code-review] {{file}}:{{line}} - {{rule_name}} - {{fix}}`.
