<?php
/**
 * Registry + executor for named scenarios.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Scenarios;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry and executor for named scenarios.
 *
 * Callers register a scenario class-string against its slug (or rely on the
 * built-in defaults seeded in {@see self::bootstrap_defaults()}), then invoke
 * {@see self::run()} to provision the fixtures. The runner owns no business
 * logic of its own — it instantiates the registered class, delegates to
 * {@see Scenario_Interface::run()}, and surfaces the returned
 * {@see Scenario_Result} unchanged.
 *
 * Unknown slugs and failed instantiation produce a structured failure result
 * rather than an exception so callers can uniformly render the error via the
 * same pipeline used for successful runs.
 */
final class Scenario_Runner {

	/**
	 * Registry of scenario slug → fully qualified class name.
	 *
	 * @var array<string,class-string<Scenario_Interface>>
	 */
	private array $registry = [];

	/**
	 * Seed the registry with the built-in scenarios when no registry is injected.
	 *
	 * @param array<string,class-string<Scenario_Interface>>|null $registry Pre-populated registry override (useful in tests).
	 */
	public function __construct( ?array $registry = null ) {
		if ( null === $registry ) {
			$this->bootstrap_defaults();
			return;
		}
		foreach ( $registry as $slug => $class ) {
			$this->register( (string) $slug, $class );
		}
	}

	/**
	 * Register a scenario class against a slug.
	 *
	 * Silently ignores classes that are not loadable or do not implement
	 * {@see Scenario_Interface} so an in-flight autoloader miss can't break
	 * the whole dispatcher.
	 *
	 * @param string $slug  Scenario slug (must match {@see Scenario_Interface::name()}).
	 * @param string $class Fully qualified class name implementing Scenario_Interface.
	 */
	public function register( string $slug, string $class ): void {
		if ( '' === $slug || '' === $class ) {
			return;
		}
		if ( ! class_exists( $class ) ) {
			return;
		}
		if ( ! is_subclass_of( $class, Scenario_Interface::class ) ) {
			return;
		}
		/** @var class-string<Scenario_Interface> $class */
		$this->registry[ $slug ] = $class;
	}

	/**
	 * Return a lightweight listing of every registered scenario.
	 *
	 * @return array<int,array{name:string,description:string,class:string}>
	 */
	public function list(): array {
		$items = [];
		foreach ( $this->registry as $slug => $class ) {
			$items[] = [
				'name'        => $slug,
				'description' => $class::description(),
				'class'       => $class,
			];
		}
		return $items;
	}

	/**
	 * Whether the runner knows about the given slug.
	 */
	public function has( string $slug ): bool {
		return isset( $this->registry[ $slug ] );
	}

	/**
	 * Look up the class registered under a slug.
	 *
	 * @return class-string<Scenario_Interface>|null
	 */
	public function class_for( string $slug ): ?string {
		return $this->registry[ $slug ] ?? null;
	}

	/**
	 * Execute a registered scenario.
	 *
	 * @param string              $slug    Scenario slug to run.
	 * @param array<string,mixed> $options Optional per-scenario options.
	 */
	public function run( string $slug, array $options = [] ): Scenario_Result {
		$start = microtime( true );

		if ( ! isset( $this->registry[ $slug ] ) ) {
			return new Scenario_Result(
				$slug,
				false,
				[],
				[],
				$this->duration_ms( $start ),
				[ sprintf( 'Unknown scenario: %s', $slug ) ]
			);
		}

		$class = $this->registry[ $slug ];
		try {
			$instance = new $class();
			return $instance->run( $options );
		} catch ( \Throwable $e ) {
			return new Scenario_Result(
				$slug,
				false,
				[],
				[],
				$this->duration_ms( $start ),
				[ sprintf( 'Uncaught %s in %s::run(): %s', get_class( $e ), $class, $e->getMessage() ) ]
			);
		}
	}

	/**
	 * Tear down fixtures previously produced by a scenario run.
	 *
	 * @param string                           $slug     Scenario slug.
	 * @param array<string,int|array<int,int>> $fixtures Fixture IDs from the prior run.
	 */
	public function cleanup( string $slug, array $fixtures ): Scenario_Result {
		$start = microtime( true );

		if ( ! isset( $this->registry[ $slug ] ) ) {
			return new Scenario_Result(
				$slug,
				false,
				[],
				$fixtures,
				$this->duration_ms( $start ),
				[ sprintf( 'Unknown scenario: %s', $slug ) ]
			);
		}

		$class = $this->registry[ $slug ];
		try {
			$instance = new $class();
			return $instance->cleanup( $fixtures );
		} catch ( \Throwable $e ) {
			return new Scenario_Result(
				$slug,
				false,
				[],
				$fixtures,
				$this->duration_ms( $start ),
				[ sprintf( 'Uncaught %s in %s::cleanup(): %s', get_class( $e ), $class, $e->getMessage() ) ]
			);
		}
	}

	/**
	 * Seed the registry with the five built-in scenarios. New scenarios go here.
	 */
	private function bootstrap_defaults(): void {
		$defaults = [
			Space_With_Pending_Join_Request::class,
			Post_With_Flags_For_Moderation::class,
			Multi_User_Voting_Thread::class,
			Full_Membership_Approval_Flow::class,
			Notification_Delivery_Sweep::class,
		];
		foreach ( $defaults as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			if ( ! is_subclass_of( $class, Scenario_Interface::class ) ) {
				continue;
			}
			$this->registry[ $class::name() ] = $class;
		}
	}

	/**
	 * Elapsed time in whole milliseconds since the given start (microtime(true)).
	 */
	private function duration_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
