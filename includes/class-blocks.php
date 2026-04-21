<?php
/**
 * Gutenberg blocks registration.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;

defined( 'ABSPATH' ) || exit;

/**
 * Register Gutenberg blocks (server-side rendered).
 *
 * Each block renders via a shortcode callback — keeps logic DRY.
 * Block settings (count, space_id, etc.) map to shortcode attributes.
 */
class Blocks {

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'wp_ajax_nopriv_jetonomy_quick_login', array( __CLASS__, 'ajax_quick_login' ) );
		add_action( 'wp_ajax_nopriv_jetonomy_quick_register', array( __CLASS__, 'ajax_quick_register' ) );
		// Register the login-block script once; the render callback enqueues
		// it only on pages that actually contain the block.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_block_assets' ) );
		// Also register on the editor side so `editor_script` handles
		// resolve inside /wp-admin/ (otherwise the inserter never learns
		// about our blocks — the 1.3.7 compose-topic regression test surfaced
		// this latent issue).
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'register_block_assets' ) );

		// Auto-inject Login / User Panel at the top of the community sidebar
		// so every theme gets a first-class auth + profile jump point
		// without the admin having to add the block manually. Integrators
		// can disable with `add_filter( 'jetonomy_sidebar_auth_card', '__return_false' )`.
		add_action( 'jetonomy_sidebar_before', array( __CLASS__, 'render_sidebar_auth_card' ), 5 );
	}

	public static function register_block_assets(): void {
		// Dedicated, compact stylesheet for the Navigation and Login blocks.
		// Self-contained — uses local tokens with WP-preset fallbacks so it
		// renders correctly on any page without depending on the main
		// jetonomy.css (which only loads on community routes).
		wp_register_style(
			'jetonomy-blocks',
			JETONOMY_URL . 'assets/css/blocks.css',
			array(),
			JETONOMY_VERSION
		);

		wp_register_script(
			'jetonomy-login-block',
			JETONOMY_URL . 'assets/js/login-block.js',
			array(),
			JETONOMY_VERSION,
			true
		);

		// Compose-topic block/shortcode piggybacks on the main view bundle —
		// that's where the Interactivity API `jetonomy` store lives. Registering
		// it here (not on community routes only) lets the block/shortcode work
		// on any page or page-builder canvas.
		//
		// NOTE: view.js uses ES module imports (`import { store } from
		// '@wordpress/interactivity'`) so it MUST be registered as a JS module,
		// not a traditional script — otherwise the browser rejects the file.
		// The main template loader also enqueues this module on community
		// routes; WordPress dedupes by handle so registering here is safe.
		if ( function_exists( 'wp_register_script_module' ) ) {
			// Asset version uses filemtime() (with the plugin version as a
			// fallback) so any in-place hotfix shipped under the same plugin
			// version still busts browser + CDN caches.
			$view_file    = JETONOMY_DIR . 'assets/js/view.js';
			$view_mtime   = file_exists( $view_file ) ? (string) filemtime( $view_file ) : '';
			$view_version = '' !== $view_mtime ? JETONOMY_VERSION . '+' . $view_mtime : JETONOMY_VERSION;
			wp_register_script_module(
				'jetonomy-compose-topic',
				JETONOMY_URL . 'assets/js/view.js',
				array( '@wordpress/interactivity' ),
				$view_version
			);
		}

		// Block-editor preview (no live REST — pure static mock so dropping
		// the block is safe and fast inside the editor).
		wp_register_script(
			'jetonomy-compose-topic-block',
			JETONOMY_URL . 'assets/js/compose-topic-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ),
			JETONOMY_VERSION,
			true
		);
	}

	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			'jetonomy/forum-feed',
			array(
				'api_version'     => '3',
				'attributes'      => array(
					'count'      => array(
						'type'    => 'number',
						'default' => 5,
					),
					'spaceId'    => array(
						'type'    => 'number',
						'default' => 0,
					),
					'sort'       => array(
						'type'    => 'string',
						'default' => 'latest',
					),
					'showHeader' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'title'      => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( __CLASS__, 'render_forum_feed' ),
				'category'        => 'widgets',
				'title'           => __( 'Forum Feed', 'jetonomy' ),
				'description'     => __( 'Display recent forum discussions. Optionally scope to a single space with a header.', 'jetonomy' ),
				'icon'            => 'format-chat',
				'keywords'        => array( 'forum', 'posts', 'discussions', 'space', 'topics', 'jetonomy' ),
			)
		);

		register_block_type(
			'jetonomy/trending',
			array(
				'api_version'     => '3',
				'attributes'      => array(
					'count'      => array(
						'type'    => 'number',
						'default' => 5,
					),
					'spaceId'    => array(
						'type'    => 'number',
						'default' => 0,
					),
					'window'     => array(
						'type'    => 'number',
						'default' => 7,
					),
					'showHeader' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'title'      => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( __CLASS__, 'render_trending' ),
				'category'        => 'widgets',
				'title'           => __( 'Trending Topics', 'jetonomy' ),
				'description'     => __( 'Display trending forum topics ranked by recent engagement (votes + replies over the last 7 days).', 'jetonomy' ),
				'icon'            => 'chart-line',
				'keywords'        => array( 'trending', 'hot', 'popular', 'topics', 'posts', 'jetonomy' ),
			)
		);

		register_block_type(
			'jetonomy/space-list',
			array(
				'api_version'     => '3',
				'attributes'      => array(
					'count'      => array(
						'type'    => 'number',
						'default' => 6,
					),
					'categoryId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'render_callback' => array( __CLASS__, 'render_space_list' ),
				'category'        => 'widgets',
				'title'           => __( 'Space List', 'jetonomy' ),
				'description'     => __( 'Display forum spaces as a grid.', 'jetonomy' ),
				'icon'            => 'groups',
				'keywords'        => array( 'spaces', 'categories', 'forum', 'jetonomy' ),
			)
		);

		register_block_type(
			'jetonomy/leaderboard',
			array(
				'api_version'     => '3',
				'attributes'      => array(
					'count' => array(
						'type'    => 'number',
						'default' => 10,
					),
				),
				'render_callback' => array( __CLASS__, 'render_leaderboard' ),
				'category'        => 'widgets',
				'title'           => __( 'Leaderboard', 'jetonomy' ),
				'description'     => __( 'Display top community members by reputation.', 'jetonomy' ),
				'icon'            => 'awards',
				'keywords'        => array( 'leaderboard', 'ranking', 'reputation', 'jetonomy' ),
			)
		);

		register_block_type(
			'jetonomy/navigation',
			array(
				'api_version'     => '3',
				'attributes'      => array(
					'showCategoryHeadings' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'collapsible'          => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'showPostCount'        => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'hideEmptyCategories'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'title'                => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( __CLASS__, 'render_navigation' ),
				'category'        => 'widgets',
				'title'           => __( 'Jetonomy Navigation', 'jetonomy' ),
				'description'     => __( 'Sidebar navigation for categories and spaces, permission-aware.', 'jetonomy' ),
				'icon'            => 'menu-alt3',
				'keywords'        => array( 'navigation', 'sidebar', 'spaces', 'categories', 'jetonomy' ),
			)
		);

		register_block_type(
			'jetonomy/user-panel',
			array(
				'api_version'     => '3',
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( __CLASS__, 'render_user_panel' ),
				'category'        => 'widgets',
				'title'           => __( 'Jetonomy User Panel', 'jetonomy' ),
				'description'     => __( 'Logged-in profile card for the sidebar: avatar, notifications, profile, and logout. Empty for logged-out viewers.', 'jetonomy' ),
				'icon'            => 'id',
				'keywords'        => array( 'profile', 'user', 'sidebar', 'notifications', 'jetonomy' ),
			)
		);

		register_block_type(
			'jetonomy/login',
			array(
				'api_version'     => '3',
				'attributes'      => array(
					'title'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'showRegister' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'render_callback' => array( __CLASS__, 'render_login' ),
				'category'        => 'widgets',
				'title'           => __( 'Jetonomy Login', 'jetonomy' ),
				'description'     => __( 'Quick login and register form for the Jetonomy sidebar. Renders empty for logged-in viewers.', 'jetonomy' ),
				'icon'            => 'admin-users',
				'keywords'        => array( 'login', 'register', 'signin', 'jetonomy' ),
			)
		);

		register_block_type(
			'jetonomy/compose-topic',
			array(
				'api_version'     => '3',
				'attributes'      => array(
					'mode'    => array(
						'type'    => 'string',
						'enum'    => array( 'picker', 'fixed' ),
						'default' => 'picker',
					),
					'spaceId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'types'   => array(
						'type'    => 'string',
						'default' => 'topic,question,idea',
					),
				),
				'render_callback' => array( __CLASS__, 'render_compose_topic' ),
				'category'        => 'widgets',
				'title'           => __( 'Jetonomy Compose Topic', 'jetonomy' ),
				'description'     => __( 'Let signed-in members start a new topic from any page. Fixed-space mode or member-only picker.', 'jetonomy' ),
				'icon'            => 'edit-page',
				'keywords'        => array( 'compose', 'topic', 'post', 'form', 'new', 'jetonomy' ),
				'editor_script'   => 'jetonomy-compose-topic-block',
			)
		);
	}

	public static function render_forum_feed( array $attributes ): string {
		wp_enqueue_style( 'jetonomy-blocks' );

		$space_id   = isset( $attributes['spaceId'] ) ? absint( $attributes['spaceId'] ) : 0;
		$show_hdr   = ! empty( $attributes['showHeader'] );
		$title_attr = isset( $attributes['title'] ) ? (string) $attributes['title'] : '';

		$atts = 'count="' . absint( $attributes['count'] ) . '"';
		if ( $space_id ) {
			$atts .= ' space_id="' . $space_id . '"';
		}
		$atts .= ' sort="' . esc_attr( $attributes['sort'] ?? 'latest' ) . '"';

		$header = $show_hdr ? self::render_space_header( $space_id, $title_attr, __( 'Recent topics', 'jetonomy' ) ) : '';

		return '<div class="wp-block-jetonomy-forum-feed jt-feed-block jt-app">'
			. $header
			. do_shortcode( '[jetonomy_recent_posts ' . $atts . ']' )
			. '</div>';
	}

	/**
	 * Render the Trending Topics block.
	 *
	 * Self-contained — enqueues jetonomy-blocks styles so it works on any
	 * page without depending on the main community stylesheet.
	 */
	public static function render_trending( array $attributes ): string {
		wp_enqueue_style( 'jetonomy-blocks' );

		$space_id   = isset( $attributes['spaceId'] ) ? absint( $attributes['spaceId'] ) : 0;
		$show_hdr   = ! empty( $attributes['showHeader'] );
		$title_attr = isset( $attributes['title'] ) ? (string) $attributes['title'] : '';
		$window     = isset( $attributes['window'] ) ? absint( $attributes['window'] ) : 7;

		$atts = 'count="' . absint( $attributes['count'] ) . '"';
		if ( $space_id ) {
			$atts .= ' space_id="' . $space_id . '"';
		}
		if ( $window ) {
			$atts .= ' window="' . $window . '"';
		}

		$header = $show_hdr ? self::render_space_header( $space_id, $title_attr, __( 'Trending topics', 'jetonomy' ) ) : '';

		return '<div class="wp-block-jetonomy-trending jt-feed-block jt-trending-block jt-app">'
			. $header
			. do_shortcode( '[jetonomy_trending_posts ' . $atts . ']' )
			. '</div>';
	}

	/**
	 * Render a block header — space-aware when a space_id is set, generic title otherwise.
	 * Output includes a "View all" link back to the space or community home.
	 */
	private static function render_space_header( int $space_id, string $custom_title, string $default_title ): string {
		$base         = \Jetonomy\base_url();
		$link         = $base;
		$heading_text = '' !== $custom_title ? $custom_title : $default_title;

		if ( $space_id > 0 && class_exists( Space::class ) ) {
			$space = Space::find( $space_id );
			if ( $space && ! empty( $space->slug ) ) {
				$link = $base . '/s/' . rawurlencode( (string) $space->slug ) . '/';
				if ( '' === $custom_title ) {
					/* translators: %s: space title */
					$heading_text = sprintf( __( '%s · Topics', 'jetonomy' ), (string) ( $space->title ?? '' ) );
				}
			}
		}

		return sprintf(
			'<header class="jt-feed-header"><h3 class="jt-feed-title">%1$s</h3><a class="jt-feed-viewall" href="%2$s">%3$s<span aria-hidden="true"> →</span></a></header>',
			esc_html( $heading_text ),
			esc_url( $link ),
			esc_html__( 'View all', 'jetonomy' )
		);
	}

	public static function render_space_list( array $attributes ): string {
		$atts = 'count="' . absint( $attributes['count'] ) . '"';
		if ( ! empty( $attributes['categoryId'] ) ) {
			$atts .= ' category_id="' . absint( $attributes['categoryId'] ) . '"';
		}

		return '<div class="wp-block-jetonomy-space-list">' . do_shortcode( '[jetonomy_spaces ' . $atts . ']' ) . '</div>';
	}

	public static function render_leaderboard( array $attributes ): string {
		return '<div class="wp-block-jetonomy-leaderboard">' . do_shortcode( '[jetonomy_leaderboard count="' . absint( $attributes['count'] ) . '"]' ) . '</div>';
	}

	/**
	 * Resolve the currently active space slug from the URL, if any, so the
	 * Navigation block can mark the active row with aria-current.
	 */
	private static function current_space_slug(): string {
		$slug = get_query_var( 'jetonomy_space' );
		if ( is_string( $slug ) && '' !== $slug ) {
			return $slug;
		}
		// Fallback for early requests where query vars aren't populated.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		if ( ! $uri || ! preg_match( '#/s/([^/]+)/#', (string) $uri, $m ) ) {
			return '';
		}
		return sanitize_title( $m[1] );
	}

	/**
	 * Build one <li> for a space. Accepts either object or array shape because
	 * Space::list_visible() hydrates rows as associative arrays when callers
	 * have filtered through jetonomy_spaces_query_args.
	 */
	private static function render_space_item( $space, string $active_slug, bool $show_count ): string {
		$space = is_object( $space ) ? $space : (object) ( is_array( $space ) ? $space : array() );
		$slug  = isset( $space->slug ) ? (string) $space->slug : '';
		$title = isset( $space->title ) ? (string) $space->title : '';
		if ( '' === $slug || '' === $title ) {
			return '';
		}
		$url        = \Jetonomy\base_url() . '/s/' . rawurlencode( $slug ) . '/';
		$is_active  = $slug === $active_slug;
		$aria_attr  = $is_active ? ' aria-current="page"' : '';
		$active_cls = $is_active ? ' is-active' : '';
		$count_html = '';
		if ( $show_count && isset( $space->post_count ) ) {
			$count_html = ' <span class="jt-nav-count">' . (int) $space->post_count . '</span>';
		}
		return sprintf(
			'<li class="jt-nav-space%s"><a href="%s"%s>%s%s</a></li>',
			esc_attr( $active_cls ),
			esc_url( $url ),
			$aria_attr,
			esc_html( $title ),
			$count_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — assembled from escaped parts above.
		);
	}

	/**
	 * Render the Navigation block — categories → spaces tree.
	 */
	public static function render_navigation( array $attributes ): string {
		if ( ! class_exists( Category::class ) || ! class_exists( Space::class ) ) {
			return '';
		}

		wp_enqueue_style( 'jetonomy-blocks' );

		$user_id       = get_current_user_id();
		$show_headings = ! empty( $attributes['showCategoryHeadings'] );
		$collapsible   = ! empty( $attributes['collapsible'] );
		$show_count    = ! empty( $attributes['showPostCount'] ) && $user_id > 0;
		$hide_empty    = ! empty( $attributes['hideEmptyCategories'] );
		$title         = isset( $attributes['title'] ) ? (string) $attributes['title'] : '';
		$active_slug   = self::current_space_slug();

		$categories = Category::list_top_level();

		$sections = array();

		foreach ( $categories as $category ) {
			$category_id = (int) ( $category->id ?? 0 );
			if ( ! $category_id ) {
				continue;
			}
			// list_visible() already filters by viewer permissions (public
			// for guests, public + membership for members, all for admins).
			// per_page capped to 200 so a single category can't run away.
			$result = Space::list_visible( $user_id, $category_id, null, null, 200, 0 );
			$spaces = $result['spaces'];
			if ( $hide_empty && empty( $spaces ) ) {
				continue;
			}

			$items_html = '';
			foreach ( $spaces as $space ) {
				$items_html .= self::render_space_item( $space, $active_slug, $show_count );
			}

			$category_name = (string) ( $category->name ?? '' );

			if ( $show_headings ) {
				$heading_tag = $collapsible ? 'details' : 'div';
				$summary_tag = $collapsible ? 'summary' : 'h3';
				$open_attr   = $collapsible ? ' open' : '';
				$sections[]  = sprintf(
					'<%1$s class="jt-nav-category"%2$s><%3$s class="jt-nav-category-title">%4$s</%3$s><ul class="jt-nav-spaces">%5$s</ul></%1$s>',
					$heading_tag,
					$open_attr,
					$summary_tag,
					esc_html( $category_name ),
					$items_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — per-item HTML escaped in render_space_item().
				);
			} else {
				$sections[] = '<ul class="jt-nav-spaces">' . $items_html . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		// "Uncategorized" bucket — any spaces with category_id = 0 that the
		// viewer can see. Renders last, un-headed, so site owners who
		// don't use categories still get a flat tree.
		$orphan_result = Space::list_visible( $user_id, null, null, null, 200, 0 );
		$orphans       = $orphan_result['spaces'];
		if ( ! empty( $orphans ) ) {
			$orphan_items = '';
			foreach ( $orphans as $space ) {
				if ( (int) ( $space->category_id ?? 0 ) !== 0 ) {
					continue;
				}
				$orphan_items .= self::render_space_item( $space, $active_slug, $show_count );
			}
			if ( '' !== $orphan_items ) {
				$sections[] = '<ul class="jt-nav-spaces jt-nav-spaces-uncategorized">' . $orphan_items . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		if ( empty( $sections ) ) {
			return '';
		}

		$title_html = '' !== $title ? '<h2 class="jt-nav-title">' . esc_html( $title ) . '</h2>' : '';

		return '<nav class="wp-block-jetonomy-navigation jt-nav-block jt-app" aria-label="' . esc_attr__( 'Community', 'jetonomy' ) . '">'
			. $title_html
			. implode( '', $sections ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — sections escaped above.
			. '</nav>';
	}

	/**
	 * Render the User Panel block — avatar, profile/notifications/logout.
	 * Companion to the Login block; returns empty when the viewer is
	 * logged-out so the two blocks can sit side-by-side and self-switch.
	 */
	public static function render_user_panel( array $attributes ): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		wp_enqueue_style( 'jetonomy-blocks' );

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();
		$base    = \Jetonomy\base_url();
		$avatar  = get_avatar( $user_id, 48, '', $user->display_name, array( 'class' => 'jt-userpanel-avatar' ) );

		// Trust level (cheap read from user_profiles).
		$profile     = class_exists( \Jetonomy\Models\UserProfile::class ) ? \Jetonomy\Models\UserProfile::find_by_user( $user_id ) : null;
		$trust_level = $profile ? (int) ( $profile->trust_level ?? 0 ) : 0;

		// Unread notifications count (bounded query — uses the index on
		// user_id + is_read so it stays cheap at 10k+ notifications).
		$unread = 0;
		if ( class_exists( \Jetonomy\Models\Notification::class ) ) {
			global $wpdb;
			$notifications_tbl = \Jetonomy\table( 'notifications' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$unread = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notifications_tbl} WHERE user_id = %d AND is_read = 0", $user_id ) );
		}

		$profile_url   = \Jetonomy\get_profile_url( $user_id );
		$edit_url      = $base . '/u/' . rawurlencode( $user->user_login ) . '/edit/';
		$notifs_url    = $base . '/notifications/';
		$messages_url  = $base . '/messages/';
		$show_messages = defined( 'JETONOMY_PRO_VERSION' );
		$logout_url    = wp_logout_url( (string) home_url( add_query_arg( array(), (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) ) );
		$title         = isset( $attributes['title'] ) && '' !== $attributes['title']
			? (string) $attributes['title']
			: sprintf( /* translators: %s: user display name */ __( 'Hi, %s', 'jetonomy' ), $user->display_name );

		ob_start();
		?>
		<div class="wp-block-jetonomy-user-panel jt-user-panel jt-app">
			<div class="jt-userpanel-head">
				<?php echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — get_avatar returns sanitized HTML. ?>
				<div class="jt-userpanel-heading">
					<h3 class="jt-userpanel-title"><?php echo esc_html( $title ); ?></h3>
					<div class="jt-userpanel-meta">
						<span class="jt-userpanel-username">@<?php echo esc_html( $user->user_login ); ?></span>
						<?php if ( $trust_level > 0 ) : ?>
							<span class="jt-userpanel-tl" data-jt-tl="<?php echo (int) $trust_level; ?>" title="<?php echo esc_attr( sprintf( /* translators: %d: trust level */ __( 'Trust Level %d', 'jetonomy' ), $trust_level ) ); ?>">
								TL<?php echo (int) $trust_level; ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<nav class="jt-userpanel-links" aria-label="<?php esc_attr_e( 'Account', 'jetonomy' ); ?>">
				<a href="<?php echo esc_url( $profile_url ); ?>" class="jt-userpanel-link">
					<span class="jt-userpanel-link-label"><?php esc_html_e( 'My Profile', 'jetonomy' ); ?></span>
				</a>
				<a href="<?php echo esc_url( $notifs_url ); ?>" class="jt-userpanel-link">
					<span class="jt-userpanel-link-label"><?php esc_html_e( 'Notifications', 'jetonomy' ); ?></span>
					<?php if ( $unread > 0 ) : ?>
						<span class="jt-userpanel-badge"><?php echo esc_html( (string) $unread ); ?></span>
					<?php endif; ?>
				</a>
				<?php if ( $show_messages ) : ?>
					<a href="<?php echo esc_url( $messages_url ); ?>" class="jt-userpanel-link">
						<span class="jt-userpanel-link-label"><?php esc_html_e( 'Messages', 'jetonomy' ); ?></span>
					</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $edit_url ); ?>" class="jt-userpanel-link">
					<span class="jt-userpanel-link-label"><?php esc_html_e( 'Edit Profile', 'jetonomy' ); ?></span>
				</a>
				<a href="<?php echo esc_url( $logout_url ); ?>" class="jt-userpanel-link jt-userpanel-link--logout">
					<span class="jt-userpanel-link-label"><?php esc_html_e( 'Log out', 'jetonomy' ); ?></span>
				</a>
			</nav>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the matching auth card (login OR user panel) at the top of the
	 * community sidebar. Hooks `jetonomy_sidebar_before` so every theme
	 * gets auth + profile navigation without the admin editing the
	 * sidebar template. Returns early if an integrator opts out via the
	 * `jetonomy_sidebar_auth_card` filter.
	 */
	public static function render_sidebar_auth_card(): void {
		/**
		 * Allow integrations to suppress the auto-rendered auth card.
		 *
		 * @param bool $show Whether to render the auth card. Default true.
		 */
		if ( ! apply_filters( 'jetonomy_sidebar_auth_card', true ) ) {
			return;
		}
		if ( is_user_logged_in() ) {
			echo self::render_user_panel( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — method returns escaped HTML.
		} else {
			echo self::render_login( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — method returns escaped HTML.
		}
	}

	/**
	 * Render the Login block — quick login/register for the Jetonomy sidebar.
	 * Returns empty output for logged-in viewers so the sidebar has no
	 * layout shift after login.
	 */
	public static function render_login( array $attributes ): string {
		if ( is_user_logged_in() ) {
			return '';
		}

		// Enqueue only when this block is actually rendered. Safe from duplicate
		// enqueues: WordPress dedupes by handle.
		wp_enqueue_style( 'jetonomy-blocks' );
		wp_enqueue_script( 'jetonomy-login-block' );

		$title             = isset( $attributes['title'] ) && '' !== $attributes['title']
			? (string) $attributes['title']
			: __( 'Join the conversation', 'jetonomy' );
		$show_register_tab = ! empty( $attributes['showRegister'] ) && (bool) get_option( 'users_can_register' );
		$login_nonce       = wp_create_nonce( 'jetonomy_quick_login' );
		$register_nonce    = wp_create_nonce( 'jetonomy_quick_register' );
		$ajax_url          = esc_url( admin_url( 'admin-ajax.php' ) );

		ob_start();
		?>
		<div class="wp-block-jetonomy-login jt-login-block jt-app"
			data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>"
			data-login-nonce="<?php echo esc_attr( $login_nonce ); ?>"
			data-register-nonce="<?php echo esc_attr( $register_nonce ); ?>">
			<h3 class="jt-login-title"><?php echo esc_html( $title ); ?></h3>

			<?php if ( $show_register_tab ) : ?>
				<div class="jt-login-tabs" role="tablist">
					<button type="button" class="jt-login-tab is-active" data-jt-tab="login" role="tab" aria-selected="true">
						<?php esc_html_e( 'Log in', 'jetonomy' ); ?>
					</button>
					<button type="button" class="jt-login-tab" data-jt-tab="register" role="tab" aria-selected="false">
						<?php esc_html_e( 'Register', 'jetonomy' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<form class="jt-login-form is-active" data-jt-panel="login" novalidate>
				<label class="jt-login-label">
					<span><?php esc_html_e( 'Username or Email', 'jetonomy' ); ?></span>
					<input type="text" name="login" autocomplete="username" required />
				</label>
				<label class="jt-login-label">
					<span><?php esc_html_e( 'Password', 'jetonomy' ); ?></span>
					<input type="password" name="password" autocomplete="current-password" required />
				</label>
				<label class="jt-login-remember">
					<input type="checkbox" name="remember" value="1" />
					<span><?php esc_html_e( 'Remember me', 'jetonomy' ); ?></span>
				</label>
				<p class="jt-login-message" role="alert" aria-live="polite"></p>
				<button type="submit" class="jt-btn jt-btn-fill jt-login-submit">
					<?php esc_html_e( 'Log in', 'jetonomy' ); ?>
				</button>
				<a class="jt-login-lostpw" href="<?php echo esc_url( wp_lostpassword_url() ); ?>">
					<?php esc_html_e( 'Forgot password?', 'jetonomy' ); ?>
				</a>
			</form>

			<?php if ( $show_register_tab ) : ?>
				<form class="jt-login-form" data-jt-panel="register" novalidate>
					<label class="jt-login-label">
						<span><?php esc_html_e( 'Username', 'jetonomy' ); ?></span>
						<input type="text" name="username" autocomplete="username" required minlength="3" />
					</label>
					<label class="jt-login-label">
						<span><?php esc_html_e( 'Email', 'jetonomy' ); ?></span>
						<input type="email" name="email" autocomplete="email" required />
					</label>
					<label class="jt-login-label">
						<span><?php esc_html_e( 'Password', 'jetonomy' ); ?></span>
						<input type="password" name="password" autocomplete="new-password" required minlength="8" />
					</label>
					<p class="jt-login-message" role="alert" aria-live="polite"></p>
					<button type="submit" class="jt-btn jt-btn-fill jt-login-submit">
						<?php esc_html_e( 'Create account', 'jetonomy' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Simple per-IP rate limit for anonymous auth endpoints. 5 attempts per
	 * minute per IP is generous enough for humans, tight enough to discourage
	 * credential stuffing.
	 */
	private static function check_rate_limit( string $bucket ): bool {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key  = 'jt_auth_' . $bucket . '_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= 5 ) {
			return false;
		}
		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * AJAX handler: quick login via admin-ajax.
	 * Nonce-gated. Generic error message so failures don't leak account existence.
	 */
	public static function ajax_quick_login(): void {
		check_ajax_referer( 'jetonomy_quick_login', 'nonce' );

		if ( ! self::check_rate_limit( 'login' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many attempts. Please wait a minute and try again.', 'jetonomy' ) ),
				429
			);
		}

		$login    = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['login'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$remember = ! empty( $_POST['remember'] );

		if ( '' === $login || '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'Enter your username and password.', 'jetonomy' ) ), 400 );
		}

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'Incorrect username or password.', 'jetonomy' ) ), 401 );
		}

		wp_send_json_success( array( 'message' => __( 'Signed in. Reloading…', 'jetonomy' ) ) );
	}

	/**
	 * AJAX handler: quick register via admin-ajax.
	 * Honours users_can_register; falls back to WP core validators.
	 */
	public static function ajax_quick_register(): void {
		check_ajax_referer( 'jetonomy_quick_register', 'nonce' );

		if ( ! (bool) get_option( 'users_can_register' ) ) {
			wp_send_json_error( array( 'message' => __( 'Registration is disabled on this site.', 'jetonomy' ) ), 403 );
		}

		if ( ! self::check_rate_limit( 'register' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many attempts. Please wait a minute and try again.', 'jetonomy' ) ),
				429
			);
		}

		$username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( (string) $_POST['username'] ), true ) : '';
		$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
		$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';

		if ( '' === $username || '' === $email || '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'All fields are required.', 'jetonomy' ) ), 400 );
		}
		if ( ! validate_username( $username ) || username_exists( $username ) ) {
			wp_send_json_error( array( 'message' => __( 'That username is unavailable.', 'jetonomy' ) ), 400 );
		}
		if ( ! is_email( $email ) || email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'That email is unavailable.', 'jetonomy' ) ), 400 );
		}
		if ( strlen( $password ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'Password must be at least 8 characters.', 'jetonomy' ) ), 400 );
		}

		$user_id = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ), 400 );
		}

		// Intentionally NOT calling wp_send_new_user_notifications() here —
		// Jetonomy's Notifier already owns branded welcome + admin emails
		// through the jetonomy_user_registered hook. Triggering WP core's
		// stock notification here would duplicate what Jetonomy sends.
		do_action( 'jetonomy_user_registered', (int) $user_id );

		wp_set_current_user( (int) $user_id );
		wp_set_auth_cookie( (int) $user_id, false, is_ssl() );

		wp_send_json_success( array( 'message' => __( 'Account created. Reloading…', 'jetonomy' ) ) );
	}

	/**
	 * Render the Compose Topic block.
	 *
	 * Delegates straight to the shortcode so both entry points share one
	 * codepath and stay in lockstep — the shortcode owns the partial render,
	 * asset enqueue, and logged-out CTA.
	 */
	public static function render_compose_topic( array $attributes ): string {
		$mode     = isset( $attributes['mode'] ) && in_array( $attributes['mode'], array( 'picker', 'fixed' ), true )
			? $attributes['mode']
			: 'picker';
		$space_id = isset( $attributes['spaceId'] ) ? absint( $attributes['spaceId'] ) : 0;
		$types    = isset( $attributes['types'] ) ? (string) $attributes['types'] : 'topic,question,idea';

		$sc = '[jetonomy_compose_topic'
			. ' mode="' . esc_attr( $mode ) . '"'
			. ' space_id="' . $space_id . '"'
			. ' types="' . esc_attr( $types ) . '"'
			. ']';

		return '<div class="wp-block-jetonomy-compose-topic">' . do_shortcode( $sc ) . '</div>';
	}
}
