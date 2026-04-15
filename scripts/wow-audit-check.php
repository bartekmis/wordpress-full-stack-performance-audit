<?php
/**
 * WOW Audit Check - ALL IN ONE FILE
 *
 * Replaces 4 separate scripts (phpinfo + bench + server-check + db-check).
 * One upload, one curl, one removal.
 *
 * SECURITY: TEMPORARY file. REMOVE IMMEDIATELY AFTER USE.
 *
 * Sections:
 *   1. PHP Runtime (phpinfo extract)
 *   2. OPcache Live Stats
 *   3. CPU Benchmark (md5 1M + math + I/O)
 *   4. PHP-FPM Workers
 *   5. System Resources (RAM, CPU, load, disk)
 *   6. MySQL Performance
 *   7. WordPress DB (autoload, revisions, posts, plugins, WC, tables)
 *   8. wp-config Constants (runtime)
 *
 * Usage:
 *   1. Upload with a RANDOM name to the WordPress root: wow-audit-check-XXXX.php
 *   2. Open: https://yourdomain.com/wow-audit-check-XXXX.php
 *   3. The AI tool fetches the result (curl)
 *   4. DELETE THE FILE and verify 404
 */

@set_time_limit(120);
@ini_set('memory_limit', '256M');

// ========== Load WordPress ==========
$wp_loaded = false;
foreach ([__DIR__, __DIR__.'/..', __DIR__.'/../..', __DIR__.'/../../..'] as $dir) {
    if (file_exists("$dir/wp-load.php")) { require_once "$dir/wp-load.php"; $wp_loaded = true; break; }
}

$data = [];

// ================================================================
// 1. PHP RUNTIME
// ================================================================
$data['php'] = [
    'version'              => PHP_VERSION,
    'sapi'                 => php_sapi_name(),
    'os'                   => PHP_OS . ' ' . php_uname('r'),
    'arch'                 => php_uname('m'),
    'memory_limit'         => ini_get('memory_limit'),
    'max_execution_time'   => ini_get('max_execution_time'),
    'max_input_vars'       => ini_get('max_input_vars'),
    'upload_max_filesize'  => ini_get('upload_max_filesize'),
    'post_max_size'        => ini_get('post_max_size'),
    'realpath_cache_size_config'  => ini_get('realpath_cache_size'),
    'realpath_cache_ttl_config'   => ini_get('realpath_cache_ttl'),
    // LIVE values: how much the current PHP process actually has cached.
    // On healthy FPM workers this grows across requests; on LSAPI with per-request
    // worker reset it stays near zero. A WordPress request touches 500-2000+ file
    // paths, so a healthy cache typically sits at 5000-50000 bytes after warmup.
    // Near-zero here on a loaded WP install is the canonical "worker recycles too
    // aggressively / LSAPI cold-starts every request" fingerprint.
    'realpath_cache_current_bytes' => function_exists('realpath_cache_size') ? realpath_cache_size() : null,
    'realpath_cache_entries'       => function_exists('realpath_cache_get') ? count(realpath_cache_get()) : null,
    'session_handler'      => ini_get('session.save_handler'),
    'display_errors'       => ini_get('display_errors'),
    'disabled_functions'   => ini_get('disable_functions'),
    'current_memory_mb'    => round(memory_get_usage(true) / 1048576, 2),
    'peak_memory_mb'       => round(memory_get_peak_usage(true) / 1048576, 2),
];

// Extensions check
$ext_list = ['opcache', 'redis', 'memcached', 'apcu', 'imagick', 'gd', 'mysqli', 'pdo_mysql', 'curl', 'mbstring', 'intl', 'zip'];
$data['php']['extensions'] = [];
foreach ($ext_list as $e) $data['php']['extensions'][$e] = extension_loaded($e);

