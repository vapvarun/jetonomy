/**
 * Jetonomy — Notifications page "Mark all read" button.
 *
 * Reads REST base + nonce from window.jetonomyData.
 */
(function () {
	'use strict';
	var btn = document.querySelector('[data-jt-mark-all-read]');
	if (!btn || !window.jetonomyData) { return; }

	btn.addEventListener('click', function (e) {
		e.preventDefault();
		btn.disabled = true;
		window.jetonomyRest.restFetch('/notifications/mark-all-read', {
			method: 'POST'
		}).then(function (res) {
			if (!res.ok) { btn.disabled = false; return; }
			document.querySelectorAll('.jt-notif-dot').forEach(function (d) { d.remove(); });
			btn.remove();
		});
	});
})();
