<?php
namespace Jetonomy\Tests\Pro\CLI;

use ReflectionClass;
use WP_UnitTestCase;

/**
 * Verifies the Jetonomy Pro dispatcher command map.
 *
 * Mirrors CliDispatcherTest on the free side: asserts every slug points
 * at a loadable class that extends the shared Base_Command contract, so
 * a slug typo or missing Pro file surfaces under PHPUnit instead of
 * waiting for a user to run `wp jetonomy-pro`.
 */
class ProCliDispatcherTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'Jetonomy_Pro\\CLI\\Pro_CLI_Dispatcher' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded.' );
		}
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function expected_slug_provider(): array {
		return [
			[ 'extension' ],
			[ 'messaging' ],
			[ 'ai' ],
		];
	}

	/**
	 * @dataProvider expected_slug_provider
	 */
	public function test_every_expected_slug_is_registered( string $slug ): void {
		$commands = $this->get_commands_map();
		$this->assertArrayHasKey( $slug, $commands, sprintf( 'Pro_CLI_Dispatcher missing slug "%s".', $slug ) );
	}

	public function test_every_mapped_class_exists_and_is_loadable(): void {
		$commands = $this->get_commands_map();
		foreach ( $commands as $slug => $class ) {
			$this->assertTrue(
				class_exists( $class ),
				sprintf( 'Pro_CLI_Dispatcher slug "%s" points to non-existent class %s', $slug, $class )
			);
		}
	}

	public function test_every_mapped_class_extends_free_base_command(): void {
		$commands = $this->get_commands_map();
		foreach ( $commands as $slug => $class ) {
			$this->assertTrue(
				is_subclass_of( $class, 'Jetonomy\\CLI\\Commands\\Base_Command' ),
				sprintf( 'Pro command %s (slug "%s") does not extend free Base_Command', $class, $slug )
			);
		}
	}

	public function test_register_is_noop_when_wp_cli_is_undefined(): void {
		$this->expectNotToPerformAssertions();
		\Jetonomy_Pro\CLI\Pro_CLI_Dispatcher::register();
	}

	/**
	 * @return array<string,class-string>
	 */
	private function get_commands_map(): array {
		$reflection = new ReflectionClass( 'Jetonomy_Pro\\CLI\\Pro_CLI_Dispatcher' );
		/** @var array<string,class-string> $map */
		$map = $reflection->getReflectionConstant( 'COMMANDS' )->getValue();
		return $map;
	}
}
