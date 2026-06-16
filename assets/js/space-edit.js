/**
 * Jetonomy — Space Edit form.
 *
 * Icon picker (search filter, show-more toggle), cover image uploader,
 * tag-prefix editor, and PATCH submit. Reads form data attrs for REST
 * base / nonce / space ID. i18n strings come from window.jetonomyData
 * .i18n.
 */
(function () {
	'use strict';

	var form = document.getElementById('jt-space-edit-form');
	if (!form) { return; }

	var i18n = (window.jetonomyData && window.jetonomyData.i18n) || {};

	// Icon picker wiring moved to assets/js/jetonomy-icon-picker.js (auto-discovers
	// every [data-jt-icon-picker] on the page). This file only owns the cover
	// uploader + submit handler now.

	var coverInput = form.querySelector('[data-jt-cover-input]');
	var coverValue = form.querySelector('[data-jt-cover-value]');
	var coverPrev = form.querySelector('[data-jt-cover-preview]');
	var coverRemove = form.querySelector('[data-jt-cover-remove]');
	var coverStatus = form.querySelector('[data-jt-cover-status]');

	function setPreview(url) {
		coverValue.value = url;
		if (url) {
			coverPrev.hidden = false;
			var img = coverPrev.querySelector('img');
			if (!img) {
				img = document.createElement('img');
				img.alt = '';
				coverPrev.appendChild(img);
			}
			img.src = url;
			coverRemove.hidden = false;
		} else {
			coverPrev.hidden = true;
			coverRemove.hidden = true;
			var existing = coverPrev.querySelector('img');
			if (existing) { existing.remove(); }
		}
	}

	if (coverInput) {
		coverInput.addEventListener('change', function () {
			var file = coverInput.files && coverInput.files[0];
			if (!file) { return; }
			coverStatus.textContent = i18n.uploading || 'Uploading...';
			var fd = new FormData();
			fd.append('file', file);
			window.jetonomyRest.restFetch('/media', {
				method: 'POST',
				body: fd
			}).then(function (res) {
				if (!res.ok || !res.data || !res.data.url) {
					coverStatus.textContent = res.status === 0
						? (i18n.networkError || 'Network error.')
						: ((res.data && res.data.message) || (i18n.uploadFailed || 'Upload failed.'));
					return;
				}
				setPreview(res.data.url);
				coverStatus.textContent = i18n.uploaded || 'Uploaded.';
				setTimeout(function () { coverStatus.textContent = ''; }, 2000);
			});
			coverInput.value = '';
		});
	}
	if (coverRemove) {
		coverRemove.addEventListener('click', function () { setPreview(''); });
	}

	var prefixToggle = form.querySelector('[data-jt-prefix-toggle]');
	var prefixConfig = form.querySelector('[data-jt-prefix-config]');
	var prefixList = form.querySelector('[data-jt-prefix-list]');
	var prefixAdd = form.querySelector('[data-jt-prefix-add]');

	if (prefixToggle && prefixConfig) {
		prefixToggle.addEventListener('change', function () {
			prefixConfig.hidden = !prefixToggle.checked;
		});
	}

	function addPrefixRow(name, color) {
		var row = document.createElement('div');
		row.className = 'jt-prefix-row';

		var nameInput = document.createElement('input');
		nameInput.type = 'text';
		nameInput.className = 'jt-input jt-prefix-name';
		nameInput.placeholder = i18n.prefixLabel || 'Label';
		nameInput.maxLength = 50;
		nameInput.value = name || '';

		var colorInput = document.createElement('input');
		colorInput.type = 'color';
		colorInput.className = 'jt-prefix-color';
		colorInput.value = color || '#3B82F6';

		var removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'jt-btn jt-btn-ghost jt-prefix-remove';
		removeBtn.setAttribute('aria-label', i18n.removePrefix || 'Remove prefix');
		removeBtn.textContent = '×';
		removeBtn.addEventListener('click', function () { row.remove(); });

		row.appendChild(nameInput);
		row.appendChild(colorInput);
		row.appendChild(removeBtn);
		prefixList.appendChild(row);
	}

	if (prefixAdd) {
		prefixAdd.addEventListener('click', function () { addPrefixRow('', '#3B82F6'); });
	}

	form.querySelectorAll('.jt-prefix-row .jt-prefix-remove').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var row = btn.closest('.jt-prefix-row');
			if (row) { row.remove(); }
		});
	});

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var errBox = form.querySelector('[data-jt-error]');
		var savedBox = form.querySelector('[data-jt-saved]');
		errBox.hidden = true;
		savedBox.hidden = true;
		var btn = form.querySelector('button[type="submit"]');
		btn.disabled = true;

		var fd = new FormData(form);
		var payload = {};
		fd.forEach(function (v, k) {
			if (k === 'posts_per_page' || k === 'enable_prefixes') { return; }
			if (/^jt_cf\[/.test(k)) { return; } // collected separately below
			payload[k] = v;
		});

		var customFields = window.jetonomyCollectCustomFields ? window.jetonomyCollectCustomFields(form) : {};
		if (Object.keys(customFields).length > 0) { payload.custom_fields = customFields; }

		var settings = {};
		var ppp = form.querySelector('[name=posts_per_page]').value.trim();
		settings.posts_per_page = ppp === '' ? '' : parseInt(ppp, 10);
		settings.enable_prefixes = prefixToggle && prefixToggle.checked ? 1 : 0;

		var prefixes = [];
		form.querySelectorAll('.jt-prefix-row').forEach(function (row) {
			var name = row.querySelector('.jt-prefix-name').value.trim();
			var color = row.querySelector('.jt-prefix-color').value;
			if (name) {
				prefixes.push({ name: name, color: color });
			}
		});
		settings.prefixes = prefixes;
		payload.settings = settings;

		window.jetonomyRest.restFetch('/spaces/' + form.dataset.jtSpaceId, {
			method: 'PATCH',
			body: payload
		}).then(function (res) {
			btn.disabled = false;
			if (!res.ok) {
				errBox.textContent = res.status === 0
					? (i18n.networkErrorRetry || 'Network error. Please try again.')
					: ((res.data && res.data.message) || (i18n.saveFailed || 'Could not save changes.'));
				errBox.hidden = false;
				return;
			}
			savedBox.hidden = false;
			setTimeout(function () { savedBox.hidden = true; }, 2500);
		});
	});
})();
