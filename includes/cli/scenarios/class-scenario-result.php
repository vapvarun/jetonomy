<?php
/**
 * Aggregated result DTO returned by every scenario run or cleanup.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Scenarios;

use Jetonomy\CLI\Journey_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Composition-friendly DTO describing the outcome of a scenario execution.
 *
 * Each step is a {@see Journey_Result} captured alongside the step's label
 * and its wall-clock duration. Callers (commands and tests) read the same
 * fields and short-circuit on {@see self::$success}. Serializable via
 * {@see self::to_array()} for JSON output and test snapshots.
 */
final class Scenario_Result {

	/**
	 * Scenario slug — mirrors {@see Scenario_Interface::name()}.
	 *
	 * @var string
	 */
	public string $scenario;

	/**
	 * Overall success — logical AND of every recorded step's success.
	 *
	 * @var bool
	 */
	public bool $success;

	/**
	 * Ordered list of step records.
	 *
	 * Each entry has keys: `name` (string), `duration_ms` (int),
	 * `result` ({@see Journey_Result}).
	 *
	 * @var array<int,array{name:string,duration_ms:int,result:Journey_Result}>
	 */
	public array $steps;

	/**
	 * Fixture IDs produced by the scenario run, keyed by semantic name.
	 *
	 * Example: `{ space_id: 42, post_id: 105, reporter_ids: [3, 4] }`.
	 *
	 * @var array<string,mixed>
	 */
	public array $fixtures;

	/**
	 * Total wall-clock duration in whole milliseconds.
	 *
	 * @var int
	 */
	public int $duration_ms;

	/**
	 * Aggregated error messages from every failed step (in order).
	 *
	 * @var array<int,string>
	 */
	public array $errors;

	/**
	 * @param string                                                              $scenario    Scenario slug.
	 * @param bool                                                                $success     Overall success flag.
	 * @param array<int,array{name:string,duration_ms:int,result:Journey_Result}> $steps       Ordered step records.
	 * @param array<string,mixed>                                                 $fixtures    Fixture IDs keyed by name.
	 * @param int                                                                 $duration_ms Total wall-clock duration in ms.
	 * @param array<int,string>                                                   $errors      Aggregated error messages.
	 */
	public function __construct(
		string $scenario,
		bool $success,
		array $steps,
		array $fixtures,
		int $duration_ms,
		array $errors
	) {
		$this->scenario    = $scenario;
		$this->success     = $success;
		$this->steps       = $steps;
		$this->fixtures    = $fixtures;
		$this->duration_ms = $duration_ms;
		$this->errors      = $errors;
	}

	public function is_success(): bool {
		return $this->success;
	}

	public function first_error(): ?string {
		return $this->errors[0] ?? null;
	}

	/**
	 * Serializable representation for CLI output and test snapshots.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$steps = [];
		foreach ( $this->steps as $step ) {
			$steps[] = [
				'name'        => $step['name'],
				'duration_ms' => $step['duration_ms'],
				'result'      => $step['result']->to_array(),
			];
		}

		return [
			'scenario'    => $this->scenario,
			'success'     => $this->success,
			'steps'       => $steps,
			'fixtures'    => $this->fixtures,
			'duration_ms' => $this->duration_ms,
			'errors'      => $this->errors,
		];
	}
}
