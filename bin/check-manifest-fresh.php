<?php
/**
 * Manifest freshness gate: the manifest must describe the plugin as it is now.
 *
 * Every coverage number, every "does this already exist" lookup and every
 * audit starts from audit/manifest.json. When the code moves and the manifest
 * does not, those checks keep passing against a plugin that no longer exists:
 * in 2.0.2 the coverage report was still measured against the 2.0.0 manifest,
 * and a route that changed from GET to POST was caught by nothing.
 *
 * Compares the code with the manifest, both ways:
 *   - hooks    every literal do_action / apply_filters( 'jetonomy_*' ) in
 *              includes/ and templates/ vs manifest hooks_fired
 *   - ajax     every add_action( 'wp_ajax_jetonomy_*' ) vs manifest ajax
 *   - cron     every manifest cron hook still appears in the code
 *   - rest     every register_rest_route path vs manifest rest.endpoints
 *   - methods  (only under `wp eval-file`) the live jetonomy/v1 routes and
 *              their HTTP methods vs the manifest, exactly
 *
 * Usage:
 *   php bin/check-manifest-fresh.php                  # static (pre-commit)
 *   wp eval-file bin/check-manifest-fresh.php         # static + live routes
 *
 * Exit 1 on any difference. Fix by updating audit/manifest.json in the same
 * commit as the code change, never by loosening this check.
 *
 * @package Jetonomy
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.WP.GlobalVariablesOverride

$jt_root     = dirname( __DIR__ );
$jt_manifest = json_decode( (string) file_get_contents( $jt_root . '/audit/manifest.json' ), true );
if ( ! is_array( $jt_manifest ) ) {
	fwrite( STDERR, "manifest-fresh: audit/manifest.json is missing or not valid JSON\n" );
	exit( 2 );
}

/**
 * Concatenated source of every PHP file under the given directories.
 *
 * @param string[] $dirs Directories relative to the plugin root.
 */
$jt_source = static function ( array $dirs ) use ( $jt_root ): string {
	$out = '';
	foreach ( $dirs as $dir ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $jt_root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			// includes/qa is the developer test harness, not the plugin.
			if ( 'php' === $file->getExtension() && ! str_contains( $file->getPathname(), '/includes/qa/' ) ) {
				$out .= file_get_contents( $file->getPathname() ) . "\n";
			}
		}
	}
	return $out;
};

$jt_code     = $jt_source( array( 'includes', 'templates' ) );
$jt_problems = array();

/**
 * Record both directions of a set difference.
 *
 * $still_exists decides the manifest -> code direction. A name fired through
 * a class constant or a scheduler is not a literal do_action() call, but it
 * is still in the code; only a name that appears nowhere is gone.
 */
$jt_compare = static function ( string $what, array $in_code, array $in_manifest, ?callable $still_exists = null ) use ( &$jt_problems ): void {
	$in_code     = array_unique( $in_code );
	$in_manifest = array_unique( $in_manifest );
	foreach ( array_diff( $in_code, $in_manifest ) as $name ) {
		$jt_problems[] = "{$what}: in the code, missing from the manifest: {$name}";
	}
	foreach ( array_diff( $in_manifest, $in_code ) as $name ) {
		if ( ! $still_exists || ! $still_exists( $name ) ) {
			$jt_problems[] = "{$what}: in the manifest, no longer in the code: {$name}";
		}
	}
};
$jt_named = static fn( string $name ): bool => str_contains( $jt_code, "'" . $name . "'" );

// Hooks the plugin fires.
preg_match_all( "/\\b(?:do_action|apply_filters|do_action_ref_array|apply_filters_ref_array|do_action_deprecated|apply_filters_deprecated)\\(\\s*'(jetonomy_[a-z0-9_]+)'/", $jt_code, $m );
$jt_compare( 'hook', $m[1], array_column( (array) ( $jt_manifest['hooks_fired'] ?? array() ), 'name' ), $jt_named );

// AJAX actions.
preg_match_all( "/add_action\\(\\s*'wp_ajax_(?:nopriv_)?(jetonomy_[a-z0-9_]+)'/", $jt_code, $m );
$jt_compare( 'ajax', $m[1], array_column( (array) ( $jt_manifest['ajax'] ?? array() ), 'action' ), $jt_named );

// Cron hooks: every documented one must still exist.
foreach ( (array) ( $jt_manifest['cron'] ?? array() ) as $jt_cron ) {
	if ( ! str_contains( $jt_code, "'" . $jt_cron['hook'] . "'" ) && ! str_contains( $jt_code, "'" . $jt_cron['hook'] ) ) {
		$jt_problems[] = "cron: in the manifest, no longer in the code: {$jt_cron['hook']}";
	}
}

