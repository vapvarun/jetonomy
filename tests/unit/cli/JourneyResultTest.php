<?php
namespace Jetonomy\Tests\Unit\CLI;

use WP_Error;
use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;

/**
 * Covers every public method on the Journey_Result DTO.
 *
 * The DTO is the foundation of every journey method's return shape. Every
 * downstream test (journey, scenario, command) depends on its factories
 * behaving consistently, so exhaustive coverage here prevents subtle
 * regressions from rippling through the whole CLI module.
 */
class JourneyResultTest extends WP_UnitTestCase {

	public function test_ok_with_no_args_is_successful_and_empty(): void {
		$result = Journey_Result::ok();
		$this->assertTrue( $result->is_success() );
		$this->assertSame( [], $result->data );
		$this->assertSame( [], $result->errors );
		$this->assertSame( [], $result->logs );
		$this->assertSame( 0, $result->duration_ms );
		$this->assertNull( $result->first_error() );
	}

	public function test_ok_preserves_data_logs_and_duration(): void {
		$result = Journey_Result::ok(
			[ 'id' => 42 ],
			[ 'step 1 ok', 'step 2 ok' ],
			17
		);
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 42, $result->data['id'] );
		$this->assertSame( [ 'step 1 ok', 'step 2 ok' ], $result->logs );
		$this->assertSame( 17, $result->duration_ms );
	}

	public function test_fail_with_string_error(): void {
		$result = Journey_Result::fail( 'bad thing happened' );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( [ 'bad thing happened' ], $result->errors );
		$this->assertSame( 'bad thing happened', $result->first_error() );
	}

	public function test_fail_with_array_of_errors(): void {
		$result = Journey_Result::fail( [ 'first', 'second', 'third' ] );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( [ 'first', 'second', 'third' ], $result->errors );
		$this->assertSame( 'first', $result->first_error() );
	}

	public function test_fail_coerces_non_string_array_entries(): void {
		$result = Journey_Result::fail( [ 'ok', 123, true ] );
		$this->assertSame( [ 'ok', '123', '1' ], $result->errors );
	}

	public function test_fail_preserves_data_payload(): void {
		$result = Journey_Result::fail( 'nope', [ 'context' => 'extra' ] );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'extra', $result->data['context'] );
	}

	public function test_from_wp_error_collects_every_code_and_message(): void {
		$err = new WP_Error( 'first_code', 'first message' );
		$err->add( 'second_code', 'second message' );

		$result = Journey_Result::from_wp_error( $err );

		$this->assertFalse( $result->is_success() );
		$this->assertCount( 2, $result->errors );
		$this->assertStringContainsString( '[first_code]', $result->errors[0] );
		$this->assertStringContainsString( 'first message', $result->errors[0] );
		$this->assertStringContainsString( '[second_code]', $result->errors[1] );
		$this->assertStringContainsString( 'second message', $result->errors[1] );
	}

	public function test_from_wp_error_handles_multiple_messages_per_code(): void {
		$err = new WP_Error( 'bulk', 'one' );
		$err->add( 'bulk', 'two' );

		$result = Journey_Result::from_wp_error( $err );

		$this->assertCount( 2, $result->errors );
		foreach ( $result->errors as $msg ) {
			$this->assertStringContainsString( '[bulk]', $msg );
		}
	}

	public function test_from_wp_error_with_empty_error_returns_unknown(): void {
		$result = Journey_Result::from_wp_error( new WP_Error() );

		$this->assertFalse( $result->is_success() );
		$this->assertCount( 1, $result->errors );
		$this->assertSame( 'Unknown WP_Error', $result->first_error() );
	}

	public function test_from_wp_error_carries_data_payload(): void {
		$result = Journey_Result::from_wp_error(
			new WP_Error( 'x', 'y' ),
			[ 'attempted_id' => 99 ]
		);
		$this->assertSame( 99, $result->data['attempted_id'] );
	}

	public function test_to_array_exposes_every_field(): void {
		$result   = Journey_Result::ok(
			[ 'k' => 'v' ],
			[ 'log entry' ],
			42
		);
		$snapshot = $result->to_array();

		$this->assertSame(
			[ 'success', 'data', 'errors', 'logs', 'duration_ms' ],
			array_keys( $snapshot )
		);
		$this->assertTrue( $snapshot['success'] );
		$this->assertSame( [ 'k' => 'v' ], $snapshot['data'] );
		$this->assertSame( [], $snapshot['errors'] );
		$this->assertSame( [ 'log entry' ], $snapshot['logs'] );
		$this->assertSame( 42, $snapshot['duration_ms'] );
	}

	public function test_first_error_returns_null_on_success(): void {
		$this->assertNull( Journey_Result::ok()->first_error() );
	}
}
