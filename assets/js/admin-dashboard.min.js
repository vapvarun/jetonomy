/**
 * Jetonomy — admin Dashboard page.
 *
 * Removes the demo-data card via AJAX. Loaded only on the Jetonomy
 * Dashboard admin page, and only when the demo-data card is present.
 * i18n strings come from window.jetonomyAdmin.i18n.
 */
(function () {
	var btn = document.getElementById('jetonomy-cleanup-demo');
	if (!btn) {
		return;
	}
	var i18n = (window.jetonomyAdmin && window.jetonomyAdmin.i18n) || {};

	btn.addEventListener('click', function () {
		if (!confirm(i18n.demoCleanupConfirm || 'Delete all sample categories, spaces, posts, and replies from the setup wizard? Your own content is not affected.')) {
			return;
		}
		btn.disabled = true;
		btn.textContent = i18n.demoCleanupRemoving || 'Removing...';
		fetch(window.ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'jetonomy_cleanup_sample_data',
				nonce: window.jetonomyAdmin.nonce
			}),
			credentials: 'same-origin'
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (res.success) {
					var card = document.getElementById('jt-demo-card');
					if (card) {
						card.remove();
					}
				} else {
					alert(res.data || (i18n.error || 'Failed'));
					btn.disabled = false;
				}
			});
	});
})();
