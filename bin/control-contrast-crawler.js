// Jetonomy control-contrast crawler (dev tool, not shipped: bin/ is export-ignored).
// Run through the Playwright MCP: browser_run_code_unsafe with filename = this file.
// The file installs page.context().jtcCrawl; a second call runs it, logged in as
// an administrator, on a site seeded with bin/seed-demo-community.php,
// bin/seed-qa-content.php and bin/seed-qa-pages.php, for example:
//   async (page) => page.context().jtcCrawl(page, { set: 'front' })   // light+dark, 1280+390
//   async (page) => page.context().jtcCrawl(page, { set: 'admin' })
//   async (page) => page.context().jtcCrawl(page, { set: 'front', guest: true, urls: [...] })
// It navigates the page it is given, so pass page.context().newPage() when the
// browser is shared. opts.base sets the site origin (default: the page's own).
// URLs are this seed's slugs; edit FRONT / ADMIN for another site.
//
// For every visible input, select, textarea, button, .button, ARIA widget
// (role=button/tab/switch/menuitem/checkbox/radio/option) and every classed
// link inside Jetonomy markup (link-buttons, menu links, chips) it:
//  - composites the control's fill and every drawn boundary (border sides,
//    box-shadow rings, outline) over the real ancestor background stack,
//    with element opacity applied;
//  - non-text rule (WCAG 1.4.11): passes when the fill OR any boundary is
//    >= 3:1 against the surface. A control that draws an edge (border,
//    shadow ring, outline) where neither the edge nor the fill reaches 3:1
//    is a violation. A field (input/select/textarea) must reach 3:1 with its
//    own edge, its fill, or the edge of a wrapper that hugs it (search pill).
//    A link whose image fills it (a thumbnail) is identified by that image,
//    and a card link (title plus meta) by its text: a faint card frame is
//    listed under cards, not counted. A text link whose only edge is a single
//    border side (a footer link under a divider) is listed the same way, and
//    so is a core block's own field (theme form styles, not Jetonomy's); a
//    core block's button inside Jetonomy markup must still draw an edge.
//    A button with no edge is identified by its label: a text-only button
//    passes, and a button whose only boundary is a sub-3:1 fill (hover tint,
//    voted tint) is listed under fillOnly for review, not counted. Its text
//    contrast is still checked in every state.
//  - text rule (WCAG 1.4.3): every text run inside a button-like control,
//    composited over its own background, >= 4.5:1 (3:1 for large text).
//  - states: default, then :hover, :focus-visible and :active forced with
//    CDP CSS.forcePseudoState on every control at once. :disabled is read
//    from controls that are disabled in the DOM; WCAG exempts inactive
//    controls, so they are listed separately and not counted.
//  - for each violation, CDP CSS.getMatchedStylesForNode names the winning
//    rule (selector, stylesheet, declaration) for the failing property.
//  - a URL entry may be { path, name, open: [steps] }: after load it clicks
//    each step (a selector string, { type: [selector, text] }, { press: key }
//    or { js: selector } for a DOM click with navigation blocked) so modals,
//    menus and pickers are measured open.
//  - an open state whose step times out or finds nothing, and a page that
//    fails to load, is listed in `missed` and makes `ok` false: it was not
//    measured. opts.stepTimeout (default 10000 ms) sets the click wait; run
//    it on a quiet machine.
//  - opts.guest runs the set in a fresh logged-out browser context.
//  - opts.hostCss is extra CSS for every run, to stand in for a host setup,
//    e.g. ':root{--text-1:initial!important;--bg:initial!important;--brand:initial!important}'
//    drops BuddyNext's tokens so stock Reign 8.1.1 feeds --jt-text #404855.
//  - the result's `host` lists, per mode, the active theme stylesheet and the
//    colours Jetonomy resolved (text, surfaces, accent, edges), so any number
//    can be compared with another run.
//  - opts.darkHostCss is extra CSS for dark runs, to stand in for a host
//    theme's dark page on a theme with no dark mode of its own, e.g.
//    'body{background:#121212;color:#e5e5e5}' on Twenty Twenty-Five.
//    Add ':root{--jt-accent:#0073aa!important}' to it to test the default
//    accent on that dark page (a dark accent on a dark surface).
// Transitions and animations are switched off and every <details> is
// opened before measuring, so computed values are final.

