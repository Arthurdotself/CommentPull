(() => {
  const clean = value => (value || '').replace(/\s+/g, ' ').trim();
  function collectVisibleComments() {
    const nodes = document.querySelectorAll('[data-ad-preview="message"], [data-testid="comment"]');
    const results = [];
    const seen = new Set();
    for (const node of nodes) {
      const comment = clean(node.innerText || node.textContent);
      if (!comment || comment.length > 5000) continue;
      const scope = node.closest('[role="article"]') || node.parentElement;
      const authorNode = scope && scope.querySelector('h2, h3, strong');
      const username = clean(authorNode && authorNode.innerText) || 'Unknown';
      const key = (username + '\n' + comment).toLowerCase();
      if (seen.has(key)) continue;
      seen.add(key);
      results.push({username, comment, timestamp: null});
    }
    return results;
  }
  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (!message || message.type !== 'CP_COLLECT_VISIBLE') return;
    const payload = {results: collectVisibleComments(), sourceUrl: location.href, capturedAt: new Date().toISOString()};
    chrome.storage.local.set({cpFacebookTestResults: payload}, () => sendResponse({ok: true, count: payload.results.length}));
    return true;
  });
})();