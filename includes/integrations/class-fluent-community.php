<?php
/**
 * FluentCommunity integration.
 *
 * Navigational + add-only sync bridge between Jetonomy and FluentCommunity
 * (FC) so both plugins feel like one product when installed together.
 *
 * Design notes:
 * - Loads only when FluentCommunity is active (class_exists gate).
 * - Keys everything on user_id. FC `xprofile.username` and WP `user_login`
 *   can diverge (e.g. this dev site has `admin` vs `admin2`) so username
 *   is never used as a join key.
 * - Member sync is add-only. Joins propagate both ways; leaves do NOT.
 *   This avoids the "leave one side, silently lose the other" footgun.
 *   Members must leave each side explicitly.
 * - Loop prevention: a static $syncing flag is set while we cross-call
 *   the other plugin's join API, so the join hook on that side sees it
 *   and no-ops.
 * - Activity broadcast is one-way: new Jetonomy topics post an
 *   announcement feed item in the paired FC space. FC feeds do NOT
 *   auto-create Jetonomy topics (too invasive; JT topics have structured
 *   metadata FC feed posts don't carry).
 * - Writes to FC tables happen only through FC's own `Helper::addToSpace`
 *   helper and the public `Feed` model, not direct SQL. Deactivating FC
 *   leaves Jetonomy untouched.
 * - Stale pair handling: at render time and at sync time, if either side
 *   of a pair no longer resolves, the action silently skips. No admin
 *   cleanup required.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Integrations;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;

/**
 * FluentCommunity integration.
 */
class Fluent_Community {

	/**
	 * Option key storing the FC space ID -> Jetonomy space ID map.
	 */
	const OPT_PAIRS = 'jetonomy_fc_space_pairs';

	/**
	 * Option key storing the tab label used on both sides.
	 */
	const OPT_LABEL = 'jetonomy_fc_tab_label';

	/**
	 * Option key: enable member sync on join events (both directions).
	 * Values: '1' (on, default), '0' (off).
	 */
	const OPT_SYNC_MEMBERS = 'jetonomy_fc_sync_members';

	/**
	 * Option key: broadcast new Jetonomy topics to the paired FC space feed.
	 * Values: '1' (on, default), '0' (off).
	 */
	const OPT_BROADCAST = 'jetonomy_fc_broadcast';

	/**
	 * Loop-prevention flag set while we cross-call the other plugin's
	 * join API so the reciprocal hook no-ops on the return trip.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Runtime cache of the pair map, keyed by FC space ID (int).
	 *
	 * @var array<int,int>|null
	 */
	private ?array $pair_map = null;

	public function __construct() {
		// Avatar: use FC's xprofile avatar everywhere if set.
		add_filter( 'get_avatar_url', array( $this, 'filter_avatar_url' ), 20, 2 );

		// Admin settings tab (sidebar link + body), plus its save handler.
		add_action( 'jetonomy_admin_settings_tabs', array( $this, 'register_settings_tab' ) );
		add_action( 'jetonomy_admin_settings_tab_content', array( $this, 'render_settings_tab' ) );
		add_action( 'admin_post_jetonomy_fc_save', array( $this, 'handle_settings_save' ) );

		// FC space header: append a tab linking to the paired Jetonomy space.
		add_filter( 'fluent_community/space_header_links', array( $this, 'filter_fc_space_header_links' ), 20, 2 );

		// Jetonomy space sidebar: render a card linking to the paired FC space.
		// `jetonomy_sidebar_after_about` fires inside the sidebar About card on
		// space views and receives the space object (or null on non-space pages).
		add_action( 'jetonomy_sidebar_after_about', array( $this, 'render_sidebar_fc_link' ) );

		// FC profile page: append a Discussions block to the user's activity view
		// showing topics started + topics followed on the Jetonomy side.
		add_filter( 'fluent_community/activity/after_contents_user', array( $this, 'filter_fc_profile_after_contents_user' ), 20, 3 );

		// Jetonomy profile page: render a "View on FluentCommunity" link so
		// members can jump from the forum profile to their FC profile.
		add_action( 'jetonomy_profile_after_stats', array( $this, 'render_jt_profile_fc_link' ) );

		// Member sync (add-only, both directions). Idempotent at both
		// sides' `::add` helpers; loop-prevented by self::$syncing.
		if ( $this->member_sync_enabled() ) {
			add_action( 'fluent_community/space/joined', array( $this, 'on_fc_space_joined' ), 20, 2 );
			add_action( 'jetonomy_user_joined_space', array( $this, 'on_jt_space_joined' ), 20, 3 );
		}

		// Activity broadcast — new Jetonomy topic posts an announcement
		// in the paired FC space's feed.
		if ( $this->broadcast_enabled() ) {
			add_action( 'jetonomy_after_create_post', array( $this, 'on_jt_post_created' ), 20, 3 );
		}
	}

	/**
	 * Whether member sync is enabled. Defaults to on.
	 */
	public function member_sync_enabled(): bool {
		return '0' !== (string) get_option( self::OPT_SYNC_MEMBERS, '1' );
	}

