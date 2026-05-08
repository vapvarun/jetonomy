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
		// 1.4.0 A.2 commit 3 + A.3 commit 3: both legacy quick-login /
		// quick-register AJAX handlers were removed once login-block.js
		// fully switched to POST /jetonomy/v1/auth/login + /register.
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
		wp_localize_script(
			'jetonomy-login-block',
			'jetonomyLoginBlock',
			array(
				'i18n' => array(
					'resendConfirmation' => esc_html__( 'Resend confirmation email', 'jetonomy' ),
					'sending'            => esc_html__( 'Sending...', 'jetonomy' ),
				),
			)
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

		// One editor script registers all the server-rendered blocks
		// (forum-feed, trending, space-list, leaderboard, navigation,
		// user-panel, login). Without a JS-side `registerBlockType()` the
		// inserter never lists them, even though the PHP side registers
		// them just fine — fixed 2026-04-28 after a customer report that
		// only Compose Topic appeared in the inserter.
		wp_register_script(
			'jetonomy-blocks-editor',
			JETONOMY_URL . 'assets/js/blocks-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ),
			JETONOMY_VERSION,
			true
		);

		// Editor-only stylesheet — frames the preview cards + harmonises the
		// Compose Topic mock so the family reads as one Jetonomy set in the
		// inserter and on the canvas. Keeps live REST out of the editor.
		wp_register_style(
			'jetonomy-blocks-editor',
			JETONOMY_URL . 'assets/css/blocks-editor.css',
			array(),
			JETONOMY_VERSION
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
				'editor_script'   => 'jetonomy-blocks-editor',
				'editor_style'    => 'jetonomy-blocks-editor',
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
				'editor_script'   => 'jetonomy-blocks-editor',
				'editor_style'    => 'jetonomy-blocks-editor',
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
				'editor_script'   => 'jetonomy-blocks-editor',
				'editor_style'    => 'jetonomy-blocks-editor',
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
				'editor_script'   => 'jetonomy-blocks-editor',
				'editor_style'    => 'jetonomy-blocks-editor',
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
				'editor_script'   => 'jetonomy-blocks-editor',
				'editor_style'    => 'jetonomy-blocks-editor',
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
				'editor_script'   => 'jetonomy-blocks-editor',
				'editor_style'    => 'jetonomy-blocks-editor',
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
				'editor_script'   => 'jetonomy-blocks-editor',
				'editor_style'    => 'jetonomy-blocks-editor',
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
				'editor_style'    => 'jetonomy-blocks-editor',
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
		wp_enqueue_style( 'jetonomy-blocks' );

		$atts = 'count="' . absint( $attributes['count'] ) . '"';
		if ( ! empty( $attributes['categoryId'] ) ) {
			$atts .= ' category_id="' . absint( $attributes['categoryId'] ) . '"';
		}

		return '<div class="wp-block-jetonomy-space-list">' . do_shortcode( '[jetonomy_spaces ' . $atts . ']' ) . '</div>';
	}

	public static function render_leaderboard( array $attributes ): string {
		wp_enqueue_style( 'jetonomy-blocks' );

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
		$my_spaces_url = $base . '/my-spaces/';
		$new_space_url = $base . '/new-space/';

		// 1.4.0 G6 — show "Create space" link when the viewer is a site
		// admin, holds the cap, or matches an admin-allowlisted role.
		$jt_settings_panel = get_option( 'jetonomy_settings', array() );
		$jt_allowed_roles  = isset( $jt_settings_panel['frontend_space_creation_roles'] )
			? array_filter( array_map( 'sanitize_key', (array) $jt_settings_panel['frontend_space_creation_roles'] ) )
			: array();
		$jt_user_roles     = ! empty( $user->roles ) ? (array) $user->roles : array();
		$can_create_space  = current_user_can( 'manage_options' )
			|| current_user_can( 'jetonomy_create_spaces' )
			|| ( ! empty( $jt_allowed_roles ) && count( array_intersect( $jt_user_roles, $jt_allowed_roles ) ) > 0 );
		$show_messages     = defined( 'JETONOMY_PRO_VERSION' );
		$logout_url        = wp_logout_url( (string) home_url( add_query_arg( array(), (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ) ) );
		$title             = isset( $attributes['title'] ) && '' !== $attributes['title']
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
				<a href="<?php echo esc_url( $my_spaces_url ); ?>" class="jt-userpanel-link">
					<span class="jt-userpanel-link-label"><?php esc_html_e( 'My Spaces', 'jetonomy' ); ?></span>
				</a>
				<?php if ( $can_create_space ) : ?>
					<a href="<?php echo esc_url( $new_space_url ); ?>" class="jt-userpanel-link">
						<span class="jt-userpanel-link-label"><?php esc_html_e( 'Create space', 'jetonomy' ); ?></span>
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

		$title = isset( $attributes['title'] ) && '' !== $attributes['title']
			? (string) $attributes['title']
			: __( 'Join the conversation', 'jetonomy' );

		// Default the Sign-up tab to whatever the WP "Anyone can register"
		// setting says. The auto-rendered sidebar Login card calls this with
		// an empty $attributes array, so a site owner who turns on
		// users_can_register and visits /community/ would otherwise see only
		// the Log-in form — no way to sign up from the community surface.
		// Block authors can still pin the attribute explicitly to override.
		$wp_can_register = (bool) get_option( 'users_can_register' );
		if ( array_key_exists( 'showRegister', $attributes ) ) {
			$show_register_tab = (bool) $attributes['showRegister'] && $wp_can_register;
		} else {
			$show_register_tab = $wp_can_register;
		}
		// 1.4.0 A.3 commit 3: both Login and Register tabs use REST. Block
		// only exposes the REST endpoint base + a wp_rest nonce; the legacy
		// `data-ajax-url` + per-action nonces are gone.
		$rest_url   = esc_url_raw( rest_url( 'jetonomy/v1' ) );
		$rest_nonce = wp_create_nonce( 'wp_rest' );

		ob_start();
		?>
		<div class="wp-block-jetonomy-login jt-login-block jt-app"
			data-rest-url="<?php echo esc_attr( $rest_url ); ?>"
			data-rest-nonce="<?php echo esc_attr( $rest_nonce ); ?>">
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
				<button type="button" class="jt-login-lostpw" data-jt-tab="forgot">
					<?php esc_html_e( 'Forgot password?', 'jetonomy' ); ?>
				</button>
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
					<?php
					/**
					 * Anti-spam honeypot. Real users never see this field
					 * (display:none + tabindex=-1 + aria-hidden) but bots
					 * that auto-fill every input populate it. The register
					 * REST handler rejects any submission where it isn't empty.
					 *
					 * Paired with a server-side time-on-form gate via the
					 * loaded_at hidden input below; both layers run before
					 * captcha so they are cheap and don't burn captcha quotas
					 * on obvious bots.
					 */
					?>
					<label class="jt-login-honeypot" aria-hidden="true" tabindex="-1" style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;">
						<span><?php esc_html_e( 'Website', 'jetonomy' ); ?></span>
						<input type="text" name="website" autocomplete="off" tabindex="-1" />
					</label>
					<input type="hidden" name="loaded_at" value="<?php echo esc_attr( (string) time() ); ?>" />
					<p class="jt-login-message" role="alert" aria-live="polite"></p>
					<button type="submit" class="jt-btn jt-btn-fill jt-login-submit">
						<?php esc_html_e( 'Create account', 'jetonomy' ); ?>
					</button>
				</form>
			<?php endif; ?>

			<form class="jt-login-form" data-jt-panel="forgot" novalidate>
				<p class="jt-login-forgot-intro">
					<?php esc_html_e( 'Enter your username or email and we will send a reset link if an account matches.', 'jetonomy' ); ?>
				</p>
				<label class="jt-login-label">
					<span><?php esc_html_e( 'Username or Email', 'jetonomy' ); ?></span>
					<input type="text" name="user_login" autocomplete="username" required />
				</label>
				<p class="jt-login-message" role="alert" aria-live="polite"></p>
				<button type="submit" class="jt-btn jt-btn-fill jt-login-submit">
					<?php esc_html_e( 'Send reset link', 'jetonomy' ); ?>
				</button>
				<button type="button" class="jt-login-lostpw" data-jt-tab="login">
					<?php esc_html_e( 'Back to login', 'jetonomy' ); ?>
				</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// 1.4.0 A.3 commit 3: ajax_quick_register and the per-IP rate-limit
	// helper were both removed. The rate-limiter now lives in
	// Jetonomy\API\Auth_Controller as a private static method so the only
	// auth surface that needs it (REST) carries it.

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
