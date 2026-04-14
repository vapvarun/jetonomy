<?php
namespace Jetonomy\Tests\Unit\CLI;

use ReflectionClass;
use WP_UnitTestCase;
use Jetonomy\CLI\CLI_Dispatcher;

/**
 * Verifies the free-plugin CLI_Dispatcher command map.
 *
 * Guards against two specific regressions:
 * - A slug added to COMMANDS with a typo or a class that was never shipped
 *   (caught by class_exists() on every value).
 * - A journey commit that forgets to register its command in the map
 *   (caught by asserting every expected slug is present).
 *
 * The dispatcher's register() method itself is a thin `add_command` loop
 * around the const — exercising the const is sufficient; registering
 * commands with WP_CLI at test time would require the CLI runtime which
 * isn't available under PHPUnit.
 */
class CliDispatcherTest extends WP_UnitTestCase {

	/**
	 * @return array<int,array{0:string}> Expected slugs in register order.
	 */
	public function expected_slug_provider(): array {
		return [
			[ 'post' ],
			[ 'reply' ],
			[ 'vote' ],
			[ 'flag' ],
			[ 'space' ],
			[ 'member' ],
			[ 'mod' ],
			[ 'notification' ],
			[ 'config' ],
			[ 'category' ],
			[ 'tag' ],
			[ 'user' ],
			[ 'scenario' ],
		];
	}

	/**
	 * @dataProvider expected_slug_provider
	 */
	public function test_every_expected_slug_is_registered( string $slug ): void {
		$commands = $this->get_commands_map();
		$this->assertArrayHasKey( $slug, $commands, sprintf( 'CLI_Dispatcher missing slug "%s".', $slug ) );
	}

	public function test_every_mapped_class_exists_and_is_loadable(): void {
		$commands = $this->get_commands_map();
		foreach ( $commands as $slug => $class ) {
			$this->assertTrue(
				class_exists( $class ),
				sprintf( 'CLI_Dispatcher slug "%s" points to non-existent class %s', $slug, $class )
			);
		}
	}

	public function test_every_mapped_class_has_a_render_contract(): void {
		$commands = $this->get_commands_map();
		foreach ( $commands as $slug => $class ) {
			$this->assertTrue(
				is_subclass_of( $class, 'Jetonomy\\CLI\\Commands\\Base_Command' ),
				sprintf( 'Command %s registered for slug "%s" does not extend Base_Command', $class, $slug )
			);
		}
	}

	public function test_register_is_noop_when_wp_cli_is_undefined(): void {
		// The only behavioral assertion we can make without the CLI runtime
		// is that calling register() outside of `wp` does not throw.
		$this->expectNotToPerformAssertions();
		CLI_Dispatcher::register();
	}

	/**
	 * Reach into the private `COMMANDS` const for inspection. Tests own this
	 * layer so reflection is acceptable — the alternative would be exposing
	 * the map publicly just for introspection.
	 *
	 * @return array<string,class-string>
	 */
	private function get_commands_map(): array {
		$reflection = new ReflectionClass( CLI_Dispatcher::class );
		/** @var array<string,class-string> $map */
		$map = $reflection->getReflectionConstant( 'COMMANDS' )->getValue();
		return $map;
	}
}
