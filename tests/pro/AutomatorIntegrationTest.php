<?php
/**
 * Uncanny Automator integration registration and trigger guards.
 *
 * Automator runs a third-party integration's actions only when the
 * `uncanny_automator_maybe_add_integration` filter reports it active. Pro never
 * hooked it, so every Jetonomy action logged "The plugin for this action is not
 * active". The role-changed trigger also called the removed
 * process->user->trigger_matches() (fatal "Call to undefined method"); it now
 * uses maybe_add_trigger_entry(). The live recipe run is verified on a site
 * with Automator; these tests pin the registration and the Automator-absent
 * guards, since Automator is not loaded in the test env.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

class AutomatorIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\Jetonomy_Pro\Integrations\Automator_Integration' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
	}

	public function test_reports_integration_active_to_automator(): void {
		$integration = new \Jetonomy_Pro\Integrations\Automator_Integration();
		$this->assertNotFalse(
			has_filter( 'uncanny_automator_maybe_add_integration', array( $integration, 'mark_active' ) ),
			'Without this filter Automator refuses to run Jetonomy actions.'
		);
		// Other integrations' status passes through untouched.
		$this->assertSame( 0, $integration->mark_active( 0, 'WP' ) );
		if ( ! function_exists( 'Automator' ) ) {
			// Automator absent: never claim to be active.
			$this->assertSame( 0, $integration->mark_active( 0, 'JETONOMY' ) );
		}
	}

	public function test_trigger_never_fatals_without_automator(): void {
		$integration = new \Jetonomy_Pro\Integrations\Automator_Integration();
		$integration->on_role_changed( 1, self::factory()->user->create(), 'moderator', 'member' );
		$integration->on_role_changed();
		$this->assertTrue( true );
	}
}
