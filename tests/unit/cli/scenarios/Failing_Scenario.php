<?php
namespace Jetonomy\Tests\Unit\CLI\Scenarios;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Scenarios\Abstract_Scenario;
use Jetonomy\CLI\Scenarios\Scenario_Result;

/**
 * Fixture scenario used by ScenarioRunnerTest::test_runner_short_circuits_on_first_failure().
 *
 * The second step deliberately returns a failed Journey_Result so the runner
 * records it and skips the third step — lets us assert on the short-circuit
 * behaviour without coupling to any real journey's failure modes.
 */
final class Failing_Scenario extends Abstract_Scenario {

	public static function name(): string {
		return 'failing-scenario';
	}

	public static function description(): string {
		return 'Test fixture — fails on step 2 to exercise runner short-circuit.';
	}

	public function run( array $options = [] ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$this->step(
			'ok-first',
			static fn (): Journey_Result => Journey_Result::ok( [ 'n' => 1 ] )
		);

		$this->step(
			'fail-second',
			static fn (): Journey_Result => Journey_Result::fail( 'intentional failure' )
		);

		$this->step(
			'never-run-third',
			static fn (): Journey_Result => Journey_Result::ok( [ 'n' => 3 ] )
		);

		return $this->finalize( [], $start );
	}

	public function cleanup( array $fixtures ): Scenario_Result {
		$this->reset();
		return $this->finalize( $fixtures, microtime( true ) );
	}
}
