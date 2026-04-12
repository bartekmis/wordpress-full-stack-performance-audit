// WP-Bloat-Detection.js - detects standard WordPress head/frontend bloat
// Checks which items from the canonical wp-cleanup.php are still present on the page.
(() => {
  const head = document.head ? document.head.innerHTML : '';
  const hasAdminBar = !!document.getElementById('wpadminbar');

  const items = [
    {
      key: 'wp_generator',
      label: 'Meta generator tag (WordPress version disclosure)',
      test: () => !!document.querySelector('meta[name="generator"][content^="WordPress"], meta[name="generator"][content*="WordPress"]'),
      fix: "remove_action('wp_head', 'wp_generator');"
    },
    {
      key: 'rsd_link',
      label: 'RSD link (Really Simple Discovery, XML-RPC clients)',
      test: () => !!document.querySelector('link[rel="EditURI"]'),
      fix: "remove_action('wp_head', 'rsd_link');"
    },
    {
      key: 'wlwmanifest_link',
      label: 'Windows Live Writer manifest (obsolete)',
      test: () => !!document.querySelector('link[rel="wlwmanifest"]'),
      fix: "remove_action('wp_head', 'wlwmanifest_link');"
    },
    {
      key: 'wp_shortlink',
      label: 'Shortlink in head',
      test: () => !!document.querySelector('link[rel="shortlink"]'),
      fix: "remove_action('wp_head', 'wp_shortlink_wp_head');"
    },
    {
      key: 'feed_links',
      label: 'RSS feed discovery links',
      test: () => document.querySelectorAll('link[rel="alternate"][type="application/rss+xml"], link[rel="alternate"][type="application/atom+xml"]').length > 0,
      fix: "remove_action('wp_head', 'feed_links', 2); remove_action('wp_head', 'feed_links_extra', 3);"
    },
    {
      key: 'rest_link',
      label: 'REST API discovery link',
      test: () => !!document.querySelector('link[rel="https://api.w.org/"]'),
      fix: "remove_action('wp_head', 'rest_output_link_wp_head', 10);"
    },
    {
      key: 'oembed_links',
      label: 'oEmbed discovery links',
      test: () => document.querySelectorAll('link[type="application/json+oembed"], link[type="text/xml+oembed"]').length > 0,
      fix: "remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);"
    },
    {
      key: 'emoji',
      label: 'Emoji detection script/styles',
      test: () => !!window.wpemojiSettings
        || /wp-emoji-release|s\.w\.org\/images\/core\/emoji/.test(head)
        || Array.from(document.scripts).some(s => /wp-emoji-release/.test(s.src || s.textContent || '')),
      fix: "remove_action('wp_head', 'print_emoji_detection_script', 7); remove_action('wp_print_styles', 'print_emoji_styles');"
    },
    {
      key: 'dashicons_frontend',
      label: 'Dashicons CSS on frontend (no admin bar)',
      test: () => !hasAdminBar && !!document.querySelector('link[id="dashicons-css"], link[href*="/wp-includes/css/dashicons"]'),
      fix: "if (!is_admin_bar_showing()) { wp_dequeue_style('dashicons'); }"
    },
    {
      key: 'svg_filters',
      label: 'Global styles SVG filters (block-library)',
      test: () => !!document.querySelector('body > svg.svg-filters, body > svg[style*="display:none"]:first-child, body > svg.components-visually-hidden'),
      fix: "remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');"
    },
    {
      key: 'heartbeat_frontend',
      label: 'Heartbeat script on frontend',
      test: () => Array.from(document.scripts).some(s => /\/wp-includes\/js\/heartbeat/.test(s.src || '')),
      fix: "wp_deregister_script('heartbeat');"
    },
    {
      key: 'resource_hints',
      label: 'wp_resource_hints dns-prefetch (often unwanted, e.g. s.w.org)',
      test: () => !!document.querySelector('link[rel="dns-prefetch"][href*="s.w.org"]'),
      fix: "remove_action('wp_head', 'wp_resource_hints', 2);"
    }
  ];

  const present = [];
  const absent = [];

  items.forEach(item => {
    try {
      if (item.test()) {
        present.push({ key: item.key, label: item.label, fix: item.fix });
      } else {
        absent.push(item.key);
      }
    } catch (e) {
      absent.push(item.key);
    }
  });

  const result = {
    script: 'WP-Bloat-Detection',
    status: 'ok',
    presentCount: present.length,
    absentCount: absent.length,
    present,
    absent,
    recommendation: present.length > 0
      ? `${present.length} standard WP bloat item(s) detected. See wow-audit/scripts/recommendations/wp-cleanup.php for the canonical one-shot fix.`
      : 'No standard WP bloat detected in head/frontend. Cleanup already in place or not applicable.'
  };

  console.group(`%cWP Bloat Detection: ${present.length} item(s) present, ${absent.length} absent`, 'font-weight: bold; font-size: 13px;');
  if (present.length) {
    console.log('Present bloat items:');
    present.forEach(p => console.log(`  - ${p.label} [${p.key}]`));
    console.log('Recommendation: apply wow-audit/scripts/recommendations/wp-cleanup.php as mu-plugin or theme functions.php');
  } else {
    console.log('No standard WP bloat detected.');
  }
  console.groupEnd();

  return result;
})();
