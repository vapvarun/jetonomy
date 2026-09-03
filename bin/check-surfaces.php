<?php
/**
 * Jetonomy — runtime surface gate.
 *
 * Every gate the plugin had before 1.9.5 checked PHP *syntax* or *style*.
 * Nothing executed a shortcode, a block, an admin page or a cron callback, so
 * a file that parses cleanly and throws at runtime shipped twice in one
 * release cycle:
 *
 *   1.9.4  sprintf ValueError fataled the Categories admin page on every site
 *   1.9.4  Pro's deactivate() referenced an unloaded class, so Pro could not
 *          be switched off at all
 *
 * Both passed php -l, PHPStan level 5, PHPCS and Plugin Check. Neither could
 * survive being run once. This script runs them.
 *
 * Renders every registered shortcode and block, asserts every cron hook has a
 * callback, asserts REST routes register, and cross-checks the live registries
 * against audit/manifest.json so the catalog cannot drift silently.
 *
 * Usage: wp eval-file bin/check-surfaces.php [--path=/path/to/wp]
 * Exit codes are not available under eval-file, so failures are printed and
 * the final line is machine-readable: SURFACE_GATE=PASS|FAIL.
 *
 * @package Jetonomy
 */

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.

// Written through $GLOBALS on purpose. `wp eval-file` includes this file
// inside a function, so a plain top-level assignment here is a LOCAL, while
// `global $x` inside jt_surface_check() binds the real global - two different
// variables. That mismatch made the summary report "checked 0, failed 0" and
// SURFACE_GATE=PASS while FAIL lines were printing directly above it. A gate
// that cannot fail is worse than no gate.
$GLOBALS['jt_failures'] = array();
$GLOBALS['jt_checked']  = 0;

/**
 * Record a check result.
 *
 * @param string $label What was checked.
 * @param bool   $ok    Whether it passed.
 * @param string $why   Detail shown on failure.
 */
function jt_surface_check( string $label, bool $ok, string $why = '' ): void {
	++$GLOBALS['jt_checked'];
	if ( ! $ok ) {
		$GLOBALS['jt_failures'][] = $label . ( '' !== $why ? ' - ' . $why : '' );
		echo "  FAIL  {$label}" . ( '' !== $why ? " — {$why}" : '' ) . "\n";
		return;
	}
	echo "  ok    {$label}\n";
}

wp_set_current_user( 1 );

$jt_manifest = array();
$jt_mpath    = dirname( __DIR__ ) . '/audit/manifest.json';
if ( is_readable( $jt_mpath ) ) {
	$jt_manifest = json_decode( (string) file_get_contents( $jt_mpath ), true ) ?: array();
}

/**
 * Pull declared names out of a manifest section, whatever key it uses.
 *
 * @param array  $manifest Decoded manifest.
 * @param string $section  Section key.
 * @param string ...$keys  Candidate name keys.
 * @return string[]
 */
function jt_manifest_names( array $manifest, string $section, string ...$keys ): array {
	$out = array();
	foreach ( (array) ( $manifest[ $section ] ?? array() ) as $item ) {
		if ( is_string( $item ) ) {
			$out[] = $item;
			continue;
		}
		if ( ! is_array( $item ) ) {
			continue;
		}
		foreach ( $keys as $k ) {
			if ( ! empty( $item[ $k ] ) ) {
				$out[] = (string) $item[ $k ];
				break;
			}
		}
	}
	return $out;
}

echo "\n== Shortcodes ==\n";
global $shortcode_tags;
$jt_live_sc = array_values( array_filter( array_keys( (array) $shortcode_tags ), static fn( $t ) => 0 === strpos( $t, 'jetonomy' ) ) );
foreach ( $jt_live_sc as $tag ) {
	try {
		ob_start();
		$out = do_shortcode( "[{$tag}]" );
		ob_end_clean();
		jt_surface_check( "[{$tag}] renders without throwing", true );
		unset( $out );
	} catch ( \Throwable $e ) {
		ob_end_clean();
		jt_surface_check( "[{$tag}] renders without throwing", false, get_class( $e ) . ': ' . $e->getMessage() );
	}
}
$jt_declared_sc = jt_manifest_names( $jt_manifest, 'shortcodes', 'tag', 'name' );
$jt_missing_sc  = array_diff( $jt_declared_sc, $jt_live_sc );
jt_surface_check(
	'every shortcode in the manifest is registered',
	empty( $jt_missing_sc ),
	$jt_missing_sc ? 'not registered: ' . implode( ', ', $jt_missing_sc ) : ''
);

echo "\n== Blocks ==\n";
$jt_live_blocks = array_values( array_filter( array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ), static fn( $b ) => 0 === strpos( $b, 'jetonomy/' ) ) );
foreach ( $jt_live_blocks as $block ) {
	try {
		ob_start();
		render_block(
			array(
				'blockName'    => $block,
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		ob_end_clean();
		jt_surface_check( "{$block} renders without throwing", true );
	} catch ( \Throwable $e ) {
		ob_end_clean();
		jt_surface_check( "{$block} renders without throwing", false, get_class( $e ) . ': ' . $e->getMessage() );
	}
}
$jt_declared_blocks = jt_manifest_names( $jt_manifest, 'blocks', 'name', 'title' );
$jt_missing_blocks  = array_diff( $jt_declared_blocks, $jt_live_blocks );
jt_surface_check(
	'every block in the manifest is registered',
	empty( $jt_missing_blocks ),
	$jt_missing_blocks ? 'not registered: ' . implode( ', ', $jt_missing_blocks ) : ''
);

echo "\n== Cron ==\n";
// A scheduled hook with no callback fails silently forever: Action Scheduler /
// WP-Cron fire it, nothing runs, and no error is raised.
foreach ( jt_manifest_names( $jt_manifest, 'cron', 'hook', 'name' ) as $hook ) {
	jt_surface_check( "cron hook {$hook} has a callback", (bool) has_action( $hook ), 'nothing is listening' );
}

echo "\n== REST ==\n";
$jt_routes = array_filter( array_keys( rest_get_server()->get_routes() ), static fn( $r ) => 0 === strpos( $r, '/jetonomy' ) );
jt_surface_check( 'jetonomy REST routes register', count( $jt_routes ) > 0, 'zero routes registered' );
echo '        (' . count( $jt_routes ) . " routes)\n";

echo "\n== Result ==\n";
printf( "checked %d, failed %d\n", (int) $GLOBALS['jt_checked'], count( (array) $GLOBALS['jt_failures'] ) );
foreach ( (array) $GLOBALS['jt_failures'] as $f ) {
	echo "  - {$f}\n";
}
echo 'SURFACE_GATE=' . ( $GLOBALS['jt_failures'] ? 'FAIL' : 'PASS' ) . "\n";
