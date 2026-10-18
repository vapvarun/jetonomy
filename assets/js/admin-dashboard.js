/**
 * Jetonomy — admin Dashboard page.
 *
 * Imports or removes demo data through /jetonomy/v1/admin/demo-data, and
 * rebuilds counters through /jetonomy/v1/admin/recount. Each reloads the
 * Dashboard (its stats change) with a jt_demo arg, so the PHP view prints the
 * success notice after the reload. UI strings use wp.i18n.
 */
(function () {
	// The jt_demo arg only carries the post-reload notice; drop it so a later
	// refresh does not repeat the notice.
	var here = new URL(window.location.href);
	if (here.searchParams.has('jt_demo')) {
		here.searchParams.delete('jt_demo');
		window.history.replaceState(null, '', here.toString());
	}

	if (!window.wp || !wp.apiFetch) {
		return;
	}
	// Owner's terminology (Settings > General > Terminology); English fallback.
	var L = window.jetonomyLabels || {};
	var lbl = function ( noun, form, fallback ) {
		return ( L[ noun ] && L[ noun ][ form ] ) || fallback;
	};
	var i18n = {
		/* translators: 1: category label, plural lowercase. 2: space label, plural lowercase. 3: reply label, plural lowercase. */
		removeConfirm: wp.i18n.sprintf( wp.i18n.__( 'Delete all sample %1$s, %2$s, posts, and %3$s? Your own content is not affected.', 'jetonomy' ), lbl( 'category', 'many_lower', 'categories' ), lbl( 'space', 'many_lower', 'spaces' ), lbl( 'reply', 'many_lower', 'replies' ) ),
		/* translators: 1: member label, plural lowercase. 2: category label, plural lowercase. 3: space label, plural lowercase. 4: topic label, plural lowercase. */
		importConfirm: wp.i18n.sprintf( wp.i18n.__( 'Add sample %1$s, %2$s, %3$s and %4$s to this community? You can remove them again in one click.', 'jetonomy' ), lbl( 'member', 'many_lower', 'members' ), lbl( 'category', 'many_lower', 'categories' ), lbl( 'space', 'many_lower', 'spaces' ), lbl( 'topic', 'many_lower', 'topics' ) ),
		removing:      wp.i18n.__( 'Removing…', 'jetonomy' ),
		importing:     wp.i18n.__( 'Importing…', 'jetonomy' ),
		/* translators: 1: topic label, plural lowercase. 2: reply label, plural lowercase. 3: member label, plural lowercase. */
		recountConfirm: wp.i18n.sprintf( wp.i18n.__( 'Rebuild the %1$s, %2$s, %3$s and vote counts from your content? It can take a minute on a large community.', 'jetonomy' ), lbl( 'topic', 'many_lower', 'topics' ), lbl( 'reply', 'many_lower', 'replies' ), lbl( 'member', 'many_lower', 'members' ) ),
		recounting:    wp.i18n.__( 'Recounting…', 'jetonomy' ),
		error:         wp.i18n.__( 'Something went wrong.', 'jetonomy' ),
		network:       wp.i18n.__( 'Network error. Please try again.', 'jetonomy' ),
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

	function run(btn, path, method, confirmMsg, busyLabel, done, danger) {
		btn.addEventListener('click', function () {
			_confirm(confirmMsg, { danger: danger }).then(function (ok) {
				if (!ok) { return; }
				var label = Array.prototype.slice.call(btn.childNodes);
				btn.disabled = true;
				btn.textContent = busyLabel;
				wp.apiFetch({ path: path, method: method })
					.then(function () {
						var url = new URL(window.location.href);
						url.searchParams.set('jt_demo', done);
						window.location.assign(url.toString());
					})
					.catch(function (err) {
						btn.disabled = false;
						btn.replaceChildren.apply(btn, label);
						// apiFetch reports a request that never got an answer as
						// fetch_error with core's generic wording; name the cause.
						_alert(err && 'fetch_error' === err.code ? i18n.network : ((err && err.message) || i18n.error));
					});
			});
		});
	}

	var remove = document.getElementById('jetonomy-cleanup-demo');
	if (remove) {
		run(remove, '/jetonomy/v1/admin/demo-data', 'DELETE', i18n.removeConfirm, i18n.removing, 'removed', true);
	}
	var add = document.getElementById('jetonomy-import-demo');
	if (add) {
		run(add, '/jetonomy/v1/admin/demo-data', 'POST', i18n.importConfirm, i18n.importing, 'imported', false);
	}
	var recount = document.getElementById('jetonomy-recount');
	if (recount) {
		run(recount, '/jetonomy/v1/admin/recount', 'POST', i18n.recountConfirm, i18n.recounting, 'recounted', false);
	}
})();
