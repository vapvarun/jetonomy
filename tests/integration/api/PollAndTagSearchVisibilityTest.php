<?php
/**
 * Two read paths that listed more than the viewer may see.
 *
 * 1. Post-scope polling listed every reply id, so a reader could watch pending
 *    and spam replies arrive. It lists published replies only.
 * 2. The jetonomy/search ability returned tags (and stored counts) from
 *    private spaces. It returns tags with a readable topic, counted as the
 *    viewer sees them.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use Jetonomy\Abilities;
use Jetonomy\Models\ActivityLog;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\Tag;
use WP_UnitTestCase;

class PollAndTagSearchVisibilityTest extends WP_UnitTestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function space( string $visibility ): int {
		return (int) Space::create(
			array(
				'title'      => ucfirst( $visibility ) . ' space',
				'slug'       => $visibility . '-' . uniqid(),
				'visibility' => $visibility,
			),
			$this->admin
		);
	}

	private function topic( int $space_id, string $title ): int {
		return (int) Post::create(
			array(
				'space_id'  => $space_id,
				'author_id' => $this->admin,
				'title'     => $title,
				'slug'      => sanitize_title( $title ) . '-' . uniqid(),
				'content'   => '<p>Body.</p>',
				'status'    => 'publish',
			)
		);
	}

	public function test_polling_lists_published_replies_only(): void {
		$post_id = $this->topic( $this->space( 'public' ), 'Polled topic' );
		$ids     = array();
		foreach ( array( 'publish', 'pending', 'spam', 'trash' ) as $status ) {
			$ids[ $status ] = (int) Reply::create(
				array(
					'post_id'   => $post_id,
					'author_id' => $this->admin,
					'content'   => '<p>' . $status . '</p>',
					'status'    => $status,
				)
			);
		}

		$listed = Reply::ids_since( $post_id, '2000-01-01 00:00:00' );

		$this->assertSame( array( $ids['publish'] ), $listed );
	}

	public function test_tag_search_ability_hides_tags_used_only_in_private_spaces(): void {
		$public  = $this->topic( $this->space( 'public' ), 'Open topic' );
		$private = $this->topic( $this->space( 'private' ), 'Hidden topic' );
		$open_tag   = Tag::find_or_create( 'zq-open-tag' );
		$secret_tag = Tag::find_or_create( 'zq-secret-tag' );
		Tag::attach_to_post( $public, $open_tag );
		Tag::attach_to_post( $private, $secret_tag );

		wp_set_current_user( 0 );
		$tags  = ( new Abilities() )->execute_search(
			array(
				'query'  => 'zq-',
				'filter' => 'tags',
			)
		)['tags'];
		$names = array_map( static fn( $t ) => $t->name, $tags );

		$this->assertContains( 'zq-open-tag', $names );
		$this->assertNotContains( 'zq-secret-tag', $names );
	}

	/** @covers-existing Pins the visibility the EXISTS rewrite must keep; the old IN() form returned the same rows, only slower. */
	public function test_global_poll_lists_only_published_content_the_viewer_can_read(): void {
		$public_topic  = $this->topic( $this->space( 'public' ), 'Open feed topic' );
		$private_topic = $this->topic( $this->space( 'private' ), 'Hidden feed topic' );
		$open_reply    = (int) Reply::create( array( 'post_id' => $public_topic, 'author_id' => $this->admin, 'content' => '<p>a</p>', 'status' => 'publish' ) );
		$pending_reply = (int) Reply::create( array( 'post_id' => $public_topic, 'author_id' => $this->admin, 'content' => '<p>b</p>', 'status' => 'pending' ) );
		$hidden_reply  = (int) Reply::create( array( 'post_id' => $private_topic, 'author_id' => $this->admin, 'content' => '<p>c</p>', 'status' => 'publish' ) );
		foreach ( array( array( 'post', $public_topic ), array( 'post', $private_topic ), array( 'reply', $open_reply ), array( 'reply', $pending_reply ), array( 'reply', $hidden_reply ) ) as $row ) {
			ActivityLog::log( $this->admin, 'created', $row[0], $row[1] );
		}

		$seen = array();
		foreach ( ActivityLog::list_since_visible( '2000-01-01 00:00:00', 0 ) as $row ) {
			$seen[ $row->object_type . ':' . $row->object_id ] = true;
		}

		$this->assertEqualsCanonicalizing(
			array( 'post:' . $public_topic, 'reply:' . $open_reply ),
			array_values( array_intersect( array_keys( $seen ), array( 'post:' . $public_topic, 'post:' . $private_topic, 'reply:' . $open_reply, 'reply:' . $pending_reply, 'reply:' . $hidden_reply ) ) )
		);
	}
}
