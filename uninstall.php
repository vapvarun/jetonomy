<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$clean = get_option( 'jetonomy_clean_uninstall', false );

if ( $clean ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/db/class-schema.php';
    Jetonomy\DB\Schema::drop_tables();

    require_once plugin_dir_path( __FILE__ ) . 'includes/permissions/class-capabilities.php';
    Jetonomy\Permissions\Capabilities::unregister();

    delete_option( 'jetonomy_db_version' );
    delete_option( 'jetonomy_settings' );
    delete_option( 'jetonomy_clean_uninstall' );
}
