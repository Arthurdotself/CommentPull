#!/usr/bin/env node
/**
 * build.js — generates /ar/ HTML pages from root source files.
 *
 * Usage:   node build.js
 * Output:  ar/about.html, ar/contact.html, ar/support.html, ar/privacy.html
 *
 * No npm install required — uses Node.js built-ins only.
 */

'use strict';

const fs   = require('fs');
const path = require('path');
const ROOT = __dirname;

/* ── 1. Extract T object from lang.js ───────────────────────────────────────
   We read lang.js and pull out the `const T = { ... };` block, then evaluate
   it with new Function so we never execute the IIFE or touch the DOM.         */
const langSrc = fs.readFileSync(path.join(ROOT, 'lang.js'), 'utf8');
const tMatch  = langSrc.match(/const T = (\{[\s\S]*?\n  \});/);
if (!tMatch) { console.error('ERROR: Could not find T object in lang.js'); process.exit(1); }
const T = new Function('return ' + tMatch[1])();

/* ── 2. Page metadata (title / description / canonical) ─────────────────── */
const META = {
  'about.html':             { title: { ar: 'حول — commentpull' },                 desc: { ar: 'تعرف على commentpull — الأسرع في استخراج وتحليل وتصدير تعليقات وسائل التواصل الاجتماعي.' } },
  'contact.html':           { title: { ar: 'تواصل معنا — commentpull' },          desc: { ar: 'تواصل مع فريق commentpull. نرد خلال يوم عمل واحد.' } },
  'support.html':           { title: { ar: 'الدعم — commentpull' },               desc: { ar: 'احصل على المساعدة مع commentpull. اعثر على إجابات للأسئلة الشائعة.' } },
  'privacy.html':           { title: { ar: 'سياسة الخصوصية — commentpull' },     desc: { ar: 'سياسة خصوصية commentpull — كيف نجمع بياناتك ونستخدمها ونحميها.' } },
  'index.html':             { title: { ar: 'commentpull - استخراج تعليقات وسائل التواصل الاجتماعي' }, desc: { ar: 'استخرج وصفّ وصدّر التعليقات من فيسبوك وإنستغرام ويوتيوب بنقرة واحدة.' } },
  'history.html':           { title: { ar: 'سجل التشغيل — commentpull' },        desc: { ar: '' } },
  'scheduled-exports.html': { title: { ar: 'الصادرات المجدولة — commentpull' }, desc: { ar: '' } },
  'pricing.html':           { title: { ar: 'الأسعار — commentpull' },            desc: { ar: 'خطط commentpull — مجانية وبرو ومؤسسات لاستخراج تعليقات وسائل التواصل الاجتماعي.' } },
  'data-deletion.html':     { title: { ar: 'حذف البيانات — commentpull' },       desc: { ar: 'طلب حذف حسابك في commentpull وجميع البيانات المرتبطة به.' } },
  'terms.html':             { title: { ar: 'شروط الخدمة — commentpull' },        desc: { ar: 'شروط خدمة commentpull — القواعد التي تحكم استخدامك للمنصة.' } },
  'guide.html':             { title: { ar: 'كيفية الاستخدام — commentpull' },       desc: { ar: 'دليل خطوة بخطوة لاستخدام commentpull وتثبيت إضافة المتصفح مفتوحة المصدر. مرحلة تجريبية.' } },
};

/* Pages that have /ar/ versions — used to rewrite internal href links */
const AR_PAGES = new Set(['index.html', 'about.html', 'contact.html', 'support.html', 'privacy.html', 'history.html', 'scheduled-exports.html', 'pricing.html', 'data-deletion.html', 'terms.html', 'guide.html']);

/* Pages to generate */
const PAGES = ['index.html', 'about.html', 'contact.html', 'support.html', 'privacy.html', 'history.html', 'scheduled-exports.html', 'pricing.html', 'data-deletion.html', 'terms.html', 'guide.html'];

