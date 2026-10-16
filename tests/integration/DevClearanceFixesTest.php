<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\JoinRequest;

/**
 * Fixes from the 2.0.2 developer-clearance pass: membership is never granted
 * by a request that ended denied or withdrawn, a linked-group join cannot
 * enter an archived or locked space, and a failed hand-over leaves no heir
 * holding admin power.
 */
class DevClearanceFixesTest extends WP_UnitTestCase {

	private int $admin;
	private int $member;
	private int $cat;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->cat    = (int) Category::create(
			array(
				'name' => 'Clearance',
				'slug' => 'clearance-' . uniqid(),
			)
		);
	}

	private function space( array $over = array() ): int {
		return (int) Space::create(
			array_merge(
				array(
					'title'       => 'Clearance space',
					'slug'        => 'dc-' . uniqid(),
					'category_id' => $this->cat,
					'visibility'  => 'private',
					'join_policy' => 'approval',
					'author_id'   => $this->admin,
				),
				$over
			)
		);
	}

	/**
	 * Run $during inside approve(), between the claim and the admission, the
	 * window the race lands in.
	 */
	private function approve_with_interference( int $request_id, callable $during ) {
		$hook = static function ( $proceed ) use ( $during, &$hook ) {
			remove_filter( 'jetonomy_before_join_space', $hook );
			$during();
			return $proceed;
		};
		add_filter( 'jetonomy_before_join_space', $hook );
		return JoinRequest::approve( $request_id, $this->admin );
	}

	public function test_deny_racing_an_approval_never_leaves_a_denied_member(): void {
		$space = $this->space();
		$id    = JoinRequest::create_request( $space, $this->member );

		$this->approve_with_interference(
			$id,
			function () use ( $id ) {
				JoinRequest::deny( $id, $this->admin );
			}
		);

		$status = (string) JoinRequest::find( $id )->status;
		$this->assertSame(
			'approved' === $status,
			SpaceMember::is_member( $space, $this->member ),
			"Request ended '{$status}' but membership disagrees."
		);
	}

	public function test_withdraw_racing_an_approval_never_leaves_a_member_without_a_request(): void {
		$space = $this->space();
		$id    = JoinRequest::create_request( $space, $this->member );

		$this->approve_with_interference(
			$id,
			function () use ( $space ) {
				JoinRequest::withdraw( $space, $this->member );
			}
		);

		$row = JoinRequest::find( $id );
		$this->assertSame(
			$row && 'approved' === (string) $row->status,
			SpaceMember::is_member( $space, $this->member ),
			'A withdrawn request still granted membership.'
		);
	}

	public function test_refused_admission_leaves_the_request_pending(): void {
		$space = $this->space();
		$id    = JoinRequest::create_request( $space, $this->member );
		Space::update( $space, array( 'status' => 'locked' ) );

		$this->assertWPError( JoinRequest::approve( $id, $this->admin ) );
		$this->assertSame( 'pending', (string) JoinRequest::find( $id )->status );
		$this->assertFalse( SpaceMember::is_member( $space, $this->member ) );
	}

	public function test_linked_group_join_cannot_enter_an_archived_or_locked_space(): void {
		foreach ( array( 'archived', 'locked' ) as $status ) {
			$space = $this->space( array( 'visibility' => 'public', 'join_policy' => 'open' ) );
			Space::update( $space, array( 'status' => $status ) );

			$this->assertWPError( SpaceMember::add_unless_blocked( $space, $this->member ), $status );
			$this->assertFalse( SpaceMember::is_member( $space, $this->member ), $status );
		}

		$open = $this->space( array( 'visibility' => 'public', 'join_policy' => 'open' ) );
		$this->assertTrue( SpaceMember::add_unless_blocked( $open, $this->member ) );
	}

	/**
	 * Make the next UPDATE on jt_spaces whose SQL contains $needle fail.
	 */
	private function fail_space_update( string $needle ): void {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$table = $wpdb->prefix . 'jt_spaces';
		$hook  = static function ( $sql ) use ( $table, $needle, &$hook ) {
			if ( str_starts_with( ltrim( $sql ), "UPDATE `{$table}`" ) && str_contains( $sql, $needle ) ) {
				remove_filter( 'query', $hook );
				return 'UPDATE jt_no_such_table SET x = 1';
			}
			return $sql;
		};
		add_filter( 'query', $hook );
	}

	public function test_failed_ownership_write_takes_back_the_heir_promotion(): void {
		$space = $this->space();
		SpaceMember::add( $space, $this->member, 'member' );
		$this->fail_space_update( 'author_id' );

		$this->assertWPError( Space::hand_over( $space, $this->member ) );
		$this->assertSame( 'member', SpaceMember::get_role( $space, $this->member ) );
		$this->assertSame( $this->admin, (int) Space::find( $space )->author_id );
	}

	public function test_failed_archive_marker_undoes_the_hand_over(): void {
		$space = $this->space();
		$this->fail_space_update( Space::HANDOVER_ARCHIVE );

		$this->assertWPError( Space::hand_over( $space, $this->member, true ) );
		$after = Space::find( $space );
		$this->assertSame( $this->admin, (int) $after->author_id );
		$this->assertSame( 'active', (string) $after->status );
		$this->assertNull( SpaceMember::get_role( $space, $this->member ) );
	}
}
