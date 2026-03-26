<?php
/**
 * Jetonomy community sub-navigation.
 *
 * This renders a lightweight nav bar BELOW the theme's header.
 * It does NOT duplicate the theme's logo, search, or user menu.
 * The theme handles all of that.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$current_route = $data['route'] ?? 'home';
$user_id       = get_current_user_id();
$unread        = 0;
if ( $user_id ) {
	$unread = \Jetonomy\Models\Notification::unread_count( $user_id );
}
$base = \Jetonomy\base_url();

/**
 * Filter whether to show the Jetonomy community navigation bar.
 * Themes can disable this if they integrate the nav into their own header.
 *
 * @param bool $show Whether to show the community nav. Default true.
 */
if ( ! apply_filters( 'jetonomy_show_community_nav', true ) ) {
	return;
}
?>
<nav class="jt-community-nav" aria-label="<?php esc_attr_e( 'Community navigation', 'jetonomy' ); ?>">
	<div class="jt-community-nav-inner">
		<div class="jt-community-nav-links">
			<a href="<?php echo esc_url( $base . '/' ); ?>" class="<?php echo 'home' === $current_route ? 'active' : ''; ?>">
				<?php esc_html_e( 'Community', 'jetonomy' ); ?>
			</a>
			<a href="<?php echo esc_url( $base . '/search/' ); ?>" class="<?php echo 'search' === $current_route ? 'active' : ''; ?>">
				<?php esc_html_e( 'Search', 'jetonomy' ); ?>
			</a>
			<a href="<?php echo esc_url( $base . '/leaderboard/' ); ?>" class="<?php echo 'leaderboard' === $current_route ? 'active' : ''; ?>">
				<?php esc_html_e( 'Leaderboard', 'jetonomy' ); ?>
			</a>
			<?php if ( $user_id ) : ?>
				<a href="<?php echo esc_url( $base . '/u/' . wp_get_current_user()->user_login . '/' ); ?>" class="<?php echo 'profile' === $current_route ? 'active' : ''; ?>">
					<?php esc_html_e( 'My Profile', 'jetonomy' ); ?>
				</a>
			<?php endif; ?>
			<?php do_action( 'jetonomy_header_nav_items' ); ?>
		</div>

		<div class="jt-community-nav-actions">
			<?php if ( $user_id ) : ?>
				<div class="jt-notif-dropdown-wrap">
					<button type="button" class="jt-community-nav-notif" aria-label="<?php esc_attr_e( 'Notifications', 'jetonomy' ); ?>" onclick="jtToggleNotifDropdown(this)">
						<?php jetonomy_echo_icon( 'bell', 16 ); ?>
						<?php if ( $unread > 0 ) : ?>
							<span class="jt-community-nav-badge"><?php echo (int) $unread; ?></span>
						<?php endif; ?>
					</button>
					<div class="jt-notif-panel" hidden>
						<div class="jt-notif-panel-head">
							<strong><?php esc_html_e( 'Notifications', 'jetonomy' ); ?></strong>
							<button type="button" class="jt-notif-mark-read" onclick="jtMarkAllRead()"><?php esc_html_e( 'Mark all read', 'jetonomy' ); ?></button>
						</div>
						<div class="jt-notif-panel-body">
							<div class="jt-notif-panel-loading"><?php esc_html_e( 'Loading...', 'jetonomy' ); ?></div>
						</div>
						<a href="<?php echo esc_url( $base . '/notifications/' ); ?>" class="jt-notif-panel-footer">
							<?php esc_html_e( 'View all notifications', 'jetonomy' ); ?>
						</a>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! did_action( 'buddynext_loaded' ) ) : ?>
			<div class="jt-font-scale" role="group" aria-label="<?php esc_attr_e( 'Font size', 'jetonomy' ); ?>">
				<button class="jt-font-scale__btn active" type="button" data-scale="100" onclick="jtSetFontScale('100')" aria-label="<?php esc_attr_e( 'Default font size', 'jetonomy' ); ?>">A</button>
				<button class="jt-font-scale__btn" type="button" data-scale="110" onclick="jtSetFontScale('110')" aria-label="<?php esc_attr_e( 'Large font size', 'jetonomy' ); ?>">A+</button>
				<button class="jt-font-scale__btn" type="button" data-scale="120" onclick="jtSetFontScale('120')" aria-label="<?php esc_attr_e( 'Extra large font size', 'jetonomy' ); ?>">A++</button>
			</div>
			<?php endif; ?>
		</div>
	</div>
