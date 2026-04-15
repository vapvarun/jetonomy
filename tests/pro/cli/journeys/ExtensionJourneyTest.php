<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\Extension_Journey;

/**
 * Exercises Extension_Journey against the live Pro singleton.
 *
 * The Pro plugin must be active during the test run. Tests manipulate the
 * `jetonomy_pro_extensions` option and restore it in `tear_down()` so they
 * can run in any order without cross-contamination.
 */
class ExtensionJourneyTest extends WP_UnitTestCase {

	private Extension_Journey $journey;

	/** @var array<int,string> */
	private array $original_enabled = [];

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Extension_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Extension_Journey.' );
		}

		$this->journey          = new Extension_Journey();
		$this->original_enabled = (array) get_option( 'jetonomy_pro_extensions', [] );
	}

	public function tear_down(): void {
		update_option( 'jetonomy_pro_extensions', $this->original_enabled );
		parent::tear_down();
	}

	public function test_list_all_returns_items_and_columns(): void {
		$result = $this->journey->list_all();
		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$this->assertNotEmpty( $result->data['items'] );
		$this->assertContains( 'id', $result->data['columns'] );
		$this->assertContains( 'enabled', $result->data['columns'] );
		$this->assertContains( 'licensed', $result->data['columns'] );
	}

	public function test_list_all_includes_known_extension_ids(): void {
		$result = $this->journey->list_all();
		$ids    = array_column( $result->data['items'], 'id' );
		$this->assertContains( 'ai', $ids );
		$this->assertContains( 'private-messaging', $ids );
		$this->assertContains( 'analytics', $ids );
	}

	public function test_enable_persists_extension_and_reports_previous_state(): void {
		update_option( 'jetonomy_pro_extensions', [] );

		$result = $this->journey->enable( 'ai' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'ai', $result->data['id'] );
		$this->assertTrue( $result->data['enabled'] );
		$this->assertFalse( $result->data['was_enabled'] );
		$this->assertContains( 'ai', (array) get_option( 'jetonomy_pro_extensions', [] ) );
	}

	public function test_enable_is_idempotent_when_already_enabled(): void {
		update_option( 'jetonomy_pro_extensions', [ 'ai' ] );

		$result = $this->journey->enable( 'ai' );

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->data['was_enabled'] );
		$stored = (array) get_option( 'jetonomy_pro_extensions', [] );
		$this->assertSame( 1, count( array_keys( $stored, 'ai', true ) ) );
	}

	public function test_enable_rejects_unknown_extension(): void {
		$result = $this->journey->enable( 'does-not-exist' );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Unknown extension id', $result->first_error() );
	}

	public function test_enable_rejects_empty_id(): void {
		$result = $this->journey->enable( '' );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'required', $result->first_error() );
	}

	public function test_disable_removes_extension(): void {
		update_option( 'jetonomy_pro_extensions', [ 'ai', 'analytics' ] );

		$result = $this->journey->disable( 'ai' );

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->data['was_enabled'] );
		$stored = (array) get_option( 'jetonomy_pro_extensions', [] );
		$this->assertNotContains( 'ai', $stored );
		$this->assertContains( 'analytics', $stored );
	}

	public function test_disable_is_noop_when_not_enabled(): void {
		update_option( 'jetonomy_pro_extensions', [ 'analytics' ] );

		$result = $this->journey->disable( 'ai' );

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $result->data['was_enabled'] );
	}

	public function test_disable_rejects_unknown_extension(): void {
		$result = $this->journey->disable( 'not-real' );
		$this->assertFalse( $result->is_success() );
	}

	public function test_status_returns_metadata(): void {
		$result = $this->journey->status( 'ai' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'ai', $result->data['id'] );
		$this->assertArrayHasKey( 'name', $result->data );
		$this->assertArrayHasKey( 'version', $result->data );
		$this->assertArrayHasKey( 'class', $result->data );
		$this->assertStringContainsString( 'Jetonomy_Pro\\Extensions', $result->data['class'] );
	}

	public function test_status_rejects_unknown_extension(): void {
		$result = $this->journey->status( 'bogus' );
		$this->assertFalse( $result->is_success() );
	}
}
