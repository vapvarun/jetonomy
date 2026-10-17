<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\InviteLink;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

/**
 * An invite link opened by someone already in the space says so, with a link
 * in, instead of silently bouncing them.
 *
 * The old view printed a meta refresh and exit()ed; a separate process turns
 * that exit into a recorded error instead of ending the whole run.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class InviteAlreadyMemberTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
	}

	public function test_invite_link_tells_an_existing_member_they_are_already_in(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$space  = (int) Space::create(
			array(
				'title'      => 'Insiders / Beta',
				'slug'       => 'tf-' . uniqid(),
				'visibility' => 'public',
			),
			$admin
		);
		SpaceMember::add( $space, $member, 'member' );
		$data = array( 'slug' => InviteLink::generate( $space, $admin ) );
		wp_set_current_user( $member );

		ob_start();
		include JETONOMY_DIR . 'templates/views/invite.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'You are already a member of', $html );
		$this->assertStringContainsString( 'Insiders / Beta', $html );
		$this->assertStringContainsString( esc_url( \Jetonomy\route_url( 'space', Space::find( $space )->slug ) ), $html, 'a link into the space' );
		$this->assertStringNotContainsString( 'http-equiv="refresh"', $html );
	}
}
