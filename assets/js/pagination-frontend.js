/**
 * Jetonomy — pagination "Load More" with infinite-scroll on user
 * scroll. Attaches to every .jt-pagination container on the page.
 *
 * Reads i18n strings from window.jetonomyData.i18n if available, or
 * window.jetonomyPagination.i18n as a dedicated channel; falls back
 * to English. Auto-load is gated on a real user scroll so it doesn't
 * stack pages when the trigger is in the initial viewport.
 */
(function () {
	if (!('IntersectionObserver' in window)) {
		return;
	}

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
		var observer;
		var userHasScrolled = false;

		function fetchAndAppend() {
			if (loading) { return; }
			loading = true;
			btn.textContent = loadingLabel;
			btn.style.pointerEvents = 'none';
			fetch(btn.href).then(function (r) { return r.text(); }).then(function (html) {
				var parser = new DOMParser();
				var doc = parser.parseFromString(html, 'text/html');
				var newTopics = doc.querySelector('.jt-topics');
				var currentTopics = container.parentElement.querySelector('.jt-topics');
				if (newTopics && currentTopics) {
					Array.from(newTopics.children).forEach(function (child) {
						currentTopics.appendChild(child);
					});
				}
				var newPag = doc.querySelector('.jt-pagination');
				if (newPag) {
					container.replaceWith(newPag);
					bind(newPag);
				} else {
					container.remove();
				}
				if (observer) { observer.disconnect(); }
			}).catch(function () {
				loading = false;
				btn.textContent = loadMoreLabel;
				btn.style.pointerEvents = '';
			});
		}

		function onFirstScroll() {
			userHasScrolled = true;
			window.removeEventListener('scroll', onFirstScroll);
			var rect = container.getBoundingClientRect();
			var inView = rect.top < window.innerHeight + 200 && rect.bottom > -200;
			if (inView) { fetchAndAppend(); }
		}
		window.addEventListener('scroll', onFirstScroll, { passive: true });

		observer = new IntersectionObserver(function (entries) {
			if (!entries[0].isIntersecting || loading || !userHasScrolled) { return; }
			fetchAndAppend();
		}, { rootMargin: '200px' });
		observer.observe(container);
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
