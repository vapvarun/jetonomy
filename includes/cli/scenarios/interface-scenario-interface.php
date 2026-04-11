<?php
/**
 * Contract every scenario definition must implement.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Scenarios;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for a named end-to-end scenario that composes journey calls.
 *
 * A scenario bundles one or more journey calls into a named fixture that QA
 * can spin up with a single command. Each scenario exposes a stable slug
 * ({@see self::name()}) and a one-line description ({@see self::description()})
 * so the runner can list it, and two lifecycle methods — {@see self::run()}
 * and {@see self::cleanup()} — that produce and tear down the fixtures they
 * created. Both lifecycle methods return a {@see Scenario_Result} so the
 * runner can short-circuit on the first failing step.
 */
interface Scenario_Interface {

	/**
	 * Stable slug used by `wp jetonomy scenario run <name>`.
	 *
	 * Must be unique across registered scenarios. Lowercase, hyphen-separated.
	 */
	public static function name(): string;

	/**
	 * One-line description shown by `wp jetonomy scenario list`.
	 */
	public static function description(): string;

	/**
	 * Provision the scenario's fixtures end-to-end.
	 *
	 * @param array<string,mixed> $options Optional per-scenario options (reserved; ignored for now).
	 */
	public function run( array $options = [] ): Scenario_Result;

	/**
	 * Tear down fixtures previously produced by {@see self::run()}.
	 *
	 * @param array<string,int|array<int,int>> $fixtures Fixture IDs returned in Scenario_Result::$fixtures.
	 */
	public function cleanup( array $fixtures ): Scenario_Result;
}
