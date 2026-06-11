/**
 * Jetonomy — runtime dark-mode mirror.
 *
 * Watches the active theme's dark-mode signal and toggles `.jt-dark` on
 * <body> so Jetonomy's dark tokens engage. Loaded only when Reign / BuddyX /
 * BuddyX Pro is active. Two generations of theme signal are supported:
 *
 * 1. Legacy class era (Reign ≤ 7.9.x, BuddyX ≤ 5.0.x, wp-dark-mode):
 *    a dark class on <html> or <body> ('dark-scheme' is the Wbcom
 *    convention; without it the whole app rendered light tokens on the
 *    theme's dark background and post titles were invisible).
 * 2. Color-mode era (BuddyX/BuddyX Pro 5.1.0+, Reign 8.0.0+):
 *    `<html data-bx-mode="light|dark|auto">`, server-rendered FOUC-safe
 *    and flipped at runtime by the theme's color-mode toggle. 'auto'
 *    follows the OS preference — same effective-dark rule the themes'
 *    own toggle uses for logo swapping.
 */
(function () {
	var html = document.documentElement;
	var body = document.body;
	if (!body) {
		return;
	}
	// Legacy dark-mode body/html classes (older theme versions + wp-dark-mode).
	var darkClasses = ['dark-scheme', 'wp-dark-mode-active', 'dark-mode', 'theme-dark', 'is-dark'];
	var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

	function attrDark() {
		var mode = html.getAttribute('data-bx-mode');
		if ('dark' === mode) {
			return true;
		}
		return 'auto' === mode && media && media.matches;
	}

	function sync() {
		var isDark = attrDark();
		for (var i = 0; !isDark && i < darkClasses.length; i++) {
			if (html.classList.contains(darkClasses[i])
				|| body.classList.contains(darkClasses[i])) {
				isDark = true;
			}
		}
		body.classList.toggle('jt-dark', !!isDark);
	}
	sync();
	if (typeof MutationObserver === 'function') {
		var opts = { attributes: true, attributeFilter: ['class', 'data-bx-mode'] };
		new MutationObserver(sync).observe(html, opts);
		new MutationObserver(sync).observe(body, opts);
	}
	// 'auto' mode flips with the OS preference without any DOM mutation.
	if (media) {
		if (typeof media.addEventListener === 'function') {
			media.addEventListener('change', sync);
		} else if (typeof media.addListener === 'function') {
			media.addListener(sync); // Safari < 14
		}
	}
})();
