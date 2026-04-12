/**
 * WOW Audit - Pre-FCP Resource Classifier (modul 08, krok 7b.3)
 *
 * Automatycznie klasyfikuje zasoby zaladowane PRZED First Contentful Paint.
 * Kategorie: removable / deferrable (known + unknown 3P) / review_1p / other
 * + concat candidates (>2 pliki z tego samego origin, ten sam typ, first-party)
 *
 * Odpal przez chrome-devtools.evaluate_script({ function: `<TRESC_TEGO_PLIKU>` })
 */
(() => {
  const fcp = performance.getEntriesByName('first-contentful-paint')[0];
  const fcpTime = fcp ? fcp.startTime : 0;
  const host = location.hostname;

  const resources = performance.getEntriesByType('resource')
    .filter(r => r.startTime < fcpTime)
    .sort((a, b) => a.startTime - b.startTime);

  // Known deferrable patterns
  const deferrablePatterns = [
    { pattern: /recaptcha|gstatic\.com\/recaptcha/i, name: 'reCAPTCHA', priority: 'high' },
    { pattern: /hcaptcha\.com/i, name: 'hCaptcha', priority: 'high' },
    { pattern: /tidio|intercom|tawk\.to|livechatinc|zdassets|crisp\.chat|drift\.com/i, name: 'Chat widget', priority: 'high' },
    { pattern: /youtube\.com\/(iframe_api|embed)|player\.vimeo\.com/i, name: 'Video embed', priority: 'high' },
    { pattern: /maps\.googleapis\.com\/maps|maps\.google\.com/i, name: 'Google Maps', priority: 'high' },
    { pattern: /js\.stripe\.com|checkout\.stripe\.com|www\.paypal\.com\/sdk/i, name: 'Payment SDK', priority: 'high' },
    { pattern: /googletagmanager\.com\/gtm\.js/i, name: 'GTM', priority: 'medium' },
    { pattern: /google-analytics\.com|googletagmanager\.com\/gtag/i, name: 'Google Analytics', priority: 'medium' },
    { pattern: /connect\.facebook\.net.*fbevents/i, name: 'FB Pixel', priority: 'medium' },
    { pattern: /static\.hotjar\.com|clarity\.ms|fullstory\.com/i, name: 'Session recording', priority: 'medium' },
    { pattern: /platform\.(twitter|x)\.com|connect\.facebook\.net(?!.*fbevents)|platform\.instagram|platform\.linkedin/i, name: 'Social embed', priority: 'low' },
    { pattern: /cookielaw\.org|termly\.io|cookieconsent|iubenda/i, name: 'Cookie consent', priority: 'low' },
    { pattern: /trustpilot|elfsight/i, name: 'Review widget', priority: 'low' },
    { pattern: /onesignal|pushengage|pushwoosh/i, name: 'Push SDK', priority: 'low' },
    { pattern: /heapanalytics|mxpnl|segment\.com/i, name: 'Analytics', priority: 'low' },
    { pattern: /bat\.bing\.com|branch\.io/i, name: 'Tracking', priority: 'low' },
  ];

  // Known removable patterns (WordPress-specific)
  const removablePatterns = [
    { pattern: /wp-emoji-release/i, name: 'WP Emoji polyfill' },
    { pattern: /jquery-migrate/i, name: 'jQuery Migrate' },
    { pattern: /dashicons\.min\.css/i, name: 'Dashicons CSS (admin icons)' },
    { pattern: /wp-embed\.min\.js/i, name: 'WP oEmbed' },
    { pattern: /block-library\/style/i, name: 'Block Library CSS (Gutenberg)' },
  ];

  const classified = resources.map(r => {
    const url = r.name;
    const domain = new URL(url).hostname;
    const isFirstParty = domain === host || domain.endsWith('.' + host);
    const sizeKB = Math.round((r.transferSize || 0) / 1024);

    let category = 'unknown';
    let service = null;
    let deferPriority = null;

    // Check removable
    for (const p of removablePatterns) {
      if (p.pattern.test(url)) { category = 'removable'; service = p.name; break; }
    }
    // Check deferrable
    if (category === 'unknown') {
      for (const p of deferrablePatterns) {
        if (p.pattern.test(url)) { category = 'deferrable'; service = p.name; deferPriority = p.priority; break; }
      }
    }
    // If still unknown, classify by type + party
    if (category === 'unknown') {
      if (!isFirstParty && (r.initiatorType === 'script' || r.initiatorType === 'link')) {
        category = 'deferrable_unknown_3p';
        service = 'Unknown 3rd party: ' + domain;
        deferPriority = 'medium';
      } else if (isFirstParty && (r.initiatorType === 'script' || r.initiatorType === 'link')) {
        category = 'review_1p';
        service = '1st party asset';
      } else {
        category = 'other';
      }
    }

    return {
      url: url.length > 120 ? url.slice(0, 120) + '...' : url,
      type: r.initiatorType, sizeKB,
      startTime: Math.round(r.startTime),
      duration: Math.round(r.duration),
      domain, isFirstParty,
      renderBlocking: r.renderBlockingStatus || 'unknown',
      category, service, deferPriority
    };
  });

  // Concatenation candidates (>2 files from same origin, same type, first-party)
  const byOriginType = {};
  classified.filter(r => r.isFirstParty && (r.type === 'script' || r.type === 'link')).forEach(r => {
    const key = r.domain + '|' + r.type;
    if (!byOriginType[key]) byOriginType[key] = [];
    byOriginType[key].push(r.url);
  });
  const concatCandidates = Object.entries(byOriginType)
    .filter(([_, files]) => files.length > 2)
    .map(([key, files]) => ({ origin_type: key, count: files.length, files }));

  return {
    fcp_ms: Math.round(fcpTime),
    total_pre_fcp: classified.length,
    total_pre_fcp_kb: classified.reduce((s, r) => s + r.sizeKB, 0),
    by_category: {
      critical_or_review: classified.filter(r => r.category === 'review_1p' || r.category === 'other').length,
      deferrable_known: classified.filter(r => r.category === 'deferrable').length,
      deferrable_unknown_3p: classified.filter(r => r.category === 'deferrable_unknown_3p').length,
      removable: classified.filter(r => r.category === 'removable').length,
    },
    resources: classified,
    concat_candidates: concatCandidates
  };
})()