// ================================================================
// 2. OPCACHE LIVE STATS
// ================================================================
$data['opcache'] = ['available' => false];
if (function_exists('opcache_get_status')) {
    $s = @opcache_get_status(false);
    $c = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : null;
    if ($s && is_array($s)) {
        $mem = $s['memory_usage'] ?? [];
        $st = $s['opcache_statistics'] ?? [];
        $d = $c['directives'] ?? [];
        $data['opcache'] = [
            'available'           => true,
            'enabled'             => $s['opcache_enabled'] ?? false,
            'config_memory_mb'    => isset($d['opcache.memory_consumption']) ? round($d['opcache.memory_consumption'] / 1048576) : null,
            'config_max_files'    => $d['opcache.max_accelerated_files'] ?? null,
            'config_validate'     => $d['opcache.validate_timestamps'] ?? null,
            'config_revalidate_freq' => $d['opcache.revalidate_freq'] ?? null,
            'config_jit'          => $d['opcache.jit'] ?? null,
            'config_jit_buffer_mb'=> isset($d['opcache.jit_buffer_size']) ? round($d['opcache.jit_buffer_size'] / 1048576, 1) : null,
            'memory_used_mb'      => round(($mem['used_memory'] ?? 0) / 1048576, 1),
            'memory_free_mb'      => round(($mem['free_memory'] ?? 0) / 1048576, 1),
            'memory_wasted_pct'   => round($mem['current_wasted_percentage'] ?? 0, 2),
            'cached_scripts'      => $st['num_cached_scripts'] ?? 0,
            'max_cached_keys'     => $st['max_cached_keys'] ?? 0,
            'hit_rate'            => round($st['opcache_hit_rate'] ?? 0, 2),
            'oom_restarts'        => $st['oom_restarts'] ?? 0,
            'keys_used_pct'       => ($st['max_cached_keys'] ?? 0) > 0
                ? round(($st['num_cached_keys'] ?? 0) / $st['max_cached_keys'] * 100, 1) : null,
            'recent_scripts'      => [],
        ];

        $full = @opcache_get_status(true);
        if ($full && !empty($full['scripts']) && is_array($full['scripts'])) {
            $scripts = $full['scripts'];
            uasort($scripts, function ($a, $b) {
                return ($b['last_used_timestamp'] ?? 0) <=> ($a['last_used_timestamp'] ?? 0);
            });
            $suspicious_patterns = [
                '#/cache/#i', '#/tmp/#i', '#/wp-content/uploads/#i',
                '#/wp-content/cache/#i', '#/cache-enabler/#i',
                '#/\.cache/#i', '#/runtime/#i',
                '#[a-f0-9]{16,}#',
                '#eval\(\)\'d code#i', '#\brun-time-created#i',
                '#\.tmp\.php$#i',
            ];
            $top = array_slice($scripts, 0, 20, true);
            foreach ($top as $path => $info) {
                $susp = [];
                foreach ($suspicious_patterns as $p) {
                    if (preg_match($p, $path)) { $susp[] = trim($p, '#i'); }
                }
                $data['opcache']['recent_scripts'][] = [
                    'path'          => $path,
                    'hits'          => $info['hits'] ?? 0,
                    'memory_kb'     => round(($info['memory_consumption'] ?? 0) / 1024, 1),
                    'last_used'     => $info['last_used_timestamp'] ?? 0,
                    'age_seconds'   => $info['last_used_timestamp'] ? (time() - $info['last_used_timestamp']) : null,
                    'suspicious'    => $susp,
                ];
            }
        }
    }
}

// ================================================================
// 3. CPU BENCHMARK
// ================================================================
$iterations = isset($_GET['n']) ? max(100000, min(5000000, (int)$_GET['n'])) : 1000000;

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) md5('test');
$bench_md5 = round((microtime(true) - $start) * 1000);

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) sqrt($i) + sin($i);
$bench_math = round((microtime(true) - $start) * 1000);

$bench_io = null;
$tmp = sys_get_temp_dir();
if ($tmp && is_writable($tmp)) {
    $f = "$tmp/wow-bench-" . uniqid() . '.tmp';
    $chunk = str_repeat('A', 1048576);
    $start = microtime(true);
    for ($i = 0; $i < 5; $i++) { file_put_contents($f, $chunk); file_get_contents($f); }
    @unlink($f);
    $bench_io = round((microtime(true) - $start) * 1000);
}

$factor = $iterations / 1000000;
$rate = function($ms, $t) { return $ms <= $t[0] ? 'fast' : ($ms <= $t[1] ? 'normal' : ($ms <= $t[2] ? 'slow' : 'very-slow')); };

$data['benchmark'] = [
    'iterations'   => $iterations,
    'md5_ms'       => $bench_md5,
    'md5_rating'   => $rate($bench_md5, [120*$factor, 220*$factor, 400*$factor]),
    'math_ms'      => $bench_math,
    'math_rating'  => $rate($bench_math, [200*$factor, 400*$factor, 700*$factor]),
    'io_ms'        => $bench_io,
    'io_rating'    => $bench_io === null ? 'n/a' : $rate($bench_io, [80, 200, 500]),
];

