<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

/**
 * REGRESSION (Basecamp 10415690152): a topic in an archived space showed
 * "Be the first to reply!" and the reply composer, while REST refuses the reply
 * with jetonomy_space_restricted.
 *
 * single-post.php declares a function, so it can be included once per process:
 * each render runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ArchivedSpaceReplyInviteTest extends WP_UnitTestCase {

	private int $admin;
	private int $member;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/** A public space with one published topic, optionally with one reply, the member joined. */
	private function topic( string $status, bool $with_reply = false ): array {
		$space = (int) Space::create(
			array(
				'title'      => 'Hackathon 2025',
				'slug'       => 'hack-' . uniqid(),
				'visibility' => 'public',
			),
			$this->admin
		);
		SpaceMember::add( $space, $this->member, 'member' );
		$post = (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $this->admin,
				'title'     => 'Winning lantern sketches',
				'slug'      => 'lantern-' . uniqid(),
				'content'   => '<p>Sketches from the winning team.</p>',
				'status'    => 'publish',
			)
		);
		if ( $with_reply ) {
			Reply::create( array( 'post_id' => $post, 'author_id' => $this->admin, 'content' => 'Lovely work.' ) );
		}
		if ( 'active' !== $status ) {
			Space::update( $space, array( 'status' => $status ) );
		}
		return array( $space, $post );
	}

	private function render( int $post_id, int $viewer ): string {
		wp_set_current_user( $viewer );
		$data = array( 'slug' => Post::find( $post_id )->slug );
		ob_start();
		include JETONOMY_DIR . 'templates/views/single-post.php';
		return (string) ob_get_clean();
	}

	public function test_archived_space_topic_is_readable_but_invites_no_reply(): void {
		[ , $post ] = $this->topic( 'archived' );
		$html       = $this->render( $post, $this->member );

		$this->assertStringContainsString( 'Sketches from the winning team.', $html, 'the topic must stay readable' );
		$this->assertStringNotContainsString( 'Be the first to reply', $html );
		$this->assertStringNotContainsString( 'jt-reply-composer', $html );
	}

	public function test_archived_space_offers_no_reply_or_quote_on_existing_replies(): void {
		[ , $post ] = $this->topic( 'archived', true );
		$html       = $this->render( $post, $this->member );

		$this->assertStringContainsString( 'Lovely work.', $html );
		$this->assertStringNotContainsString( 'jt-reply-to-btn', $html );
	}

	public function test_guest_on_an_archived_space_is_not_asked_to_log_in_to_reply(): void {
		[ , $post ] = $this->topic( 'archived' );
		$html       = $this->render( $post, 0 );

		$this->assertStringContainsString( 'Sketches from the winning team.', $html );
		$this->assertStringNotContainsString( 'Log in to reply', $html );
	}

	/**
	 * REST already refused; pinned so the template and the server keep agreeing.
	 *
	 * @covers-existing
	 */
	public function test_rest_still_refuses_the_reply_and_stores_nothing(): void {
		[ , $post ] = $this->topic( 'archived' );
		wp_set_current_user( $this->member );
		$req = new \WP_REST_Request( 'POST', '/jetonomy/v1/posts/' . $post . '/replies' );
		$req->set_body_params( array( 'content' => 'Can I still add one?' ) );
		$res = rest_do_request( $req );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_space_restricted', $res->get_data()['code'] ?? '' );
		$this->assertSame( 0, Reply::count( array( 'post_id' => $post ) ) );
	}

	/**
	 * Negative case: a live space keeps its invitation.
	 *
	 * @covers-existing
	 */
	public function test_live_space_with_no_replies_still_invites_the_first_reply(): void {
		[ , $post ] = $this->topic( 'active' );
		$html       = $this->render( $post, $this->member );

		$this->assertStringContainsString( 'Be the first to reply!', $html );
		$this->assertStringContainsString( 'jt-reply-composer', $html );
	}

	/**
	 * Negative case: a live space keeps Reply on each reply.
	 *
	 * @covers-existing
	 */
	public function test_live_space_reply_card_keeps_reply_button(): void {
		[ , $post ] = $this->topic( 'active', true );
		$html       = $this->render( $post, $this->member );

		$this->assertStringContainsString( 'jt-reply-to-btn', $html );
	}
}
