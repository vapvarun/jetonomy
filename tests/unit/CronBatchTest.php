<?php
/**
 * Verifies that each cron handler processes at most one batch of rows per
 * invocation when the table holds more rows than the batch size.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Cron;
use Jetonomy\DB\Schema;
use function Jetonomy\table;

defined( 'ABSPATH' ) || exit;

/**
 * @covers \Jetonomy\Cron
 */
class CronBatchTest extends WP_UnitTestCase {

	/** Default batch size applied by each filterable cron handler. */
	const BATCH = 500;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	// -----------------------------------------------------------------------
	// evaluate_trust_levels
	// -----------------------------------------------------------------------

	/**
	 * Confirm that one trust-sweep batch processes at most the filtered batch
	 * size per invocation. We override the batch to 2, seed 3 qualifying
	 * profiles (all eligible for promotion to trust level 1), run one batch,
	 * and assert only 2 profiles were promoted -- not 3.
	 *
	 * The public entry point evaluate_trust_levels() now only resets the keyset
	 * cursor and enqueues the first async slice (Action Scheduler); the bounded
	 * per-run work lives in evaluate_trust_batch(), which is what we exercise
	 * here so the cap is asserted synchronously without waiting on the queue.
	 */
	public function test_evaluate_trust_levels_caps_at_batch_size(): void {
		global $wpdb;
		$profiles_t = table( 'user_profiles' );
		$posts_t    = table( 'posts' );
		$replies_t  = table( 'replies' );

		// Use a small batch so we can seed only a few rows.
		$small_batch = 2;
		add_filter(
			'jetonomy_cron_batch_size',
			static function ( $default, $handler ) use ( $small_batch ) {
				if ( 'evaluate_trust_levels' === $handler ) {
					return $small_batch;
				}
				return $default;
			},
			10,
			2
		);

		// Seed 3 profiles that all qualify for trust level 1:
		// - post_count >= 5
		// - days_active >= 3 (created_at set 10 days ago)
		// - replies_received >= 10 (seeded via the replies/posts tables)
		$ten_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) );
		$now          = gmdate( 'Y-m-d H:i:s' );

		for ( $uid = 101; $uid <= 103; $uid++ ) {
			$wpdb->insert(
				$profiles_t,
				array(
					'user_id'     => $uid,
					'trust_level' => 0,
					'reputation'  => 0,
					'post_count'  => 5,
					'reply_count' => 0,
					'created_at'  => $ten_days_ago,
				)
			);

			// Create a published post authored by this user.
			$wpdb->insert(
				$posts_t,
				array(
					'space_id'   => 0,
					'author_id'  => $uid,
					'title'      => 'Trust post ' . $uid,
					'slug'       => 'trust-post-' . $uid . '-' . uniqid(),
					'content'    => 'body',
					'status'     => 'publish',
					'created_at' => $ten_days_ago,
					'updated_at' => $ten_days_ago,
				)
			);
			$post_id = (int) $wpdb->insert_id;

			// Seed 10 replies by a different author to satisfy replies_received >= 10.
			for ( $r = 0; $r < 10; $r++ ) {
				$wpdb->insert(
					$replies_t,
					array(
						'post_id'    => $post_id,
						'author_id'  => 999,
						'content'    => 'reply',
						'status'     => 'publish',
						'created_at' => $ten_days_ago,
					)
				);
			}
		}

		// Count promotions fired via the action hook.
		$promoted = array();
		add_action(
			'jetonomy_trust_level_changed',
			static function ( $user_id ) use ( &$promoted ) {
				$promoted[] = $user_id;
			}
		);

		// Fresh cursor (no option yet) => start at user_id 0; evaluate_trust_batch()
		// applies the jetonomy_cron_batch_size filter (=> 2) and runs one slice.
		( new Cron() )->evaluate_trust_batch();

		remove_all_filters( 'jetonomy_cron_batch_size' );

		$this->assertCount(
			$small_batch,
			$promoted,
			sprintf(
				'one trust batch must promote exactly %d profiles per run when batch is %d and %d qualify',
				$small_batch,
				$small_batch,
				3
			)
		);
	}

	// -----------------------------------------------------------------------
	// cleanup_expired_restrictions
	// -----------------------------------------------------------------------

	/**
	 * Confirm that cleanup_expired_restrictions deletes at most BATCH rows per
	 * invocation when more than BATCH expired rows exist.
	 */
	public function test_cleanup_expired_restrictions_caps_at_batch_size(): void {
		global $wpdb;
		$table    = table( 'restrictions' );
		$past     = '2020-01-01 00:00:00';
		$total    = self::BATCH + 1;

		for ( $i = 0; $i < $total; $i++ ) {
			$wpdb->insert(
				$table,
				array(
					'user_id'    => 1,
					'type'       => 'silence',
					'issued_by'  => 1,
					'expires_at' => $past,
					'created_at' => $past,
				)
			);
		}

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertGreaterThanOrEqual( $total, $before );

		( new Cron() )->cleanup_expired_restrictions();

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertSame( self::BATCH, $before - $after, 'cleanup_expired_restrictions must delete exactly BATCH rows per run' );
	}

	// -----------------------------------------------------------------------
	// cleanup_old_notifications
	// -----------------------------------------------------------------------

	/**
	 * Confirm that cleanup_old_notifications marks at most BATCH notifications
	 * as read per invocation when more than BATCH old unread rows exist.
	 */
	public function test_cleanup_old_notifications_caps_at_batch_size(): void {
		global $wpdb;
		$table = table( 'notifications' );
		$old   = '2020-01-01 00:00:00';
		$total = self::BATCH + 1;

		for ( $i = 0; $i < $total; $i++ ) {
			$wpdb->insert(
				$table,
				array(
					'user_id'     => 1,
					'actor_id'    => 2,
					'type'        => 'test',
					'object_type' => 'post',
					'object_id'   => 1,
					'message'     => 'test',
					'is_read'     => 0,
					'created_at'  => $old,
				)
			);
		}

		$before_unread = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$table} WHERE is_read = 0"
		);
		$this->assertGreaterThanOrEqual( $total, $before_unread );

		( new Cron() )->cleanup_old_notifications();

		$after_unread = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$table} WHERE is_read = 0"
		);
		$this->assertSame(
			self::BATCH,
			$before_unread - $after_unread,
			'cleanup_old_notifications must mark exactly BATCH rows as read per run'
		);
	}

	// -----------------------------------------------------------------------
	// publish_scheduled_posts
	// -----------------------------------------------------------------------

	/**
	 * Confirm that publish_scheduled_posts processes at most BATCH due posts
	 * per invocation when more than BATCH scheduled posts are past due.
	 */
	public function test_publish_scheduled_posts_caps_at_batch_size(): void {
		global $wpdb;
		$table = table( 'posts' );
		$past  = '2020-01-01 00:00:00';
		$total = self::BATCH + 1;

		// Insert BATCH + 1 draft posts with a past published_at.
		for ( $i = 0; $i < $total; $i++ ) {
			$wpdb->insert(
				$table,
				array(
					'space_id'      => 0,
					'author_id'     => 1,
					'title'         => 'Scheduled ' . $i,
					'slug'          => 'scheduled-' . $i . '-' . uniqid(),
					'content'       => 'body',
					'content_plain' => 'body',
					'status'        => 'draft',
					'published_at'  => $past,
					'created_at'    => $past,
					'updated_at'    => $past,
				)
			);
		}

		$before_draft = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'draft' AND published_at IS NOT NULL AND published_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		$this->assertGreaterThanOrEqual( $total, $before_draft );

		( new Cron() )->publish_scheduled_posts();

		$after_draft = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'draft' AND published_at IS NOT NULL AND published_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		$this->assertSame(
			self::BATCH,
			$before_draft - $after_draft,
			'publish_scheduled_posts must publish exactly BATCH due posts per run'
		);
	}
}
