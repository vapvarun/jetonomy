<?php
namespace Jetonomy\Tests\Integration;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use Jetonomy\Admin\Admin;
use Jetonomy\DB\Schema;
use Jetonomy\Models\InviteLink;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Space;
use Jetonomy\Permissions\Capabilities;

/**
 * REGRESSION (Basecamp 10415690207): the front-end invite links list and the
 * admin join requests tab printed every row with no pager, and anything past
 * the model's default limit of 100 was dropped without a word.
 */
class InviteAndJoinRequestPagingTest extends WP_Ajax_UnitTestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		Schema::create_tables();
		\Jetonomy\Cache::flush();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account     = new \WP_User( $this->admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		wp_set_current_user( $this->admin );
		new \Jetonomy\Admin\Ajax\Spaces_Handler();
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function space( int $links, int $requests ): object {
		$id = (int) Space::create(
			array(
				'title'       => 'Busy private space',
				'slug'        => 'busy-' . uniqid(),
				'visibility'  => 'private',
				'join_policy' => 'approval',
			),
			$this->admin
		);
		for ( $i = 0; $i < $links; $i++ ) {
			InviteLink::generate( $id, $this->admin );
		}
		for ( $i = 0; $i < $requests; $i++ ) {
			JoinRequest::create_request( $id, self::factory()->user->create(), 'Please let me in ' . $i );
		}
		return Space::find( $id );
	}

	private function members_page( object $space, array $get = array() ): string {
		$_GET = $get;
		$data = array( 'slug' => $space->slug );
		ob_start();
		include JETONOMY_DIR . 'templates/views/space-members.php';
		return (string) ob_get_clean();
	}

	private function admin_join_tab( object $space, array $get = array() ): string {
		$_GET = array_merge(
			array(
				'page'     => 'jetonomy-spaces',
				'action'   => 'edit',
				'space_id' => (int) $space->id,
				'tab'      => 'join_requests',
			),
			$get
		);
		ob_start();
		( new Admin() )->render_spaces();
		return (string) ob_get_clean();
	}

	public function test_front_end_invite_links_page_with_a_total(): void {
		$space = $this->space( 60, 0 );

		$first = $this->members_page( $space );
		$this->assertSame( 20, substr_count( $first, 'data-jt-invite-row' ), 'every invite link was printed on one page' );
		$this->assertStringContainsString( '<span class="jt-badge-accent">60</span>', $first, 'the total is missing' );
		$this->assertStringContainsString( 'Page 1 of 3', $first );

		$last = $this->members_page( $space, array( 'ipg' => '3' ) );
		$this->assertSame( 20, substr_count( $last, 'data-jt-invite-row' ) );
		$this->assertStringContainsString( 'Page 3 of 3', $last );
	}

	public function test_admin_join_requests_page_and_keep_the_total(): void {
		$space = $this->space( 0, 40 );

		$html = $this->admin_join_tab( $space );
		$this->assertSame( 20, substr_count( $html, 'jetonomy-approve-join-request' ), 'every request was painted on one page' );
		$this->assertStringContainsString( 'Pending Join Requests (40)', $html );
		$this->assertStringContainsString( 'jrpg=2', $html, 'no link to the next page' );

		$second = $this->admin_join_tab( $space, array( 'jrpg' => '2' ) );
		$this->assertSame( 20, substr_count( $second, 'jetonomy-approve-join-request' ) );
	}

	public function test_admin_invite_list_pages_instead_of_dropping_past_100(): void {
		$space = $this->space( 101, 0 );

		$_POST = array(
			'nonce'    => wp_create_nonce( 'jetonomy_admin' ),
			'space_id' => (int) $space->id,
			'page'     => 3,
		);
		try {
			$this->_handleAjax( 'jetonomy_list_invites' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the JSON response is asserted.
		}
		$res = json_decode( $this->_last_response, true );

		$this->assertSame( 101, $res['data']['total'] ?? null );
		$this->assertCount( 1, $res['data']['invites'] ?? array(), 'the 101st link is unreachable' );
		$this->assertFalse( $res['data']['has_more'] ?? true );
	}

	/**
	 * Negative case: a small space still shows the form, its links and the
	 * empty join-requests copy, with no pager.
	 *
	 * @covers-existing
	 */
	public function test_small_space_keeps_form_links_and_empty_copy(): void {
		$space = $this->space( 2, 0 );

		$front = $this->members_page( $space );
		$this->assertStringContainsString( 'Generate invite link', $front );
		$this->assertSame( 2, substr_count( $front, 'data-jt-invite-row' ) );
		$this->assertStringNotContainsString( 'Invite links pagination', $front );

		$admin = $this->admin_join_tab( $space );
		$this->assertStringContainsString( 'No pending join requests', $admin );
		$this->assertStringNotContainsString( 'jrpg=', $admin );
	}
}
