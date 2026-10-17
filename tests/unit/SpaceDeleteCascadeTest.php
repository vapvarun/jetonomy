<?php
namespace Jetonomy\Tests\Unit;

use Jetonomy\CLI\Journeys\Space_Journey;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use WP_UnitTestCase;

/**
 * REGRESSION (Basecamp 10415690130): Space::delete() removed the space row and
 * left its topics and replies behind (700 topics after one imported space).
 */
class SpaceDeleteCascadeTest extends WP_UnitTestCase {

	private function seed( string $slug ): array {
		$space_id = Space::create( [ 'title' => 'S ' . $slug, 'slug' => $slug, 'visibility' => 'public' ], 1 );
		$post_id  = Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => 1,
				'title'     => 'T ' . $slug,
				'slug'      => $slug . '-t',
				'content'   => 'body',
				'status'    => 'publish',
			]
		);
		$reply_id = Reply::create( [ 'post_id' => $post_id, 'author_id' => 1, 'content' => 'r' ] );
		return [ $space_id, $post_id, $reply_id ];
	}

	private function rows( string $table, int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_{$table} WHERE id = %d", $id ) );
	}

	/** Make the purge's topic lookup for one space fail, as a database outage would. */
	private function break_topic_lookup( int $space_id ): callable {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$filter = static function ( $sql ) use ( $space_id ) {
			if ( str_contains( $sql, 'jt_posts WHERE space_id = ' . $space_id ) ) {
				return 'SELECT id FROM jt_no_such_table_for_test';
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		return $filter;
	}

	public function test_delete_cascades_to_topics_and_replies(): void {
		[ $space_id, $post_id, $reply_id ] = $this->seed( 'del-cascade' );
		[ $keep_space, $keep_post ]        = $this->seed( 'del-bystander' );

		$this->assertTrue( Space::delete( $space_id ) );

		$this->assertNull( Space::find( $space_id ) );
		$this->assertSame( 0, $this->rows( 'posts', $post_id ), 'topic orphaned by Space::delete' );
		$this->assertSame( 0, $this->rows( 'replies', $reply_id ), 'reply orphaned by Space::delete' );
		$this->assertNotNull( Space::find( $keep_space ), 'a neighbouring space was removed' );
		$this->assertSame( 1, $this->rows( 'posts', $keep_post ), 'a neighbouring topic was removed' );
	}

	public function test_a_database_failure_keeps_the_space_and_its_topics(): void {
		global $wpdb;
		[ $space_id, $post_id, $reply_id ] = $this->seed( 'del-dbfail' );

		$filter = $this->break_topic_lookup( $space_id );
		$result = Space::delete( $space_id );
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( false );

		$this->assertWPError( $result, 'a refused delete reported success' );
		$this->assertSame( 'jetonomy_space_delete_failed', $result->get_error_code() );
		$this->assertSame( 1, $this->rows( 'spaces', $space_id ), 'space row lost on a database failure' );
		$this->assertSame( 1, $this->rows( 'posts', $post_id ) );
		$this->assertSame( 1, $this->rows( 'replies', $reply_id ) );
	}

	public function test_cli_purge_reports_a_database_failure(): void {
		global $wpdb;
		[ $space_id, $post_id ] = $this->seed( 'del-cli-dbfail' );

		$filter = $this->break_topic_lookup( $space_id );
		$result = ( new Space_Journey() )->delete( $space_id, 'purge' );
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( false );

		$this->assertFalse( $result->success, 'CLI said the space was deleted while the database refused' );
		$this->assertSame( 1, $this->rows( 'spaces', $space_id ) );
		$this->assertSame( 1, $this->rows( 'posts', $post_id ) );
	}

	public function test_deleting_a_missing_space_is_false(): void {
		$this->assertFalse( Space::delete( 999999 ) );
	}
}
