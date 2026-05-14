/**
 * Jetonomy — Notifications page actions.
 *
 * Wires up everything on the /notifications page that mutates state via REST:
 *   - "Mark all as read" header button
 *   - Per-row "Mark as read" / "Delete" via the row's ⋯ menu
 *   - Bulk select + bulk Mark read / Delete from the bulk toolbar
 *
 * SSR drives filtering and pagination (regular <a href> reloads), so this
 * script only handles state-change interactions. Reads REST base + nonce
 * from window.jetonomyData via window.jetonomyRest.restFetch.
 */
(function () {
	'use strict';

	if (!window.jetonomyRest || typeof window.jetonomyRest.restFetch !== 'function') { return; }

	var list = document.querySelector('[data-jt-notif-list]');
	var bulkbar = document.querySelector('[data-jt-notif-bulkbar]');
	var markAllBtn = document.querySelector('[data-jt-mark-all-read]');

	function rest(path, opts) {
		return window.jetonomyRest.restFetch(path, opts || {});
	}

	function markRowRead(row) {
		row.classList.remove('unread');
		row.setAttribute('data-jt-notif-read', '1');
		var dot = row.querySelector('.jt-notif-dot');
		if (dot) { dot.remove(); }
		var markBtn = row.querySelector('[data-jt-notif-action="mark_read"]');
		if (markBtn) {
			var li = markBtn.closest('li');
			if (li) { li.remove(); }
		}
	}

	function closeAllMenus(except) {
		document.querySelectorAll('[data-jt-notif-menu]').forEach(function (menu) {
			if (menu === except) { return; }
			var listEl = menu.querySelector('.jt-notif-item__menu-list');
			var trigger = menu.querySelector('[data-jt-notif-menu-trigger]');
			if (listEl) { listEl.setAttribute('hidden', ''); }
			if (trigger) { trigger.setAttribute('aria-expanded', 'false'); }
		});
	}

	function updateBulkbar() {
		if (!bulkbar || !list) { return; }
		var checked = list.querySelectorAll('.jt-notif-cb:checked');
		var countEl = bulkbar.querySelector('[data-jt-notif-selected-count]');
		if (countEl) { countEl.textContent = String(checked.length); }
		if (checked.length > 0) {
			bulkbar.removeAttribute('hidden');
		} else {
			bulkbar.setAttribute('hidden', '');
		}
	}

	function getCheckedIds() {
		if (!list) { return []; }
		return Array.from(list.querySelectorAll('.jt-notif-cb:checked'))
			.map(function (cb) { return parseInt(cb.value, 10); })
			.filter(function (id) { return id > 0; });
	}

	// ── Mark all read (header) ─────────────────────────────────────────────
	if (markAllBtn) {
		markAllBtn.addEventListener('click', function (e) {
			e.preventDefault();
			markAllBtn.disabled = true;
			rest('/notifications/mark-all-read', { method: 'POST' }).then(function (res) {
				if (!res.ok) { markAllBtn.disabled = false; return; }
				if (list) {
					list.querySelectorAll('.jt-notif-item.unread').forEach(markRowRead);
				}
				markAllBtn.remove();
			});
		});
	}

	if (!list) { return; }

	// ── Per-row ⋯ menu open/close + actions ────────────────────────────────
	list.addEventListener('click', function (e) {
		var trigger = e.target.closest('[data-jt-notif-menu-trigger]');
		if (trigger) {
			e.preventDefault();
			var menu = trigger.closest('[data-jt-notif-menu]');
			var menuList = menu.querySelector('.jt-notif-item__menu-list');
			var isOpen = !menuList.hasAttribute('hidden');
			closeAllMenus(isOpen ? null : menu);
			if (isOpen) {
				menuList.setAttribute('hidden', '');
				trigger.setAttribute('aria-expanded', 'false');
			} else {
				menuList.removeAttribute('hidden');
				trigger.setAttribute('aria-expanded', 'true');
			}
			return;
		}

		var actionBtn = e.target.closest('[data-jt-notif-action]');
		if (actionBtn) {
			e.preventDefault();
			var row = actionBtn.closest('.jt-notif-item');
			if (!row) { return; }
			var id = parseInt(row.getAttribute('data-jt-notif-id'), 10);
			if (!id) { return; }
			var action = actionBtn.getAttribute('data-jt-notif-action');
			closeAllMenus();

			if ('mark_read' === action) {
				rest('/notifications/' + id, { method: 'PATCH' }).then(function (res) {
					if (res.ok) { markRowRead(row); }
				});
			} else if ('delete' === action) {
				// Optimistic remove — re-insert on failure so the user can retry.
				var nextSibling = row.nextSibling;
				var parent = row.parentNode;
				row.style.display = 'none';
				rest('/notifications/' + id, { method: 'DELETE' }).then(function (res) {
					if (res.ok) {
						row.remove();
						if (!list.querySelector('.jt-notif-item')) {
							// All rows gone — reload to render the empty state correctly.
							window.location.reload();
						}
					} else {
						row.style.display = '';
						if (parent && nextSibling) { parent.insertBefore(row, nextSibling); }
					}
				});
			}
			return;
		}

		// Click on a checkbox label / checkbox cell — handled via change event below.
	});

	// Close menus on outside click.
	document.addEventListener('click', function (e) {
		if (!e.target.closest('[data-jt-notif-menu]')) { closeAllMenus(); }
	});

	// Close menus on Escape.
	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key) { closeAllMenus(); }
	});

	// ── Bulk selection ─────────────────────────────────────────────────────
	list.addEventListener('change', function (e) {
		if (e.target.matches('.jt-notif-cb')) {
			updateBulkbar();
		}
	});

	var selectAll = document.querySelector('[data-jt-notif-selectall]');
	if (selectAll) {
		selectAll.addEventListener('change', function () {
			list.querySelectorAll('.jt-notif-cb').forEach(function (cb) {
				cb.checked = selectAll.checked;
			});
			updateBulkbar();
		});
	}

	// ── Bulk actions ───────────────────────────────────────────────────────
	if (bulkbar) {
		bulkbar.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-jt-notif-bulk]');
			if (!btn) { return; }
			var action = btn.getAttribute('data-jt-notif-bulk');
			var ids = getCheckedIds();
			if (!ids.length) { return; }

			btn.disabled = true;
			rest('/notifications/bulk', {
				method: 'POST',
				body: { action: action, ids: ids }
			}).then(function (res) {
				btn.disabled = false;
				if (!res.ok) { return; }
				ids.forEach(function (id) {
					var row = list.querySelector('.jt-notif-item[data-jt-notif-id="' + id + '"]');
					if (!row) { return; }
					if ('delete' === action) {
						row.remove();
					} else {
						markRowRead(row);
					}
				});
				if (selectAll) { selectAll.checked = false; }
				updateBulkbar();
				if (!list.querySelector('.jt-notif-item')) {
					window.location.reload();
				}
			});
		});
	}
})();
