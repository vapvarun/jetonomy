<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Config_Journey;
use Jetonomy\Permissions\Rate_Limiter;
use Jetonomy\Trust\Trust_Levels;
use WP_UnitTestCase;

/**
 * Exercises every Config_Journey method against the real `jetonomy_settings`
 * option.
 *
 * Each test seeds a known option payload in set_up() and deletes it in
 * tear_down() so the journey tests remain isolated from the activation seed.
 * The journey is pure PHP with no WP-CLI coupling, so these tests run through
 * the standard WP_UnitTestCase bootstrap without any CLI harness involvement.
 */
class ConfigJourneyTest extends WP_UnitTestCase {

	private Config_Journey $journey;

	/**
	 * Minimal seed payload exercising every shape the journey needs to walk.
	 *
	 * @var array<string,mixed>
	 */
	private array $seed;

	public function set_up(): void {
		parent::set_up();

		$this->journey = new Config_Journey();

		$this->seed = [
			'base_slug'             => 'community',
			'posts_per_page'        => 20,
			'guest_read'            => true,
			'trust_thresholds'      => [
				1 => [
					'posts'            => 5,
					'days_active'      => 3,
					'reputation'       => 0,
					'replies_received' => 10,
				],
			],
			'rate_limits'           => [
				'posts'   => 3,
				'replies' => 10,
				'votes'   => 5,
			],
			'notification_defaults' => [
				'mention' => [
					'web'   => true,
					'email' => true,
				],
			],
		];

		update_option( 'jetonomy_settings', $this->seed );
	}

	public function tear_down(): void {
		delete_option( 'jetonomy_settings' );
		parent::tear_down();
	}

	public function test_get_full_settings_without_path(): void {
		$result = $this->journey->get();

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertNull( $result->data['path'] );
		$this->assertSame( $this->seed, $result->data['value'] );
	}

	public function test_get_single_top_level_key(): void {
		$result = $this->journey->get( 'base_slug' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'base_slug', $result->data['path'] );
		$this->assertSame( 'community', $result->data['value'] );
	}

	public function test_get_dotted_path_into_nested_array(): void {
		$result = $this->journey->get( 'trust_thresholds.1.posts' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'trust_thresholds.1.posts', $result->data['path'] );
		$this->assertSame( 5, $result->data['value'] );
	}

	public function test_get_fails_on_missing_path(): void {
		$result = $this->journey->get( 'trust_thresholds.9.posts' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Key not found', $result->first_error() );
	}

	public function test_get_rejects_malformed_path(): void {
		$double_dot = $this->journey->get( 'trust_thresholds..1' );
		$this->assertFalse( $double_dot->is_success() );
		$this->assertStringContainsString( 'Malformed path', $double_dot->first_error() );

		$leading = $this->journey->get( '.trust_thresholds' );
		$this->assertFalse( $leading->is_success() );

		$trailing = $this->journey->get( 'trust_thresholds.' );
		$this->assertFalse( $trailing->is_success() );
	}

	public function test_set_creates_new_leaf(): void {
		$result = $this->journey->set( 'custom_knob', 'hello' );

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertSame( 'custom_knob', $result->data['path'] );
		$this->assertNull( $result->data['old_value'] );
		$this->assertSame( 'hello', $result->data['new_value'] );

		$stored = get_option( 'jetonomy_settings' );
		$this->assertArrayHasKey( 'custom_knob', $stored );
		$this->assertSame( 'hello', $stored['custom_knob'] );
	}

	public function test_set_updates_existing_leaf_and_returns_old(): void {
		$result = $this->journey->set( 'base_slug', 'forum' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'community', $result->data['old_value'] );
		$this->assertSame( 'forum', $result->data['new_value'] );

		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( 'forum', $stored['base_slug'] );
	}

	public function test_set_creates_intermediate_arrays(): void {
		$result = $this->journey->set( 'a.b.c', 1 );

		$this->assertTrue( $result->is_success() );
		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( [ 'c' => 1 ], $stored['a']['b'] );
		$this->assertSame( 1, $stored['a']['b']['c'] );
	}

	public function test_set_coerces_string_true_to_bool(): void {
		$this->journey->set( 'flag_true', 'true' );
		$this->journey->set( 'flag_false', 'false' );
		$this->journey->set( 'flag_null', 'null' );

		$stored = get_option( 'jetonomy_settings' );
		$this->assertTrue( $stored['flag_true'] );
		$this->assertFalse( $stored['flag_false'] );
		$this->assertNull( $stored['flag_null'] );
	}

	public function test_set_coerces_numeric_strings(): void {
		$this->journey->set( 'ints.count', '42' );
		$this->journey->set( 'floats.ratio', '3.14' );
		$this->journey->set( 'strings.name', 'abc' );

		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( 42, $stored['ints']['count'] );
		$this->assertSame( 3.14, $stored['floats']['ratio'] );
		$this->assertSame( 'abc', $stored['strings']['name'] );
	}

	public function test_set_rejects_empty_path(): void {
		$result = $this->journey->set( '', 'x' );
		$this->assertFalse( $result->is_success() );
	}

	public function test_reset_trust_thresholds_matches_defaults(): void {
		// Mutate first so reset has something to revert.
		$this->journey->set( 'trust_thresholds.1.posts', 999 );

		$result = $this->journey->reset( 'trust_thresholds' );
		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->data['reset_to_default'] );

		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( Trust_Levels::defaults(), $stored['trust_thresholds'] );
	}

	public function test_reset_rate_limits_matches_defaults(): void {
		$this->journey->set( 'rate_limits.posts', 999 );

		$result = $this->journey->reset( 'rate_limits' );
		$this->assertTrue( $result->is_success() );

		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( Rate_Limiter::defaults(), $stored['rate_limits'] );
	}

	public function test_reset_unknown_path_unsets_leaf(): void {
		$this->journey->set( 'temp.value', 'gone' );

		$result = $this->journey->reset( 'temp.value' );
		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $result->data['reset_to_default'] );

		$stored = get_option( 'jetonomy_settings' );
		$this->assertArrayNotHasKey( 'value', $stored['temp'] );
	}

	public function test_reset_all_reseeds_every_block(): void {
		// Pre-mutate all blocks so we can verify they were restored.
		$this->journey->set( 'trust_thresholds.1.posts', 999 );
		$this->journey->set( 'rate_limits.posts', 999 );
		$this->journey->set( 'notification_defaults.mention.email', 'false' );
		$this->journey->set( 'email_from_name', 'Varun' );

		$result = $this->journey->reset_all();
		$this->assertTrue( $result->is_success() );
		$this->assertSame(
			[ 'trust_thresholds', 'rate_limits', 'notification_defaults' ],
			$result->data['reset']
		);

		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( Trust_Levels::defaults(), $stored['trust_thresholds'] );
		$this->assertSame( Rate_Limiter::defaults(), $stored['rate_limits'] );
		$this->assertSame( Config_Journey::notification_defaults(), $stored['notification_defaults'] );

		// Unrelated key survives.
		$this->assertSame( 'Varun', $stored['email_from_name'] );
	}

	public function test_list_keys_returns_top_level(): void {
		$result = $this->journey->list_keys();

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertSame( [ 'key', 'type' ], $result->data['columns'] );

		$keys = array_column( $result->data['items'], 'key' );
		$this->assertContains( 'base_slug', $keys );
		$this->assertContains( 'trust_thresholds', $keys );
		$this->assertContains( 'rate_limits', $keys );
	}
}
