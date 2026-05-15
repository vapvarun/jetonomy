/**
 * Jetonomy — pagination "Load More" click-to-load.
 *
 * Attaches to every .jt-pagination container on the page. The button is a
 * real <a href="?pg=N">, so visitors with JS disabled get classic full-page
 * pagination. With JS, the click is intercepted and the next page is
 * fetched + appended in place.
 *
 * Why click-only (not infinite scroll): the posts_per_page / replies_per_page
 * settings are a customer contract — "show N items per page". An auto-firing
 * IntersectionObserver chain-loads pages on scroll, defeating the setting on
 * any community larger than the initial viewport. See Basecamp #9860293843.
 *
 * Reads i18n strings from window.jetonomyData.i18n if available, or
 * window.jetonomyPagination.i18n as a dedicated channel; falls back to English.
 */
(function () {
	function bind(container) {
		if (container.dataset.jtBound === '1') {
			return;
		}
		container.dataset.jtBound = '1';

		var btn = container.querySelector('.jt-load-more-trigger');
		if (!btn) { return; }

		var i18n = (window.jetonomyPagination && window.jetonomyPagination.i18n)
			|| (window.jetonomyData && window.jetonomyData.i18n)
			|| {};
		var loadingLabel = i18n.loading || 'Loading...';
		var loadMoreLabel = i18n.loadMore || 'Load More';

		var loading = false;

		// Selector for the list container the next page's items should be
		// merged into. The partial emits this on the wrapper so each surface
		// (posts, replies, drafts, etc.) can target its own list element.
		var targetSel = container.dataset.jtTarget || '.jt-topics';

		function fetchAndAppend(event) {
			if (event) { event.preventDefault(); }
			if (loading) { return; }
			loading = true;
			btn.textContent = loadingLabel;
			btn.setAttribute('aria-busy', 'true');
			btn.style.pointerEvents = 'none';
			fetch(btn.href).then(function (r) { return r.text(); }).then(function (html) {
				var parser = new DOMParser();
				var doc = parser.parseFromString(html, 'text/html');
				var newList = doc.querySelector(targetSel);
				var currentList = document.querySelector(targetSel);
				var appended = [];
				if (newList && currentList) {
					Array.from(newList.children).forEach(function (child) {
						currentList.appendChild(child);
						appended.push(child);
					});
				}
				// Re-hydrate WP Interactivity API directives on appended nodes.
				// Core's IA only scans regions once on DOMContentLoaded, so without
				// this call every data-wp-on--click button on the new replies (vote,
				// reply, quote, flag, three-dot menu) stays inert. The helper is a
				// generic intra-plugin primitive — see assets/js/interactivity-
				// rehydrate.js. Any future caller that injects [data-wp-interactive]
				// markup post-load should call the same function.
				if (appended.length && typeof window.jetonomyHydrateInteractive === 'function') {
					var regions = [];
					appended.forEach(function (n) {
						if (n.nodeType !== 1) { return; }
						if (n.hasAttribute('data-wp-interactive')) { regions.push(n); }
						n.querySelectorAll('[data-wp-interactive]').forEach(function (r) {
							regions.push(r);
						});
					});
					if (regions.length) {
						window.jetonomyHydrateInteractive(regions);
					}
				}
				var newPag = doc.querySelector('.jt-pagination[data-jt-target="' + targetSel + '"]')
					|| doc.querySelector('.jt-pagination');
				if (newPag) {
					container.replaceWith(newPag);
					bind(newPag);
				} else {
					container.remove();
				}
			}).catch(function () {
				loading = false;
				btn.textContent = loadMoreLabel;
				btn.removeAttribute('aria-busy');
				btn.style.pointerEvents = '';
			});
		}

		btn.addEventListener('click', fetchAndAppend);
	}

	function initAll() {
		document.querySelectorAll('.jt-pagination').forEach(bind);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
