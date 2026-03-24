<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Trust\Trust_Evaluator;
use Jetonomy\Import\Import_Manager;
use Jetonomy\Admin\Ajax\Demo_Seeder;
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
		global $wpdb;
		$type = $assoc_args['type'] ?? 'all';

		$posts_t    = table( 'posts' );
		$replies_t  = table( 'replies' );
		$spaces_t   = table( 'spaces' );
		$votes_t    = table( 'votes' );
		$cats_t     = table( 'categories' );
		$profiles_t = table( 'user_profiles' );

		if ( in_array( $type, [ 'all', 'posts' ], true ) ) {
			\WP_CLI::log( 'Recounting reply counts on posts...' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$posts_t} p SET p.reply_count = (SELECT COUNT(*) FROM {$replies_t} r WHERE r.post_id = p.id AND r.status = 'publish')" );

			\WP_CLI::log( 'Updating last_reply_at on posts...' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$posts_t} p SET p.last_reply_at = (SELECT MAX(r.created_at) FROM {$replies_t} r WHERE r.post_id = p.id AND r.status = 'publish')" );
		}

		if ( in_array( $type, [ 'all', 'spaces' ], true ) ) {
			\WP_CLI::log( 'Recounting post counts on spaces...' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$spaces_t} s SET s.post_count = (SELECT COUNT(*) FROM {$posts_t} p WHERE p.space_id = s.id AND p.status = 'publish')" );

			\WP_CLI::log( 'Recounting space counts on categories...' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$cats_t} c SET c.space_count = (SELECT COUNT(*) FROM {$spaces_t} s WHERE s.category_id = c.id AND s.status = 'active')" );
		}

		if ( in_array( $type, [ 'all', 'votes' ], true ) ) {
			\WP_CLI::log( 'Recounting vote scores on posts...' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$posts_t} p SET p.vote_score = COALESCE((SELECT SUM(v.value) FROM {$votes_t} v WHERE v.object_type = 'post' AND v.object_id = p.id), 0)" );

			\WP_CLI::log( 'Recounting vote scores on replies...' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$replies_t} r SET r.vote_score = COALESCE((SELECT SUM(v.value) FROM {$votes_t} v WHERE v.object_type = 'reply' AND v.object_id = r.id), 0)" );
		}

		if ( 'all' === $type ) {
			\WP_CLI::log( 'Recounting user stats...' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$profiles_t} u SET u.post_count = (SELECT COUNT(*) FROM {$posts_t} p WHERE p.author_id = u.user_id AND p.status = 'publish')" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$profiles_t} u SET u.reply_count = (SELECT COUNT(*) FROM {$replies_t} r WHERE r.author_id = u.user_id AND r.status = 'publish')" );
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
				'replies_received' => 0, // Would need a join to count — simplified
			];

			$new_level = Trust_Evaluator::evaluate_level( $stats );

			if ( $new_level > (int) $profile->trust_level ) {
				$wpdb->update( $profiles_t, [ 'trust_level' => $new_level ], [ 'user_id' => $profile->user_id ] );
				\WP_CLI::log( sprintf( 'User %d: Level %d → %d', $profile->user_id, $profile->trust_level, $new_level ) );
				do_action( 'jetonomy_trust_level_changed', (int) $profile->user_id, (int) $profile->trust_level, $new_level );
				$promoted++;
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
			\WP_CLI::log( 'DRY RUN — no data will be written.' );
		}

		$result = Import_Manager::run( $source, [ 'dry_run' => $dry_run ] );
		if ( null === $result ) {
			\WP_CLI::error( "Unknown source: {$source}. Available: " . implode( ', ', array_keys( Import_Manager::get_available() ) ) );
			return;
		}

		$prefix = $dry_run ? '[DRY RUN] ' : '';

		\WP_CLI::success( sprintf(
			'%sImport complete. Imported: %d, Skipped: %d, Errors: %d',
			$prefix,
			$result['imported'],
			$result['skipped'],
			count( $result['errors'] )
		) );

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
			$wpdb->insert( $activity_t, [
				'user_id'     => (int) $p->author_id,
				'action'      => 'created_post',
				'object_type' => 'post',
				'object_id'   => (int) $p->id,
				'metadata'    => wp_json_encode( [ 'space_id' => (int) $p->space_id ] ),
				'created_at'  => $p->created_at,
			] );
			$inserted++;
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
			$wpdb->insert( $activity_t, [
				'user_id'     => (int) $r->author_id,
				'action'      => 'created_reply',
				'object_type' => 'reply',
				'object_id'   => (int) $r->id,
				'metadata'    => wp_json_encode( [ 'post_id' => (int) $r->post_id ] ),
				'created_at'  => $r->created_at,
			] );
			$inserted++;
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
			$wpdb->insert( $activity_t, [
				'user_id'     => (int) $m->user_id,
				'action'      => 'joined_space',
				'object_type' => 'space',
				'object_id'   => (int) $m->space_id,
				'metadata'    => wp_json_encode( [ 'role' => $m->role ] ),
				'created_at'  => $m->joined_at,
			] );
			$inserted++;
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

		$admin_id = (int) get_option( 'jetonomy_setup_admin_id', get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] ?? 1 );

		\WP_CLI::log( 'Seeding demo users...' );
		$demo = Demo_Seeder::seed( $admin_id );
		update_option( 'jetonomy_demo_data', $demo );
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
	 * spaces, and categories — everything tracked in the jetonomy_demo_data option.
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

		foreach ( $tables as $label => $t ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
			\WP_CLI::log( sprintf( '  %-15s %s', $label, number_format( $count ) ) );
		}
	}
}
