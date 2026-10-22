const statusEl = document.getElementById('status');
const debugEl = document.getElementById('debug');
let pollTimer = null;

async function fbTab() {
  const [tab] = await chrome.tabs.query({active: true, currentWindow: true});
  if (!tab || !/^https:\/\/(www\.|m\.)?facebook\.com\//i.test(tab.url || '')) {
    statusEl.textContent = 'Open a Facebook post in this tab first.';
    return null;
  }
  return tab;
}
async function send(type, extra = {}) {
  const tab = await fbTab();
  if (!tab) return null;
  try { return await chrome.tabs.sendMessage(tab.id, {type, ...extra}); }
  catch (e) { statusEl.textContent = 'Refresh the Facebook post and try again.'; return null; }
}
function render(r) {
  if (!r || !r.ok) return;
  const line = `${r.total} comments`;
  statusEl.textContent = r.running ? 'Collecting… ' + line
    : r.status === 'done' || r.status === 'stopped' ? `Finished (${r.status}): ${line}. Import on the guide page.`
    : r.status === 'snapshot' ? `Snapshot saved: ${line}.` : 'Ready.';
}
async function poll() {
  const r = await send('CP_STATUS');
  render(r);
  if (!r || !r.running) { clearInterval(pollTimer); pollTimer = null; }
}
function startPolling() { if (!pollTimer) pollTimer = setInterval(poll, 1000); }

document.getElementById('start').addEventListener('click', async () => {
  const max = parseInt(document.getElementById('max').value, 10) || 0;
  const r = await send('CP_START', {max});
  if (r && r.ok) { statusEl.textContent = 'Started. You can close this popup; progress shows on the page.'; startPolling(); }
});
document.getElementById('stop').addEventListener('click', async () => { await send('CP_STOP'); statusEl.textContent = 'Stopping and saving…'; });
document.getElementById('collect').addEventListener('click', async () => {
  const r = await send('CP_COLLECT_VISIBLE');
  if (r && r.ok) statusEl.textContent = `Captured ${r.count} visible comment(s). Import them on the guide page.`;
});
document.getElementById('debug-btn').addEventListener('click', async () => {
  const r = await send('CP_DEBUG');
  if (!r || !r.report) return;
  const json = JSON.stringify(r.report, null, 2);
  debugEl.style.display = 'block'; debugEl.value = json;
  try { await navigator.clipboard.writeText(json); statusEl.textContent = 'Debug info copied to clipboard.'; }
  catch (e) { statusEl.textContent = 'Debug info shown below; copy it manually.'; }
});
document.getElementById('open').addEventListener('click', () => chrome.tabs.create({url: 'https://commentpull.com/guide.html'}));

// Resume showing progress if a run is already in progress when the popup opens.
(async () => { const r = await send('CP_STATUS'); if (r && r.ok) { render(r); if (r.running) startPolling(); } })();
