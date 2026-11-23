<?php
/**
 * The verification-reminder seed builds a translated template, so it runs on init, not plugins_loaded.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;

class VerificationReminderSeedTimingTest extends WP_UnitTestCase {

	public function test_seed_is_hooked_on_init_after_the_textdomain(): void {
		$jetonomy = \Jetonomy\Jetonomy::instance();
		$this->assertSame( 1, has_action( 'init', array( $jetonomy, 'load_textdomain' ) ) );
		$this->assertSame( 2, has_action( 'init', array( $jetonomy, 'maybe_seed_verification_reminder_defaults' ) ), 'seed waits for init, after the textdomain' );
	}

	public function test_seed_fills_missing_defaults_once(): void {
		delete_option( 'jetonomy_verification_reminder_seeded' );
		update_option( 'jetonomy_settings', array( 'base_slug' => 'community' ) );
		update_option( 'jetonomy_email_templates', array() );

		\Jetonomy\Jetonomy::instance()->maybe_seed_verification_reminder_defaults();

		$this->assertSame( 24, get_option( 'jetonomy_settings' )['verification_reminder_hours'] ?? null );
		$this->assertArrayHasKey( 'verification_reminder', (array) get_option( 'jetonomy_email_templates' ) );
		$this->assertNotEmpty( get_option( 'jetonomy_verification_reminder_seeded' ) );

		// Seeded flag set: an owner's later change is never overwritten.
		update_option( 'jetonomy_settings', array( 'verification_reminder_hours' => 0 ) );
		\Jetonomy\Jetonomy::instance()->maybe_seed_verification_reminder_defaults();
		$this->assertSame( 0, get_option( 'jetonomy_settings' )['verification_reminder_hours'] );
	}
}
