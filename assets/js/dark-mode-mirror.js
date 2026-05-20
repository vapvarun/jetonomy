/**
 * Jetonomy — runtime dark-mode mirror.
 *
 * Watches <html> and <body> class lists for the dark-mode classes emitted by
 * Reign / BuddyX / wp-dark-mode at runtime and toggles `.jt-dark` on <body>
 * so Jetonomy's dark tokens engage. Loaded only when those themes are active.
 */
(function () {
	var html = document.documentElement;
	var body = document.body;
	if (!body) {
		return;
	}
	// Dark-mode body/html classes emitted by the themes Jetonomy mirrors.
	// 'dark-scheme' is the Wbcom convention used by Reign AND BuddyX (without it
	// the whole app rendered light tokens on the theme's dark background — the
	// post title came out near-black-on-dark and was invisible).
	var darkClasses = ['dark-scheme', 'wp-dark-mode-active', 'dark-mode', 'theme-dark', 'is-dark'];
	function sync() {
		var isDark = false;
		for (var i = 0; i < darkClasses.length; i++) {
			if (html.classList.contains(darkClasses[i])
				|| body.classList.contains(darkClasses[i])) {
				isDark = true;
				break;
			}
		}
		body.classList.toggle('jt-dark', isDark);
	}
	sync();
	if (typeof MutationObserver === 'function') {
		var opts = { attributes: true, attributeFilter: ['class'] };
		new MutationObserver(sync).observe(html, opts);
		new MutationObserver(sync).observe(body, opts);
	}
})();
