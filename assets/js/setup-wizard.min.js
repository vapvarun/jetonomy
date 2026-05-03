/**
 * Jetonomy — Setup wizard.
 *
 * 3-step wizard run from the standalone setup page. Reads ajax URL,
 * nonce, site URL, and i18n strings from window.jetonomySetup.
 */
(function () {
	'use strict';

	var cfg = window.jetonomySetup || {};
	var ajaxUrl = cfg.ajaxUrl;
	var nonce = cfg.nonce;
	var siteUrl = cfg.siteUrl;
	var i18n = cfg.i18n || {};

	if (!ajaxUrl || !nonce || !siteUrl) { return; }

	var communityUrl = siteUrl + 'community/';

	var steps = document.querySelectorAll('.jt-step');
	var stepDots = document.querySelectorAll('.jt-setup-step-dot');
	var conn1 = document.getElementById('jt-conn-1');
	var conn2 = document.getElementById('jt-conn-2');

	function showStep(n) {
		steps.forEach(function (el, i) {
			el.classList.toggle('jt-step--active', i + 1 === n);
		});
		stepDots.forEach(function (dot, i) {
			var num = i + 1;
			dot.classList.toggle('jt-setup-step-dot--active', num === n);
			dot.classList.toggle('jt-setup-step-dot--done', num < n);
		});
		if (conn1) { conn1.classList.toggle('jt-setup-step-connector--done', n > 1); }
		if (conn2) { conn2.classList.toggle('jt-setup-step-connector--done', n > 2); }
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function showError(id, msg) {
		var el = document.getElementById(id);
		if (el) { el.textContent = msg; el.style.display = 'block'; }
	}

	function hideError(id) {
		var el = document.getElementById(id);
		if (el) { el.style.display = 'none'; }
	}

	function setLoading(btn, loading) {
		btn.classList.toggle('jt-btn--loading', loading);
		btn.disabled = loading;
	}

	function sanitizeSlug(raw) {
		return raw.replace(/[^a-z0-9-]/gi, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').toLowerCase() || 'community';
	}

	var slugInput = document.getElementById('jt-base-slug');
	var urlSlugEl = document.getElementById('jt-url-slug');
	var visitBtn = document.getElementById('jt-visit-community');

	if (slugInput) {
		slugInput.addEventListener('input', function () {
			var slug = sanitizeSlug(this.value);
			communityUrl = siteUrl + slug + '/';
			if (urlSlugEl) {
				urlSlugEl.textContent = slug;
			}
		});
	}

	var next1 = document.getElementById('jt-next-1');
	if (next1) {
		next1.addEventListener('click', function () {
			hideError('jt-error-1');
			var slug = slugInput ? sanitizeSlug(slugInput.value) : 'community';
			if (!slug) {
				showError('jt-error-1', i18n.slugRequired || 'Please enter a community URL slug.');
				return;
			}
			showStep(2);
		});
	}

	var back2 = document.getElementById('jt-back-2');
	if (back2) {
		back2.addEventListener('click', function () { showStep(1); });
	}

	function doAjax(action, data, btn, errorId, onSuccess) {
		setLoading(btn, true);
		hideError(errorId);

		var params = new URLSearchParams();
		params.append('action', action);
		params.append('nonce', nonce);
		Object.keys(data).forEach(function (k) { params.append(k, data[k]); });

		fetch(ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString()
		}).then(function (r) { return r.json(); }).then(function (res) {
			setLoading(btn, false);
			if (res.success) {
				onSuccess(res.data);
			} else {
				var msg = (res.data && typeof res.data === 'object' && res.data.message)
					? String(res.data.message)
					: (i18n.genericError || 'Something went wrong. Please try again.');
				showError(errorId, msg);
			}
		}).catch(function () {
			setLoading(btn, false);
			showError(errorId, i18n.networkError || 'Network error. Please try again.');
		});
	}

	var next2 = document.getElementById('jt-next-2');
	if (next2) {
		next2.addEventListener('click', function () {
			var slug = slugInput ? sanitizeSlug(slugInput.value) : 'community';
			var typeEl = document.querySelector('input[name="jt-default-type"]:checked');
			var type = typeEl ? typeEl.value : 'forum';
			var catNameEl = document.getElementById('jt-cat-name');
			var spaceNameEl = document.getElementById('jt-space-name');
			var spaceDescEl = document.getElementById('jt-space-desc');
			var catName = catNameEl ? catNameEl.value : 'General';
			var spaceName = spaceNameEl ? spaceNameEl.value : 'Community Discussion';
			var spaceDesc = spaceDescEl ? spaceDescEl.value : '';

			if (!catName.trim() || !spaceName.trim()) {
				showError('jt-error-2', i18n.fillCategoryAndSpace || 'Please fill in the category and space name.');
				return;
			}

			doAjax('jetonomy_setup_save', {
				base_slug: slug,
				default_space_type: type,
				category_name: catName,
				space_name: spaceName,
				space_description: spaceDesc
			}, next2, 'jt-error-2', function () {
				communityUrl = siteUrl + slug + '/';
				if (visitBtn) { visitBtn.href = communityUrl; }
				showStep(3);
			});
		});
	}

	var sampleBtn = document.getElementById('jt-create-sample');
	if (sampleBtn) {
		sampleBtn.addEventListener('click', function () {
			var slug = slugInput ? sanitizeSlug(slugInput.value) : 'community';
			var typeEl = document.querySelector('input[name="jt-default-type"]:checked');
			var type = typeEl ? typeEl.value : 'forum';

			doAjax('jetonomy_setup_create_sample', {
				base_slug: slug,
				default_space_type: type
			}, sampleBtn, 'jt-error-2', function () {
				communityUrl = siteUrl + slug + '/';
				if (visitBtn) { visitBtn.href = communityUrl; }
				showStep(3);
			});
		});
	}
})();