</nav>
<!-- Mobile bottom tab bar (visible ≤640px only, hidden when BuddyNext provides its own) -->
<?php if ( ! did_action( 'buddynext_loaded' ) ) : ?>
<nav class="jt-mobile-tabs" aria-label="<?php esc_attr_e( 'Mobile navigation', 'jetonomy' ); ?>">
	<a href="<?php echo esc_url( $base . '/' ); ?>" class="jt-mobile-tab <?php echo 'home' === $current_route ? 'active' : ''; ?>">
		<?php jetonomy_echo_icon( 'home', 20 ); ?>
		<span><?php esc_html_e( 'Home', 'jetonomy' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $base . '/search/' ); ?>" class="jt-mobile-tab <?php echo 'search' === $current_route ? 'active' : ''; ?>">
		<?php jetonomy_echo_icon( 'search', 20 ); ?>
		<span><?php esc_html_e( 'Search', 'jetonomy' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $base . '/leaderboard/' ); ?>" class="jt-mobile-tab <?php echo 'leaderboard' === $current_route ? 'active' : ''; ?>">
		<?php jetonomy_echo_icon( 'award', 20 ); ?>
		<span><?php esc_html_e( 'Ranks', 'jetonomy' ); ?></span>
	</a>
	<?php if ( $user_id ) : ?>
		<a href="<?php echo esc_url( $base . '/notifications/' ); ?>" class="jt-mobile-tab <?php echo 'notifications' === $current_route ? 'active' : ''; ?>">
			<?php jetonomy_echo_icon( 'bell', 20 ); ?>
			<span><?php esc_html_e( 'Alerts', 'jetonomy' ); ?></span>
			<?php if ( $unread > 0 ) : ?>
				<span class="jt-mobile-tab-badge"><?php echo (int) $unread; ?></span>
			<?php endif; ?>
		</a>
		<a href="<?php echo esc_url( $base . '/u/' . wp_get_current_user()->user_login . '/' ); ?>" class="jt-mobile-tab <?php echo 'profile' === $current_route ? 'active' : ''; ?>">
			<?php jetonomy_echo_icon( 'users', 20 ); ?>
			<span><?php esc_html_e( 'Profile', 'jetonomy' ); ?></span>
		</a>
	<?php else : ?>
		<a href="<?php echo esc_url( wp_login_url( $base . '/' ) ); ?>" class="jt-mobile-tab">
			<?php jetonomy_echo_icon( 'users', 20 ); ?>
			<span><?php esc_html_e( 'Login', 'jetonomy' ); ?></span>
		</a>
	<?php endif; ?>
</nav>
<?php endif; ?>

