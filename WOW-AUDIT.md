# WOW Performance Audit - Main Playbook

> Automated WordPress performance audit playbook.
> Course: WOW (Wielka Optymalizacja WordPressa) - author: Bartlomiej Mis.
> Works with any AI assistant that has MCP access (Claude Code, Codex, Cursor, Windsurf).

---

## For the AI: rules

1. **REPORT LANGUAGE** chosen by user in phase 1.7 (`REPORT_LANG`). All output + report content in that language with proper diacritics. **Missing diacritics = defective report - do not save.**
   - **If `REPORT_LANG = PL`:** before writing ANY Polish content (first `tmp/section-*.json`, any checkpoint text, any finding, any recommendation), **read `wow-audit/report-pl-vocabulary.md` in full**. Keep the cheatsheet active while generating. Copy words from it rather than reconstructing them from memory. If a word you need is not in the cheatsheet, pause and place every ą/ć/ę/ł/ń/ó/ś/ź/ż consciously before writing.
   - Use native Polish letters everywhere: **ą, ć, ę, ł, ń, ó, ś, ź, ż** (and uppercase). Write "środowisko" not "srodowisko", "żądanie" not "zadanie", "przeglądarka" not "przegladarka", "łącze" not "lacze". No `a` for `ą`, no `s` for `ś`, no `z` for `ż`.
   - **Before saving any `tmp/section-*.json` or the final HTML:** run the "Quick self-check" list at the bottom of `report-pl-vocabulary.md` against the content you just wrote. If any item on that list fails, fix and re-check. This is a hard gate, not a stylistic preference.
   - Applies to: all placeholder values, findings text, action plan items, section headings, table cells, evidence lines, recommendations. The template file (`report-template.html`) already uses correct diacritics - do not break them.
   - Applies to all languages with non-ASCII letters (German umlauts, French accents, etc.) if a different `REPORT_LANG` is chosen - if no vocabulary file exists for that language yet, write diacritics carefully from native knowledge.
2. Execute phases **sequentially**. If you skip any step - state what and why.
3. After each phase: **CHECKPOINT** - 3-5 bullets of what you collected and key findings.
4. If a tool is unavailable, use the fallback from the module. Don't block.
5. **Read-only audit** - never modify project files or server.
6. `curl`, `dig`, `whois`, `openssl` via bash locally.
7. Chrome DevTools MCP tools: `navigate_page`, `evaluate_script`, `performance_start_trace`, `list_network_requests`, `take_screenshot`, `lighthouse_audit`, `emulate`. Other clients may use different prefixes - adapt.
8. Never use em dashes or en dashes. Always use regular hyphens (-).
9. Write all AUTO results as text in your responses - don't rely on memory alone.
10. Save report placeholder JSON (`tmp/section-*.json`) after each module checkpoint. Phase 10 only generates cross-cutting sections and assembles the final HTML.
11. **Module 08 execution model (HARD GATE):** main agent does NOT run diagnostic tool calls inline. For each (page_type × profile) combo it spawns one sub-agent with a 28-item tool-call checklist (navigate, trace, 5 insights, network, console, lighthouse, 14 snippets, pre-FCP classify, screenshot). Sub-agents run sequentially (shared Chrome tab). Main agent verifies `checklist_items_completed == 28` and exactly 14 snippet keys, AND persists this evidence into `tmp/section-frontend.json` under key `sub_agent_runs` (array, one entry per combo). **If `section-frontend.json` is saved without a populated `sub_agent_runs` array, Phase 10 must refuse to assemble the HTML report and instead report to the user "module 08 incomplete - sub-agents not run".** Parsing WPT JSON or static HTML is supplementary - never a substitute for the sub-agent battery. Inline-approximating snippets is forbidden. See module 08 "Execution model" for the full spec.
12. If a module is long - execute step by step, checkpoint after each step.
13. Before any module's checkpoint that depends on an MCP tool (Chrome DevTools, CoreDash, New Relic), explicitly verify the tool is available in the session. If it is unavailable, **state this to the user and ask whether to skip or install** - do not silently substitute another data source.

---

## Phase 0 - Preflight

### 0.1 Check directory

Verify `wp-config.php` and `wp-content/` exist. If missing - ask whether the audit is online-only (skip modules 04, 05).

### 0.2 Detect AI client

