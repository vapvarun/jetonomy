<?php
namespace Jetonomy\Tests\Unit\Trust;

use WP_UnitTestCase;
use Jetonomy\Trust\Trust_Evaluator;

class TrustEvaluatorTest extends WP_UnitTestCase {

	public function test_new_user_is_level_zero(): void {
		$level = Trust_Evaluator::evaluate_level( [] );
		$this->assertEquals( 0, $level );
	}

	public function test_all_zeros_is_level_zero(): void {
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 0,
			'days_active'      => 0,
			'reputation'       => 0,
			'replies_received' => 0,
		] );
		$this->assertEquals( 0, $level );
	}

	public function test_meets_level_1_requirements(): void {
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 5,
			'days_active'      => 3,
			'reputation'       => 0,
			'replies_received' => 10,
		] );
		$this->assertEquals( 1, $level );
	}

	public function test_partial_level_1_stays_at_zero(): void {
		// Has post count and days but not replies_received.
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 5,
			'days_active'      => 3,
			'reputation'       => 0,
			'replies_received' => 9,
		] );
		$this->assertEquals( 0, $level );
	}

	public function test_partial_level_1_missing_days_active(): void {
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 10,
			'days_active'      => 2,
			'reputation'       => 0,
			'replies_received' => 15,
		] );
		$this->assertEquals( 0, $level );
	}

	public function test_meets_level_2_requirements(): void {
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 30,
			'days_active'      => 20,
			'reputation'       => 50,
			'replies_received' => 10,
		] );
		$this->assertEquals( 2, $level );
	}

	public function test_partial_level_2_stays_at_level_1(): void {
		// Meets level 1 but not level 2 (reputation too low).
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 30,
			'days_active'      => 20,
			'reputation'       => 49,
			'replies_received' => 10,
		] );
		$this->assertEquals( 1, $level );
	}

	public function test_partial_level_2_missing_days_active(): void {
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 30,
			'days_active'      => 19,
			'reputation'       => 100,
			'replies_received' => 20,
		] );
		$this->assertEquals( 1, $level );
	}

	public function test_meets_level_3_requirements(): void {
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 100,
			'days_active'      => 60,
			'reputation'       => 200,
			'replies_received' => 10,
		] );
		$this->assertEquals( 3, $level );
	}

	public function test_partial_level_3_stays_at_level_2(): void {
		// Meets level 2 but not level 3 (reputation too low).
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 100,
			'days_active'      => 60,
			'reputation'       => 199,
			'replies_received' => 20,
		] );
		$this->assertEquals( 2, $level );
	}

	public function test_never_auto_promotes_above_level_3(): void {
		// Even extreme stats should not exceed level 3.
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 99999,
			'days_active'      => 9999,
			'reputation'       => 999999,
			'replies_received' => 99999,
		] );
		$this->assertLessThanOrEqual( 3, $level );
	}

	public function test_exact_level_3_boundaries(): void {
		// Exactly at each threshold for level 3.
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 100,
			'days_active'      => 60,
			'reputation'       => 200,
			'replies_received' => 10,
		] );
		$this->assertEquals( 3, $level );
	}

	public function test_just_below_level_3_days_active(): void {
		$level = Trust_Evaluator::evaluate_level( [
			'post_count'       => 100,
			'days_active'      => 59,
			'reputation'       => 200,
			'replies_received' => 10,
		] );
		$this->assertEquals( 2, $level );
	}

	public function test_missing_keys_default_to_zero(): void {
		// Passing an empty array should default all values to 0 and return level 0.
		$level = Trust_Evaluator::evaluate_level( [] );
		$this->assertEquals( 0, $level );
	}
}
