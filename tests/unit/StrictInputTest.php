<?php
/**
 * strict_bool() and strict_int(): the one reading of yes/no and whole numbers
 * from CLI flags and loosely typed inputs.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

/**
 * "false" is false, "5abc" is not 5: a cast reads both wrong.
 */
class StrictInputTest extends WP_UnitTestCase {

	public function test_strict_bool_reads_only_clear_answers(): void {
		foreach ( array( true, 1, '1', 'true', 'TRUE', ' yes ', 'On' ) as $yes ) {
			$this->assertTrue( \Jetonomy\strict_bool( $yes ), wp_json_encode( $yes ) );
		}
		foreach ( array( false, 0, '0', 'false', 'False', 'no', 'OFF' ) as $no ) {
			$this->assertFalse( \Jetonomy\strict_bool( $no ), wp_json_encode( $no ) );
		}
		foreach ( array( 'maybe', '', '2', 2, null, array(), 1.0, 'truee' ) as $bad ) {
			$this->assertNull( \Jetonomy\strict_bool( $bad ), wp_json_encode( $bad ) );
		}
	}

	public function test_strict_int_reads_only_whole_numbers(): void {
		$this->assertSame( 5, \Jetonomy\strict_int( 5 ) );
		$this->assertSame( 5, \Jetonomy\strict_int( '5' ) );
		$this->assertSame( -3, \Jetonomy\strict_int( ' -3 ' ) );
		$this->assertSame( 0, \Jetonomy\strict_int( '0' ) );
		foreach ( array( 'abc', '5abc', '0x1A', '1.5', '', 'false', true, null, 1.5, '1e3', '9999999999999999999' ) as $bad ) {
			$this->assertNull( \Jetonomy\strict_int( $bad ), wp_json_encode( $bad ) );
		}
	}
}
