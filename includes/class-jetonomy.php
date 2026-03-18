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

        update_option( 'jetonomy_db_version', JETONOMY_DB_VERSION );
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }

    public function init(): void {
        $this->check_db_version();
        $this->load_dependencies();
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

        new API\Api();

        // Adapters — autoloader resolves all classes
        Adapters\Adapter_Registry::init_defaults();
        Adapters\Adapter_Registry::register_email( 'wp-mail', new Adapters\WP_Mail_Adapter() );

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

        Import\Import_Manager::init();

        if ( is_admin() ) {
            new Admin\Admin();
        }
    }
}
