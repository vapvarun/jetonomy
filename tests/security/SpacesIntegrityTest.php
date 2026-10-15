<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\InviteLink;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Import_Map;
use Jetonomy\Models\Post;
use Jetonomy\Models\Tag;
use Jetonomy\Models\Flag;
use Jetonomy\Space_Purge;

/**
 * Spaces & Categories data integrity (2.0.3 audit cards 10408498204,
 * 10408502612, 10408505663 and the 2.0.2 security record's deferred LOWs).
 *
 * Each test states the corrected behaviour, so each fails on the code the
 * audit read: membership, notifications, counters and listings must agree
 * with the database on the failure paths too, not only the happy path.
 */
class SpacesIntegrityTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $cat;
	private int $admin;
	private int $user;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->cat   = (int) Category::create(
			array(
				'name' => 'Integrity',
				'slug' => 'integrity-' . uniqid(),
			)
		);
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->user  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function space( array $over = array() ): int {
		return (int) Space::create(
			array_merge(
				array(
					'title'       => 'Space',
					'slug'        => 'space-' . uniqid(),
					'category_id' => $this->cat,
					'visibility'  => 'public',
					'join_policy' => 'open',
					'type'        => 'forum',
				),
				$over
			),
			$this->admin
		);
	}

	private function rows( string $table, string $where ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Jetonomy\table( $table ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function count_hook( string $hook ): \stdClass {
		$counter    = new \stdClass();
		$counter->n = 0;
		add_action(
			$hook,
			static function () use ( $counter ) {
				++$counter->n;
			}
		);
		return $counter;
	}

	private function rest( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$req = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $this->server->dispatch( $req );
	}

	// -- Join requests -------------------------------------------------------

	public function test_refused_admission_leaves_the_request_pending_and_unannounced(): void {
		$space = $this->space( array( 'join_policy' => 'approval' ) );
		SpaceMember::join( $space, $this->user );
		$jr       = JoinRequest::find_pending( $space, $this->user );
		$approved = $this->count_hook( 'jetonomy_join_request_approved' );
		add_filter( 'jetonomy_before_join_space', static fn() => new \WP_Error( 'veto', 'No.' ) );

		$this->assertWPError( JoinRequest::approve( (int) $jr->id, $this->admin ) );
		$this->assertSame( 'pending', JoinRequest::find( (int) $jr->id )->status, 'can be approved again later' );
		$this->assertSame( 0, $approved->n, 'nobody is told "approved"' );
		$this->assertNull( SpaceMember::get_role( $space, $this->user ) );
	}

	public function test_a_decided_request_is_not_decided_again(): void {
		$space = $this->space( array( 'join_policy' => 'approval' ) );
		SpaceMember::join( $space, $this->user );
		$jr = JoinRequest::find_pending( $space, $this->user );
		$this->assertTrue( JoinRequest::deny( (int) $jr->id, $this->admin ) );
		$approved = $this->count_hook( 'jetonomy_join_request_approved' );

		$again = JoinRequest::approve( (int) $jr->id, $this->admin );
		$this->assertWPError( $again );
		$this->assertSame( 409, $again->get_error_data()['status'] );
		$this->assertSame( 'denied', JoinRequest::find( (int) $jr->id )->status );
		$this->assertSame( 0, $approved->n );
		$this->assertNull( SpaceMember::get_role( $space, $this->user ), 'and nobody was admitted on the way' );
		$this->assertFalse( JoinRequest::deny( (int) $jr->id, $this->admin ), 'deny twice fires nothing' );
	}

	public function test_a_member_banned_after_asking_is_not_admitted(): void {
		$space = $this->space( array( 'join_policy' => 'approval' ) );
		SpaceMember::join( $space, $this->user );
		$jr = JoinRequest::find_pending( $space, $this->user );
		Restriction::ban( $this->user, 'space_ban', $this->admin, $space );
		\Jetonomy\Cache::flush();

		$this->assertSame( 'jetonomy_space_banned', JoinRequest::approve( (int) $jr->id, $this->admin )->get_error_code() );
		$this->assertSame( 1, JoinRequest::resolve_pending_for_space( $space, true, $this->admin ) );
		$this->assertSame( 'denied', JoinRequest::find( (int) $jr->id )->status, 'opening the space denies them' );
		$this->assertNull( SpaceMember::get_role( $space, $this->user ) );
	}

	public function test_a_deny_that_lost_the_race_says_so(): void {
		wp_set_current_user( $this->admin );
		$space = $this->space( array( 'join_policy' => 'approval' ) );
		SpaceMember::join( $space, $this->user );
		$jr = JoinRequest::find_pending( $space, $this->user );
		$this->assertTrue( JoinRequest::approve( (int) $jr->id, $this->admin ) );
		$this->assertFalse( JoinRequest::deny( (int) $jr->id, $this->admin ) );
	}

	public function test_a_refused_create_leaves_no_orphan_space(): void {
		wp_set_current_user( $this->admin );
		$slug = 'vetoed-' . uniqid();
		add_filter( 'jetonomy_before_join_space', static fn() => new \WP_Error( 'veto', 'No.' ) );
		$res = $this->rest(
			'POST',
			'/spaces',
			array(
				'title'       => 'Vetoed',
				'slug'        => $slug,
				'category_id' => $this->cat,
			)
		);
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 0, $this->rows( 'spaces', "slug = '{$slug}'" ), 'no admin-less space left behind' );
	}

	public function test_closing_requests_keeps_a_role_granted_meanwhile(): void {
		$space = $this->space( array( 'join_policy' => 'approval' ) );
		SpaceMember::join( $space, $this->user );
		SpaceMember::add( $space, $this->user, 'viewer' );

		$this->assertSame( 1, JoinRequest::resolve_pending_for_space( $space, true, $this->admin ) );
		$this->assertSame( 'viewer', SpaceMember::get_role( $space, $this->user ), 'not lifted to member' );
	}

	// -- Membership writes ---------------------------------------------------

	public function test_a_join_that_lost_the_insert_race_counts_once(): void {
		global $wpdb;
		$space  = $this->space();
		$before = (int) Space::find( $space )->member_count;
		$this->assertNull( SpaceMember::get_role( $space, $this->user ) );
		// The concurrent request's row lands between the check and the insert.
		$wpdb->insert(
			\Jetonomy\table( 'space_members' ),
			array(
				'space_id'  => $space,
				'user_id'   => $this->user,
				'role'      => 'member',
				'joined_at' => current_time( 'mysql', true ),
				'source'    => 'manual',
			)
		);
		$joined = $this->count_hook( 'jetonomy_user_joined_space' );

		$this->assertTrue( SpaceMember::add( $space, $this->user ) );
		\Jetonomy\Cache::flush();
		$this->assertSame( $before, (int) Space::find( $space )->member_count, 'the winner counted it' );
		$this->assertSame( 0, $joined->n, 'the winner announced it' );
	}

	public function test_archived_locked_and_banned_cannot_join_by_any_door(): void {
		$archived = $this->space( array( 'status' => 'archived' ) );
		$locked   = $this->space( array( 'status' => 'locked' ) );
		foreach ( array( $archived, $locked ) as $space ) {
			$this->assertSame( 'jetonomy_space_restricted', SpaceMember::join( $space, $this->user )->get_error_code() );
			$token = InviteLink::generate( $space, $this->admin );
			$this->assertSame( 'jetonomy_space_restricted', InviteLink::accept( $token, $this->user )->get_error_code() );
		}

		$open = $this->space();
		Restriction::ban( $this->user, 'space_ban', $this->admin, $open );
		\Jetonomy\Cache::flush();
		$this->assertSame( 'jetonomy_space_banned', SpaceMember::join( $open, $this->user )->get_error_code() );
		$this->assertSame( 'jetonomy_space_banned', InviteLink::accept( InviteLink::generate( $open, $this->admin ), $this->user )->get_error_code() );
		$this->assertSame( 0, $this->rows( 'space_members', "user_id = {$this->user}" ) );
	}

	// -- Invites -------------------------------------------------------------

	public function test_invite_expiry_is_the_end_of_the_chosen_day_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'America/New_York' );
		$this->assertSame( '2030-06-16 03:59:59', InviteLink::expiry_utc( '2030-06-15' ) );
		$this->assertNull( InviteLink::expiry_utc( '' ) );
		$this->assertWPError( InviteLink::expiry_utc( 'tomorrow-ish' ), 'unreadable is refused, not stored' );
		$this->assertWPError( InviteLink::expiry_utc( '2001-01-01' ), 'past is refused' );

		$today = wp_date( 'Y-m-d' );
		$token = InviteLink::generate( $this->space(), $this->admin, 0, InviteLink::expiry_utc( $today ) );
		$this->assertTrue( InviteLink::is_valid( InviteLink::find_by_token( $token ) ), 'a link set to expire today works today' );
	}

	public function test_the_last_invite_use_goes_to_one_person(): void {
		$token  = InviteLink::generate( $this->space(), $this->admin, 1 );
		$invite = InviteLink::find_by_token( $token );
		$this->assertTrue( InviteLink::use_invite( (int) $invite->id ) );
		$this->assertFalse( InviteLink::use_invite( (int) $invite->id ), 'no room left' );
	}

	public function test_opening_an_invite_link_does_not_join(): void {
		$space  = $this->space();
		$token  = InviteLink::generate( $space, $this->admin );
		$result = InviteLink::accept( $token, $this->user, false );

		$this->assertSame( 'ready', $result['status'] );
		$this->assertNull( SpaceMember::get_role( $space, $this->user ) );
		$this->assertSame( 0, (int) InviteLink::find_by_token( $token )->use_count );
		$this->assertSame( 'joined', InviteLink::accept( $token, $this->user )['status'] );
	}

	public function test_a_failed_insert_returns_no_token(): void {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$break = static fn( $q ) => str_contains( $q, 'jt_invite_links' ) && str_starts_with( ltrim( $q ), 'INSERT' ) ? 'INSERT INTO jt_missing_table VALUES (1)' : $q;
		add_filter( 'query', $break );
		$this->assertSame( '', InviteLink::generate( $this->space(), $this->admin ) );
		remove_filter( 'query', $break );
	}

	// -- Lists and counts ----------------------------------------------------

	public function test_list_routes_cannot_return_unbounded_pages(): void {
		wp_set_current_user( $this->admin );
		$space = $this->space( array( 'join_policy' => 'approval' ) );
		foreach ( array( '0', '-1', '5000' ) as $limit ) {
			$this->assertSame( 400, $this->rest( 'GET', "/spaces/{$space}/moderation/flags", array( 'limit' => $limit ) )->get_status(), "limit={$limit}" );
		}

		foreach ( array( 'a', 'b', 'c' ) as $n ) {
			SpaceMember::join( $space, self::factory()->user->create( array( 'user_login' => 'req-' . $n . uniqid() ) ) );
		}
		$res = $this->rest( 'GET', "/spaces/{$space}/join-requests", array( 'limit' => 2 ) );
		$this->assertCount( 2, $res->get_data()['data'] );
		$this->assertSame( 3, $res->get_data()['meta']['total'] );
	}

	public function test_counts_and_tag_lists_show_only_what_the_viewer_can_read(): void {
		$private = $this->space( array( 'visibility' => 'hidden', 'join_policy' => 'invite' ) );
		$this->space();
		$post = (int) Post::create(
			array(
				'space_id'  => $private,
				'author_id' => $this->admin,
				'title'     => 'Board minutes',
				'content'   => 'x',
				'status'    => 'publish',
			)
		);
		Tag::attach_to_post( $post, Tag::find_or_create( 'secret-acquisition' ) );

		wp_set_current_user( 0 );
		$tags = $this->rest( 'GET', '/tags', array( 'limit' => 100 ) )->get_data();
		$this->assertNotContains( 'secret-acquisition', wp_list_pluck( $tags['data'], 'slug' ), 'a private-only tag name never lists' );

		$cats = $this->rest( 'GET', '/categories' )->get_data()['data'];
		$mine = array_values( array_filter( $cats, fn( $c ) => (int) $c['id'] === $this->cat ) )[0];
		$this->assertSame( count( $mine['spaces'] ), $mine['space_count'], 'the count matches the list the guest sees' );
	}

	public function test_counters_follow_status_and_real_activity(): void {
		$before = (int) Category::find( $this->cat )->space_count;
		$this->space( array( 'status' => 'archived' ) );
		$this->assertSame( $before, (int) Category::find( $this->cat )->space_count, 'an archived space is not counted' );

		global $wpdb;
		$space = $this->space();
		$wpdb->update( \Jetonomy\table( 'spaces' ), array( 'last_activity_at' => '2020-01-01 00:00:00' ), array( 'id' => $space ) );
		Space::increment_post_count( $space, -1 );
		\Jetonomy\Cache::flush();
		$this->assertSame( '2020-01-01 00:00:00', Space::find( $space )->last_activity_at, 'removing a topic is not activity' );
	}

	// -- Admin and archive ---------------------------------------------------

	public function test_space_admins_cannot_edit_an_archived_space(): void {
		$space = $this->space( array( 'status' => 'archived' ) );
		SpaceMember::add( $space, $this->user, 'admin' );

		wp_set_current_user( $this->user );
		$this->assertSame( 409, $this->rest( 'PATCH', "/spaces/{$space}", array( 'title' => 'Renamed' ) )->get_status() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 200, $this->rest( 'PATCH', "/spaces/{$space}", array( 'title' => 'Renamed' ) )->get_status() );
	}

	public function test_an_access_rule_needs_a_value_that_can_work(): void {
		wp_set_current_user( $this->admin );
		$space = $this->space( array( 'visibility' => 'private', 'join_policy' => 'invite' ) );
		$cases = array(
			array( 'trust_level', '', 400 ),
			array( 'trust_level', '0', 400 ),
			array( 'role', 'no-such-role', 400 ),
			array( 'capability', '', 400 ),
			array( 'trust_level', '2', 201 ),
		);
		foreach ( $cases as [ $type, $value, $status ] ) {
			$res = $this->rest(
				'POST',
				"/spaces/{$space}/access-rules",
				array(
					'rule_type'  => $type,
					'rule_value' => $value,
				)
			);
			$this->assertSame( $status, $res->get_status(), "{$type}={$value}" );
		}
	}

	public function test_purge_removes_the_importer_map(): void {
		$space = $this->space();
		Import_Map::record( 'bbpress', 'space', 991, $space );
		Space_Purge::purge( $space );
		$this->assertSame( 0, $this->rows( 'import_map', "object_type = 'space' AND object_id = {$space}" ) );
		$this->assertSame( '', Space_Purge::last_error() );
	}
}