/* ── 3. Core transformation ─────────────────────────────────────────────── */
function generateAr(filename) {
  const srcPath = path.join(ROOT, filename);
  if (!fs.existsSync(srcPath)) {
    console.warn(`SKIP: ${filename} not found`);
    return;
  }

  let html = fs.readFileSync(srcPath, 'utf8');
  const meta = META[filename] || {};

  /* 3a. Remove source-file comment at top (we'll add generated comment) */
  html = html.replace(/^<!-- SOURCE FILE:[^\n]*-->\n/, '');

  /* 3b. Set html lang / dir */
  html = html.replace(/<html lang="en" dir="ltr"/, '<html lang="ar" dir="rtl"');

  /* 3c. Add lang-ar class to body (prepend to existing class) */
  html = html.replace(/<body class="(antialiased)/, '<body class="lang-ar antialiased');

  /* 3d. Update <title> */
  if (meta.title && meta.title.ar) {
    html = html.replace(/<title>[^<]*<\/title>/, `<title>${meta.title.ar}</title>`);
  }

  /* 3e. Update meta description */
  if (meta.desc && meta.desc.ar) {
    html = html.replace(/(<meta name="description" content=")[^"]*"/, `$1${meta.desc.ar}"`);
  }

  /* 3e2. Localize Open Graph and Twitter Card tags for Arabic */
  // og:locale → ar_AR
  html = html.replace(/(<meta property="og:locale" content=")[^"]*"/, '$1ar_AR"');
  // og:url and twitter:url → insert /ar/
  html = html.replace(
    /(<meta property="og:url" content="https:\/\/commentpull\.com\/)([^"]*")/,
    (m, prefix, rest) => `${prefix}ar/${rest}`
  );
  html = html.replace(
    /(<meta name="twitter:url" content="https:\/\/commentpull\.com\/)([^"]*")/,
    (m, prefix, rest) => `${prefix}ar/${rest}`
  );
  // og:title and twitter:title
  if (meta.title && meta.title.ar) {
    html = html.replace(/(<meta property="og:title" content=")[^"]*"/, `$1${meta.title.ar}"`);
    html = html.replace(/(<meta name="twitter:title" content=")[^"]*"/, `$1${meta.title.ar}"`);
  }
  // og:description and twitter:description
  if (meta.desc && meta.desc.ar) {
    html = html.replace(/(<meta property="og:description" content=")[^"]*"/, `$1${meta.desc.ar}"`);
    html = html.replace(/(<meta name="twitter:description" content=")[^"]*"/, `$1${meta.desc.ar}"`);
  }
  // JSON-LD url field → insert /ar/
  html = html.replace(
    /("url":\s*"https:\/\/commentpull\.com\/)([^"]*")/g,
    (m, prefix, rest) => {
      if (rest.startsWith('ar/')) return m;
      return `${prefix}ar/${rest}`;
    }
  );

  /* 3f. Update canonical URL (insert /ar/ before the filename) */
  html = html.replace(
    /(<link rel="canonical" href="https:\/\/commentpull\.com\/)([^"]+)"/,
    (m, prefix, rest) => {
      if (rest.startsWith('ar/')) return m; // already AR
      return `${prefix}ar/${rest}"`;
    }
  );

  /* 3g. Replace translations line by line */
  const lines = html.split('\n');
  const out   = lines.map(line => replaceLine(line));
  html = out.join('\n');

  /* 3h. Replace data-i18n-placeholder attributes */
  html = html.replace(
    /( data-i18n-placeholder="([^"]+)"[^>]*placeholder=")[^"]*"/g,
    (m, prefix, key) => {
      const val = T.ar[key];
      return val !== undefined ? `${prefix}${val}"` : m;
    }
  );
  /* Also handle placeholder BEFORE data-i18n-placeholder (attribute order varies) */
  html = html.replace(
    /(placeholder=")[^"]*("[^>]*data-i18n-placeholder="([^"]+)")/g,
    (m, pStart, pEnd, key) => {
      const val = T.ar[key];
      return val !== undefined ? `${pStart}${val}${pEnd}` : m;
    }
  );

  /* 3i. Rewrite internal href links for pages that have AR versions */
  html = html.replace(/href="([a-z][\w-]*\.html[^"]*)"/g, (m, href) => {
    const base = href.split('#')[0].split('?')[0];
    if (AR_PAGES.has(base)) {
      return `href="/ar/${href}"`;
    }
    return m;
  });

  /* 3j. Fix href="#..." anchor links (contact page local anchors stay as-is) */
  /* already fine — the regex above only matches file.html hrefs */

  /* 3k. Prepend generated-file warning */
  html = '<!-- AUTO-GENERATED — do not edit. Edit root source files and run: node build.js -->\n' + html;

  const destDir  = path.join(ROOT, 'ar');
  const destPath = path.join(destDir, filename);
  if (!fs.existsSync(destDir)) fs.mkdirSync(destDir, { recursive: true });
  fs.writeFileSync(destPath, html, 'utf8');
  console.log(`✓  ar/${filename}`);
}

/* ── 4. Line-level translation replacement ──────────────────────────────── */
function replaceLine(line) {
  /* 4a. data-i18n-html="KEY" — element must be on a single line.
         Uses a backreference (\2) so </tagname> matches the opening tag only. */
  const htmlLine = line.replace(
    /(<(\w+)[^>]*\sdata-i18n-html="([^"]+)"[^>]*>)([\s\S]*?)(<\/\2>)(.*)/,
    (m, open, _tag, key, _content, close, trailing) => {
      const val = T.ar[key];
      return val !== undefined ? `${open}${val}${close}${trailing}` : m;
    }
  );
  if (htmlLine !== line) return htmlLine; // replaced — return early

  /* 4b. data-i18n="KEY" — replace text content (no nested tags in content) */
  return line.replace(
    /(\sdata-i18n="([^"]+)"[^>]*)>([^<]*)</g,
    (m, attrBlock, key) => {
      const val = T.ar[key];
      return val !== undefined ? `${attrBlock}>${val}<` : m;
    }
  );
}

/* ── 5. Run ─────────────────────────────────────────────────────────────── */
console.log('Building /ar/ pages…\n');
PAGES.forEach(generateAr);
console.log('\nDone. Run `node build.js` again after editing any root source file.');