// ================================================================
// 4. PHP-FPM WORKERS
// ================================================================
$data['fpm'] = ['detected' => stripos(php_sapi_name(), 'fpm') !== false];
if ($data['fpm']['detected']) {
    $pool_paths = [
        '/etc/php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '/fpm/pool.d/www.conf',
        '/etc/php-fpm.d/www.conf', '/usr/local/etc/php-fpm.d/www.conf',
    ];
    foreach ($pool_paths as $p) {
        if (is_readable($p)) {
            $pc = file_get_contents($p);
            $data['fpm']['config_path'] = $p;
            foreach (['pm' => '/^pm\s*=\s*(.+)$/m', 'pm_max_children' => '/^pm\.max_children\s*=\s*(\d+)/m',
                       'pm_start_servers' => '/^pm\.start_servers\s*=\s*(\d+)/m',
                       'pm_max_requests' => '/^pm\.max_requests\s*=\s*(\d+)/m'] as $k => $pat) {
                if (preg_match($pat, $pc, $m)) $data['fpm'][$k] = trim($m[1]);
            }
            break;
        }
    }
    // Try FPM status
    foreach (['http://localhost/fpm-status', 'http://127.0.0.1/fpm-status'] as $url) {
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $st = @file_get_contents($url, false, $ctx);
        if ($st && stripos($st, 'pool') !== false) {
            if (preg_match('/active processes:\s*(\d+)/i', $st, $m)) $data['fpm']['active_processes'] = (int)$m[1];
            if (preg_match('/total processes:\s*(\d+)/i', $st, $m)) $data['fpm']['total_processes'] = (int)$m[1];
            if (preg_match('/max children reached:\s*(\d+)/i', $st, $m)) $data['fpm']['max_children_reached'] = (int)$m[1];
            break;
        }
    }
}

// ================================================================
// 5. SYSTEM RESOURCES
// ================================================================
$data['system'] = [];
if (is_readable('/proc/meminfo')) {
    $mi = file_get_contents('/proc/meminfo');
    if (preg_match('/MemTotal:\s+(\d+)/', $mi, $m)) $data['system']['ram_total_mb'] = round($m[1]/1024);
    if (preg_match('/MemAvailable:\s+(\d+)/', $mi, $m)) $data['system']['ram_available_mb'] = round($m[1]/1024);
    if (isset($data['system']['ram_total_mb'], $data['system']['ram_available_mb']))
        $data['system']['ram_used_pct'] = round((1 - $data['system']['ram_available_mb']/$data['system']['ram_total_mb'])*100, 1);
}
if (is_readable('/proc/cpuinfo')) {
    preg_match_all('/^processor\s+:/m', file_get_contents('/proc/cpuinfo'), $m);
    $data['system']['cpu_cores'] = count($m[0]);
    if (preg_match('/model name\s+:\s+(.+)/i', file_get_contents('/proc/cpuinfo'), $m)) $data['system']['cpu_model'] = trim($m[1]);
}
if (is_readable('/proc/loadavg')) {
    $l = explode(' ', trim(file_get_contents('/proc/loadavg')));
    $data['system']['load_1m'] = (float)$l[0]; $data['system']['load_5m'] = (float)$l[1];
}
$data['system']['disk_free_gb'] = round(disk_free_space(__DIR__)/1073741824, 1);
$data['system']['disk_total_gb'] = round(disk_total_space(__DIR__)/1073741824, 1);

// FPM recommendation
if (isset($data['system']['ram_total_mb'])) {
    $overhead = max(512, $data['system']['ram_total_mb'] * 0.25);
    $avail = $data['system']['ram_total_mb'] - $overhead;
    $rec = max(2, floor($avail / 50));
    $cur = isset($data['fpm']['pm_max_children']) ? (int)$data['fpm']['pm_max_children'] : null;
    $data['fpm_recommendation'] = [
        'recommended' => $rec, 'current' => $cur,
        'status' => $cur !== null ? ($cur > $rec*1.3 ? 'over-provisioned' : ($cur < $rec*0.5 ? 'under-provisioned' : 'ok')) : 'unknown',
    ];
}

