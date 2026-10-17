<?php
/**
 * Jetonomy Uninstall
 *
 * Runs when a site administrator deletes the Jetonomy plugin from wp-admin.
 * Only when Settings > General > Data > "Remove all community data" is on, it
 * removes all database tables, options, capabilities, cron jobs, and transients
 * created by the plugin. Otherwise it leaves everything in place.
 *
 * This file is intentionally standalone — it does not require or include any
 * other plugin files so it remains safe to execute after the plugin files have
 * been removed by WordPress.
 *
 * @package Jetonomy
 * @since   1.0.0
 */

// WordPress sets this constant before calling uninstall.php.
// Bail immediately if this file is accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
defined( 'ABSPATH' ) || exit;

// Keep everything unless the owner ticked Settings > General > Data. A
// community is years of other people's writing; deleting the plugin to
// reinstall it must not erase it.
if ( empty( get_option( 'jetonomy_settings', array() )['remove_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// -------------------------------------------------------------------------
// 1. Drop custom database tables.
//
// Pulls the canonical table list from Schema::get_table_names() so the drop
// list never goes stale when a new table is added to Schema. Schema is a
// pure namespaced helper with no load-time side effects (no hooks, no DB
// calls in the class body), so requiring it here is safe even after
// plugin files are mostly removed by WordPress's uninstall flow. The
// Composer autoloader is intentionally NOT relied on — vendor/ may already
// be deleted by the time this file runs.
// -------------------------------------------------------------------------

require_once __DIR__ . '/includes/db/class-schema.php';
\Jetonomy\DB\Schema::drop_tables();

// -------------------------------------------------------------------------
// 2. Delete plugin options.
// -------------------------------------------------------------------------

delete_option( 'jetonomy_settings' );
delete_option( 'jetonomy_db_version' );
delete_option( 'jetonomy_setup_complete' );
delete_option( 'jetonomy_demo_data' );
delete_option( 'jetonomy_clean_uninstall' );

// Then every other jetonomy_ option (role map, email templates, migration and
// seeding flags...) - a fixed list kept missing new ones. jetonomy_pro_ options
// are left to Pro's own uninstall: Pro and its licence may still be installed.
// Also the saved settings of the plugin's classic widgets (widget_jetonomy_*).
// delete_option() rather than a bulk DELETE so a persistent object cache
// does not keep serving the removed values.
$jetonomy_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE ( option_name LIKE %s AND option_name NOT LIKE %s ) OR option_name LIKE %s",
		$wpdb->esc_like( 'jetonomy_' ) . '%',
		$wpdb->esc_like( 'jetonomy_pro_' ) . '%',
		$wpdb->esc_like( 'widget_jetonomy_' ) . '%'
	)
);
foreach ( $jetonomy_options as $jetonomy_option ) {
	delete_option( $jetonomy_option );
}

// -------------------------------------------------------------------------
// 3. Remove Jetonomy capabilities from all WordPress roles.
//
// wp_roles() covers built-in roles and custom roles added by other plugins,
// so nothing is left behind regardless of site configuration.
// -------------------------------------------------------------------------

// Every capability whose name starts with "jetonomy_", read from the roles
// themselves. A hand-kept list went stale (it still named "jetonomy_upload"
// and missed delete_own_posts, manage_badges, manage_extensions and others),
// leaving capabilities on roles after the plugin was deleted.
foreach ( wp_roles()->roles as $jetonomy_role_name => $jetonomy_role_data ) {
	$jetonomy_role = get_role( $jetonomy_role_name );
	if ( ! $jetonomy_role ) {
		continue;
	}
	foreach ( array_keys( (array) ( $jetonomy_role_data['capabilities'] ?? array() ) ) as $jetonomy_cap ) {
		if ( str_starts_with( (string) $jetonomy_cap, 'jetonomy_' ) ) {
			$jetonomy_role->remove_cap( $jetonomy_cap );
		}
	}
}

// -------------------------------------------------------------------------
// 4. Clear scheduled cron jobs.
// -------------------------------------------------------------------------

// Every WP-Cron hook named jetonomy_*, plus the licence SDK's weekly check, read
// from the cron array itself: a hand-kept list had drifted to hook names the
// plugin no longer uses. Action Scheduler jobs are already gone: deactivation
// unschedules them, and WordPress deactivates before it uninstalls.
foreach ( (array) _get_cron_array() as $jetonomy_events ) {
	foreach ( array_keys( (array) $jetonomy_events ) as $jetonomy_hook ) {
		if ( str_starts_with( (string) $jetonomy_hook, 'jetonomy_' ) || 'edd_sl_sdk_weekly_license_check_jetonomy' === $jetonomy_hook ) {
			wp_clear_scheduled_hook( $jetonomy_hook );
		}
	}
}

// -------------------------------------------------------------------------
// 5. Delete transients created by the plugin.
//
// WordPress stores transients as options with the key "_transient_{name}"
// (and "_transient_timeout_{name}" for the expiry). A LIKE query is the
// only reliable way to bulk-delete transients by prefix.
// -------------------------------------------------------------------------

$transient_prefix = $wpdb->esc_like( '_transient_jetonomy_' );
$timeout_prefix   = $wpdb->esc_like( '_transient_timeout_jetonomy_' );

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$transient_prefix . '%',
		$timeout_prefix . '%'
	)
);

// -------------------------------------------------------------------------
// 6. Flush rewrite rules.
//
// Remove the plugin's custom rewrite rules from the database so WordPress
// does not attempt to resolve /community/* URLs after the plugin is gone.
// -------------------------------------------------------------------------

delete_option( 'rewrite_rules' );
