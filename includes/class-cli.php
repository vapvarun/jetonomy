<?php
/**
 * WP-CLI commands.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Trust\Trust_Evaluator;
use Jetonomy\Import\Import_Manager;
use Jetonomy\Admin\Ajax\Demo_Seeder;
use Jetonomy\Recount;
use function Jetonomy\table;

class CLI {

	/**
	 * Recount all denormalized fields (reply counts, post counts, vote scores).
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : What to recount. Options: all, posts, spaces, votes. Default: all.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy recount
	 *     wp jetonomy recount --type=posts
	 *
	 * @subcommand recount
	 */
	public function recount( $args, $assoc_args ): void {
		$type  = isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : 'all';
		$stats = Recount::run( $type );

		foreach ( $stats as $step => $rows ) {
			\WP_CLI::log( sprintf( '%s: %d rows', $step, $rows ) );
		}

		\WP_CLI::success( 'Recount complete.' );
	}

	/**
	 * Evaluate trust levels for all users.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy trust-evaluate
	 *
	 * @subcommand trust-evaluate
	 */
	public function trust_evaluate( $args, $assoc_args ): void {
		global $wpdb;
		$profiles_t = table( 'user_profiles' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$profiles = $wpdb->get_results( "SELECT * FROM {$profiles_t} WHERE trust_level < 4" );
		$promoted = 0;

		foreach ( $profiles as $profile ) {
			$days_active = 0;
			if ( $profile->created_at ) {
				$days_active = (int) ( ( time() - strtotime( $profile->created_at ) ) / DAY_IN_SECONDS );
			}

			$stats = [
				'post_count'       => (int) $profile->post_count,
				'days_active'      => $days_active,
				'reputation'       => (int) $profile->reputation,
				'replies_received' => 0, // Would need a join to count -- simplified
			];

			$new_level = Trust_Evaluator::evaluate_level( $stats );

			if ( $new_level > (int) $profile->trust_level ) {
				$wpdb->update( $profiles_t, [ 'trust_level' => $new_level ], [ 'user_id' => $profile->user_id ] );
				\WP_CLI::log( sprintf( 'User %d: Level %d -> %d', $profile->user_id, $profile->trust_level, $new_level ) );
				do_action( 'jetonomy_trust_level_changed', (int) $profile->user_id, (int) $profile->trust_level, $new_level );
				++$promoted;
			}
		}

		\WP_CLI::success( sprintf( '%d users promoted.', $promoted ) );
	}

	/**
	 * Run a forum import.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Import source: bbpress, wpforo, or asgaros
	 *
	 * [--dry-run]
	 * : Validate and count without importing any data.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy import bbpress
	 *     wp jetonomy import bbpress --dry-run
	 *     wp jetonomy import wpforo
	 */
	public function import( $args, $assoc_args ): void {
		$source  = $args[0] ?? '';
		$dry_run = ! empty( $assoc_args['dry-run'] );

		Import_Manager::init();

		if ( ! Import_Manager::get_available() ) {
			\WP_CLI::error( 'No import sources available.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log( 'DRY RUN -- no data will be written.' );
		}

		$result = Import_Manager::run( $source, [ 'dry_run' => $dry_run ] );
		if ( null === $result ) {
			\WP_CLI::error( "Unknown source: {$source}. Available: " . implode( ', ', array_keys( Import_Manager::get_available() ) ) );
			return;
		}

		$prefix = $dry_run ? '[DRY RUN] ' : '';

		\WP_CLI::success(
			sprintf(
				'%sImport complete. Imported: %d, Skipped: %d, Errors: %d',
				$prefix,
				$result['imported'],
				$result['skipped'],
				count( $result['errors'] )
			)
		);

		if ( ! empty( $result['errors'] ) ) {
			\WP_CLI::warning( 'Errors:' );
			foreach ( $result['errors'] as $err ) {
				\WP_CLI::log( sprintf( '  [%s #%s] %s', $err['type'], $err['id'], $err['message'] ) );
			}
		}

		if ( ! $dry_run ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Flush and regenerate rewrite rules.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy flush-rules
	 *
	 * @subcommand flush-rules
	 */
	public function flush_rules( $args, $assoc_args ): void {
		flush_rewrite_rules();
		\WP_CLI::success( 'Rewrite rules flushed.' );
	}

	/**
	 * Backfill the activity_log table from existing posts, replies, and space memberships.
	 *
	 * Useful after first installing the Activity Tracker, or after a migration.
	 * Skips items that already have matching activity entries.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy backfill-activity
	 *
	 * @subcommand backfill-activity
	 */
	public function backfill_activity( $args, $assoc_args ): void {
		global $wpdb;
		$posts_t    = table( 'posts' );
		$replies_t  = table( 'replies' );
		$members_t  = table( 'space_members' );
		$activity_t = table( 'activity_log' );
		$inserted   = 0;

		// Posts.
		\WP_CLI::log( 'Backfilling posts...' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = $wpdb->get_results(
			"SELECT p.id, p.author_id, p.space_id, p.created_at
			 FROM {$posts_t} p
			 LEFT JOIN {$activity_t} a ON a.action = 'created_post' AND a.object_type = 'post' AND a.object_id = p.id
			 WHERE a.id IS NULL AND p.status = 'publish'
			 ORDER BY p.id ASC"
		);
		foreach ( $posts as $p ) {
			$wpdb->insert(
				$activity_t,
				[
					'user_id'     => (int) $p->author_id,
					'action'      => 'created_post',
					'object_type' => 'post',
					'object_id'   => (int) $p->id,
					'metadata'    => wp_json_encode( [ 'space_id' => (int) $p->space_id ] ),
					'created_at'  => $p->created_at,
				]
			);
			++$inserted;
		}

		// Replies.
		\WP_CLI::log( 'Backfilling replies...' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$replies = $wpdb->get_results(
			"SELECT r.id, r.author_id, r.post_id, r.created_at
			 FROM {$replies_t} r
			 LEFT JOIN {$activity_t} a ON a.action = 'created_reply' AND a.object_type = 'reply' AND a.object_id = r.id
			 WHERE a.id IS NULL AND r.status = 'publish'
			 ORDER BY r.id ASC"
		);
		foreach ( $replies as $r ) {
			$wpdb->insert(
				$activity_t,
				[
					'user_id'     => (int) $r->author_id,
					'action'      => 'created_reply',
					'object_type' => 'reply',
					'object_id'   => (int) $r->id,
					'metadata'    => wp_json_encode( [ 'post_id' => (int) $r->post_id ] ),
					'created_at'  => $r->created_at,
				]
			);
			++$inserted;
		}

		// Space memberships.
		\WP_CLI::log( 'Backfilling space joins...' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$members = $wpdb->get_results(
			"SELECT m.space_id, m.user_id, m.role, m.joined_at
			 FROM {$members_t} m
			 LEFT JOIN {$activity_t} a ON a.action = 'joined_space' AND a.object_type = 'space' AND a.object_id = m.space_id AND a.user_id = m.user_id
			 WHERE a.id IS NULL
			 ORDER BY m.joined_at ASC"
		);
		foreach ( $members as $m ) {
			$wpdb->insert(
				$activity_t,
				[
					'user_id'     => (int) $m->user_id,
					'action'      => 'joined_space',
					'object_type' => 'space',
					'object_id'   => (int) $m->space_id,
					'metadata'    => wp_json_encode( [ 'role' => $m->role ] ),
					'created_at'  => $m->joined_at,
				]
			);
			++$inserted;
		}

		\WP_CLI::success( sprintf( 'Backfilled %d activity entries.', $inserted ) );
	}

	/**
	 * Seed a realistic multi-user demo community.
	 *
	 * Creates 5 demo users, 2 categories, 5 spaces, 11 posts, 18+ replies,
	 * votes, flags, badges, and Pro data (reactions, poll) if Jetonomy Pro is active.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Auto-cleanup existing demo data before seeding. Without this flag the
	 *   command aborts if demo data already exists.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy demo-seed
	 *     wp jetonomy demo-seed --force
	 *
	 * @subcommand demo-seed
	 */
	public function demo_seed( $args, $assoc_args ): void {
		$existing = get_option( 'jetonomy_demo_data', [] );
		if ( ! empty( $existing ) ) {
			if ( empty( $assoc_args['force'] ) ) {
				\WP_CLI::error( 'Demo data already exists. Run with --force to replace it, or run `wp jetonomy demo-cleanup` first.' );
				return;
			}
			\WP_CLI::log( 'Cleaning up existing demo data...' );
			Demo_Seeder::cleanup( $existing );
			delete_option( 'jetonomy_demo_data' );
			\WP_CLI::log( 'Done.' );
		}

		$admin_id = (int) get_option(
			'jetonomy_setup_admin_id',
			get_users(
				[
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				]
			)[0] ?? 1
		);

		\WP_CLI::log( 'Seeding demo users...' );
		$demo = Demo_Seeder::seed( $admin_id );
		update_option( 'jetonomy_demo_data', $demo, false );
		flush_rewrite_rules();

		\WP_CLI::log( sprintf( '  Users created:     %d', count( $demo['users'] ) ) );
		\WP_CLI::log( sprintf( '  Categories:        %d', count( $demo['categories'] ) ) );
		\WP_CLI::log( sprintf( '  Spaces:            %d', count( $demo['spaces'] ) ) );
		\WP_CLI::log( sprintf( '  Posts:             %d', count( $demo['posts'] ) ) );
		\WP_CLI::log( sprintf( '  Replies:           %d', count( $demo['replies'] ) ) );
		\WP_CLI::log( sprintf( '  Flags (pending):   %d', count( $demo['flags'] ) ) );
		\WP_CLI::log( sprintf( '  Badges defined:    %d', count( $demo['badges'] ) ) );

		if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
			\WP_CLI::log( sprintf( '  Polls (Pro):       %d', count( $demo['polls'] ) ) );
			\WP_CLI::log( '  Reactions seeded   (see wp_jt_pro_reactions)' );
		}

		\WP_CLI::success( 'Demo community seeded. Visit /community/ to see it.' );
	}

	/**
	 * Remove all demo data created by demo-seed or the setup wizard.
	 *
	 * Deletes demo users, their content, votes, reactions, polls, badges, flags,
	 * spaces, and categories -- everything tracked in the jetonomy_demo_data option.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy demo-cleanup
	 *
	 * @subcommand demo-cleanup
	 */
	public function demo_cleanup( $args, $assoc_args ): void {
		$demo = get_option( 'jetonomy_demo_data', [] );
		if ( empty( $demo ) ) {
			\WP_CLI::error( 'No demo data found. Nothing to clean up.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Removing %d posts, %d replies, %d users, %d spaces...', count( $demo['posts'] ?? [] ), count( $demo['replies'] ?? [] ), count( $demo['users'] ?? [] ), count( $demo['spaces'] ?? [] ) ) );

		Demo_Seeder::cleanup( $demo );
		delete_option( 'jetonomy_demo_data' );

		\WP_CLI::success( 'All demo data removed.' );
	}

	/**
	 * Run discovery-based pre-release QA checks.
	 *
	 * Scans the actual codebase to discover what to test -- DB tables, REST routes,
	 * notification types, permissions, templates, rewrite rules, settings, Pro extensions,
	 * and JS-to-REST alignment. New code is picked up automatically on the next run.
	 *
	 * ## OPTIONS
	 *
	 * [--report]
	 * : Write a JSON report to plans/qa-report-{date}.json.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy qa
	 *     wp jetonomy qa --report
	 *
	 * @subcommand qa
	 */
	public function qa( $args, $assoc_args ): void {
		global $wpdb;

		$write_report = isset( $assoc_args['report'] );
		$version      = defined( 'JETONOMY_VERSION' ) ? JETONOMY_VERSION : '?';
		$pro_version  = defined( 'JETONOMY_PRO_VERSION' ) ? JETONOMY_PRO_VERSION : null;

		$sections = [];

		$record = function (
			string $section,
			string $label,
			bool $ok,
			string $detail = '',
			bool $warning = false
		) use ( &$sections ): void {
			if ( ! isset( $sections[ $section ] ) ) {
				$sections[ $section ] = [ 'checks' => [] ];
			}
			$status                           = $ok ? 'pass' : ( $warning ? 'warn' : 'fail' );
			$sections[ $section ]['checks'][] = [
				'label'  => $label,
				'status' => $status,
				'detail' => $detail,
			];
		};

		// ── Section 1: Database Tables ────────────────────────────────────────
		$schema_tables = \Jetonomy\DB\Schema::get_table_names();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_tables_raw = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}jt_%'" );
		$existing_tables_set = array_flip( $existing_tables_raw );

		$empty_warn_tables = [ 'jt_categories', 'jt_spaces', 'jt_posts', 'jt_replies', 'jt_user_profiles' ];
		$tables_to_count   = [];

		foreach ( $schema_tables as $table_suffix ) {
			$full   = $wpdb->prefix . $table_suffix;
			$exists = isset( $existing_tables_set[ $full ] );

			if ( $exists && in_array( $table_suffix, $empty_warn_tables, true ) ) {
				$tables_to_count[ $table_suffix ] = $full;
			} else {
				$record( 'database', "Table {$table_suffix}", $exists, $exists ? '' : 'table missing' );
			}
		}

		if ( ! empty( $tables_to_count ) ) {
			$union_parts = [];
			foreach ( $tables_to_count as $suffix => $full ) {
				$union_parts[] = "SELECT '{$suffix}' AS tbl, COUNT(*) AS cnt FROM `{$full}`";
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_rows = $wpdb->get_results( implode( ' UNION ALL ', $union_parts ) );
			$count_map  = [];
			foreach ( $count_rows as $row ) {
				$count_map[ $row->tbl ] = (int) $row->cnt;
			}
			foreach ( $tables_to_count as $suffix => $full ) {
				$count  = $count_map[ $suffix ] ?? 0;
				$detail = $count > 0 ? "{$count} rows" : 'table exists but is empty';
				$record( 'database', "Table {$suffix}", true, $detail, 0 === $count );
			}
		}

		// ── Section 2: REST Routes (Discovery) ───────────────────────────────
		$server       = rest_get_server();
		$all_routes   = $server->get_routes( 'jetonomy/v1' );
		$namespace_ok = count( $all_routes ) > 0;

		$record( 'rest_routes', 'Namespace jetonomy/v1 registered', $namespace_ok, count( $all_routes ) . ' routes found' );

		$mutating_methods = [ 'POST', 'PATCH', 'DELETE', 'PUT' ];

		foreach ( $all_routes as $route_path => $route_handlers ) {
			foreach ( $route_handlers as $handler ) {
				$methods      = array_keys( $handler['methods'] ?? [] );
				$has_mutating = ! empty( array_intersect( $methods, $mutating_methods ) );

				if ( ! $has_mutating ) {
					continue;
				}

				$perm_cb     = $handler['permission_callback'] ?? null;
				$is_open     = '__return_true' === $perm_cb
					|| ( is_array( $perm_cb ) && in_array( '__return_true', $perm_cb, true ) );
				$methods_str = implode( '|', array_intersect( $methods, $mutating_methods ) );
				$route_label = "{$route_path} [{$methods_str}]";

				$is_webhook = str_contains( $route_path, 'inbound' ) || str_contains( $route_path, 'webhook' );

				$record( 'rest_routes', "Permission gate: {$route_label}", ! $is_open || $is_webhook, $is_open && ! $is_webhook ? 'permission_callback is __return_true -- OPEN WRITE' : '' );
			}
		}

		// ── Section 3: Notification Type Keys (Discovery) ─────────────────────
		$notif_template = JETONOMY_DIR . 'templates/views/notifications.php';
		$template_keys  = [];

		if ( file_exists( $notif_template ) ) {
			$src = file_get_contents( $notif_template ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			preg_match_all( "/['\"]([a-z_]+)['\"]\s*=>/", $src, $matches );
			$template_keys = array_filter( $matches[1], fn( $k ) => str_contains( $k, '_' ) );
			$template_keys = array_values( array_unique( $template_keys ) );
		}

		$settings   = get_option( 'jetonomy_settings', [] );
		$notif_defs = $settings['notification_defaults'] ?? [];

		if ( empty( $template_keys ) ) {
			$record( 'notification_keys', 'notifications.php $type_labels parseable', false, 'Could not parse template or file missing' );
		} else {
			foreach ( $template_keys as $key ) {
				$in_settings = array_key_exists( $key, $notif_defs );
				$record( 'notification_keys', "Type '{$key}' in notification_defaults", $in_settings, $in_settings ? '' : 'key exists in template but missing from settings' );
			}

			foreach ( array_keys( $notif_defs ) as $key ) {
				$in_template = in_array( $key, $template_keys, true );
				$record( 'notification_keys', "Type '{$key}' has template label", $in_template, $in_template ? '' : 'key in settings but no label in notifications.php', true );
			}
		}

		// ── Section 4: Permission Engine ──────────────────────────────────────
		$admin_id = (int) ( get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			]
		)[0] ?? 1 );

		$member_actions = [ 'read', 'create_posts', 'create_replies', 'vote', 'flag' ];
		$mod_actions    = [ 'edit_others_posts', 'delete_others_posts', 'close_posts', 'pin_posts', 'move_posts' ];
		$admin_actions  = [ 'manage_spaces' ];

		foreach ( array_merge( $member_actions, $mod_actions, $admin_actions ) as $action ) {
			$can = \Jetonomy\Permissions\Permission_Engine::can( $admin_id, $action );
			$record( 'permissions', "Admin can '{$action}'", $can );
		}

		$rate_ok = \Jetonomy\Permissions\Rate_Limiter::check( $admin_id, 'vote', 0 );
		$record( 'permissions', 'Rate limiter: admin bypasses vote limit', $rate_ok );

		$rate_ok_posts = \Jetonomy\Permissions\Rate_Limiter::check( $admin_id, 'create_posts', 0 );
		$record( 'permissions', 'Rate limiter: admin bypasses create_posts limit', $rate_ok_posts );

		$fake_tl0_user_id = 999999;
		set_transient( "jetonomy_rate_{$fake_tl0_user_id}_create_posts", 9999, 60 );
		$tl0_blocked = ! \Jetonomy\Permissions\Rate_Limiter::check( $fake_tl0_user_id, 'create_posts', 0 );
		delete_transient( "jetonomy_rate_{$fake_tl0_user_id}_create_posts" );
		$record( 'permissions', 'Rate limiter: TL0 user is blocked when over limit', $tl0_blocked );

		$restrictions_table = table( 'restrictions' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$restrictions_table,
			[
				'user_id'    => $fake_tl0_user_id,
				'type'       => 'global_ban',
				'reason'     => 'QA test ban',
				'issued_by'  => $admin_id,
				'expires_at' => null,
				'created_at' => current_time( 'mysql' ),
			]
		);
		$ban_id      = $wpdb->insert_id;
		$ban_blocked = ! \Jetonomy\Permissions\Permission_Engine::can( $fake_tl0_user_id, 'create_posts' );
		$wpdb->delete( $restrictions_table, [ 'id' => $ban_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$record( 'permissions', 'Banned user blocked from create_posts', $ban_blocked );

		// ── Section 5: Template Integrity ────────────────────────────────────
		$template_dirs = [
			JETONOMY_DIR . 'templates/views/',
			JETONOMY_DIR . 'templates/partials/',
		];

		foreach ( $template_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				$record( 'templates', "Template dir exists: {$dir}", false, 'directory missing' );
				continue;
			}

			$files = glob( $dir . '*.php' );
			if ( empty( $files ) ) {
				$record( 'templates', "Template dir has files: {$dir}", false, 'no PHP files found', true );
				continue;
			}

			foreach ( $files as $file ) {
				$ok     = true;
				$detail = '';
				try {
					$src    = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$tokens = @token_get_all( $src, TOKEN_PARSE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$ok     = is_array( $tokens );
				} catch ( \ParseError $e ) {
					$ok     = false;
					$detail = $e->getMessage();
				}

				$rel = str_replace( JETONOMY_DIR, '', $file );
				$record( 'templates', "Syntax OK: {$rel}", $ok, $detail );
			}
		}

		// ── Section 6: Rewrite Rules ──────────────────────────────────────────
		$_jt_settings = get_option( 'jetonomy_settings', [] );
		$base         = ! empty( $_jt_settings['base_slug'] ) ? $_jt_settings['base_slug'] : 'community';
		$rules        = get_option( 'rewrite_rules', [] ) ?: [];

		$record( 'rewrite_rules', 'Rewrite rules option is populated', ! empty( $rules ), empty( $rules ) ? 'No rules found -- run wp rewrite flush' : '' );
		$record( 'rewrite_rules', "Base slug '{$base}' is non-empty", ! empty( $base ) );

		$expected_patterns = [
			"^{$base}/?$"                       => 'Community home',
			"^{$base}/category/([^/]+)/?$"      => 'Category view',
			"^{$base}/s/([^/]+)/?$"             => 'Space view',
			"^{$base}/s/([^/]+)/t/([^/]+)/?$"   => 'Single post',
			"^{$base}/u/([^/]+)/?$"             => 'User profile',
			"^{$base}/notifications/?$"         => 'Notifications',
			"^{$base}/search/?$"                => 'Search',
			"^{$base}/leaderboard/?$"           => 'Leaderboard',
			"^{$base}/mod/?$"                   => 'Moderation',
			"^{$base}/tag/([^/]+)/?$"           => 'Tag view',
			"^{$base}/invite/([a-zA-Z0-9]+)/?$" => 'Invite link',
		];

		if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
			$expected_patterns[ "^{$base}/messages/?$" ]       = 'Messages list (Pro)';
			$expected_patterns[ "^{$base}/messages/(\d+)/?$" ] = 'Conversation thread (Pro)';
		}

		foreach ( $expected_patterns as $pattern => $label ) {
			$found = isset( $rules[ $pattern ] );
			$record( 'rewrite_rules', "Rule: {$label}", $found, $found ? '' : "Pattern '{$pattern}' not in rewrite_rules -- run wp rewrite flush" );
		}

		// ── Section 7: Settings Integrity ────────────────────────────────────
		$jt = get_option( 'jetonomy_settings', [] );

		$record( 'settings', 'jetonomy_settings option exists', ! empty( $jt ) );
		$record( 'settings', 'base_slug is set', ! empty( $jt['base_slug'] ) );
		$record( 'settings', 'trust_thresholds is set', ! empty( $jt['trust_thresholds'] ) );
		$record( 'settings', 'rate_limits is set', ! empty( $jt['rate_limits'] ) );
		$record( 'settings', 'notification_defaults is set', ! empty( $jt['notification_defaults'] ) );

		$installed_db = get_option( 'jetonomy_db_version', '' );
		$schema_ver   = defined( 'JETONOMY_DB_VERSION' ) ? JETONOMY_DB_VERSION : '';
		$record( 'settings', 'DB version matches schema', $installed_db === $schema_ver, "installed={$installed_db} schema={$schema_ver}" );

		// ── Section 8: Pro Integration ───────────────────────────────────────
		if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
			$record( 'pro_extensions', 'Jetonomy Pro is active', true, 'v' . JETONOMY_PRO_VERSION );

			$ext_dir        = defined( 'JETONOMY_PRO_DIR' ) ? JETONOMY_PRO_DIR . 'includes/extensions/' : '';
			$enabled_exts   = get_option( 'jetonomy_pro_extensions', [] );
			$discovered_ids = [];

			if ( $ext_dir && is_dir( $ext_dir ) ) {
				$ext_files = glob( $ext_dir . '*/class-extension.php' );
				foreach ( $ext_files as $ext_file ) {
					$ext_id           = basename( dirname( $ext_file ) );
					$discovered_ids[] = $ext_id;

					$is_enabled = in_array( $ext_id, $enabled_exts, true );
					$record( 'pro_extensions', "Extension '{$ext_id}' discovered", true, $is_enabled ? 'enabled' : 'disabled', false );

					$src        = file_get_contents( $ext_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$has_routes = str_contains( $src, 'register_routes' );

					if ( $has_routes && $is_enabled ) {
						preg_match_all(
							"/register_rest_route\s*\([^,]+,\s*['\"]([^'\"]+)['\"]/",
							$src,
							$route_matches
						);

						$ext_route_paths = $route_matches[1] ?? [];

						if ( empty( $ext_route_paths ) ) {
							$record( 'pro_extensions', "Extension '{$ext_id}' REST routes registered", true, 'has register_routes() but no static paths found (may be dynamic)', false );
						} else {
							foreach ( $ext_route_paths as $ext_path ) {
								$expected_key      = '/jetonomy/v1' . $ext_path;
								$found_in_registry = false;
								foreach ( array_keys( $all_routes ) as $rk ) {
									$static_prefix = rtrim( explode( '(', $ext_path )[0], '/' );
									if ( $rk === $expected_key || str_starts_with( $rk, '/jetonomy/v1' . $static_prefix ) ) {
										$found_in_registry = true;
										break;
									}
								}
								$short_path = strlen( $ext_path ) > 40 ? substr( $ext_path, 0, 40 ) . '...' : $ext_path;
								$record( 'pro_extensions', "Extension '{$ext_id}': route '{$short_path}' registered", $found_in_registry, $found_in_registry ? '' : "Route '{$ext_path}' not found in live registry" );
							}
						}
					}
				}

				$record( 'pro_extensions', 'Extensions discovered from filesystem', count( $discovered_ids ) > 0, count( $discovered_ids ) . ' found' );
			} else {
				$record( 'pro_extensions', 'Pro extensions directory accessible', false, 'JETONOMY_PRO_DIR not defined or dir missing' );
			}

			$pro_tables = [
				'jt_pro_conversations'             => 'Conversations',
				'jt_pro_conversation_participants' => 'Conversation participants',
				'jt_pro_messages'                  => 'Messages',
			];
			$pm_enabled = in_array( 'private-messaging', $enabled_exts, true );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing_pro_tables_raw = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}jt_pro_%'" );
			$existing_pro_tables_set = array_flip( $existing_pro_tables_raw );

			foreach ( $pro_tables as $tbl => $label ) {
				$full   = $wpdb->prefix . $tbl;
				$exists = isset( $existing_pro_tables_set[ $full ] );
				if ( ! $pm_enabled ) {
					$record( 'pro_tables', "Pro table {$label} ({$tbl})", $exists, 'private-messaging disabled -- table optional', true );
				} else {
					$record( 'pro_tables', "Pro table {$label} ({$tbl})", $exists, $exists ? '' : 'table missing -- run wp jetonomy recount to trigger activation' );
				}
			}
		}

		// ── Section 9: JS-REST Alignment (Static Analysis) ───────────────────
		$view_js = JETONOMY_DIR . 'assets/js/view.js';

		if ( ! file_exists( $view_js ) ) {
			$record( 'js_rest', 'view.js exists', false, 'file missing' );
		} else {
			$js_src = file_get_contents( $view_js ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			preg_match_all(
				'/fetch\s*\(\s*[`\'"]?\$\{[^}]+\}\/([a-z0-9_\-\/]+)/i',
				$js_src,
				$template_literal_matches
			);
			preg_match_all(
				"/fetch\s*\(\s*[a-zA-Z_]+\s*\+\s*['\"]\/([a-z0-9_\-\/]+)/i",
				$js_src,
				$concat_matches
			);

			$js_segments = array_unique(
				array_merge(
					$template_literal_matches[1] ?? [],
					$concat_matches[1] ?? []
				)
			);

			$js_segments = array_map( fn( $s ) => explode( '?', $s )[0], $js_segments );
			$js_segments = array_filter( $js_segments, fn( $s ) => ! empty( trim( $s ) ) );
			$js_segments = array_values( array_unique( $js_segments ) );

			$route_slugs = [];
			foreach ( array_keys( $all_routes ) as $rp ) {
				$slug          = preg_replace( '#^/jetonomy/v1/#', '', $rp );
				$slug          = preg_replace( '#/\(\?P<[^>]+>[^)]+\)#', '/{id}', $slug );
				$slug          = trim( $slug, '/' );
				$route_slugs[] = $slug;
			}

			if ( empty( $js_segments ) ) {
				$record( 'js_rest', 'Fetch calls extracted from view.js', false, 'regex found no fetch() URL segments', true );
			} else {
				foreach ( $js_segments as $seg ) {
					$normalized = preg_replace( '/\$\{[^}]+\}/', '{id}', $seg );
					$normalized = trim( $normalized, '/' );

					$first_part = explode( '/', $normalized )[0];
					$matched    = false;
					foreach ( $route_slugs as $rs ) {
						if ( str_starts_with( $rs, $first_part ) ) {
							$matched = true;
							break;
						}
					}
					$record( 'js_rest', "JS fetch '{$seg}' matches REST route", $matched, $matched ? '' : "No registered route starting with '{$first_part}'" );
				}
			}
		}

		// ── Aggregate & Output ────────────────────────────────────────────────

		$section_labels = [
			'database'          => 'Database Tables',
			'rest_routes'       => 'REST Routes',
			'notification_keys' => 'Notification Keys',
			'permissions'       => 'Permissions',
			'templates'         => 'Templates',
			'rewrite_rules'     => 'Rewrite Rules',
			'settings'          => 'Settings',
			'pro_extensions'    => 'Pro Extensions',
			'pro_tables'        => 'Pro Tables',
			'js_rest'           => 'JS-REST Alignment',
		];

		$total_checks = 0;
		$total_pass   = 0;
		$total_fail   = 0;
		$total_warn   = 0;

		foreach ( $sections as $key => &$section ) {
			$section['total']  = count( $section['checks'] );
			$section['passed'] = count( array_filter( $section['checks'], fn( $c ) => 'pass' === $c['status'] ) );
			$section['failed'] = count( array_filter( $section['checks'], fn( $c ) => 'fail' === $c['status'] ) );
			$section['warned'] = count( array_filter( $section['checks'], fn( $c ) => 'warn' === $c['status'] ) );

			$total_checks += $section['total'];
			$total_pass   += $section['passed'];
			$total_fail   += $section['failed'];
			$total_warn   += $section['warned'];
		}
		unset( $section );

		$line = str_repeat( '-', 44 );
		\WP_CLI::log( '' );
		\WP_CLI::log( $line );
		\WP_CLI::log( sprintf( '  Jetonomy QA Report -- v%s', $version . ( $pro_version ? " / Pro v{$pro_version}" : '' ) ) );
		\WP_CLI::log( $line );

		$col_label_width = 22;
		$col_count_width = 10;

		foreach ( $section_labels as $key => $label ) {
			if ( ! isset( $sections[ $key ] ) ) {
				continue;
			}
			$s        = $sections[ $key ];
			$fraction = "{$s['passed']}/{$s['total']}";
			$marker   = $s['failed'] > 0 ? 'FAIL' : ( $s['warned'] > 0 ? 'WARN' : 'OK' );
			\WP_CLI::log(
				sprintf(
					'  %-' . $col_label_width . 's %' . $col_count_width . 's  %s',
					$label,
					$fraction,
					$marker
				)
			);
		}

		\WP_CLI::log( $line );
		$total_fraction = "{$total_pass}/{$total_checks}";
		$release_status = 0 === $total_fail ? 'RELEASE READY' : 'RELEASE BLOCKED';
		\WP_CLI::log(
			sprintf(
				'  %-' . $col_label_width . 's %' . $col_count_width . 's  %s%s',
				'TOTAL',
				$total_fraction,
				$release_status,
				$total_warn > 0 ? " ({$total_warn} warnings)" : ''
			)
		);
		\WP_CLI::log( $line );
		\WP_CLI::log( '' );

		$print_details = $total_fail > 0 || $total_warn > 0;
		if ( $print_details ) {
			\WP_CLI::log( 'Details:' );
			foreach ( $sections as $key => $section ) {
				$label = $section_labels[ $key ] ?? $key;
				foreach ( $section['checks'] as $check ) {
					if ( 'fail' === $check['status'] ) {
						$msg = "  FAIL  [{$label}] {$check['label']}";
						if ( $check['detail'] ) {
							$msg .= " -- {$check['detail']}";
						}
						\WP_CLI::warning( $msg );
					} elseif ( 'warn' === $check['status'] ) {
						$msg = "  WARN  [{$label}] {$check['label']}";
						if ( $check['detail'] ) {
							$msg .= " -- {$check['detail']}";
						}
						\WP_CLI::log( $msg );
					}
				}
			}
			\WP_CLI::log( '' );
		}

		// ── Optional JSON Report ──────────────────────────────────────────────
		if ( $write_report ) {
			$plans_dir = JETONOMY_DIR . 'plans/';
			if ( ! is_dir( $plans_dir ) ) {
				wp_mkdir_p( $plans_dir );
			}

			$date        = gmdate( 'Y-m-d' );
			$report_path = $plans_dir . "qa-report-{$date}.json";

			$report = [
				'date'          => $date,
				'timestamp'     => gmdate( 'c' ),
				'version'       => $version,
				'pro_version'   => $pro_version,
				'total_checks'  => $total_checks,
				'passed'        => $total_pass,
				'failed'        => $total_fail,
				'warnings'      => $total_warn,
				'release_ready' => 0 === $total_fail,
				'sections'      => array_combine(
					array_keys( $sections ),
					array_map(
						function ( $section ) {
							return [
								'total'  => $section['total'],
								'passed' => $section['passed'],
								'failed' => $section['failed'],
								'warned' => $section['warned'],
								'checks' => $section['checks'],
							];
						},
						$sections
					)
				),
			];

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = file_put_contents( $report_path, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( $written ) {
				\WP_CLI::success( "JSON report written: {$report_path}" );
			} else {
				\WP_CLI::warning( "Could not write report to {$report_path}" );
			}
		}

		if ( $total_fail > 0 ) {
			\WP_CLI::error( sprintf( '%d check(s) failed -- RELEASE BLOCKED', $total_fail ), false );
		} else {
			\WP_CLI::success( sprintf( 'All %d checks passed%s -- RELEASE READY', $total_checks, $total_warn > 0 ? " ({$total_warn} warnings)" : '' ) );
		}
	}

	/**
	 * Run end-to-end REST round-trip and model unit tests.
	 *
	 * Phase 1 exercises every core action via rest_do_request() -- the same code
	 * path the browser uses. Phase 2 validates the model and permission layer
	 * directly. All test fixtures are created then cleaned up automatically.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy qa-actions
	 *
	 * @subcommand qa-actions
	 */
	public function qa_actions( $args, $assoc_args ): void {
		\WP_CLI::log( '' );
		\WP_CLI::log( '--- Jetonomy Action Tests ---' );

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Phase 1: REST Round-Trip Tests' );
		$rest = new \Jetonomy\QA\REST_Tests();
		$r1   = $rest->run();

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Phase 2: Model Unit Tests' );
		$model = new \Jetonomy\QA\Model_Tests();
		$r2    = $model->run();

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Phase 3: Pro Extension Tests' );
		$pro = new \Jetonomy\QA\Pro_Tests();
		$r3  = $pro->run();

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Phase 4: Journey Smoke Tests (C1-C12)' );
		$journey = new \Jetonomy\QA\Journey_Tests();
		$r4      = $journey->run();

		$total_pass = $r1['pass'] + $r2['pass'] + $r3['pass'] + $r4['pass'];
		$total_fail = $r1['fail'] + $r2['fail'] + $r3['fail'] + $r4['fail'];

		\WP_CLI::log( '' );
		\WP_CLI::log( '--------------------------------------' );
		\WP_CLI::log( sprintf( '  REST Tests:     %d/%d', $r1['pass'], $r1['pass'] + $r1['fail'] ) );
		\WP_CLI::log( sprintf( '  Model Tests:    %d/%d', $r2['pass'], $r2['pass'] + $r2['fail'] ) );
		\WP_CLI::log( sprintf( '  Pro Tests:      %d/%d', $r3['pass'], $r3['pass'] + $r3['fail'] ) );
		\WP_CLI::log( sprintf( '  Journey Tests:  %d/%d', $r4['pass'], $r4['pass'] + $r4['fail'] ) );
		\WP_CLI::log( sprintf( '  TOTAL:          %d/%d', $total_pass, $total_pass + $total_fail ) );
		\WP_CLI::log( '--------------------------------------' );

		if ( $total_fail > 0 ) {
			\WP_CLI::error( sprintf( '%d test(s) failed -- ACTION TESTS FAILED', $total_fail ) );
		} else {
			\WP_CLI::success( sprintf( 'All %d action tests passed. Full stack verified.', $total_pass ) );
		}
	}


	/**
	 * Show plugin status and stats.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy status
	 */
	public function status( $args, $assoc_args ): void {
		global $wpdb;

		\WP_CLI::log( 'Jetonomy v' . JETONOMY_VERSION );
		\WP_CLI::log( 'DB Version: ' . get_option( 'jetonomy_db_version', 'not installed' ) );
		\WP_CLI::log( '' );

		$tables = [
			'categories'    => table( 'categories' ),
			'spaces'        => table( 'spaces' ),
			'posts'         => table( 'posts' ),
			'replies'       => table( 'replies' ),
			'users'         => table( 'user_profiles' ),
			'votes'         => table( 'votes' ),
			'notifications' => table( 'notifications' ),
			'flags'         => table( 'flags' ),
		];

		$union_parts = [];
		foreach ( $tables as $label => $t ) {
			$union_parts[] = "SELECT '{$label}' AS tbl, COUNT(*) AS cnt FROM {$t}";
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count_rows = $wpdb->get_results( implode( ' UNION ALL ', $union_parts ) );
		$count_map  = [];
		foreach ( $count_rows as $row ) {
			$count_map[ $row->tbl ] = (int) $row->cnt;
		}

		foreach ( $tables as $label => $t ) {
			$count = $count_map[ $label ] ?? 0;
			\WP_CLI::log( sprintf( '  %-15s %s', $label, number_format( $count ) ) );
		}
	}
}