// ================================================================
// 6. MYSQL PERFORMANCE (requires WordPress)
// ================================================================
$data['mysql'] = ['available' => false];
if ($wp_loaded) {
    global $wpdb;
    $data['mysql']['available'] = true;
    $data['mysql']['version'] = $wpdb->get_var('SELECT VERSION()');

    $vars = ['innodb_buffer_pool_size','max_connections','slow_query_log','long_query_time',
             'tmp_table_size','max_heap_table_size','innodb_flush_log_at_trx_commit'];
    $data['mysql']['variables'] = [];
    foreach ($vars as $v) { $val = $wpdb->get_var("SHOW VARIABLES LIKE '$v'", 1); if ($val !== null) $data['mysql']['variables'][$v] = $val; }

    if (isset($data['mysql']['variables']['innodb_buffer_pool_size']))
        $data['mysql']['innodb_buffer_pool_mb'] = round((int)$data['mysql']['variables']['innodb_buffer_pool_size']/1048576);

    $svars = ['Threads_connected','Threads_running','Slow_queries','Innodb_buffer_pool_read_requests','Innodb_buffer_pool_reads','Created_tmp_tables','Created_tmp_disk_tables'];
    $data['mysql']['status'] = [];
    foreach ($svars as $v) { $val = $wpdb->get_var("SHOW GLOBAL STATUS LIKE '$v'", 1); if ($val !== null) $data['mysql']['status'][$v] = $val; }

    $rr = (int)($data['mysql']['status']['Innodb_buffer_pool_read_requests'] ?? 0);
    $dr = (int)($data['mysql']['status']['Innodb_buffer_pool_reads'] ?? 0);
    if ($rr > 0) $data['mysql']['buffer_hit_ratio'] = round((1 - $dr/$rr)*100, 2);

    $start = microtime(true); $wpdb->get_var('SELECT 1'); $data['mysql']['ping_ms'] = round((microtime(true)-$start)*1000, 1);
}

// ================================================================
// 7. WORDPRESS DB
// ================================================================
$data['wordpress'] = ['available' => $wp_loaded];
if ($wp_loaded) {
    $data['wordpress']['wp_version'] = get_bloginfo('version');
    $data['wordpress']['active_theme'] = get_stylesheet();
    $data['wordpress']['parent_theme'] = get_template();
    $data['wordpress']['multisite'] = is_multisite() ? 'yes' : 'no';
    $data['wordpress']['table_prefix'] = $wpdb->prefix;
    $data['wordpress']['siteurl'] = get_option('siteurl');
    $data['wordpress']['home'] = get_option('home');
    $data['wordpress']['language'] = get_locale();

    // Autoload
    $al = $wpdb->get_row("SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')");
    $data['wordpress']['autoload'] = ['count' => (int)$al->cnt, 'mb' => round($al->bytes/1048576, 2)];

    // Top 20 autoload
    $data['wordpress']['autoload_top20'] = $wpdb->get_results("SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY bytes DESC LIMIT 20", ARRAY_A);

    // Posts
    $data['wordpress']['posts'] = $wpdb->get_results("SELECT post_type, post_status, COUNT(*) AS n FROM {$wpdb->posts} GROUP BY post_type, post_status ORDER BY n DESC", ARRAY_A);
    $data['wordpress']['revisions'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision'");

    // Comments
    $data['wordpress']['comments'] = $wpdb->get_results("SELECT comment_approved, COUNT(*) AS n FROM {$wpdb->comments} GROUP BY comment_approved", ARRAY_A);

    // Tables
    $data['wordpress']['tables'] = $wpdb->get_results($wpdb->prepare("SELECT TABLE_NAME AS name, ROUND(((data_length+index_length)/1048576),2) AS size_mb, table_rows, ENGINE FROM information_schema.tables WHERE table_schema=%s ORDER BY (data_length+index_length) DESC", DB_NAME), ARRAY_A);

    // Non-InnoDB
    $data['wordpress']['non_innodb'] = $wpdb->get_results($wpdb->prepare("SELECT TABLE_NAME AS name, ENGINE FROM information_schema.tables WHERE table_schema=%s AND ENGINE!='InnoDB' AND ENGINE IS NOT NULL", DB_NAME), ARRAY_A);

    // Transients
    $tr = $wpdb->get_row("SELECT SUM(CASE WHEN option_name LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_transient\\_timeout\\_%' THEN 1 ELSE 0 END) AS total, SUM(CASE WHEN option_name LIKE '\\_transient\\_timeout\\_%' AND CAST(option_value AS UNSIGNED)<UNIX_TIMESTAMP() THEN 1 ELSE 0 END) AS expired FROM {$wpdb->options}");
    $data['wordpress']['transients'] = ['total' => (int)$tr->total, 'expired' => (int)$tr->expired];

    // Plugins
    $active = (array)get_option('active_plugins', []);
    $data['wordpress']['plugins'] = ['count' => count($active), 'list' => array_values($active), 'mu' => array_keys((array)get_mu_plugins())];

    // WooCommerce
    $wc = in_array('woocommerce/woocommerce.php', $active);
    $data['wordpress']['woocommerce'] = ['active' => $wc];
    if ($wc) {
        $wct = $wpdb->prefix.'wc_orders';
        $hpos = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wct)) === $wct;
        $data['wordpress']['woocommerce']['hpos'] = $hpos;
        $data['wordpress']['woocommerce']['orders'] = $hpos
            ? $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$wct} GROUP BY status", ARRAY_A)
            : $wpdb->get_results("SELECT post_status AS status, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type='shop_order' GROUP BY post_status", ARRAY_A);
    }

    // Object cache
    $data['wordpress']['object_cache'] = [
        'external' => wp_using_ext_object_cache(),
        'dropin' => file_exists(WP_CONTENT_DIR.'/object-cache.php'),
        'advanced_cache' => file_exists(WP_CONTENT_DIR.'/advanced-cache.php'),
    ];
}

