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

	/* ── Notification Dropdown ── */
	var notifLoaded = false;
	window.jtToggleNotifDropdown = function (btn) {
		var wrap = btn.closest('.jt-notif-dropdown-wrap');
		var panel = wrap.querySelector('.jt-notif-panel');
		var isOpen = !panel.hidden;
		panel.hidden = isOpen;
		if (!isOpen && !notifLoaded) {
			notifLoaded = true;
			fetch(D.restNotif + '?limit=5', {
				headers: { 'X-WP-Nonce': D.nonce }
			}).then(function (r) { return r.json(); }).then(function (resp) {
				var body = panel.querySelector('.jt-notif-panel-body');
				var data = resp.data || resp;
				if (!data || !data.length) {
					body.textContent = D.i18n.noNotifs;
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
					txt.textContent = n.message || '';
					a.appendChild(txt);
					var time = document.createElement('span');
					time.className = 'jt-notif-panel-time';
					time.textContent = n.time_ago || '';
					a.appendChild(time);
					body.appendChild(a);
				});
			}).catch(function () {
				var body = panel.querySelector('.jt-notif-panel-body');
				body.textContent = D.i18n.loadFail;
				body.className = 'jt-notif-panel-body jt-notif-panel-empty';
			});
		}
	};

	window.jtMarkAllRead = function () {
		fetch(D.restMarkRead, {
			method: 'POST', headers: { 'X-WP-Nonce': D.nonce }
		}).then(function () {
			var badge = document.querySelector('.jt-community-nav-badge');
			if (badge) { badge.remove(); }
			document.querySelectorAll('.jt-notif-panel-item.unread').forEach(function (i) {
				i.classList.remove('unread');
			});
		});
	};

	document.addEventListener('click', function (e) {
		if (!e.target || !e.target.closest) { return; }
		var item = e.target.closest('.jt-notif-panel-item.unread');
		if (!item) { return; }
		var id = item.getAttribute('data-jt-notif-id');
		if (!id) { return; }
		item.classList.remove('unread');
		fetch(D.restNotif + '/' + encodeURIComponent(id), {
			method: 'PATCH',
			headers: { 'X-WP-Nonce': D.nonce },
			credentials: 'same-origin'
		}).catch(function () { /* UI already updated */ });
		var badge = document.querySelector('.jt-community-nav-badge');
		if (badge) {
			var next = parseInt(badge.textContent, 10) - 1;
			if (next > 0) { badge.textContent = String(next); } else { badge.remove(); }
		}
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
		input.placeholder = D.i18n.searchPH;
		input.autocomplete = 'off';
		field.appendChild(input);
		var kbd = document.createElement('kbd');
		kbd.className = 'jt-search-overlay-kbd';
		kbd.textContent = 'ESC';
		field.appendChild(kbd);
		inner.appendChild(field);
		var results = document.createElement('div');
		results.className = 'jt-search-overlay-results';
		inner.appendChild(results);
		searchEl.appendChild(inner);
		document.body.appendChild(searchEl);

		input.addEventListener('input', function () {
			clearTimeout(searchTimer);
			var q = input.value.trim();
			if (q.length < 2) { results.textContent = ''; return; }
			searchTimer = setTimeout(function () {
				fetch(D.restSearch + '?q=' + encodeURIComponent(q) + '&per_page=6', {
					headers: { 'X-WP-Nonce': D.nonce }
				}).then(function (r) { return r.json(); }).then(function (data) {
					results.textContent = '';
					if (!data.length) {
						results.textContent = D.i18n.noResults;
						results.className = 'jt-search-overlay-results jt-search-overlay-empty';
						return;
					}
					results.className = 'jt-search-overlay-results';
					data.forEach(function (r) {
						var a = document.createElement('a');
						a.href = r.url;
						a.className = 'jt-search-overlay-item';
						var strong = document.createElement('strong');
						strong.textContent = r.title;
						a.appendChild(strong);
						if (r.space_title) {
							var span = document.createElement('span');
							span.textContent = r.space_title;
							a.appendChild(span);
						}
						results.appendChild(a);
					});
				});
			}, 250);
		});

		searchEl.addEventListener('click', function (e) { if (e.target === searchEl) { window.jtCloseSearch(); } });
		input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { window.jtCloseSearch(); } });
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
	document.addEventListener('keydown', function (e) {
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
			if (focused) { focused.click(); return; }
		}
		if (e.key === '?' && !e.metaKey && !e.ctrlKey) {
			e.preventDefault();
			if (shortcutOpen) { window.jtCloseShortcutHelp(); return; }
			shortcutOpen = true;
			var modal = document.createElement('div');
			modal.className = 'jt-shortcut-help';
			var box = document.createElement('div');
			box.className = 'jt-shortcut-modal';
			var h3 = document.createElement('h3');
			h3.textContent = D.i18n.shortcuts;
			box.appendChild(h3);
			var tbl = document.createElement('table');
			var shortcuts = [
				['/ or Ctrl+K', 'Search'],
				['j / k', 'Navigate up/down'],
				['Enter', 'Open selected'],
				['n', 'Home'],
				['?', 'This help']
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
			closeBtn.textContent = D.i18n.close;
			closeBtn.addEventListener('click', function () { window.jtCloseShortcutHelp(); });
			box.appendChild(closeBtn);
			modal.appendChild(box);
			document.body.appendChild(modal);
			modal.addEventListener('click', function (ev) { if (ev.target === modal) { window.jtCloseShortcutHelp(); } });
		}
	});
	window.jtCloseShortcutHelp = function () {
		shortcutOpen = false;
		var m = document.querySelector('.jt-shortcut-help');
		if (m) { m.remove(); }
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
		fetch(D.restBase + '/users/' + userId, {
			headers: { 'X-WP-Nonce': D.nonce }
		}).then(function (r) { return r.json(); }).then(function (data) {
			hcCache[userId] = data;
			renderHoverCard(card, data, anchor);
		}).catch(function () { card.style.display = 'none'; });
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
		trustEl.textContent = 'Level ' + (data.trust_level || 0) + ' · ' + (data.reputation || 0) + ' rep';
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
		stats.textContent = (data.post_count || 0) + ' posts · ' + (data.reply_count || 0) + ' replies';
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
	document.addEventListener('mouseover', function (e) {
		if (!e.target || !e.target.closest) { return; }
		var link = e.target.closest('.jt-user-link, .jt-mention');
		if (!link) { return; }
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
			fetch(D.restBase + '/users/by-login/' + encodeURIComponent(login), {
				headers: { 'X-WP-Nonce': D.nonce }
			}).then(function (r) { return r.json(); }).then(function (data) {
				if (data.id) {
					hcCache[data.id] = data;
					hcCache['login_' + login] = data.id;
					link.dataset.userId = data.id;
					showHoverCard(link, data.id);
				}
			});
		}, 400);
	});
	document.addEventListener('mouseout', function (e) {
		if (!e.target || !e.target.closest) { return; }
		var link = e.target.closest('.jt-user-link, .jt-mention');
		if (!link) { return; }
		clearTimeout(hcTimer);
		hcHideTimer = setTimeout(function () {
			if (hcEl) { hcEl.style.display = 'none'; }
		}, 200);
	});
})();
