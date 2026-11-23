<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Permissions\Capabilities;

/**
 * QA J37 / card 10430961069: every Ban, Lift ban, Approve and Deny button on the members page had the same accessible
 * name ("Ban from space" x23). Each now names the member it acts on; the visible text stays short.
 */
class MembersRowActionNamesTest extends WP_UnitTestCase {

	public function test_each_row_action_names_its_member(): void {
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account = new \WP_User( $admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		wp_set_current_user( $admin );
		$space_id = (int) Space::create( array( 'title' => 'Row names', 'slug' => 'row-names-' . uniqid(), 'visibility' => 'private', 'join_policy' => 'approval' ), $admin );
		$names    = array( 'Aiko T.', 'Ben & O"Neil', 'Chen' );
		$ids      = array();
		foreach ( $names as $name ) {
			$ids[] = $uid = self::factory()->user->create( array( 'display_name' => $name ) );
			SpaceMember::add( $space_id, $uid );
		}
		Restriction::ban( $ids[2], 'space_ban', $admin, $space_id );
		JoinRequest::create_request( $space_id, self::factory()->user->create( array( 'display_name' => 'Dana' ) ), 'Hi' );
		Restriction::reset_memo();

		$data = array( 'slug' => Space::find( $space_id )->slug );
		ob_start();
		include JETONOMY_DIR . 'templates/views/space-members.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'aria-label="Ban Aiko T. from this space"', $html );
		$this->assertStringContainsString( 'aria-label="Ban Ben &amp; O&quot;Neil from this space"', $html, 'the name is escaped' );
		$this->assertStringContainsString( 'aria-label="Lift ban for Chen"', $html );
		$this->assertStringContainsString( 'aria-label="Approve join request from Dana"', $html );
		$this->assertStringContainsString( 'aria-label="Deny join request from Dana"', $html );
		$this->assertStringContainsString( 'Ban from space', $html, 'the visible text stays' );
		preg_match_all( '/class="[^"]*jt-member-ban-btn[^>]*aria-label="([^"]+)"/s', $html, $m );
		$this->assertSame( count( $m[1] ), count( array_unique( $m[1] ) ), 'no two Ban buttons share a name' );
		$this->assertGreaterThanOrEqual( 2, count( $m[1] ) );
	}
}
