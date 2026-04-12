# Module 01 - Project Baseline

> **Purpose:** Collect project metadata and verify access to the source code.

## What we collect

- WordPress version (from `wp-includes/version.php`)
- Active theme (from the `template` option in the database OR from the `wp-content/themes/` path)
- Number of active plugins
- Whether the project uses multisite
- Whether there are any mu-plugins
- PHP version (if detectable from headers)
- List of page types (already collected in phase 1.5 of the main playbook)

## AUTO steps

### 1. WordPress version
Read `wp-includes/version.php` and extract the `$wp_version` constant.

```
Read: ./wp-includes/version.php
```

Save as `WP_VERSION`.

### 2. Active theme
Check if the `wp-content/themes/` directory exists. List its contents. If there is only one theme - that's it. If there are more, try to locate the active one by:
- Checking the marker in the page HTML (`rel="stylesheet"` attribute with a link to `wp-content/themes/<name>/style.css`) - can be fetched via `curl -s {{SITE_URL}} | grep -o 'wp-content/themes/[^/]*' | head -1`
- Or ask the user

Save as `ACTIVE_THEME`.

### 3. Plugins
```
ls wp-content/plugins/
```
Count directories (excluding `index.php`). Save `PLUGINS_COUNT`.

List directory names - this is the list of installed plugins (not all may be active, but it's a good approximate list). Save `PLUGINS_LIST`.

Pay special attention to:
- Page builders: `elementor`, `elementor-pro`, `wpbakery`, `divi`, `beaver-builder`
- Cache: `wp-rocket`, `w3-total-cache`, `wp-super-cache`, `litespeed-cache`, `wp-fastest-cache`, `breeze`
- WooCommerce: `woocommerce`
- Image optimization: `imagify`, `smush`, `shortpixel`, `ewww-image-optimizer`
- Heavy SEO: `wordpress-seo` (Yoast), `rankmath`
- Backup: `updraftplus`, `backupbuddy`

Save detected performance-relevant plugins.

### 4. Mu-plugins
```
ls wp-content/mu-plugins/ 2>/dev/null
```
If they exist - list them. Mu-plugins load on every request, so they are audit candidates. Save `MU_PLUGINS`.

### 5. Multisite
Check in `wp-config.php` (Read) whether `define('MULTISITE', true)` or `define('WP_ALLOW_MULTISITE', true)` is present. Save `IS_MULTISITE`.

### 6. Custom code in theme
Check for the presence of `wp-content/themes/{{ACTIVE_THEME}}/functions.php` and `wp-content/themes/{{ACTIVE_THEME}}-child/functions.php` (child theme). Note whether they exist.

## ASK steps

### A1. Seasonality / traffic
> "What traffic does the site get at peak? If you know: how many sessions per day / RPS at peak hour. If you don't know - say `don't know`."

Save as `TRAFFIC_INFO`. If traffic is high (>10k sessions/day), in module 04 you will more strongly suggest a load test.

## Report data

Save: `WP_VERSION`, `ACTIVE_THEME`, `PLUGINS_COUNT`, `PLUGINS_LIST`, `MU_PLUGINS`, `IS_MULTISITE`, `TRAFFIC_INFO`. Goes into Cover + Methodology sections.
