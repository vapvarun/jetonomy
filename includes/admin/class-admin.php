<?php
namespace Jetonomy\Admin;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;
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

		new Ajax\Categories_Handler();
		new Ajax\Spaces_Handler();
		new Ajax\Moderation_Handler();
		new Ajax\Users_Handler();

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

		$where_sql   = implode( ' AND ', $where );
		$spaces_t    = table( 'spaces' );
		$paged       = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page    = 20;
		$offset      = ( $paged - 1 ) * $per_page;
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$spaces_t} WHERE {$where_sql}" );
		$total_pages = (int) ceil( $total / $per_page );
		$spaces      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$spaces_t} WHERE {$where_sql} ORDER BY title ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		) ?: [];
		$categories  = $this->get_all_categories_flat();

		include JETONOMY_DIR . 'includes/admin/views/spaces.php';
	}

	public function render_moderation(): void {
		global $wpdb;

		$posts_t        = table( 'posts' );
		$replies_t      = table( 'replies' );
		$flags_t        = table( 'flags' );
		$restrictions_t = table( 'restrictions' );
		$per_page       = 20;

		// Per-tab paged params.
		$paged_posts   = max( 1, absint( $_GET['paged_posts'] ?? 1 ) );
		$paged_replies = max( 1, absint( $_GET['paged_replies'] ?? 1 ) );
		$paged_flags   = max( 1, absint( $_GET['paged_flags'] ?? 1 ) );
		$paged_banned  = max( 1, absint( $_GET['paged_banned'] ?? 1 ) );

		// Real totals for tab badge counts.
		$total_posts   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$posts_t} WHERE status = 'pending'" );
		$total_replies = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$replies_t} WHERE status = 'pending'" );
		$total_flags   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flags_t} WHERE status = 'pending'" );
		$total_banned  = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$restrictions_t}
			 WHERE type IN ('global_ban','space_ban','silence')
			 AND (expires_at IS NULL OR expires_at > '" . now() . "')"
		);

		$pending_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, s.title as space_title
				 FROM {$posts_t} p
				 LEFT JOIN " . table( 'spaces' ) . " s ON s.id = p.space_id
				 WHERE p.status = 'pending'
				 ORDER BY p.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				( $paged_posts - 1 ) * $per_page
			)
		) ?: [];

		$pending_replies = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, p.title as post_title
				 FROM {$replies_t} r
				 LEFT JOIN {$posts_t} p ON p.id = r.post_id
				 WHERE r.status = 'pending'
				 ORDER BY r.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				( $paged_replies - 1 ) * $per_page
			)
		) ?: [];

		$pending_flags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$flags_t}
				 WHERE status = 'pending'
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				( $paged_flags - 1 ) * $per_page
			)
		) ?: [];

		$banned_users = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, u.display_name, u.user_login
				 FROM {$restrictions_t} r
				 LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
				 WHERE r.type IN ('global_ban','space_ban','silence')
				 AND (r.expires_at IS NULL OR r.expires_at > '" . now() . "')
				 ORDER BY r.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				( $paged_banned - 1 ) * $per_page
			)
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
	//  AJAX: Spaces
	// ═══════════════════════════════════════════════════════════════



	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Space Members (moved to Spaces_Handler)
	// ═══════════════════════════════════════════════════════════════



	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Access Rules (moved to Spaces_Handler)
	// ═══════════════════════════════════════════════════════════════


	// ═══════════════════════════════════════════════════════════════
	//  AJAX: Users
	// ═══════════════════════════════════════════════════════════════





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
		// Branch: if a post_id is given, show that post's replies page.
		$post_id = absint( $_GET['post_id'] ?? 0 );
		if ( $post_id ) {
			$this->render_post_replies( $post_id );
			return;
		}

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

		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		// Total count with same filters (no LIMIT).
		$count_sql = "SELECT COUNT(*) FROM {$posts_t} p WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) );
		$total_pages = (int) ceil( $total / $per_page );

		$sql = "SELECT p.*, s.title AS space_title, s.slug AS space_slug
		        FROM {$posts_t} p
		        LEFT JOIN {$spaces_t} s ON s.id = p.space_id
		        WHERE {$where}
		        ORDER BY p.created_at DESC
		        LIMIT %d OFFSET %d";

		$full_args = array_merge( $args, [ $per_page, $offset ] );
		$posts = $wpdb->get_results( $wpdb->prepare( $sql, ...$full_args ) ) ?: [];

		include JETONOMY_DIR . 'includes/admin/views/content.php';
	}

	/**
	 * Renders the replies page for a specific post.
	 * Handles pagination for posts with hundreds/thousands of replies.
	 */
	private function render_post_replies( int $post_id ): void {
		global $wpdb;

		$post = Post::find( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Post not found.', 'jetonomy' ) );
		}

		$replies_t = table( 'replies' );

		// Status filter.
		$current_status = sanitize_text_field( $_GET['status'] ?? 'all' );
		$valid_statuses = [ 'all', 'publish', 'pending', 'spam', 'trash' ];
		if ( ! in_array( $current_status, $valid_statuses, true ) ) {
			$current_status = 'all';
		}

		// Search.
		$search_query = sanitize_text_field( $_GET['s'] ?? '' );

		// Build WHERE clause.
		$where = 'r.post_id = %d';
		$args  = [ $post_id ];
		if ( 'all' !== $current_status ) {
			$where .= ' AND r.status = %s';
			$args[] = $current_status;
		}
		if ( $search_query ) {
			$where .= ' AND r.content_plain LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search_query ) . '%';
		}

		// Pagination — 50 per page for large reply sets.
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 50;
		$offset   = ( $paged - 1 ) * $per_page;

		$count_sql   = "SELECT COUNT(*) FROM {$replies_t} r WHERE {$where}";
		$total       = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );
		$total_pages = (int) ceil( $total / $per_page );

		$sql     = "SELECT r.* FROM {$replies_t} r WHERE {$where} ORDER BY r.created_at ASC LIMIT %d OFFSET %d";
		$replies = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $args, [ $per_page, $offset ] ) ) ) ?: [];

		$nonce_value = wp_create_nonce( 'jetonomy_admin' );

		include JETONOMY_DIR . 'includes/admin/views/replies.php';
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
