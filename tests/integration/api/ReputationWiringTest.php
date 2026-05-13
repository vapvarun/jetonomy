<?php
/**
 * Integration test: WS4-B Reputation wiring.
 *
 * Verifies the three previously-dead POINTS_MAP entries fire from the
 * surfaces they're supposed to fire from:
 *
 *  - post_reported  : POST /flags deducts -10 from the reported author
 *  - flag_validated : POST /moderation/flags/{id}/resolve (valid) gives the reporter +5
 *  - idea_planned   : PATCH /posts/{id}/idea-status (planned) gives the author +20
 *
 * Each scenario uses two distinct users (actor + author) so the
 * skip-self-action guards in the wiring don't suppress the award.
 *
 * @package Jetonomy\Tests\Integration\API
 */

namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;

class ReputationWiringTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin_id;
	private int $author_id;
	private int $space_id;
	private int $ideas_space_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->grant_caps( $this->admin_id, true );
		$this->grant_caps( $this->author_id, false );

		UserProfile::find_or_create( $this->admin_id );
		UserProfile::find_or_create( $this->author_id );

		$cat_id = Category::create(
			array(
				'name' => 'WS4-B Cat',
				'slug' => 'ws4b-cat-' . uniqid(),
			)
		);

		$this->space_id = Space::create(
			array(
				'title'       => 'WS4-B Discussion Space',
				'slug'        => 'ws4b-discuss-' . uniqid(),
				'category_id' => $cat_id,
				'visibility'  => 'public',
				'type'        => 'discussion',
			)
		);
		$this->ideas_space_id = Space::create(
			array(
				'title'       => 'WS4-B Ideas Space',
				'slug'        => 'ws4b-ideas-' . uniqid(),
				'category_id' => $cat_id,
				'visibility'  => 'public',
				'type'        => 'ideas',
			)
		);

		SpaceMember::add( $this->space_id, $this->admin_id, 'admin' );
		SpaceMember::add( $this->space_id, $this->author_id, 'member' );
		SpaceMember::add( $this->ideas_space_id, $this->admin_id, 'admin' );
		SpaceMember::add( $this->ideas_space_id, $this->author_id, 'member' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function grant_caps( int $user_id, bool $is_moderator ): void {
		$user = get_user_by( 'id', $user_id );
		$caps = array(
			'jetonomy_read',
			'jetonomy_create_posts',
			'jetonomy_create_replies',
			'jetonomy_vote',
			'jetonomy_flag',
		);
		if ( $is_moderator ) {
			$caps[] = 'jetonomy_moderate';
			$caps[] = 'jetonomy_close_posts';
			$caps[] = 'jetonomy_edit_others_posts';
			$caps[] = 'jetonomy_delete_others_posts';
		}
		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}
	}

	private function reputation( int $user_id ): int {
		$profile = UserProfile::find_by_user( $user_id );
		return $profile ? (int) $profile->reputation : 0;
	}

	private function do_request( int $as_user, string $method, string $route, array $params = [] ) {
		wp_set_current_user( $as_user );
		$request = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		if ( in_array( $method, array( 'POST', 'PATCH', 'PUT' ), true ) ) {
			$request->set_body_params( $params );
		} else {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}
		return $this->server->dispatch( $request );
	}

	// -------------------------------------------------------------------------
	// post_reported : -10 to the reported author when a moderator-eligible user
	// reports their post.
	// -------------------------------------------------------------------------

	public function test_reporting_a_post_deducts_from_author(): void {
		$post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Reportable post',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		$before = $this->reputation( $this->author_id );

		$response = $this->do_request(
			$this->admin_id,
			'POST',
			'/flags',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'reason'      => 'spam',
			)
		);

		$this->assertSame( 201, $response->get_status(), 'Flag should be created' );

		$after = $this->reputation( $this->author_id );
		$this->assertSame( $before - 10, $after, 'Reported author should lose 10 reputation' );
	}

	public function test_self_report_does_not_deduct(): void {
		$post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->admin_id,
				'title'     => 'Own post',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		$before = $this->reputation( $this->admin_id );

		$this->do_request(
			$this->admin_id,
			'POST',
			'/flags',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'reason'      => 'spam',
			)
		);

		$this->assertSame( $before, $this->reputation( $this->admin_id ), 'Self-report must not deduct' );
	}

	// -------------------------------------------------------------------------
	// flag_validated : +5 to the reporter when a moderator marks the flag valid.
	// -------------------------------------------------------------------------

	public function test_validating_a_flag_rewards_reporter(): void {
		$reporter_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_caps( $reporter_id, false );
		UserProfile::find_or_create( $reporter_id );
		SpaceMember::add( $this->space_id, $reporter_id, 'member' );

		$post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Flag target',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		// Reporter (a third party — not author, not moderator) files the flag.
		$create = $this->do_request(
			$reporter_id,
			'POST',
			'/flags',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'reason'      => 'spam',
			)
		);
		$this->assertSame( 201, $create->get_status() );

		$flag_id = (int) $create->get_data()['id'];
		$before  = $this->reputation( $reporter_id );

		// Moderator validates the flag.
		$resolve = $this->do_request(
			$this->admin_id,
			'POST',
			"/moderation/flags/{$flag_id}/resolve",
			array( 'status' => 'valid' )
		);
		$this->assertSame( 200, $resolve->get_status(), 'Valid resolution should succeed' );

		$after = $this->reputation( $reporter_id );
		$this->assertSame( $before + 5, $after, 'Reporter should gain 5 reputation when flag is valid' );
	}

	public function test_dismissing_a_flag_does_not_reward_reporter(): void {
		$reporter_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_caps( $reporter_id, false );
		UserProfile::find_or_create( $reporter_id );
		SpaceMember::add( $this->space_id, $reporter_id, 'member' );

		$post_id = Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Dismissable',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		$create  = $this->do_request(
			$reporter_id,
			'POST',
			'/flags',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'reason'      => 'spam',
			)
		);
		$flag_id = (int) $create->get_data()['id'];
		$before  = $this->reputation( $reporter_id );

		$this->do_request(
			$this->admin_id,
			'POST',
			"/moderation/flags/{$flag_id}/resolve",
			array( 'status' => 'dismissed' )
		);

		$this->assertSame( $before, $this->reputation( $reporter_id ), 'Dismissed flag must not reward' );
	}

	// -------------------------------------------------------------------------
	// idea_planned : +20 to the idea's author when a curator moves status -> planned.
	// -------------------------------------------------------------------------

	public function test_idea_status_planned_rewards_author(): void {
		$post_id = Post::create(
			array(
				'space_id'  => $this->ideas_space_id,
				'author_id' => $this->author_id,
				'title'     => 'Idea: Dark mode',
				'content'   => 'Please.',
				'status'    => 'publish',
			)
		);

		$before = $this->reputation( $this->author_id );

		$response = $this->do_request(
			$this->admin_id,
			'POST',
			"/posts/{$post_id}/idea-status",
			array( 'idea_status' => 'planned' )
		);

		$this->assertSame( 200, $response->get_status(), 'Idea status change should succeed' );

		$after = $this->reputation( $this->author_id );
		$this->assertSame( $before + 20, $after, 'Idea author should gain 20 reputation when planned' );
	}

	public function test_idea_status_planned_is_idempotent_on_repeat(): void {
		$post_id = Post::create(
			array(
				'space_id'    => $this->ideas_space_id,
				'author_id'   => $this->author_id,
				'title'       => 'Already planned idea',
				'content'     => 'Body',
				'status'      => 'publish',
				'idea_status' => 'planned',
			)
		);

		$before = $this->reputation( $this->author_id );

		$this->do_request(
			$this->admin_id,
			'POST',
			"/posts/{$post_id}/idea-status",
			array( 'idea_status' => 'planned' )
		);

		$this->assertSame( $before, $this->reputation( $this->author_id ), 'Re-planning must not stack reputation' );
	}
}
