# WOW Performance Audit

Automated WordPress performance audit. Works with any AI assistant (Claude Code, Codex, Cursor, Windsurf, Gemini CLI). Based on the full diagnostic process from the **WOW - Wielka Optymalizacja WordPressa** course.

## What is this

A set of markdown files that your AI assistant reads and executes step by step. No installer, no binary. Just a playbook that drives an audit:

1. Checks if you have the required tools (Chrome DevTools MCP, CoreDash MCP, CWV Superpowers) and if not - tells you what to install
2. Asks for the site URL, page types, RUM data, optional WebPageTest files, optional APM data
3. Executes 9 diagnostic modules: DNS, TLS, server/PHP, wp-config, APM, cache, frontend, RUM
4. Correlates results (curl vs lab vs RUM, lab vs field, page type vs page type)
5. Generates an HTML report with an action plan (code + WP admin + infra)

## Requirements

- **AI assistant** with MCP access: Claude Code, Codex, Cursor or Windsurf
- **Local WordPress project directory** (assistant reads theme, plugins, `wp-config.php`)
- `curl`, `dig`, `whois`, `openssl` (standard on macOS and Linux)
- Optionally: CoreDash account with RUM collected for at least 7 days
- Optionally: WebPageTest reports (3 mobile runs + 3 desktop runs)
- Optionally: APM access (New Relic / Tideways) or dashboard screenshots

## How to run

1. Clone or copy the `wow-audit/` folder into your WordPress project directory (where `wp-config.php` is)
2. **Open terminal in the project directory** (`cd /path/to/my/project`)
3. **Open your AI assistant in the same directory** (e.g. `claude` in terminal, or open the folder in Cursor/Windsurf)
4. Type:
   ```
   Follow wow-audit/WOW-AUDIT.md
   ```
5. The assistant will first ask which client you're using, then give you exact MCP install commands **for your client**, to run in the project directory (per-project, not global - each project has its own keys).
6. The assistant will guide you through all steps. When it needs a screenshot or answer - it will ask.
7. Result: `wow-audit-YYYY-MM-DD.html` in the project directory. Open in Chrome, `Cmd+P`, "Save as PDF".

> **Why "in the project directory"?** Each audited site has its own CoreDash key, own URL, own source code. MCP config must be per-project (not global), otherwise auditing another project mixes keys and risks running tests on the wrong site. The assistant will tell you how to configure this per-project for your AI client.

> **Key security:** add to `.gitignore`: `.cursor/mcp.json`, `.windsurf/mcp.json`, `.codex/`, `.mcp.json` - so you don't commit API keys to the repo.

## How long does it take

- Full audit: 30-60 minutes, including ~10 minutes of your active work (screenshots, answers)
- Quick audit (AUTO only, no APM/RUM): ~15 minutes, almost no interaction needed

## What it does automatically vs what it asks you

| Layer | Auto | Asks for screen/data |
|---|---|---|
| DNS | whois, dig, anycast detection, preconnect audit | dnsperf.com benchmark |
| TLS | curl/openssl, HTTP/2-3, HSTS, session resumption | SSL Labs grade |
| Server | curl TTFB cached vs uncached, web server detection | Site Health, phpinfo, ab load test |
| WordPress | Read wp-config.php | Query Monitor, AAAA Option Optimizer |
| APM | New Relic MCP (if configured) | transaction screenshots OR skip entirely |
| Cache | curl headers, MISS/HIT, Cache-Control, compression | Cloudflare dashboard hit ratio |
| Frontend | Chrome DevTools MCP per page type, lighthouse, snippets | CrUX if no traffic |
| RUM | CoreDash MCP queries | dashboard screenshots if no MCP |

## Security

- The audit is **read-only**, it changes nothing in your WordPress or on the server
- Lab tests (Chrome DevTools) generate a few requests to production - negligible load
- Apache Bench (`ab`) is **optional** and requires your explicit consent before running
- API keys (CoreDash, New Relic) are kept locally in MCP config, they don't leave your computer

## Files

```
wow-audit/
├── WOW-AUDIT.md              # main playbook (~200 lines, lean orchestrator)
├── README.md                  # this file
├── report-template.html       # print-ready HTML report template (read only at final assembly)
├── report-placeholders.md     # placeholder reference for AI (read instead of full template)
├── modules/
│   ├── 00-wow-principles.md   # tuning fork (10 principles + quotes)
│   ├── 01-baseline.md         # baseline: URL, page types
│   ├── 02-dns.md              # DNS: dig, whois, anycast, preconnect
│   ├── 03-tls.md              # TLS: certificate, HTTP/2-3, HSTS
│   ├── 04-server-php.md       # server: PHP, OPcache, TTFB, load test
│   ├── 04b-code-review.md     # code review: 10 WP anti-patterns
│   ├── 05-wp-config.md        # wp-config, DB, autoload, revisions
│   ├── 06-apm.md              # APM: New Relic / Tideways / screenshots
│   ├── 07-cache.md            # cache: page, object, CDN, compression
│   ├── 08-frontend.md         # frontend per page type
│   └── 09-rum.md              # RUM + root cause replay
└── scripts/
    ├── wow-audit-check.php    # consolidated diagnostic script (phpinfo + bench + server + db)
    ├── pre-fcp-classify.js    # automatic pre-FCP resource classification
    └── snippets/              # 13 JS snippets for evaluate_script (source: nucliweb/webperf-snippets)
        ├── LCP.js, LCP-Sub-Parts.js, CLS.js, LongTask.js
        ├── Resource-Hints.js, Fonts-Preloaded-*.js, First-And-Third-Party-*.js
        ├── Image-Element-Audit.js, Find-Images-*.js (x2), Find-render-blocking-*.js
        ├── Validate-Preload-Async-Defer-Scripts.js
        └── Head-Order-Audit.js  # custom, inspired by csswizardry/ct
```

## Course

This audit is a practical application of the process from the **WOW** course by Bartlomiej Mis (Web Dev Insider). More: https://www.wielkaoptymalizacjawordpressa.pl
