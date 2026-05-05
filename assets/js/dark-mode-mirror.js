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
	var darkClasses = ['wp-dark-mode-active', 'dark-mode', 'theme-dark'];
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
