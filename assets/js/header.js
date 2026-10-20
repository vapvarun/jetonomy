/**
 * Jetonomy — header chrome behaviours.
 *
 * - Toast helper fallback when BuddyNext isn't on the page.
 * - Notification dropdown (lazy-loaded list, mark all read, mark single).
 * - Search overlay with debounced REST search.
 * - Keyboard shortcuts (j/k navigation, Cmd+K / / search, ? help).
 * - User hover cards (cached lookup with login-fallback).
 *
 * Reads runtime data from window.jetonomyHeader (localised via
 * wp_localize_script). The search icon SVG is read by cloning the
 * server-rendered <template id="jt-search-icon-tpl"> in the DOM —
 * avoiding any innerHTML assignment in this file.
 */
(function () {
	var D = window.jetonomyHeader;
	var __ = wp.i18n.__;
	var _x = wp.i18n._x;
	if (!D) { return; }

	if (!window.bnToast) {
		window.bnToast = function (msg) {
			var t = document.createElement('div');
			t.className = 'jt-toast';
			t.textContent = msg;
			document.body.appendChild(t);
			setTimeout(function () { t.classList.add('show'); }, 10);
			setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 3000);
		};
	}

	// Controls the viewer may not use (e.g. voting without the permission)
	// explain themselves on click instead of doing nothing.
	document.addEventListener('click', function (e) {
		var el = e.target && e.target.closest ? e.target.closest('[data-jt-denied]') : null;
		if (el) {
			e.preventDefault();
			window.bnToast(el.getAttribute('data-jt-denied'));
		}
	});

	/* ── Notification Dropdown ── */
	var notifLoaded = false;
	var markFailed = __( 'Could not mark notifications as read. Check your connection and try again.', 'jetonomy' );
	window.jtToggleNotifDropdown = function (btn) {
		var wrap = btn.closest('.jt-notif-dropdown-wrap');
		var panel = wrap.querySelector('.jt-notif-panel');
		var isOpen = !panel.hidden;
		panel.hidden = isOpen;
		if (!isOpen && !notifLoaded) {
			notifLoaded = true;
			window.jetonomyRest.restFetch('/notifications?limit=5').then(function (res) {
				var body = panel.querySelector('.jt-notif-panel-body');
				if (!res.ok) {
					body.textContent = __( 'Failed to load', 'jetonomy' );
					body.className = 'jt-notif-panel-body jt-notif-panel-empty';
					return;
				}
				var resp = res.data || {};
				var data = resp.data || resp;
				if (!data || !data.length) {
					body.textContent = __( 'No notifications yet.', 'jetonomy' );
					body.className = 'jt-notif-panel-body jt-notif-panel-empty';
					return;
				}
				body.textContent = '';
				data.forEach(function (n) {
					var a = document.createElement('a');
					a.href = n.object_url || n.url || (D.base + '/notifications/');
					a.className = 'jt-notif-panel-item' + (n.is_read ? '' : ' unread');
					if (n.id) { a.setAttribute('data-jt-notif-id', n.id); }
					var txt = document.createElement('span');
					txt.className = 'jt-notif-panel-text';
					txt.dir = 'auto';
					txt.textContent = n.message || '';
					a.appendChild(txt);
					var time = document.createElement('span');
					time.className = 'jt-notif-panel-time';
					time.textContent = n.time_ago || '';
					a.appendChild(time);
					body.appendChild(a);
				});
			});
		}
	};

	/**
	 * Lower every unread-count badge by `by`, or clear them all when `by` is null:
	 * the desktop bell, the mobile Alerts tab, the user panel and the Unread tab.
	 * The one place that knows where the count is shown; view.js calls it too.
	 */
	window.jtLowerUnreadBadges = function (by) {
		document.querySelectorAll('.jt-community-nav-badge, .jt-mobile-tab-badge, .jt-userpanel-badge, [data-jt-notif-tab="unread"] .jt-notif-tab__count').forEach(function (badge) {
			var next = null === by ? 0 : (parseInt(badge.textContent.replace(/\D/g, ''), 10) || 0) - by;
			if (next > 0) { badge.textContent = String(next); } else { badge.remove(); }
		});
	};

	/**
	 * Show the server's unread count on every badge, adding a badge to its host
	 * ([data-jt-unread-host] names the badge class) when the count rose from zero.
	 */
	window.jtSetUnreadBadges = function (count) {
		document.querySelectorAll('[data-jt-unread-host]').forEach(function (host) {
			window.jtSetCountBadge(host, host.getAttribute('data-jt-unread-host'), count);
		});
	};

	/**
	 * Show `count` in the `cls` badge inside `host`: added when it rose from
	 * zero, removed at zero. view.js uses it for the notifications page tabs.
	 */
	window.jtSetCountBadge = function (host, cls, count) {
		var badge = host.querySelector('.' + cls);
		if (count > 0) {
			if (!badge) {
				badge = document.createElement('span');
				badge.className = cls;
				host.appendChild(badge);
			}
			badge.textContent = String(count);
		} else if (badge) {
			badge.remove();
		}
	};

	// The count refreshes when the member comes back to the tab and after they
	// mark things read: never on a timer (no idle polling).
	var countInFlight = false;
	window.jtRefreshUnreadCount = function () {
		if (!D.isLoggedIn || countInFlight || !document.querySelector('[data-jt-unread-host]')) { return; }
		countInFlight = true;
		// On the notifications page the Unread tab shows the count the list was rendered with.
		var tabCount = document.querySelector('[data-jt-notif-tab="unread"] .jt-notif-tab__count');
		var shown = tabCount ? (parseInt(tabCount.textContent.replace(/\D/g, ''), 10) || 0) : 0;
		// The route allows a 15s browser cache; a fresh read must skip it.
		window.jetonomyRest.restFetch('/notifications/unread-count?fresh=' + Date.now()).then(function (res) {
			countInFlight = false;
			if (res.ok && res.data && 'number' === typeof res.data.count) {
				window.jtSetUnreadBadges(res.data.count);
				notifLoaded = false; // The dropdown reloads its list on next open.
				// A changed count means the rows on screen are stale too: re-render them.
				if (res.data.count !== shown && window.jtReloadNotifList) { window.jtReloadNotifList(); }
			}
		});
	};
	document.addEventListener('visibilitychange', function () {
		if ('visible' === document.visibilityState) { window.jtRefreshUnreadCount(); }
	});
	window.addEventListener('focus', window.jtRefreshUnreadCount);

	window.jtMarkAllRead = function () {
		window.jetonomyRest.restFetch('/notifications/mark-all-read', {
			method: 'POST'
		}).then(function (res) {
			if (!res.ok) {
				window.bnToast(markFailed, 'error');
				return;
			}
			window.jtLowerUnreadBadges(null);
			document.querySelectorAll('.jt-notif-panel-item.unread').forEach(function (i) {
				i.classList.remove('unread');
			});
			window.jtRefreshUnreadCount();
		});
	};

	// Opening an unread notice (bell dropdown or the notifications page) marks
	// it read. keepalive lets the request finish while the link navigates away.
	document.addEventListener('click', function (e) {
		if (!e.target || !e.target.closest) { return; }
		var item = e.target.closest('.jt-notif-panel-item.unread, .jt-notif-item.unread .jt-notif-item__link');
		if (!item) { return; }
		var row = item.closest('.jt-notif-item') || item;
		var id = row.getAttribute('data-jt-notif-id');
		if (!id) { return; }
		row.classList.remove('unread');
		var dot = row.querySelector('.jt-notif-dot');
		if (dot) { dot.remove(); }
		window.jetonomyRest.restFetch('/notifications/' + encodeURIComponent(id), {
			method: 'PATCH',
			keepalive: true
		});
		window.jtLowerUnreadBadges(1);
	});

	document.addEventListener('click', function (e) {
		if (!e.target || !e.target.closest) { return; }
		var notifBtn = e.target.closest('.jt-community-nav-notif');
		if (notifBtn) {
			e.preventDefault();
			window.jtToggleNotifDropdown(notifBtn);
			return;
		}
		var markReadBtn = e.target.closest('.jt-notif-mark-read');
		if (markReadBtn) {
			e.preventDefault();
			window.jtMarkAllRead();
			return;
		}
		if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
		if (e.target.closest('a, button, input, select, textarea, label')) { return; }
		var row = e.target.closest('[data-jt-href]');
		if (row) {
			window.location = row.getAttribute('data-jt-href');
		}
	});

	document.addEventListener('click', function (e) {
		if (!e.target || !e.target.closest) { return; }
		if (!e.target.closest('.jt-notif-dropdown-wrap')) {
			var panel = document.querySelector('.jt-notif-panel');
			if (panel) { panel.hidden = true; }
		}
	});

	/* ── Search Overlay ── */
	var searchEl = null;
	var searchTimer = null;
	function buildSearchOverlay() {
		if (searchEl) { return searchEl; }
		searchEl = document.createElement('div');
		searchEl.className = 'jt-search-overlay';
		var inner = document.createElement('div');
		inner.className = 'jt-search-overlay-inner';
		var field = document.createElement('div');
		field.className = 'jt-search-overlay-field';
		var iconSpan = document.createElement('span');
		iconSpan.className = 'jt-search-overlay-icon';
		// Clone the server-rendered icon template — no innerHTML touch.
		var iconTpl = document.getElementById('jt-search-icon-tpl');
		if (iconTpl && iconTpl.content && iconTpl.content.firstElementChild) {
			iconSpan.appendChild(iconTpl.content.firstElementChild.cloneNode(true));
		}
		field.appendChild(iconSpan);
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'jt-search-overlay-input';
		input.dir = 'auto';
		input.placeholder = __( 'Search discussions…', 'jetonomy' );
		input.autocomplete = 'off';
		field.appendChild(input);
		var kbd = document.createElement('kbd');
		kbd.className = 'jt-search-overlay-kbd';
		kbd.textContent = _x( 'ESC', 'keyboard key label shown next to the search overlay', 'jetonomy' );
		field.appendChild(kbd);
		inner.appendChild(field);
		var results = document.createElement('div');
		results.className = 'jt-search-overlay-results';
		inner.appendChild(results);
		searchEl.appendChild(inner);
		document.body.appendChild(searchEl);

		function searchPageUrl(query) {
			return ((D && D.base) || '') + '/search/?q=' + encodeURIComponent(query || '');
		}

		input.addEventListener('input', function () {
			clearTimeout(searchTimer);
			var q = input.value.trim();
			if (q.length < 2) { results.textContent = ''; return; }
			/**
			 * Permalink for a search hit.
			 *
			 * The overlay queries posts (space_slug + slug); the same endpoint
			 * can return spaces (slug, no space_slug). Anything unrecognised
			 * goes to the search page for the query, which is always a useful
			 * destination - never a broken one.
			 */
			function searchResultUrl(r, query) {
				var base = (D && D.base) || '';
				if (r && r.space_slug && r.slug) {
					return base + '/s/' + encodeURIComponent(r.space_slug) + '/t/' + encodeURIComponent(r.slug) + '/';
				}
				if (r && r.slug && r.category_id !== undefined) {
					return base + '/s/' + encodeURIComponent(r.slug) + '/';
				}
				if (r && r.slug && r.space_id === undefined) {
					return base + '/tag/' + encodeURIComponent(r.slug) + '/';
				}
				return searchPageUrl(query);
			}

			searchTimer = setTimeout(function () {
				window.jetonomyRest.restFetch('/search?q=' + encodeURIComponent(q) + '&per_page=6').then(function (res) {
					// restFetch wraps the REST body as {ok, status, data} - the
					// results array lives one level deeper (the
					// single-unwrap here showed 'No results' for every query).
					var data = ( res.data && res.data.data ) || [];
					results.textContent = '';
					if (!data.length) {
						results.textContent = __( 'No results found.', 'jetonomy' );
						results.className = 'jt-search-overlay-results jt-search-overlay-empty';
						return;
					}
					results.className = 'jt-search-overlay-results';
					data.forEach(function (r) {
						var a = document.createElement('a');
						// The search payload carries slugs, not a permalink -
						// there is no `url` field, so `a.href = r.url` produced
						// "undefined" and every result led nowhere once results
						// started rendering.
						// Build the destination from the slugs the API does
						// return, and fall back to the full search page rather
						// than emit a dead link.
						a.href = searchResultUrl(r, q);
						a.className = 'jt-search-overlay-item';
						var strong = document.createElement('strong');
						strong.dir = 'auto';
						strong.textContent = r.title;
						a.appendChild(strong);
						if (r.space_title) {
							var span = document.createElement('span');
							span.textContent = r.space_title;
							a.appendChild(span);
						}
						results.appendChild(a);
					});
					// Six hits is a preview; the search page has the rest plus filters.
					var all = document.createElement('a');
					all.href = searchPageUrl(q);
					all.className = 'jt-search-overlay-all';
					all.textContent = __( 'See all results', 'jetonomy' );
					results.appendChild(all);
				});
			}, 250);
		});

		searchEl.addEventListener('click', function (e) { if (e.target === searchEl) { window.jtCloseSearch(); } });
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { window.jtCloseSearch(); }
			// Enter runs the full search, the same as the search page's own form.
			if (e.key === 'Enter' && !e.isComposing && input.value.trim()) {
				e.preventDefault();
				window.location.href = searchPageUrl(input.value.trim());
			}
		});
		return searchEl;
	}

	window.jtOpenSearch = function () {
		var el = buildSearchOverlay();
		el.classList.add('open');
		var inp = el.querySelector('.jt-search-overlay-input');
		inp.value = '';
		el.querySelector('.jt-search-overlay-results').textContent = '';
		setTimeout(function () { inp.focus(); }, 50);
	};
	window.jtCloseSearch = function () {
		if (searchEl) { searchEl.classList.remove('open'); }
	};

	/* ── Keyboard Shortcuts ── */
	var shortcutOpen = false;
	var shortcutOpener = null;
	document.addEventListener('keydown', function (e) {
		// The help dialog is modal: Escape (or ?) closes it, Tab stays on its
		// only control, and no page shortcut fires behind it.
		if (shortcutOpen) {
			var helpClose = document.querySelector('.jt-shortcut-modal button');
			if (e.key === 'Escape' || e.key === '?') { e.preventDefault(); window.jtCloseShortcutHelp(); }
			else if (e.key === 'Tab' && helpClose) { e.preventDefault(); helpClose.focus(); }
			return;
		}
		var tag = (e.target.tagName || '').toLowerCase();
		if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) { return; }

		if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); window.jtOpenSearch(); return; }
		if (e.key === '/' && !e.metaKey && !e.ctrlKey) { e.preventDefault(); window.jtOpenSearch(); return; }
		if (e.key === 'n' && !e.metaKey && !e.ctrlKey && D.isLoggedIn) { window.location.href = D.base + '/'; return; }

		if (e.key === 'j' || e.key === 'k') {
			var rows = Array.from(document.querySelectorAll('.jt-row, a.jt-row'));
			if (!rows.length) { return; }
			var cur = document.querySelector('.jt-row.jt-kb-focus, a.jt-row.jt-kb-focus');
			var idx = cur ? rows.indexOf(cur) : -1;
			if (cur) { cur.classList.remove('jt-kb-focus'); }
			idx = e.key === 'j' ? Math.min(idx + 1, rows.length - 1) : Math.max(idx - 1, 0);
			rows[idx].classList.add('jt-kb-focus');
			rows[idx].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			return;
		}
		if (e.key === 'Enter') {
			var focused = document.querySelector('.jt-row.jt-kb-focus, a.jt-row.jt-kb-focus');
			if (focused) {
				// The row is a DIV whose navigation anchor lives inside it -
				// clicking the div fires nothing. Click the
				// real link so the client-side router intercepts it like any
				// pointer click; fall back to a hard navigation.
				var link = focused.matches('a[href]') ? focused : focused.querySelector('.jt-row-title-link, a[href]');
				if (link) { link.click(); } 
				return;
			}
		}
		if (e.key === 'l' && !e.metaKey && !e.ctrlKey) {
			// Documented shortcut (readme FAQ): upvote the focused post
			// (promised but never implemented).
			var lRow = document.querySelector('.jt-row.jt-kb-focus, a.jt-row.jt-kb-focus');
			var up = lRow && lRow.querySelector('[data-wp-on--click="actions.voteUp"], .jt-vote-up');
			if (up) { e.preventDefault(); up.click(); }
			return;
		}
		if (e.key === 'r' && !e.metaKey && !e.ctrlKey) {
			// Documented shortcut: open a reply on the focused post - navigate
			// to it with the #reply intent; the post view focuses the composer.
			var rRow = document.querySelector('.jt-row.jt-kb-focus, a.jt-row.jt-kb-focus');
			var rLink = rRow && (rRow.matches('a[href]') ? rRow : rRow.querySelector('.jt-row-title-link, a[href]'));
			if (rLink) { e.preventDefault(); window.location.href = rLink.href.split('#')[0] + '#reply'; }
			return;
		}
		if (e.key === '?' && !e.metaKey && !e.ctrlKey) {
			e.preventDefault();
			shortcutOpen = true;
			shortcutOpener = document.activeElement;
			var modal = document.createElement('div');
			modal.className = 'jt-shortcut-help';
			var box = document.createElement('div');
			box.className = 'jt-shortcut-modal';
			box.setAttribute('role', 'dialog');
			box.setAttribute('aria-modal', 'true');
			box.setAttribute('aria-labelledby', 'jt-shortcut-help-title');
			var h3 = document.createElement('h3');
			h3.id = 'jt-shortcut-help-title';
			h3.textContent = __( 'Keyboard Shortcuts', 'jetonomy' );
			box.appendChild(h3);
			var tbl = document.createElement('table');
			var shortcuts = [
				[
					/* translators: keyboard shortcut for search; keep "/" and "Ctrl+K" as they are. */
					__( '/ or Ctrl+K', 'jetonomy' ),
					__( 'Search', 'jetonomy' )
				],
				['j / k', __( 'Navigate up/down', 'jetonomy' )],
				[_x( 'Enter', 'keyboard key', 'jetonomy' ), __( 'Open selected', 'jetonomy' )],
				['l', __( 'Upvote selected', 'jetonomy' )],
				['r', __( 'Reply to selected', 'jetonomy' )], // jt-terms-ok: verb.
				['n', __( 'Home', 'jetonomy' )],
				['?', __( 'This help', 'jetonomy' )]
			];
			shortcuts.forEach(function (s) {
				var tr = document.createElement('tr');
				var td1 = document.createElement('td');
				td1.textContent = s[0];
				tr.appendChild(td1);
				var td2 = document.createElement('td');
				td2.textContent = s[1];
				tr.appendChild(td2);
				tbl.appendChild(tr);
			});
			box.appendChild(tbl);
			var closeBtn = document.createElement('button');
			closeBtn.textContent = __( 'Close', 'jetonomy' );
			closeBtn.addEventListener('click', function () { window.jtCloseShortcutHelp(); });
			box.appendChild(closeBtn);
			modal.appendChild(box);
			document.body.appendChild(modal);
			modal.addEventListener('click', function (ev) { if (ev.target === modal) { window.jtCloseShortcutHelp(); } });
			closeBtn.focus();
		}
	});
	window.jtCloseShortcutHelp = function () {
		shortcutOpen = false;
		var m = document.querySelector('.jt-shortcut-help');
		if (m) { m.remove(); }
		if (shortcutOpener && shortcutOpener.isConnected && shortcutOpener.focus) { shortcutOpener.focus(); }
		shortcutOpener = null;
	};

	/* ── User Hover Cards ── */
	var hcCache = {};
	var hcEl = null;
	var hcTimer = null;
	var hcHideTimer = null;
	function getHoverCard() {
		if (hcEl) { return hcEl; }
		hcEl = document.createElement('div');
		hcEl.className = 'jt-hover-card';
		hcEl.style.display = 'none';
		hcEl.addEventListener('mouseenter', function () { clearTimeout(hcHideTimer); });
		hcEl.addEventListener('mouseleave', function () { hcEl.style.display = 'none'; });
		document.body.appendChild(hcEl);
		return hcEl;
	}
	function showHoverCard(anchor, userId) {
		var card = getHoverCard();
		var cached = hcCache[userId];
		if (cached) {
			renderHoverCard(card, cached, anchor);
			return;
		}
		card.textContent = '...';
		renderPosition(card, anchor);
		card.style.display = '';
		window.jetonomyRest.restFetch('/users/' + userId).then(function (res) {
			if (!res.ok) { card.style.display = 'none'; return; }
			var data = res.data;
			hcCache[userId] = data;
			renderHoverCard(card, data, anchor);
		});
	}
	function renderHoverCard(card, data, anchor) {
		card.textContent = '';
		var header = document.createElement('div');
		header.className = 'jt-hc-header';
		if (data.avatar_url) {
			var img = document.createElement('img');
			img.src = data.avatar_url;
			img.width = 40;
			img.height = 40;
			img.className = 'jt-hc-avatar';
			img.alt = data.display_name || '';
			header.appendChild(img);
		}
		var info = document.createElement('div');
		var nameEl = document.createElement('span');
		nameEl.className = 'jt-hc-name';
		nameEl.textContent = data.display_name || '';
		info.appendChild(nameEl);
		var trustEl = document.createElement('span');
		trustEl.className = 'jt-hc-trust';
		trustEl.textContent = wp.i18n.sprintf(
			/* translators: 1: trust level name (e.g. Regular), 2: reputation points. */
			__( '%1$s · %2$d rep', 'jetonomy' ),
			data.trust_level_label || data.trust_level_name || '',
			data.reputation || 0
		);
		info.appendChild(trustEl);
		header.appendChild(info);
		card.appendChild(header);
		if (data.bio) {
			var bio = document.createElement('p');
			bio.className = 'jt-hc-bio';
			bio.textContent = data.bio;
			card.appendChild(bio);
		}
		var stats = document.createElement('div');
		stats.className = 'jt-hc-stats';
		// Server-built: the topic and reply nouns are the owner's configured labels.
		var statsFmt = D.i18n.hcStatsFormat;
		stats.textContent = statsFmt
			.replace( '%1$d', data.post_count || 0 )
			.replace( '%2$d', data.reply_count || 0 );
		card.appendChild(stats);
		renderPosition(card, anchor);
		card.style.display = '';
	}
	function renderPosition(card, anchor) {
		var rect = anchor.getBoundingClientRect();
		card.style.position = 'fixed';
		card.style.top = (rect.bottom + 8) + 'px';
		card.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 296)) + 'px';
	}
	// Show the card for a user link. `delay` = 0 for keyboard focus (immediate),
	// 400ms for pointer hover (avoids flicker on pass-through).
	function scheduleShowHoverCard(link, delay) {
		var userId = link.dataset.userId;
		if (!userId) {
			var href = link.getAttribute('href') || '';
			var m = href.match(/\/u\/([^/]+)/);
			if (!m) { return; }
			link.dataset.userLogin = m[1];
		}
		clearTimeout(hcHideTimer);
		clearTimeout(hcTimer);
		hcTimer = setTimeout(function () {
			if (userId) { showHoverCard(link, userId); return; }
			var login = link.dataset.userLogin;
			if (!login) { return; }
			var cachedId = hcCache['login_' + login];
			if (cachedId) { showHoverCard(link, cachedId); return; }
			window.jetonomyRest.restFetch('/users/by-login/' + encodeURIComponent(login)).then(function (res) {
				var data = res.data || {};
				if (data.id) {
					hcCache[data.id] = data;
					hcCache['login_' + login] = data.id;
					link.dataset.userId = data.id;
					showHoverCard(link, data.id);
				}
			});
		}, delay);
	}
	function scheduleHideHoverCard() {
		clearTimeout(hcTimer);
		hcHideTimer = setTimeout(function () {
			if (hcEl) { hcEl.style.display = 'none'; }
		}, 200);
	}
	function hideHoverCardNow() {
		clearTimeout(hcTimer);
		clearTimeout(hcHideTimer);
		if (hcEl && hcEl.style.display !== 'none') { hcEl.style.display = 'none'; }
	}

	document.addEventListener('mouseover', function (e) {
		if (!e.target || !e.target.closest) { return; }
		var link = e.target.closest('.jt-user-link, .jt-mention');
		if (link) { scheduleShowHoverCard(link, 400); }
	});
	document.addEventListener('mouseout', function (e) {
		if (!e.target || !e.target.closest) { return; }
		if (e.target.closest('.jt-user-link, .jt-mention')) { scheduleHideHoverCard(); }
	});
	// Keyboard: tabbing to a user link opens the card, blurring closes it, so
	// keyboard users get the same info and can dismiss it.
	document.addEventListener('focusin', function (e) {
		if (!e.target || !e.target.closest) { return; }
		var link = e.target.closest('.jt-user-link, .jt-mention');
		if (link) { scheduleShowHoverCard(link, 0); }
	});
	document.addEventListener('focusout', function (e) {
		if (!e.target || !e.target.closest) { return; }
		if (e.target.closest('.jt-user-link, .jt-mention')) { scheduleHideHoverCard(); }
	});
	// Escape dismisses immediately (keyboard) …
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && hcEl && hcEl.style.display !== 'none') { hideHoverCardNow(); }
	});
	// … and a tap/click outside the card or a user link dismisses it, so a
	// touch-opened card is never stuck open (touch fires no mouseout).
	document.addEventListener('pointerdown', function (e) {
		if (!hcEl || hcEl.style.display === 'none') { return; }
		if (!e.target || !e.target.closest) { hideHoverCardNow(); return; }
		if (!e.target.closest('.jt-hover-card, .jt-user-link, .jt-mention')) { hideHoverCardNow(); }
	});
})();
