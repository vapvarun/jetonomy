/**
 * Jetonomy — Community meta box on Appearance → Menus.
 *
 * Wires the "Select All" checkbox in the Community meta box so it toggles
 * every menu-item checkbox in that box, with full parity to WP core's own
 * bulk-select boxes: tick = all, untick = none, indeterminate when partial.
 *
 * Scoped strictly to #jetonomy-nav-menu so it never touches core's Pages /
 * Posts / Categories meta boxes (which WordPress wires itself).
 */
jQuery(function ($) {
	var $box = $('#jetonomy-nav-menu');
	if (!$box.length) {
		return;
	}

	var $selectAll = $box.find('.select-all');
	var itemSelector = '.menu-item-checkbox';

	// Tick / untick all items when the Select All box changes.
	$selectAll.on('change', function () {
		$box.find(itemSelector).prop('checked', this.checked);
	});

	// Keep Select All in sync (checked / unchecked / indeterminate) as
	// individual items are toggled.
	$box.on('change', itemSelector, function () {
		var $items = $box.find(itemSelector);
		var total = $items.length;
		var checked = $items.filter(':checked').length;

		$selectAll
			.prop('checked', total > 0 && checked === total)
			.prop('indeterminate', checked > 0 && checked < total);
	});
});
