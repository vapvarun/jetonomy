/**
 * Jetonomy — New Space form.
 *
 * Icon picker (search filter, show-more toggle), cover image uploader,
 * and form submit. Reads form data attrs for REST base / nonce /
 * community base. i18n strings come from window.jetonomyData.i18n.
 */
(function () {
	'use strict';

	var form = document.getElementById('jt-new-space-form');
	if (!form) { return; }

	var i18n = (window.jetonomyData && window.jetonomyData.i18n) || {};

	// Collect Pro custom-field inputs (jt_cf[<slug>] / jt_cf[<slug>][]) into a
	// { slug: value } map, mirroring the new-post composer so the storage format
	// matches Pro's validate/sanitize/upsert (multi-checkbox values comma-joined).
	// Empty when the custom-fields extension is off (no inputs render).
	function collectCustomFields(scope) {
		var cf = {};
		scope.querySelectorAll('[name^="jt_cf["]').forEach(function (input) {
			var m = input.name.match(/^jt_cf\[([^\]]+)\](\[\])?$/);
			if (!m) { return; }
			var slug = m[1];
			var isMulti = m[2] === '[]';
			if (input.type === 'checkbox') {
				if (isMulti) {
					if (input.checked) {
						cf[slug] = cf[slug] ? cf[slug] + ',' + input.value : input.value;
					} else if (!(slug in cf)) {
						cf[slug] = '';
					}
				} else {
					cf[slug] = input.checked ? input.value : '';
				}
			} else if (input.type === 'radio') {
				if (input.checked) {
					cf[slug] = input.value;
				} else if (!(slug in cf)) {
					cf[slug] = '';
				}
			} else {
				cf[slug] = input.value;
			}
		});
		return cf;
	}

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
					coverStatus.textContent = (res.data && res.data.message) || (i18n.uploadFailed || 'Upload failed.');
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

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var errBox = form.querySelector('[data-jt-error]');
		errBox.hidden = true;
		var btn = form.querySelector('button[type="submit"]');
		btn.disabled = true;
		var fd = new FormData(form);
		var payload = {};
		fd.forEach(function (v, k) {
			if (/^jt_cf\[/.test(k)) { return; } // collected separately below
			if (v) { payload[k] = v; }
		});
		var customFields = collectCustomFields(form);
		if (Object.keys(customFields).length > 0) { payload.custom_fields = customFields; }
		window.jetonomyRest.restFetch('/spaces', {
			method: 'POST',
			body: payload
		}).then(function (res) {
			if (!res.ok || !res.data || !res.data.slug) {
				errBox.textContent = (res.data && res.data.message) || (i18n.createSpaceFailed || 'Could not create the space. Please try again.');
				errBox.hidden = false;
				btn.disabled = false;
				return;
			}
			window.location.href = form.dataset.jtCommunityBase + '/s/' + res.data.slug + '/';
		});
	});
})();
