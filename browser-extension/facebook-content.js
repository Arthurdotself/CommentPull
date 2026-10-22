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
    return dialog || document.querySelector('[role="main"]') || document.body;
  }
  const label = el => el.getAttribute('aria-label') || '';

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

  function mergeComments(root) {
    let added = 0;
    const {list, post} = findComments(root);
    for (const a of list) {
      const e = extract(a, post);
      if (!e || (!state.replies && e.isReply)) continue;
      if (!state.store.has(e.key)) { state.store.set(e.key, e.rec); added++; }
    }
    return added;
  }

  // ---------- expanding + scrolling ----------
  function findExpandButtons(root) {
    const out = [];
    for (const el of root.querySelectorAll('[role="button"]')) {
      const t = clean(el.innerText || label(el));
      if (!t || t.length > 80 || !(EXPAND_RE.test(t) || SEE_MORE_RE.test(t))) continue;
      if (!state.replies && /repl(y|ies)|ردود|ردًا|(^|\s)رد(\s|$)/i.test(t)) continue;
      if (!isVisible(el) || Date.now() - Number(el.dataset.cpClicked || 0) < 8000) continue;
      out.push(el);
    }
    return out;
  }
  async function ensureAllComments(root) {
    const sortBtn = [...root.querySelectorAll('[role="button"]')].find(b => isVisible(b) && SORT_RE.test(clean(b.innerText)));
    if (!sortBtn) return;
    sortBtn.click(); await sleep(700);
    const item = [...document.querySelectorAll('[role="menuitem"], [role="menuitemradio"], [role="option"]')].find(i => ALL_COMMENTS_RE.test(clean(i.innerText)));
    if (item) { item.click(); await sleep(1200); } else { document.body.click(); }
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
    overlay('CommentPull Lab: starting…', true);
    try { await ensureAllComments(getRoot()); } catch (e) {}
    let idle = 0, last = -1;
    while (!state.stop && Date.now() - state.startedAt < MAX_RUN_MS) {
      let root = getRoot();
      mergeComments(root);
      if (max && state.store.size >= max) break;
      let clicked = 0;
      for (const b of findExpandButtons(root).slice(0, 8)) {
        if (state.stop) break;
        b.dataset.cpClicked = String(Date.now()); b.click(); clicked++;
        await sleep(jitter(250, 500));
      }
      scrollDown(root);
      await sleep(jitter(800, 1300));
      mergeComments(getRoot());
      overlay(`CommentPull Lab: ${state.store.size} comments…`, true);
      await persist(max);
      idle = (state.store.size === last && clicked === 0) ? idle + 1 : 0;
      last = state.store.size;
      if (idle >= 4) break;
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
      setTimeout(() => run(resp.max || 0, resp.includeReplies !== false), 2500);
    });
  } catch (e) {}
})();