	/**
	 * Whether activity broadcast is enabled. Defaults to on.
	 */
	public function broadcast_enabled(): bool {
		return '0' !== (string) get_option( self::OPT_BROADCAST, '1' );
	}

	/**
	 * FC join event handler: mirror the join into the paired Jetonomy space.
	 *
	 * Signature per FC: do_action('fluent_community/space/joined', $space, $userId, $by, $created?).
	 *
	 * @param mixed $space   FC space model.
	 * @param mixed $user_id User ID.
	 */
	public function on_fc_space_joined( $space, $user_id ): void {
		if ( self::$syncing ) {
			return;
		}
		if ( ! is_object( $space ) || empty( $space->id ) ) {
			return;
		}
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		$pairs = $this->get_pair_map();
		$jt_id = $pairs[ (int) $space->id ] ?? 0;
		if ( $jt_id <= 0 ) {
			return;
		}

		$jt_space = Space::find( $jt_id );
		if ( ! $jt_space ) {
			return;
		}

		// Idempotent: skip if already a member.
		if ( SpaceMember::is_member( $jt_id, $user_id ) ) {
			return;
		}

		self::$syncing = true;
		SpaceMember::add( $jt_id, $user_id );
		self::$syncing = false;
	}

	/**
	 * Jetonomy join event handler: mirror the join into the paired FC space.
	 *
	 * Signature per Jetonomy: do_action('jetonomy_user_joined_space', $space_id, $user_id, $role).
	 *
	 * @param int|mixed $space_id Jetonomy space ID.
	 * @param int|mixed $user_id  User ID.
	 * @param mixed     $role     Role assigned on the Jetonomy side (unused here; FC adds as 'member').
	 */
	public function on_jt_space_joined( $space_id, $user_id, $role ): void {
		unset( $role );
		if ( self::$syncing ) {
			return;
		}
		$space_id = (int) $space_id;
		$user_id  = (int) $user_id;
		if ( $space_id <= 0 || $user_id <= 0 ) {
			return;
		}

		$fc_id = $this->fc_id_for_jt_id( $space_id );
		if ( $fc_id <= 0 ) {
			return;
		}

		// FC space must exist. Use a lightweight DB check (no autoload dance).
		if ( ! $this->fc_space_by_id( $fc_id ) ) {
			return;
		}

		$helper = '\\FluentCommunity\\App\\Services\\Helper';
		if ( ! class_exists( $helper ) || ! is_callable( array( $helper, 'addToSpace' ) ) ) {
			return;
		}

		self::$syncing = true;
		// FC's addToSpace is idempotent and fires `fluent_community/space/joined`
		// internally if it actually enrolls the user. The self::$syncing guard
		// above keeps that fired hook from bouncing back.
		call_user_func( array( $helper, 'addToSpace' ), $fc_id, $user_id, 'member', 'jetonomy_sync' );
		self::$syncing = false;
	}

	/**
	 * Jetonomy post-created handler: broadcast an announcement feed in
	 * the paired FC space.
	 *
	 * @param int|mixed $post_id  New Jetonomy post ID.
	 * @param int|mixed $space_id Space the post was created in.
	 * @param mixed     $request  Unused request context.
	 */
	public function on_jt_post_created( $post_id, $space_id, $request ): void {
		unset( $request );
		$post_id  = (int) $post_id;
		$space_id = (int) $space_id;
		if ( $post_id <= 0 || $space_id <= 0 ) {
			return;
		}

		$fc_id = $this->fc_id_for_jt_id( $space_id );
		if ( $fc_id <= 0 ) {
			return;
		}

		$post = Post::find( $post_id );
		if ( ! $post || empty( $post->slug ) || empty( $post->title ) ) {
			return;
		}
		if ( isset( $post->status ) && 'publish' !== $post->status ) {
			return;
		}

		$jt_space = Space::find( $space_id );
		if ( ! $jt_space || empty( $jt_space->slug ) ) {
			return;
		}

		// FC space must exist.
		if ( ! $this->fc_space_by_id( $fc_id ) ) {
			return;
		}

		$base      = $this->jetonomy_base_slug();
		$topic_url = home_url( '/' . $base . '/s/' . $jt_space->slug . '/t/' . $post->slug . '/' );

		// Generous excerpt — we want the FC feed post to read as a
		// standalone preview, not a bait-and-switch that forces a click.
		$excerpt = '';
		if ( ! empty( $post->content ) ) {
			// Preserve paragraph breaks so the rendered version keeps its shape.
			$clean   = wp_strip_all_tags( (string) $post->content );
			$excerpt = trim( preg_replace( '/[ \t]+/', ' ', $clean ) );
		}

		// Plain-text message: used by FC for search, activity log, email
		// digests. No title — FC renders $feed->title as the post heading.
		$message = $excerpt ? $excerpt . "\n\n" . $topic_url : $topic_url;

		// Rendered HTML body. Excerpt first, then a discreet attribution
		// line at the end that reads as a byline rather than a hard CTA.
		$rendered = '';
		if ( $excerpt ) {
			// Split on double newlines to preserve paragraph structure.
			$paras = preg_split( '/\n{2,}/', $excerpt );
			foreach ( $paras as $para ) {
				$para = trim( $para );
				if ( '' !== $para ) {
					$rendered .= '<p>' . esc_html( $para ) . '</p>';
				}
			}
		}
		$rendered .= '<p style="margin-top:16px;color:var(--fcom-text-2,#6b7280);font-size:14px;">';
		$rendered .= esc_html__( 'Shared from the forum', 'jetonomy' ) . ' &middot; ';
		$rendered .= '<a href="' . esc_url( $topic_url ) . '" rel="noopener" style="color:inherit;text-decoration:underline;">';
		$rendered .= esc_html__( 'View discussion', 'jetonomy' );
		$rendered .= '</a></p>';

		$feed_class = '\\FluentCommunity\\App\\Models\\Feed';
		if ( ! class_exists( $feed_class ) ) {
			return;
		}

		$author_id = isset( $post->author_id ) ? (int) $post->author_id : 0;

		// Feed::$scopeType = 'text' — FC's Feed model applies a global
		// scope filtering all queries to type='text', so every visible
		// feed row must use that value (confirmed at
		// app/Http/Controllers/FeedsController.php line 829 where FC's
		// own store() sets $data['type'] = 'text').
		$feed                   = new $feed_class();
		$feed->user_id          = $author_id;
		$feed->space_id         = $fc_id;
		$feed->type             = 'text';
		$feed->content_type     = 'text';
		$feed->title            = (string) $post->title;
		$feed->message          = $message;
		$feed->message_rendered = $rendered;
		$feed->status           = 'published';
		$feed->privacy          = 'public';
		$feed->meta             = wp_json_encode(
			array(
				'jetonomy_post_id' => $post_id,
				'source'           => 'jetonomy',
			)
		);
		try {
			$feed->save();
		} catch ( \Throwable $e ) {
			return;
		}

		// Fire FC's downstream hooks so notifications / activity log / search
		// pick the row up just as if a user had posted it via FC's UI.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- FC hook names, not ours.
		do_action( 'fluent_community/feed/created', $feed );
		do_action( 'fluent_community/space_feed/created', $feed );
		// phpcs:enable
	}