Infer from available tools:
- `chrome-devtools__*` or `mcp__chrome-devtools__*` -> **Claude Code**
- `.cursor/mcp.json` -> **Cursor**
- `.codex/` or `~/.codex/config.toml` -> **Codex**
- `.windsurf/` or `mcp_config.json` -> **Windsurf**
- Otherwise ask directly

Save as `AI_CLIENT`. Show ONLY the install block for that client in 0.3.

### 0.3 Required tools

| # | Component | Purpose | Required? |
|---|---|---|---|
| 1 | **Chrome DevTools MCP** | lab tests, snippets, screenshots, lighthouse | YES |
| 2 | **CoreDash MCP** | RUM field data | optional |
| 3 | **CWV Superpowers** | CWV expert knowledge | YES |
| 4 | **wordpress-performance-best-practices** | WP code review rules | YES |
| 5 | **New Relic MCP** | APM data | optional |

Config MUST be **per-project** (not global). Before showing commands, confirm the user is in the project directory.

Show **ONE block** for `AI_CLIENT`:

#### Claude Code
```bash
claude mcp add --scope local chrome-devtools -- npx chrome-devtools-mcp@latest
claude mcp add --scope local --transport http coredash https://app.coredash.app/api/mcp \
  --header "Authorization: Bearer cdk_YOUR_API_KEY"
# CWV Superpowers:
/plugin marketplace add corewebvitals/cwv-superpowers
/plugin install cwv-superpowers@cwv-superpower
# wordpress-performance-best-practices (skill, installed via skills.sh):
npx skills add https://github.com/bartekmis/wordpress-performance-best-practices --skill wordpress-performance-best-practices
# New Relic (optional):
claude mcp add --scope local newrelic -- npx @newrelic/mcp-server@latest \
  -e NEW_RELIC_API_KEY=NRAK-...
```

#### Cursor
Create `.cursor/mcp.json` in project directory:
```json
{
  "mcpServers": {
    "chrome-devtools": { "command": "npx", "args": ["chrome-devtools-mcp@latest"] },
    "coredash": { "type": "http", "url": "https://app.coredash.app/api/mcp",
      "headers": { "Authorization": "Bearer cdk_YOUR_API_KEY" } },
    "newrelic": { "command": "npx", "args": ["@newrelic/mcp-server@latest"],
      "env": { "NEW_RELIC_API_KEY": "YOUR_KEY" } }
  }
}
```
Skills: `git clone` repos to `.cursor/rules/`. Add `.cursor/mcp.json` to `.gitignore`.

#### Windsurf
Same as Cursor but `.windsurf/mcp.json`. Add to `.gitignore`.

#### Codex
```bash
mkdir -p .codex && export CODEX_CONFIG_DIR="$(pwd)/.codex"
```
Create `.codex/config.toml` with same servers in TOML format. Clone skill repos to `.codex/rules/`. Add `.codex/` to `.gitignore`.

#### Other
Tell user: configure `chrome-devtools-mcp` (npx) + CoreDash (HTTP transport) per-project.

#### After installation
> "Restart the AI client in the same project directory. Type `done` - I'll verify and proceed."

---

## Phase 1 - Collect inputs

Ask one at a time. After each answer, confirm.

### 1.1 Site URL
> "Provide the URL of the site to audit. It can be production or staging - your choice. The audit will be read-only and won't modify the site."

Save `SITE_URL`, extract `DOMAIN`. Infer `ENVIRONMENT` from URL hints (`staging.`, `dev.`, `.test`, `.local` subdomains or TLD patterns -> "staging"; otherwise "production"). If unclear, ask once. Warn about Apache Bench impact if `ENVIRONMENT = production`.

### 1.2 Hosting
A) Shared B) Managed WP C) VPS/dedicated/cloud D) Don't know. Save `HOSTING_TYPE`.

If user picks **D) Don't know**, auto-detect before moving on. Run these probes against `SITE_URL` and match signals below:

```bash
curl -sI -L {{SITE_URL}}                              # response headers
dig +short {{DOMAIN}}                                  # A records
dig +short {{DOMAIN}} NS                               # nameservers
dig +short {{DOMAIN}} CNAME                            # CNAMEs
whois $(dig +short {{DOMAIN}} | tail -1) 2>/dev/null | grep -iE 'orgname|netname|descr' | head -5
```

**Signal matrix** (first match wins; combine signals when multiple match):

