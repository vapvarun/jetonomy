<?php
/**
 * Phase 5 of `wp jetonomy qa-actions`: the refusal half of each core journey.
 *
 * Phases 1-4 walk journeys that succeed. The 2.0.2 spaces audit found twelve
 * defects that all lived on the other path - an admission a filter refused, a
 * request someone else decided first, a banned member, an archived space, the
 * last use of an invite - and the suite passed identically before and after
 * every one was fixed, because nothing in it ever asked for a refusal.
 *
 * Each check here goes through REST as the real role and asserts two things:
 * the request is refused, and nothing changed (no roster row, no flag, the
 * request still pending, the invite still unused). Fixtures are throwaway
 * users and spaces, purged afterwards; qa-actions' footprint check fails the
 * run if anything is left behind.
 *
 * @package Jetonomy
 */

namespace Jetonomy\QA;

use Jetonomy\Models\Category;
use Jetonomy\Models\Flag;
use Jetonomy\Models\InviteLink;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Post;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

defined( 'ABSPATH' ) || exit;

class Refusal_Tests {

	private int $pass = 0;

	private int $fail = 0;

	/** @var array<int,array{type:string,id:int}> LIFO cleanup. */
	private array $cleanup = array();

	private int $owner = 0;

	private int $member = 0;

	private int $outsider = 0;

	private int $moderator = 0;

	private int $category = 0;

	public function run(): array {
		$previous_user = get_current_user_id();
		try {
			$this->owner     = $this->user( 'owner' );
			$this->member    = $this->user( 'member' );
			$this->outsider  = $this->user( 'outsider' );
			$this->moderator = $this->user( 'moderator', 'editor' );
			get_user_by( 'id', $this->moderator )->add_cap( 'jetonomy_moderate' );

			$this->category = (int) Category::create(
				array(
					'name' => 'QA refusals',
					'slug' => 'jt-qa-refusals-' . uniqid(),
				)
			);
			$this->cleanup[] = array(
				'type' => 'category',
				'id'   => $this->category,
			);

			$this->test_join_requests();
			$this->test_closed_doors();
			$this->test_invites();
			$this->test_wrong_role();
			$this->test_unreadable_content();
		} catch ( \Throwable $e ) {
			$this->record( 'Refusal_Tests uncaught', false, $e->getMessage() );
		} finally {
			wp_set_current_user( $previous_user );
			$this->cleanup();
		}

		return array(
			'pass'    => $this->pass,
			'fail'    => $this->fail,
			'skipped' => 0,
		);
	}

	private function test_join_requests(): void {
		$space = $this->space( 'approval', 'private' );

		// RF1: a refused admission leaves the request pending and unannounced.
		SpaceMember::join( $space, $this->member );
		$jr       = JoinRequest::find_pending( $space, $this->member );
		$told     = 0;
		$listener = static function () use ( &$told ) {
			++$told;
		};
		$veto     = static fn() => new \WP_Error( 'jt_qa_veto', 'QA veto' );
		add_action( 'jetonomy_join_request_approved', $listener );
		add_filter( 'jetonomy_before_join_space', $veto );
		$res = $this->rest( $this->owner, 'POST', "/spaces/{$space}/join-requests/{$jr->id}/approve" );
		remove_filter( 'jetonomy_before_join_space', $veto );
		remove_action( 'jetonomy_join_request_approved', $listener );
		$this->record(
			'RF1: a refused admission leaves the request pending, nobody told "approved"',
			$res->get_status() >= 400 && 'pending' === JoinRequest::find( (int) $jr->id )->status && 0 === $told && null === SpaceMember::get_role( $space, $this->member ),
			'status ' . $res->get_status()
		);

		// RF2: a decided request is not decided again.
		$this->rest( $this->owner, 'POST', "/spaces/{$space}/join-requests/{$jr->id}/deny" );
		$res = $this->rest( $this->owner, 'POST', "/spaces/{$space}/join-requests/{$jr->id}/approve" );
		$this->record(
			'RF2: approving a denied request is refused and admits nobody',
			in_array( $res->get_status(), array( 404, 409 ), true ) && 'denied' === JoinRequest::find( (int) $jr->id )->status && null === SpaceMember::get_role( $space, $this->member ),
			'status ' . $res->get_status()
		);

		// RF3: banned from the space after asking.
		SpaceMember::join( $space, $this->outsider );
		$jr2 = JoinRequest::find_pending( $space, $this->outsider );
		Restriction::ban( $this->outsider, 'space_ban', $this->owner, $space );
		\Jetonomy\Cache::flush();
		$res = $this->rest( $this->owner, 'POST', "/spaces/{$space}/join-requests/{$jr2->id}/approve" );
		$this->record(
			'RF3: a member banned after asking is not admitted',
			403 === $res->get_status() && null === SpaceMember::get_role( $space, $this->outsider ),
			'status ' . $res->get_status()
		);
	}