// ================================================================
// 8. WP-CONFIG CONSTANTS (runtime)
// ================================================================
$consts = ['WP_DEBUG','WP_DEBUG_LOG','WP_DEBUG_DISPLAY','SCRIPT_DEBUG','SAVE_QUERIES',
           'WP_CACHE','DISABLE_WP_CRON','WP_CRON_LOCK_TIMEOUT','WP_POST_REVISIONS',
           'WP_MEMORY_LIMIT','WP_MAX_MEMORY_LIMIT','CONCATENATE_SCRIPTS',
           'DISALLOW_FILE_EDIT','FORCE_SSL_ADMIN','WP_HOME','WP_SITEURL','AUTOSAVE_INTERVAL','EMPTY_TRASH_DAYS'];
$data['constants'] = [];
foreach ($consts as $c) $data['constants'][$c] = defined($c) ? constant($c) : null;

// ================================================================
// OUTPUT
// ================================================================
header('Content-Type: text/html; charset=utf-8');
$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>WOW Audit Check</title>
<style>
body{font-family:-apple-system,sans-serif;max-width:1100px;margin:20px auto;padding:0 20px;color:#1a1a1a;line-height:1.5;font-size:13px}
.w{background:#ffebee;border:2px solid #c62828;color:#c62828;padding:14px;border-radius:6px;font-weight:700;font-size:16px;margin:16px 0}
h1{font-size:24px}h2{font-size:16px;margin-top:28px;padding-bottom:4px;border-bottom:2px solid #e5e5e5;color:#0066cc}
table{border-collapse:collapse;width:100%;margin:6px 0}th,td{text-align:left;padding:5px 8px;border-bottom:1px solid #e5e5e5;vertical-align:top}
th{background:#f8f9fa;font-weight:600}code{font-family:Monaco,Consolas,monospace;font-size:11px;background:#f0f0f0;padding:1px 4px;border-radius:2px}
.b{color:#c62828;font-weight:600}.g{color:#2e7d32;font-weight:600}.y{color:#ed6c02;font-weight:600}.m{color:#666;font-size:11px}
details{margin:6px 0}summary{cursor:pointer;font-weight:600}
</style></head><body>
<div class="w">DELETE THIS FILE IMMEDIATELY AFTER USE. It exposes the full server, database, and WordPress configuration.</div>
<h1>WOW Audit Check</h1>
<p class="m"><?=date('Y-m-d H:i:s')?> | <?=htmlspecialchars($_SERVER['SERVER_NAME']??'')?> | One file = phpinfo + benchmark + server + DB</p>

<h2>1. PHP Runtime</h2>
<table>
<?php foreach(['version','sapi','os','arch','memory_limit','max_execution_time','upload_max_filesize','post_max_size','session_handler','display_errors','current_memory_mb','peak_memory_mb'] as $k): ?>
<tr><td><code><?=$k?></code></td><td><?=htmlspecialchars((string)($data['php'][$k]??''))?></td></tr>
<?php endforeach; ?>
<tr><td>Extensions</td><td><?php foreach($data['php']['extensions'] as $e=>$v) echo '<code>'.$e.'</code>:'.($v?'<span class="g">ON</span>':'<span class="b">off</span>').' '; ?></td></tr>
</table>

<h3>Realpath cache (SAPI fingerprint)</h3>
<?php
  $rp_bytes = $data['php']['realpath_cache_current_bytes'];
  $rp_entries = $data['php']['realpath_cache_entries'];
  $sapi_lower = strtolower((string)$data['php']['sapi']);
  $is_lsapi = strpos($sapi_lower,'litespeed')!==false || strpos($sapi_lower,'lsapi')!==false;
  // Heuristic: a request that reached this script already loaded dozens of core PHP
  // files; a non-WP plain probe will sit around 500-3000 bytes. If a diagnostic run
  // inside a loaded WordPress shows < 2000 bytes, realpath cache is effectively
  // not persisting between requests on this worker.
  $rp_flag_class = '';
  $rp_flag_msg = '';
  if ($rp_bytes !== null) {
    if ($rp_bytes < 1500) {
      $rp_flag_class = 'b';
      $rp_flag_msg = 'very low - worker may be recycling per request (common on misconfigured LSAPI or low pm.max_requests on FPM). Every include() re-stats the full path. See diagnosis note below.';
    } elseif ($rp_bytes < 5000) {
      $rp_flag_class = 'y';
      $rp_flag_msg = 'low - cache is warming but may be resetting too often; refresh this page 3-5x and check whether the number grows.';
    } else {
      $rp_flag_class = 'g';
      $rp_flag_msg = 'healthy - cache persists across requests.';
    }
  }
?>
<table>
<tr><td><code>realpath_cache_size</code> (config)</td><td><?=htmlspecialchars((string)$data['php']['realpath_cache_size_config'])?></td></tr>
<tr><td><code>realpath_cache_ttl</code> (config)</td><td><?=htmlspecialchars((string)$data['php']['realpath_cache_ttl_config'])?> s</td></tr>
<tr><td><code>realpath_cache_size()</code> <strong>live bytes</strong></td><td class="<?=$rp_flag_class?>"><?=$rp_bytes===null?'n/a':number_format($rp_bytes).' B'?></td></tr>
<tr><td><code>realpath_cache_get()</code> entries</td><td><?=$rp_entries===null?'n/a':number_format($rp_entries)?></td></tr>
<?php if ($rp_flag_msg): ?>
<tr><td>Diagnosis</td><td class="<?=$rp_flag_class?>"><?=htmlspecialchars($rp_flag_msg)?></td></tr>
<?php endif; ?>
<?php if ($is_lsapi && $rp_bytes !== null && $rp_bytes < 1500): ?>
<tr><td>LSAPI note</td><td class="b">SAPI is LiteSpeed (LSAPI) and realpath cache is near-empty. Strong sign of worker recycling between requests. On a 1000+ file WordPress request this produces 1-3 s of extra syscall overhead (amplified further by CloudLinux LVE if present). Ask hosting to raise <code>LSAPI_CHILDREN</code> / <code>LSAPI_MAX_REQS</code> / LSWS <code>Max Idle Time</code>, or switch account to PHP-FPM.</td></tr>
<?php endif; ?>
</table>
<p class="m">How to interpret: refresh this page 3-5x in quick succession. If "live bytes" stays under ~1500 across reloads, the PHP worker is not persisting realpath cache - every file include pays a fresh stat() syscall. On WordPress with 1000+ files loaded per request this can add 1-3 s of TTFB that no WordPress-level optimization can remove. This is a <strong>server / SAPI</strong> problem, not a WordPress problem.</p>

<h2>2. OPcache</h2>
<?php if(!$data['opcache']['available']): ?><p class="b">Not available</p><?php else: ?>
<table>
<tr><td>Enabled</td><td class="<?=$data['opcache']['enabled']?'g':'b'?>"><?=$data['opcache']['enabled']?'YES':'NO'?></td></tr>
<tr><td>Memory</td><td><?=$data['opcache']['memory_used_mb']?>MB used / <?=$data['opcache']['memory_free_mb']?>MB free (config: <?=$data['opcache']['config_memory_mb']??'?'?>MB)</td></tr>
<tr><td>Wasted</td><td class="<?=$data['opcache']['memory_wasted_pct']>10?'y':''?>"><?=$data['opcache']['memory_wasted_pct']?>%</td></tr>
<tr><td>Hit rate</td><td class="<?=$data['opcache']['hit_rate']>=95?'g':($data['opcache']['hit_rate']>=80?'y':'b')?>"><?=$data['opcache']['hit_rate']?>%</td></tr>
<tr><td>Scripts cached</td><td><?=number_format($data['opcache']['cached_scripts'])?> (keys: <?=$data['opcache']['keys_used_pct']?>%)</td></tr>
<tr><td>OOM restarts</td><td class="<?=($data['opcache']['oom_restarts']??0)>0?'b':'g'?>"><?=$data['opcache']['oom_restarts']?></td></tr>
<tr><td>JIT</td><td><?=htmlspecialchars($data['opcache']['config_jit']??'off')?></td></tr>
</table>

<?php if(!empty($data['opcache']['recent_scripts'])): ?>
<h3>Top 20 most recently compiled scripts</h3>
<p class="m">Flagged paths (cache/tmp/uploads/hash-like names) can indicate plugins generating PHP on the fly - each unique path = 1 permanent miss.</p>
<table>
<tr><th>Path</th><th>Hits</th><th>Size</th><th>Last used</th><th>Flag</th></tr>
<?php foreach($data['opcache']['recent_scripts'] as $r):
    $age = $r['age_seconds'];
    $age_str = $age===null ? '?' : ($age < 60 ? $age.'s' : ($age < 3600 ? round($age/60).'m' : round($age/3600,1).'h')).' ago';
    $flag = !empty($r['suspicious']);
?>
<tr>
  <td><code style="font-size:11px"><?=htmlspecialchars($r['path'])?></code></td>
  <td><?=number_format($r['hits'])?></td>
  <td><?=$r['memory_kb']?>KB</td>
  <td><?=$age_str?></td>
  <td class="<?=$flag?'b':''?>"><?=$flag?htmlspecialchars(implode(', ',$r['suspicious'])):'ok'?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
<?php endif; ?>

<h2>3. CPU Benchmark (<?=number_format($iterations)?> iter)</h2>
<table>
<tr><td>MD5</td><td><strong><?=$bench_md5?>ms</strong></td><td class="<?=$data['benchmark']['md5_rating']?>"><?=$data['benchmark']['md5_rating']?></td></tr>
<tr><td>Math</td><td><?=$bench_math?>ms</td><td class="<?=$data['benchmark']['math_rating']?>"><?=$data['benchmark']['math_rating']?></td></tr>
<tr><td>File I/O</td><td><?=$bench_io??'n/a'?>ms</td><td class="<?=$data['benchmark']['io_rating']?>"><?=$data['benchmark']['io_rating']?></td></tr>
</table>

<h2>4. PHP-FPM</h2>
<?php if(!$data['fpm']['detected']): ?><p class="m">Not FPM (SAPI: <?=php_sapi_name()?>)</p>
<?php else: ?>
<table>
<?php foreach(['pm','pm_max_children','pm_start_servers','pm_max_requests','config_path'] as $k): if(isset($data['fpm'][$k])): ?>
<tr><td><code><?=$k?></code></td><td><?=htmlspecialchars((string)$data['fpm'][$k])?></td></tr>
<?php endif; endforeach; ?>
<?php if(isset($data['fpm']['max_children_reached'])): ?>
<tr><td>max_children_reached</td><td class="<?=$data['fpm']['max_children_reached']>0?'b':'g'?>"><?=$data['fpm']['max_children_reached']?></td></tr>
<?php endif; ?>
</table>
<?php if(isset($data['fpm_recommendation'])): ?>
<p class="m">Recommended max_children: <strong><?=$data['fpm_recommendation']['recommended']?></strong> (current: <?=$data['fpm_recommendation']['current']??'?'?>, status: <strong><?=$data['fpm_recommendation']['status']?></strong>)</p>
<?php endif; endif; ?>

<h2>5. System</h2>
<table>
<?php if(isset($data['system']['ram_total_mb'])): ?>
<tr><td>RAM</td><td><?=$data['system']['ram_available_mb']??'?'?> / <?=$data['system']['ram_total_mb']?> MB (<?=$data['system']['ram_used_pct']??'?'?>% used)</td></tr>
<?php endif; if(isset($data['system']['cpu_cores'])): ?>
<tr><td>CPU</td><td><?=$data['system']['cpu_cores']?> cores<?=isset($data['system']['cpu_model'])?' - '.htmlspecialchars($data['system']['cpu_model']):''?></td></tr>
<?php endif; if(isset($data['system']['load_1m'])): ?>
<tr><td>Load</td><td><?=$data['system']['load_1m']?> / <?=$data['system']['load_5m']?></td></tr>
<?php endif; ?>
<tr><td>Disk</td><td><?=$data['system']['disk_free_gb']?> / <?=$data['system']['disk_total_gb']?> GB free</td></tr>
</table>

<h2>6. MySQL</h2>
<?php if(!$data['mysql']['available']): ?><p class="m">wp-load.php not found</p>
<?php else: ?>
<table>
<tr><td>Version</td><td><?=htmlspecialchars($data['mysql']['version'])?></td></tr>
<tr><td>Buffer pool</td><td><?=$data['mysql']['innodb_buffer_pool_mb']??'?'?> MB</td></tr>
<tr><td>Buffer hit ratio</td><td class="<?=($data['mysql']['buffer_hit_ratio']??100)>=99?'g':(($data['mysql']['buffer_hit_ratio']??100)>=95?'y':'b')?>"><?=$data['mysql']['buffer_hit_ratio']??'?'?>%</td></tr>
<tr><td>Slow query log</td><td><?=$data['mysql']['variables']['slow_query_log']??'?'?></td></tr>
<tr><td>Threads connected</td><td><?=$data['mysql']['status']['Threads_connected']??'?'?></td></tr>
<tr><td>Slow queries</td><td><?=$data['mysql']['status']['Slow_queries']??'?'?></td></tr>
<tr><td>Ping</td><td><?=$data['mysql']['ping_ms']??'?'?> ms</td></tr>
</table>
<?php endif; ?>

<?php if($wp_loaded): ?>
<h2>7. WordPress DB</h2>
<table>
<tr><td>WP version</td><td><?=$data['wordpress']['wp_version']?></td></tr>
<tr><td>Theme</td><td><?=$data['wordpress']['active_theme']?><?=$data['wordpress']['parent_theme']!==$data['wordpress']['active_theme']?' (parent: '.$data['wordpress']['parent_theme'].')':''?></td></tr>
<tr><td>Autoload</td><td class="<?=$data['wordpress']['autoload']['mb']>2?'b':($data['wordpress']['autoload']['mb']>1?'y':'g')?>"><?=$data['wordpress']['autoload']['mb']?> MB (<?=$data['wordpress']['autoload']['count']?> options)</td></tr>
<tr><td>Revisions</td><td class="<?=$data['wordpress']['revisions']>1000?'b':($data['wordpress']['revisions']>500?'y':'g')?>"><?=number_format($data['wordpress']['revisions'])?></td></tr>
<tr><td>Transients</td><td><?=$data['wordpress']['transients']['total']?> (expired: <?=$data['wordpress']['transients']['expired']?>)</td></tr>
<tr><td>Plugins</td><td><?=$data['wordpress']['plugins']['count']?></td></tr>
<tr><td>Object cache</td><td class="<?=$data['wordpress']['object_cache']['external']?'g':'y'?>"><?=$data['wordpress']['object_cache']['external']?'External (Redis/Memcached)':'In-memory only'?></td></tr>
<?php if($data['wordpress']['woocommerce']['active']): ?>
<tr><td>WooCommerce</td><td>Active | HPOS: <?=!empty($data['wordpress']['woocommerce']['hpos'])?'YES':'no'?></td></tr>
<?php endif; ?>
</table>

<details><summary>Top 20 autoload options</summary>
<table><tr><th>option_name</th><th>bytes</th></tr>
<?php foreach($data['wordpress']['autoload_top20'] as $r): ?>
<tr><td><code><?=htmlspecialchars($r['option_name'])?></code></td><td><?=number_format($r['bytes'])?></td></tr>
<?php endforeach; ?></table></details>

<details><summary>Active plugins (<?=$data['wordpress']['plugins']['count']?>)</summary>
<ul><?php foreach($data['wordpress']['plugins']['list'] as $p): ?><li><code><?=htmlspecialchars($p)?></code></li><?php endforeach; ?></ul></details>

<details><summary>Database tables (<?=count($data['wordpress']['tables'])?>)</summary>
<table><tr><th>table</th><th>MB</th><th>rows</th><th>engine</th></tr>
<?php foreach($data['wordpress']['tables'] as $r): ?>
<tr><td><code><?=htmlspecialchars($r['name'])?></code></td><td><?=$r['size_mb']?></td><td><?=number_format((int)($r['row_count']??0))?></td><td class="<?=($r['ENGINE']??'')==='InnoDB'?'':'b'?>"><?=htmlspecialchars($r['ENGINE']??'')?></td></tr>
<?php endforeach; ?></table></details>

<h2>8. wp-config Constants</h2>
<table>
<?php foreach($data['constants'] as $k=>$v): ?>
<tr><td><code><?=$k?></code></td><td><?=$v===null?'<span class="m">(undefined)</span>':(is_bool($v)?($v?'<strong>true</strong>':'false'):htmlspecialchars((string)$v))?></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<!-- WOW_AUDIT_JSON_START
<?=$json?>
WOW_AUDIT_JSON_END -->

<div class="w" style="margin-top:30px">DELETE THIS FILE IMMEDIATELY.</div>
</body></html>
