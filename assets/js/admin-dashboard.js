/**
 * Jetonomy — admin Dashboard page.
 *
 * Imports or removes demo data through /jetonomy/v1/admin/demo-data. Both
 * reload the Dashboard (its stats change) with a jt_demo arg, so the PHP
 * view prints the success notice after the reload. UI strings use wp.i18n.
 */
(function () {
	// The jt_demo arg only carries the post-reload notice; drop it so a later
	// refresh does not repeat the notice.
	var here = new URL(window.location.href);
	if (here.searchParams.has('jt_demo')) {
		here.searchParams.delete('jt_demo');
		window.history.replaceState(null, '', here.toString());
	}

	var card = document.getElementById('jt-demo-card');
	if (!card || !window.wp || !wp.apiFetch) {
		return;
	}
	var i18n = {
		removeConfirm: wp.i18n.__( 'Delete all sample categories, spaces, posts, and replies? Your own content is not affected.', 'jetonomy' ),
		importConfirm: wp.i18n.__( 'Add sample members, categories, spaces and topics to this community? You can remove them again in one click.', 'jetonomy' ),
		removing:      wp.i18n.__( 'Removing…', 'jetonomy' ),
		importing:     wp.i18n.__( 'Importing…', 'jetonomy' ),
		error:         wp.i18n.__( 'Something went wrong.', 'jetonomy' ),
	};

	// Modal toolkit (jetonomy-modals.js) is a hard dependency on every
	// Jetonomy admin page. Degrade silently if it is absent rather than
	// emitting native alert/confirm; both paths resolve to false so nothing
	// runs without the owner's express confirmation.
	var _alert = function (msg) {
		return typeof window.jetonomyAlert === 'function' ? window.jetonomyAlert(msg) : Promise.resolve();
	};
	var _confirm = function (msg, opts) {
		return typeof window.jetonomyConfirm === 'function' ? window.jetonomyConfirm(msg, opts) : Promise.resolve(false);
	};

	function run(btn, method, confirmMsg, busyLabel, done, danger) {
		btn.addEventListener('click', function () {
			_confirm(confirmMsg, { danger: danger }).then(function (ok) {
				if (!ok) { return; }
				var label = Array.prototype.slice.call(btn.childNodes);
				btn.disabled = true;
				btn.textContent = busyLabel;
				wp.apiFetch({ path: '/jetonomy/v1/admin/demo-data', method: method })
					.then(function () {
						var url = new URL(window.location.href);
						url.searchParams.set('jt_demo', done);
						window.location.assign(url.toString());
					})
					.catch(function (err) {
						btn.disabled = false;
						btn.replaceChildren.apply(btn, label);
						_alert((err && err.message) || i18n.error);
					});
			});
		});
	}

	var remove = document.getElementById('jetonomy-cleanup-demo');
	if (remove) {
		run(remove, 'DELETE', i18n.removeConfirm, i18n.removing, 'removed', true);
	}
	var add = document.getElementById('jetonomy-import-demo');
	if (add) {
		run(add, 'POST', i18n.importConfirm, i18n.importing, 'imported', false);
	}
})();
