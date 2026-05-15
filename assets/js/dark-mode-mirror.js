/**
 * Jetonomy — runtime dark-mode mirror.
 *
 * Watches <html> and <body> class lists / data-theme attribute for the
 * dark-mode signals emitted by Reign / BuddyX / wp-dark-mode and toggles
 * `.jt-dark` on <body> so Jetonomy's dark tokens engage. Loaded only when
 * those themes are active.
 */
(function () {
	var html = document.documentElement;
	var body = document.body;
	if (!body) {
		return;
	}
	// Each theme advertises dark mode differently. Keep this list aligned
	// with what the themes actually emit — verify by inspecting body class
	// when the theme's dark preset is on:
	//
	//   buddyx-dark-theme   BuddyX  (inc/Kirki_Option/Component.php:110)
	//   reign-dark-theme    Reign   (themes/reign style.scss .reign-dark)
	//   wp-dark-mode-active wp-dark-mode plugin
	//   theme-dark          generic / Astra-style
	//   dark-mode           generic / many themes
	var darkClasses = [
		'wp-dark-mode-active',
		'dark-mode',
		'theme-dark',
		'buddyx-dark-theme',
		'reign-dark-theme'
	];
	function sync() {
		var isDark = false;
		for (var i = 0; i < darkClasses.length; i++) {
			if (html.classList.contains(darkClasses[i])
				|| body.classList.contains(darkClasses[i])) {
				isDark = true;
				break;
			}
		}
		// Modern themes prefer the `data-theme="dark"` attribute over a
		// body class. Honour it on either <html> or <body>.
		if (!isDark) {
			if (html.getAttribute('data-theme') === 'dark'
				|| body.getAttribute('data-theme') === 'dark') {
				isDark = true;
			}
		}
		body.classList.toggle('jt-dark', isDark);
	}
	sync();
	if (typeof MutationObserver === 'function') {
		var opts = { attributes: true, attributeFilter: ['class', 'data-theme'] };
		new MutationObserver(sync).observe(html, opts);
		new MutationObserver(sync).observe(body, opts);
	}
})();
