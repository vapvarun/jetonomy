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

	/**
	 * Clearing the Community Base URL field and saving must not unmount the community.
	 *
	 * An empty slug was stored as-is, base_slug() returned '', and every
	 * /community/ URL 404'd (the base moved to the site root) after one save.
	 */
	public function test_a_cleared_base_slug_keeps_the_current_base(): void {
		update_option( 'jetonomy_settings', [ 'base_slug' => 'forum' ] );

		$clean = $this->admin->sanitize_settings( $this->general_tab( [ 'base_slug' => '' ] ) );

		$this->assertSame( 'forum', $clean['base_slug'] );
	}

	/** A site that already stored an empty slug, or never saved Settings, runs on 'community'. */
	public function test_base_slug_falls_back_to_community_when_empty_or_unset(): void {
		update_option( 'jetonomy_settings', [ 'base_slug' => '' ] );
		$this->assertSame( 'community', \Jetonomy\base_slug() );

		update_option( 'jetonomy_settings', [] );
		$this->assertSame( 'community', \Jetonomy\base_slug() );
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

	/**
	 * The form's number fields: digits kept (clamped to the range), empty means
	 * the default, anything else keeps the stored value with a notice.
	 */
	public function test_form_number_fields_refuse_non_numbers_and_empty_means_default(): void {
		update_option(
			'jetonomy_settings',
			array(
				'posts_per_page'    => 40,
				'rate_limits'       => array(
					'posts'   => 9,
					'replies' => 9,
					'votes'   => 9,
				),
				'reputation_points' => array( 'post_upvoted' => 7 ),
			)
		);
		$notices = new \ReflectionProperty( \Jetonomy\Settings::class, 'notices' );
		$notices->setValue( null, array() );
		$clean = \Jetonomy\Settings::sanitize(
			$this->general_tab(
				array(
					'posts_per_page'    => '5abc',
					'replies_per_page'  => '',
					'rate_limits'       => array(
						'posts'   => 'abc',
						'replies' => '12',
						'votes'   => '',
					),
					'reputation_points' => array(
						'post_upvoted'   => 'false',
						'reply_upvoted'  => '0x1A',
						'reply_accepted'  => '99999',
					),
				)
			)
		);
		$this->assertSame( 40, $clean['posts_per_page'], '"5abc" keeps the stored value' );
		$this->assertSame( 30, $clean['replies_per_page'], 'empty is the default' );
		$this->assertSame( 9, $clean['rate_limits']['posts'] );
		$this->assertSame( 12, $clean['rate_limits']['replies'] );
		$this->assertSame( \Jetonomy\Permissions\Rate_Limiter::defaults()['votes'], $clean['rate_limits']['votes'] );
		$this->assertSame( 7, $clean['reputation_points']['post_upvoted'], '"false" keeps the stored override' );
		$this->assertArrayNotHasKey( 'reply_upvoted', $clean['reputation_points'], '"0x1A" with no override stays on the default' );
		$this->assertSame( 10000, $clean['reputation_points']['reply_accepted'], 'clamped like the API range' );
		$codes = array_column( $notices->getValue(), 'code' );
		foreach ( array( 'jetonomy_invalid_posts_per_page', 'jetonomy_invalid_rate_limits', 'jetonomy_invalid_reputation_points' ) as $code ) {
			$this->assertContains( $code, $codes, 'A refused number is named, not saved silently.' );
		}
		$this->assertSame( 50, \Jetonomy\Settings::sanitize( $this->general_tab( array( 'posts_per_page' => '50' ) ) )['posts_per_page'] );
		$this->assertSame( 100, \Jetonomy\Settings::sanitize( $this->general_tab( array( 'posts_per_page' => '500' ) ) )['posts_per_page'] );
	}

	/**
	 * Reputation points outside -10000..10000 are clamped AND named, like every other number field.
	 */
	public function test_out_of_range_reputation_points_say_what_was_saved(): void {
		$notices = new \ReflectionProperty( \Jetonomy\Settings::class, 'notices' );
		$notices->setValue( null, array() );
		$clean = \Jetonomy\Settings::sanitize(
			$this->general_tab(
				array(
					'reputation_points' => array(
						'post_upvoted'  => '20000',
						'post_removed'  => '-20000',
					),
				)
			)
		);
		$this->assertSame( 10000, $clean['reputation_points']['post_upvoted'] );
		$this->assertSame( -10000, $clean['reputation_points']['post_removed'] );
		$messages = implode( "\n", array_column( $notices->getValue(), 'message' ) );
		$this->assertStringContainsString( 'post_upvoted must be between -10000 and 10000, so it was saved as 10000.', $messages );
		$this->assertStringContainsString( 'post_removed must be between -10000 and 10000, so it was saved as -10000.', $messages );
	}
}
