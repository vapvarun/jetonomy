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
}