| Signal | Inferred provider | HOSTING_TYPE |
|---|---|---|
| Header `X-Kinsta-Cache` or `X-Edge-Location` | Kinsta | Managed WP |
| Header `Server: Flywheel` or `X-Cache: Flywheel` | Flywheel | Managed WP |
| Header `X-Pagely-Request-ID` or NS `*.pagely.com` | Pagely | Managed WP |
| Header `X-Pantheon-*` or `X-Styx-Req-Id` | Pantheon | Managed WP |
| NS/CNAME `*.wpengine.com` or `X-WPE-*` header | WP Engine | Managed WP |
| Header `X-Hacker` (WordPress.com) | WordPress.com / VIP | Managed WP |
| NS `*.bluehost.com`, `*.hostgator.com`, `*.godaddy.com`, `*.namecheaphosting.com`, `*.siteground.net` (and no CF in front) | Shared reseller | Shared |
| Header `X-Proxy-Cache` + IP whois shows SiteGround | SiteGround | Managed WP (entry) |
| Header `Server: cloudflare` + `CF-Ray` | Cloudflare CDN (need to peel back - check origin via `dig` A record + whois) | (determine from origin) |
| whois orgname matches `Hetzner`, `DigitalOcean`, `Vultr`, `Linode`, `OVH`, `Amazon`, `Google Cloud`, `Microsoft Azure` | VPS/cloud (possibly Cloudways on top - check `Server: Apache` + `X-Cloudways-*`) | VPS/dedicated/cloud |
| Header `X-Cloudways-*` or NS CNAME to `cloudwaysapps.com` | Cloudways | Managed WP (on VPS) |
| whois orgname `OVH`, `GoDaddy`, `Hostinger` + no managed-WP headers | Shared/budget host | Shared |

Report detected provider and inferred `HOSTING_TYPE` to the user:
> "Auto-detected: **{{PROVIDER_NAME}}** (signal: {{SIGNAL}}). Setting hosting type to **{{HOSTING_TYPE}}**. Correct? (yes/no)"

If detection is ambiguous (only IP-level match, no distinctive header), set `HOSTING_TYPE = Unknown` and state what signals were found so the user can confirm manually. Save `HOSTING_PROVIDER` alongside `HOSTING_TYPE` so downstream modules (04, 07) can use provider-specific knowledge.

### 1.3 Page types
> "Provide page types to audit: `type name | URL`. Examples: homepage, blog listing, blog post, category, product page, cart, checkout. Suggest 4-7 types."

Save `PAGE_TYPES = [{name, url}, ...]`.

### 1.3b Desktop profile (opt-in)
> "Also run the desktop profile in module 08? Mobile runs by default (CWV are mobile-weighted by Google). Desktop doubles audit time on the frontend module and rarely changes the action plan. A) Mobile only (default, recommended) B) Mobile + desktop."

Save `INCLUDE_DESKTOP = true` if user picks B, else `false`.

### 1.4 RUM (CoreDash)
A) CoreDash MCP B) Screenshots C) Preliminary (<7 days) D) Skip. Save `RUM_MODE`.

### 1.5 WebPageTest (optional)
If yes: files in `wow-audit/wpt/mobile/` and `wpt/desktop/`, one JSON per page type per profile (3 runs, median). Save `WPT_AVAILABLE`.

### 1.6 APM (optional)
A) New Relic / Tideways with PHP agent + MCP configured B) Access to any APM dashboard (Kinsta APM, Cloudways, WP Engine, or New Relic UI) - will send screenshots C) No APM - will use Query Monitor plugin (free) + optionally Code Profiler Pro (paid, flame graphs) D) Skip. Save `APM_MODE`.

### 1.7 Report language
Default PL. Save `REPORT_LANG`.

### 1.8 Summarize and confirm
Display table of all inputs. Wait for `OK`.

---

## Phases 2-9 - Execute modules

### Preliminary: read the WOW tuning fork (MANDATORY, once)

Read `modules/00-wow-principles.md` in full before module 01. Keep principles in memory. Reference them in recommendations.

### Progressive report data (MANDATORY)

After each module's checkpoint, generate the HTML content for that module's report placeholders and save as a JSON file in `wow-audit/tmp/`. Each file is a flat `{ "PLACEHOLDER_NAME": "html content", ... }` object. This spreads report generation across the entire audit instead of doing it all in Phase 10.

