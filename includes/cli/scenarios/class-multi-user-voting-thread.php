<?php
/**
 * Scenario: post + 5 replies + 10 votes across 3 users.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Scenarios;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Content_Journey;
use Jetonomy\CLI\Journeys\Space_Journey;
use Jetonomy\CLI\Journeys\Taxonomy_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a post with five replies from three authors and casts ten votes
 * across the post and replies so QA can verify sort-by-popular and vote
 * score denormalization without hand-wiring fixtures.
 *
 * The vote plan is deterministic: every user votes once on the post, then
 * each user votes on every reply (3 x 5 = 15 possible slots), but we cap
 * total votes at 10 by cycling through a fixed mix of +1/-1 values so the
 * scoreboard ends non-zero in both directions.
 */
final class Multi_User_Voting_Thread extends Abstract_Scenario {

	public static function name(): string {
		return 'multi-user-voting-thread';
	}

	public static function description(): string {
		return 'Seeds a post with 5 replies from 3 users and 10 votes distributed across them.';
	}

	public function run( array $options = [] ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$suffix    = uniqid();
		$taxonomy  = new Taxonomy_Journey();
		$space_srv = new Space_Journey();
		$content   = new Content_Journey();

		$fixtures = [
			'category_id' => 0,
			'space_id'    => 0,
			'post_id'     => 0,
			'reply_ids'   => [],
			'user_ids'    => [],
			'vote_count'  => 0,
		];

		$cat = $this->step(
			'create-category',
			static fn (): Journey_Result => $taxonomy->create_category(
				[
					'name' => 'Voting scenario cat',
					'slug' => 'muv-cat-' . $suffix,
				]
			)
		);
		if ( null === $cat ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['category_id'] = (int) $cat->data['id'];

		$space = $this->step(
			'create-space',
			static fn (): Journey_Result => $space_srv->create(
				[
					'title'       => 'Voting scenario space',
					'slug'        => 'muv-space-' . $suffix,
					'category_id' => (int) $cat->data['id'],
					'type'        => 'forum',
					'visibility'  => 'public',
					'join_policy' => 'open',
				]
			)
		);
		if ( null === $space ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['space_id'] = (int) $space->data['id'];

		$user_ids = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$slot   = $i;
			$result = $this->step(
				sprintf( 'create-user-%d', $i ),
				static fn (): Journey_Result => self::insert_user( 'muv_u' . $slot . '_' . $suffix )
			);
			if ( null === $result ) {
				return $this->finalize( $fixtures, $start );
			}
			$user_ids[] = (int) $result->data['id'];
		}
		$fixtures['user_ids'] = $user_ids;

		$post = $this->step(
			'create-post',
			static fn (): Journey_Result => $content->create_post(
				[
					'space_id'  => (int) $space->data['id'],
					'author_id' => $user_ids[0],
					'title'     => 'Multi-user voting thread',
					'content'   => 'Seeded by multi-user-voting-thread scenario.',
				]
			)
		);
		if ( null === $post ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['post_id'] = (int) $post->data['id'];

		$reply_ids = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$slot   = $i;
			$author = $user_ids[ ( $i - 1 ) % 3 ];
			$result = $this->step(
				sprintf( 'create-reply-%d', $i ),
				static fn (): Journey_Result => $content->create_reply(
					[
						'post_id'   => (int) $post->data['id'],
						'author_id' => $author,
						'content'   => sprintf( 'Reply %d body.', $slot ),
					]
				)
			);
			if ( null === $result ) {
				return $this->finalize( $fixtures, $start );
			}
			$reply_ids[] = (int) $result->data['id'];
		}
		$fixtures['reply_ids'] = $reply_ids;

		// Ten votes: post gets one from each user (3), then seven more across
		// the first four replies cycling users. Values alternate +1/-1.
		$vote_plan = [
			[
				'user'  => $user_ids[0],
				'type'  => 'post',
				'id'    => (int) $post->data['id'],
				'value' => 1,
			],
			[
				'user'  => $user_ids[1],
				'type'  => 'post',
				'id'    => (int) $post->data['id'],
				'value' => 1,
			],
			[
				'user'  => $user_ids[2],
				'type'  => 'post',
				'id'    => (int) $post->data['id'],
				'value' => -1,
			],
			[
				'user'  => $user_ids[0],
				'type'  => 'reply',
				'id'    => $reply_ids[0],
				'value' => 1,
			],
			[
				'user'  => $user_ids[1],
				'type'  => 'reply',
				'id'    => $reply_ids[0],
				'value' => 1,
			],
			[
				'user'  => $user_ids[2],
				'type'  => 'reply',
				'id'    => $reply_ids[1],
				'value' => -1,
			],
			[
				'user'  => $user_ids[0],
				'type'  => 'reply',
				'id'    => $reply_ids[2],
				'value' => 1,
			],
			[
				'user'  => $user_ids[1],
				'type'  => 'reply',
				'id'    => $reply_ids[2],
				'value' => -1,
			],
			[
				'user'  => $user_ids[2],
				'type'  => 'reply',
				'id'    => $reply_ids[3],
				'value' => 1,
			],
			[
				'user'  => $user_ids[0],
				'type'  => 'reply',
				'id'    => $reply_ids[4],
				'value' => 1,
			],
		];

		$vote_count = 0;
		foreach ( $vote_plan as $idx => $plan ) {
			$result = $this->step(
				sprintf( 'vote-%d', $idx + 1 ),
				static fn (): Journey_Result => $content->vote(
					(int) $plan['user'],
					(string) $plan['type'],
					(int) $plan['id'],
					(int) $plan['value']
				)
			);
			if ( null === $result ) {
				return $this->finalize( $fixtures, $start );
			}
			++$vote_count;
		}
		$fixtures['vote_count'] = $vote_count;

		return $this->finalize( $fixtures, $start );
	}

	public function cleanup( array $fixtures ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$content   = new Content_Journey();
		$space_srv = new Space_Journey();
		$taxonomy  = new Taxonomy_Journey();

		$reply_ids = (array) ( $fixtures['reply_ids'] ?? [] );
		$post_id   = (int) ( $fixtures['post_id'] ?? 0 );
		$user_ids  = (array) ( $fixtures['user_ids'] ?? [] );
		$space_id  = (int) ( $fixtures['space_id'] ?? 0 );
		$cat_id    = (int) ( $fixtures['category_id'] ?? 0 );

		foreach ( $reply_ids as $idx => $rid ) {
			$rid = (int) $rid;
			$this->step(
				sprintf( 'delete-reply-%d', $idx + 1 ),
				static fn (): Journey_Result => $content->delete_reply( $rid )
			);
			$this->failed = false;
		}

		if ( $post_id > 0 ) {
			$this->step(
				'delete-post',
				static fn (): Journey_Result => $content->delete_post( $post_id )
			);
			$this->failed = false;
		}

		foreach ( $user_ids as $idx => $uid ) {
			$target = (int) $uid;
			if ( $target <= 0 ) {
				continue;
			}
			$this->step(
				sprintf( 'delete-user-%d', $idx + 1 ),
				static function () use ( $target ): Journey_Result {
					if ( ! function_exists( 'wp_delete_user' ) ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
					}
					$ok = wp_delete_user( $target );
					return $ok
						? Journey_Result::ok( [ 'id' => $target ] )
						: Journey_Result::fail( sprintf( 'wp_delete_user(%d) returned false.', $target ) );
				}
			);
			$this->failed = false;
		}

		if ( $space_id > 0 ) {
			$this->step(
				'delete-space',
				static fn (): Journey_Result => $space_srv->delete( $space_id )
			);
			$this->failed = false;
		}

		if ( $cat_id > 0 ) {
			$this->step(
				'delete-category',
				static fn (): Journey_Result => $taxonomy->delete_category( $cat_id )
			);
			$this->failed = false;
		}

		return $this->finalize( $fixtures, $start );
	}

	/**
	 * Create a subscriber user or return a failed Journey_Result.
	 */
	private static function insert_user( string $login ): Journey_Result {
		$uid = wp_insert_user(
			[
				'user_login' => $login,
				'user_email' => $login . '@example.test',
				'user_pass'  => wp_generate_password( 16, false ),
				'role'       => 'subscriber',
			]
		);
		if ( is_wp_error( $uid ) ) {
			return Journey_Result::from_wp_error( $uid );
		}
		return Journey_Result::ok( [ 'id' => (int) $uid ] );
	}
}
