<?php
/**
 * Records what a test run actually exercised.
 *
 * The static coverage gate counts a route as covered when its path appears in
 * test source. That proves someone typed the path, not that a request reached
 * the route: in 2.0.2 the invite route changed from GET to POST and nothing
 * noticed, and tests calling a route that no longer exists still "passed" on
 * the 404. This records, per run:
 *
 *   - every REST request that reached a route (method + path)
 *   - every REST request that matched NO route, with the test that sent it
 *   - every jetonomy_* hook and wp_ajax_jetonomy_* handler that fired
 *
 * Off unless JETONOMY_COVERAGE_OUT names an output file. Used by the PHPUnit
 * bootstrap and by `wp jetonomy qa-actions`; read by bin/qa-coverage-check.php
 * --runtime. Lives in includes/qa/, which release builds exclude.
 *
 * @package Jetonomy
 */

namespace Jetonomy\QA;

defined( 'ABSPATH' ) || exit;

final class Coverage_Recorder {

	private static string $out = '';

	private static string $test = '';

	/** @var array<string,int> "METHOD /path" => hits */
	private static array $rest = array();

	/** @var array<string,int> hook name => times fired */
	private static array $hooks = array();

	/** @var array<int,array{method:string,path:string,test:string}> Requests in flight, by request object id. */
	private static array $pending = array();

	/** @var array<int,array{method:string,path:string,test:string}> */
	private static array $no_route = array();

	/**
	 * Start recording to $out (once per process).
	 */
	public static function start( string $out ): void {
		if ( '' !== self::$out || '' === $out ) {
			return;
		}
		self::$out = $out;
		add_filter( 'rest_pre_dispatch', array( self::class, 'on_dispatch' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'on_matched' ), PHP_INT_MIN, 3 );
		add_action( 'all', array( self::class, 'on_hook' ) );
		register_shutdown_function( array( self::class, 'write' ) );
	}

	/**
	 * Name the test now running (PHPUnit extension, or a qa-actions phase).
	 */
	public static function set_test( string $name ): void {
		self::$test = $name;
	}

	/**
	 * A request is about to be routed.
	 *
	 * @param mixed            $result  Short-circuit value (passed through).
	 * @param mixed            $server  REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function on_dispatch( $result, $server, $request ) {
		if ( null === $result && $request instanceof \WP_REST_Request ) {
			self::$pending[ spl_object_id( $request ) ] = array(
				'method' => $request->get_method(),
				'path'   => $request->get_route(),
				'test'   => self::$test,
			);
		}
		return $result;
	}

	/**
	 * The request matched a route (fires before permission and validation).
	 *
	 * @param mixed            $response Response so far (passed through).
	 * @param mixed            $handler  Matched handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function on_matched( $response, $handler, $request ) {
		$id = spl_object_id( $request );
		if ( isset( self::$pending[ $id ] ) ) {
			$key                = self::$pending[ $id ]['method'] . ' ' . self::$pending[ $id ]['path'];
			self::$rest[ $key ] = ( self::$rest[ $key ] ?? 0 ) + 1;
			unset( self::$pending[ $id ] );
		}
		return $response;
	}

	/**
	 * Count our hooks as they fire.
	 *
	 * @param string $tag Hook name.
	 */
	public static function on_hook( $tag ): void {
		if ( is_string( $tag ) && ( str_starts_with( $tag, 'jetonomy_' ) || str_starts_with( $tag, 'wp_ajax_jetonomy_' ) ) ) {
			self::$hooks[ $tag ] = ( self::$hooks[ $tag ] ?? 0 ) + 1;
		}
		// A request still pending when the next one starts matched nothing.
		if ( 'rest_pre_dispatch' === $tag ) {
			self::settle_pending();
		}
	}

	/**
	 * Move requests that never reached a route into the no-route list.
	 */
	private static function settle_pending(): void {
		foreach ( self::$pending as $id => $req ) {
			self::$no_route[] = $req;
			unset( self::$pending[ $id ] );
		}
	}

	/**
	 * Write the record (shutdown).
	 */
	public static function write(): void {
		if ( '' === self::$out ) {
			return;
		}
		self::settle_pending();
		ksort( self::$rest );
		ksort( self::$hooks );
		$dir = dirname( self::$out );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$out,
			wp_json_encode(
				array(
					'recorded_at' => gmdate( 'c' ),
					'rest'        => self::$rest,
					'hooks'       => self::$hooks,
					'no_route'    => self::$no_route,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)
		);
	}
}
