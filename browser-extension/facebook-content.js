// CommentPull Lab - lean Facebook comment collector (runs inside your own logged-in browser tab).
// Output per comment: { comment, profileUrl, timestampText }
(() => {
  if (window.__cpLabLoaded) return;
  window.__cpLabLoaded = true;

  const STORAGE_KEY = 'cpFacebookTestResults';
  const MAX_RUN_MS = 15 * 60 * 1000;
  const clean = v => (v || '').replace(/\s+/g, ' ').trim();
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const jitter = (a, b) => a + Math.random() * (b - a);
  const isVisible = el => !!el && el.getClientRects().length > 0;
  // Poll until cond() is truthy (or timeout) - replaces fixed sleeps so we move on the moment Facebook has rendered.
  const waitFor = async (cond, timeout, step = 80) => {
    const t0 = Date.now();
    while (Date.now() - t0 < timeout) { const v = cond(); if (v) return v; await sleep(step); }
    return cond();
  };
  // Speed knobs. Lower = faster, but too aggressive can make Facebook rate-limit your account.
  const CLICK_DELAY = [40, 120];   // ms between clicks on "View more comments / replies" buttons
  const MAX_CLICKS_PER_PASS = 25;  // expand buttons clicked per loop pass
  const SETTLE_MAX_MS = 1200;      // max wait for new comments to render after scrolling
  const IDLE_STOP_MS = 6000;       // finish after this long with no new comments AND no "view more" button left to try
  const EXPAND_RETRY_MS = 3000;    // wait before pressing the same "view more" button again
  const EXPAND_MAX_TRIES = 6;      // give up on a button that never loads anything after this many presses
  const PERSIST_EVERY_MS = 2000;   // throttle chrome.storage writes

  // aria-label prefixes Facebook puts on comment articles (EN, NL, FR, DE, ES, PT, IT, AR).
  const COMMENT_LABEL = /^(comment (by|from)|reply (by|from|to)|opmerking van|antwoord van|commentaire de|réponse de|kommentar von|antwort von|comentario de|respuesta de|comentário de|resposta de|commento di|risposta di|تعليق|رد\s)/i;
  const REPLY_LABEL = /^(reply|antwoord|réponse|antwort|respuesta|resposta|risposta|رد\s)/i;
  // Buttons that load more comments / replies.
  const EXPAND_RE = /(view|see|show)\s+(\d+\s+|all\s+|more\s+|previous\s+)*(comments?|repl(y|ies))|^\d+\s+repl(y|ies)$|more (comments|replies)|previous comments|(عرض|مشاهدة|إظهار).*(تعليق|تعليقات|رد|ردود|ردًا)|^\d+\s*(ردود|رد|ردًا)$|ver (más|\d+|todas).*(comentarios|respuestas)|voir.*(commentaires|réponses)|(weitere|\d+)\s*(kommentare|antworten)|bekijk.*(reacties|antwoorden)/i;
  const SEE_MORE_RE = /^(…\s*)?(see more|عرض المزيد|ver más|voir plus|mehr anzeigen|meer weergeven)$/i;
  const SORT_RE = /^(most relevant|top comments|relevant|الأكثر صلة|الأبرز)/i;
  const ALL_COMMENTS_RE = /^(all comments|جميع التعليقات|كل التعليقات)/i;
  // Relative-time text ("8 hours ago", "5h", "منذ 3 ساعات") - must never be mistaken for a name.
  const TIME_RE = /^(about\s+)?\d+\s*(seconds?|minutes?|hours?|days?|weeks?|months?|years?|mins?|hrs?|s|m|h|d|w|y|mo)\b(\s+ago)?$|^(just now|now|yesterday|edited)$|منذ|^[\d٠-٩]+\s*(ث|د|س|ي|أ|ش)$|ساعة|ساعات|دقيقة|دقائق|يوم|أيام|أسبوع|أسابيع|الآن/i;
  const TRAILING_TIME_RE = /\s+((?:about\s+)?\d+\s*(?:seconds?|minutes?|hours?|days?|weeks?|months?|years?|mins?|hrs?|s|m|h|d|w|y)(?:\s+ago)?|just now|yesterday|منذ\s.+)$/i;

  const state = {running: false, stop: false, status: 'idle', store: new Map(), startedAt: null, replies: true};

  // ---------- scope ----------
  function getRoot() {
    const dialog = [...document.querySelectorAll('[role="dialog"]')].find(d => d.querySelector('[role="article"]'));
    if (dialog) return dialog;
    const main = document.querySelector('[role="main"]');
    // Photo/video viewer pages: the media sits in [role="main"] but the comment panel is rendered OUTSIDE it
    // (side column), so only use main when it really contains comments; otherwise search the whole page.
    if (main && [...main.querySelectorAll('[role="article"]')].some(a => COMMENT_LABEL.test(label(a)))) return main;
    return document.body;
  }
  const label = el => el.getAttribute('aria-label') || '';

  // Single-photo / video / reel layouts often hide the comments until you press the Comment button or the "N comments" link.
  const COMMENT_COUNT_RE = /^[\d.,٠-٩]+\s*[KkMm]?\s*(comments?|تعليق(ات|ا)?|تعليقات|commentaires?|comentarios?|kommentare?|reacties|commenti)$/i;
  const COMMENT_BTN_RE = /^(leave a comment|write a comment|add a comment|view comments?|comments?|تعليق|تعليقات|اكتب تعليق(ًا|ا)|أضف تعليق(ًا|ا)|commenter|comentar|kommentieren|reageren|commenta)$/i;
  function findCommentOpener() {
    const ok = el => isVisible(el) && !el.closest('[role="article"][aria-label]') && !el.closest('form') && Date.now() - Number(el.dataset.cpClicked || 0) > 8000;
    const els = [...document.querySelectorAll('[role="button"], a')];
    const texts = el => [clean(el.getAttribute('aria-label')), clean(el.textContent)].filter(t => t && t.length < 40);
    return els.find(el => ok(el) && texts(el).some(t => COMMENT_COUNT_RE.test(t)))       // "12 comments" link first
        || els.find(el => ok(el) && texts(el).some(t => COMMENT_BTN_RE.test(t)))         // then the Comment button
        || null;
  }
  async function ensureCommentsVisible() {
    if (findComments(getRoot()).list.length) return;
    for (let i = 0; i < 3; i++) {
      const btn = findCommentOpener();
      if (!btn) return;
      btn.dataset.cpClicked = String(Date.now()); btn.click();
      if (await waitFor(() => findComments(getRoot()).list.length, 2500, 150)) return;
    }
  }

  function findComments(root) {
    const all = [...root.querySelectorAll('[role="article"]')];
    if (!all.length) return {post: null, list: [], tops: []};
    const tops = all.filter(a => !a.parentElement.closest('[role="article"]'));
    const post = tops.find(a => !COMMENT_LABEL.test(label(a))) || null;
    let list = all.filter(a => a !== post && ((post && post.contains(a)) || COMMENT_LABEL.test(label(a))));
    if (!list.length) list = tops.filter(a => a !== post);   // comments rendered as siblings of the post
    return {post, list, tops};
  }

  // ---------- field extraction (comment, profileUrl, timestampText only) ----------
  function profileLink(article) {
    // First link with real text that is not a timestamp (the author link often carries comment_id too).
    for (const l of article.querySelectorAll('a[href]')) {
      if (l.closest('[role="article"]') !== article) continue;
      const t = clean(l.innerText);
      if (!t || t.length > 100 || TIME_RE.test(t)) continue;
      if (/\/posts\/|\/videos\/|\/photo|\/permalink|story_fbid|\/reel\/|\/hashtag\//.test(l.getAttribute('href') || '')) continue;
      return l;
    }
    return null;
  }
  function cleanProfileUrl(href) {
    try {
      const u = new URL(href, location.href);
      if (u.pathname === '/profile.php') { const id = u.searchParams.get('id'); u.search = id ? '?id=' + id : ''; }
      else u.search = '';
      u.hash = '';
      return u.toString();
    } catch (e) { return ''; }
  }
  function commentText(article, author) {
    const cands = [...article.querySelectorAll('div[dir="auto"], span[dir="auto"]')].filter(n =>
      n.closest('[role="article"]') === article &&
      !n.closest('[role="button"]') &&
      !n.closest('a[href*="comment_id"]') &&
      !(author && (author.contains(n) || n.contains(author) || n.closest('a') === author)));
    const outer = cands.filter(n => !cands.some(o => o !== n && o.contains(n)));
    const styled = outer.filter(n => /text-align/.test(n.getAttribute('style') || ''));
    return clean((styled.length ? styled : outer).map(n => n.innerText).join('\n'));
  }

  function extract(article, post) {
    const author = profileLink(article);
    const text = commentText(article, author);
    if (!text) return null;
    const links = article.querySelectorAll('a[href*="comment_id"]');
    let timeLink = null;
    for (const l of links) { if (TIME_RE.test(clean(l.innerText))) { timeLink = l; break; } }
    const lblTime = (clean(label(article).replace(COMMENT_LABEL, '')).match(TRAILING_TIME_RE) || [])[1] || '';
    const profileUrl = author ? cleanProfileUrl(author.getAttribute('href')) : '';
    // internal de-duplication key only (not exported)
    let commentId = '';
    const pl = timeLink || links[0];
    if (pl) { try { const u = new URL(pl.getAttribute('href'), location.href); commentId = u.searchParams.get('reply_comment_id') || u.searchParams.get('comment_id') || ''; } catch (e) {} }
    const key = commentId || (profileUrl + '|' + text).toLowerCase();
    // cheap reply detection (no layout reads): reply label, or nested inside another comment instead of directly under the post
    const parent = article.parentElement.closest('[role="article"]');
    const isReply = REPLY_LABEL.test(label(article)) || !!(parent && parent !== post);
    return {key, isReply, rec: {username: clean(author && author.innerText), comment: text, profileUrl, timestampText: clean(timeLink && timeLink.innerText) || lblTime, isReply}};
  }

  // Articles already fully read are skipped on later passes, so each pass only touches NEW comments
  // (before, every pass re-read every comment on the page, which got slower as the list grew).
  let done = new WeakSet(), keyOf = new WeakMap();
  const hasSeeMore = a => [...a.querySelectorAll('[role="button"]')].some(b => b.closest('[role="article"]') === a && SEE_MORE_RE.test(clean(b.textContent)));
  function mergeComments(root) {
    let added = 0;
    const {list, post} = findComments(root);
    for (const a of list) {
      if (done.has(a)) continue;
      const e = extract(a, post);
      if (!e) continue;                                  // text not rendered yet - retry next pass
      if (!state.replies && e.isReply) { done.add(a); continue; }
      const old = keyOf.get(a);
      if (old && old !== e.key) state.store.delete(old); // "See more" expanded the text - replace the truncated copy
      keyOf.set(a, e.key);
      if (!state.store.has(e.key)) added++;
      state.store.set(e.key, e.rec);
      if (!hasSeeMore(a)) done.add(a);
    }
    return added;
  }

  // ---------- expanding + scrolling ----------
  function findExpandButtons(root, ignoreCooldown = false) {
    const out = [];
    for (const el of root.querySelectorAll('[role="button"]')) {
      const t = clean(el.textContent || label(el));   // textContent: no forced layout, much cheaper than innerText
      if (!t || t.length > 80 || !(EXPAND_RE.test(t) || SEE_MORE_RE.test(t))) continue;
      if (!state.replies && /repl(y|ies)|ردود|ردًا|(^|\s)رد(\s|$)/i.test(t)) continue;
      if (!isVisible(el) || Number(el.dataset.cpTries || 0) >= EXPAND_MAX_TRIES) continue;
      if (!ignoreCooldown && Date.now() - Number(el.dataset.cpClicked || 0) < EXPAND_RETRY_MS) continue;
      out.push(el);
    }
    return out;
  }
  async function ensureAllComments(root) {
    const sortBtn = [...root.querySelectorAll('[role="button"]')].find(b => isVisible(b) && SORT_RE.test(clean(b.innerText)));
    if (!sortBtn) return;
    sortBtn.click();
    const item = await waitFor(() => [...document.querySelectorAll('[role="menuitem"], [role="menuitemradio"], [role="option"]')].find(i => ALL_COMMENTS_RE.test(clean(i.innerText))), 1500, 60);
    if (item) { item.click(); await sleep(500); } else { document.body.click(); }
  }
  function getScroller(root) {
    let p = [...root.querySelectorAll('[role="article"]')].pop() || root;
    while (p && p !== document.body && p !== document.documentElement) {
      const s = getComputedStyle(p);
      if (/(auto|scroll)/.test(s.overflowY) && p.scrollHeight > p.clientHeight + 20) return p;
      p = p.parentElement;
    }
    return document.scrollingElement || document.documentElement;
  }
  function scrollDown(root) {
    const sc = getScroller(root);
    const last = [...root.querySelectorAll('[role="article"]')].pop();
    if (last) last.scrollIntoView({block: 'end'});
    sc.scrollTop = sc.scrollHeight;
  }

  // ---------- state / persistence / overlay ----------
  // The tab URL can be the home feed when the post is open in a pop-up, so use the post link seen in the comments.
  let postLink = '';
  function detectPostUrl(root) {
    if (postLink) return postLink;
    for (const a of root.querySelectorAll('a[href*="comment_id"]')) {
      try {
        const u = new URL(a.getAttribute('href'), location.href); u.search = ''; u.hash = '';
        if (/\/(posts|videos|permalink|reel|photo)/.test(u.pathname)) return (postLink = u.toString());
      } catch (e) {}
    }
    return location.href;
  }
  function persist(maxItems) {
    let results = [...state.store.values()];
    if (maxItems) results = results.slice(0, maxItems);
    return new Promise(res => chrome.storage.local.set({[STORAGE_KEY]: {
      results, sourceUrl: detectPostUrl(getRoot()), capturedAt: new Date().toISOString(), status: state.status, stats: {total: results.length}
    }}, res));
  }
  let ov = null;
  function overlay(text, showStop) {
    if (!ov) {
      const box = document.createElement('div');
      box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2147483647;background:#17191f;color:#f5f5f5;border:1px solid #ff4c60;border-radius:12px;padding:10px 14px;font:13px/1.4 system-ui,sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.4);max-width:280px';
      const t = document.createElement('div');
      const b = document.createElement('button');
      b.style.cssText = 'margin-top:8px;background:#ff4c60;color:#fff;border:0;border-radius:8px;padding:6px 12px;font-weight:600;cursor:pointer';
      box.append(t, b); document.body.appendChild(box); ov = {box, t, b};
    }
    ov.t.textContent = text;
    ov.b.textContent = showStop ? 'Stop & save' : 'Close';
    ov.b.onclick = () => { if (state.running) state.stop = true; else { ov.box.remove(); ov = null; } };
  }

  async function run(max, includeReplies) {
    if (state.running) return;
    Object.assign(state, {running: true, stop: false, status: 'running', store: new Map(), startedAt: Date.now(), replies: includeReplies !== false});
    postLink = '';
    done = new WeakSet(); keyOf = new WeakMap();
    overlay('CommentPull Lab: starting…', true);
    await waitFor(() => getRoot().querySelector('[role="article"]'), 4000, 100);
    await waitFor(() => findComments(getRoot()).list.length, 2500, 150);   // give the comment panel a moment to render
    try { await ensureCommentsVisible(); } catch (e) {}
    try { await ensureAllComments(getRoot()); } catch (e) {}
    const articleCount = () => getRoot().querySelectorAll('[role="article"]').length;
    let last = -1, lastChange = Date.now(), lastPersist = 0;
    while (!state.stop && Date.now() - state.startedAt < MAX_RUN_MS) {
      if (!state.store.size) { try { await ensureCommentsVisible(); } catch (e) {} }   // still nothing: try opening the comments again
      const root = getRoot();
      mergeComments(root);
      if (max && state.store.size >= max) break;
      const before = articleCount();
      let clicked = 0;
      for (const b of findExpandButtons(root).slice(0, MAX_CLICKS_PER_PASS)) {
        if (state.stop) break;
        try { b.scrollIntoView({block: 'center'}); } catch (e) {}   // Facebook only loads more when the button is on screen
        b.dataset.cpClicked = String(Date.now()); b.dataset.cpTries = String(Number(b.dataset.cpTries || 0) + 1); b.click(); clicked++;
        await sleep(jitter(CLICK_DELAY[0], CLICK_DELAY[1]));
      }
      scrollDown(root);
      // Wait only until Facebook actually renders something new, instead of a fixed ~1s sleep every pass.
      await sleep(200);
      await waitFor(() => articleCount() !== before, SETTLE_MAX_MS, 100);
      mergeComments(getRoot());
      overlay(`CommentPull Lab: ${state.store.size} comments…`, true);
      if (Date.now() - lastPersist > PERSIST_EVERY_MS) { lastPersist = Date.now(); persist(max); }
      if (state.store.size !== last || clicked > 0) lastChange = Date.now();
      last = state.store.size;
      // Never finish while a "view more" button is still on screen and has retries left (it may just be loading slowly).
      if (Date.now() - lastChange > IDLE_STOP_MS && !findExpandButtons(getRoot(), true).length) break;
    }
    state.status = state.stop ? 'stopped' : 'done';
    state.running = false;
    await persist(max);
    overlay(`CommentPull Lab ${state.status}: ${Math.min(state.store.size, max || Infinity)} comments. Import them on the test page.`, false);
  }

  // ---------- diagnostics (no comment text is included) ----------
  function debugReport() {
    const root = getRoot();
    const {post, list, tops} = findComments(root);
    return {
      url: location.href, rootTag: root.getAttribute('role') || root.tagName,
      articlesTotal: root.querySelectorAll('[role="article"]').length, topLevelArticles: tops.length,
      postFound: !!post, commentsFound: list.length,
      sample: list.slice(0, 15).map(a => {
        const author = profileLink(a);
        return {ariaLabel: label(a).slice(0, 60), author: clean(author && author.innerText).slice(0, 40), hasProfileUrl: !!author,
          textLength: commentText(a, author).length, hasTimeLink: !!a.querySelector('a[href*="comment_id"]')};
      }),
      expandButtons: findExpandButtons(root).slice(0, 15).map(b => clean(b.innerText || label(b))),
      scroller: (() => { const s = getScroller(root); return s.tagName + (s.getAttribute('role') ? '[' + s.getAttribute('role') + ']' : ''); })()
    };
  }

  chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (!msg || !msg.type) return;
    switch (msg.type) {
      case 'CP_START': run(Math.max(0, parseInt(msg.max, 10) || 0), msg.includeReplies !== false); sendResponse({ok: true}); return;
      case 'CP_STOP': state.stop = true; sendResponse({ok: true}); return;
      case 'CP_STATUS': sendResponse({ok: true, running: state.running, status: state.status, total: state.store.size}); return;
      case 'CP_DEBUG': sendResponse({ok: true, report: debugReport()}); return;
      case 'CP_COLLECT_VISIBLE':
        mergeComments(getRoot()); state.status = 'snapshot';
        persist().then(() => sendResponse({ok: true, count: state.store.size}));
        return true;
    }
  });

  // When commentpull.com started this run, the background worker tells this tab to begin.
  try {
    chrome.runtime.sendMessage({type: 'CP_READY'}, resp => {
      if (chrome.runtime.lastError || !resp || !resp.start) return;
      setTimeout(() => run(resp.max || 0, resp.includeReplies !== false), 300);
    });
  } catch (e) {}
})();
