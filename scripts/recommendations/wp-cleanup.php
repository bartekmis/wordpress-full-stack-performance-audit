<?php
/**
 * WOW Audit - Canonical WordPress Cleanup
 *
 * Removes standard WordPress head/frontend bloat: meta generator tag, RSD link,
 * WLW manifest, shortlink, feed links, REST/oEmbed head links, emoji stack,
 * dashicons on frontend, SVG filters, heartbeat, and resource hints (s.w.org).
 * Also disables XML-RPC.
 *
 * RECOMMENDED placement: mu-plugin (theme-independent, loads always).
 *   Save as: wp-content/mu-plugins/wow-wp-cleanup.php
 *
 * Alternative: drop into active theme functions.php (or child theme).
 *
 * To keep a specific feature, comment out the matching line below.
 * Comments next to each line describe what it removes - verify before applying.
 */

// ---------------------------------------------------------------------------
// Head cleanup + emoji removal + XML-RPC
// ---------------------------------------------------------------------------
add_action('init', function () {
    // Head meta / links
    remove_action('wp_head', 'wp_generator');                                 // WordPress version disclosure
    remove_action('wp_head', 'rsd_link');                                     // Really Simple Discovery (XML-RPC clients)
    remove_action('wp_head', 'wlwmanifest_link');                             // Windows Live Writer manifest (obsolete)
    remove_action('wp_head', 'wp_shortlink_wp_head');                         // Shortlink in <head>
    remove_action('wp_head', 'feed_links', 2);                                // Main RSS feed links
    remove_action('wp_head', 'feed_links_extra', 3);                          // Category/tag/author feed links
    remove_action('wp_head', 'wp_resource_hints', 2);                         // dns-prefetch (e.g. s.w.org) - keep if you need specific hints

    // REST API / oEmbed discovery
    remove_action('wp_head', 'rest_output_link_wp_head', 10);                 // REST API discovery <link>
    remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);            // oEmbed discovery links
    remove_action('template_redirect', 'rest_output_link_header', 11, 0);     // REST API Link header

    // Gutenberg global styles SVG filters (block-library)
    remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');

    // Emoji stack (scripts + styles + filters)
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

    // Disable XML-RPC (block brute-force + pingbacks)
    add_filter('xmlrpc_enabled', '__return_false');
});

// ---------------------------------------------------------------------------
// Dequeue unnecessary scripts & styles on frontend
// ---------------------------------------------------------------------------
add_action('wp_enqueue_scripts', function () {
    // Dashicons: needed by admin bar; dequeue only when not shown to the user
    if (!is_admin_bar_showing()) {
        wp_dequeue_style('dashicons');
    }

    // Heartbeat: 15s polling script, not needed for frontend visitors
    wp_deregister_script('heartbeat');
});
