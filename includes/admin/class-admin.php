<?php
namespace Jetonomy\Admin;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Flag;
use Jetonomy\Import\Import_Manager;
use function Jetonomy\table;

class Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX handlers for import
        add_action( 'wp_ajax_jetonomy_run_import', [ $this, 'ajax_run_import' ] );
    }

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

    public function register_settings(): void {
        register_setting( 'jetonomy_settings', 'jetonomy_settings', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( $input ): array {
        $clean = [];
        $clean['base_slug']        = sanitize_title( $input['base_slug'] ?? 'community' );
        $clean['posts_per_page']   = absint( $input['posts_per_page'] ?? 20 );
        $clean['replies_per_page'] = absint( $input['replies_per_page'] ?? 30 );
        $clean['guest_read']       = ! empty( $input['guest_read'] );
        $clean['require_login']    = ! empty( $input['require_login'] );
        $clean['email_from_name']  = sanitize_text_field( $input['email_from_name'] ?? '' );
        $clean['email_from_email'] = sanitize_email( $input['email_from_email'] ?? '' );
        return $clean;
    }

    public function enqueue_assets( string $hook ): void {
        if ( false === strpos( $hook, 'jetonomy' ) ) {
            return;
        }

        wp_enqueue_style( 'jetonomy-admin', JETONOMY_URL . 'assets/css/admin.css', [], JETONOMY_VERSION );
    }

    // ── Dashboard ──
    public function render_dashboard(): void {
        global $wpdb;
        $posts_t  = table( 'posts' );
        $replies_t = table( 'replies' );
        $spaces_t = table( 'spaces' );
        $users_t  = table( 'user_profiles' );
        $flags_t  = table( 'flags' );

        $stats = [
            'posts'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$posts_t} WHERE status = 'publish'" ),
            'replies'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$replies_t} WHERE status = 'publish'" ),
            'spaces'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$spaces_t} WHERE status = 'active'" ),
            'users'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$users_t}" ),
            'pending_flags' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flags_t} WHERE status = 'pending'" ),
        ];

        include JETONOMY_DIR . 'includes/admin/views/dashboard.php';
    }

    // ── Spaces ──
    public function render_spaces(): void {
        global $wpdb;
        $spaces = $wpdb->get_results( 'SELECT * FROM ' . table( 'spaces' ) . ' ORDER BY title ASC' );
        include JETONOMY_DIR . 'includes/admin/views/spaces.php';
    }

    // ── Moderation ──
    public function render_moderation(): void {
        global $wpdb;
        $pending_posts = $wpdb->get_results( "SELECT * FROM " . table( 'posts' ) . " WHERE status = 'pending' ORDER BY created_at DESC LIMIT 50" );
        $pending_flags = $wpdb->get_results( "SELECT * FROM " . table( 'flags' ) . " WHERE status = 'pending' ORDER BY created_at DESC LIMIT 50" );
        include JETONOMY_DIR . 'includes/admin/views/moderation.php';
    }

    // ── Import ──
    public function render_import(): void {
        Import_Manager::init();
        $available = Import_Manager::get_available();
        include JETONOMY_DIR . 'includes/admin/views/import.php';
    }

    // ── Settings ──
    public function render_settings(): void {
        $settings = get_option( 'jetonomy_settings', [] );
        include JETONOMY_DIR . 'includes/admin/views/settings.php';
    }

    // ── Import AJAX ──
    public function ajax_run_import(): void {
        check_ajax_referer( 'jetonomy_import', 'nonce' );

        if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
        }

        $source = sanitize_text_field( $_POST['source'] ?? '' );
        Import_Manager::init();
        $result = Import_Manager::run( $source );

        if ( null === $result ) {
            wp_send_json_error( __( 'Unknown import source.', 'jetonomy' ) );
        }

        // Flush rewrite rules after import
        flush_rewrite_rules();

        wp_send_json_success( $result );
    }
}
