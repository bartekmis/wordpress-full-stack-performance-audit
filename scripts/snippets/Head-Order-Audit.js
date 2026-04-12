// Head Order Audit - inspired by csswizardry/ct (Harry Roberts)
// Analyzes <head> element ordering for optimal loading performance.
// Blocking resources before async/preload = suboptimal order.
(() => {
  const children = [...document.head.children];
  const items = children.map((el, i) => {
    const tag = el.tagName;
    const rel = el.getAttribute('rel') || '';
    const httpEquiv = el.getAttribute('http-equiv') || '';
    const isCharset = tag === 'META' && el.hasAttribute('charset');
    const isViewport = tag === 'META' && el.getAttribute('name') === 'viewport';
    const isPreconnect = tag === 'LINK' && rel === 'preconnect';
    const isDnsPrefetch = tag === 'LINK' && rel === 'dns-prefetch';
    const isPreload = tag === 'LINK' && rel === 'preload';
    const isStylesheet = tag === 'LINK' && rel === 'stylesheet';
    const isBlockingCSS = isStylesheet && !el.media;
    const isAsyncScript = tag === 'SCRIPT' && (el.async || el.defer);
    const isBlockingScript = tag === 'SCRIPT' && el.src && !el.async && !el.defer && !el.type?.includes('module');
    const isInlineScript = tag === 'SCRIPT' && !el.src;
    const isModuleScript = tag === 'SCRIPT' && el.type?.includes('module');

    let category = 'other';
    let priority = 50;

    if (isCharset) { category = 'charset'; priority = 1; }
    else if (httpEquiv === 'x-ua-compatible') { category = 'x-ua-compatible'; priority = 2; }
    else if (isViewport) { category = 'viewport'; priority = 3; }
    else if (tag === 'TITLE') { category = 'title'; priority = 10; }
    else if (isPreconnect) { category = 'preconnect'; priority = 15; }
    else if (isDnsPrefetch) { category = 'dns-prefetch'; priority = 16; }
    else if (isPreload) { category = 'preload'; priority = 20; }
    else if (tag === 'STYLE') { category = 'inline-style'; priority = 25; }
    else if (isBlockingCSS) { category = 'blocking-css'; priority = 30; }
    else if (isStylesheet) { category = 'non-blocking-css'; priority = 35; }
    else if (isBlockingScript) { category = 'blocking-script'; priority = 40; }
    else if (isInlineScript) { category = 'inline-script'; priority = 45; }
    else if (isAsyncScript) { category = 'async-script'; priority = 50; }
    else if (isModuleScript) { category = 'module-script'; priority = 50; }
    else if (tag === 'LINK' && rel === 'prefetch') { category = 'prefetch'; priority = 60; }
    else if (tag === 'META') { category = 'meta'; priority = 55; }

    return {
      index: i, tag, category, priority,
      blocking: isBlockingCSS || isBlockingScript,
      src: (el.href || el.src || '').slice(0, 100) || null
    };
  });

  // Find out-of-order issues
  const issues = [];
  let lastBlockingIdx = -1;
  items.forEach((item, i) => {
    if (item.blocking) lastBlockingIdx = i;
  });

  // Check: preconnect/preload AFTER blocking resources = suboptimal
  items.forEach(item => {
    if ((item.category === 'preconnect' || item.category === 'preload') && item.index > lastBlockingIdx && lastBlockingIdx > -1) {
      // OK - preconnect/preload after blocking is fine if blocking is early
    }
    if ((item.category === 'preconnect' || item.category === 'preload') && lastBlockingIdx > -1) {
      const blockingBefore = items.filter(b => b.blocking && b.index < item.index);
      if (blockingBefore.length > 0) {
        issues.push({
          severity: 'warning',
          message: `${item.category} (${item.src}) at position ${item.index} is after ${blockingBefore.length} blocking resource(s). Move it earlier in <head>.`
        });
      }
    }
  });

  // Check: charset/viewport should be first
  const charsetItem = items.find(i => i.category === 'charset');
  const viewportItem = items.find(i => i.category === 'viewport');
  if (charsetItem && charsetItem.index > 3) {
    issues.push({ severity: 'error', message: `<meta charset> at position ${charsetItem.index} - should be in first 3 elements of <head>` });
  }
  if (viewportItem && viewportItem.index > 5) {
    issues.push({ severity: 'warning', message: `<meta name="viewport"> at position ${viewportItem.index} - should be early in <head>` });
  }

  // Check: blocking scripts in <head> before CSS
  const blockingScripts = items.filter(i => i.category === 'blocking-script');
  const firstCSS = items.find(i => i.category === 'blocking-css');
  blockingScripts.forEach(s => {
    if (firstCSS && s.index < firstCSS.index) {
      issues.push({
        severity: 'error',
        message: `Blocking <script> (${s.src}) at position ${s.index} is BEFORE first CSS at position ${firstCSS.index}. This blocks CSS download. Move script after CSS or add async/defer.`
      });
    }
  });

  return {
    script: 'Head-Order-Audit',
    totalElements: items.length,
    blocking: items.filter(i => i.blocking).length,
    order: items.filter(i => i.tag !== 'META' || i.category !== 'other'),
    issues
  };
})();