async (page) => {
const FRONT = [
  '/community/', '/community/?spg=2', '/community/search/?q=dark', '/community/leaderboard/', '/community/mod/',
  '/community/my-spaces/', '/community/subscriptions/', '/community/drafts/', '/community/bookmarks/',
  '/community/new-space/', '/community/category/product-engineering/', '/community/category/jt-qa-chapters/',
  '/community/s/general-discussion/', '/community/s/general-discussion/members/',
  '/community/s/general-discussion/edit/', '/community/s/general-discussion/new/',
  '/community/s/general-discussion/mod/', '/community/s/feature-requests/', '/community/s/feature-requests/roadmap/',
  '/community/s/help-support/', '/community/s/show-and-tell/', '/community/s/founders-lounge/',
  '/community/s/feature-requests/t/dark-mode-toggle-in-user-preferences/',
  '/community/s/welcome/t/welcome-to-our-community-heres-how-it-works/',
  '/community/s/help-support/t/how-do-i-customize-the-notification-settings/',
  '/community/s/tips-best-practices/t/setting-up-your-community-for-the-first-100-members/',
  // Fixtures from bin/seed-qa-content.php (reactions, image) and bin/seed-qa-pages.php (blocks, shortcodes, widgets).
  '/community/s/jt-qa-qna/t/qa-qa-space-fixture-topic/',
  ...['block-compose-topic', 'block-login', 'block-user-panel', 'block-navigation', 'block-leaderboard', 'block-space-list', 'block-trending', 'block-forum-feed',
    'sc-compose-topic', 'sc-space-members', 'sc-user-profile', 'sc-leaderboard', 'sc-spaces', 'sc-trending-posts', 'sc-recent-posts',
    'widget-user-stats', 'widget-active-spaces', 'widget-leaderboard', 'widget-recent-posts'].map((s) => '/jt-qa-' + s + '/'),
];

// Open states: each loads the page, clicks the steps, then measures. `who`:
// member (default) runs logged in, guest only for a guest, any for both.
const TOPIC = '/community/s/feature-requests/t/dark-mode-toggle-in-user-preferences/';
const QA_TOPIC = '/community/s/jt-qa-qna/t/qa-qa-space-fixture-topic/';
const FRONT_OPEN = [
  { path: '/community/s/general-discussion/members/', name: 'ban', open: ['.jt-member-ban-btn'] },
  { path: TOPIC, name: 'report', open: ['button.jt-act[aria-label="Report"]'] },
  { path: TOPIC, name: 'share', who: 'any', open: ['.jt-share-btn'] },
  { path: TOPIC, name: 'more', open: ['.jt-more-trigger'] },
  { path: TOPIC, name: 'delete-confirm', open: ['.jt-more-trigger', '[data-wp-on--click="actions.deletePost"]'] },
  { path: TOPIC, name: 'move', open: ['.jt-more-trigger', '[data-wp-on--click="actions.movePost"]'] },
  { path: TOPIC, name: 'react-picker', open: ['.jt-reaction-trigger'] },
  { path: TOPIC, name: 'editor-emoji', open: ['[aria-label="Insert emoji"]'] },
  { path: TOPIC, name: 'mention', open: [{ type: ['.jt-editor-body', '@a'] }] },
  { path: QA_TOPIC, name: 'lightbox', who: 'any', open: ['.jt-attach-img'] },
  { path: '/community/s/general-discussion/edit/', name: 'space-delete', open: ['.jt-space-delete'] },
  // The bell shows at desktop width; the tab overflow menu only on a phone,
  // and only for a member (a guest's community nav has no overflow menu).
  // `absentOk`: Jetonomy's own community nav, which a host shell (BuddyNext)
  // replaces with its own; when the trigger is not in the page at all the
  // state is listed under `absent`, not measured and not a failure. A trigger
  // that is present but does not open still fails.
  { path: '/community/', name: 'notifications', width: 1280, absentOk: true, open: ['.jt-community-nav-notif'] },
  { path: '/community/', name: 'tab-menu', width: 390, absentOk: true, open: ['[data-jt-menu]'] },
  // With a host that owns sign-up (BuddyNext), Register is a link to its page, not a tab.
  { path: '/jt-qa-block-login/', name: 'register-tab', who: 'guest', absentOk: true, open: ['.jt-login-tab[data-jt-tab="register"]'] },
];

const ADMIN = [
  'jetonomy', 'jetonomy-categories', 'jetonomy-tags', 'jetonomy-spaces', 'jetonomy-content',
  'jetonomy-moderation', 'jetonomy-activity', 'jetonomy-revisions', 'jetonomy-users', 'jetonomy-import',
  'jetonomy-settings', 'jetonomy-extensions', 'jetonomy-community-media', 'jetonomy-setup',
  'jetonomy-pro-analytics', 'jetonomy-pro-badges', 'jetonomy-pro-custom-fields', 'jetonomy-pro-polls',
  ...['license', 'general', 'permissions', 'email', 'appearance', 'seo', 'antispam', 'integrations', 'ai', 'attachments', 'email-digest', 'reactions', 'reply-by-email', 'web-push', 'webhooks', 'branding'].map((t) => 'jetonomy-settings&tab=' + t),
  ...['posts', 'replies', 'flags', 'banned', 'auto-rules'].map((t) => 'jetonomy-moderation&tab=' + t),
  'jetonomy-spaces&action=edit&space_id=18', 'jetonomy-spaces&action=edit&space_id=7',
].map((p) => '/wp-admin/admin.php?page=' + p);
const ADMIN_OPEN = [
  // Row actions show on hover, so these click through the DOM ({ js }), with navigation blocked.
  { path: '/wp-admin/admin.php?page=jetonomy-users', name: 'ban-modal', open: [{ js: '.jetonomy-ban-trigger' }] },
  { path: '/wp-admin/admin.php?page=jetonomy-users', name: 'silence-modal', open: [{ js: '.jetonomy-silence-trigger' }] },
  // The shared confirm dialog every data-jt-confirm link opens, called directly (needs no row to exist).
  { path: '/wp-admin/admin.php?page=jetonomy-spaces', name: 'confirm', open: [{ run: 'window.jetonomyConfirm("Delete this item?", { danger: true })' }] },
];

const SITE_CHROME = '.bn-cookie-consent{display:none!important}';

// Runs inside the page. Returns one record per tagged control.
const IN_PAGE = (args) => {
  const { scope, tag } = args;
  const cv = document.createElement('canvas');
  cv.width = cv.height = 1;
  const cx = cv.getContext('2d', { willReadFrequently: true });
  const over = (base, color, alpha = 1) => {
    cx.globalAlpha = 1;
    cx.fillStyle = `rgb(${base[0]},${base[1]},${base[2]})`;
    cx.fillRect(0, 0, 1, 1);
    cx.fillStyle = 'rgba(0,0,0,0)';
    cx.fillStyle = color;
    cx.globalAlpha = alpha;
    cx.fillRect(0, 0, 1, 1);
    cx.globalAlpha = 1;
    const d = cx.getImageData(0, 0, 1, 1).data;
    return [d[0], d[1], d[2]];
  };
  const alphaOf = (color) => {
    cx.clearRect(0, 0, 1, 1);
    cx.fillStyle = 'rgba(0,0,0,0)';
    cx.fillStyle = color;
    cx.fillRect(0, 0, 1, 1);
    return cx.getImageData(0, 0, 1, 1).data[3] / 255;
  };
  const lum = (c) => {
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100; };
  const hex = (c) => '#' + c.map((v) => v.toString(16).padStart(2, '0')).join('');
  const opacityOf = (el) => { let o = 1; for (let n = el; n && n.nodeType === 1; n = n.parentElement) o *= parseFloat(getComputedStyle(n).opacity); return o; };
  // Background stack from <html> down to (and optionally including) el.
  const bgUnder = (el, includeSelf) => {
    const chain = [];
    for (let n = includeSelf ? el : el.parentElement; n && n.nodeType === 1; n = n.parentElement) chain.unshift(n);
    let c = [255, 255, 255];
    for (const n of chain) {
      const bg = getComputedStyle(n).backgroundColor;
      if (alphaOf(bg) > 0) c = over(c, bg, n === el ? 1 : 1);
    }
    return c;
  };
  const sel = (el) => {
    const cls = [...el.classList].filter((c) => !c.startsWith('data-')).slice(0, 4).map((c) => '.' + c).join('');
    const p = el.parentElement;
    const pc = p ? (p.id ? '#' + p.id : [...p.classList].slice(0, 2).map((c) => '.' + c).join('')) : '';
    const t = el.tagName.toLowerCase() + (el.type && el.tagName === 'INPUT' ? `[type=${el.type}]` : '') + (el.id ? '#' + el.id : '') + cls;
    return (pc ? pc + ' > ' : '') + t;
  };
  const label = (el) => (el.getAttribute('aria-label') || el.innerText || el.value || el.placeholder || '').trim().replace(/\s+/g, ' ').slice(0, 40);

  if (tag) {
    document.querySelectorAll('[data-jtc]').forEach((n) => n.removeAttribute('data-jtc'));
    const Q = 'input:not([type=hidden]), select, textarea, button, .button, [role=button], [role=tab], [role=switch], [role=menuitem], [role=menuitemradio], [role=menuitemcheckbox], [role=checkbox], [role=radio], [role=option], a[class*="btn"], a[class*="button"]' + (scope === 'admin' ? '' : ', a[class]');
    const inScope = (el) => {
      if (scope === 'admin') {
        const w = el.closest('#wpbody-content, .jt-setup-wrap'); // setup wizard renders its own shell
        if (!w || el.closest('#screen-meta, #screen-meta-links')) return false; // WP core screen chrome
        const notice = el.closest('.notice, .updated, .error, .update-nag');
        return !notice || /jt-|jetonomy/.test(notice.className);
      }
      for (let n = el; n && n !== document.body; n = n.parentElement) {
        if ([...n.classList].some((c) => c.startsWith('jt-') || c.startsWith('jetonomy') || c === 'jt-app')) return true;
      }
      return false;
    };
    let i = 0;
    for (const el of document.querySelectorAll(Q)) {
      if (!inScope(el)) continue;
      if (!el.checkVisibility({ opacityProperty: true, visibilityProperty: true })) continue;
      const r = el.getBoundingClientRect();
      if (r.width < 4 || r.height < 4) continue;
      if (r.right + scrollX <= 0 || r.bottom + scrollY <= 0) continue; // off-screen (spam honeypot): never seen
      const cs = getComputedStyle(el);
      if (el.tagName === 'INPUT' && /^(checkbox|radio)$/.test(el.type) && cs.appearance !== 'none') continue; // UA-drawn
      if (el.tagName === 'INPUT' && /^(range|color|file|image)$/.test(el.type)) continue;
      // Visually hidden pattern (clip / 1px).
      if (cs.clip === 'rect(0px, 0px, 0px, 0px)' || cs.clipPath === 'inset(50%)') continue;
      el.setAttribute('data-jtc', String(i++));
    }
    return i;
  }

  const out = [];
  for (const el of document.querySelectorAll('[data-jtc]')) {
    const cs = getComputedStyle(el);
    const op = opacityOf(el);
    const surf = bgUnder(el, false);
    const isField = /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) && !/^(button|submit|reset)$/.test(el.type || '');
    const fillA = alphaOf(cs.backgroundColor);
    const fill = fillA > 0 ? over(surf, cs.backgroundColor, op) : surf;
    const fillR = ratio(fill, surf);
    const parts = [];
    if (fillA > 0) parts.push({ k: 'fill', c: cs.backgroundColor, r: fillR });
    for (const s of ['top', 'right', 'bottom', 'left']) {
      const w = parseFloat(cs[`border-${s}-width`] || cs.getPropertyValue(`border-${s}-width`));
      const st = cs.getPropertyValue(`border-${s}-style`);
      const col = cs.getPropertyValue(`border-${s}-color`);
      if (w > 0 && st !== 'none' && st !== 'hidden' && alphaOf(col) > 0) {
        parts.push({ k: 'border-' + s, c: col, r: ratio(over(surf, col, op * alphaOf(col) / alphaOf(col)), surf), w });
      }
    }
    const bs = cs.boxShadow;
    if (bs && bs !== 'none') {
      // Split on commas that are not inside parentheses.
      const shadows = bs.split(/,(?![^(]*\))/);
      for (const sh of shadows) {
        const m = sh.match(/(rgba?\([^)]*\)|color\([^)]*\)|oklab\([^)]*\)|oklch\([^)]*\)|#[0-9a-f]+)/i);
        if (!m) continue;
        const nums = sh.replace(m[0], '').replace('inset', '').trim().split(/\s+/).map(parseFloat);
        const [x = 0, y = 0, blur = 0, spread = 0] = nums;
        if (spread > 0 || ((x || y) && blur === 0)) {
          const inset = /inset/.test(sh);
          const base = inset ? fill : surf;
          parts.push({ k: inset ? 'inset-shadow' : 'shadow-ring', c: m[0], r: ratio(over(base, m[0], op), inset ? fill : surf) });
        }
      }
    }
    const ow = parseFloat(cs.outlineWidth);
    if (ow > 0 && cs.outlineStyle !== 'none' && alphaOf(cs.outlineColor) > 0) {
      parts.push({ k: 'outline', c: cs.outlineColor, r: ratio(over(surf, cs.outlineColor, op), surf) });
    }
    // A field that draws nothing itself may sit in a wrapper that draws the
    // box (search pills). Use the nearest of two ancestors that has a border
    // and hugs the field.
    if (isField && parts.length === 0) {
      const fr = el.getBoundingClientRect();
      for (let a = el.parentElement, d = 0; a && d < 2; a = a.parentElement, d++) {
        const acs = getComputedStyle(a);
        const ar = a.getBoundingClientRect();
        if (ar.height > fr.height * 2 + 24) break;
        const asurf = bgUnder(a, false);
        for (const s of ['top', 'right', 'bottom', 'left']) {
          const w = parseFloat(acs.getPropertyValue(`border-${s}-width`));
          const col = acs.getPropertyValue(`border-${s}-color`);
          if (w > 0 && !/none|hidden/.test(acs.getPropertyValue(`border-${s}-style`)) && alphaOf(col) > 0) parts.push({ k: 'wrap-border-' + s, c: col, r: ratio(over(asurf, col, op), asurf) });
        }
        if (parts.length) break;
      }
    }
    const best = parts.reduce((m, p) => Math.max(m, p.r), 0);
    const drawsEdge = parts.some((p) => p.k !== 'fill');
    let nonText = 'pass';
    // An image link (thumbnail) is identified by the image it shows, not its frame.
    const img = el.querySelector('img');
    const ir = img && img.getBoundingClientRect(), er = el.getBoundingClientRect();
    const isImage = !!(ir && ir.width * ir.height >= 0.8 * er.width * er.height);
    if (parts.length === 0) nonText = isField ? 'fail-no-boundary' : 'text-only';
    else if (isImage) nonText = 'image';
    else if (best < 3) nonText = (drawsEdge || isField) ? 'fail' : 'fill-only';

    // Text runs.
    const texts = [];
    if (!isField) {
      const seen = new Set();
      const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      let n;
      while ((n = walker.nextNode())) {
        if (!n.textContent.trim()) continue;
        const pe = n.parentElement;
        if (!pe || seen.has(pe)) continue;
        if (!pe.checkVisibility({ opacityProperty: true, visibilityProperty: true })) continue;
        seen.add(pe);
        const pcs = getComputedStyle(pe);
        const bg = bgUnder(pe, true);
        const fg = over(bg, pcs.color, opacityOf(pe));
        const size = parseFloat(pcs.fontSize);
        const bold = parseInt(pcs.fontWeight, 10) >= 700;
        const large = size >= 24 || (bold && size >= 18.66);
        texts.push({ r: ratio(fg, bg), need: large ? 3 : 4.5, fg: hex(fg), bg: hex(bg), text: n.textContent.trim().slice(0, 30) });
      }
      if (el.tagName === 'INPUT' && el.value) {
        const fg = over(fill, cs.color, op);
        texts.push({ r: ratio(fg, fill), need: 4.5, fg: hex(fg), bg: hex(fill), text: el.value.slice(0, 30) });
      }
    }
    const worstText = texts.reduce((m, t) => (!m || t.r / t.need < m.r / m.need ? t : m), null);
    // A card link (title plus meta, 56px or taller) is identified by its text;
    // a faint card frame is listed under cards for review, not counted.
    if (nonText === 'fail' && el.tagName === 'A' && texts.length >= 2 && el.getBoundingClientRect().height >= 56) nonText = 'card';
    // A text link whose only edge is one border side (a "View all" footer under
    // a divider) is identified by its text; the rule is a divider, not its edge.
    const edges = parts.filter((p) => p.k !== 'fill');
    if (nonText === 'fail' && el.tagName === 'A' && texts.length && edges.length === 1 && /^border-/.test(edges[0].k)) nonText = 'divider';
    // A core block's own field (the Search block input in a Jetonomy sidebar)
    // is drawn by the theme's form styles and Jetonomy does not restyle it:
    // listed, not counted. Its BUTTON is different: Jetonomy's button reset
    // strips its fill, so Jetonomy owns its edge. A core-block button with no
    // edge and no fill fails, it is not passed as a text-only button.
    if (nonText === 'text-only' && el.tagName === 'BUTTON' && el.matches('.wp-element-button, [class*="wp-block-"]')) nonText = 'fail-no-boundary';
    if (nonText === 'fail' && isField && [...el.classList].some((c) => c.startsWith('wp-block-'))) nonText = 'host-field';
    out.push({
      i: +el.getAttribute('data-jtc'),
      sel: sel(el),
      label: label(el),
      disabled: el.disabled === true || el.getAttribute('aria-disabled') === 'true' || el.classList.contains('disabled'),
      surf: hex(surf),
      fill: hex(fill),
      parts: parts.map((p) => `${p.k}:${p.r}`).join(' '),
      best,
      nonText,
      text: worstText,
      textFail: !!(worstText && worstText.r < worstText.need),
    });
  }
  return out;
};

