/**
 * Jetonomy — Content admin page (post/reply moderation table).
 *
 * Inline-edit, row actions (trash / spam / restore), and bulk actions.
 * AJAX URL / nonce / i18n strings come from window.jetonomyAdmin and
 * the page-specific window.jetonomyContent localize. Loaded only on
 * the Content admin page.
 */
(function () {
	'use strict';

	var cfg = {
		ajaxUrl: (window.jetonomyAdmin && window.jetonomyAdmin.ajaxUrl) || window.ajaxurl,
		nonce: (window.jetonomyContent && window.jetonomyContent.nonce) || (window.jetonomyAdmin && window.jetonomyAdmin.nonce) || '',
		i18n: (window.jetonomyContent && window.jetonomyContent.i18n) || {}
	};

	var _alert = function (msg) {
		return window.jetonomyAlert ? window.jetonomyAlert(msg) : Promise.resolve(window.alert(msg));
	};
	var _confirm = function (msg, opts) {
		return window.jetonomyConfirm ? window.jetonomyConfirm(msg, opts) : Promise.resolve(window.confirm(msg));
	};

	var table = document.getElementById('jt-posts-table');

	function ajax(action, data) {
		var params = new URLSearchParams();
		params.set('action', action);
		params.set('nonce', cfg.nonce);
		Object.keys(data).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) {
				v.forEach(function (item) { params.append(k + '[]', item); });
			} else {
				params.set(k, v);
			}
		});
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

	document.addEventListener('click', function (e) {
		var trigger = e.target.closest('.jt-edit-trigger');
		if (trigger) {
			e.preventDefault();
			var row = trigger.closest('tr');
			row.querySelector('.jt-post-title-view').style.display = 'none';
			row.querySelector('.row-actions').style.display = 'none';
			var editDiv = row.querySelector('.jt-inline-edit');
			editDiv.style.display = '';
			editDiv.removeAttribute('aria-hidden');
			editDiv.querySelector('.jt-edit-title').focus();
			return;
		}

		var cancelBtn = e.target.closest('.jt-cancel-edit');
		if (cancelBtn) {
			e.preventDefault();
			var cancelRow = cancelBtn.closest('tr');
			cancelRow.querySelector('.jt-inline-edit').style.display = 'none';
			cancelRow.querySelector('.jt-inline-edit').setAttribute('aria-hidden', 'true');
			cancelRow.querySelector('.jt-post-title-view').style.display = '';
			cancelRow.querySelector('.row-actions').style.display = '';
			return;
		}

		var saveBtn = e.target.closest('.jt-save-post');
		if (saveBtn) {
			e.preventDefault();
			var saveRow = saveBtn.closest('tr');
			var postId = saveBtn.getAttribute('data-id');
			var titleEl = saveRow.querySelector('.jt-edit-title');
			var contentEl = saveRow.querySelector('.jt-edit-content');
			var spinner = saveRow.querySelector('.jt-save-spinner');
			var feedback = saveRow.querySelector('.jt-save-feedback');

			saveBtn.disabled = true;
			spinner.classList.add('is-active');

			ajax('jetonomy_update_post', {
				post_id: postId,
				title: titleEl.value,
				content: contentEl.value
			}).then(function (res) {
				saveBtn.disabled = false;
				spinner.classList.remove('is-active');
				if (res.success) {
					var titleView = saveRow.querySelector('.jt-post-title-view');
					var titleLink = titleView.querySelector('a');
					if (titleLink) {
						titleLink.textContent = titleEl.value;
					} else {
						titleView.textContent = titleEl.value;
					}
					saveRow.querySelector('.jt-inline-edit').style.display = 'none';
					saveRow.querySelector('.jt-inline-edit').setAttribute('aria-hidden', 'true');
					titleView.style.display = '';
					saveRow.querySelector('.row-actions').style.display = '';
					showFeedback(feedback, cfg.i18n.saved || 'Saved!', 'success');
				} else {
					showFeedback(feedback, (res.data && res.data.message) || cfg.i18n.saveError || 'Save failed.', 'error');
				}
			}).catch(function () {
				saveBtn.disabled = false;
				spinner.classList.remove('is-active');
				showFeedback(feedback, cfg.i18n.saveError || 'Save failed.', 'error');
			});
			return;
		}

		var actionLink = e.target.closest('.jt-action-link');
		if (actionLink) {
			e.preventDefault();
			var action = actionLink.getAttribute('data-action');
			var actionPostId = actionLink.getAttribute('data-id');
			var type = actionLink.getAttribute('data-type');

			var performAction = function () {
				var ajaxAction = 'post' === type ? 'jetonomy_delete_post' : 'jetonomy_delete_reply';
				var idParam = 'post' === type ? 'post_id' : 'reply_id';
				var statusParam = 'approve' === action ? 'publish' : action;

				var data = { status: statusParam };
				data[idParam] = actionPostId;

				ajax(ajaxAction, data).then(function (res) {
					if (res.success) {
						var actionRow = actionLink.closest('tr');
						actionRow.style.opacity = '0.4';
						setTimeout(function () { window.location.reload(); }, 600);
					}
				});
			};

			if ('approve' === action) {
				performAction();
				return;
			}
			var confirmMsg = 'trash' === action ? (cfg.i18n.confirmTrash || 'Move this to trash?') : (cfg.i18n.confirmSpam || 'Mark this as spam?');
			_confirm(confirmMsg, { danger: true }).then(function (ok) {
				if (ok) { performAction(); }
			});
		}
	});

	document.addEventListener('change', function (e) {
		if (e.target.id === 'jt-select-all' || e.target.closest('tfoot input[type="checkbox"]')) {
			var checked = e.target.checked;
			if (table) {
				table.querySelectorAll('.jt-row-cb').forEach(function (cb) { cb.checked = checked; });
			}
		}
	});

	var bulkBtn = document.getElementById('jt-bulk-apply');
	var bulkSelect = document.getElementById('jt-bulk-action');
	var bulkSpinner = document.getElementById('jt-bulk-spinner');

	if (bulkBtn && bulkSelect) {
		bulkBtn.addEventListener('click', function () {
			var action = bulkSelect.value;
			if (!action) {
				_alert(cfg.i18n.noAction || 'Please choose a bulk action.');
				return;
			}
			if (!table) { return; }
			var checked = table.querySelectorAll('.jt-row-cb:checked');
			if (!checked.length) {
				_alert(cfg.i18n.noneSelected || 'Please select at least one post.');
				return;
			}
			var ids = [];
			checked.forEach(function (cb) { ids.push(cb.value); });

			var bulkAction = 'approve' === action ? 'publish' : action;

			var runBulk = function () {
				bulkBtn.disabled = true;
				bulkSpinner.classList.add('is-active');
				ajax('jetonomy_bulk_content_action', {
					bulk_action: bulkAction,
					type: 'post',
					ids: ids
				}).then(function () {
					bulkBtn.disabled = false;
					bulkSpinner.classList.remove('is-active');
					window.location.reload();
				}).catch(function () {
					bulkBtn.disabled = false;
					bulkSpinner.classList.remove('is-active');
				});
			};

			if ('trash' === action || 'spam' === action) {
				_confirm(cfg.i18n.confirmBulk || 'Apply this action to all selected posts?', { danger: true }).then(function (ok) {
					if (ok) { runBulk(); }
				});
			} else {
				runBulk();
			}
		});
	}
})();