	/**
	 * Reverse-lookup: Jetonomy space ID -> FC space ID.
	 *
	 * @param int $jt_id Jetonomy space ID.
	 * @return int FC space ID, or 0 if not paired.
	 */
	private function fc_id_for_jt_id( int $jt_id ): int {
		if ( $jt_id <= 0 ) {
			return 0;
		}
		foreach ( $this->get_pair_map() as $fc_id => $jt_sid ) {
			if ( (int) $jt_sid === $jt_id ) {
				return (int) $fc_id;
			}
		}
		return 0;
	}

	/**
	 * One-click backfill: enroll each side's existing members into the paired side.
	 *
	 * Capped at 5000 members per pair per run to keep synchronous request
	 * times bounded. Admins can re-run to continue.
	 *
	 * @return array{pairs:int, added_to_jt:int, added_to_fc:int, capped:bool}
	 */
	public function run_backfill(): array {
		$stats = array(
			'pairs'       => 0,
			'added_to_jt' => 0,
			'added_to_fc' => 0,
			'capped'      => false,
		);
		$pairs = $this->get_pair_map();
		if ( empty( $pairs ) ) {
			return $stats;
		}

		global $wpdb;
		$cap    = 5000;
		$helper = '\\FluentCommunity\\App\\Services\\Helper';

		foreach ( $pairs as $fc_id => $jt_id ) {
			$fc_id = (int) $fc_id;
			$jt_id = (int) $jt_id;
			if ( $fc_id <= 0 || $jt_id <= 0 ) {
				continue;
			}
			if ( ! Space::find( $jt_id ) || ! $this->fc_space_by_id( $fc_id ) ) {
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$fc_members = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->prefix}fcom_space_user WHERE space_id = %d AND status = 'active' LIMIT %d",
					$fc_id,
					$cap + 1
				)
			);
			$jt_members = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->prefix}jt_space_members WHERE space_id = %d LIMIT %d",
					$jt_id,
					$cap + 1
				)
			);
			// phpcs:enable

			if ( count( $fc_members ) > $cap || count( $jt_members ) > $cap ) {
				$stats['capped'] = true;
			}
			$fc_members = array_slice( array_map( 'intval', (array) $fc_members ), 0, $cap );
			$jt_members = array_slice( array_map( 'intval', (array) $jt_members ), 0, $cap );

			$only_in_fc = array_diff( $fc_members, $jt_members );
			$only_in_jt = array_diff( $jt_members, $fc_members );

			self::$syncing = true;
			foreach ( $only_in_fc as $uid ) {
				if ( $uid > 0 && ! SpaceMember::is_member( $jt_id, $uid ) ) {
					SpaceMember::add( $jt_id, $uid );
					++$stats['added_to_jt'];
				}
			}
			if ( class_exists( $helper ) && is_callable( array( $helper, 'addToSpace' ) ) ) {
				foreach ( $only_in_jt as $uid ) {
					if ( $uid > 0 ) {
						call_user_func( array( $helper, 'addToSpace' ), $fc_id, $uid, 'member', 'backfill' );
						++$stats['added_to_fc'];
					}
				}
			}
			self::$syncing = false;

			++$stats['pairs'];
		}
		return $stats;
	}

	/**
	 * Render a link on the Jetonomy profile page pointing to the user's FC profile.
	 *
	 * Resolved by user_id -> xprofile.username. Silently skips when the user
	 * has no FC xprofile row (keeps existing Jetonomy-only users undisturbed).
	 *
	 * @param int $profile_user_id Target user ID.
	 */
	public function render_jt_profile_fc_link( $profile_user_id ): void {
		$profile_user_id = (int) $profile_user_id;
		if ( $profile_user_id <= 0 ) {
			return;
		}
		$fc_username = $this->fc_username_for_user( $profile_user_id );
		if ( ! $fc_username ) {
			return;
		}
		$fc_url = home_url( '/portal/u/' . $fc_username . '/' );
		?>
		<p class="jt-fc-profile-cta" style="margin:12px 0 0;">
			<a href="<?php echo esc_url( $fc_url ); ?>" class="jt-btn jt-btn-sm jt-btn-ghost">
				<?php esc_html_e( 'View on FluentCommunity', 'jetonomy' ); ?> &rarr;
			</a>
		</p>
		<?php
	}

	/**
	 * Look up an FC xprofile username by WP user ID.
	 *
	 * Request-scoped cache to avoid repeated queries when the same user's
	 * profile block renders alongside other integration surfaces.
	 *
	 * @param int $user_id WP user ID.
	 * @return string|null Username or null if user has no FC xprofile.
	 */
	private function fc_username_for_user( int $user_id ): ?string {
		static $cache = array();
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $user_id, $cache ) ) {
			return $cache[ $user_id ];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$username          = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT username FROM {$wpdb->prefix}fcom_xprofile WHERE user_id = %d AND status = 'active' LIMIT 1",
				$user_id
			)
		);
		$username          = is_string( $username ) && '' !== $username ? $username : null;
		$cache[ $user_id ] = $username;
		return $username;
	}

	/**
	 * Append a "Discussions" tab to a paired FC space's header.
	 *
	 * Called during FC's REST response assembly (BaseSpace::getHeaderLinks).
	 * The tab carries both a plain `url` for static render paths and a
	 * null `route` so FC's Vue tab component can distinguish an external
	 * link from an internal SPA route.
	 *
	 * Stale pair handling: if the paired Jetonomy space no longer resolves,
	 * the tab is not appended.
	 *
	 * @param array $links Existing header link entries.
	 * @param mixed $space FC space model instance (has id and slug).
	 * @return array
	 */
	public function filter_fc_space_header_links( $links, $space ): array {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		if ( ! is_object( $space ) || empty( $space->id ) ) {
			return $links;
		}
		$pairs = $this->get_pair_map();
		$jt_id = $pairs[ (int) $space->id ] ?? 0;
		if ( $jt_id <= 0 ) {
			return $links;
		}
		$jt_space = Space::find( $jt_id );
		if ( ! $jt_space || empty( $jt_space->slug ) ) {
			return $links;
		}

		$base    = $this->jetonomy_base_slug();
		$url     = home_url( '/' . $base . '/s/' . $jt_space->slug . '/' );
		$links[] = array(
			'title'    => $this->get_tab_label(),
			'url'      => esc_url_raw( $url ),
			'external' => true,
			'icon'     => 'fcom-icon-chat',
			'route'    => null,
		);
		return $links;
	}

	/**
	 * Render a sidebar card on a Jetonomy space view linking to the paired FC space.
	 *
	 * Stale pair handling: if the paired FC space no longer resolves, no
	 * card renders. If the current context is not a space view ($space is
	 * null), nothing renders either.
	 *
	 * @param mixed $space Jetonomy space object (or null if not on a space view).
	 */
	public function render_sidebar_fc_link( $space ): void {
		if ( ! is_object( $space ) || empty( $space->id ) ) {
			return;
		}
		// Reverse-lookup Jetonomy space ID -> FC space ID.
		$pairs = $this->get_pair_map();
		$fc_id = 0;
		foreach ( $pairs as $fc_space_id => $jt_space_id ) {
			if ( (int) $jt_space_id === (int) $space->id ) {
				$fc_id = (int) $fc_space_id;
				break;
			}
		}
		if ( $fc_id <= 0 ) {
			return;
		}
		$fc_space = $this->fc_space_by_id( $fc_id );
		if ( ! $fc_space ) {
			return;
		}

		$fc_url     = home_url( '/portal/space/' . $fc_space->slug );
		$bn_active  = did_action( 'buddynext_loaded' );
		$wrap_class = $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md';
		$head_class = $bn_active ? 'bn-sidebar-card__header' : '';
		$body_class = $bn_active ? 'bn-sidebar-card__body' : '';
		$head_label = esc_html__( 'Also on FluentCommunity', 'jetonomy' );
		?>
		<div class="<?php echo esc_attr( $wrap_class ); ?>">
			<div class="<?php echo esc_attr( $head_class ); ?>">
				<?php if ( ! $bn_active ) : ?>
					<h4><?php echo esc_html( $head_label ); ?></h4>
				<?php else : ?>
					<?php echo esc_html( $head_label ); ?>
				<?php endif; ?>
			</div>
			<div class="<?php echo esc_attr( $body_class ); ?>">
				<p class="jt-fc-side-desc" style="margin:0 0 12px;">
					<strong><?php echo esc_html( $fc_space->title ); ?></strong><br>
					<?php esc_html_e( 'This space has a matching feed on FluentCommunity.', 'jetonomy' ); ?>
				</p>
				<a href="<?php echo esc_url( $fc_url ); ?>" class="jt-btn jt-btn-sm jt-btn-fill" style="width:100%;text-align:center;">
					<?php esc_html_e( 'Open Feed', 'jetonomy' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Append a "Discussions" block to FC's user-activity view with
	 * topics the user started and topics they follow on Jetonomy.
	 *
	 * The filter signature (verified at `app/Http/Controllers/ActivityController.php:97`):
	 *   apply_filters( 'fluent_community/activity/after_contents_user', '', $userId, $context );
	 *
	 * @param string $html    Upstream HTML (usually empty).
	 * @param mixed  $user_id Target user ID.
	 * @param mixed  $context FC-supplied context (unused here).
	 * @return string
	 */
	public function filter_fc_profile_after_contents_user( $html, $user_id, $context ): string {
		$html    = (string) $html;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return $html;
		}

		$started  = Post::list_by_author( $user_id, 5, 0 );
		$followed = $this->list_followed_posts( $user_id, 5 );

		if ( empty( $started ) && empty( $followed ) ) {
			return $html;
		}

		$label       = $this->get_tab_label();
		$base        = $this->jetonomy_base_slug();
		$user        = get_userdata( $user_id );
		$profile_url = $user ? home_url( '/' . $base . '/u/' . $user->user_login . '/' ) : '';

		ob_start();
		?>
		<style>
			.jt-fc-profile-disc { margin-top:24px;padding:16px;background:#fff;border:1px solid #e5e7eb;border-radius:8px; }
			.jt-fc-profile-disc h3 { margin:0 0 12px;font-size:16px;font-weight:600; }
			.jt-fc-profile-disc h4 { margin:12px 0 6px;font-size:13px;font-weight:600;color:#4b5563;text-transform:uppercase;letter-spacing:0.04em; }
			.jt-fc-profile-disc ul { margin:0 0 8px;padding:0;list-style:none; }
			.jt-fc-profile-disc li { padding:6px 0;border-bottom:1px solid #f3f4f6; }
			.jt-fc-profile-disc a { color:inherit;text-decoration:none; }
			.jt-fc-profile-disc .jt-fc-viewall { margin:12px 0 0;font-size:13px; }
			@media (max-width: 640px) {
				.jt-fc-profile-disc { padding:12px; }
				.jt-fc-profile-disc h3 { font-size:15px; }
			}
		</style>
		<div class="jt-fc-profile-disc">
			<h3><?php echo esc_html( $label ); ?></h3>

			<?php if ( ! empty( $started ) ) : ?>
				<h4><?php esc_html_e( 'Topics started', 'jetonomy' ); ?></h4>
				<ul>
					<?php foreach ( $started as $p ) : ?>
						<?php
						$space = isset( $p->space_id ) ? Space::find( (int) $p->space_id ) : null;
						$purl  = $space ? home_url( '/' . $base . '/s/' . $space->slug . '/t/' . $p->slug . '/' ) : '';
						?>
						<li>
							<?php if ( $purl ) : ?>
								<a href="<?php echo esc_url( $purl ); ?>"><?php echo esc_html( $p->title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $p->title ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $followed ) ) : ?>
				<h4><?php esc_html_e( 'Topics followed', 'jetonomy' ); ?></h4>
				<ul>
					<?php foreach ( $followed as $p ) : ?>
						<?php
						$space = isset( $p->space_id ) ? Space::find( (int) $p->space_id ) : null;
						$purl  = $space ? home_url( '/' . $base . '/s/' . $space->slug . '/t/' . $p->slug . '/' ) : '';
						?>
						<li>
							<?php if ( $purl ) : ?>
								<a href="<?php echo esc_url( $purl ); ?>"><?php echo esc_html( $p->title ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $p->title ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( $profile_url ) : ?>
				<p class="jt-fc-viewall">
					<a href="<?php echo esc_url( $profile_url ); ?>">
						<?php esc_html_e( 'View all on forum', 'jetonomy' ); ?> &rarr;
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return $html . (string) ob_get_clean();
	}

	/**
	 * List the most-recently-followed Jetonomy posts for a user.
	 *
	 * Uses a direct JOIN between subscriptions and posts — crosses two
	 * models so it lives here rather than behind a single-model helper.
	 *
	 * @param int $user_id Target user.
	 * @param int $limit   Max rows (clamped to 20).
	 * @return object[]
	 */
	private function list_followed_posts( int $user_id, int $limit = 5 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 20, $limit ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.title, p.slug, p.space_id
				 FROM {$wpdb->prefix}jt_subscriptions s
				 INNER JOIN {$wpdb->prefix}jt_posts p ON s.object_id = p.id
				 WHERE s.user_id = %d
				   AND s.object_type = 'post'
				   AND p.status = 'publish'
				 ORDER BY s.created_at DESC
				 LIMIT %d",
				$user_id,
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch a minimal FC space by ID (id, slug, title). Returns null when missing.
	 *
	 * @param int $id FC space ID.
	 * @return object|null
	 */
	private function fc_space_by_id( int $id ): ?object {
		if ( $id <= 0 ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, slug, title FROM {$wpdb->prefix}fcom_spaces WHERE id = %d AND status = 'published' LIMIT 1",
				$id
			)
		);
		return $row ?: null;
	}

	/**
	 * Resolve the Jetonomy base URL slug (defaults to "community").
	 *
	 * @return string
	 */
	private function jetonomy_base_slug(): string {
		$settings = get_option( 'jetonomy_settings', array() );
		$slug     = is_array( $settings ) && ! empty( $settings['base_slug'] ) ? (string) $settings['base_slug'] : 'community';
		return trim( $slug, '/' );
	}

	/**
	 * Current configured tab label (defaults to "Discussions").
	 *
	 * @return string
	 */
	public function get_tab_label(): string {
		$label = get_option( self::OPT_LABEL, '' );
		$label = is_string( $label ) ? trim( $label ) : '';
		return '' !== $label ? $label : __( 'Discussions', 'jetonomy' );
	}

	/**
	 * Add the FluentCommunity tab to the Jetonomy settings sidebar.
	 *
	 * @param string $active_tab Currently active tab slug.
	 */
	public function register_settings_tab( $active_tab ): void {
		$url       = add_query_arg(
			array(
				'page' => 'jetonomy-settings',
				'tab'  => 'fluent-community',
			),
			admin_url( 'admin.php' )
		);
		$is_active = 'fluent-community' === $active_tab;
		printf(
			'<a href="%s" class="jt-snav-link%s"><span class="dashicons dashicons-groups" aria-hidden="true"></span>%s</a>',
			esc_url( $url ),
			$is_active ? ' jt-snav-link--active' : '',
			esc_html__( 'FluentCommunity', 'jetonomy' )
		);
	}

	/**
	 * Render the tab body when the tab is active.
	 *
	 * @param string $active_tab Currently active tab slug.
	 */
	public function render_settings_tab( $active_tab ): void {
		if ( 'fluent-community' !== $active_tab ) {
			return;
		}

		$pairs       = $this->get_pair_map();
		$jt_spaces   = Space::list_all( 'active' );
		$fc_spaces   = $this->list_fc_spaces();
		$label       = $this->get_tab_label();
		$sync_on     = $this->member_sync_enabled();
		$broadcast   = $this->broadcast_enabled();
		$saved_flash = isset( $_GET['fc_saved'] ) && '1' === $_GET['fc_saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bf_stats    = null;
		if ( isset( $_GET['fc_backfill'] ) && '1' === $_GET['fc_backfill'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$bf_stats = array(
				'pairs'       => isset( $_GET['bf_p'] ) ? (int) $_GET['bf_p'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'added_to_jt' => isset( $_GET['bf_jt'] ) ? (int) $_GET['bf_jt'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'added_to_fc' => isset( $_GET['bf_fc'] ) ? (int) $_GET['bf_fc'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'capped'      => isset( $_GET['bf_c'] ) && '1' === $_GET['bf_c'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}
		$action_url = admin_url( 'admin-post.php' );
		?>
		<?php if ( $saved_flash ) : ?>
			<div class="notice notice-success is-dismissible" style="margin-bottom:16px;">
				<p><?php esc_html_e( 'FluentCommunity settings saved.', 'jetonomy' ); ?></p>
			</div>
		<?php endif; ?>
		<?php if ( $bf_stats ) : ?>
			<div class="notice notice-success is-dismissible" style="margin-bottom:16px;">
				<p>
					<?php
					printf(
						/* translators: 1: pair count, 2: members added to Jetonomy, 3: members added to FluentCommunity */
						esc_html__( 'Backfill complete. Pairs processed: %1$d, members added to Jetonomy: %2$d, members added to FluentCommunity: %3$d.', 'jetonomy' ),
						(int) $bf_stats['pairs'],
						(int) $bf_stats['added_to_jt'],
						(int) $bf_stats['added_to_fc']
					);
					?>
					<?php if ( ! empty( $bf_stats['capped'] ) ) : ?>
						<br><em><?php esc_html_e( 'Some spaces had more than 5,000 members. Re-run the backfill to continue.', 'jetonomy' ); ?></em>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( 'jetonomy_fc_save', '_jt_fc_nonce' ); ?>
			<input type="hidden" name="action" value="jetonomy_fc_save">

			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'FluentCommunity Integration', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc">
						<?php esc_html_e( 'Pair FluentCommunity spaces with Jetonomy spaces so members can jump between the feed and the discussions in one click.', 'jetonomy' ); ?>
					</p>
				</div>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="jt_fc_tab_label"><?php esc_html_e( 'Tab Label', 'jetonomy' ); ?></label>
						</th>
						<td>
							<input type="text" id="jt_fc_tab_label" name="jt_fc_tab_label"
								value="<?php echo esc_attr( $label ); ?>"
								placeholder="<?php esc_attr_e( 'Discussions', 'jetonomy' ); ?>"
								class="regular-text" maxlength="40">
							<p class="description">
								<?php esc_html_e( 'Shown on the FluentCommunity space header, the Jetonomy space header, and the profile section. Leave blank to reset to "Discussions".', 'jetonomy' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Space Pairings', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc">
						<?php esc_html_e( 'Each row pairs one FluentCommunity space with one Jetonomy space. A tab linking between the two appears on both sides. Leave the Jetonomy column set to "Not paired" to disable a row.', 'jetonomy' ); ?>
					</p>
				</div>
				<?php if ( empty( $fc_spaces ) ) : ?>
					<p style="padding:0 12px 12px;">
						<?php esc_html_e( 'No FluentCommunity spaces found. Create a space in FluentCommunity first.', 'jetonomy' ); ?>
					</p>
				<?php else : ?>
					<table class="wp-list-table widefat striped" style="margin:0 12px 12px;width:calc(100% - 24px);">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'FluentCommunity Space', 'jetonomy' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Paired Jetonomy Space', 'jetonomy' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $fc_spaces as $fc ) : ?>
								<?php $current = isset( $pairs[ (int) $fc->id ] ) ? (int) $pairs[ (int) $fc->id ] : 0; ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $fc->title ); ?></strong><br>
										<code style="font-size:11px;color:#646970;">/<?php echo esc_html( $fc->slug ); ?>/</code>
									</td>
									<td>
										<select name="jt_fc_pairs[<?php echo esc_attr( (string) (int) $fc->id ); ?>]" class="regular-text">
											<option value="0"><?php esc_html_e( '— Not paired —', 'jetonomy' ); ?></option>
											<?php foreach ( $jt_spaces as $jt ) : ?>
												<option value="<?php echo esc_attr( (string) (int) $jt->id ); ?>"
													<?php selected( $current, (int) $jt->id ); ?>>
													<?php echo esc_html( $jt->title ); ?>
													<?php
													// Show slug for disambiguation.
													echo ' (/' . esc_html( $jt->slug ) . '/)';
													?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Sync Behavior', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc">
						<?php esc_html_e( 'Controls what happens across paired spaces. Member sync is add-only — joins on either side add the member to both; leaves stay on the side they happened. This keeps behavior predictable and prevents accidental removal from both at once.', 'jetonomy' ); ?>
					</p>
				</div>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Member sync', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jt_fc_sync_members" value="1" <?php checked( $sync_on ); ?>>
								<?php esc_html_e( 'When a member joins one side, add them to the paired space on the other side.', 'jetonomy' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Activity broadcast', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jt_fc_broadcast" value="1" <?php checked( $broadcast ); ?>>
								<?php esc_html_e( 'When a new topic is created in a paired Jetonomy space, post an announcement in the paired FluentCommunity feed with a link back to the topic.', 'jetonomy' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Backfill existing members', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc">
						<?php esc_html_e( 'After pairing spaces, run this once to sync members who already belong to one side but not the other. Safe to run multiple times — members already in both sides are skipped. Large spaces are capped at 5,000 members per run; re-run to continue.', 'jetonomy' ); ?>
					</p>
				</div>
				<p class="submit" style="margin-left:12px;">
					<button type="submit" name="jt_fc_run_backfill" value="1" class="button">
						<?php esc_html_e( 'Sync existing members now', 'jetonomy' ); ?>
					</button>
				</p>
			</div>

			<p class="submit">
				<?php submit_button( __( 'Save FluentCommunity Settings', 'jetonomy' ), 'primary', 'submit', false ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Handle the settings form submit.
	 *
	 * Validates nonce + capability, then writes the label and the
	 * (fc_id => jt_id) pair map to two options. Invalid selections are
	 * dropped silently on save; stale pairs handled at render time.
	 */
	public function handle_settings_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'jetonomy' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'jetonomy_fc_save', '_jt_fc_nonce' );

		$raw_label = isset( $_POST['jt_fc_tab_label'] ) ? sanitize_text_field( wp_unslash( $_POST['jt_fc_tab_label'] ) ) : '';
		$raw_label = trim( $raw_label );
		if ( '' === $raw_label ) {
			delete_option( self::OPT_LABEL );
		} else {
			update_option( self::OPT_LABEL, mb_substr( $raw_label, 0, 40 ) );
		}

		// Sync toggles (checkbox absence = off).
		update_option( self::OPT_SYNC_MEMBERS, isset( $_POST['jt_fc_sync_members'] ) ? '1' : '0' );
		update_option( self::OPT_BROADCAST, isset( $_POST['jt_fc_broadcast'] ) ? '1' : '0' );

		// Pairs come in as [fc_id => jt_id]; cast both to int and drop
		// zero/negative values. Casting to int is the canonical sanitizer
		// for integer input (no string form survives the cast).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- per-key absint() below.
		$raw_pairs = isset( $_POST['jt_fc_pairs'] ) && is_array( $_POST['jt_fc_pairs'] ) ? wp_unslash( $_POST['jt_fc_pairs'] ) : array();
		$clean     = array();
		foreach ( $raw_pairs as $fc_id => $jt_id ) {
			$fc_id = absint( $fc_id );
			$jt_id = is_scalar( $jt_id ) ? absint( $jt_id ) : 0;
			if ( $fc_id > 0 && $jt_id > 0 ) {
				$clean[ $fc_id ] = $jt_id;
			}
		}
		update_option( self::OPT_PAIRS, $clean );
		$this->pair_map = null; // Force re-read on next access.

		$query_args = array(
			'page' => 'jetonomy-settings',
			'tab'  => 'fluent-community',
		);

		// Backfill button — runs synchronously, caps at 5000 per pair per run.
		$run_backfill = isset( $_POST['jt_fc_run_backfill'] ) ? sanitize_text_field( wp_unslash( $_POST['jt_fc_run_backfill'] ) ) : '';
		if ( '1' === $run_backfill ) {
			$stats                     = $this->run_backfill();
			$query_args['fc_backfill'] = '1';
			$query_args['bf_p']        = (int) $stats['pairs'];
			$query_args['bf_jt']       = (int) $stats['added_to_jt'];
			$query_args['bf_fc']       = (int) $stats['added_to_fc'];
			$query_args['bf_c']        = $stats['capped'] ? '1' : '0';
		} else {
			$query_args['fc_saved'] = '1';
		}

		wp_safe_redirect( add_query_arg( $query_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Lightweight list of FC spaces (id, slug, title) for the pair dropdown.
	 *
	 * Direct query on purpose: FC's models are not on Jetonomy's autoload
	 * path and we only need three columns. Query returns quickly even at
	 * a few hundred spaces; capped at 500 defensively.
	 *
	 * @return object[]
	 */
	private function list_fc_spaces(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, slug, title, type
			 FROM {$wpdb->prefix}fcom_spaces
			 WHERE status = 'published' AND type != 'space_group'
			 ORDER BY title ASC
			 LIMIT 500"
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Detect whether FluentCommunity is loaded.
	 *
	 * @return bool
	 */
	public static function is_fc_active(): bool {
		return class_exists( '\\FluentCommunity\\App\\App' );
	}

	/**
	 * Load the pair map from options, cached per request.
	 *
	 * @return array<int,int> Map of FC space ID => Jetonomy space ID.
	 */
	private function get_pair_map(): array {
		if ( null !== $this->pair_map ) {
			return $this->pair_map;
		}
		$raw            = get_option( self::OPT_PAIRS, array() );
		$this->pair_map = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $fc_id => $jt_id ) {
				$fc_id = (int) $fc_id;
				$jt_id = (int) $jt_id;
				if ( $fc_id > 0 && $jt_id > 0 ) {
					$this->pair_map[ $fc_id ] = $jt_id;
				}
			}
		}
		return $this->pair_map;
	}

	/**
	 * FC avatar URL for a given WP user, or null if none.
	 *
	 * Uses a short request-scoped static cache to avoid repeated queries
	 * when the same user's avatar renders multiple times on a page.
	 *
	 * @param int $user_id WP user ID.
	 * @return string|null
	 */
	private function fc_avatar_for_user( int $user_id ): ?string {
		static $cache = array();
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( array_key_exists( $user_id, $cache ) ) {
			return $cache[ $user_id ];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$avatar = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT avatar FROM {$wpdb->prefix}fcom_xprofile WHERE user_id = %d LIMIT 1",
				$user_id
			)
		);
		$avatar = is_string( $avatar ) && '' !== trim( $avatar ) ? $avatar : null;
		if ( null !== $avatar && ! preg_match( '#^https?://#i', $avatar ) ) {
			// FC stores bare URLs in avatar column; guard against stored garbage.
			$avatar = null;
		}
		$cache[ $user_id ] = $avatar;
		return $avatar;
	}

	/**
	 * Filter WordPress avatar URL to prefer FC's custom avatar when present.
	 *
	 * @param string           $url         Default avatar URL.
	 * @param int|string|mixed $id_or_email User identifier (id, email, WP_User, etc).
	 * @return string
	 */
	public function filter_avatar_url( $url, $id_or_email ): string {
		$user_id = $this->resolve_user_id( $id_or_email );
		if ( $user_id <= 0 ) {
			return (string) $url;
		}
		$fc = $this->fc_avatar_for_user( $user_id );
		return $fc ? $fc : (string) $url;
	}

	/**
	 * Resolve a WP user ID from the many forms WordPress passes to avatar filters.
	 *
	 * @param mixed $id_or_email Int, email, WP_User, WP_Comment, WP_Post, or similar.
	 * @return int 0 if unresolvable.
	 */
	private function resolve_user_id( $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( is_object( $id_or_email ) ) {
			if ( isset( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			if ( isset( $id_or_email->ID ) ) {
				return (int) $id_or_email->ID;
			}
			if ( isset( $id_or_email->user_email ) ) {
				$u = get_user_by( 'email', $id_or_email->user_email );
				return $u ? (int) $u->ID : 0;
			}
			if ( isset( $id_or_email->comment_author_email ) ) {
				$u = get_user_by( 'email', $id_or_email->comment_author_email );
				return $u ? (int) $u->ID : 0;
			}
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$u = get_user_by( 'email', $id_or_email );
			return $u ? (int) $u->ID : 0;
		}
		return 0;
	}
}
