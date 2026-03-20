<?php
namespace Jetonomy\Admin;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\AccessRule;
use Jetonomy\Import\Import_Manager;
use function Jetonomy\table;
use function Jetonomy\now;

class Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Category AJAX
		add_action( 'wp_ajax_jetonomy_create_category', [ $this, 'ajax_create_category' ] );
		add_action( 'wp_ajax_jetonomy_update_category', [ $this, 'ajax_update_category' ] );
		add_action( 'wp_ajax_jetonomy_delete_category', [ $this, 'ajax_delete_category' ] );
		add_action( 'wp_ajax_jetonomy_reorder_categories', [ $this, 'ajax_reorder_categories' ] );

		// Space AJAX
		add_action( 'wp_ajax_jetonomy_create_space', [ $this, 'ajax_create_space' ] );
		add_action( 'wp_ajax_jetonomy_update_space', [ $this, 'ajax_update_space' ] );
		add_action( 'wp_ajax_jetonomy_delete_space', [ $this, 'ajax_delete_space' ] );

		// Space Members AJAX
		add_action( 'wp_ajax_jetonomy_add_space_member', [ $this, 'ajax_add_space_member' ] );
		add_action( 'wp_ajax_jetonomy_remove_space_member', [ $this, 'ajax_remove_space_member' ] );
		add_action( 'wp_ajax_jetonomy_change_member_role', [ $this, 'ajax_change_member_role' ] );

		// Access Rules AJAX
		add_action( 'wp_ajax_jetonomy_add_access_rule', [ $this, 'ajax_add_access_rule' ] );
		add_action( 'wp_ajax_jetonomy_delete_access_rule', [ $this, 'ajax_delete_access_rule' ] );

		// Moderation AJAX
		add_action( 'wp_ajax_jetonomy_approve_content', [ $this, 'ajax_approve_content' ] );
		add_action( 'wp_ajax_jetonomy_spam_content', [ $this, 'ajax_spam_content' ] );
		add_action( 'wp_ajax_jetonomy_trash_content', [ $this, 'ajax_trash_content' ] );
		add_action( 'wp_ajax_jetonomy_resolve_flag', [ $this, 'ajax_resolve_flag' ] );

		// User AJAX
		add_action( 'wp_ajax_jetonomy_ban_user', [ $this, 'ajax_ban_user' ] );
		add_action( 'wp_ajax_jetonomy_unban_user', [ $this, 'ajax_unban_user' ] );
		add_action( 'wp_ajax_jetonomy_change_trust_level', [ $this, 'ajax_change_trust_level' ] );
		add_action( 'wp_ajax_jetonomy_search_users', [ $this, 'ajax_search_users' ] );

		// Import AJAX (legacy single-shot kept for CLI / backwards compat)
		add_action( 'wp_ajax_jetonomy_run_import', [ $this, 'ajax_run_import' ] );
		// Batched import
		add_action( 'wp_ajax_jetonomy_import_batch', [ $this, 'ajax_import_batch' ] );
		add_action( 'wp_ajax_jetonomy_import_progress', [ $this, 'ajax_import_progress' ] );

		// Settings AJAX
		add_action( 'wp_ajax_jetonomy_test_email', [ $this, 'ajax_test_email' ] );
		add_action( 'wp_ajax_jetonomy_flush_rules', [ $this, 'ajax_flush_rules' ] );

		// Content AJAX (post/reply management)
		add_action( 'wp_ajax_jetonomy_update_post', [ $this, 'ajax_update_post' ] );
		add_action( 'wp_ajax_jetonomy_delete_post', [ $this, 'ajax_delete_post' ] );
		add_action( 'wp_ajax_jetonomy_update_reply', [ $this, 'ajax_update_reply' ] );
		add_action( 'wp_ajax_jetonomy_delete_reply', [ $this, 'ajax_delete_reply' ] );
		add_action( 'wp_ajax_jetonomy_get_replies', [ $this, 'ajax_get_replies' ] );
		add_action( 'wp_ajax_jetonomy_bulk_content_action', [ $this, 'ajax_bulk_content_action' ] );

		// Setup Wizard AJAX
		add_action( 'wp_ajax_jetonomy_setup_save', [ $this, 'ajax_setup_save' ] );
		add_action( 'wp_ajax_jetonomy_setup_create_sample', [ $this, 'ajax_setup_create_sample' ] );
		add_action( 'wp_ajax_jetonomy_cleanup_sample_data', [ $this, 'ajax_cleanup_sample_data' ] );
	}

	// ── Menu ──

	public function add_menu(): void {
		$menu_label = apply_filters( 'jetonomy_admin_menu_label', __( 'Jetonomy', 'jetonomy' ) );
		$menu_icon  = apply_filters( 'jetonomy_admin_menu_icon', 'dashicons-groups' );

		add_menu_page(
			$menu_label,
			$menu_label,
			'jetonomy_manage_settings',
			'jetonomy',
			[ $this, 'render_dashboard' ],
			$menu_icon,
			30
		);

		add_submenu_page(
			'jetonomy',
			__( 'Dashboard', 'jetonomy' ),
			__( 'Dashboard', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'jetonomy',
			__( 'Categories', 'jetonomy' ),
			__( 'Categories', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-categories',
			[ $this, 'render_categories' ]
		);

		add_submenu_page(
			'jetonomy',
			__( 'Spaces', 'jetonomy' ),
			__( 'Spaces', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-spaces',
			[ $this, 'render_spaces' ]
		);

		add_submenu_page(
			'jetonomy',
			__( 'Content', 'jetonomy' ),
			__( 'Content', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-content',
			[ $this, 'render_content' ]
		);

		add_submenu_page(
			'jetonomy',
			__( 'Moderation', 'jetonomy' ),
			__( 'Moderation', 'jetonomy' ),
			'jetonomy_moderate',
			'jetonomy-moderation',
			[ $this, 'render_moderation' ]
		);

		add_submenu_page(
			'jetonomy',
			__( 'Users', 'jetonomy' ),
			__( 'Users', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-users',
			[ $this, 'render_users' ]
		);

		add_submenu_page(
			'jetonomy',
			__( 'Import', 'jetonomy' ),
			__( 'Import', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-import',
			[ $this, 'render_import' ]
		);

		add_submenu_page(
			'jetonomy',
			__( 'Settings', 'jetonomy' ),
			__( 'Settings', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-settings',
			[ $this, 'render_settings' ]
		);

		// Pro-only subpages — only show when Pro is active.
		if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
			add_submenu_page(
				'jetonomy',
				__( 'Extensions', 'jetonomy' ),
				__( 'Extensions', 'jetonomy' ),
				'jetonomy_manage_settings',
				'jetonomy-extensions',
				[ $this, 'render_extensions' ]
			);
			add_submenu_page(
				'jetonomy',
				__( 'License', 'jetonomy' ),
				__( 'License', 'jetonomy' ),
				'jetonomy_manage_settings',
				'jetonomy-license',
				[ $this, 'render_license' ]
			);
		}

		// Hidden setup wizard page (no menu item).
		add_submenu_page( '', __( 'Jetonomy Setup', 'jetonomy' ), '', 'manage_options', 'jetonomy-setup', [ $this, 'render_setup' ] );
	}

	// ── Settings API ──

	public function register_settings(): void {
		register_setting( 'jetonomy_settings', 'jetonomy_settings', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_settings' ],
		] );
	}

	public function sanitize_settings( $input ): array {
		$clean = [];

		// General
		$clean['base_slug']          = sanitize_title( $input['base_slug'] ?? 'community' );
		$clean['posts_per_page']     = absint( $input['posts_per_page'] ?? 20 );
		$clean['replies_per_page']   = absint( $input['replies_per_page'] ?? 30 );
		$clean['default_space_type'] = sanitize_text_field( $input['default_space_type'] ?? 'forum' );
		$clean['guest_read']         = ! empty( $input['guest_read'] );
		$clean['require_login']      = ! empty( $input['require_login'] );

		// Permissions
		$clean['trust_level_1_posts']      = absint( $input['trust_level_1_posts'] ?? 5 );
		$clean['trust_level_1_days']       = absint( $input['trust_level_1_days'] ?? 3 );
		$clean['trust_level_1_reputation'] = absint( $input['trust_level_1_reputation'] ?? 10 );
		$clean['trust_level_1_replies']    = absint( $input['trust_level_1_replies'] ?? 5 );
		$clean['trust_level_2_posts']      = absint( $input['trust_level_2_posts'] ?? 20 );
		$clean['trust_level_2_days']       = absint( $input['trust_level_2_days'] ?? 15 );
		$clean['trust_level_2_reputation'] = absint( $input['trust_level_2_reputation'] ?? 50 );
		$clean['trust_level_2_replies']    = absint( $input['trust_level_2_replies'] ?? 20 );
		$clean['trust_level_3_posts']      = absint( $input['trust_level_3_posts'] ?? 50 );
		$clean['trust_level_3_days']       = absint( $input['trust_level_3_days'] ?? 50 );
		$clean['trust_level_3_reputation'] = absint( $input['trust_level_3_reputation'] ?? 200 );
		$clean['trust_level_3_replies']    = absint( $input['trust_level_3_replies'] ?? 50 );
		$clean['rate_limit_posts']         = absint( $input['rate_limit_posts'] ?? 3 );
		$clean['rate_limit_replies']       = absint( $input['rate_limit_replies'] ?? 10 );
		$clean['rate_limit_votes']         = absint( $input['rate_limit_votes'] ?? 20 );

		// Email
		$clean['email_from_name']  = sanitize_text_field( $input['email_from_name'] ?? '' );
		$clean['email_from_email'] = sanitize_email( $input['email_from_email'] ?? '' );

		// Appearance
		$clean['inherit_fonts']    = ! empty( $input['inherit_fonts'] );
		$clean['inherit_colors']   = ! empty( $input['inherit_colors'] );
		$clean['accent_color']     = sanitize_hex_color( $input['accent_color'] ?? '#0073aa' );
		$clean['layout_density']   = sanitize_text_field( $input['layout_density'] ?? 'comfortable' );
		$clean['custom_css']       = wp_strip_all_tags( $input['custom_css'] ?? '' );

		// SEO
		$clean['seo_post_title']      = sanitize_text_field( $input['seo_post_title'] ?? '{post_title} - {space_name} | {site_name}' );
		$clean['seo_space_title']     = sanitize_text_field( $input['seo_space_title'] ?? '{space_name} | {site_name}' );
		$clean['seo_schema']          = ! empty( $input['seo_schema'] );
		$clean['seo_sitemap']         = ! empty( $input['seo_sitemap'] );
		$clean['seo_noindex_profiles'] = ! empty( $input['seo_noindex_profiles'] );
		$clean['seo_noindex_search']  = ! empty( $input['seo_noindex_search'] );

		return $clean;
	}

	// ── Assets ──

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'jetonomy' ) ) {
			return;
		}

		wp_enqueue_style(
			'jetonomy-admin',
			JETONOMY_URL . 'assets/css/admin.css',
			[],
			JETONOMY_VERSION
		);

		wp_enqueue_script(
			'jetonomy-admin',
			JETONOMY_URL . 'assets/js/admin.js',
			[ 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ],
			JETONOMY_VERSION,
			true
		);

		wp_enqueue_style( 'wp-color-picker' );

		// Code editor for custom CSS
		$page = sanitize_text_field( $_GET['page'] ?? '' );
		if ( 'jetonomy-settings' === $page ) {
			$cm_settings = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
			if ( false !== $cm_settings ) {
				wp_localize_script( 'jetonomy-admin', 'jetonomyCmSettings', $cm_settings );
			}
		}

		// Media uploader
		wp_enqueue_media();

		wp_localize_script( 'jetonomy-admin', 'jetonomyAdmin', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'jetonomy_admin' ),
			'i18n'    => [
				'confirmDelete'    => __( 'Are you sure? This cannot be undone.', 'jetonomy' ),
				'confirmBan'       => __( 'Are you sure you want to ban this user?', 'jetonomy' ),
				'saving'           => __( 'Saving...', 'jetonomy' ),
				'saved'            => __( 'Saved!', 'jetonomy' ),
				'deleted'          => __( 'Deleted.', 'jetonomy' ),
				'error'            => __( 'Something went wrong.', 'jetonomy' ),
				'importing'        => __( 'Importing...', 'jetonomy' ),
				'importDone'       => __( 'Import complete!', 'jetonomy' ),
				'selectImage'      => __( 'Select Image', 'jetonomy' ),
				'useImage'         => __( 'Use this image', 'jetonomy' ),
				'testEmailSent'    => __( 'Test email sent!', 'jetonomy' ),
				'rewritesFlushed'  => __( 'Rewrite rules flushed.', 'jetonomy' ),
			],
		] );
	}

	// ── Page Renderers ──

	public function render_dashboard(): void {
		global $wpdb;

		$posts_t    = table( 'posts' );
		$replies_t  = table( 'replies' );
		$spaces_t   = table( 'spaces' );
		$users_t    = table( 'user_profiles' );
		$flags_t    = table( 'flags' );
		$activity_t = table( 'activity_log' );

		$today = current_time( 'Y-m-d' );

		$stats = [
			'total_posts'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$posts_t} WHERE status = 'publish'" ),
			'total_replies' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$replies_t} WHERE status = 'publish'" ),
			'active_spaces' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$spaces_t} WHERE status = 'active'" ),
			'users'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$users_t}" ),
			'pending_flags' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flags_t} WHERE status = 'pending'" ),
			'posts_today'   => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$posts_t} WHERE status = 'publish' AND created_at >= %s",
				$today . ' 00:00:00'
			) ),
		];

		$recent_activity = $wpdb->get_results(
			"SELECT * FROM {$activity_t} ORDER BY created_at DESC LIMIT 10"
		) ?: [];

		$settings = get_option( 'jetonomy_settings', [] );
		$base_slug = $settings['base_slug'] ?? 'community';

		include JETONOMY_DIR . 'includes/admin/views/dashboard.php';
	}

	public function render_categories(): void {
		$categories = Category::list_top_level();
		$all_categories = $this->get_all_categories_nested();
		include JETONOMY_DIR . 'includes/admin/views/categories.php';
	}

	public function render_spaces(): void {
		global $wpdb;

		$action = sanitize_text_field( $_GET['action'] ?? 'list' );
		$space_id = absint( $_GET['space_id'] ?? 0 );

		if ( 'edit' === $action && $space_id > 0 ) {
			$space = Space::find( $space_id );
			if ( ! $space ) {
				wp_die( esc_html__( 'Space not found.', 'jetonomy' ) );
			}
			$categories = $this->get_all_categories_flat();
			$members = SpaceMember::list_by_space( $space_id );
			$access_rules = AccessRule::list_for_space( $space_id );
			$space_settings = Space::get_settings( $space_id );
			include JETONOMY_DIR . 'includes/admin/views/space-edit.php';
			return;
		}

		// List view
		$filter_category = absint( $_GET['category_id'] ?? 0 );
		$filter_type     = sanitize_text_field( $_GET['type'] ?? '' );
		$filter_status   = sanitize_text_field( $_GET['status'] ?? '' );

		$where = [ '1=1' ];
		if ( $filter_category ) {
			$where[] = $wpdb->prepare( 'category_id = %d', $filter_category );
		}
		if ( $filter_type && in_array( $filter_type, [ 'forum', 'qa', 'ideas', 'feed' ], true ) ) {
			$where[] = $wpdb->prepare( 'type = %s', $filter_type );
		}
		if ( $filter_status && in_array( $filter_status, [ 'active', 'archived', 'locked' ], true ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $filter_status );
		}

		$where_sql = implode( ' AND ', $where );
		$spaces = $wpdb->get_results( "SELECT * FROM " . table( 'spaces' ) . " WHERE {$where_sql} ORDER BY title ASC" );
		$categories = $this->get_all_categories_flat();

		include JETONOMY_DIR . 'includes/admin/views/spaces.php';
	}

	public function render_moderation(): void {
		global $wpdb;

		$pending_posts = $wpdb->get_results(
			"SELECT p.*, s.title as space_title FROM " . table( 'posts' ) . " p
			 LEFT JOIN " . table( 'spaces' ) . " s ON s.id = p.space_id
			 WHERE p.status = 'pending' ORDER BY p.created_at DESC LIMIT 50"
		) ?: [];

		$pending_replies = $wpdb->get_results(
			"SELECT r.*, p.title as post_title FROM " . table( 'replies' ) . " r
			 LEFT JOIN " . table( 'posts' ) . " p ON p.id = r.post_id
			 WHERE r.status = 'pending' ORDER BY r.created_at DESC LIMIT 50"
		) ?: [];

		$pending_flags = $wpdb->get_results(
			"SELECT * FROM " . table( 'flags' ) . " WHERE status = 'pending' ORDER BY created_at DESC LIMIT 50"
		) ?: [];

		$banned_users = $wpdb->get_results(
			"SELECT r.*, u.display_name, u.user_login FROM " . table( 'restrictions' ) . " r
			 LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
			 WHERE r.type IN ('global_ban','space_ban','silence')
			 AND (r.expires_at IS NULL OR r.expires_at > '" . now() . "')
			 ORDER BY r.created_at DESC"
		) ?: [];

		include JETONOMY_DIR . 'includes/admin/views/moderation.php';
	}

	public function render_users(): void {
		global $wpdb;

		$search      = sanitize_text_field( $_GET['s'] ?? '' );
		$filter_trust = sanitize_text_field( $_GET['trust_level'] ?? '' );
		$paged       = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page    = 20;
		$offset      = ( $paged - 1 ) * $per_page;

		$profiles_t = table( 'user_profiles' );

		$where = [ '1=1' ];
		$join_where = '';

		if ( '' !== $filter_trust && is_numeric( $filter_trust ) ) {
			$where[] = $wpdb->prepare( 'p.trust_level = %d', absint( $filter_trust ) );
		}
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(u.user_login LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)', $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$profiles_t} p
			 INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
			 WHERE {$where_sql}"
		);

		$users = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, u.user_login, u.display_name as wp_display_name, u.user_email, u.user_registered
				 FROM {$profiles_t} p
				 INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
				 WHERE {$where_sql}
				 ORDER BY p.reputation DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		) ?: [];

		$total_pages = ceil( $total / $per_page );

		include JETONOMY_DIR . 'includes/admin/views/users.php';
	}

	public function render_import(): void {
		Import_Manager::init();
		$available = Import_Manager::get_available();
		include JETONOMY_DIR . 'includes/admin/views/import.php';
	}

	public function render_settings(): void {
		$settings = get_option( 'jetonomy_settings', [] );
		include JETONOMY_DIR . 'includes/admin/views/settings.php';
	}

	/**
	 * Render the Extensions page — content provided by Pro via hook.
	 */
	public function render_extensions(): void {
		/**
		 * Fires to render the Extensions page content.
		 * Hooked by Jetonomy Pro to display the extensions manager.
		 */
		do_action( 'jetonomy_admin_render_extensions' );
	}

	/**
	 * Render the License page — content provided by Pro via hook.
	 */
	public function render_license(): void {
		/**
		 * Fires to render the License page content.
		 * Hooked by Jetonomy Pro to display the license form.
		 */
		do_action( 'jetonomy_admin_render_license' );
	}

	// ── Helpers ──

	private function get_all_categories_nested(): array {
		$top = Category::list_top_level();
		$result = [];
		foreach ( $top as $cat ) {
			$cat->children = Category::list_children( (int) $cat->id );
			$result[] = $cat;
		}
		return $result;
	}

	private function get_all_categories_flat(): array {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT * FROM ' . table( 'categories' ) . ' ORDER BY sort_order ASC, name ASC'
		) ?: [];
	}

	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Categories
	// ═══════════════════════════════════════════════════════════════

	public function ajax_create_category(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$name       = sanitize_text_field( $_POST['name'] ?? '' );
		$slug       = sanitize_title( $_POST['slug'] ?? $name );
		$desc       = wp_kses_post( $_POST['description'] ?? '' );
		$parent_id  = absint( $_POST['parent_id'] ?? 0 );
		$icon       = sanitize_text_field( $_POST['icon'] ?? '' );
		$color      = sanitize_hex_color( $_POST['color'] ?? '' );
		$visibility = sanitize_text_field( $_POST['visibility'] ?? 'public' );

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Name is required.', 'jetonomy' ) );
		}

		if ( ! in_array( $visibility, [ 'public', 'private', 'hidden' ], true ) ) {
			$visibility = 'public';
		}

		$id = Category::create( [
			'name'        => $name,
			'slug'        => $slug,
			'description' => $desc,
			'parent_id'   => $parent_id,
			'icon'        => $icon ?: null,
			'color'       => $color ?: null,
			'visibility'  => $visibility,
		] );

		if ( ! $id ) {
			wp_send_json_error( __( 'Failed to create category.', 'jetonomy' ) );
		}

		$category = Category::find( $id );
		wp_send_json_success( [
			'id'       => $id,
			'category' => $category,
			'message'  => __( 'Category created.', 'jetonomy' ),
		] );
	}

	public function ajax_update_category(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid category ID.', 'jetonomy' ) );
		}

		$data = [];
		if ( isset( $_POST['name'] ) ) {
			$data['name'] = sanitize_text_field( $_POST['name'] );
		}
		if ( isset( $_POST['slug'] ) ) {
			$data['slug'] = sanitize_title( $_POST['slug'] );
		}
		if ( isset( $_POST['description'] ) ) {
			$data['description'] = wp_kses_post( $_POST['description'] );
		}
		if ( isset( $_POST['parent_id'] ) ) {
			$data['parent_id'] = absint( $_POST['parent_id'] );
		}
		if ( isset( $_POST['icon'] ) ) {
			$data['icon'] = sanitize_text_field( $_POST['icon'] ) ?: null;
		}
		if ( isset( $_POST['color'] ) ) {
			$data['color'] = sanitize_hex_color( $_POST['color'] ) ?: null;
		}
		if ( isset( $_POST['visibility'] ) ) {
			$visibility = sanitize_text_field( $_POST['visibility'] );
			if ( in_array( $visibility, [ 'public', 'private', 'hidden' ], true ) ) {
				$data['visibility'] = $visibility;
			}
		}

		if ( empty( $data ) ) {
			wp_send_json_error( __( 'No data to update.', 'jetonomy' ) );
		}

		$result = Category::update( $id, $data );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to update category.', 'jetonomy' ) );
		}

		$category = Category::find( $id );
		wp_send_json_success( [
			'category' => $category,
			'message'  => __( 'Category updated.', 'jetonomy' ),
		] );
	}

	public function ajax_delete_category(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid category ID.', 'jetonomy' ) );
		}

		// Check for spaces in this category
		$spaces = Space::list_by_category( $id );
		if ( ! empty( $spaces ) ) {
			wp_send_json_error( __( 'Cannot delete a category that contains spaces. Move or delete the spaces first.', 'jetonomy' ) );
		}

		// Check for child categories
		$children = Category::list_children( $id );
		if ( ! empty( $children ) ) {
			wp_send_json_error( __( 'Cannot delete a category that has sub-categories. Delete them first.', 'jetonomy' ) );
		}

		$result = Category::delete( $id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to delete category.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Category deleted.', 'jetonomy' ) ] );
	}

	public function ajax_reorder_categories(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$order = $_POST['order'] ?? [];
		if ( ! is_array( $order ) ) {
			wp_send_json_error( __( 'Invalid order data.', 'jetonomy' ) );
		}

		foreach ( $order as $index => $cat_id ) {
			Category::update( absint( $cat_id ), [ 'sort_order' => absint( $index ) ] );
		}

		wp_send_json_success( [ 'message' => __( 'Order saved.', 'jetonomy' ) ] );
	}

	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Spaces
	// ═══════════════════════════════════════════════════════════════

	public function ajax_create_space(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$title       = sanitize_text_field( $_POST['title'] ?? '' );
		$slug        = sanitize_title( $_POST['slug'] ?? $title );
		$description = wp_kses_post( $_POST['description'] ?? '' );
		$category_id = absint( $_POST['category_id'] ?? 0 );
		$type        = sanitize_text_field( $_POST['type'] ?? 'forum' );
		$visibility  = sanitize_text_field( $_POST['visibility'] ?? 'public' );
		$join_policy = sanitize_text_field( $_POST['join_policy'] ?? 'open' );
		$icon        = sanitize_text_field( $_POST['icon'] ?? '' );
		$cover_image = esc_url_raw( $_POST['cover_image'] ?? '' );
		$status      = sanitize_text_field( $_POST['status'] ?? 'active' );

		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Title is required.', 'jetonomy' ) );
		}

		if ( ! in_array( $type, [ 'forum', 'qa', 'ideas', 'feed' ], true ) ) {
			$type = 'forum';
		}
		if ( ! in_array( $visibility, [ 'public', 'private', 'hidden' ], true ) ) {
			$visibility = 'public';
		}
		if ( ! in_array( $join_policy, [ 'open', 'approval', 'invite' ], true ) ) {
			$join_policy = 'open';
		}
		if ( ! in_array( $status, [ 'active', 'archived', 'locked' ], true ) ) {
			$status = 'active';
		}

		$id = Space::create( [
			'title'       => $title,
			'slug'        => $slug,
			'description' => $description,
			'category_id' => $category_id,
			'author_id'   => get_current_user_id(),
			'type'        => $type,
			'visibility'  => $visibility,
			'join_policy' => $join_policy,
			'icon'        => $icon ?: null,
			'cover_image' => $cover_image ?: null,
			'status'      => $status,
		] );

		if ( ! $id ) {
			wp_send_json_error( __( 'Failed to create space.', 'jetonomy' ) );
		}

		wp_send_json_success( [
			'id'      => $id,
			'message' => __( 'Space created.', 'jetonomy' ),
		] );
	}

	public function ajax_update_space(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid space ID.', 'jetonomy' ) );
		}

		$data = [];
		$allowed_fields = [
			'title'       => 'sanitize_text_field',
			'description' => 'wp_kses_post',
			'icon'        => 'sanitize_text_field',
		];

		foreach ( $allowed_fields as $field => $sanitizer ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = $sanitizer( $_POST[ $field ] );
			}
		}

		if ( isset( $_POST['slug'] ) ) {
			$data['slug'] = sanitize_title( $_POST['slug'] );
		}
		if ( isset( $_POST['category_id'] ) ) {
			$data['category_id'] = absint( $_POST['category_id'] );
		}
		if ( isset( $_POST['type'] ) ) {
			$type = sanitize_text_field( $_POST['type'] );
			if ( in_array( $type, [ 'forum', 'qa', 'ideas', 'feed' ], true ) ) {
				$data['type'] = $type;
			}
		}
		if ( isset( $_POST['visibility'] ) ) {
			$visibility = sanitize_text_field( $_POST['visibility'] );
			if ( in_array( $visibility, [ 'public', 'private', 'hidden' ], true ) ) {
				$data['visibility'] = $visibility;
			}
		}
		if ( isset( $_POST['join_policy'] ) ) {
			$join_policy = sanitize_text_field( $_POST['join_policy'] );
			if ( in_array( $join_policy, [ 'open', 'approval', 'invite' ], true ) ) {
				$data['join_policy'] = $join_policy;
			}
		}
		if ( isset( $_POST['status'] ) ) {
			$status = sanitize_text_field( $_POST['status'] );
			if ( in_array( $status, [ 'active', 'archived', 'locked' ], true ) ) {
				$data['status'] = $status;
			}
		}
		if ( isset( $_POST['cover_image'] ) ) {
			$data['cover_image'] = esc_url_raw( $_POST['cover_image'] ) ?: null;
		}
		if ( isset( $_POST['settings'] ) ) {
			$settings_raw = $_POST['settings'];
			if ( is_string( $settings_raw ) ) {
				$decoded = json_decode( wp_unslash( $settings_raw ), true );
				if ( is_array( $decoded ) ) {
					$data['settings'] = wp_json_encode( $decoded );
				}
			}
		}

		if ( empty( $data ) ) {
			wp_send_json_error( __( 'No data to update.', 'jetonomy' ) );
		}

		$data['updated_at'] = now();

		$result = Space::update( $id, $data );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to update space.', 'jetonomy' ) );
		}

		wp_send_json_success( [
			'message' => __( 'Space updated.', 'jetonomy' ),
		] );
	}

	public function ajax_delete_space(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid space ID.', 'jetonomy' ) );
		}

		$space = Space::find( $id );
		if ( ! $space ) {
			wp_send_json_error( __( 'Space not found.', 'jetonomy' ) );
		}

		// Decrement category space count
		if ( $space->category_id ) {
			Category::increment_space_count( (int) $space->category_id, -1 );
		}

		$result = Space::delete( $id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to delete space.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Space deleted.', 'jetonomy' ) ] );
	}

	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Space Members
	// ═══════════════════════════════════════════════════════════════

	public function ajax_add_space_member(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		$user_id  = absint( $_POST['user_id'] ?? 0 );
		$role     = sanitize_text_field( $_POST['role'] ?? 'member' );

		if ( ! $space_id || ! $user_id ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		if ( ! in_array( $role, [ 'viewer', 'member', 'moderator', 'admin' ], true ) ) {
			$role = 'member';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_error( __( 'User not found.', 'jetonomy' ) );
		}

		SpaceMember::add( $space_id, $user_id, $role );

		wp_send_json_success( [
			'message'      => sprintf( __( '%s added as %s.', 'jetonomy' ), $user->display_name, $role ),
			'user_id'      => $user_id,
			'display_name' => $user->display_name,
			'user_login'   => $user->user_login,
			'role'         => $role,
		] );
	}

	public function ajax_remove_space_member(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		$user_id  = absint( $_POST['user_id'] ?? 0 );

		if ( ! $space_id || ! $user_id ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		SpaceMember::remove( $space_id, $user_id );

		wp_send_json_success( [ 'message' => __( 'Member removed.', 'jetonomy' ) ] );
	}

	public function ajax_change_member_role(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		$user_id  = absint( $_POST['user_id'] ?? 0 );
		$role     = sanitize_text_field( $_POST['role'] ?? '' );

		if ( ! $space_id || ! $user_id || ! $role ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		if ( ! in_array( $role, [ 'viewer', 'member', 'moderator', 'admin' ], true ) ) {
			wp_send_json_error( __( 'Invalid role.', 'jetonomy' ) );
		}

		SpaceMember::add( $space_id, $user_id, $role );

		wp_send_json_success( [ 'message' => __( 'Role updated.', 'jetonomy' ) ] );
	}

	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Access Rules
	// ═══════════════════════════════════════════════════════════════

	public function ajax_add_access_rule(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id   = absint( $_POST['space_id'] ?? 0 );
		$rule_type  = sanitize_text_field( $_POST['rule_type'] ?? '' );
		$rule_value = sanitize_text_field( $_POST['rule_value'] ?? '' );
		$grants     = sanitize_text_field( $_POST['grants'] ?? 'read' );
		$space_role = sanitize_text_field( $_POST['space_role'] ?? 'viewer' );
		$priority   = absint( $_POST['priority'] ?? 0 );

		if ( ! $space_id ) {
			wp_send_json_error( __( 'Missing space ID.', 'jetonomy' ) );
		}

		$valid_types = [ 'membership', 'role', 'capability', 'trust_level', 'logged_in', 'everyone' ];
		if ( ! in_array( $rule_type, $valid_types, true ) ) {
			wp_send_json_error( __( 'Invalid rule type.', 'jetonomy' ) );
		}

		if ( ! in_array( $grants, [ 'read', 'participate', 'full' ], true ) ) {
			$grants = 'read';
		}

		if ( ! in_array( $space_role, [ 'viewer', 'member', 'moderator', 'admin' ], true ) ) {
			$space_role = 'viewer';
		}

		$id = AccessRule::create( [
			'space_id'   => $space_id,
			'rule_type'  => $rule_type,
			'rule_value' => $rule_value ?: null,
			'grants'     => $grants,
			'space_role' => $space_role,
			'priority'   => $priority,
		] );

		if ( ! $id ) {
			wp_send_json_error( __( 'Failed to create access rule.', 'jetonomy' ) );
		}

		$rule = AccessRule::find( $id );
		wp_send_json_success( [
			'id'      => $id,
			'rule'    => $rule,
			'message' => __( 'Access rule added.', 'jetonomy' ),
		] );
	}

	public function ajax_delete_access_rule(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid rule ID.', 'jetonomy' ) );
		}

		$result = AccessRule::delete( $id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to delete rule.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Access rule deleted.', 'jetonomy' ) ] );
	}

	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Moderation
	// ═══════════════════════════════════════════════════════════════

	public function ajax_approve_content(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$object_type = sanitize_text_field( $_POST['object_type'] ?? '' );
		$object_id   = absint( $_POST['object_id'] ?? 0 );

		if ( ! $object_id || ! in_array( $object_type, [ 'post', 'reply' ], true ) ) {
			wp_send_json_error( __( 'Invalid content.', 'jetonomy' ) );
		}

		if ( 'post' === $object_type ) {
			Post::update( $object_id, [ 'status' => 'publish' ] );
		} else {
			Reply::update( $object_id, [ 'status' => 'publish' ] );
		}

		wp_send_json_success( [ 'message' => __( 'Content approved.', 'jetonomy' ) ] );
	}

	public function ajax_spam_content(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$object_type = sanitize_text_field( $_POST['object_type'] ?? '' );
		$object_id   = absint( $_POST['object_id'] ?? 0 );

		if ( ! $object_id || ! in_array( $object_type, [ 'post', 'reply' ], true ) ) {
			wp_send_json_error( __( 'Invalid content.', 'jetonomy' ) );
		}

		if ( 'post' === $object_type ) {
			Post::update( $object_id, [ 'status' => 'spam' ] );
		} else {
			Reply::update( $object_id, [ 'status' => 'spam' ] );
		}

		wp_send_json_success( [ 'message' => __( 'Marked as spam.', 'jetonomy' ) ] );
	}

	public function ajax_trash_content(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$object_type = sanitize_text_field( $_POST['object_type'] ?? '' );
		$object_id   = absint( $_POST['object_id'] ?? 0 );

		if ( ! $object_id || ! in_array( $object_type, [ 'post', 'reply' ], true ) ) {
			wp_send_json_error( __( 'Invalid content.', 'jetonomy' ) );
		}

		if ( 'post' === $object_type ) {
			Post::update( $object_id, [ 'status' => 'trash' ] );
		} else {
			Reply::update( $object_id, [ 'status' => 'trash' ] );
		}

		wp_send_json_success( [ 'message' => __( 'Content trashed.', 'jetonomy' ) ] );
	}

	public function ajax_resolve_flag(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$flag_id    = absint( $_POST['flag_id'] ?? 0 );
		$resolution = sanitize_text_field( $_POST['resolution'] ?? '' );

		if ( ! $flag_id || ! in_array( $resolution, [ 'valid', 'dismissed' ], true ) ) {
			wp_send_json_error( __( 'Invalid flag data.', 'jetonomy' ) );
		}

		$flag = Flag::find( $flag_id );
		if ( ! $flag ) {
			wp_send_json_error( __( 'Flag not found.', 'jetonomy' ) );
		}

		Flag::resolve( $flag_id, get_current_user_id(), $resolution );

		// If valid, also trash the reported content
		if ( 'valid' === $resolution ) {
			if ( 'post' === $flag->object_type ) {
				Post::update( (int) $flag->object_id, [ 'status' => 'trash' ] );
			} elseif ( 'reply' === $flag->object_type ) {
				Reply::update( (int) $flag->object_id, [ 'status' => 'trash' ] );
			}
		}

		wp_send_json_success( [ 'message' => __( 'Flag resolved.', 'jetonomy' ) ] );
	}

	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Users
	// ═══════════════════════════════════════════════════════════════

	public function ajax_ban_user(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$user_id   = absint( $_POST['user_id'] ?? 0 );
		$type      = sanitize_text_field( $_POST['type'] ?? 'global_ban' );
		$reason    = sanitize_text_field( $_POST['reason'] ?? '' );
		$duration  = sanitize_text_field( $_POST['duration'] ?? 'permanent' );
		$space_id  = absint( $_POST['space_id'] ?? 0 );

		if ( ! $user_id ) {
			wp_send_json_error( __( 'Invalid user ID.', 'jetonomy' ) );
		}

		if ( ! in_array( $type, [ 'global_ban', 'space_ban', 'silence' ], true ) ) {
			$type = 'global_ban';
		}

		$expires_at = null;
		switch ( $duration ) {
			case '1d':
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
				break;
			case '7d':
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
				break;
			case '30d':
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
				break;
			case 'permanent':
			default:
				$expires_at = null;
				break;
		}

		if ( 'permanent' !== $duration && ! $expires_at ) {
			// Custom duration in days
			$custom_days = absint( $duration );
			if ( $custom_days > 0 ) {
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + $custom_days * DAY_IN_SECONDS );
			}
		}

		$restriction_id = Restriction::ban(
			$user_id,
			$type,
			get_current_user_id(),
			$space_id ?: null,
			$reason ?: null,
			$expires_at
		);

		if ( ! $restriction_id ) {
			wp_send_json_error( __( 'Failed to ban user.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'User banned.', 'jetonomy' ) ] );
	}

	public function ajax_unban_user(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$restriction_id = absint( $_POST['restriction_id'] ?? 0 );
		if ( ! $restriction_id ) {
			wp_send_json_error( __( 'Invalid restriction ID.', 'jetonomy' ) );
		}

		$result = Restriction::remove_ban( $restriction_id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to remove ban.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Ban removed.', 'jetonomy' ) ] );
	}

	public function ajax_change_trust_level(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$user_id     = absint( $_POST['user_id'] ?? 0 );
		$trust_level = absint( $_POST['trust_level'] ?? 0 );

		if ( ! $user_id ) {
			wp_send_json_error( __( 'Invalid user ID.', 'jetonomy' ) );
		}

		if ( $trust_level > 5 ) {
			$trust_level = 5;
		}

		UserProfile::find_or_create( $user_id );
		$result = UserProfile::update_profile( $user_id, [ 'trust_level' => $trust_level ] );

		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to update trust level.', 'jetonomy' ) );
		}

		wp_send_json_success( [
			'message'     => __( 'Trust level updated.', 'jetonomy' ),
			'trust_level' => $trust_level,
		] );
	}

	public function ajax_search_users(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$search = sanitize_text_field( $_POST['search'] ?? '' );
		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( [ 'users' => [] ] );
		}

		$users = get_users( [
			'search'         => '*' . $search . '*',
			'search_columns' => [ 'user_login', 'display_name', 'user_email' ],
			'number'         => 10,
		] );

		$results = [];
		foreach ( $users as $user ) {
			$results[] = [
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'avatar'       => get_avatar_url( $user->ID, [ 'size' => 32 ] ),
			];
		}

		wp_send_json_success( [ 'users' => $results ] );
	}

	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Import
	// ═══════════════════════════════════════════════════════════════

	public function ajax_run_import(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$source = sanitize_text_field( $_POST['source'] ?? '' );
		Import_Manager::init();
		$result = Import_Manager::run( $source );

		if ( null === $result ) {
			wp_send_json_error( __( 'Unknown import source.', 'jetonomy' ) );
		}

		flush_rewrite_rules();
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Run a single import batch (500 records) and return progress.
	 */
	public function ajax_import_batch(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$source     = sanitize_text_field( $_POST['source'] ?? '' );
		$phase      = sanitize_text_field( $_POST['phase'] ?? 'forums' );
		$offset     = absint( $_POST['offset'] ?? 0 );
		$batch_size = absint( $_POST['batch_size'] ?? 500 );

		Import_Manager::init();
		$importers = Import_Manager::get_importers();

		if ( ! isset( $importers[ $source ] ) ) {
			wp_send_json_error( __( 'Unknown import source.', 'jetonomy' ) );
		}

		$importer = $importers[ $source ];

		// Restore ID map from previous batch.
		$importer->id_map = get_option( 'jetonomy_import_id_map', [] );

		// Save resume point so the import can be resumed if interrupted.
		$existing_resume = get_option( 'jetonomy_import_resume', [] );
		update_option( 'jetonomy_import_resume', [
			'source'     => $source,
			'phase'      => $phase,
			'offset'     => $offset,
			'batch_size' => $batch_size,
			'started_at' => $existing_resume['started_at'] ?? current_time( 'mysql' ),
		], false );

		// Run one batch.
		$result = $importer->run_batch( $phase, $offset, $batch_size );

		// Calculate overall progress.
		$total           = $importer->get_total_count();
		$total_processed = absint( get_option( 'jetonomy_import_total_processed', 0 ) ) + $result['processed'];
		update_option( 'jetonomy_import_total_processed', $total_processed, false );

		$percent = $total > 0 ? min( 100, round( ( $total_processed / $total ) * 100, 1 ) ) : 0;

		$phase_labels = [
			'forums'   => __( 'Importing forums...', 'jetonomy' ),
			'topics'   => __( 'Importing topics...', 'jetonomy' ),
			'replies'  => __( 'Importing replies...', 'jetonomy' ),
			'profiles' => __( 'Creating user profiles...', 'jetonomy' ),
			'recount'  => __( 'Recounting statistics...', 'jetonomy' ),
			'complete' => __( 'Import complete!', 'jetonomy' ),
		];

		// Save progress for polling endpoint.
		$importer->save_progress( [
			'status'    => $result['done'] ? 'complete' : 'running',
			'phase'     => $result['phase'],
			'processed' => $total_processed,
			'total'     => $total,
			'percent'   => $percent,
			'message'   => $phase_labels[ $result['phase'] ] ?? '',
		] );

		if ( $result['done'] ) {
			// Save completion record to import history.
			$history            = get_option( 'jetonomy_import_history', [] );
			$history[ $source ] = [
				'completed_at' => current_time( 'mysql' ),
				'imported'     => $total_processed,
				'source'       => $source,
				'source_name'  => $importers[ $source ]->get_source_name(),
			];
			update_option( 'jetonomy_import_history', $history );

			// Clear transient state.
			delete_option( 'jetonomy_import_resume' );
			delete_option( 'jetonomy_import_total_processed' );
			delete_option( 'jetonomy_import_id_map' );
			\Jetonomy\Import\Importer::clear_progress();
		}

		wp_send_json_success( [
			'phase'     => $result['phase'],
			'offset'    => $result['offset'],
			'done'      => $result['done'],
			'processed' => $total_processed,
			'total'     => $total,
			'percent'   => $percent,
			'message'   => $phase_labels[ $result['phase'] ] ?? '',
		] );
	}

	/**
	 * AJAX: Return current import progress for polling.
	 */
	public function ajax_import_progress(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		$progress = \Jetonomy\Import\Importer::get_progress();
		wp_send_json_success( $progress );
	}

	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Misc
	// ═══════════════════════════════════════════════════════════════

	public function ajax_test_email(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$admin_email = get_option( 'admin_email' );
		$result = wp_mail(
			$admin_email,
			__( 'Jetonomy Test Email', 'jetonomy' ),
			__( 'This is a test email from your Jetonomy forum plugin. If you received this, email is working correctly.', 'jetonomy' )
		);

		if ( $result ) {
			wp_send_json_success( [
				'message' => sprintf(
					__( 'Test email sent to %s.', 'jetonomy' ),
					$admin_email
				),
			] );
		} else {
			wp_send_json_error( __( 'Failed to send test email. Check your WordPress email configuration.', 'jetonomy' ) );
		}
	}

	public function ajax_flush_rules(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		flush_rewrite_rules();
		wp_send_json_success( [ 'message' => __( 'Rewrite rules flushed.', 'jetonomy' ) ] );
	}

	// ═══════════════════════════════════════════════════════════════
	//  Content Management
	// ═══════════════════════════════════════════════════════════════

	public function render_content(): void {
		global $wpdb;
		$posts_t  = table( 'posts' );
		$spaces_t = table( 'spaces' );

		$current_space  = absint( $_GET['space_id'] ?? 0 );
		$current_status = sanitize_text_field( $_GET['status'] ?? 'all' );
		$search_query   = sanitize_text_field( $_GET['s'] ?? '' );

		$spaces = $wpdb->get_results( "SELECT id, title FROM {$spaces_t} ORDER BY title ASC" ) ?: [];

		$where = '1=1';
		$args  = [];
		if ( $current_space ) {
			$where .= ' AND p.space_id = %d';
			$args[] = $current_space;
		}
		if ( 'all' !== $current_status ) {
			$where .= ' AND p.status = %s';
			$args[] = $current_status;
		}
		if ( $search_query ) {
			$where .= ' AND p.title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search_query ) . '%';
		}

		$sql = "SELECT p.*, s.title AS space_title, s.slug AS space_slug
		        FROM {$posts_t} p
		        LEFT JOIN {$spaces_t} s ON s.id = p.space_id
		        WHERE {$where}
		        ORDER BY p.created_at DESC
		        LIMIT 100";

		$posts = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_results( $sql );
		$posts = $posts ?: [];

		include JETONOMY_DIR . 'includes/admin/views/content.php';
	}

	public function ajax_update_post(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'jetonomy' ) );
		}

		$data = [];
		if ( isset( $_POST['title'] ) ) {
			$data['title'] = sanitize_text_field( $_POST['title'] );
		}
		if ( isset( $_POST['content'] ) ) {
			$data['content']       = wp_kses_post( $_POST['content'] );
			$data['content_plain'] = wp_strip_all_tags( $data['content'] );
		}
		if ( isset( $_POST['status'] ) ) {
			$data['status'] = sanitize_text_field( $_POST['status'] );
		}

		if ( empty( $data ) ) {
			wp_send_json_error( __( 'Nothing to update.', 'jetonomy' ) );
		}

		$data['edited_at'] = current_time( 'mysql' );
		$data['edited_by'] = get_current_user_id();

		Post::update( $id, $data );
		wp_send_json_success( [ 'message' => __( 'Post updated.', 'jetonomy' ) ] );
	}

	public function ajax_delete_post(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id     = absint( $_POST['post_id'] ?? 0 );
		$status = sanitize_text_field( $_POST['status'] ?? 'trash' );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'jetonomy' ) );
		}

		Post::update( $id, [ 'status' => $status ] );

		do_action( 'jetonomy_content_moderated', $status, 'post', $id, get_current_user_id() );

		wp_send_json_success();
	}

	public function ajax_update_reply(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['reply_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid reply ID.', 'jetonomy' ) );
		}

		$data = [];
		if ( isset( $_POST['content'] ) ) {
			$data['content']       = wp_kses_post( $_POST['content'] );
			$data['content_plain'] = wp_strip_all_tags( $data['content'] );
		}
		if ( isset( $_POST['status'] ) ) {
			$data['status'] = sanitize_text_field( $_POST['status'] );
		}

		if ( empty( $data ) ) {
			wp_send_json_error( __( 'Nothing to update.', 'jetonomy' ) );
		}

		$data['edited_at'] = current_time( 'mysql' );
		$data['edited_by'] = get_current_user_id();

		Reply::update( $id, $data );
		wp_send_json_success( [ 'message' => __( 'Reply updated.', 'jetonomy' ) ] );
	}

	public function ajax_delete_reply(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id     = absint( $_POST['reply_id'] ?? 0 );
		$status = sanitize_text_field( $_POST['status'] ?? 'trash' );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid reply ID.', 'jetonomy' ) );
		}

		Reply::update( $id, [ 'status' => $status ] );
		wp_send_json_success();
	}

	public function ajax_get_replies(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'jetonomy' ) );
		}

		$replies = Reply::list_by_post( $post_id, 'oldest', 100, 0, 0 );
		$items   = [];
		foreach ( $replies as $r ) {
			$author  = get_userdata( (int) $r->author_id );
			$items[] = [
				'id'          => (int) $r->id,
				'author_name' => $author ? $author->display_name : __( 'Unknown', 'jetonomy' ),
				'content'     => $r->content ?? '',
				'status'      => $r->status ?? 'publish',
				'created_at'  => $r->created_at ?? '',
			];
		}

		wp_send_json_success( $items );
	}

	public function ajax_bulk_content_action(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$action = sanitize_text_field( $_POST['bulk_action'] ?? '' );
		$ids    = array_map( 'absint', (array) ( $_POST['ids'] ?? [] ) );
		$type   = sanitize_text_field( $_POST['type'] ?? 'post' );

		if ( empty( $ids ) || ! in_array( $action, [ 'trash', 'spam', 'publish' ], true ) ) {
			wp_send_json_error( __( 'Invalid bulk action.', 'jetonomy' ) );
		}

		foreach ( $ids as $id ) {
			if ( 'post' === $type ) {
				Post::update( $id, [ 'status' => $action ] );
			} else {
				Reply::update( $id, [ 'status' => $action ] );
			}
		}

		wp_send_json_success( [ 'updated' => count( $ids ) ] );
	}

	// ═══════════════════════════════════════════════════════════════
	//  Setup Wizard
	// ═══════════════════════════════════════════════════════════════

	public function render_setup(): void {
		include JETONOMY_DIR . 'includes/admin/views/setup-wizard.php';
	}

	public function ajax_setup_save(): void {
		check_ajax_referer( 'jetonomy_setup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$settings = get_option( 'jetonomy_settings', [] );
		$settings['base_slug']    = sanitize_title( $_POST['base_slug'] ?? 'community' );
		$settings['default_type'] = sanitize_text_field( $_POST['default_type'] ?? 'forum' );
		$settings['guest_read']   = true;
		update_option( 'jetonomy_settings', $settings );

		// Create category + space.
		$cat_name   = sanitize_text_field( $_POST['category_name'] ?? 'General' );
		$space_name = sanitize_text_field( $_POST['space_name'] ?? 'Community Discussion' );
		$space_desc = sanitize_textarea_field( $_POST['space_description'] ?? '' );
		$space_type = $settings['default_type'];

		$cat_id = Category::create( [
			'name'       => $cat_name,
			'slug'       => sanitize_title( $cat_name ),
			'visibility' => 'public',
		] );

		$space_id = Space::create( [
			'category_id' => $cat_id,
			'author_id'   => get_current_user_id(),
			'type'        => $space_type,
			'title'       => $space_name,
			'slug'        => sanitize_title( $space_name ),
			'description' => $space_desc,
			'visibility'  => 'public',
			'join_policy' => 'open',
		] );

		// Add admin as space member.
		SpaceMember::add( $space_id, get_current_user_id(), 'admin' );

		// Create user profile for admin.
		UserProfile::find_or_create( get_current_user_id() );

		// Flush rewrite rules with new base slug.
		flush_rewrite_rules();

		// Mark setup as complete.
		update_option( 'jetonomy_setup_complete', true );

		wp_send_json_success( [ 'category_id' => $cat_id, 'space_id' => $space_id ] );
	}

	public function ajax_setup_create_sample(): void {
		check_ajax_referer( 'jetonomy_setup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$uid = get_current_user_id();
		UserProfile::find_or_create( $uid );

		// Track all IDs for cleanup.
		$demo = [ 'categories' => [], 'spaces' => [], 'posts' => [], 'replies' => [] ];

		$settings = get_option( 'jetonomy_settings', [] );
		$settings['base_slug']    = sanitize_title( $_POST['base_slug'] ?? 'community' );
		$settings['default_type'] = sanitize_text_field( $_POST['default_type'] ?? 'forum' );
		$settings['guest_read']   = true;
		update_option( 'jetonomy_settings', $settings );

		// ── Categories ──

		$cat1 = Category::create( [
			'name'        => 'Product & Engineering',
			'slug'        => 'product-engineering',
			'description' => 'Technical discussions, bug reports, and development workflows.',
			'visibility'  => 'public',
		] );
		$demo['categories'][] = $cat1;

		$cat2 = Category::create( [
			'name'        => 'Community',
			'slug'        => 'community-hub',
			'description' => 'Everything about our community — introductions, events, and general chat.',
			'visibility'  => 'public',
		] );
		$demo['categories'][] = $cat2;

		// ── Spaces ──

		$s_welcome = Space::create( [
			'category_id' => $cat2, 'author_id' => $uid, 'type' => 'forum',
			'title'       => 'Welcome & Introductions',
			'slug'        => 'welcome',
			'description' => 'New here? Introduce yourself and say hello to the community.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_welcome;

		$s_general = Space::create( [
			'category_id' => $cat2, 'author_id' => $uid, 'type' => 'forum',
			'title'       => 'General Discussion',
			'slug'        => 'general-discussion',
			'description' => 'Off-topic conversations, industry news, and anything that doesn\'t fit elsewhere.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_general;

		$s_help = Space::create( [
			'category_id' => $cat1, 'author_id' => $uid, 'type' => 'qa',
			'title'       => 'Help & Support',
			'slug'        => 'help-support',
			'description' => 'Ask questions and get answers from experienced community members.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_help;

		$s_ideas = Space::create( [
			'category_id' => $cat1, 'author_id' => $uid, 'type' => 'ideas',
			'title'       => 'Feature Requests',
			'slug'        => 'feature-requests',
			'description' => 'Submit ideas, vote on what matters, and shape our roadmap together.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_ideas;

		$s_tips = Space::create( [
			'category_id' => $cat1, 'author_id' => $uid, 'type' => 'forum',
			'title'       => 'Tips & Best Practices',
			'slug'        => 'tips-best-practices',
			'description' => 'Share workflows, shortcuts, and hard-won lessons with fellow members.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_tips;

		// Memberships.
		foreach ( $demo['spaces'] as $sid ) {
			SpaceMember::add( $sid, $uid, 'admin' );
		}

		// ── Posts with realistic content ──

		$posts_data = [
			// Welcome space.
			[
				'space_id' => $s_welcome, 'type' => 'topic',
				'title'    => 'Welcome to our community — here\'s how it works',
				'content'  => '<p>Hey everyone! We\'re excited to have you here.</p><p>This community is built for real conversations — no algorithms, no noise. Here\'s a quick orientation:</p><ul><li><strong>Spaces</strong> are topic-specific areas. Browse the ones that interest you and join freely.</li><li><strong>Reputation</strong> grows as you contribute. Higher trust levels unlock more features.</li><li><strong>Voting</strong> helps the best content rise to the top. Use it generously.</li></ul><p>Don\'t be shy — introduce yourself below or jump straight into a discussion. Welcome aboard!</p>',
			],
			[
				'space_id' => $s_welcome, 'type' => 'topic',
				'title'    => 'Community guidelines — the short version',
				'content'  => '<p>We keep things simple. Three principles:</p><ol><li><strong>Be respectful.</strong> Disagree with ideas, not with people. No personal attacks.</li><li><strong>Be helpful.</strong> If someone asks a question, try to answer it — or point them in the right direction.</li><li><strong>Stay on topic.</strong> Each space has a purpose. Use General Discussion for everything else.</li></ol><p>Moderators are here to keep conversations productive. If you see something that doesn\'t belong, use the flag button. Thanks for helping us build a great community.</p>',
			],
			// General Discussion.
			[
				'space_id' => $s_general, 'type' => 'topic',
				'title'    => 'What\'s everyone working on this week?',
				'content'  => '<p>I always find it motivating to hear what others are building. I\'m currently migrating a client\'s legacy forum to this platform — the import tools have been surprisingly smooth.</p><p>What\'s on your plate? Drop a quick update below.</p>',
			],
			[
				'space_id' => $s_general, 'type' => 'topic',
				'title'    => 'Interesting article: the future of online communities',
				'content'  => '<p>Came across a thoughtful piece about how community platforms are shifting away from engagement metrics toward meaningful interactions. The core argument is that smaller, focused communities consistently outperform large social networks for professional learning.</p><p>Curious what you all think — does that match your experience?</p>',
			],
			// Help & Support (Q&A).
			[
				'space_id' => $s_help, 'type' => 'question',
				'title'    => 'How do I customize the notification settings?',
				'content'  => '<p>I\'m getting email notifications for every reply in spaces I\'ve joined. Is there a way to set it to daily digest instead? I looked in my profile settings but couldn\'t find the option.</p><p>Running the latest version on WordPress 6.9 with the BuddyX theme.</p>',
			],
			[
				'space_id' => $s_help, 'type' => 'question',
				'title'    => 'Can I restrict a space to specific membership levels?',
				'content'  => '<p>We have a premium membership tier using MemberPress. I want to create a space that\'s only visible to members at the "Pro" level and above.</p><p>I see there\'s an Access Rules section in the space settings — is that the right place? What should the configuration look like?</p>',
			],
			[
				'space_id' => $s_help, 'type' => 'question',
				'title'    => 'Best approach for migrating from bbPress?',
				'content'  => '<p>We have about 3,000 topics and 12,000 replies in bbPress. Before I hit the import button, a few questions:</p><ul><li>Does the importer preserve the original post dates?</li><li>What happens to forum categories — do they become spaces?</li><li>Is there a way to do a dry run first?</li></ul><p>Any migration tips from people who\'ve done this would be really helpful.</p>',
			],
			// Feature Requests.
			[
				'space_id' => $s_ideas, 'type' => 'idea',
				'title'    => 'Dark mode toggle in user preferences',
				'content'  => '<p>It would be great if users could switch to a dark color scheme directly from their profile preferences, independent of the system theme. Many of us work late and a dark mode would reduce eye strain significantly.</p><p>Ideally it should respect the theme\'s dark palette if one exists, and fall back to a sensible default otherwise.</p>',
			],
			[
				'space_id' => $s_ideas, 'type' => 'idea',
				'title'    => 'Saved/bookmarked posts for quick reference',
				'content'  => '<p>I often find great answers in Q&A threads but have no way to save them for later. A simple "bookmark" or "save" button on posts and replies would be incredibly useful.</p><p>Bonus points if there\'s a "My Saved" page where I can see all my bookmarks organized by space.</p>',
			],
			// Tips & Best Practices.
			[
				'space_id' => $s_tips, 'type' => 'topic',
				'title'    => 'Setting up your community for the first 100 members',
				'content'  => '<p>After launching three communities over the past two years, here\'s what I\'ve learned about the critical first 100 members:</p><ol><li><strong>Seed the content yourself.</strong> Nobody wants to post in an empty forum. Write 10-15 quality topics across different spaces before inviting anyone.</li><li><strong>Personal invitations beat mass emails.</strong> Send individual messages to people you know will contribute.</li><li><strong>Respond to everything.</strong> For the first month, reply to every single post. People come back when they feel heard.</li><li><strong>Celebrate first-time posters.</strong> A simple "Great first post, welcome!" goes a long way.</li></ol><p>The goal isn\'t growth — it\'s establishing the culture. Get the first 100 right and the next 1,000 takes care of itself.</p>',
			],
			[
				'space_id' => $s_tips, 'type' => 'topic',
				'title'    => 'Keyboard shortcuts you might not know about',
				'content'  => '<p>Quick productivity tip — this platform has built-in keyboard shortcuts:</p><ul><li><code>j</code> / <code>k</code> — Navigate between topics</li><li><code>l</code> — Upvote the current topic</li><li><code>r</code> — Open the reply composer</li><li><code>n</code> — New post</li><li><code>/</code> — Focus the search bar</li><li><code>?</code> — Show the full shortcut help</li></ul><p>Try pressing <code>?</code> anywhere on the community pages to see the complete list.</p>',
			],
		];

		foreach ( $posts_data as $p ) {
			$pid = Post::create( [
				'space_id'      => $p['space_id'],
				'author_id'     => $uid,
				'type'          => $p['type'],
				'title'         => $p['title'],
				'slug'          => sanitize_title( $p['title'] ),
				'content'       => $p['content'],
				'content_plain' => wp_strip_all_tags( $p['content'] ),
				'status'        => 'publish',
			] );
			$demo['posts'][] = $pid;
		}

		// ── Replies that form actual conversations ──

		$replies_data = [
			// Welcome post — 3 replies.
			[ 'post_idx' => 0, 'content' => '<p>This is exactly the kind of community space I\'ve been looking for. Clean interface, no distractions. Happy to be here!</p>' ],
			[ 'post_idx' => 0, 'content' => '<p>Love the reputation system — it\'s a smart way to build trust gradually. Looking forward to contributing.</p>' ],
			[ 'post_idx' => 0, 'content' => '<p>Just joined today. Coming from a Discourse community that got too noisy. This feels much more focused already.</p>' ],

			// Guidelines — 2 replies.
			[ 'post_idx' => 1, 'content' => '<p>Simple and clear — the best kind of community guidelines. Bookmarked for reference.</p>' ],
			[ 'post_idx' => 1, 'content' => '<p>Appreciate that flagging is encouraged. In my experience that\'s the best way to keep forums healthy without over-moderating.</p>' ],

			// What are you working on? — 3 replies.
			[ 'post_idx' => 2, 'content' => '<p>Currently setting up a knowledge base for our support team. We\'re using the Q&A space type which is perfect for structured answers. The voting system helps surface the best solutions.</p>' ],
			[ 'post_idx' => 2, 'content' => '<p>Building out a members-only community for our online course. The MemberPress integration was a game-changer — took about 10 minutes to set up space-level access rules.</p>' ],
			[ 'post_idx' => 2, 'content' => '<p>Rebuilding our company intranet forum. We had bbPress before and the migration import handled 8,000+ posts without a hitch. Pretty impressed so far.</p>' ],

			// Notification settings Q&A — 2 replies.
			[ 'post_idx' => 4, 'content' => '<p>Go to your profile → Edit → scroll down to the <strong>Notification Preferences</strong> section. You can choose between instant, daily digest, and weekly digest for each type of notification.</p><p>If the section isn\'t visible, make sure you\'re running at least version 1.0. The email digest feature requires Pro.</p>' ],
			[ 'post_idx' => 4, 'content' => '<p>Adding to the above — you can also unsubscribe from individual spaces by clicking the bell icon on the space page. That way you only get notifications for spaces you actively follow.</p>' ],

			// Access rules Q&A — 2 replies.
			[ 'post_idx' => 5, 'content' => '<p>Yes, Access Rules is the right section. Here\'s the setup:</p><ol><li>Edit the space → Access Rules tab</li><li>Click "Add Rule"</li><li>Set Type to "Membership", Level to "Pro"</li><li>Set the space visibility to "Private"</li></ol><p>Members at the Pro level and above will see the space automatically. Others won\'t even know it exists.</p>' ],
			[ 'post_idx' => 5, 'content' => '<p>One thing to note — if you\'re using the PMPro adapter instead of MemberPress, the setup is identical. The adapter pattern means all membership plugins work the same way from the community side.</p>' ],

			// bbPress migration — 2 replies.
			[ 'post_idx' => 6, 'content' => '<p>I migrated about 5,000 topics last week. To answer your questions:</p><ul><li>Yes, original dates are preserved. Posts appear in the correct chronological order.</li><li>bbPress forums become spaces; forum categories become Jetonomy categories.</li><li>Use the CLI command <code>wp jetonomy import --source=bbpress --dry-run</code> to preview without actually importing.</li></ul><p>Tip: run a database backup first. The importer is non-destructive (doesn\'t delete bbPress data) but better safe than sorry.</p>' ],
			[ 'post_idx' => 6, 'content' => '<p>Did the same migration last month. The batched import was a lifesaver — we have 12,000 replies and it handled them in chunks with a progress bar. No timeouts. Took about 4 minutes total.</p>' ],

			// Dark mode idea — 2 replies.
			[ 'post_idx' => 7, 'content' => '<p>Fully support this. A lot of developer communities default to dark mode now. It would be great to see it built into the user preferences rather than relying on browser extensions.</p>' ],
			[ 'post_idx' => 7, 'content' => '<p>If the theme supports a dark palette via theme.json, would it make sense to just toggle the CSS custom properties? That way it stays consistent with the overall site design.</p>' ],

			// Bookmarks idea — 1 reply.
			[ 'post_idx' => 8, 'content' => '<p>Yes please! I find myself copying URLs into a note-taking app which is not ideal. A native bookmark system would save me so much time. Especially in Q&A spaces where the accepted answers are gold.</p>' ],

			// First 100 members — 2 replies.
			[ 'post_idx' => 9, 'content' => '<p>Point 3 is so true. Early on, I was the only person replying in our community. It felt slow, but within a month people started replying to each other. That transition from "founder answers everything" to "community helps itself" is magical when it happens.</p>' ],
			[ 'post_idx' => 9, 'content' => '<p>Great advice. I\'d add one more: <strong>create rituals</strong>. A weekly "What are you working on?" thread or a monthly AMA gives people a reason to come back regularly. Consistency beats novelty.</p>' ],
		];

		foreach ( $replies_data as $rd ) {
			$post_id = $demo['posts'][ $rd['post_idx'] ] ?? 0;
			if ( ! $post_id ) {
				continue;
			}
			$rid = Reply::create( [
				'post_id'       => $post_id,
				'author_id'     => $uid,
				'content'       => $rd['content'],
				'content_plain' => wp_strip_all_tags( $rd['content'] ),
				'status'        => 'publish',
			] );
			$demo['replies'][] = $rid;
		}

		// ── Seed badges if Pro is active ──

		if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
			global $wpdb;
			$badges_t = $wpdb->prefix . 'jt_badges';

			// Check if badges table exists.
			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$badges_t}'" );
			if ( $table_exists ) {
				$now = current_time( 'mysql' );
				$demo['badges'] = [];

				$badge_data = [
					[ 'name' => 'First Post',       'description' => 'Created your first post in the community.',                   'icon' => 'pencil',  'tier' => 'bronze', 'criteria_type' => 'post_count',  'criteria_value' => 1 ],
					[ 'name' => 'Conversation Starter', 'description' => 'Started 10 discussions that got replies.',                'icon' => 'chat',    'tier' => 'silver', 'criteria_type' => 'post_count',  'criteria_value' => 10 ],
					[ 'name' => 'Helpful Member',    'description' => 'Posted 25 replies that helped fellow members.',                'icon' => 'heart',   'tier' => 'bronze', 'criteria_type' => 'reply_count', 'criteria_value' => 25 ],
					[ 'name' => 'Community Pillar',   'description' => 'Reached 100 reputation points through quality contributions.', 'icon' => 'star',    'tier' => 'gold',   'criteria_type' => 'reputation',  'criteria_value' => 100 ],
					[ 'name' => 'Rising Star',        'description' => 'Earned Trust Level 2 through consistent participation.',       'icon' => 'rocket',  'tier' => 'silver', 'criteria_type' => 'trust_level', 'criteria_value' => 2 ],
					[ 'name' => 'Veteran',            'description' => 'Been an active member for over 30 days.',                     'icon' => 'shield',  'tier' => 'bronze', 'criteria_type' => 'days_active', 'criteria_value' => 30 ],
					[ 'name' => 'Top Contributor',    'description' => 'Reached 500 reputation — a true community leader.',            'icon' => 'trophy',  'tier' => 'gold',   'criteria_type' => 'reputation',  'criteria_value' => 500 ],
					[ 'name' => 'Early Adopter',      'description' => 'Joined during the community launch period.',                   'icon' => 'flag',    'tier' => 'silver', 'criteria_type' => 'manual',      'criteria_value' => 0 ],
				];

				foreach ( $badge_data as $b ) {
					$wpdb->insert( $badges_t, [
						'name'           => $b['name'],
						'description'    => $b['description'],
						'icon'           => $b['icon'],
						'tier'           => $b['tier'],
						'criteria_type'  => $b['criteria_type'],
						'criteria_value' => $b['criteria_value'],
						'is_active'      => 1,
						'created_at'     => $now,
					] );
					$demo['badges'][] = (int) $wpdb->insert_id;
				}

				// Award "Early Adopter" + "First Post" to the admin user.
				$user_badges_t = $wpdb->prefix . 'jt_user_badges';
				$ub_exists     = $wpdb->get_var( "SHOW TABLES LIKE '{$user_badges_t}'" );
				if ( $ub_exists && ! empty( $demo['badges'] ) ) {
					// Early Adopter = last badge.
					$wpdb->insert( $user_badges_t, [ 'user_id' => $uid, 'badge_id' => end( $demo['badges'] ), 'awarded_at' => $now ] );
					// First Post = first badge.
					$wpdb->insert( $user_badges_t, [ 'user_id' => $uid, 'badge_id' => $demo['badges'][0], 'awarded_at' => $now ] );
				}
			}
		}

		// Store demo data IDs for cleanup.
		update_option( 'jetonomy_demo_data', $demo );

		flush_rewrite_rules();
		update_option( 'jetonomy_setup_complete', true );

		wp_send_json_success( [ 'message' => __( 'Sample community created with realistic content.', 'jetonomy' ) ] );
	}

	/**
	 * Remove all demo data created by the setup wizard.
	 */
	public function ajax_cleanup_sample_data(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$demo = get_option( 'jetonomy_demo_data', [] );
		if ( empty( $demo ) ) {
			wp_send_json_error( __( 'No demo data found to clean up.', 'jetonomy' ) );
		}

		global $wpdb;

		// Delete replies first (foreign key safety).
		if ( ! empty( $demo['replies'] ) ) {
			$ids = implode( ',', array_map( 'absint', $demo['replies'] ) );
			$wpdb->query( "DELETE FROM " . table( 'replies' ) . " WHERE id IN ({$ids})" );
		}

		// Delete posts.
		if ( ! empty( $demo['posts'] ) ) {
			$ids = implode( ',', array_map( 'absint', $demo['posts'] ) );
			$wpdb->query( "DELETE FROM " . table( 'posts' ) . " WHERE id IN ({$ids})" );
		}

		// Remove space memberships and spaces.
		if ( ! empty( $demo['spaces'] ) ) {
			$ids = implode( ',', array_map( 'absint', $demo['spaces'] ) );
			$wpdb->query( "DELETE FROM " . table( 'space_members' ) . " WHERE space_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM " . table( 'spaces' ) . " WHERE id IN ({$ids})" );
		}

		// Delete categories.
		if ( ! empty( $demo['categories'] ) ) {
			$ids = implode( ',', array_map( 'absint', $demo['categories'] ) );
			$wpdb->query( "DELETE FROM " . table( 'categories' ) . " WHERE id IN ({$ids})" );
		}

		// Delete badges (Pro).
		if ( ! empty( $demo['badges'] ) ) {
			$badges_t      = $wpdb->prefix . 'jt_badges';
			$user_badges_t = $wpdb->prefix . 'jt_user_badges';
			$ids           = implode( ',', array_map( 'absint', $demo['badges'] ) );
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$user_badges_t}'" ) ) {
				$wpdb->query( "DELETE FROM {$user_badges_t} WHERE badge_id IN ({$ids})" );
			}
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$badges_t}'" ) ) {
				$wpdb->query( "DELETE FROM {$badges_t} WHERE id IN ({$ids})" );
			}
		}

		delete_option( 'jetonomy_demo_data' );

		wp_send_json_success( [ 'message' => __( 'All demo data has been removed.', 'jetonomy' ) ] );
	}
}
