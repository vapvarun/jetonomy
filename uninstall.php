<?php
/**
 * Jetonomy Uninstall
 *
 * Runs when a site administrator deletes the Jetonomy plugin from wp-admin.
 * Removes all database tables, options, capabilities, cron jobs, and transients
 * created by the plugin.
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

global $wpdb;

// -------------------------------------------------------------------------
// 1. Drop custom database tables.
//
// All Jetonomy tables use the site's table prefix followed by "jt_".
// Tables are dropped in reverse dependency order to avoid foreign-key issues
// on environments that have FOREIGN_KEY_CHECKS enabled.
// -------------------------------------------------------------------------

$tables = array(
	'invite_links',
	'join_requests',
	'revisions',
	'flags',
	'access_rules',
	'restrictions',
	'activity_log',
	'user_interests',
	'space_tag_map',
	'space_tags',
	'post_tags',
	'tags',
	'space_members',
	'read_status',
	'subscriptions',
	'notifications',
	'user_profiles',
	'votes',
	'replies',
	'posts',
	'spaces',
	'categories',
);

foreach ( $tables as $table ) {
	$wpdb->query(
		'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . 'jt_' . $table )
	);
}

// -------------------------------------------------------------------------
// 2. Delete plugin options.
// -------------------------------------------------------------------------

delete_option( 'jetonomy_settings' );
delete_option( 'jetonomy_db_version' );
delete_option( 'jetonomy_setup_complete' );
delete_option( 'jetonomy_demo_data' );
delete_option( 'jetonomy_clean_uninstall' );

// Delete any option whose name begins with "jetonomy_permalinks_flushed"
// (the plugin stores per-blog variants of this key on multisite).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'jetonomy_permalinks_flushed' ) . '%'
	)
);

// -------------------------------------------------------------------------
// 3. Remove Jetonomy capabilities from all WordPress roles.
//
// get_editable_roles() covers built-in and any custom roles added by other
// plugins, ensuring nothing is left behind regardless of site configuration.
// -------------------------------------------------------------------------

$jetonomy_caps = array(
	'jetonomy_read',
	'jetonomy_create_posts',
	'jetonomy_create_replies',
	'jetonomy_vote',
	'jetonomy_upload',
	'jetonomy_edit_own_posts',
	'jetonomy_edit_others_posts',
	'jetonomy_delete_others_posts',
	'jetonomy_close_posts',
	'jetonomy_pin_posts',
	'jetonomy_move_posts',
	'jetonomy_moderate',
	'jetonomy_manage_spaces',
	'jetonomy_manage_settings',
	'jetonomy_manage_users',
	'jetonomy_view_reports',
	'jetonomy_manage_tags',
	'jetonomy_manage_categories',
	'jetonomy_ban_users',
	'jetonomy_manage_restrictions',
);

foreach ( array_keys( get_editable_roles() ) as $role_name ) {
	$role = get_role( $role_name );

	if ( ! $role ) {
		continue;
	}

	foreach ( $jetonomy_caps as $cap ) {
		$role->remove_cap( $cap );
	}
}

// -------------------------------------------------------------------------
// 4. Clear scheduled cron jobs.
// -------------------------------------------------------------------------

$cron_hooks = array(
	'jetonomy_evaluate_trust_levels',
	'jetonomy_cleanup_restrictions',
	'jetonomy_prune_activity_log',
	'jetonomy_cleanup_notifications',
);

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
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
