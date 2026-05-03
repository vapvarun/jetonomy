/**
 * Jetonomy — Revisions admin page.
 *
 * Toggles the diff row open/closed for each revision pair. i18n strings
 * for the button label come from window.jetonomyAdmin.i18n.
 */
(function () {
	var i18n = (window.jetonomyAdmin && window.jetonomyAdmin.i18n) || {};
	var labelView = i18n.revisionViewDiff || 'View diff';
	var labelHide = i18n.revisionHideDiff || 'Hide diff';

	var toggles = document.querySelectorAll('.jt-rev-diff-toggle');
	for (var i = 0; i < toggles.length; i++) {
		toggles[i].addEventListener('click', function (evt) {
			var btn = evt.currentTarget;
			var targetId = btn.getAttribute('data-target');
			var row = document.getElementById(targetId);
			if (!row) {
				return;
			}
			var isOpen = !row.hasAttribute('hidden');
			if (isOpen) {
				row.setAttribute('hidden', '');
				btn.setAttribute('aria-expanded', 'false');
				btn.textContent = labelView;
			} else {
				row.removeAttribute('hidden');
				btn.setAttribute('aria-expanded', 'true');
				btn.textContent = labelHide;
			}
		});
	}
})();
