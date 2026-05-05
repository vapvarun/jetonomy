/**
 * Jetonomy — Tags admin page.
 *
 * Create / edit / delete tags via AJAX, including the edit modal and
 * bulk-delete flow. All user-visible strings come from
 * window.jetonomyAdmin.i18n. Loaded via the conditional enqueue in
 * Admin::enqueue_assets when the hook matches the Tags page.
 */
(function () {
	'use strict';

	var i18n = (window.jetonomyAdmin && window.jetonomyAdmin.i18n) || {};
	var _alert = function (msg) {
		return window.jetonomyAlert ? window.jetonomyAlert(msg) : Promise.resolve(window.alert(msg));
	};
	var _confirm = function (msg, opts) {
		return window.jetonomyConfirm ? window.jetonomyConfirm(msg, opts) : Promise.resolve(window.confirm(msg));
	};

	function post(action, data) {
		var ajax = window.ajaxurl;
		var nonce = window.jetonomyAdmin && window.jetonomyAdmin.nonce;
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', nonce);
		Object.keys(data || {}).forEach(function (k) {
			if (Array.isArray(data[k])) {
				data[k].forEach(function (v) { body.append(k + '[]', v); });
			} else {
				body.append(k, data[k]);
			}
		});
		return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	function showError(res) {
		_alert(res.data && res.data.message ? res.data.message : res.data);
	}

	var perPage = document.getElementById('tags-per-page');
	if (perPage) {
		perPage.addEventListener('change', function () { this.form.submit(); });
	}

	var saveBtn = document.getElementById('jetonomy-save-tag');
	if (saveBtn) {
		saveBtn.addEventListener('click', function () {
			var name = document.getElementById('tag-name').value.trim();
			var slug = document.getElementById('tag-slug').value.trim();
			if (!name) {
				_alert(i18n.tagNameRequired || 'Name is required.');
				return;
			}
			post('jetonomy_create_tag', { name: name, slug: slug }).then(function (res) {
				if (res.success) { window.location.reload(); } else { showError(res); }
			});
		});
	}

	var modal = document.getElementById('jetonomy-edit-tag-modal');
	function openModal() { if (modal) { modal.style.display = ''; } }
	function closeModal() { if (modal) { modal.style.display = 'none'; } }

	document.querySelectorAll('.jetonomy-edit-tag').forEach(function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			document.getElementById('edit-tag-id').value = this.dataset.id;
			document.getElementById('edit-tag-name').value = this.dataset.name;
			document.getElementById('edit-tag-slug').value = this.dataset.slug;
			openModal();
		});
	});
	document.querySelectorAll('.jetonomy-modal-close, #jetonomy-edit-tag-modal .jetonomy-modal__overlay').forEach(function (el) {
		el.addEventListener('click', closeModal);
	});
	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key && modal && 'none' !== modal.style.display) { closeModal(); }
	});

	var updateBtn = document.getElementById('jetonomy-update-tag');
	if (updateBtn) {
		updateBtn.addEventListener('click', function () {
			var id = document.getElementById('edit-tag-id').value;
			var name = document.getElementById('edit-tag-name').value.trim();
			var slug = document.getElementById('edit-tag-slug').value.trim();
			post('jetonomy_update_tag', { id: id, name: name, slug: slug }).then(function (res) {
				if (res.success) { window.location.reload(); } else { showError(res); }
			});
		});
	}

	document.querySelectorAll('.jetonomy-delete-tag').forEach(function (a) {
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var id = this.dataset.id;
			var count = parseInt(this.dataset.count || '0', 10);
			var msg = i18n.tagDeleteConfirm || 'Delete this tag?';
			if (count > 0) {
				msg = (i18n.tagDeleteAttachedPrefix || 'This tag is attached to') + ' ' + count + ' ' + (i18n.tagDeleteAttachedSuffix || 'posts. Delete it and detach from all posts?');
			}
			_confirm(msg, { danger: true }).then(function (ok) {
				if (!ok) { return; }
				post('jetonomy_delete_tag', { id: id, force: 1 }).then(function (res) {
					if (res.success) { window.location.reload(); } else { showError(res); }
				});
			});
		});
	});

	var cbAll = document.getElementById('jetonomy-tags-cb-all');
	if (cbAll) {
		cbAll.addEventListener('change', function () {
			var self = this;
			document.querySelectorAll('.jetonomy-tag-cb').forEach(function (cb) { cb.checked = self.checked; });
		});
	}
	var bulkApply = document.getElementById('jetonomy-tags-bulk-apply');
	if (bulkApply) {
		bulkApply.addEventListener('click', function () {
			var action = document.getElementById('jetonomy-tags-bulk-action').value;
			if ('delete' !== action) { return; }
			var ids = Array.from(document.querySelectorAll('.jetonomy-tag-cb:checked')).map(function (cb) { return cb.value; });
			if (ids.length === 0) {
				_alert(i18n.tagBulkSelectAtLeastOne || 'Select at least one tag.');
				return;
			}
			_confirm(i18n.tagBulkDeleteConfirm || 'Delete the selected tags?', { danger: true }).then(function (ok) {
				if (!ok) { return; }
				post('jetonomy_bulk_delete_tags', { ids: ids }).then(function (res) {
					if (res.success) { window.location.reload(); } else { showError(res); }
				});
			});
		});
	}
})();
