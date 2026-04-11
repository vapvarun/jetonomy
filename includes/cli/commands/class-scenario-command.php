<?php
/**
 * wp jetonomy scenario — named end-to-end fixtures.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Scenarios\Scenario_Result;
use Jetonomy\CLI\Scenarios\Scenario_Runner;

defined( 'ABSPATH' ) || exit;

/**
 * QA-facing command surface for the Scenario Runner.
 *
 * Thin formatter — all logic lives in {@see Scenario_Runner} and the
 * individual scenario classes. Subcommands:
 *
 * - `list`     — registered scenarios + descriptions.
 * - `describe` — a scenario's one-liner (no run side effects).
 * - `run`      — provision the scenario end-to-end; optionally clean up after.
 *
 * Successful `run` output uses a table of {@step, success, duration_ms,
 * error} rows, followed by the fixture IDs the scenario produced so the
 * operator can feed them to a follow-up command or a manual QA session.
 */
final class Scenario_Command extends Base_Command {

	/**
	 * List every registered scenario.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated column names.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy scenario list
	 *     wp jetonomy scenario list --format=json
	 */
	public function list( $args, $assoc ): void {
		$runner = new Scenario_Runner();
		$items  = $runner->list();

		if ( empty( $items ) ) {
			\WP_CLI::log( 'No scenarios registered.' );
			return;
		}

		$format = (string) ( $assoc['format'] ?? 'table' );
		$fields = (string) ( $assoc['fields'] ?? 'name,description,class' );
		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Describe a scenario without running it.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Scenario slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy scenario describe space-with-pending-join-request
	 */
	public function describe( $args, $assoc ): void {
		$name   = (string) ( $args[0] ?? '' );
		$runner = new Scenario_Runner();

		if ( ! $runner->has( $name ) ) {
			\WP_CLI::error( sprintf( 'Unknown scenario: %s', $name ) );
			return;
		}

		$class = $runner->class_for( $name );
		if ( null === $class ) {
			\WP_CLI::error( sprintf( 'Unknown scenario: %s', $name ) );
			return;
		}

		$payload = [
			'name'        => $class::name(),
			'description' => $class::description(),
			'class'       => $class,
		];

		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $payload ) );
			return;
		}

		foreach ( $payload as $key => $value ) {
			\WP_CLI::log( sprintf( '%s: %s', $key, (string) $value ) );
		}
	}

	/**
	 * Run a scenario end-to-end.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Scenario slug.
	 *
	 * [--cleanup]
	 * : Tear down fixtures after a successful run.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy scenario run space-with-pending-join-request
	 *     wp jetonomy scenario run notification-delivery-sweep --cleanup
	 *     wp jetonomy scenario run multi-user-voting-thread --format=json
	 */
	public function run( $args, $assoc ): void {
		$name   = (string) ( $args[0] ?? '' );
		$runner = new Scenario_Runner();

		if ( ! $runner->has( $name ) ) {
			\WP_CLI::error( sprintf( 'Unknown scenario: %s', $name ) );
			return;
		}

		$result = $runner->run( $name );
		$this->emit_result( $result, $assoc );

		if ( ! $result->is_success() ) {
			\WP_CLI::error( $result->first_error() ?? 'Scenario failed.' );
			return;
		}

		if ( ! empty( $assoc['cleanup'] ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Running cleanup...' );
			$cleanup = $runner->cleanup( $name, $result->fixtures );
			$this->emit_result( $cleanup, $assoc );
			if ( ! $cleanup->is_success() ) {
				\WP_CLI::warning( $cleanup->first_error() ?? 'Cleanup finished with errors.' );
				return;
			}
		}

		\WP_CLI::success(
			sprintf( 'Scenario %s completed in %dms.', $result->scenario, $result->duration_ms )
		);
	}

	/**
	 * Render a scenario result as either JSON or a step table + fixtures dump.
	 */
	private function emit_result( Scenario_Result $result, array $assoc ): void {
		$format = (string) ( $assoc['format'] ?? 'table' );

		if ( 'json' === $format ) {
			\WP_CLI::log( (string) wp_json_encode( $result->to_array() ) );
			return;
		}

		$rows = [];
		foreach ( $result->steps as $step ) {
			$journey = $step['result'];
			$rows[]  = [
				'step'        => $step['name'],
				'success'     => $journey->is_success() ? 'yes' : 'no',
				'duration_ms' => $step['duration_ms'],
				'error'       => $journey->is_success() ? '' : (string) ( $journey->first_error() ?? '' ),
			];
		}

		if ( ! empty( $rows ) ) {
			\WP_CLI\Utils\format_items( 'table', $rows, 'step,success,duration_ms,error' );
		}

		if ( ! empty( $result->fixtures ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Fixtures:' );
			foreach ( $result->fixtures as $key => $value ) {
				\WP_CLI::log(
					sprintf(
						'  %s: %s',
						$key,
						is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value )
					)
				);
			}
		}
	}
}
