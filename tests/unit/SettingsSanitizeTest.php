<?php
namespace Jetonomy\Tests\Unit;

use Jetonomy\Admin\Admin;
use WP_UnitTestCase;

/**
 * sanitize_settings() must WRITE every checkbox the settings screen RENDERS.
 *
 * REGRESSION (Basecamp 10217204334, second bounce). Two settings shipped in
 * 1.9.3 as checkboxes on the General tab and were never added to the
 * sanitizer. sanitize_settings() starts with `$clean = $existing`, so a key it
 * does not write silently keeps its old value: the box appeared to tick, the
 * page reloaded showing it unticked, and the feature behind it stayed off.
 *
 * That is not a cosmetic bug. allow_space_admin_purge is read by the AJAX
 * guard in class-spaces-handler.php and by the REST controller, so a setting
 * that could never become true meant "let space admins delete a space" was
 * impossible to switch on no matter how many times an owner clicked Save.
 *
 * The class of bug is "rendered and read but never written", and the cheapest
 * guard against the next one is to assert the round trip for each such field
 * rather than to trust that whoever adds the checkbox also finds the
 * sanitizer.
 */
class SettingsSanitizeTest extends WP_UnitTestCase {

	private Admin $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = new Admin();
	}

	/** A General-tab submit. base_slug is what gates that branch of the sanitizer. */
	private function general_tab( array $fields = [] ): array {
		return array_merge(
			[
				'base_slug'       => 'community',
				'community_title' => 'Community',
			],
			$fields
		);
	}

	/**
	 * @dataProvider general_tab_checkboxes
	 */
	public function test_a_general_tab_checkbox_survives_a_save( string $key ): void {
		$clean = $this->admin->sanitize_settings( $this->general_tab( [ $key => '1' ] ) );

		$this->assertArrayHasKey( $key, $clean, "{$key} was dropped by sanitize_settings()" );
		$this->assertTrue( (bool) $clean[ $key ], "{$key} did not persist as ON" );
	}

	/**
	 * An unchecked checkbox submits nothing, so absence inside a General-tab
	 * save has to mean OFF. If it meant "leave alone", the setting could be
	 * switched on but never off again.
	 *
	 * @dataProvider general_tab_checkboxes
	 */
	public function test_an_unticked_general_tab_checkbox_turns_off( string $key ): void {
		$this->admin->sanitize_settings( $this->general_tab( [ $key => '1' ] ) );

		$clean = $this->admin->sanitize_settings( $this->general_tab() );

		$this->assertArrayHasKey( $key, $clean, "{$key} was dropped by sanitize_settings()" );
		$this->assertFalse( (bool) $clean[ $key ], "{$key} could be switched on but not off" );
	}

	public static function general_tab_checkboxes(): array {
		return [
			// Gates permanent space deletion for non-administrators.
			'allow_space_admin_purge'    => [ 'allow_space_admin_purge' ],
			// Already worked - here so the pair above cannot regress alone.
			'require_email_verification' => [ 'require_email_verification' ],
			'front_page'                 => [ 'front_page' ],
		];
	}
}