<?php if ( ! did_action( 'buddynext_loaded' ) ) : ?>
<style>
/* Font size control — mirrors BuddyNext A/A+/A++ pattern */
.jt-font-scale {
	display: flex;
	align-items: center;
	gap: 2px;
	background: var(--jt-bg-muted, #f1f1f0);
	border: 1px solid var(--jt-border, #e0e0e0);
	border-radius: 6px;
	padding: 2px;
	margin-left: auto;
}
.jt-font-scale__btn {
	border: none;
	background: transparent;
	border-radius: 4px;
	padding: 3px 8px;
	font-size: 11px;
	font-weight: 600;
	color: var(--jt-text-secondary, #6b7280);
	cursor: pointer;
	white-space: nowrap;
	transition: background 0.12s, color 0.12s;
	line-height: 1.4;
}
.jt-font-scale__btn:hover { color: var(--jt-text, #1a1a1a); }
.jt-font-scale__btn.active {
	background: var(--jt-accent, #3b82f6);
	color: #fff;
}
</style>
<script>
(function () {
	var scales = ['100', '110', '120'];
	function applyScale(s) {
		document.documentElement.setAttribute('data-bn-font-scale', s);
		try { localStorage.setItem('bn_font_scale', s); } catch (e) { /* noop */ }
		var btns = document.querySelectorAll('.jt-font-scale__btn');
		btns.forEach(function (b) { b.classList.toggle('active', b.dataset.scale === s); });
	}
	var saved = '100';
	try { saved = localStorage.getItem('bn_font_scale') || '100'; } catch (e) { /* noop */ }
	if (scales.indexOf(saved) === -1) { saved = '100'; }
	applyScale(saved);
	window.jtSetFontScale = function (s) { if (scales.indexOf(s) !== -1) { applyScale(s); } };
})();
</script>
<?php endif; ?>

<?php
// Data for JS — all escaped server-side via wp_json_encode.
$jt_js_data = [
	'base'          => $base,
	'nonce'         => wp_create_nonce( 'wp_rest' ),
	'isLoggedIn'    => (bool) $user_id,
	'restNotif'     => rest_url( 'jetonomy/v1/notifications' ),
	'restMarkRead'  => rest_url( 'jetonomy/v1/notifications/mark-all-read' ),
	'restSearch'    => rest_url( 'jetonomy/v1/search' ),
	'searchIcon'    => jetonomy_icon( 'search', 20 ),
	'i18n'          => [
		'noNotifs'    => __( 'No notifications yet.', 'jetonomy' ),
		'noResults'   => __( 'No results found.', 'jetonomy' ),
		'searchPH'    => __( 'Search discussions...', 'jetonomy' ),
		'shortcuts'   => __( 'Keyboard Shortcuts', 'jetonomy' ),
		'close'       => __( 'Close', 'jetonomy' ),
		'loadFail'    => __( 'Failed to load', 'jetonomy' ),
	],
];
?>
<script>
(function() {
	var D = <?php echo wp_json_encode( $jt_js_data ); ?>;

	/* ── Toast helper (standalone fallback — BuddyNext provides its own) ── */
	if (!window.bnToast) {
		window.bnToast = function(msg) {
			var t = document.createElement('div');
			t.className = 'jt-toast';
			t.textContent = msg;
			document.body.appendChild(t);
			setTimeout(function() { t.classList.add('show'); }, 10);
			setTimeout(function() { t.classList.remove('show'); setTimeout(function() { t.remove(); }, 300); }, 3000);
		};
	}

	/* ── Notification Dropdown ── */
	var notifLoaded = false;
	window.jtToggleNotifDropdown = function(btn) {
		var wrap = btn.closest('.jt-notif-dropdown-wrap');
		var panel = wrap.querySelector('.jt-notif-panel');
		var isOpen = !panel.hidden;
		panel.hidden = isOpen;
		if (!isOpen && !notifLoaded) {
			notifLoaded = true;
			fetch(D.restNotif + '?limit=5', {
				headers: { 'X-WP-Nonce': D.nonce }
			}).then(function(r) { return r.json(); }).then(function(resp) {
				var body = panel.querySelector('.jt-notif-panel-body');
				var data = resp.data || resp;
				if (!data || !data.length) {
					body.textContent = D.i18n.noNotifs;
					body.className = 'jt-notif-panel-body jt-notif-panel-empty';
					return;
				}
				body.textContent = '';
				data.forEach(function(n) {
					var a = document.createElement('a');
					a.href = n.object_url || n.url || (D.base + '/notifications/');
					a.className = 'jt-notif-panel-item' + (n.is_read ? '' : ' unread');
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
			}).catch(function() {
				var body = panel.querySelector('.jt-notif-panel-body');
				body.textContent = D.i18n.loadFail;
				body.className = 'jt-notif-panel-body jt-notif-panel-empty';
			});
		}
	};
	window.jtMarkAllRead = function() {
		fetch(D.restMarkRead, {
			method: 'POST', headers: { 'X-WP-Nonce': D.nonce }
		}).then(function() {
			var badge = document.querySelector('.jt-community-nav-badge');
			if (badge) badge.remove();
			document.querySelectorAll('.jt-notif-panel-item.unread').forEach(function(i) {
				i.classList.remove('unread');
			});
		});
	};
	/* Close dropdown on outside click */
	document.addEventListener('click', function(e) {
		if (!e.target || !e.target.closest) return;
		if (!e.target.closest('.jt-notif-dropdown-wrap')) {
			var panel = document.querySelector('.jt-notif-panel');
			if (panel) panel.hidden = true;
		}
	});

	/* ── Search Overlay ── */
	var searchEl = null, searchTimer = null;
	function buildSearchOverlay() {
		if (searchEl) return searchEl;
		searchEl = document.createElement('div');
		searchEl.className = 'jt-search-overlay';
		var inner = document.createElement('div');
		inner.className = 'jt-search-overlay-inner';
		var field = document.createElement('div');
		field.className = 'jt-search-overlay-field';
		var iconSpan = document.createElement('span');
		iconSpan.className = 'jt-search-overlay-icon';
		iconSpan.innerHTML = D.searchIcon; /* Trusted SVG from server */
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

		input.addEventListener('input', function() {
			clearTimeout(searchTimer);
			var q = input.value.trim();
			if (q.length < 2) { results.textContent = ''; return; }
			searchTimer = setTimeout(function() {
				fetch(D.restSearch + '?q=' + encodeURIComponent(q) + '&per_page=6', {
					headers: { 'X-WP-Nonce': D.nonce }
				}).then(function(r) { return r.json(); }).then(function(data) {
					results.textContent = '';
					if (!data.length) { results.textContent = D.i18n.noResults; results.className = 'jt-search-overlay-results jt-search-overlay-empty'; return; }
					results.className = 'jt-search-overlay-results';
					data.forEach(function(r) {
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

		searchEl.addEventListener('click', function(e) { if (e.target === searchEl) jtCloseSearch(); });
		input.addEventListener('keydown', function(e) { if (e.key === 'Escape') jtCloseSearch(); });
		return searchEl;
	}
	window.jtOpenSearch = function() {
		var el = buildSearchOverlay();
		el.classList.add('open');
		var inp = el.querySelector('.jt-search-overlay-input');
		inp.value = '';
		el.querySelector('.jt-search-overlay-results').textContent = '';
		setTimeout(function() { inp.focus(); }, 50);
	};
	window.jtCloseSearch = function() {
		if (searchEl) searchEl.classList.remove('open');
	};

	/* ── Keyboard Shortcuts ── */
	var shortcutOpen = false;
	document.addEventListener('keydown', function(e) {
		var tag = (e.target.tagName || '').toLowerCase();
		if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) return;

		if ((e.metaKey || e.ctrlKey) && e.key === 'k') { e.preventDefault(); jtOpenSearch(); return; }
		if (e.key === '/' && !e.metaKey && !e.ctrlKey) { e.preventDefault(); jtOpenSearch(); return; }
		if (e.key === 'n' && !e.metaKey && !e.ctrlKey && D.isLoggedIn) { window.location.href = D.base + '/'; return; }

		if (e.key === 'j' || e.key === 'k') {
			var rows = Array.from(document.querySelectorAll('.jt-row, a.jt-row'));
			if (!rows.length) return;
			var cur = document.querySelector('.jt-row.jt-kb-focus, a.jt-row.jt-kb-focus');
			var idx = cur ? rows.indexOf(cur) : -1;
			if (cur) cur.classList.remove('jt-kb-focus');
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
			if (shortcutOpen) { jtCloseShortcutHelp(); return; }
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
			shortcuts.forEach(function(s) {
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
			closeBtn.addEventListener('click', function() { jtCloseShortcutHelp(); });
			box.appendChild(closeBtn);
			modal.appendChild(box);
			document.body.appendChild(modal);
			modal.addEventListener('click', function(ev) { if (ev.target === modal) jtCloseShortcutHelp(); });
		}
	});
	window.jtCloseShortcutHelp = function() {
		shortcutOpen = false;
		var m = document.querySelector('.jt-shortcut-help');
		if (m) m.remove();
	};

	/* ── User Hover Cards ── */
	var hcCache = {};
	var hcEl = null;
	var hcTimer = null;
	var hcHideTimer = null;
	function getHoverCard() {
		if (hcEl) return hcEl;
		hcEl = document.createElement('div');
		hcEl.className = 'jt-hover-card';
		hcEl.style.display = 'none';
		hcEl.addEventListener('mouseenter', function() { clearTimeout(hcHideTimer); });
		hcEl.addEventListener('mouseleave', function() { hcEl.style.display = 'none'; });
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
		fetch(D.base.replace(/\/community\/?$/, '') + '/wp-json/jetonomy/v1/users/' + userId, {
			headers: { 'X-WP-Nonce': D.nonce }
		}).then(function(r) { return r.json(); }).then(function(data) {
			hcCache[userId] = data;
			renderHoverCard(card, data, anchor);
		}).catch(function() { card.style.display = 'none'; });
	}
	function renderHoverCard(card, data, anchor) {
		card.textContent = '';
		var header = document.createElement('div');
		header.className = 'jt-hc-header';
		if (data.avatar_url) {
			var img = document.createElement('img');
			img.src = data.avatar_url;
			img.width = 40; img.height = 40;
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
	document.addEventListener('mouseover', function(e) {
		if (!e.target || !e.target.closest) return;
		var link = e.target.closest('.jt-user-link, .jt-mention');
		if (!link) return;
		var userId = link.dataset.userId;
		if (!userId) {
			var href = link.getAttribute('href') || '';
			var m = href.match(/\/u\/([^/]+)/);
			if (!m) return;
			link.dataset.userLogin = m[1];
		}
		clearTimeout(hcHideTimer);
		clearTimeout(hcTimer);
		hcTimer = setTimeout(function() {
			if (userId) { showHoverCard(link, userId); return; }
			var login = link.dataset.userLogin;
			if (!login) return;
			var cachedId = hcCache['login_' + login];
			if (cachedId) { showHoverCard(link, cachedId); return; }
			fetch(D.base.replace(/\/community\/?$/, '') + '/wp-json/jetonomy/v1/users/by-login/' + encodeURIComponent(login), {
				headers: { 'X-WP-Nonce': D.nonce }
			}).then(function(r) { return r.json(); }).then(function(data) {
				if (data.id) {
					hcCache[data.id] = data;
					hcCache['login_' + login] = data.id;
					link.dataset.userId = data.id;
					showHoverCard(link, data.id);
				}
			});
		}, 400);
	});
	document.addEventListener('mouseout', function(e) {
		if (!e.target || !e.target.closest) return;
		var link = e.target.closest('.jt-user-link, .jt-mention');
		if (!link) return;
		clearTimeout(hcTimer);
		hcHideTimer = setTimeout(function() {
			if (hcEl) hcEl.style.display = 'none';
		}, 200);
	});
})();
</script>
<?php
