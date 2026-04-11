<?php
/**
 * Scenario: a post with two pending flags awaiting moderator review.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Scenarios;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Content_Journey;
use Jetonomy\CLI\Journeys\Moderation_Journey;
use Jetonomy\CLI\Journeys\Space_Journey;
use Jetonomy\CLI\Journeys\Taxonomy_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Creates an author, two reporter users, a fresh category + open space, a
 * published post from the author, and files two flags against the post from
 * the reporters (spam + offensive). QA can then exercise the flag resolution
 * UI end-to-end without manually wiring fixtures.
 *
 * A scenario-local category + space are always provisioned rather than
 * targeting an assumed fixture ID because Jetonomy test databases are not
 * guaranteed to have any specific space pre-seeded.
 */
final class Post_With_Flags_For_Moderation extends Abstract_Scenario {

	public static function name(): string {
		return 'post-with-flags-for-moderation';
	}

	public static function description(): string {
		return 'Seeds a post with two pending flags (spam + offensive) so moderators can test flag resolution.';
	}

	public function run( array $options = [] ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$suffix    = uniqid();
		$taxonomy  = new Taxonomy_Journey();
		$space_srv = new Space_Journey();
		$content   = new Content_Journey();

		$fixtures = [
			'category_id'  => 0,
			'space_id'     => 0,
			'post_id'      => 0,
			'author_id'    => 0,
			'reporter_ids' => [],
			'flag_ids'     => [],
		];

		$cat = $this->step(
			'create-category',
			static fn (): Journey_Result => $taxonomy->create_category(
				[
					'name' => 'Flag scenario cat',
					'slug' => 'pff-cat-' . $suffix,
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
					'title'       => 'Flag scenario space',
					'slug'        => 'pff-space-' . $suffix,
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

		$author = $this->step(
			'create-author',
			static fn (): Journey_Result => self::insert_user( 'pff_author_' . $suffix )
		);
		if ( null === $author ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['author_id'] = (int) $author->data['id'];

		$reporter_one = $this->step(
			'create-reporter-1',
			static fn (): Journey_Result => self::insert_user( 'pff_rep1_' . $suffix )
		);
		if ( null === $reporter_one ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['reporter_ids'][] = (int) $reporter_one->data['id'];

		$reporter_two = $this->step(
			'create-reporter-2',
			static fn (): Journey_Result => self::insert_user( 'pff_rep2_' . $suffix )
		);
		if ( null === $reporter_two ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['reporter_ids'][] = (int) $reporter_two->data['id'];

		$post = $this->step(
			'create-post',
			static fn (): Journey_Result => $content->create_post(
				[
					'space_id'  => (int) $space->data['id'],
					'author_id' => (int) $author->data['id'],
					'title'     => 'Flag me',
					'content'   => 'This is a post that will be flagged twice.',
				]
			)
		);
		if ( null === $post ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['post_id'] = (int) $post->data['id'];

		$flag_one = $this->step(
			'flag-as-spam',
			static fn (): Journey_Result => $content->flag(
				[
					'object_type' => 'post',
					'object_id'   => (int) $post->data['id'],
					'reporter_id' => (int) $reporter_one->data['id'],
					'reason'      => 'spam',
					'description' => 'Scenario spam report',
				]
			)
		);
		if ( null === $flag_one ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['flag_ids'][] = (int) $flag_one->data['id'];

		$flag_two = $this->step(
			'flag-as-offensive',
			static fn (): Journey_Result => $content->flag(
				[
					'object_type' => 'post',
					'object_id'   => (int) $post->data['id'],
					'reporter_id' => (int) $reporter_two->data['id'],
					'reason'      => 'offensive',
					'description' => 'Scenario offensive report',
				]
			)
		);
		if ( null === $flag_two ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['flag_ids'][] = (int) $flag_two->data['id'];

		return $this->finalize( $fixtures, $start );
	}

	public function cleanup( array $fixtures ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$content   = new Content_Journey();
		$mod       = new Moderation_Journey();
		$space_srv = new Space_Journey();
		$taxonomy  = new Taxonomy_Journey();

		$flag_ids     = (array) ( $fixtures['flag_ids'] ?? [] );
		$post_id      = (int) ( $fixtures['post_id'] ?? 0 );
		$reporter_ids = (array) ( $fixtures['reporter_ids'] ?? [] );
		$author_id    = (int) ( $fixtures['author_id'] ?? 0 );
		$space_id     = (int) ( $fixtures['space_id'] ?? 0 );
		$cat_id       = (int) ( $fixtures['category_id'] ?? 0 );

		foreach ( $flag_ids as $idx => $flag_id ) {
			$fid = (int) $flag_id;
			$this->step(
				sprintf( 'dismiss-flag-%d', $idx + 1 ),
				static fn (): Journey_Result => $mod->resolve_flag( $fid, 1, 'dismissed' )
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

		foreach ( array_merge( $reporter_ids, [ $author_id ] ) as $idx => $uid ) {
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
