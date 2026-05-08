<?php
/**
 * Multisite-aware activation helpers.
 *
 * Two functions: one that WordPress calls when the plugin is activated
 * (replaces the old single-blog activation hook callback), and one that
 * creates tables on any blog provisioned after plugin activation. Together
 * they ensure every sub-site gets Jetonomy's database tables, whether the
 * site existed at activation time or was created later.
 *
 * Intentionally minimal. The full schema-uniform refactor is planned for a
 * future release and will revisit this layer. No new abstractions added here.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Run install on every blog when network-activated, otherwise just the current blog.
 *
 * On a single-site install -- or on network activation once table creation
 * is done for each sub-blog -- we delegate to Jetonomy::activate() so all
 * one-time setup (capabilities, cron, rewrites, options) runs on the
 * current/main blog as normal. The per-blog loop only calls
 * Schema::create_tables() because those other setup steps are
 * network-level concerns, not per-blog ones.
 *
 * @param bool $network_wide True when the plugin is network-activated on Multisite.
 */
function activate_plugin( bool $network_wide = false ): void {
	if ( is_multisite() && $network_wide ) {
		require_once JETONOMY_DIR . 'includes/db/class-schema.php';
		$blog_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			DB\Schema::create_tables();
			restore_current_blog();
		}
	}

	// Always run the full single-site activation on the main (current) blog:
	// capabilities, cron schedule, rewrite flush, and option defaults.
	Jetonomy::instance()->activate();
}

/**
 * Create tables on a newly created sub-site so Jetonomy works there immediately.
 *
 * Fires on wp_initialize_site (WP 5.1+) which runs whenever a new blog is
 * provisioned, regardless of how the plugin was activated. This closes the
 * gap where network-activating before a sub-site exists would otherwise
 * leave that sub-site without tables.
 *
 * @param \WP_Site $new_site The newly created site object.
 * @param array    $args     Initialization arguments passed by WordPress core.
 */
function install_on_new_site( $new_site, array $args ): void {
	if ( ! is_a( $new_site, 'WP_Site' ) ) {
		return;
	}
	require_once JETONOMY_DIR . 'includes/db/class-schema.php';
	switch_to_blog( (int) $new_site->blog_id );
	DB\Schema::create_tables();
	restore_current_blog();
}
