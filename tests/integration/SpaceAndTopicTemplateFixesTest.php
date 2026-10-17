<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;

/**
 * Template fixes from the 2.0.3 lane-B re-walk: the pending join state on a
 * public approval space, one Members-tab rule, the ban message on a public
 * topic, and no OP badge between two deleted accounts.
 */
class SpaceAndTopicTemplateFixesTest extends WP_UnitTestCase {

	private int $admin;
	private int $member;
	private int $cat;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		Restriction::reset_memo();

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->cat    = (int) Category::create(
			array(
				'name' => 'Space and topic fixes',
				'slug' => 'stf-' . uniqid(),
			)
		);
	}

	private function space( string $join_policy = 'open', string $type = 'forum' ): object {
		$id = (int) Space::create(
			array(
				'title'       => 'Mentorship Circle',
				'slug'        => 'stf-' . uniqid(),
				'category_id' => $this->cat,
				'visibility'  => 'public',
				'join_policy' => $join_policy,
				'type'        => $type,
			),
			$this->admin
		);
		return Space::find( $id );
	}

	private function render_partial( string $name, array $args ): string {
		ob_start();
		\Jetonomy\Template_Loader::partial( $name, $args );
		return (string) ob_get_clean();
	}

	public function test_pending_request_on_a_public_approval_space_says_so_and_offers_withdraw(): void {
		$space = $this->space( 'approval' );
		JoinRequest::create_request( (int) $space->id, $this->member, 'I mentor new contributors.' );
		wp_set_current_user( $this->member );

		$html = $this->render_partial( 'space-header', array( 'space' => $space ) );

		$this->assertStringContainsString( 'Your request to join Mentorship Circle is awaiting approval.', $html );
		$this->assertMatchesRegularExpression( '/class="[^"]*jt-withdraw-request-btn[^"]*" data-space-id="' . (int) $space->id . '"/', $html, 'the same Withdraw the private gate offers' );
		$this->assertStringNotContainsString( 'jt-join-request-btn', $html );
	}

	/** @covers-existing */
	public function test_no_pending_request_still_offers_request_to_join(): void {
		$space = $this->space( 'approval' );
		wp_set_current_user( $this->member );

		$html = $this->render_partial( 'space-header', array( 'space' => $space ) );

		$this->assertStringContainsString( 'jt-join-request-btn', $html );
		$this->assertStringNotContainsString( 'awaiting approval', $html );
	}

	public function test_guest_on_a_public_space_gets_the_members_tab_like_the_members_page(): void {
		$space = $this->space();
		wp_set_current_user( 0 );

		$html = $this->render_partial(
			'space-tabs',
			array(
				'space'     => $space,
				'space_url' => \Jetonomy\route_url( 'space', $space->slug ),
			)
		);

		$this->assertStringContainsString( \Jetonomy\route_url( 'space-members', $space->slug ), $html );
	}

	public function test_space_banned_member_opening_a_public_topic_sees_the_ban_not_not_found(): void {
		$space = $this->space();
		$post  = (int) Post::create(
			array(
				'space_id'  => (int) $space->id,
				'author_id' => $this->admin,
				'title'     => 'Pairing sessions this week',
				'slug'      => 'pairing-' . uniqid(),
				'content'   => '<p>Who is free on Thursday?</p>',
				'status'    => 'publish',
			)
		);
		Restriction::ban( $this->member, 'space_ban', $this->admin, (int) $space->id, 'Repeated off-topic posts' );
		Restriction::reset_memo();
		wp_set_current_user( $this->member );

		$data = array( 'slug' => Post::find( $post )->slug );
		ob_start();
		include JETONOMY_DIR . 'templates/views/single-post.php';
		$html = (string) ob_get_clean();

		$expected = \Jetonomy\Permissions\Permission_Engine::restriction_error( $this->member, 'read', (int) $space->id )->get_error_message();
		$this->assertStringContainsString( esc_html( $expected ), $html );
		$this->assertStringNotContainsString( 'Who is free on Thursday?', $html, 'the topic itself stays closed' );
	}

	public function test_reply_by_a_deleted_account_is_not_op_when_the_topic_author_is_deleted_too(): void {
		$space = $this->space();
		$post  = Post::find(
			(int) Post::create(
				array(
					'space_id'  => (int) $space->id,
					'author_id' => $this->admin,
					'title'     => 'Old thread',
					'slug'      => 'old-' . uniqid(),
					'content'   => '<p>Kept after both accounts were deleted.</p>',
					'status'    => 'publish',
				)
			)
		);
		$reply = Reply::find(
			(int) Reply::create(
				array(
					'post_id'   => (int) $post->id,
					'author_id' => $this->member,
					'content'   => '<p>A reply from a deleted account.</p>',
					'status'    => 'publish',
				)
			)
		);
		// Both accounts deleted: the rows keep author 0.
		$post->author_id  = 0;
		$reply->author_id = 0;

		$html = $this->render_partial(
			'reply-card',
			array(
				'reply' => $reply,
				'post'  => $post,
				'space' => $space,
			)
		);

		$this->assertStringContainsString( 'A reply from a deleted account.', $html );
		$this->assertStringNotContainsString( 'jt-reply-op', $html );
	}
}
