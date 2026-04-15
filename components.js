/* ─── CommentPull Shared Components ───
 * Injects the navbar and footer into every page.
 *
 * Usage: add a placeholder div, then load this script BEFORE lang.js
 *
 *   Nav variants  : full | marketing | app | signin | signup
 *   Footer variants: extended | simple | none
 *
 *   <div id="site-nav"    data-variant="marketing"></div>
 *   <div id="site-footer" data-variant="simple"></div>
 *   <script src="/components.js"></script>
 *   <script src="/lang.js"></script>
 */

(function () {
  'use strict';

  /* ─────────────────────────────────────────────
   * NAV TEMPLATES
   * ───────────────────────────────────────────── */

  /* Used by: index.html
   * Links: How it Works | Pricing | Support | History | Scheduled
   * Auth : lang-toggle + static Sign In / Sign Up (replaced by page JS when signed in) */
  var NAV_FULL = `
<header class="fixed top-0 left-0 right-0 z-50 glass-panel border-b border-white/5">
  <div class="max-w-[1440px] mx-auto px-6 h-20 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <a href="/index.html" class="text-xl font-semibold tracking-tight text-white no-underline">commentpull</a>
    </div>
    <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-gray-300">
      <a href="/index.html#how-it-works" class="hover:text-white transition-colors" data-i18n="nav.how">How it Works</a>
      <a href="/pricing.html"            class="hover:text-white transition-colors" data-i18n="nav.pricing">Pricing</a>
      <a href="/support.html"            class="hover:text-white transition-colors" data-i18n="footer.support">Support</a>
      <a href="/history.html"            class="hover:text-white transition-colors" data-i18n="nav.history">History</a>
      <a href="/scheduled-exports.html"  class="hover:text-white transition-colors" data-i18n="nav.scheduled">Scheduled</a>
    </nav>
    <div class="flex items-center gap-3" id="nav-auth-area">
      <button id="lang-toggle" onclick="toggleLang()"
        class="text-sm text-gray-300 hover:text-white transition-colors px-3 py-2 rounded-lg border border-white/10 hover:border-white/20">
        العربية
      </button>
      <div class="flex items-center gap-3" id="nav-auth">
        <a href="/signin.html" id="nav-signin"
          class="text-sm text-gray-300 hover:text-white transition-colors px-4 py-2 rounded-full border border-white/10 hover:border-white/20 font-medium"
          data-i18n="nav.signin">Sign In</a>
        <a href="/signup.html" id="nav-signup"
          class="bg-white text-black px-5 py-2.5 rounded-full text-sm font-medium hover:bg-gray-100 transition-colors"
          data-i18n="nav.signup">Sign Up</a>
      </div>
    </div>
  </div>
</header>`;

  /* Used by: about, pricing, contact, support, privacy
   * Links: How it Works | Pricing | Support
   * Auth : lang-toggle + static Sign In / Sign Up */
  var NAV_MARKETING = `
<header class="fixed top-0 left-0 right-0 z-50 glass-panel border-b border-white/5">
  <div class="max-w-[1440px] mx-auto px-6 h-20 flex items-center justify-between">
    <a href="/index.html" class="text-xl font-semibold tracking-tight text-white no-underline">commentpull</a>
    <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-gray-300">
      <a href="/index.html#how-it-works" class="hover:text-white transition-colors" data-i18n="nav.how">How it Works</a>
      <a href="/pricing.html"            class="hover:text-white transition-colors" data-i18n="nav.pricing">Pricing</a>
      <a href="/support.html"            class="hover:text-white transition-colors" data-i18n="footer.support">Support</a>
    </nav>
    <div class="flex items-center gap-3">
      <button id="lang-toggle" onclick="toggleLang()"
        class="text-sm text-gray-300 hover:text-white transition-colors px-3 py-2 rounded-lg border border-white/10 hover:border-white/20">
        العربية
      </button>
      <a href="/signin.html"
        class="text-sm text-gray-300 hover:text-white transition-colors px-4 py-2 rounded-full border border-white/10 hover:border-white/20 font-medium"
        data-i18n="nav.signin">Sign In</a>
      <a href="/signup.html"
        class="bg-white text-black px-5 py-2.5 rounded-full text-sm font-medium hover:bg-gray-100 transition-colors"
        data-i18n="nav.signup">Sign Up</a>
    </div>
  </div>
</header>`;

  /* Used by: history, scheduled-exports, settings
   * Links: Tool | Pricing | Support | History | Scheduled
   * Auth : nav-auth div filled entirely by each page's own JS */
  var NAV_APP = `
<header class="fixed top-0 left-0 right-0 z-50 glass-panel border-b border-white/5">
  <div class="max-w-[1440px] mx-auto px-6 h-20 flex items-center justify-between">
    <a href="/" class="flex items-center gap-2 no-underline">
      <span class="text-xl font-semibold tracking-tight text-white">commentpull</span>
    </a>
    <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-gray-300">
      <a href="/"                        class="hover:text-white transition-colors" data-i18n="nav.tool">Tool</a>
      <a href="/pricing.html"            class="hover:text-white transition-colors" data-i18n="nav.pricing">Pricing</a>
      <a href="/support.html"            class="hover:text-white transition-colors" data-i18n="footer.support">Support</a>
      <a href="/history.html"            class="hover:text-white transition-colors" data-i18n="nav.history">History</a>
      <a href="/scheduled-exports.html"  class="hover:text-white transition-colors" data-i18n="nav.scheduled">Scheduled</a>
    </nav>
    <div class="flex items-center gap-3" id="nav-auth">
      <!-- filled by page JS -->
    </div>
  </div>
</header>`;

  /* Used by: signin.html */
  var NAV_SIGNIN = `
<header class="fixed top-0 left-0 right-0 z-50 glass-panel border-b border-white/5">
  <div class="max-w-[1440px] mx-auto px-6 h-16 flex items-center justify-between">
    <a href="/index.html" class="text-xl font-semibold tracking-tight text-white no-underline">commentpull</a>
    <div class="flex items-center gap-4 text-sm">
      <span class="text-gray-400" data-i18n="nav.signin.prompt">Don't have an account?</span>
      <a href="/signup.html" class="bg-white text-black px-4 py-2 rounded-full text-sm font-medium hover:bg-gray-100 transition-colors" data-i18n="nav.signup">Sign Up</a>
    </div>
  </div>
</header>`;

  /* Used by: signup.html */
  var NAV_SIGNUP = `
<header class="fixed top-0 left-0 right-0 z-50 glass-panel border-b border-white/5">
  <div class="max-w-[1440px] mx-auto px-6 h-16 flex items-center justify-between">
    <a href="/index.html" class="text-xl font-semibold tracking-tight text-white no-underline">commentpull</a>
    <div class="flex items-center gap-4 text-sm">
      <span class="text-gray-400" data-i18n="nav.signup.prompt">Already have an account?</span>
      <a href="/signin.html" class="border border-white/20 text-white px-4 py-2 rounded-full text-sm font-medium hover:border-white/40 transition-colors" data-i18n="nav.signin">Sign In</a>
    </div>
  </div>
</header>`;

  /* ─────────────────────────────────────────────
   * FOOTER TEMPLATES
   * ───────────────────────────────────────────── */

  /* Used by: index, pricing
   * 5-column grid: brand | Product | Platforms | Export Formats | Company */
  var FOOTER_EXTENDED = `
<footer class="bg-[#0F1115] border-t border-white/10 pt-16 pb-8">
  <div class="max-w-[1440px] mx-auto px-6">
    <div class="grid grid-cols-1 md:grid-cols-5 gap-12 mb-16">
      <div class="col-span-1 md:col-span-2">
        <div class="flex items-center gap-2 mb-6">
          <span class="text-xl font-semibold tracking-tight">commentpull</span>
        </div>
        <p class="text-gray-400 text-sm leading-relaxed mb-6">
          The fastest way to extract, analyze, and export social media comments for marketers and researchers.
        </p>
      </div>
      <div>
        <h4 class="text-white font-semibold mb-4" data-i18n="footer.product">Product</h4>
        <ul class="space-y-3 text-sm text-gray-400">
          <li><a href="/index.html#how-it-works" class="hover:text-white transition-colors" data-i18n="footer.how">How it Works</a></li>
          <li><a href="/index.html#preview"       class="hover:text-white transition-colors" data-i18n="footer.preview">Results Preview</a></li>
          <li><a href="/pricing.html"             class="hover:text-white transition-colors" data-i18n="nav.pricing">Pricing</a></li>
        </ul>
      </div>
      <div>
        <h4 class="text-white font-semibold mb-4" data-i18n="footer.platforms">Platforms</h4>
        <ul class="space-y-3 text-sm text-gray-400">
          <li class="flex items-center gap-2"><i class="fa-brands fa-facebook text-blue-400"></i> Facebook</li>
          <li class="flex items-center gap-2"><i class="fa-brands fa-instagram text-pink-400"></i> Instagram</li>
          <li class="flex items-center gap-2"><i class="fa-brands fa-youtube text-red-400"></i> YouTube</li>
        </ul>
      </div>
      <div>
        <h4 class="text-white font-semibold mb-4">Company</h4>
        <ul class="space-y-3 text-sm text-gray-400">
          <li><a href="/about.html"   class="hover:text-white transition-colors" data-i18n="footer.about">About</a></li>
          <li><a href="/support.html" class="hover:text-white transition-colors" data-i18n="footer.support">Support</a></li>
          <li><a href="/contact.html" class="hover:text-white transition-colors" data-i18n="footer.contact">Contact</a></li>
          <li><a href="/privacy.html" class="hover:text-white transition-colors" data-i18n="footer.privacy">Privacy Policy</a></li>
        </ul>
      </div>
    </div>
    <div class="border-t border-white/10 pt-8 flex flex-col md:flex-row justify-between items-center gap-4 text-sm text-gray-500">
      <p data-i18n="footer.index.copyright">&copy; 2026 commentpull. All rights reserved.</p>
      <div class="flex gap-6">
        <a href="/about.html"   class="hover:text-white transition-colors" data-i18n="footer.about">About</a>
        <a href="/privacy.html" class="hover:text-white transition-colors" data-i18n="footer.privacy">Privacy</a>
        <a href="/support.html" class="hover:text-white transition-colors" data-i18n="footer.support">Support</a>
        <a href="/contact.html" class="hover:text-white transition-colors" data-i18n="footer.contact">Contact</a>
      </div>
    </div>
  </div>
</footer>`;

  /* Used by: about, contact, support, privacy
   * 2-section: copyright row + links row */
  var FOOTER_SIMPLE = `
<footer class="bg-[#0F1115] border-t border-white/10 pt-16 pb-8">
  <div class="max-w-[1440px] mx-auto px-6">
    <div class="border-t border-white/10 pt-8 flex flex-col md:flex-row justify-between items-center gap-4 text-sm text-gray-500">
      <p data-i18n="footer.index.copyright">&copy; 2026 commentpull. All rights reserved.</p>
      <div class="flex gap-6">
        <a href="/about.html"   class="hover:text-white transition-colors" data-i18n="footer.about">About</a>
        <a href="/privacy.html" class="hover:text-white transition-colors" data-i18n="footer.privacy">Privacy Policy</a>
        <a href="/support.html" class="hover:text-white transition-colors" data-i18n="footer.support">Support</a>
        <a href="/contact.html" class="hover:text-white transition-colors" data-i18n="footer.contact">Contact</a>
      </div>
    </div>
  </div>
</footer>`;

  /* ─────────────────────────────────────────────
   * ACTIVE LINK HIGHLIGHTING
   * Reads the current filename and adds active
   * classes to the matching nav link.
   * ───────────────────────────────────────────── */
  function highlightActive(container) {
    var path = window.location.pathname;
    // Strip /ar/ prefix for matching
    var normalised = path.replace(/^\/ar\//, '/').replace(/^\/ar$/, '/');
    var page = normalised.split('/').pop() || 'index.html';

    container.querySelectorAll('nav a').forEach(function (a) {
      var href = a.getAttribute('href') || '';
      var hPage = href.split('#')[0].split('/').pop() || 'index.html';
      if (hPage && hPage === page) {
        a.classList.remove('hover:text-white', 'text-gray-300');
        a.classList.add('text-indigo-400', 'font-semibold');
      }
    });
  }

  /* ─────────────────────────────────────────────
   * INJECTION
   * ───────────────────────────────────────────── */
  function inject() {
    var navEl = document.getElementById('site-nav');
    if (navEl) {
      var navVariant = navEl.getAttribute('data-variant');
      var navHtml = {
        'full':      NAV_FULL,
        'marketing': NAV_MARKETING,
        'app':       NAV_APP,
        'signin':    NAV_SIGNIN,
        'signup':    NAV_SIGNUP
      }[navVariant];

      if (navHtml) {
        navEl.outerHTML = navHtml;
        // Re-query the injected header for active highlighting
        var injectedHeader = document.querySelector('header');
        if (injectedHeader) highlightActive(injectedHeader);
      }
    }

    var footerEl = document.getElementById('site-footer');
    if (footerEl) {
      var footerVariant = footerEl.getAttribute('data-variant');
      var footerHtml = {
        'extended': FOOTER_EXTENDED,
        'simple':   FOOTER_SIMPLE
      }[footerVariant];

      if (footerHtml) {
        footerEl.outerHTML = footerHtml;
      }
      // 'none' variant: element stays in DOM as empty div — harmless
    }
  }

  /* Run now if DOM is ready, otherwise wait for it */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }

})();
