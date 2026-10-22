// CommentPull Lab - background service worker.
// Lets commentpull.com start a collection run: opens the Facebook post in a tab, the content script
// collects, and when it finishes the tab is closed and the user is returned to commentpull.com.
const KEY  = 'cpFacebookTestResults';
const META = 'cpRunMeta';
const FB_URL  = /^https:\/\/(www\.|m\.|web\.)?facebook\.com\//i;
const SITE_URL = /^https:\/\/(www\.)?commentpull\.com\//i;

async function getResults() { return (await chrome.storage.local.get(KEY))[KEY] || {}; }
async function patchResults(patch) { await chrome.storage.local.set({[KEY]: {...(await getResults()), ...patch}}); }
async function getMeta() { return (await chrome.storage.local.get(META))[META] || null; }

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (!msg || !msg.type) return;

  if (msg.type === 'CP_SITE_START') {
    startRun(msg, sender).then(sendResponse, e => sendResponse({ok: false, error: String((e && e.message) || e)}));
    return true;
  }
  if (msg.type === 'CP_SITE_STOP') {
    (async () => {
      const meta = await getMeta();
      if (meta) { try { await chrome.tabs.sendMessage(meta.tabId, {type: 'CP_STOP'}); } catch (e) {} }
      sendResponse({ok: true});
    })();
    return true;
  }
  if (msg.type === 'CP_READY') {            // sent by the Facebook content script on every load
    (async () => {
      const meta = await getMeta();
      if (meta && sender.tab && meta.tabId === sender.tab.id && !meta.started) {
        await chrome.storage.local.set({[META]: {...meta, started: true}});
        sendResponse({start: true, max: meta.max, includeReplies: meta.includeReplies});
      } else sendResponse({start: false});
    })();
    return true;
  }
});

async function startRun(msg, sender) {
  if (!SITE_URL.test(sender.url || '')) throw new Error('Request not allowed from this page.');
  const url = String(msg.url || '').trim();
  if (!FB_URL.test(url)) throw new Error('Please use a facebook.com post URL.');

  await chrome.storage.local.set({[KEY]: {results: [], sourceUrl: url, capturedAt: new Date().toISOString(), status: 'starting', stats: {total: 0}}});
  const tab = await chrome.tabs.create({url, active: true});
  await chrome.storage.local.set({[META]: {
    tabId: tab.id, originTabId: sender.tab ? sender.tab.id : null,
    max: Math.max(0, parseInt(msg.max, 10) || 0), includeReplies: msg.includeReplies !== false, started: false
  }});
  return {ok: true};
}

// Finished (or stopped): close the Facebook tab and bring the user back to commentpull.com.
chrome.storage.onChanged.addListener(async (changes, area) => {
  if (area !== 'local' || !changes[KEY]) return;
  const st = changes[KEY].newValue && changes[KEY].newValue.status;
  if (st !== 'done' && st !== 'stopped') return;
  const meta = await getMeta();
  if (!meta) return;                         // manual popup runs have no META - leave the tab alone
  await chrome.storage.local.remove(META);
  try {
    if (meta.originTabId != null) {
      const t = await chrome.tabs.update(meta.originTabId, {active: true});
      if (t && t.windowId != null) chrome.windows.update(t.windowId, {focused: true});
    }
  } catch (e) {}
  try { await chrome.tabs.remove(meta.tabId); } catch (e) {}
});

// The user closed the Facebook tab early: keep what was collected and mark the run finished.
chrome.tabs.onRemoved.addListener(async tabId => {
  const meta = await getMeta();
  if (!meta || meta.tabId !== tabId) return;
  const cur = await getResults();
  if (cur.status === 'starting') await patchResults({status: 'error', error: 'The Facebook tab was closed before collecting started.'});
  else if (cur.status === 'running') await patchResults({status: 'stopped'});
  await chrome.storage.local.remove(META);
});
