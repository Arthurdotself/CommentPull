(() => {
  const allowedOrigin = 'https://commentpull.com';
  if (location.origin !== allowedOrigin) return;
  window.addEventListener('message', async event => {
    if (event.source !== window || event.origin !== allowedOrigin || !event.data || event.data.source !== 'commentpull-test-page') return;
    const type = event.data.type;
    if (!['PING', 'GET_RESULTS', 'CLEAR_RESULTS'].includes(type)) return;
    if (type === 'PING') {
      window.postMessage({source: 'commentpull-extension', ok: true}, allowedOrigin);
      return;
    }
    if (type === 'GET_RESULTS') {
      const data = await chrome.storage.local.get('cpFacebookTestResults');
      const saved = data.cpFacebookTestResults || {};
      window.postMessage({source: 'commentpull-extension', ok: true, results: Array.isArray(saved.results) ? saved.results : [], sourceUrl: saved.sourceUrl || '', capturedAt: saved.capturedAt || ''}, allowedOrigin);
      return;
    }
    await chrome.storage.local.remove('cpFacebookTestResults');
    window.postMessage({source: 'commentpull-extension', ok: true}, allowedOrigin);
  });
})();