// Clicks each step; stops at the first one that has nothing visible to act on.
const runSteps = async (pg, steps, timeout) => {
  for (const s of steps) {
    const target = typeof s === 'string' ? s : (s.type ? s.type[0] : null);
    try {
      if (s.js) {
        // A DOM click on a delete link must never navigate: block it.
        await pg.route('**/*', (r) => (r.request().isNavigationRequest() ? r.abort() : r.continue()));
        if (!(await pg.evaluate((q) => { const el = document.querySelector(q); if (el) el.click(); return !!el; }, s.js))) return 'miss: ' + s.js;
      }
      if (target) await pg.locator(target + ' >> visible=true').first().click({ timeout });
      if (s.type) await pg.keyboard.type(s.type[1], { delay: 40 });
      if (s.press) await pg.keyboard.press(s.press);
      if (s.run) await pg.evaluate((code) => { (0, eval)(code); }, s.run);
      await pg.waitForTimeout(s.wait || 500);
    } catch (e) { return (/Timeout/.test(String(e)) ? 'timeout: ' : 'miss: ') + (target || s.press || s.js); }
  }
  return 'open';
};

const jtcCrawl = async (page0, opts = {}) => {
  const origin = new URL(page0.url()).origin;
  const BASE = opts.base || (origin !== 'null' ? origin : '');
  if (!BASE) throw new Error('jtcCrawl: pass opts.base, e.g. { base: "http://example.local" }');
  const set = opts.set || 'front';
  const forWho = (e) => typeof e === 'string' || (e.who || 'member') === 'any' || (e.who || 'member') === (opts.guest ? 'guest' : 'member');
  const urls = (opts.urls || (set === 'admin' ? [...ADMIN, ...ADMIN_OPEN] : [...FRONT, ...FRONT_OPEN])).filter(forWho);
  const guestCtx = opts.guest ? await page0.context().browser().newContext() : null;
  const page = guestCtx ? await guestCtx.newPage() : page0;
  const modes = opts.modes || (set === 'admin' ? ['light'] : ['light', 'dark']);
  const widths = opts.widths || [1280, 390];
  const states = opts.states || ['default', 'hover', 'focus-visible', 'active'];
  const cdp = await page.context().newCDPSession(page);
  const violations = [];
  const disabledInfo = [];
  const fillOnly = [];
  const cards = [];
  const pages = [];
  let controls = 0;
  const host = {};
  for (const width of widths) {
    await page.setViewportSize({ width, height: width > 800 ? 900 : 844 });
    for (const entry of urls) {
      if (entry.width && entry.width !== width) continue;
      const path = typeof entry === 'string' ? entry : entry.path + ' #' + entry.name;
      const url = typeof entry === 'string' ? entry : entry.path;
      for (const mode of modes) {
        let resp;
        await page.unrouteAll({ behavior: 'ignoreErrors' }).catch(() => {});
        try { resp = await page.goto(BASE + url, { waitUntil: 'load', timeout: 30000 }); } catch (e) { pages.push({ path, mode, width, error: String(e).slice(0, 80) }); continue; }
        const finalUrl = page.url();
        // SITE_CHROME hides the seed site's cookie banner: at 390 it covers the
        // menus a visitor opens after dismissing it.
        await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}' + SITE_CHROME + (opts.hostCss || '') + (mode === 'dark' && opts.darkHostCss ? opts.darkHostCss : '') });
        await page.evaluate((m) => {
          document.querySelectorAll('details').forEach((d) => { d.open = true; });
          if (m === 'dark') { document.documentElement.setAttribute('data-bx-mode', 'dark'); document.documentElement.setAttribute('data-theme', 'dark'); }
        }, mode);
        await page.waitForTimeout(150);
        if (!host[mode]) {
          host[mode] = await page.evaluate(() => {
            const el = document.querySelector('.jt-app') || document.body;
            const probe = document.createElement('i');
            el.appendChild(probe);
            const tok = {};
            for (const t of ['text', 'text-secondary', 'bg', 'bg-subtle', 'page-bg', 'accent', 'accent-ink', 'accent-fg', 'border-strong', 'focus-ring']) {
              probe.style.color = '';
              probe.style.color = 'var(--jt-' + t + ')';
              tok[t] = getComputedStyle(probe).color;
            }
            probe.remove();
            const sheet = [...document.querySelectorAll('link[rel=stylesheet]')].map((l) => l.href).find((h) => /\/themes\/[^/]+\//.test(h)) || '';
            // A block theme may load no stylesheet of its own; core's body class names it.
            const theme = (sheet.match(/\/themes\/([^/]+)\//) || [])[1] || ((document.body.className.match(/\bwp-theme-([\w-]+)/) || [])[1] || '');
            return { loggedIn: document.body.classList.contains('logged-in'), theme, themeVer: (sheet.match(/[?&]ver=([^&]+)/) || [])[1] || '', bodyBg: getComputedStyle(document.body).backgroundColor, tokens: tok, ua: navigator.userAgent.replace(/^.*(Chrome\/[\d.]+).*$/, '$1') };
          });
        }
        if (entry.absentOk && !(await page.locator(entry.open[0]).count())) { pages.push({ path, mode, width, absent: true }); continue; }
        const opened = typeof entry === 'string' ? '' : await runSteps(page, entry.open, opts.stepTimeout || 10000);
        const n = await page.evaluate(IN_PAGE, { scope: set, tag: true });
        controls += n;
        pages.push({ path, final: finalUrl.replace(BASE, ''), status: resp && resp.status(), mode, width, controls: n, opened });
        if (!n) continue;
        const sheets = {};
        const onSheet = (e) => { sheets[e.header.styleSheetId] = e.header.sourceURL || '(inline)'; };
        cdp.on('CSS.styleSheetAdded', onSheet);
        await cdp.send('DOM.enable');
        await cdp.send('CSS.enable');
        const { root } = await cdp.send('DOM.getDocument', { depth: -1 });
        const { nodeIds } = await cdp.send('DOM.querySelectorAll', { nodeId: root.nodeId, selector: '[data-jtc]' });
        // getDocument re-sends styleSheetAdded only on CSS.enable; give it a tick.
        await page.waitForTimeout(50);
        for (const state of states) {
          if (state !== 'default') await Promise.all(nodeIds.map((nodeId) => cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [state] })));
          const recs = await page.evaluate(IN_PAGE, { scope: set, tag: false });
          for (const r of recs) {
            if (r.disabled) {
              if (state === 'default') disabledInfo.push({ path, mode, width, sel: r.sel, label: r.label, nonText: r.nonText, best: r.best, text: r.text && r.text.r });
              continue;
            }
            if ((r.nonText === 'divider' || r.nonText === 'host-field') && state === 'default') cards.push(`${r.nonText}: ${mode} ${width} ${r.sel} "${r.label}" ${r.parts}`);
            if (r.nonText === 'card' && state === 'default') cards.push(`${mode} ${width} ${r.sel} "${r.label}" best ${r.best}`);
            if (r.nonText === 'fill-only') fillOnly.push({ sel: r.sel, label: r.label, state, mode, width, best: r.best, path });
            const bad = [];
            if (r.nonText === 'fail' || r.nonText === 'fail-no-boundary') bad.push('nonText');
            if (r.textFail) bad.push('text');
            if (!bad.length) continue;
            const v = { path, mode, width, state, sel: r.sel, label: r.label, kind: bad.join('+'), surf: r.surf, fill: r.fill, parts: r.parts, best: r.best, text: r.text, rule: [] };
            // Winning rule for the failing property.
            try {
              const ms = await cdp.send('CSS.getMatchedStylesForNode', { nodeId: nodeIds[r.i] });
              const want = bad.includes('text') && !bad.includes('nonText')
                ? ['color', 'background-color', 'background']
                : ['border-color', 'border', 'border-top-color', 'border-top', 'border-inline-start-color', 'border-width', 'border-style', 'background-color', 'background', 'box-shadow'];
              const hits = [];
              for (const m of ms.matchedCSSRules || []) {
                const props = (m.rule.style.cssProperties || []).filter((p) => want.includes(p.name) && p.value && !p.disabled && p.implicit !== true);
                if (!props.length || m.rule.origin !== 'regular') continue;
                hits.push({ sheet: (sheets[m.rule.styleSheetId] || m.rule.styleSheetId).replace(BASE, '').replace(/\?.*$/, ''), line: m.rule.style.range ? m.rule.style.range.startLine + 1 : null, selector: m.rule.selectorList.text.slice(0, 120), decl: [...new Set(props.map((p) => `${p.name}: ${p.value.replace(/\s*!important/, '')}${p.important ? ' !important' : ''}`))].join('; ').slice(0, 200), important: props.some((p) => p.important) });
              }
              // Cascade order: last wins, !important beats normal.
              const imp = hits.filter((h) => h.important);
              v.rule = (imp.length ? imp : hits).slice(-2);
            } catch (e) { v.rule = [{ error: String(e).slice(0, 60) }]; }
            violations.push(v);
          }
          if (state !== 'default') await Promise.all(nodeIds.map((nodeId) => cdp.send('CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] })));
        }
        cdp.off('CSS.styleSheetAdded', onSheet);
        await cdp.send('CSS.disable');
      }
    }
  }
  await cdp.detach();
  if (guestCtx) await guestCtx.close();
  // An open state that did not open, or a page that did not load, was not
  // measured: it fails the run instead of passing silently.
  const missed = pages.filter((p) => (p.opened && p.opened !== 'open') || p.error).map((p) => `${p.width} ${p.mode} ${p.path}: ${p.opened || p.error}`);
  // Collapse identical (selector, kind, state, mode) across pages/widths.
  const groups = {};
  for (const v of violations) {
    const key = [v.sel, v.kind, v.state, v.mode].join('|');
    if (!groups[key]) groups[key] = { ...v, pages: new Set(), widths: new Set() };
    groups[key].pages.add(v.path);
    groups[key].widths.add(v.width);
  }
  const table = Object.values(groups).map((g) => ({ sel: g.sel, label: g.label, kind: g.kind, state: g.state, mode: g.mode, widths: [...g.widths].join('/'), pages: [...g.pages].slice(0, 3).join(' ') + (g.pages.size > 3 ? ` (+${g.pages.size - 3})` : ''), surf: g.surf, fill: g.fill, parts: g.parts, text: g.text ? `${g.text.r} (${g.text.fg} on ${g.text.bg}) "${g.text.text}"` : '', rule: g.rule }));
  const fo = {};
  for (const f of fillOnly) { const k = f.sel + '|' + f.state + '|' + f.mode; if (!fo[k] || f.best < fo[k].best) fo[k] = f; }
  return { ok: violations.length === 0 && missed.length === 0, host, hostCss: opts.hostCss || '', darkHostCss: opts.darkHostCss || '', guest: !!opts.guest, cards: [...new Set(cards)], fillOnly: Object.values(fo).map((f) => `${f.state}/${f.mode} ${f.sel} "${f.label}" fill ${f.best}`), set, modes, widths, states, pagesLoaded: pages.length, opened: pages.filter((p) => p.opened === 'open').length, missed, absent: pages.filter((p) => p.absent).map((p) => `${p.width} ${p.mode} ${p.path}`), controlsMeasured: controls, violationCount: violations.length, uniqueViolations: table.length, table, disabled: opts.showDisabled ? disabledInfo : disabledInfo.length, pages: opts.showPages ? pages : undefined };
};

// Kept on the browser context so later calls can run it without re-sending this file.
page.context().jtcCrawl = jtcCrawl;
return 'jtcCrawl installed';
}
