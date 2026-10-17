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
 * any community larger than the initial viewport.
 *
 * Button labels are translated with wp.i18n.
 */
(function () {
	function bind(container) {
		if (container.dataset.jtBound === '1') {
			return;
		}
		container.dataset.jtBound = '1';

		var btn = container.querySelector('.jt-load-more-trigger');
		if (!btn) { return; }

		var loadingLabel = wp.i18n.__( 'Loading…', 'jetonomy' );
		var loadMoreLabel = wp.i18n.__( 'Load More', 'jetonomy' );
		var failedLabel = wp.i18n.__( 'Could not load more. Check your connection and try again.', 'jetonomy' );

		var loading = false;

		// Selector for the list container the next page's items should be
		// merged into. The partial emits this on the wrapper so each surface
		// (posts, replies, drafts, etc.) can target its own list element.
		var targetSel = container.dataset.jtTarget || '.jt-topics';

		// A row's identity: topic rows carry data-jt-key, reply cards an id.
		function keyOf(el) {
			return el.getAttribute('data-jt-key') || el.id || '';
		}

		function reset() {
			loading = false;
			btn.textContent = loadMoreLabel;
			btn.removeAttribute('aria-busy');
			btn.style.pointerEvents = '';
		}

		function fetchAndAppend(event) {
			if (event) { event.preventDefault(); }
			if (loading) { return; }
			loading = true;
			var staleErr = container.querySelector('.jt-pagination-error');
			if (staleErr) { staleErr.remove(); }
			btn.textContent = loadingLabel;
			btn.setAttribute('aria-busy', 'true');
			btn.style.pointerEvents = 'none';
			fetch(btn.href).then(function (r) {
				if (!r.ok) { throw new Error(String(r.status)); }
				return r.text();
			}).then(function (html) {
				var parser = new DOMParser();
				var doc = parser.parseFromString(html, 'text/html');
				var newList = doc.querySelector(targetSel);
				var currentList = document.querySelector(targetSel);
				var appended = [];
				if (newList && currentList) {
					// Pages are offsets, so a topic posted or bumped since this page
					// loaded shifts a row already shown onto the next page. Skip
					// rows that are already in the list.
					var seen = {};
					Array.from(currentList.children).forEach(function (child) {
						var k = keyOf(child);
						if (k) { seen[k] = true; }
					});
					Array.from(newList.children).forEach(function (child) {
						var k = keyOf(child);
						if (k && seen[k]) { return; }
						currentList.appendChild(child);
						appended.push(child);
					});
				}
				// Restore click-action behaviour on the appended cards. The WP
				// Interactivity API only walks data-wp-* directives at boot and
				// doesn't observe post-boot DOM inserts, so without this call
				// every action button (vote, reply, quote, flag, edit, delete,
				// split, more-menu) on the new replies would be inert.
				if (appended.length && typeof window.jetonomyHydrateInteractive === 'function') {
					window.jetonomyHydrateInteractive(appended);
				}
				var newPag = doc.querySelector('.jt-pagination[data-jt-target="' + targetSel + '"]')
					|| doc.querySelector('.jt-pagination');
				if (newPag) {
					container.replaceWith(newPag);
					bind(newPag);
				} else {
					container.remove();
				}
				// The button that had focus is gone; keyboard users continue
				// from the first new row instead of the top of the page.
				if (appended.length) {
					var first = appended[0];
					var focusTarget = first.querySelector('a[href], button:not([disabled])');
					if (!focusTarget) {
						first.setAttribute('tabindex', '-1');
						focusTarget = first;
					}
					focusTarget.focus();
				}
			}).catch(function () {
				reset();
				// Inline and announced, beside the button the member just used.
				var err = container.querySelector('.jt-pagination-error');
				if (!err) {
					err = document.createElement('p');
					err.className = 'jt-form-error jt-pagination-error';
					err.setAttribute('role', 'alert');
					container.appendChild(err);
				}
				err.textContent = failedLabel;
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

	// Re-bind after an iAPI client-side navigation swaps in a new view. bind()
	// is idempotent (dataset.jtBound guard), so re-running initAll only wires
	// the freshly-swapped .jt-pagination containers.
	document.addEventListener('jetonomy:navigated', initAll);
})();