	private function test_closed_doors(): void {
		// RF4: archived and locked spaces take no new members.
		foreach ( array( 'archived', 'locked' ) as $status ) {
			$space = $this->space( 'open', 'public', $status );
			$res   = $this->rest( $this->outsider, 'POST', "/spaces/{$space}/members" );
			$this->record(
				"RF4: joining an {$status} space is refused",
				403 === $res->get_status() && null === SpaceMember::get_role( $space, $this->outsider ),
				'status ' . $res->get_status()
			);
		}

		// RF5: a space ban keeps the member out.
		$space = $this->space( 'open', 'public' );
		Restriction::ban( $this->member, 'space_ban', $this->owner, $space );
		\Jetonomy\Cache::flush();
		$res = $this->rest( $this->member, 'POST', "/spaces/{$space}/members" );
		$this->record(
			'RF5: a space-banned member cannot join',
			403 === $res->get_status() && null === SpaceMember::get_role( $space, $this->member ),
			'status ' . $res->get_status()
		);
	}

	private function test_invites(): void {
		$space = $this->space( 'invite', 'private' );
		$token = InviteLink::generate( $space, $this->owner, 1 );

		// RF6: accepting needs a logged-in member, and changes nothing otherwise.
		$res = $this->rest( 0, 'POST', "/invite/{$token}" );
		$this->record(
			'RF6: a logged-out invite accept is refused and uses nothing',
			401 === $res->get_status() && 0 === (int) InviteLink::find_by_token( $token )->use_count,
			'status ' . $res->get_status()
		);

		// RF7: the last use goes to one person.
		$first  = $this->rest( $this->member, 'POST', "/invite/{$token}" );
		$second = $this->rest( $this->outsider, 'POST', "/invite/{$token}" );
		$this->record(
			'RF7: a single-use invite admits one person',
			200 === $first->get_status() && 410 === $second->get_status() && null === SpaceMember::get_role( $space, $this->outsider ) && 1 === (int) InviteLink::find_by_token( $token )->use_count,
			'statuses ' . $first->get_status() . '/' . $second->get_status()
		);
	}

	private function test_wrong_role(): void {
		$space = $this->space( 'open', 'public' );
		SpaceMember::add( $space, $this->member, 'member' );

		// RF8a: a plain member cannot edit the space.
		$res = $this->rest( $this->member, 'PATCH', "/spaces/{$space}", array( 'title' => 'Taken over' ) );
		$this->record(
			'RF8a: a member cannot edit a space',
			403 === $res->get_status() && 'Taken over' !== Space::find( $space )->title,
			'status ' . $res->get_status()
		);

		// RF8b: a space admin cannot edit an archived space.
		$archived = $this->space( 'open', 'public', 'archived' );
		SpaceMember::add( $archived, $this->member, 'admin' );
		$res = $this->rest( $this->member, 'PATCH', "/spaces/{$archived}", array( 'title' => 'Renamed' ) );
		$this->record(
			'RF8b: a space admin cannot edit an archived space',
			409 === $res->get_status() && 'Renamed' !== Space::find( $archived )->title,
			'status ' . $res->get_status()
		);

		// RF8c: an access rule that would admit everyone is refused.
		$res = $this->rest(
			1,
			'POST',
			"/spaces/{$space}/access-rules",
			array(
				'rule_type'  => 'trust_level',
				'rule_value' => '',
			)
		);
		$this->record( 'RF8c: a trust-level rule without a level is refused', 400 === $res->get_status(), 'status ' . $res->get_status() );
	}

