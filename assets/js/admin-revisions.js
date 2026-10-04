/**
 * Jetonomy — Revisions admin page.
 *
 * Toggles the diff row open/closed for each revision pair. The button
 * labels are translated with wp.i18n.
 */
(function () {
	var labelView = wp.i18n.__( 'View diff', 'jetonomy' );
	var labelHide = wp.i18n.__( 'Hide diff', 'jetonomy' );

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
