<?php
/**
 * The Activity Log and the Dashboard's Recent Activity read in plain words.
 *
 * Rows used to print "Post #4253", "delta=-10 • reason=post_upvoted_revoked"
 * and a bare "Reputation changed". These hold the shared formatter on
 * Activity_List_Table to titles, deltas and reasons a site owner can read.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Unit\Admin;

use WP_UnitTestCase;
use Jetonomy\Admin\Activity_List_Table;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;

class ActivityFormatterTest extends WP_UnitTestCase {

	private function row( string $action, string $type, int $id, array $meta = array() ): object {
		return (object) array(
			'user_id'     => 1,
			'action'      => $action,
			'object_type' => $type,
			'object_id'   => $id,
			'metadata'    => $meta ? wp_json_encode( $meta ) : '',
		);
	}

	public function test_reputation_row_says_how_much_and_why(): void {
		$row = $this->row( 'reputation_changed', 'post', 1, array( 'delta' => -10, 'reason' => 'post_upvoted_revoked' ) );

		$this->assertSame( 'Lost 10 reputation points', Activity_List_Table::action_text( $row ) );
		$this->assertSame( 'Their topic was upvoted, then undone', Activity_List_Table::details_text( $row ) );
		$this->assertSame( 'Gained 15 reputation points', Activity_List_Table::action_text( $this->row( 'reputation_changed', 'reply', 1, array( 'delta' => 15, 'reason' => 'reply_accepted' ) ) ) );
	}

	public function test_details_never_print_raw_keys(): void {
		foreach ( array(
			$this->row( 'trust_level_changed', 'user', 1, array( 'old' => 0, 'new' => 1 ) ),
			$this->row( 'joined_space', 'space', 1, array( 'role' => 'moderator' ) ),
			$this->row( 'idea_status_changed', 'post', 1, array( 'old' => 'in_progress', 'new' => 'shipped' ) ),
			$this->row( 'addon_event', 'post', 1, array( 'space_id' => 235 ) ),
		) as $row ) {
			$text = Activity_List_Table::details_text( $row );
			$this->assertStringNotContainsString( '=', $text );
			$this->assertStringNotContainsString( '_', $text, $text );
		}
	}

	public function test_object_shows_the_title_from_one_batched_load(): void {
		$space_id = Space::create(
			array(
				'title' => 'Help Desk',
				'slug'  => 'help-desk-' . wp_generate_password( 6, false ),
			)
		);
		$post_id  = Post::create(
			array(
				'space_id'  => $space_id,
				'author_id' => 1,
				'title'     => 'Backups for membership sites',
				'slug'      => 'backups-' . wp_generate_password( 6, false ),
				'content'   => 'Body',
			)
		);
		$rows     = array( $this->row( 'voted', 'post', $post_id ), $this->row( 'voted', 'post', 999999 ) );
		$context  = Activity_List_Table::prime( $rows );

		$queries = array();
		$spy     = static function ( $sql ) use ( &$queries ) {
			if ( str_contains( $sql, 'jt_' ) || str_contains( $sql, 'users' ) ) { // Content and user lookups; the option cache refilling is not per row.
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $spy );
		$live = Activity_List_Table::object_html( $rows[0], $context );
		$gone = Activity_List_Table::object_html( $rows[1], $context );
		remove_filter( 'query', $spy );
		$this->assertSame( array(), $queries, 'Rendering a row must not query; prime() loads everything.' );

		$this->assertStringContainsString( 'Backups for membership sites</a>', $live );
		$this->assertSame( 'Deleted topic', $gone );
	}
}
