const statusEl = document.getElementById('status');
document.getElementById('collect').addEventListener('click', async () => {
  const [tab] = await chrome.tabs.query({active: true, currentWindow: true});
  if (!tab || !/^https:\/\/(www\.|m\.)?facebook\.com\//i.test(tab.url || '')) {
    statusEl.textContent = 'Open a Facebook post in this tab first.';
    return;
  }
  try {
    const response = await chrome.tabs.sendMessage(tab.id, {type: 'CP_COLLECT_VISIBLE'});
    statusEl.textContent = response && response.ok ? `Captured ${response.count} visible comment(s). Import them on the test page.` : 'Could not read this page. Refresh Facebook and try again.';
  } catch (error) {
    statusEl.textContent = 'Refresh the Facebook post and try again.';
  }
});
document.getElementById('open').addEventListener('click', () => chrome.tabs.create({url: 'https://commentpull.com/fb-extension-test.html'}));