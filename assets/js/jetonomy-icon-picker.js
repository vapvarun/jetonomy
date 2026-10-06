/**
 * Jetonomy — reusable icon picker.
 *
 * Auto-discovers every <code>[data-jt-icon-picker]</code> on the page and
 * wires up: radio selection state, search filter, and the "Show more icons"
 * toggle. The picker markup ships from templates/partials/icon-picker.php
 * and is identical on the frontend new-space form and every admin form that
 * needs to pick a Lucide icon (admin spaces, admin categories, Pro badges).
 *
 * The show more / fewer labels are translated with wp.i18n, so the picker
 * reads the same on admin pages, where jetonomyData is not localized.
 */
(function () {
	'use strict';

	function initPicker(pickerWrap) {
		if (!pickerWrap || pickerWrap.dataset.jtIconPickerInit === '1') { return; }
		pickerWrap.dataset.jtIconPickerInit = '1';

		var moreLabelOpen = wp.i18n.__( 'Show fewer icons', 'jetonomy' );
		var moreLabelClosed = wp.i18n.__( 'Show more icons', 'jetonomy' );

		var searchInput = pickerWrap.querySelector('[data-jt-icon-search]');
		var moreBtn = pickerWrap.querySelector('[data-jt-icon-more]');
		var emptyMsg = pickerWrap.querySelector('[data-jt-icon-empty]');
		var options = pickerWrap.querySelectorAll('.jt-icon-option');
		var moreOpen = false;

		// Sync the selected class with whatever radio is currently checked.
		options.forEach(function (opt) {
			var radio = opt.querySelector('input[type=radio]');
			if (!radio) { return; }
			radio.addEventListener('change', function () {
				options.forEach(function (other) {
					other.classList.toggle('is-selected', other === opt && radio.checked);
				});
			});
		});

		function applyFilter() {
			var q = (searchInput && searchInput.value || '').trim().toLowerCase();
			var anyVisible = false;
			options.forEach(function (opt) {
				var keywords = (opt.getAttribute('data-jt-icon-keywords') || '').toLowerCase();
				var isExtended = '1' === opt.getAttribute('data-jt-icon-extended');
				var isSelected = opt.classList.contains('is-selected');
				var show;
				if ('' === q) {
					show = isSelected || !isExtended || moreOpen;
				} else {
					show = keywords.indexOf(q) !== -1;
				}
				opt.hidden = !show;
				if (show) { anyVisible = true; }
			});
			if (emptyMsg) { emptyMsg.hidden = anyVisible; }
			if (moreBtn) { moreBtn.hidden = '' !== q; }
		}

		if (searchInput) { searchInput.addEventListener('input', applyFilter); }
		if (moreBtn) {
			moreBtn.textContent = moreLabelClosed;
			moreBtn.addEventListener('click', function () {
				moreOpen = !moreOpen;
				moreBtn.textContent = moreOpen ? moreLabelOpen : moreLabelClosed;
				applyFilter();
			});
		}

		applyFilter();
	}

	function bootAll() {
		document.querySelectorAll('[data-jt-icon-picker]').forEach(initPicker);
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', bootAll);
	} else {
		bootAll();
	}

	// Expose a small re-scan hook for templates that inject pickers after
	// page load (e.g. an admin modal that lazily reveals its form).
	window.jetonomyIconPickerScan = bootAll;

	// Re-scan after an iAPI client-side navigation swaps in a new view, so the
	// new-space / edit-space pickers bind without a full reload. bootAll +
	// initPicker are idempotent (dataset.jtIconPickerInit guard).
	document.addEventListener('jetonomy:navigated', bootAll);
})();
