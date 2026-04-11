<?php
/**
 * Abstract base for scenario definitions — owns step-recording plumbing.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Scenarios;

use Jetonomy\CLI\Journey_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for scenario definitions.
 *
 * Concrete scenarios call {@see self::step()} for each journey invocation so
 * the runner gets a uniform, ordered list of {@see Journey_Result}s without
 * each scenario duplicating timing or short-circuit checks. Scenarios still
 * decide what to run; this base only provides the book-keeping.
 *
 * Scenarios use an internal accumulator because PHP closures cannot by-ref
 * mutate an array on a parent class cheaply — instead each run reuses the
 * same instance properties and resets them at the start of {@see self::run()}.
 */
abstract class Abstract_Scenario implements Scenario_Interface {

	/**
	 * Ordered step records captured during the current run or cleanup.
	 *
	 * @var array<int,array{name:string,duration_ms:int,result:Journey_Result}>
	 */
	protected array $steps = [];

	/**
	 * Aggregated error messages captured during the current run or cleanup.
	 *
	 * @var array<int,string>
	 */
	protected array $errors = [];

	/**
	 * Whether a prior step has already failed — subsequent calls to
	 * {@see self::step()} short-circuit once this flips true.
	 *
	 * @var bool
	 */
	protected bool $failed = false;

	/**
	 * Reset the accumulators so the same instance can be reused for
	 * cleanup immediately after run() (or for a second run).
	 */
	protected function reset(): void {
		$this->steps  = [];
		$this->errors = [];
		$this->failed = false;
	}

	/**
	 * Execute one step of the scenario and record the outcome.
	 *
	 * Short-circuits once any prior step has failed so the rest of the
	 * scenario body can stay linear. The callable must return a
	 * {@see Journey_Result}.
	 *
	 * @param string   $name Display name for the step (becomes the first column in the CLI table).
	 * @param callable $fn   Zero-arg callable that returns a Journey_Result.
	 */
	protected function step( string $name, callable $fn ): ?Journey_Result {
		if ( $this->failed ) {
			return null;
		}

		$start  = microtime( true );
		$result = $fn();
		$ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( ! $result instanceof Journey_Result ) {
			$this->failed   = true;
			$fake           = Journey_Result::fail(
				sprintf( 'Step "%s" did not return a Journey_Result.', $name )
			);
			$this->steps[]  = [
				'name'        => $name,
				'duration_ms' => $ms,
				'result'      => $fake,
			];
			$this->errors[] = $fake->first_error() ?? 'unknown error';
			return null;
		}

		$this->steps[] = [
			'name'        => $name,
			'duration_ms' => $ms,
			'result'      => $result,
		];

		if ( ! $result->is_success() ) {
			$this->failed = true;
			foreach ( $result->errors as $err ) {
				$this->errors[] = sprintf( '[%s] %s', $name, $err );
			}
			return null;
		}

		return $result;
	}

	/**
	 * Build a finalized {@see Scenario_Result} from the current accumulator state.
	 *
	 * @param array<string,mixed> $fixtures Fixture IDs to carry on the result.
	 * @param float               $start    microtime(true) when the scenario started.
	 */
	protected function finalize( array $fixtures, float $start ): Scenario_Result {
		return new Scenario_Result(
			static::name(),
			! $this->failed,
			$this->steps,
			$fixtures,
			(int) round( ( microtime( true ) - $start ) * 1000 ),
			$this->errors
		);
	}
}