| Module | JSON file | Placeholders |
|---|---|---|
| 01-baseline | `tmp/section-cover.json` | LANG, DOMAIN, DATE, SITE_URL, ENVIRONMENT, HOSTING_TYPE, PAGE_TYPES_COUNT, PAGE_TYPES_LIST, RUM_STATUS, WPT_STATUS, APM_STATUS |
| 02-dns | `tmp/section-dns.json` | DNS_PROVIDER, DNS_PROVIDER_STATUS, DNS_ANYCAST, DNS_ANYCAST_STATUS, DNS_LOOKUP_MS, DNS_LOOKUP_STATUS, DNS_NS_COUNT, DNS_TTL, DNS_PRECONNECT_COUNT, DNS_PRECONNECT_STATUS, DNS_FINDINGS |
| 03-tls | `tmp/section-tls.json` | TLS_VERSION, TLS_VERSION_STATUS, HTTP_PROTOCOL, HTTP_PROTOCOL_STATUS, HSTS_PRESENT, HSTS_STATUS, HTTPS_REDIRECT, HTTPS_REDIRECT_STATUS, TLS_HANDSHAKE_FIRST, TLS_HANDSHAKE_REPEAT, TLS_RESUMPTION_STATUS, TLS_FINDINGS |
| 04-server-php | `tmp/section-server.json` | WEB_SERVER, PHP_VERSION, PHP_VERSION_STATUS, OPCACHE_STATUS, OPCACHE_BADGE, OBJECT_CACHE_TYPE, OBJECT_CACHE_STATUS, TTFB_CACHED, TTFB_CACHED_STATUS, TTFB_UNCACHED, TTFB_UNCACHED_STATUS, AB_RESULTS, SERVER_FINDINGS |
| 05-wp-config | `tmp/section-wp.json` | WP_MEMORY_LIMIT, WP_MEMORY_LIMIT_STATUS, DISABLE_WP_CRON, DISABLE_WP_CRON_STATUS, WP_POST_REVISIONS, WP_POST_REVISIONS_STATUS, WP_DEBUG, WP_DEBUG_STATUS, SAVE_QUERIES, SAVE_QUERIES_STATUS, AUTOLOAD_SIZE, AUTOLOAD_STATUS, REVISIONS_COUNT, REVISIONS_STATUS, QM_OBJECT_CACHE_RATIO, QM_OBJECT_CACHE_STATUS, WP_FINDINGS |
| 04b-code-review | `tmp/section-code-review.json` | CR_FILES_COUNT, CR_DIRS_COUNT, CR_BAD_COUNT, CR_WARN_COUNT, CR_FINDINGS_ROWS, CR_RULE_EXAMPLES, CR_SKIPPED_PLUGINS |
| 06-apm | `tmp/section-apm.json` | APM_SECTION |
| 07-cache | `tmp/section-cache.json` | PAGE_CACHE_LAYER, PAGE_CACHE_HEADER, CACHE_PROGRESSION, CACHE_BYPASS_URL, CACHE_BYPASS_COOKIE, STATIC_CACHE_CONTROL, STATIC_CACHE_STATUS, COMPRESSION_TYPE, COMPRESSION_STATUS, CDN_HIT_RATIO, CDN_HIT_RATIO_STATUS, CACHE_FINDINGS |
| 08-frontend | `tmp/section-frontend.json` | PAGETYPE_COMPARISON_ROWS, PAGETYPE_SECTIONS |
| 09-rum | `tmp/section-rum.json` | RUM_SECTION, INP_REPLAY_SECTION, LCP_REPLAY_SECTION, CLS_REPLAY_SECTION |

**Quality checkpoint per JSON (blocking - do not save until all four pass):**
1. **Diacritics:** if `REPORT_LANG = PL`, run the 10-point "Quick self-check" list at the bottom of `report-pl-vocabulary.md` against the content you are about to save. Every "Every occurrence of X has Y" item must pass. If any fails, fix the word(s) and re-run the self-check before saving.
2. **Numeric consistency:** values match collected audit data (no rounding drift, no hallucinated numbers).
3. **No hallucinations:** every fact traces back to a tool result or module output.
4. **Valid HTML fragments:** balanced tags, escaped `<`/`>`/`&` inside text, correct class names matching the template.

### Module execution order

Read each module file, execute its steps, checkpoint after each.

| Phase | Module file | Title | Scope |
|---|---|---|---|
| 2 | `modules/01-baseline.md` | Project baseline | once |
| 3 | `modules/02-dns.md` | DNS and lookup | once |
| 4 | `modules/03-tls.md` | TLS, HTTP/2-3 | once |
| 5 | `modules/04-server-php.md` | Server and PHP | once |
| 6 | `modules/05-wp-config.md` | wp-config and database | once |
| 6.5 | `modules/04b-code-review.md` | Theme/plugin code review | once (after 05) |
| 7 | `modules/06-apm.md` | APM (if available) | once |
| 8 | `modules/07-cache.md` | Cache stack | once |
| 9 | `modules/08-frontend.md` | Frontend per page type | **per page type** |
| 10 | `modules/09-rum.md` | RUM (if available) | per page type |

