<?php
namespace Jetonomy\Tests\Unit\Models;

use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use WP_UnitTestCase;

/**
 * Deleting a space owner's account: who inherits, and whether the space is parked.
 *
 * A space is only stranded when no space admin survives. A site administrator
 * who is also a space admin is a survivor like any other (Basecamp 10344393613).
 */
class SpaceOwnerDeletionTest extends WP_UnitTestCase {

	private function space_owned_by( int $owner, string $slug ): int {
		return (int) Space::create(
			[
				'title'     => 'Space ' . $slug,
				'slug'      => $slug,
				'type'      => 'forum',
				'author_id' => $owner,
			],
			$owner
		);
	}

	/** Read straight from the table so no model cache can mask the write. */
	private function row( int $space_id ): object {
		global $wpdb;
		$table = \Jetonomy\table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( "SELECT author_id, status FROM {$table} WHERE id = %d", $space_id ) );
	}

	public function test_site_admin_who_is_space_admin_inherits_and_space_stays_active(): void {
		// The co-admin must be the very user the fallback would pick, or this
		// test cannot tell "inherited as survivor" from "fell back".
		$site_admin = Space::fallback_owner( 0 );
		$this->assertGreaterThan( 0, $site_admin );
		$owner = self::factory()->user->create();
		$space = $this->space_owned_by( $owner, 'site-admin-heir' );
		SpaceMember::add( $space, $site_admin, 'admin' );

		self::delete_user( $owner ); // Core helper: wp_delete_user(), which fires delete_user.

		$row = $this->row( $space );
		$this->assertSame( $site_admin, (int) $row->author_id );
		$this->assertNotSame( 'archived', $row->status );
	}

	public function test_member_space_admin_inherits_and_space_stays_active(): void {
		$owner    = self::factory()->user->create();
		$co_admin = self::factory()->user->create();
		$space    = $this->space_owned_by( $owner, 'member-heir' );
		SpaceMember::add( $space, $co_admin, 'admin' );

		self::delete_user( $owner ); // Core helper: wp_delete_user(), which fires delete_user.

		$row = $this->row( $space );
		$this->assertSame( $co_admin, (int) $row->author_id );
		$this->assertNotSame( 'archived', $row->status );
	}

	public function test_no_surviving_admin_hands_to_site_admin_and_archives(): void {
		$site_admin = Space::fallback_owner( 0 );
		$owner      = self::factory()->user->create();
		$space      = $this->space_owned_by( $owner, 'stranded' );

		self::delete_user( $owner ); // Core helper: wp_delete_user(), which fires delete_user.

		$row = $this->row( $space );
		$this->assertSame( $site_admin, (int) $row->author_id );
		$this->assertSame( 'archived', $row->status );
		$this->assertSame( 'admin', SpaceMember::get_role( $space, $site_admin ) );
	}

	public function test_a_refused_archive_does_not_leave_the_space_without_a_manager(): void {
		$site_admin = Space::fallback_owner( 0 );
		$owner      = self::factory()->user->create();
		$space      = $this->space_owned_by( $owner, 'stranded-refused' );
		// A plugin that owns the space's status refuses to archive it (BuddyNext does, for a forum linked to a Space).
		$refuse = static fn( $proceed, $id, $data ) => isset( $data['status'] ) ? new \WP_Error( 'refused', 'No.', [ 'status' => 409 ] ) : $proceed;
		add_filter( 'jetonomy_before_update_space', $refuse, 10, 3 );

		self::delete_user( $owner );
		remove_filter( 'jetonomy_before_update_space', $refuse, 10 );

		$row = $this->row( $space );
		$this->assertSame( $site_admin, (int) $row->author_id, 'The erased account no longer owns the space.' );
		$this->assertSame( 'admin', SpaceMember::get_role( $space, $site_admin ), 'And a live admin manages it.' );
		$this->assertNotSame( 'archived', $row->status, 'The refused archive stayed refused.' );
	}
}
