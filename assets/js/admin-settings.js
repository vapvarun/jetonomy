/**
 * Jetonomy — Settings admin page.
 *
 * Email-preview / send-test / reset-to-default flows for the Email
 * Templates section, plus the CAPTCHA-provider field toggle on the
 * Spam tab. Loaded only on the Settings admin page; reads nonce / ajax
 * URL / i18n strings from window.jetonomyAdmin.
 */
(function () {
	'use strict';

	var i18n = (window.jetonomyAdmin && window.jetonomyAdmin.i18n) || {};
	var nonce = (window.jetonomyAdmin && window.jetonomyAdmin.nonce) || '';
	var ajax = (window.jetonomyAdmin && window.jetonomyAdmin.ajaxUrl) || window.ajaxurl;

	// Modal toolkit (jetonomy-modals.js) is a hard dependency. Degrade
	// silently if it is absent rather than emitting native alert/confirm.
	// See assets/js/admin-content.js for the full rationale.
	var alertFn = function (msg) {
		return typeof window.jetonomyAlert === 'function' ? window.jetonomyAlert(msg) : Promise.resolve();
	};
	var confirmFn = function (msg, opts) {
		return typeof window.jetonomyConfirm === 'function' ? window.jetonomyConfirm(msg, opts) : Promise.resolve(false);
	};

	// CAPTCHA provider toggle (Spam tab).
	(function () {
		var sel = document.getElementById('captcha_provider');
		var rcRow = document.querySelector('.jt-captcha-recaptcha-only');
		if (sel && rcRow) {
			sel.addEventListener('change', function () {
				rcRow.style.display = this.value === 'recaptcha_v3' ? '' : 'none';
			});
		}
	})();

	// Email Templates: preview / send test / reset.
	var modal = document.getElementById('jetonomy-email-preview-modal');
	if (!modal || !nonce || !ajax) {
		return;
	}
	var iframe = document.getElementById('jetonomy-email-preview-iframe');
	var subjEl = document.getElementById('jetonomy-email-preview-subject');

	// Dialog keyboard contract (Basecamp 10146440810 a11y addendum): remember
	// the opener, move focus into the dialog on open, trap Tab inside it, and
	// restore focus on close. The markup carries role="dialog" + aria-modal.
	var lastOpener = null;
	function focusables() {
		return modal.querySelectorAll('button, [href], iframe, input, select, textarea, [tabindex]:not([tabindex="-1"])');
	}
	function closeModal() {
		modal.style.display = 'none';
		if (lastOpener && lastOpener.focus) { lastOpener.focus(); }
		lastOpener = null;
	}
	function openModal() {
		lastOpener = document.activeElement;
		modal.style.display = '';
		// Focus the Close button, not the first focusable: DOM order put the
		// preview IFRAME first, which swallowed the dialog's key handling and
		// read poorly to screen readers (QA 2026-07-30, card 10146440810).
		var close = modal.querySelector('.jetonomy-modal-close');
		var f = focusables();
		if (close) { close.focus(); } else if (f.length) { f[0].focus(); }
	}

	// Escape must close the dialog even while focus is INSIDE the preview
	// iframe: the parent document's keydown listeners never fire there
	// because the srcdoc iframe owns its own document. Rebind per load -
	// srcdoc replaces the document on every preview.
	if (iframe) {
		iframe.addEventListener('load', function () {
			try {
				iframe.contentDocument.addEventListener('keydown', function (e) {
					if ('Escape' === e.key) { closeModal(); }
				});
			} catch (err) { /* cross-origin guard - srcdoc is same-origin, never expected */ }
		});
	}
	modal.addEventListener('keydown', function (e) {
		if ('Tab' !== e.key) { return; }
		var f = focusables();
		if (!f.length) { return; }
		var first = f[0], last = f[f.length - 1];
		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	});

	modal.querySelectorAll('.jetonomy-modal-close, .jetonomy-modal__overlay').forEach(function (el) {
		el.addEventListener('click', closeModal);
	});
	document.addEventListener('keydown', function (e) {
		if ('Escape' === e.key && 'none' !== modal.style.display) { closeModal(); }
	});

	function rowFields(type) {
		var row = document.querySelector('[data-jt-email-type="' + type + '"]');
		return {
			subject: row ? (row.querySelector('.jetonomy-email-subject-input') || {}).value || '' : '',
			body: row ? (row.querySelector('.jetonomy-email-body-input') || {}).value || '' : ''
		};
	}

	function postForm(action, fields) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', nonce);
		Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
		return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	document.querySelectorAll('.jetonomy-email-preview-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var type = btn.dataset.type;
			var f = rowFields(type);
			postForm('jetonomy_email_preview', { type: type, subject: f.subject, body: f.body }).then(function (json) {
				if (!json.success) {
					alertFn((json.data && json.data.message) || json.data || (i18n.emailPreviewFailed || 'Preview failed.'));
					return;
				}
				subjEl.textContent = json.data.subject || (i18n.emailPreviewTitle || 'Email Preview');
				iframe.srcdoc = json.data.html;
				openModal();
			});
		});
	});

	document.querySelectorAll('.jetonomy-email-send-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var type = btn.dataset.type;
			var label = btn.dataset.label || btn.textContent;
			btn.disabled = true;
			btn.textContent = i18n.emailSending || 'Sending...';

			postForm('jetonomy_test_email', { type: type }).then(function (json) {
				btn.disabled = false;
				btn.textContent = label;
				var msg = (json.data && json.data.message) || json.data || '';
				alertFn(msg || (json.success ? (i18n.emailSent || 'Sent.') : (i18n.emailSendFailed || 'Failed to send.')));
			});
		});
	});

	document.querySelectorAll('.jetonomy-email-reset-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var type = btn.dataset.type;
			var label = btn.dataset.label || type;
			var msg = (i18n.emailResetConfirm || 'Reset %s to default? Your custom copy will be lost.').replace('%s', label);

			confirmFn(msg, { danger: true }).then(function (ok) {
				if (!ok) { return; }

				btn.disabled = true;
				postForm('jetonomy_email_reset', { type: type }).then(function (json) {
					btn.disabled = false;
					if (!json.success) {
						alertFn((json.data && json.data.message) || json.data || (i18n.emailResetFailed || 'Reset failed.'));
						return;
					}
					var row = document.querySelector('[data-jt-email-type="' + type + '"]');
					if (row) {
						var subjectInput = row.querySelector('.jetonomy-email-subject-input');
						var bodyInput = row.querySelector('.jetonomy-email-body-input');
						if (subjectInput) { subjectInput.value = json.data.subject || ''; }
						if (bodyInput) { bodyInput.value = json.data.body || ''; }
					}
					btn.style.display = 'none';
				});
			});
		});
	});
})();
