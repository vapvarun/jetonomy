<?php
namespace Jetonomy\Tests\Integration\Api;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Flag;
use Jetonomy\Models\InviteLink;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Tag;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Reply;
use Jetonomy\Models\BlockedUser;

/**
 * Every write route and every route that decides membership, invites, access,
 * moderation, bans or trust, called through REST as the role allowed to use
 * it and as one that is not.
 *
 * These routes were "covered" before only in the sense that a model test
 * exercised the code behind them: bin/qa-runtime-coverage.php found that no
 * test ever sent a request to them, including the moderation queue and flag
 * lists where 2.0.2's staff-reach bug lived. The runtime gate now requires
 * each one to be reached.
 *
 * @covers-existing These pin access rules that already held; they are
 *                  coverage, not regression guards for one change.
 */
class MustCoverRoutesTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin;
	private int $mod;
	private int $member;
	private int $outsider;
	private int $public_space;
	private int $private_space;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		Flag::reset_memo();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->mod      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->member   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_user_by( 'id', $this->mod )->add_cap( 'jetonomy_moderate' );

		$cat                 = (int) Category::create(
			array(
				'name' => 'Routes',
				'slug' => 'routes-' . uniqid(),
			)
		);
		$this->public_space  = (int) Space::create(
			array(
				'title'       => 'Town square',
				'slug'        => 'square-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'join_policy' => 'open',
				'type'        => 'forum',
			),
			$this->admin
		);
		$this->private_space = (int) Space::create(
			array(
				'title'       => 'Board room',
				'slug'        => 'board-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'private',
				'join_policy' => 'invite',
				'type'        => 'forum',
			),
			$this->admin
		);
		SpaceMember::add( $this->public_space, $this->member, 'member' );
		SpaceMember::add( $this->private_space, $this->member, 'member' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function call( int $as, string $method, string $route, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $as );
		$req = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $this->server->dispatch( $req );
	}

	private function refused( \WP_REST_Response $res, string $why ): void {
		$this->assertContains( $res->get_status(), array( 401, 403, 404 ), $why . ' (got ' . $res->get_status() . ')' );
	}

	private function topic( int $space ): int {
		return (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $this->member,
				'title'     => 'Topic ' . uniqid(),
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);
	}

	public function test_moderation_lists_answer_staff_only_and_leave_out_unreadable_spaces(): void {
		$public  = $this->topic( $this->public_space );
		$private = $this->topic( $this->private_space );
		foreach ( array( $public, $private ) as $pid ) {
			Flag::create(
				array(
					'reporter_id' => $this->outsider,
					'object_type' => 'post',
					'object_id'   => $pid,
					'reason'      => 'spam',
				)
			);
		}

		foreach ( array( '/moderation/queue', '/moderation/flags', "/posts/{$public}/flags" ) as $route ) {
			$this->refused( $this->call( $this->member, 'GET', $route ), "a member cannot read {$route}" );
			$this->assertSame( 200, $this->call( $this->mod, 'GET', $route )->get_status(), "a moderator reads {$route}" );
		}

		$flags = $this->call( $this->mod, 'GET', '/moderation/flags', array( 'status' => 'pending' ) )->get_data();
		$ids   = array_map( static fn( $f ) => (int) ( (array) $f )['object_id'], (array) ( $flags['data'] ?? $flags ) );
		$this->assertContains( $public, $ids );
		$this->assertNotContains( $private, $ids, 'a moderator outside the private space does not see its flags' );
		$this->refused( $this->call( $this->mod, 'GET', "/posts/{$private}/flags" ), 'nor one private topic\'s flags' );
	}

	public function test_ban_list_and_ban_need_moderation_rights(): void {
		$this->refused( $this->call( $this->member, 'GET', '/moderation/ban' ), 'members cannot list bans' );
		$this->refused(
			$this->call(
				$this->member,
				'POST',
				'/moderation/ban',
				array(
					'user_id' => $this->outsider,
					'type'    => 'global_ban',
				)
			),
			'members cannot ban'
		);
		$this->assertFalse( Restriction::is_banned( $this->outsider ) );

		$res = $this->call(
			$this->admin,
			'POST',
			'/moderation/ban',
			array(
				'user_id'  => $this->outsider,
				'type'     => 'space_ban',
				'space_id' => $this->public_space,
				'reason'   => 'Spam',
			)
		);
		$this->assertContains( $res->get_status(), array( 200, 201 ) );
		\Jetonomy\Cache::flush();
		$this->assertTrue( Restriction::is_space_banned( $this->outsider, $this->public_space ) );
		$this->assertSame( 200, $this->call( $this->admin, 'GET', '/moderation/ban' )->get_status() );
	}

	public function test_member_list_of_a_private_space_is_for_its_members(): void {
		$this->refused( $this->call( $this->outsider, 'GET', "/spaces/{$this->private_space}/members" ), 'an outsider cannot list a private space' );
		$res = $this->call( $this->member, 'GET', "/spaces/{$this->private_space}/members" );
		$this->assertSame( 200, $res->get_status() );
		$this->assertContains( $this->member, array_map( static fn( $m ) => (int) ( (array) $m )['user_id'], (array) $res->get_data()['data'] ) );
	}

	public function test_invite_links_are_managed_by_space_admins_and_accepted_by_post(): void {
		$token  = InviteLink::generate( $this->private_space, $this->admin, 1 );
		$invite = InviteLink::find_by_token( $token );

		$this->refused( $this->call( $this->member, 'GET', "/spaces/{$this->private_space}/invites" ), 'a plain member cannot list invite tokens' );
		$this->assertSame( 200, $this->call( $this->admin, 'GET', "/spaces/{$this->private_space}/invites" )->get_status() );

		$this->refused( $this->call( 0, 'POST', "/invite/{$token}" ), 'accepting needs a logged-in member' );
		$res = $this->call( $this->outsider, 'POST', "/invite/{$token}" );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'joined', $res->get_data()['status'] );
		$this->assertSame( 'member', SpaceMember::get_role( $this->private_space, $this->outsider ) );

		$this->refused( $this->call( $this->member, 'DELETE', "/spaces/{$this->private_space}/invites/{$invite->id}" ), 'a plain member cannot revoke' );
		$this->assertSame( 200, $this->call( $this->admin, 'DELETE', "/spaces/{$this->private_space}/invites/{$invite->id}" )->get_status() );
		$this->assertNull( InviteLink::find_by_token( $token ) );
	}

	public function test_access_rules_are_managed_with_the_manage_spaces_capability(): void {
		$route = "/spaces/{$this->private_space}/access-rules";
		$this->refused( $this->call( $this->member, 'GET', $route ), 'members cannot read access rules' );
		$this->refused(
			$this->call(
				$this->member,
				'POST',
				$route,
				array(
					'rule_type'  => 'logged_in',
					'rule_value' => '',
				)
			),
			'members cannot add one'
		);

		$made = $this->call(
			$this->admin,
			'POST',
			$route,
			array(
				'rule_type'  => 'trust_level',
				'rule_value' => '3',
			)
		);
		$this->assertSame( 201, $made->get_status() );
		$rule_id = (int) ( (array) $made->get_data()['data'] )['id'];
		$this->assertSame( 200, $this->call( $this->admin, 'GET', $route )->get_status() );

		$this->refused( $this->call( $this->member, 'DELETE', "/access-rules/{$rule_id}" ), 'members cannot delete one' );
		$this->assertSame( 200, $this->call( $this->admin, 'DELETE', "/access-rules/{$rule_id}" )->get_status() );
		$this->assertNull( AccessRule::find( $rule_id ) );
	}

	public function test_notifications_are_changed_only_by_their_owner(): void {
		$make = fn() => Notification::create(
			array(
				'user_id'     => $this->member,
				'actor_id'    => $this->outsider,
				'type'        => 'mention',
				'object_type' => 'post',
				'object_id'   => 1,
				'message'     => 'Mentioned you',
			)
		);
		$one  = $make();
		$two  = $make();

		// DELETE is idempotent here: the model deletes WHERE user_id = caller,
		// so someone else's request answers 200 having removed nothing.
		$theirs = $this->call( $this->outsider, 'DELETE', "/notifications/{$one}" );
		$this->assertSame( 0, $theirs->get_data()['deleted'], 'someone else cannot delete it' );
		$this->assertNotNull( Notification::find( $one ) );

		$bulk = $this->call(
			$this->member,
			'POST',
			'/notifications/bulk',
			array(
				'action' => 'mark_read',
				'ids'    => array( $one, $two ),
			)
		);
		$this->assertSame( 200, $bulk->get_status() );
		$this->assertSame( 1, (int) Notification::find( $two )->is_read );

		$this->assertSame( 200, $this->call( $this->member, 'DELETE', "/notifications/{$one}" )->get_status() );
		$this->assertNull( Notification::find( $one ) );
	}

	public function test_tags_are_managed_with_the_manage_settings_capability(): void {
		$this->refused( $this->call( $this->member, 'POST', '/tags', array( 'name' => 'Nope' ) ), 'members cannot create tags' );

		$made = $this->call( $this->admin, 'POST', '/tags', array( 'name' => 'Release notes' ) );
		$this->assertContains( $made->get_status(), array( 200, 201 ) );
		$id = (int) ( (array) ( $made->get_data()['data'] ?? $made->get_data() ) )['id'];

		$this->refused( $this->call( $this->member, 'PATCH', "/tags/{$id}", array( 'name' => 'Hijacked' ) ), 'members cannot rename' );
		$this->assertSame( 200, $this->call( $this->admin, 'PATCH', "/tags/{$id}", array( 'name' => 'Changelog' ) )->get_status() );
		$this->assertSame( 'Changelog', Tag::find( $id )->name );

		$this->refused( $this->call( $this->member, 'DELETE', "/tags/{$id}" ), 'members cannot delete' );
		$this->assertSame( 200, $this->call( $this->admin, 'DELETE', "/tags/{$id}" )->get_status() );
		$this->assertNull( Tag::find( $id ) );
	}

	public function test_demo_data_removal_is_for_site_admins(): void {
		$this->assertSame( 403, $this->call( $this->mod, 'DELETE', '/admin/demo-data' )->get_status(), 'staff without manage_options cannot remove demo data' );
		// An admin is let through; with no demo set installed the answer is 404 by design.
		$res = $this->call( $this->admin, 'DELETE', '/admin/demo-data' );
		$this->assertSame( 'jetonomy_no_demo_data', $res->get_data()['code'] ?? '' );
		$this->assertNotNull( Space::find( $this->public_space ), 'real spaces are untouched' );
	}

	public function test_resend_verification_does_not_reveal_who_has_an_account(): void {
		$known   = $this->call( 0, 'POST', '/auth/resend-verification', array( 'user_login' => get_userdata( $this->member )->user_login ) );
		$unknown = $this->call( 0, 'POST', '/auth/resend-verification', array( 'user_login' => 'no-such-person-' . uniqid() ) );
		$this->assertSame( $known->get_status(), $unknown->get_status() );
		$this->assertSame( $known->get_data(), $unknown->get_data(), 'the same answer for an account that exists and one that does not' );
	}

	public function test_join_requests_are_decided_by_space_staff_over_rest(): void {
		$space = (int) Space::create(
			array(
				'title'       => 'Approval club',
				'slug'        => 'approval-' . uniqid(),
				'visibility'  => 'private',
				'join_policy' => 'approval',
				'type'        => 'forum',
			),
			$this->admin
		);
		SpaceMember::add( $space, $this->member, 'member' );
		SpaceMember::join( $space, $this->outsider );
		$jr    = JoinRequest::find_pending( $space, $this->outsider );
		$route = "/spaces/{$space}/join-requests/{$jr->id}";

		$this->refused( $this->call( $this->member, 'POST', "{$route}/approve" ), 'a plain member cannot approve' );
		$this->assertSame( 200, $this->call( $this->admin, 'POST', "{$route}/approve" )->get_status() );
		$this->assertSame( 'member', SpaceMember::get_role( $space, $this->outsider ) );
		$this->assertContains( $this->call( $this->admin, 'POST', "{$route}/deny" )->get_status(), array( 404, 409 ), 'a decided request is not decided again' );
	}

	public function test_only_the_asker_or_staff_can_unaccept_an_answer(): void {
		$qa    = (int) Space::create(
			array(
				'title'      => 'Help desk',
				'slug'       => 'help-' . uniqid(),
				'visibility' => 'public',
				'type'       => 'qa',
			),
			$this->admin
		);
		SpaceMember::add( $qa, $this->member, 'member' );
		SpaceMember::add( $qa, $this->outsider, 'member' );
		$post  = $this->topic( $qa );
		$reply = (int) Reply::create(
			array(
				'post_id'   => $post,
				'author_id' => $this->outsider,
				'content'   => 'Try clearing the cache.',
				'status'    => 'publish',
			)
		);
		$this->assertSame( 200, $this->call( $this->member, 'POST', "/replies/{$reply}/accept" )->get_status() );

		$this->refused( $this->call( $this->outsider, 'DELETE', "/replies/{$reply}/accept" ), 'the answerer cannot unaccept' );
		$this->assertSame( 200, $this->call( $this->member, 'DELETE', "/replies/{$reply}/accept" )->get_status() );
	}

	public function test_members_manage_their_own_blocks(): void {
		$this->refused( $this->call( 0, 'GET', '/users/me/blocks' ), 'guests have no block list' );
		$this->assertContains( $this->call( $this->member, 'POST', '/users/me/blocks', array( 'user_id' => $this->outsider ) )->get_status(), array( 200, 201 ) );
		$list = $this->call( $this->member, 'GET', '/users/me/blocks' );
		$this->assertSame( 200, $list->get_status() );
		$this->assertStringContainsString( (string) $this->outsider, wp_json_encode( $list->get_data() ) );
		$this->assertSame( 200, $this->call( $this->member, 'DELETE', "/users/me/blocks/{$this->outsider}" )->get_status() );
		$this->assertStringNotContainsString( '"' . $this->outsider . '"', wp_json_encode( $this->call( $this->member, 'GET', '/users/me/blocks' )->get_data()['data'] ?? array() ) );
	}

	public function test_privileged_members_of_a_hidden_space_stay_hidden(): void {
		$hidden = (int) Space::create(
			array(
				'title'       => 'Skunkworks',
				'slug'        => 'skunk-' . uniqid(),
				'visibility'  => 'hidden',
				'join_policy' => 'invite',
				'type'        => 'forum',
			),
			$this->admin
		);
		$this->assertSame( 404, $this->call( $this->outsider, 'GET', "/spaces/{$hidden}/privileged-members" )->get_status() );
		$this->assertSame( 200, $this->call( 0, 'GET', "/spaces/{$this->private_space}/privileged-members" )->get_status(), 'a private space still says who to ask' );
	}

	public function test_own_account_and_account_deletion(): void {
		$this->assertSame( 200, $this->call( $this->member, 'GET', '/users/me' )->get_status() );
		// Valid parameters, so the permission check (not param validation) answers.
		$this->refused(
			$this->call(
				0,
				'DELETE',
				'/users/me',
				array(
					'confirm'  => 'DELETE',
					'password' => 'password',
				)
			),
			'a guest has no account to delete'
		);
		$admin = $this->call(
			$this->admin,
			'DELETE',
			'/users/me',
			array(
				'confirm'  => 'DELETE',
				'password' => 'password',
			)
		);
		$this->assertSame( 'jetonomy_admin_must_use_wp_admin', $admin->get_data()['code'] ?? '', 'admins use wp-admin' );
		$this->assertSame( 400, $this->call( $this->outsider, 'DELETE', '/users/me', array( 'confirm' => 'yes' ) )->get_status(), 'typing DELETE is required' );
		$wrong = $this->call(
			$this->outsider,
			'DELETE',
			'/users/me',
			array(
				'confirm'  => 'DELETE',
				'password' => 'not-it',
			)
		);
		$this->assertContains( $wrong->get_status(), array( 400, 401, 403 ), 'and the right password' );
		$this->assertNotFalse( get_userdata( $this->outsider ) );

		$gone = $this->call(
			$this->outsider,
			'DELETE',
			'/users/me',
			array(
				'confirm'  => 'DELETE',
				'password' => 'password',
			)
		);
		$this->assertSame( 200, $gone->get_status() );
		$this->assertFalse( get_userdata( $this->outsider ) );
	}

	public function test_demo_import_is_for_site_admins(): void {
		$this->assertSame( 403, $this->call( $this->mod, 'POST', '/admin/demo-data' )->get_status() );
		$this->assertSame( 201, $this->call( $this->admin, 'POST', '/admin/demo-data' )->get_status() );
		$this->assertSame( 200, $this->call( $this->admin, 'DELETE', '/admin/demo-data' )->get_status(), 'and removable again' );
	}

}
