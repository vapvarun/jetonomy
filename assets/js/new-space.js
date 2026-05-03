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

	form.querySelectorAll('.jt-icon-option input[type=radio]').forEach(function (radio) {
		radio.addEventListener('change', function () {
			form.querySelectorAll('.jt-icon-option').forEach(function (el) {
				el.classList.toggle('is-selected', el.contains(radio) && radio.checked);
			});
		});
	});

	(function () {
		var pickerWrap = form.querySelector('[data-jt-icon-picker]');
		if (!pickerWrap) { return; }
		var searchInput = pickerWrap.querySelector('[data-jt-icon-search]');
		var moreBtn = pickerWrap.querySelector('[data-jt-icon-more]');
		var emptyMsg = pickerWrap.querySelector('[data-jt-icon-empty]');
		var options = pickerWrap.querySelectorAll('.jt-icon-option');
		var moreOpen = false;
		var moreLabelOpen = i18n.iconShowFewer || 'Show fewer icons';
		var moreLabelClosed = i18n.iconShowMore || 'Show more icons';

		function applyFilter() {
			var q = (searchInput.value || '').trim().toLowerCase();
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
			moreBtn.addEventListener('click', function () {
				moreOpen = !moreOpen;
				moreBtn.textContent = moreOpen ? moreLabelOpen : moreLabelClosed;
				applyFilter();
			});
		}
	})();

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
			fetch(form.dataset.jtRestBase + '/media', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': form.dataset.jtRestNonce },
				body: fd
			}).then(function (r) {
				return r.json().then(function (b) { return { ok: r.ok, body: b }; });
			}).then(function (res) {
				if (!res.ok || !res.body || !res.body.url) {
					coverStatus.textContent = (res.body && res.body.message) || (i18n.uploadFailed || 'Upload failed.');
					return;
				}
				setPreview(res.body.url);
				coverStatus.textContent = i18n.uploaded || 'Uploaded.';
				setTimeout(function () { coverStatus.textContent = ''; }, 2000);
			}).catch(function () {
				coverStatus.textContent = i18n.networkError || 'Network error.';
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
		fd.forEach(function (v, k) { if (v) { payload[k] = v; } });
		fetch(form.dataset.jtRestBase + '/spaces', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': form.dataset.jtRestNonce,
				'Content-Type': 'application/json'
			},
			body: JSON.stringify(payload)
		}).then(function (r) {
			return r.json().then(function (body) { return { ok: r.ok, body: body }; });
		}).then(function (res) {
			if (!res.ok || !res.body || !res.body.slug) {
				errBox.textContent = (res.body && res.body.message) || (i18n.createSpaceFailed || 'Could not create the space. Please try again.');
				errBox.hidden = false;
				btn.disabled = false;
				return;
			}
			window.location.href = form.dataset.jtCommunityBase + '/s/' + res.body.slug + '/';
		}).catch(function () {
			errBox.textContent = i18n.networkErrorRetry || 'Network error. Please try again.';
			errBox.hidden = false;
			btn.disabled = false;
		});
	});
})();
