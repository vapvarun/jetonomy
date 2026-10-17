<?php
/**
 * Dump the live REST route registry with the authority each route declares.
 *
 * Run with: wp eval-file bin/route-authority-scan.php [free|pro]
 *
 * Seeds audit/route-authority.json (Free) and jetonomy-pro/audit/route-authority.json
 * (Pro). The output is what is registered right now and what each
 * permission_callback resolves to; the audience in the manifest is decided by
 * reading that callback, not by trusting this dump.
 *
 * RouteAuthorityTest (Free) and RouteAuthorityProTest (tests/pro) load this
 * file for jetonomy_route_authority_live(), so the scan and the gate can never
 * disagree about what "the live registry" means.
 *
 * Route ownership is decided by the file the route's handler lives in, not by
 * its namespace: Pro registers most of its routes inside jetonomy/v1.
 *
 * Most Jetonomy permission callbacks are closures built by a factory
 * (REST_Auth::auth_mutation(), Pro's Extension::rest_auth_mutation() /
 * rest_permission()). A closure's name says nothing, so each one is described
 * by the factory that built it plus the capabilities and options it captured -
 * a change to either is a change to the route's authority and shows up as a
 * manifest mismatch. Inline closures are described by their own source.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jetonomy_route_authority_live' ) ) {

	/**
	 * Short, stable class label: drops the plugin namespace roots.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string
	 */
	function jetonomy_route_authority_class_label( string $class_name ): string {
		foreach ( array( 'Jetonomy_Pro\\Extensions\\', 'Jetonomy_Pro\\', 'Jetonomy\\API\\', 'Jetonomy\\' ) as $root ) {
			if ( 0 === strpos( $class_name, $root ) ) {
				return substr( $class_name, strlen( $root ) );
			}
		}
		return $class_name;
	}

	/**
	 * Source of a closure, comments stripped and whitespace collapsed.
	 *
	 * @param ReflectionFunction $ref Closure reflection.
	 * @return string
	 */
	function jetonomy_route_authority_closure_source( ReflectionFunction $ref ): string {
		$file  = (string) $ref->getFileName();
		$lines = file( $file );
		if ( false === $lines ) {
			return '';
		}
		$code   = '<?php ' . implode( '', array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
		$out    = '';
		$depth  = 0;
		$arrow  = false;
		$inside = false;
		foreach ( token_get_all( $code ) as $token ) {
			$id   = is_array( $token ) ? $token[0] : null;
			$text = is_array( $token ) ? $token[1] : $token;
			if ( ! $inside ) {
				if ( T_FUNCTION === $id || T_FN === $id ) {
					$inside = true;
					$arrow  = T_FN === $id;
					$out    = $text;
				}
				continue;
			}
			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				continue;
			}
			// An arrow function has no braces: it ends at the first unbalanced
			// closer or separator of the expression it sits in.
			if ( $arrow && 0 === $depth && in_array( $text, array( ',', ';', ')', ']' ), true ) ) {
				break;
			}
			$out .= ( T_WHITESPACE === $id ) ? ' ' : $text;
			if ( in_array( $text, array( '{', '(', '[' ), true ) || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				++$depth;
			} elseif ( in_array( $text, array( '}', ')', ']' ), true ) && 0 === --$depth && ! $arrow && '}' === $text ) {
				break;
			}
		}
		return trim( (string) preg_replace( '/\s+/', ' ', $out ) );
	}

	/**
	 * Describe a permission callback by the authority it carries.
	 *
	 * @param mixed $cb permission_callback as registered.
	 * @return string
	 */
	function jetonomy_route_authority_describe( $cb ): string {
		if ( null === $cb ) {
			return 'NONE';
		}
		if ( is_string( $cb ) ) {
			return ltrim( $cb, '\\' );
		}
		if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) ) {
			return jetonomy_route_authority_class_label( is_object( $cb[0] ) ? get_class( $cb[0] ) : ltrim( (string) $cb[0], '\\' ) ) . '::' . $cb[1];
		}
		if ( ! $cb instanceof Closure ) {
			return 'UNKNOWN';
		}

		$ref     = new ReflectionFunction( $cb );
		$scope   = $ref->getClosureScopeClass();
		$statics = $ref->getStaticVariables();
		$where   = 'Closure';

		// The method that built the closure: the factory for REST_Auth / Pro helpers.
		if ( $scope ) {
			foreach ( $scope->getMethods() as $method ) {
				if ( $method->getFileName() === $ref->getFileName() && $method->getStartLine() <= $ref->getStartLine() && $method->getEndLine() >= $ref->getEndLine() ) {
					$where = jetonomy_route_authority_class_label( $method->getDeclaringClass()->getName() ) . '::' . $method->getName();
					break;
				}
			}
		}

		$args = array();
		if ( array_key_exists( 'caps', $statics ) ) {
			$args[] = 'caps=' . implode( '|', array_map( 'strval', (array) $statics['caps'] ) );
		}
		if ( array_key_exists( 'opts', $statics ) ) {
			$opts = (array) $statics['opts'];
			ksort( $opts );
			$keys = array();
			foreach ( $opts as $key => $value ) {
				// Messages are translated at registration; only the switches carry authority.
				if ( 'forbidden_message' === $key ) {
					continue;
				}
				$keys[] = is_bool( $value ) ? $key . '=' . ( $value ? 'true' : 'false' ) : $key;
			}
			$args[] = 'opts=' . implode( ',', $keys );
		}
		if ( array_key_exists( 'check', $statics ) ) {
			$args[] = 'check=' . jetonomy_route_authority_describe( $statics['check'] );
		}

		if ( $args ) {
			return $where . '(' . implode( '; ', $args ) . ')';
		}

		// Factory-less closure (or a factory that captured nothing): its source is its authority.
		return $where . ' ' . jetonomy_route_authority_closure_source( $ref );
	}

	/**
	 * Which plugin a route belongs to, by the file its handler lives in.
	 *
	 * @param mixed $callback The route's handler callback.
	 * @return string free|pro|other
	 */
	function jetonomy_route_authority_owner( $callback ): string {
		try {
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$ref = new ReflectionMethod( $callback[0], (string) $callback[1] );
			} elseif ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				$ref = new ReflectionFunction( $callback );
			} else {
				return 'other';
			}
		} catch ( ReflectionException $e ) {
			return 'other';
		}

		$file = (string) realpath( (string) $ref->getFileName() );
		if ( defined( 'JETONOMY_PRO_DIR' ) && 0 === strpos( $file, rtrim( (string) realpath( JETONOMY_PRO_DIR ), '/' ) . '/' ) ) {
			return 'pro';
		}
		if ( defined( 'JETONOMY_DIR' ) && 0 === strpos( $file, rtrim( (string) realpath( JETONOMY_DIR ), '/' ) . '/' ) ) {
			return 'free';
		}
		return 'other';
	}

	/**
	 * The live Jetonomy routes owned by one plugin, keyed "METHODS /route".
	 *
	 * @param string $owner free|pro.
	 * @return array<string, array{route:string, methods:string[], permission_callback:string, always_true:bool, object_params:string[], callback:mixed}>
	 */
	function jetonomy_route_authority_live( string $owner ): array {
		$out = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			$trimmed = ltrim( $route, '/' );
			if ( 0 !== strpos( $trimmed, 'jetonomy/v1/' ) && 0 !== strpos( $trimmed, 'jetonomy-pro/v1/' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( jetonomy_route_authority_owner( $handler['callback'] ?? null ) !== $owner ) {
					continue;
				}

				$methods = array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) );
				$cb      = $handler['permission_callback'] ?? null;
				$desc    = jetonomy_route_authority_describe( $cb );

				preg_match_all( '/\(\?P<([a-z_]*id)>/', $route, $params );

				$out[ implode( ',', $methods ) . ' ' . $route ] = array(
					'route'               => $route,
					'methods'             => $methods,
					'permission_callback' => $desc,
					'always_true'         => in_array( $desc, array( '__return_true', 'REST_Auth::auth_public_write' ), true ),
					'object_params'       => $params[1],
					'callback'            => $cb,
				);
			}
		}

		ksort( $out );
		return $out;
	}
}

if ( ! defined( 'JETONOMY_ROUTE_AUTHORITY_LIB' ) ) {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CLI argument.
	$jetonomy_scan_owner = isset( $args[0] ) ? (string) $args[0] : 'free';
	$jetonomy_scan_out   = array();
	foreach ( jetonomy_route_authority_live( $jetonomy_scan_owner ) as $jetonomy_scan_key => $jetonomy_scan_row ) {
		unset( $jetonomy_scan_row['callback'], $jetonomy_scan_row['route'] );
		$jetonomy_scan_out[ $jetonomy_scan_key ] = $jetonomy_scan_row;
	}
	echo wp_json_encode( $jetonomy_scan_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
