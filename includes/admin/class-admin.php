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

		// Import AJAX
		add_action( 'wp_ajax_jetonomy_run_import', [ $this, 'ajax_run_import' ] );

		// Settings AJAX
		add_action( 'wp_ajax_jetonomy_test_email', [ $this, 'ajax_test_email' ] );
		add_action( 'wp_ajax_jetonomy_flush_rules', [ $this, 'ajax_flush_rules' ] );
	}

	// ── Menu ──

	public function add_menu(): void {
		add_menu_page(
			__( 'Jetonomy', 'jetonomy' ),
			__( 'Jetonomy', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy',
			[ $this, 'render_dashboard' ],
			'dashicons-groups',
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
			'jetonomy_manage_spaces',
			'jetonomy-spaces',
			[ $this, 'render_spaces' ]
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
}
