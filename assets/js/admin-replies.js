/**
 * Jetonomy — Replies admin page (reply moderation table).
 *
 * Inline-edit, row actions (trash / spam / restore), and bulk actions.
 * AJAX URL / nonce / i18n strings come from window.jetonomyAdmin and
 * page-specific window.jetonomyReplies localize. Loaded only on the
 * Replies admin page (or wherever jt-replies-table renders).
 */
(function () {
	'use strict';

	var cfg = {
		ajaxUrl: (window.jetonomyAdmin && window.jetonomyAdmin.ajaxUrl) || window.ajaxurl,
		nonce: (window.jetonomyReplies && window.jetonomyReplies.nonce) || (window.jetonomyAdmin && window.jetonomyAdmin.nonce) || '',
		i18n: (window.jetonomyReplies && window.jetonomyReplies.i18n) || {}
	};

	var _alert = function (msg) {
		return window.jetonomyAlert ? window.jetonomyAlert(msg) : Promise.resolve(window.alert(msg));
	};
	var _confirm = function (msg, opts) {
		return window.jetonomyConfirm ? window.jetonomyConfirm(msg, opts) : Promise.resolve(window.confirm(msg));
	};

	function ajax(action, data) {
		var params = new URLSearchParams();
		params.set('action', action);
		params.set('nonce', cfg.nonce);
		Object.keys(data).forEach(function (k) { params.set(k, data[k]); });
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: params.toString()
		}).then(function (res) {
			if (!res.ok) { throw new Error(res.statusText); }
			return res.json();
		});
	}

	function showFeedback(el, message, type) {
		el.textContent = message;
		el.className = 'jt-save-feedback jt-save-feedback--' + type;
		setTimeout(function () {
			el.textContent = '';
			el.className = 'jt-save-feedback';
		}, 3500);
	}

	var table = document.getElementById('jt-replies-table');
	if (!table) { return; }

	var selectAll = document.getElementById('jt-select-all');
	if (selectAll) {
		selectAll.addEventListener('change', function () {
			table.querySelectorAll('.jt-row-cb').forEach(function (cb) { cb.checked = selectAll.checked; });
		});
		table.addEventListener('change', function (e) {
			if (!e.target.classList.contains('jt-row-cb')) { return; }
			var all = table.querySelectorAll('.jt-row-cb');
			var checked = table.querySelectorAll('.jt-row-cb:checked');
			selectAll.checked = all.length > 0 && checked.length === all.length;
			selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
		});
	}
	var tfootCb = table.querySelector('tfoot input[type="checkbox"]');
	if (tfootCb && selectAll) {
		tfootCb.addEventListener('change', function () { selectAll.click(); });
	}

	table.addEventListener('click', function (e) {
		var trigger = e.target.closest('.jt-edit-trigger');
		if (!trigger) { return; }
		e.preventDefault();
		var replyId = trigger.dataset.replyId;
		var row = document.getElementById('jt-reply-row-' + replyId);
		if (!row) { return; }

		var viewEl = row.querySelector('.jt-reply-preview');
		var editEl = row.querySelector('.jt-inline-edit');
		var isOpen = 'true' === editEl.dataset.open;

		if (isOpen) {
			editEl.style.display = 'none';
			editEl.setAttribute('aria-hidden', 'true');
			editEl.dataset.open = 'false';
			viewEl.style.display = '';
		} else {
			viewEl.style.display = 'none';
			editEl.style.display = '';
			editEl.removeAttribute('aria-hidden');
			editEl.dataset.open = 'true';
			var ta = editEl.querySelector('.jt-edit-reply-content');
			if (ta) { ta.focus(); }
		}
	});

	table.addEventListener('click', function (e) {
		var btn = e.target.closest('.jt-cancel-edit');
		if (!btn) { return; }
		e.preventDefault();
		var editEl = btn.closest('.jt-inline-edit');
		var td = editEl.closest('td');
		var viewEl = td.querySelector('.jt-reply-preview');
		editEl.style.display = 'none';
		editEl.setAttribute('aria-hidden', 'true');
		editEl.dataset.open = 'false';
		viewEl.style.display = '';
	});

	table.addEventListener('click', function (e) {
		var btn = e.target.closest('.jt-save-reply');
		if (!btn) { return; }
		e.preventDefault();
		var replyId = btn.dataset.id;
		var row = document.getElementById('jt-reply-row-' + replyId);
		var editEl = row.querySelector('.jt-inline-edit');
		var content = editEl.querySelector('.jt-edit-reply-content').value;
		var spinner = editEl.querySelector('.jt-save-spinner');
		var feedback = editEl.querySelector('.jt-save-feedback');
		var viewEl = row.querySelector('.jt-reply-preview');

		btn.disabled = true;
		spinner.classList.add('is-active');

		ajax('jetonomy_update_reply', { reply_id: replyId, content: content })
			.then(function (res) {
				if (res.success) {
					var preview = (res.data && res.data.preview) ? res.data.preview : content.slice(0, 200);
					viewEl.textContent = preview;
					showFeedback(feedback, cfg.i18n.saved || 'Saved!', 'success');
					setTimeout(function () {
						editEl.style.display = 'none';
						editEl.setAttribute('aria-hidden', 'true');
						editEl.dataset.open = 'false';
						viewEl.style.display = '';
					}, 1200);
				} else {
					var msg = (res.data && res.data.message) ? res.data.message : (cfg.i18n.saveError || 'Save failed.');
					showFeedback(feedback, msg, 'error');
				}
			})
			.catch(function () { showFeedback(feedback, cfg.i18n.saveError || 'Save failed.', 'error'); })
			.finally(function () {
				btn.disabled = false;
				spinner.classList.remove('is-active');
			});
	});

	function performReplyAction(action, replyId) {
		var ajaxAction = 'approve' === action ? 'jetonomy_approve_content'
			: 'spam' === action ? 'jetonomy_spam_content'
				: 'jetonomy_trash_content';

		ajax(ajaxAction, { type: 'reply', id: replyId })
			.then(function (res) {
				if (!res.success) { return; }
				var row = document.getElementById('jt-reply-row-' + replyId);
				if ('trash' === action || 'spam' === action) {
					row.style.opacity = '0.4';
					row.style.pointerEvents = 'none';
					setTimeout(function () { row.remove(); }, 700);
				} else {
					window.location.reload();
				}
			});
	}

	table.addEventListener('click', function (e) {
		var link = e.target.closest('.jt-action-link');
		if (!link) { return; }
		e.preventDefault();
		var action = link.dataset.action;
		var replyId = link.dataset.id;
		var confirmMsg = 'trash' === action ? (cfg.i18n.confirmTrash || 'Move this to trash?') : (cfg.i18n.confirmSpam || 'Mark this as spam?');

		if ('trash' === action || 'spam' === action) {
			_confirm(confirmMsg, { danger: true }).then(function (ok) {
				if (ok) { performReplyAction(action, replyId); }
			});
			return;
		}
		performReplyAction(action, replyId);
	});

	var bulkBtn = document.getElementById('jt-bulk-apply');
	var bulkSelect = document.getElementById('jt-bulk-action');
	var bulkSpinner = document.getElementById('jt-bulk-spinner');

	if (bulkBtn && bulkSelect) {
		bulkBtn.addEventListener('click', function () {
			var action = bulkSelect.value;
			if (!action) { _alert(cfg.i18n.noAction || 'Please choose a bulk action.'); return; }

			var checked = table.querySelectorAll('.jt-row-cb:checked');
			if (!checked.length) { _alert(cfg.i18n.noneSelected || 'Please select at least one reply.'); return; }

			var ids = [];
			checked.forEach(function (cb) { ids.push(cb.value); });

			var ajaxAction = 'approve' === action ? 'jetonomy_approve_content'
				: 'spam' === action ? 'jetonomy_spam_content'
					: 'jetonomy_trash_content';

			var runBulk = function () {
				bulkBtn.disabled = true;
				bulkSpinner.classList.add('is-active');
				var promises = ids.map(function (id) {
					return ajax(ajaxAction, { type: 'reply', id: id });
				});
				return Promise.allSettled(promises).then(function () {
					bulkBtn.disabled = false;
					bulkSpinner.classList.remove('is-active');
					window.location.reload();
				});
			};

			if ('trash' === action || 'spam' === action) {
				_confirm(cfg.i18n.confirmBulk || 'Apply this action to all selected replies?', { danger: true }).then(function (ok) {
					if (ok) { runBulk(); }
				});
			} else {
				runBulk();
			}
		});
	}
})();