// REST route paths written as literals must be documented. Routes built in a
// loop are not literals here; the live check below covers both directions.
$jt_manifest_routes = array_column( (array) ( $jt_manifest['rest']['endpoints'] ?? array() ), 'route' );
preg_match_all( "/register_rest_route\\(\\s*\\\$[a-z_>-]+\\s*,\\s*'([^']+)'/", $jt_code, $m );
foreach ( array_diff( array_unique( $m[1] ), $jt_manifest_routes, array( '/' ) ) as $jt_route ) {
	$jt_problems[] = "rest route: in the code, missing from the manifest: {$jt_route}";
}

// CLAUDE.md's inventory line is the first thing every session reads; its
// counts must agree with the manifest (which the checks above tie to code).
$jt_claude = (string) @file_get_contents( $jt_root . '/CLAUDE.md' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
if ( '' !== $jt_claude ) {
	$jt_eps    = (array) ( $jt_manifest['rest']['endpoints'] ?? array() );
	$jt_hooks  = (array) ( $jt_manifest['hooks_fired'] ?? array() );
	$jt_counts = array(
		'REST routes'                                 => count( array_unique( array_column( $jt_eps, 'route' ) ) ) + 1, // + the namespace index.
		'method-endpoints'                            => array_sum( array_map( static fn( $e ) => count( (array) $e['methods'] ), $jt_eps ) ) + 1,
		'AJAX handlers'                               => count( (array) ( $jt_manifest['ajax'] ?? array() ) ),
		'hooks fired'                                 => count( $jt_hooks ),
		'actions,'                                    => count( array_filter( $jt_hooks, static fn( $h ) => 'action' === ( $h['type'] ?? '' ) ) ),
		'filters)'                                    => count( array_filter( $jt_hooks, static fn( $h ) => 'filter' === ( $h['type'] ?? '' ) ) ),
		'tables'                                      => count( (array) ( $jt_manifest['tables'] ?? array() ) ),
		'capabilities'                                => count( (array) ( $jt_manifest['capabilities'] ?? array() ) ),
		'WP-CLI command groups'                       => count( (array) ( $jt_manifest['wp_cli'] ?? array() ) ),
		'cron hooks'                                  => count( (array) ( $jt_manifest['cron'] ?? array() ) ),
	);
	foreach ( $jt_counts as $jt_label => $jt_want ) {
		if ( preg_match( '/(\d+) ' . preg_quote( $jt_label, '/' ) . '/', $jt_claude, $jt_cm ) && (int) $jt_cm[1] !== $jt_want ) {
			$jt_problems[] = "CLAUDE.md says {$jt_cm[1]} {$jt_label}, the manifest has {$jt_want}";
		}
	}
}

// Live routes and methods, when WordPress is loaded.
if ( function_exists( 'rest_get_server' ) ) {
	$jt_ns   = '/' . trim( (string) ( $jt_manifest['rest']['namespace'] ?? 'jetonomy/v1' ), '/' );
	$jt_live = array();
	foreach ( rest_get_server()->get_routes() as $jt_route => $jt_handlers ) {
		if ( ! str_starts_with( $jt_route, $jt_ns . '/' ) ) {
			continue;
		}
		$jt_path = substr( $jt_route, strlen( $jt_ns ) );
		foreach ( $jt_handlers as $jt_handler ) {
			foreach ( array_keys( (array) $jt_handler['methods'] ) as $jt_method ) {
				$jt_live[] = $jt_method . ' ' . $jt_path;
			}
		}
	}
	$jt_documented = array();
	foreach ( (array) ( $jt_manifest['rest']['endpoints'] ?? array() ) as $jt_ep ) {
		foreach ( (array) $jt_ep['methods'] as $jt_method ) {
			$jt_documented[] = strtoupper( $jt_method ) . ' ' . $jt_ep['route'];
		}
	}
	// Pro registers routes in the same namespace and documents them in its own
	// manifest. Those vouch for a live route, but cannot be required: a Pro
	// extension that is switched off registers nothing.
	$jt_pro_manifest = dirname( $jt_root ) . '/jetonomy-pro/audit/manifest.json';
	if ( defined( 'JETONOMY_PRO_VERSION' ) && is_file( $jt_pro_manifest ) ) {
		$jt_pro = json_decode( (string) file_get_contents( $jt_pro_manifest ), true );
		foreach ( (array) ( $jt_pro['rest']['endpoints'] ?? array() ) as $jt_ep ) {
			foreach ( (array) $jt_ep['methods'] as $jt_method ) {
				$jt_live = array_values( array_diff( $jt_live, array( strtoupper( $jt_method ) . ' ' . $jt_ep['route'] ) ) );
			}
		}
	}
	$jt_compare( 'rest method', $jt_live, $jt_documented );
}

if ( $jt_problems ) {
	sort( $jt_problems );
	echo implode( "\n", $jt_problems ), "\n";
	printf( "\nmanifest-fresh: %d difference(s). Update audit/manifest.json in the same commit as the code.\n", count( $jt_problems ) );
	exit( 1 );
}

echo 'manifest-fresh: OK (' . ( function_exists( 'rest_get_server' ) ? 'static + live routes' : 'static' ) . ")\n";
exit( 0 );
