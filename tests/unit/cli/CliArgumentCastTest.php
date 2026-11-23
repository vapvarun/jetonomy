<?php
/**
 * No WP-CLI command reads an argument with a cast.
 *
 * @package Jetonomy\Tests\Unit\CLI
 */

namespace Jetonomy\Tests\Unit\CLI;

use WP_UnitTestCase;

/**
 * A cast reads "abc" as 0 and "false" as true, so `category create --parent=abc`
 * made a top-level category and `--dry-run=false` still dry-ran. Every Free and
 * Pro command reads numbers through int_arg() and yes/no through bool_arg(),
 * which refuse a bad value by name. This scan keeps a new cast out.
 */
class CliArgumentCastTest extends WP_UnitTestCase {

	public function test_no_command_casts_or_presence_tests_an_argument(): void {
		$dirs = array( dirname( __DIR__, 3 ) . '/includes/cli/commands' );
		$pro  = dirname( __DIR__, 4 ) . '/jetonomy-pro/includes/cli/commands';
		if ( is_dir( $pro ) ) {
			$dirs[] = $pro;
		}
		$pattern = '/\((?:int|bool|float)\)\s*\(?\s*\$(?:assoc|args)\s*\[|(?:absint|intval|floatval)\(\s*\$(?:assoc|args)\s*\[|!\s*empty\(\s*\$assoc\s*\[|isset\(\s*\$assoc\s*\[\s*\'(?:dry-run|yes|force|all|cleanup)\'/';
		$found   = array();
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
				foreach ( (array) file( $file ) as $n => $line ) {
					if ( preg_match( $pattern, (string) $line ) ) {
						$found[] = basename( $file ) . ':' . ( $n + 1 ) . ' ' . trim( (string) $line );
					}
				}
			}
		}
		$this->assertSame( array(), $found, 'Read the argument with int_arg() or bool_arg().' );
	}

	/**
	 * `reply create --parent=-1` stored a top-level reply: int_arg() took any whole
	 * number. Every call now states its own minimum (1 for a required id, 0 where 0
	 * means none, null only for a signed value or one the journey range-checks).
	 */
	public function test_every_int_arg_call_states_its_minimum(): void {
		$dirs = array( dirname( __DIR__, 3 ) . '/includes/cli/commands' );
		$pro  = dirname( __DIR__, 4 ) . '/jetonomy-pro/includes/cli/commands';
		if ( is_dir( $pro ) ) {
			$dirs[] = $pro;
		}
		$missing = array();
		$calls   = 0;
		foreach ( $dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
				foreach ( (array) file( $file ) as $n => $line ) {
					if ( ! preg_match_all( '/\$this->int_arg\(([^()]*)\)/', (string) $line, $m ) ) {
						continue;
					}
					foreach ( $m[1] as $inner ) {
						++$calls;
						if ( count( explode( ',', $inner ) ) < 4 ) {
							$missing[] = basename( $file ) . ':' . ( $n + 1 ) . ' ' . trim( (string) $line );
						}
					}
				}
			}
		}
		$this->assertGreaterThan( 50, $calls, 'the scan found the commands' );
		$this->assertSame( array(), $missing, 'Pass int_arg( $from, $key, $default, $min ).' );
		$this->assertMatchesRegularExpression( "/int_arg\\( \\\$assoc, 'parent', 0, 0 \\)/", (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/cli/commands/class-reply-command.php' ) );
	}
}

