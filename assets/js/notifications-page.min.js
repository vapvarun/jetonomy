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
		fetch(window.jetonomyData.restBase + '/notifications/mark-all-read', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': window.jetonomyData.restNonce,
				'Content-Type': 'application/json'
			}
		}).then(function (r) {
			if (!r.ok) { throw new Error('mark_all_read_failed'); }
			document.querySelectorAll('.jt-notif-dot').forEach(function (d) { d.remove(); });
			btn.remove();
		}).catch(function () {
			btn.disabled = false;
		});
	});
})();