	private function test_unreadable_content(): void {
		$space = $this->space( 'invite', 'private' );
		SpaceMember::add( $space, $this->member, 'member' );
		$post            = (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $this->member,
				'title'     => 'QA refusal: private topic',
				'content'   => 'Members only.',
				'status'    => 'publish',
			)
		);
		$this->cleanup[] = array(
			'type' => 'post',
			'id'   => $post,
		);

		// RF9: no flagging what you cannot read.
		$before = Flag::count_pending();
		$res    = $this->rest(
			$this->outsider,
			'POST',
			'/flags',
			array(
				'object_type' => 'post',
				'object_id'   => $post,
				'reason'      => 'spam',
			)
		);
		Flag::reset_memo();
		$this->record(
			'RF9: an outsider cannot flag a private topic',
			404 === $res->get_status() && Flag::count_pending() === $before,
			'status ' . $res->get_status()
		);

		// RF10: a moderator outside a private space cannot read its flags.
		$res = $this->rest( $this->moderator, 'GET', "/posts/{$post}/flags" );
		$this->record( 'RF10: a moderator outside a private space cannot read its flags', in_array( $res->get_status(), array( 403, 404 ), true ), 'status ' . $res->get_status() );
	}

	// -- Helpers -------------------------------------------------------------

	private function rest( int $as, string $method, string $route, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $as );
		$req = new \WP_REST_Request( $method, '/jetonomy/v1' . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_do_request( $req );
	}

	private function user( string $slot, string $role = 'subscriber' ): int {
		$suffix = uniqid( '', false );
		$uid    = wp_insert_user(
			array(
				'user_login' => 'jt_qa_rf_' . $slot . '_' . $suffix,
				'user_pass'  => wp_generate_password(),
				'user_email' => 'rf-' . $slot . '-' . $suffix . '@example.test',
				'role'       => $role,
			)
		);
		if ( is_wp_error( $uid ) ) {
			throw new \RuntimeException( 'could not create the ' . $slot . ' user: ' . $uid->get_error_message() );
		}
		$this->cleanup[] = array(
			'type' => 'user',
			'id'   => (int) $uid,
		);
		return (int) $uid;
	}

	private function space( string $join_policy, string $visibility, string $status = 'active' ): int {
		$id = (int) Space::create(
			array(
				'title'       => 'QA refusal space',
				'slug'        => 'jt-qa-rf-' . uniqid(),
				'category_id' => $this->category,
				'visibility'  => $visibility,
				'join_policy' => $join_policy,
				'status'      => $status,
				'type'        => 'forum',
			),
			$this->owner
		);
		$this->cleanup[] = array(
			'type' => 'space',
			'id'   => $id,
		);
		return $id;
	}

	private function cleanup(): void {
		foreach ( array_reverse( $this->cleanup ) as $fixture ) {
			try {
				switch ( $fixture['type'] ) {
					case 'post':
						Post::delete( $fixture['id'] );
						break;
					case 'space':
						\Jetonomy\Space_Purge::purge( $fixture['id'] );
						break;
					case 'category':
						Category::delete( $fixture['id'] );
						break;
					case 'user':
						// Space bans went with their spaces (purged above).
						wp_delete_user( $fixture['id'] );
						break;
				}
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( sprintf( 'Refusal cleanup failed for %s %d: %s', $fixture['type'], $fixture['id'], $e->getMessage() ) );
			}
		}
		\WP_CLI::log( sprintf( '  [cleanup] %d refusal fixture(s) reversed.', count( $this->cleanup ) ) );
	}

	private function record( string $label, bool $ok, string $detail = '' ): void {
		if ( $ok ) {
			++$this->pass;
			\WP_CLI::log( "    PASS  {$label}" );
		} else {
			++$this->fail;
			// Same shape as Model_Tests, so bin/check-guards-can-fail.php can
			// require an RF guard to go red when its bug is put back.
			\WP_CLI::log( "    FAIL  {$label}" . ( $detail ? " ({$detail})" : '' ) );
		}
	}
}
