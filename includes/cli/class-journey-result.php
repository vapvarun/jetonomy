<?php
/**
 * Structured result returned by every journey method.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable DTO describing the outcome of a single journey call.
 *
 * Journey classes (pure PHP, zero WP_CLI coupling) return a Journey_Result
 * so the same code path can drive both the CLI command layer and PHPUnit
 * assertions. Command wrappers format `success`/`data`/`errors` for the
 * terminal; tests read the same fields and assert on them directly.
 *
 * Scenario definitions also chain journeys by checking `is_success()` after
 * each step and short-circuiting on the first failure, so the DTO doubles as
 * a composition primitive.
 */
final class Journey_Result {

	public bool $success;

	/**
	 * @var array<string,mixed>
	 */
	public array $data;

	/**
	 * @var array<int,string> Human-readable error messages.
	 */
	public array $errors;

	/**
	 * @var array<int,string> Optional progress breadcrumbs, emitted by long-running journeys.
	 */
	public array $logs;

	public int $duration_ms;

	private function __construct( bool $success, array $data, array $errors, array $logs, int $duration_ms ) {
		$this->success     = $success;
		$this->data        = $data;
		$this->errors      = $errors;
		$this->logs        = $logs;
		$this->duration_ms = $duration_ms;
	}

	/**
	 * Successful result.
	 *
	 * @param array<string,mixed> $data
	 * @param array<int,string>   $logs
	 */
	public static function ok( array $data = [], array $logs = [], int $duration_ms = 0 ): self {
		return new self( true, $data, [], $logs, $duration_ms );
	}

	/**
	 * Failed result.
	 *
	 * @param string|array<int,string> $error
	 * @param array<string,mixed>      $data
	 * @param array<int,string>        $logs
	 */
	public static function fail( $error, array $data = [], array $logs = [], int $duration_ms = 0 ): self {
		$errors = is_array( $error )
			? array_values( array_map( 'strval', $error ) )
			: [ (string) $error ];
		return new self( false, $data, $errors, $logs, $duration_ms );
	}

	/**
	 * Convert a WP_Error into a Journey_Result for uniform downstream handling.
	 *
	 * @param \WP_Error           $err  WordPress error object.
	 * @param array<string,mixed> $data Optional payload to carry alongside the error.
	 */
	public static function from_wp_error( \WP_Error $err, array $data = [] ): self {
		$messages = [];
		foreach ( $err->get_error_codes() as $code ) {
			foreach ( (array) $err->get_error_messages( $code ) as $msg ) {
				$messages[] = sprintf( '[%s] %s', $code, $msg );
			}
		}
		if ( empty( $messages ) ) {
			$messages[] = 'Unknown WP_Error';
		}
		return new self( false, $data, $messages, [], 0 );
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
		return [
			'success'     => $this->success,
			'data'        => $this->data,
			'errors'      => $this->errors,
			'logs'        => $this->logs,
			'duration_ms' => $this->duration_ms,
		];
	}
}
