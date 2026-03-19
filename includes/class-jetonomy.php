<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

final class Jetonomy {
    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_hooks();
    }

    private function register_hooks(): void {
        register_activation_hook( JETONOMY_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( JETONOMY_FILE, [ $this, 'deactivate' ] );
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    public function activate(): void {
        require_once JETONOMY_DIR . 'includes/db/class-schema.php';
        DB\Schema::create_tables();

        require_once JETONOMY_DIR . 'includes/permissions/class-capabilities.php';
        Permissions\Capabilities::register();

        Cron::schedule();

        update_option( 'jetonomy_db_version', JETONOMY_DB_VERSION );
        flush_rewrite_rules();
        set_transient( 'jetonomy_activation_redirect', true, 30 );
    }

    public function deactivate(): void {
        delete_option( 'jetonomy_permalinks_flushed' );
        Cron::unschedule();
        flush_rewrite_rules();
    }

    public function init(): void {
        load_plugin_textdomain( 'jetonomy', false, dirname( plugin_basename( JETONOMY_FILE ) ) . '/languages' );
        $this->maybe_redirect_to_setup();
        $this->check_db_version();
        $this->load_dependencies();
    }

    private function maybe_redirect_to_setup(): void {
        if ( ! get_transient( 'jetonomy_activation_redirect' ) ) return;
        delete_transient( 'jetonomy_activation_redirect' );
        if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() ) return;
        wp_safe_redirect( admin_url( 'admin.php?page=jetonomy-setup' ) );
        exit;
    }

    private function check_db_version(): void {
        $current = get_option( 'jetonomy_db_version', '0.0.0' );
        if ( version_compare( $current, JETONOMY_DB_VERSION, '<' ) ) {
            require_once JETONOMY_DIR . 'includes/db/class-migrator.php';
            DB\Migrator::run( $current );
        }
    }

    private function load_dependencies(): void {
        require_once JETONOMY_DIR . 'includes/functions.php';

        // Non-namespaced files still need explicit require
        require_once JETONOMY_DIR . 'includes/class-router.php';
        require_once JETONOMY_DIR . 'includes/class-template-loader.php';
        new Router();

        // Ensure rewrite rules are flushed at least once after activation.
        // Version-keyed so a sitemap or URL change triggers a re-flush.
        $flush_key = 'jetonomy_permalinks_flushed_' . JETONOMY_VERSION;
        if ( ! get_option( $flush_key ) ) {
            flush_rewrite_rules();
            update_option( $flush_key, true );
        }

        new API\Api();

        // Adapters — autoloader resolves all classes
        Adapters\Adapter_Registry::init_defaults();
        Adapters\Adapter_Registry::register_email( 'wp-mail', new Adapters\WP_Mail_Adapter() );
        Adapters\Adapter_Registry::register_search( 'fulltext', new Search\Fulltext_Search() );

        // MemberPress adapter (conditional)
        if ( defined( 'MEPR_VERSION' ) ) {
            $mepr = new Adapters\MemberPress_Adapter();
            Adapters\Adapter_Registry::register_membership( 'memberpress', $mepr );
            $mepr->register_hooks();
        }

        // PMPro adapter (conditional)
        if ( defined( 'PMPRO_VERSION' ) ) {
            $pmpro = new Adapters\PMPro_Adapter();
            Adapters\Adapter_Registry::register_membership( 'pmpro', $pmpro );
            $pmpro->register_hooks();
        }

        new Notifications\Notifier();
        new Cron();
        new Privacy();

        new SEO\Sitemap();
        new SEO\Schema_Markup();
        new Nav_Menus();
        new Media();
        new Activity_Tracker();
        new Abilities();

        Import\Import_Manager::init();

        if ( is_admin() ) {
            new Admin\Admin();
        }
    }
}
