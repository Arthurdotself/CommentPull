// Bridge between commentpull.com pages (main site + test lab) and the extension.
(() => {
  const ALLOWED = ['https://commentpull.com', 'https://www.commentpull.com'];
  if (!ALLOWED.includes(location.origin)) return;
  const SOURCES = ['commentpull-test-page', 'commentpull-site'];
  const KEY = 'cpFacebookTestResults';
  const reply = (id, data) => window.postMessage({source: 'commentpull-extension', id, ...data}, location.origin);

  window.addEventListener('message', async event => {
    const d = event.data;
    if (event.source !== window || event.origin !== location.origin || !d || !SOURCES.includes(d.source)) return;
    const id = d.id;
    try {
      switch (d.type) {
        case 'PING':
          reply(id, {ok: true, version: chrome.runtime.getManifest().version, siteRun: true});
          return;
        case 'GET_RESULTS': {
          const saved = (await chrome.storage.local.get(KEY))[KEY] || {};
          reply(id, {
            ok: true, results: Array.isArray(saved.results) ? saved.results : [],
            sourceUrl: saved.sourceUrl || '', capturedAt: saved.capturedAt || '',
            status: saved.status || '', error: saved.error || ''
          });
          return;
        }
        case 'CLEAR_RESULTS':
          await chrome.storage.local.remove(KEY);
          reply(id, {ok: true});
          return;
        case 'START_COLLECT': {
          const r = await chrome.runtime.sendMessage({type: 'CP_SITE_START', url: d.url, max: d.max, includeReplies: d.includeReplies});
          reply(id, r || {ok: false, error: 'No response from the extension.'});
          return;
        }
        case 'STOP_COLLECT': {
          const r = await chrome.runtime.sendMessage({type: 'CP_SITE_STOP'});
          reply(id, r || {ok: true});
          return;
        }
      }
    } catch (e) {
      reply(id, {ok: false, error: String((e && e.message) || e)});
    }
  });
})();
