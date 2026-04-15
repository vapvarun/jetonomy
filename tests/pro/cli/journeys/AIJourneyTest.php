<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\AI_Journey;

/**
 * Exercises AI_Journey against the live Pro singleton.
 *
 * Every test that would otherwise hit a real AI provider either falls back
 * to asserting on error paths or skips when no provider is configured, so
 * this suite stays deterministic regardless of local API keys. Options are
 * captured in `set_up()` and restored in `tear_down()` so each test can
 * mutate `jetonomy_pro_extensions` and `jetonomy_pro_ai_settings` freely.
 */
class AIJourneyTest extends WP_UnitTestCase {

	private AI_Journey $journey;

	/** @var array<int,string> */
	private array $original_enabled = [];

	/** @var array<string,mixed> */
	private array $original_settings = [];

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( AI_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise AI_Journey.' );
		}

		$this->journey           = new AI_Journey();
		$this->original_enabled  = (array) get_option( 'jetonomy_pro_extensions', [] );
		$this->original_settings = (array) get_option( 'jetonomy_pro_ai_settings', [] );

		// Ensure ai extension is enabled for the happy-path tests. Tests that
		// need it disabled flip the option themselves.
		$with_ai = array_values( array_unique( array_merge( $this->original_enabled, [ 'ai' ] ) ) );
		update_option( 'jetonomy_pro_extensions', $with_ai );
	}

	public function tear_down(): void {
		update_option( 'jetonomy_pro_extensions', $this->original_enabled );
		update_option( 'jetonomy_pro_ai_settings', $this->original_settings );
		parent::tear_down();
	}

	public function test_is_enabled_reflects_option(): void {
		update_option( 'jetonomy_pro_extensions', [ 'ai' ] );
		$result = $this->journey->is_enabled();
		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->data['enabled'] );

		update_option( 'jetonomy_pro_extensions', [] );
		$result = $this->journey->is_enabled();
		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $result->data['enabled'] );
	}

	public function test_list_providers_when_none_configured_returns_empty_items(): void {
		update_option( 'jetonomy_pro_ai_settings', [] );

		$result = $this->journey->list_providers();

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$this->assertSame( [], $result->data['items'] );
		$this->assertContains( 'id', $result->data['columns'] );
		$this->assertContains( 'configured', $result->data['columns'] );
	}

	public function test_list_providers_when_configured_returns_shape(): void {
		update_option(
			'jetonomy_pro_ai_settings',
			[
				'default_provider' => 'openai',
				'fallback_chain'   => [ 'openai', 'anthropic' ],
				'providers'        => [
					'openai'    => [
						'enabled' => true,
						'api_key' => 'sk-test',
						'model'   => 'gpt-4o-mini',
					],
					'anthropic' => [
						'enabled' => false,
						'api_key' => '',
						'model'   => 'claude-3-5-sonnet',
					],
				],
			]
		);

		$result = $this->journey->list_providers();

		$this->assertTrue( $result->is_success() );
		$ids = array_column( $result->data['items'], 'id' );
		$this->assertContains( 'openai', $ids );
		$this->assertContains( 'anthropic', $ids );

		$openai_row = null;
		foreach ( $result->data['items'] as $row ) {
			if ( 'openai' === $row['id'] ) {
				$openai_row = $row;
				break;
			}
		}

		$this->assertNotNull( $openai_row );
		$this->assertSame( 'yes', $openai_row['configured'] );
		$this->assertSame( 'yes', $openai_row['api_key_set'] );
		$this->assertSame( 'gpt-4o-mini', $openai_row['model'] );
	}

	public function test_spam_check_returns_verdict_string_or_null(): void {
		$settings = (array) get_option( 'jetonomy_pro_ai_settings', [] );
		if ( empty( $settings['providers'] ) ) {
			$this->markTestSkipped( 'No AI provider configured' );
		}

		$result = $this->journey->spam_check( 'This is a normal message.' );

		// Either the spam detector ran and returned something, or it failed
		// gracefully — we only assert the result is a Journey_Result, and
		// that success responses carry a string verdict.
		$this->assertInstanceOf( Journey_Result::class, $result );
		if ( $result->is_success() ) {
			$this->assertArrayHasKey( 'verdict', $result->data );
			$this->assertIsString( $result->data['verdict'] );
		}
	}

	public function test_spam_check_rejects_empty_content(): void {
		$result = $this->journey->spam_check( '   ' );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'required', (string) $result->first_error() );
	}

	public function test_summarize_rejects_empty_content(): void {
		$result = $this->journey->summarize( '' );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'required', (string) $result->first_error() );
	}

	public function test_summarize_rejects_invalid_max_sentences(): void {
		$result = $this->journey->summarize( 'Some text.', 0 );
		$this->assertFalse( $result->is_success() );

		$result = $this->journey->summarize( 'Some text.', 99 );
		$this->assertFalse( $result->is_success() );
	}

	public function test_test_provider_rejects_unknown_provider(): void {
		update_option(
			'jetonomy_pro_ai_settings',
			[
				'providers' => [
					'openai' => [
						'enabled' => true,
						'api_key' => 'sk-test',
						'model'   => 'gpt-4o-mini',
					],
				],
			]
		);

		$result = $this->journey->test_provider( 'does-not-exist' );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Unknown provider', (string) $result->first_error() );
	}

	public function test_provider_status_rejects_unknown_provider(): void {
		update_option(
			'jetonomy_pro_ai_settings',
			[
				'providers' => [
					'openai' => [
						'enabled' => true,
						'api_key' => 'sk-test',
						'model'   => 'gpt-4o-mini',
					],
				],
			]
		);

		$result = $this->journey->provider_status( 'nope' );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Unknown provider', (string) $result->first_error() );
	}

	public function test_methods_fail_when_extension_disabled(): void {
		update_option( 'jetonomy_pro_extensions', [] );

		// is_enabled + list_providers always succeed regardless of state.
		$this->assertTrue( $this->journey->is_enabled()->is_success() );
		$this->assertTrue( $this->journey->list_providers()->is_success() );

		// Everything else must fail with the extension-disabled error.
		$expected = 'AI extension is not enabled.';

		$result = $this->journey->provider_status( 'openai' );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( $expected, $result->first_error() );

		$result = $this->journey->test_provider( 'openai' );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( $expected, $result->first_error() );

		$result = $this->journey->spam_check( 'hello world' );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( $expected, $result->first_error() );

		$result = $this->journey->summarize( 'hello world' );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( $expected, $result->first_error() );
	}
}
