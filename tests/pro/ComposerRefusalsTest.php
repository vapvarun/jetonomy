<?php
/**
 * Composer refusals tell the member what to do next.
 *
 * - Attachments: "That file type is not allowed." / "That file is too large."
 *   named neither the allowed types nor the limit. Settings::type_refusal() and
 *   size_refusal() now name both from the site owner's settings, and the REST
 *   attach gate uses the same text.
 * - Private messaging: starting a conversation needs trust level 1. The compose
 *   panel now reads Extension::can_send_messages() to show the reason up front
 *   instead of a recipient field the server refuses on Send.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use Jetonomy_Pro\Extensions\Attachments\Settings;
use Jetonomy_Pro\Extensions\Private_Messaging\Extension as Messaging;
use WP_UnitTestCase;

class ComposerRefusalsTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_attachment_refusals_name_allowed_types_and_limit(): void {
		if ( ! class_exists( Settings::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		update_option(
			Settings::OPTION,
			array(
				'allowed_types'  => array( 'png', 'pdf' ),
				'max_size_bytes' => 2 * MB_IN_BYTES,
				'max_files'      => 3,
			)
		);

		$type = Settings::type_refusal();
		$this->assertStringContainsString( 'PNG', $type );
		$this->assertStringContainsString( 'PDF', $type );
		$this->assertStringContainsString( '2 MB', Settings::size_refusal() );
	}

	public function test_trust_level_zero_member_cannot_start_a_conversation(): void {
		if ( ! class_exists( Messaging::class ) || ! method_exists( Messaging::class, 'can_send_messages' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro messaging is not loaded.' );
		}
		$tl0 = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\Jetonomy\Models\UserProfile::find_or_create( $tl0 );
		\Jetonomy\Models\UserProfile::update_profile( $tl0, array( 'trust_level' => 0 ) );
		$tl1 = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\Jetonomy\Models\UserProfile::find_or_create( $tl1 );
		\Jetonomy\Models\UserProfile::update_profile( $tl1, array( 'trust_level' => 1 ) );

		wp_set_current_user( $tl0 );
		$this->assertFalse( Messaging::can_send_messages( $tl0 ) );
		$this->assertNotEmpty( Messaging::trust_required_error()->get_error_message() );

		wp_set_current_user( $tl1 );
		$this->assertTrue( Messaging::can_send_messages( $tl1 ) );
	}
}
