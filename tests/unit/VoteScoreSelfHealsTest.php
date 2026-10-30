<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Vote;
use Jetonomy\DB\Schema;

/**
 * A topic's score is the sum of its votes after every vote. Rapid double
 * clicks raced (both read "no vote yet", one insert lost to the unique key)
 * and the old increment left the score off for good (2.0.7 smoke c-member-vote.18).
 */
class VoteScoreSelfHealsTest extends WP_UnitTestCase {

	public function test_score_matches_the_votes_after_a_lost_race(): void {
		Schema::create_tables();
		global $wpdb;
		$author = self::factory()->user->create();
		$voter  = self::factory()->user->create();
		$other  = self::factory()->user->create();
		$cat    = Category::create( array( 'name' => 'VS', 'slug' => 'vs-' . uniqid() ) );
		$space  = Space::create( array( 'title' => 'VS', 'slug' => 'vs-' . uniqid(), 'category_id' => $cat ) );
		$post   = Post::create( array( 'space_id' => $space, 'author_id' => $author, 'title' => 'Score', 'content' => 'x' ) );

		Vote::cast( $other, 'post', $post, 1 );
		// The drift a lost race left behind: the counter says 0, the votes say 1.
		$wpdb->update( \Jetonomy\table( 'posts' ), array( 'vote_score' => 0 ), array( 'id' => $post ) );

		Vote::cast( $voter, 'post', $post, 1 );
		Post::bust_cache( $post );
		$this->assertSame( 2, (int) Post::find( $post )->vote_score );

		Vote::cast( $voter, 'post', $post, 1 ); // Retract.
		Post::bust_cache( $post );
		$this->assertSame( 1, (int) Post::find( $post )->vote_score );
	}
}
