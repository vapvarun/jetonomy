<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Trust\Trust_Evaluator;
use Jetonomy\Import\Import_Manager;
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