Modules 02-07 run once (domain-wide). Module 08 runs per page type. Module 09 per page type if RUM available.

---

## Phase 10 - Synthesis and HTML report

### 10.1 Cross-layer correlations

#### A: TTFB curl vs Lab vs RUM
Compare TTFB from module 04 (cached/uncached), module 08 (lab), module 09 (RUM p75). Detect patterns:
- `curl_cached << lab << RUM`: cache works, users have slow networks - suggest CDN/edge
- `curl_uncached >>> curl_cached`: backend slow on miss - optimize PHP/DB or longer TTL
- `lab > curl_cached + 200ms`: lab JS overhead

#### B: Lab vs Field
Compare module 08 lab with module 09 RUM p75 per page type (LCP, INP/TBT, CLS). If divergence >30%, flag.

#### C: Page type vs page type
Table per page type: TTFB, LCP, INP, CLS, requests, KB. Identify worst type and differentiator.

### 10.2 Action plan - 3 horizons

Group findings into: **Quick wins** (today, <1h), **Phase 2** (this week, <1 day), **Phase 3** (refactor, >1 day).

Each item: **What** (one sentence) + **Why** (which metric) + **How** (code/command/wp-admin steps) + **Expected impact**.

**Sorting rule (from principle 5a):** Root-cause fixes BEFORE cache. Within each horizon: root-cause first, then cache as multiplier. If recommending Redis/object cache, always precede with "have root-cause issues been fixed?" checklist. Mark temporary cache band-aids with `<span class="badge badge-bad">band-aid</span>`.

**Exceptions** (always quick wins, before root-cause): OPcache enable, browser cache headers on statics - these are zero-effort hygiene.

### 10.3 Generate HTML report

**Most placeholder content is already generated** in `wow-audit/tmp/section-*.json` files (saved progressively during phases 2-9). Phase 10 only generates the cross-cutting sections that need data from multiple modules.

1. Read all `wow-audit/tmp/section-*.json` files. Verify all 10 files exist. If any are missing, generate that section's placeholders now from collected data. **For `section-frontend.json` specifically, verify it contains a populated `sub_agent_runs` array with one entry per combo, each with `checklist_items_completed: 28` and exactly 14 snippet keys. If missing or incomplete, halt and tell the user "Cannot assemble report - module 08 sub-agents did not run. Re-run module 08 first." Do NOT proceed to assembly.**

2. Generate the remaining cross-cutting placeholders (these need data from multiple modules):
   - **Executive summary:** `TOP_FINDING_1/2/3_TITLE`, `_DESC`, `_EVIDENCE`, `TTFB_CLASS`, `TTFB_VALUE`, `LCP_CLASS`, `LCP_VALUE`, `INP_CLASS`, `INP_VALUE`, `CLS_CLASS`, `CLS_VALUE`
   - **Methodology:** `METHODOLOGY_TEXT`, `PROBE_RUNS`, `RUM_METHOD`, `WPT_METHOD`, `APM_METHOD`
   - **Correlations:** `CORR_TTFB_CURL_CACHED`, `_UNCACHED`, `_LAB_MOBILE`, `_LAB_DESKTOP`, `_RUM` (each with `_NOTE`), `CORR_TTFB_CONCLUSION`, `CORR_LAB_FIELD_TEXT`, `CORR_PAGETYPE_TEXT`
   - **Action plan:** `QUICK_WINS`, `PHASE_2`, `PHASE_3`
   - **Appendix:** `RAW_NETWORK_OUTPUT`, `SCREENSHOT_LIST`, `COMMAND_LOG`

   Save as `wow-audit/tmp/section-synthesis.json`.

3. Read `wow-audit/report-template.html` and replace all `{{PLACEHOLDER}}` tokens using values from all JSON files.

4. Save as `wow-audit-YYYY-MM-DD.html` in the main project directory.

5. Tell user to open in Chrome, Cmd+P, Save as PDF.

6. List 3 quick wins from the action plan.

### 10.4 After the report
> "Want me to explain any finding deeper or generate a specific diff?"
