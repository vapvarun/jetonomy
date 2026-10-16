<?php
/**
 * Runtime coverage gate: what the tests actually ran, not what they mention.
 *
 * Reads the records Coverage_Recorder writes during PHPUnit and
 * `wp jetonomy qa-actions` (JETONOMY_COVERAGE_OUT) and checks them against
 * audit/manifest.json:
 *
 *   1. Stale tests. A REST request that matched no route fails the gate,
 *      unless the test that sent it says `@expects-no-route` in its docblock.
 *      A test calling a route the plugin no longer has (or with the wrong
 *      method) tests nothing; it used to pass on the 404.
 *   2. Must-cover. Every write route (POST/PUT/PATCH/DELETE) and every route
 *      that decides membership, invites, access, moderation, bans or trust
 *      must have been reached. These cannot be baselined.
 *   3. Ratchet. Everything else that is uncovered is listed by name in
 *      audit/qa-runtime-baseline.json. A new uncovered item fails; an item
 *      that became covered must be removed (--update-baseline), so the list
 *      only shrinks and never describes a plugin that no longer exists.
 *
 * Coverage counted: REST endpoints (method + route), AJAX actions (their
 * wp_ajax_ hook fired), fired hooks, cron hooks.
 *
 * Usage:
 *   php bin/qa-runtime-coverage.php --runtime=phpunit.json [--stale-only=qa-actions.json] [--update-baseline]
 *
 * --runtime records decide coverage and the ratchet. Use the PHPUnit run
 * (free plugin only, JETONOMY_TEST_SKIP_PRO=1): its tests build their own
 * fixtures, so the result is the same on every machine and in CI. A
 * qa-actions run depends on the site's data, so it is checked for stale
 * requests only (--stale-only), never counted toward coverage.
 *
 * @package Jetonomy
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

$jt_root = dirname( __DIR__ );
$jt_opts = array(
	'runtime' => array(),
	'stale'   => array(),
	'update'  => false,
);
foreach ( array_slice( $argv, 1 ) as $jt_arg ) {
	if ( str_starts_with( $jt_arg, '--runtime=' ) ) {
		$jt_opts['runtime'] = array_filter( explode( ',', substr( $jt_arg, 10 ) ) );
	} elseif ( str_starts_with( $jt_arg, '--stale-only=' ) ) {
		$jt_opts['stale'] = array_filter( explode( ',', substr( $jt_arg, 13 ) ) );
	} elseif ( '--update-baseline' === $jt_arg ) {
		$jt_opts['update'] = true;
	}
}
if ( ! $jt_opts['runtime'] && ! $jt_opts['stale'] ) {
	fwrite( STDERR, "qa-runtime-coverage: pass --runtime=<record.json> and/or --stale-only=<record.json>\n" );
	exit( 2 );
}

$jt_manifest = json_decode( (string) file_get_contents( $jt_root . '/audit/manifest.json' ), true );
$jt_rest     = array();
$jt_hooks    = array();
$jt_noroute  = array();
foreach ( array_merge( $jt_opts['runtime'], $jt_opts['stale'] ) as $jt_file ) {
	$jt_rec = json_decode( (string) @file_get_contents( $jt_file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! is_array( $jt_rec ) ) {
		fwrite( STDERR, "qa-runtime-coverage: no record at {$jt_file} (did the run set JETONOMY_COVERAGE_OUT?)\n" );
		exit( 2 );
	}
	$jt_noroute = array_merge( $jt_noroute, (array) $jt_rec['no_route'] );
	if ( in_array( $jt_file, $jt_opts['runtime'], true ) ) {
		$jt_rest  += (array) $jt_rec['rest'];
		$jt_hooks += (array) $jt_rec['hooks'];
	}
}
$jt_ns       = '/' . trim( (string) ( $jt_manifest['rest']['namespace'] ?? 'jetonomy/v1' ), '/' );
$jt_failures = array();

// 1. Stale tests: requests that reached no route.
$jt_test_src = array();
foreach ( $jt_noroute as $jt_req ) {
	$jt_ok = false;
	if ( preg_match( '/^([\w\\\\]+)::(\w+)/', (string) $jt_req['test'], $jt_tm ) ) {
		// Find the method's docblock in the test sources.
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $jt_root . '/tests', FilesystemIterator::SKIP_DOTS ) ) as $jt_f ) {
			if ( 'php' !== $jt_f->getExtension() ) {
				continue;
			}
			$jt_src = $jt_test_src[ $jt_f->getPathname() ] ??= (string) file_get_contents( $jt_f->getPathname() );
			if ( preg_match( '#/\*\*((?:(?!\*/).)*)\*/\s*public function ' . preg_quote( $jt_tm[2], '#' ) . '\s*\(#s', $jt_src, $jt_doc ) ) {
				$jt_ok = str_contains( $jt_doc[1], '@expects-no-route' );
				break;
			}
		}
	}
	if ( ! $jt_ok ) {
		$jt_failures[] = sprintf( 'stale test: %s sent %s %s, which matches no route (fix the test, or add @expects-no-route if that is the point)', $jt_req['test'] ?: '(unnamed)', $jt_req['method'], $jt_req['path'] );
	}
}

// A stale-only run (the qa-actions job) stops here.
if ( ! $jt_opts['runtime'] ) {
	if ( $jt_failures ) {
		echo implode( "\n", $jt_failures ), "\n";
		exit( 1 );
	}
	printf( "qa-runtime-coverage: %d request(s) matched no route, none unexpected\n", count( $jt_noroute ) );
	exit( 0 );
}

// 2 + 3. Coverage per manifest item.
$jt_sensitive = '#(member|join|invite|access-rule|moderation|flag|ban|block|restriction|trust|role|/users/me)#';
$jt_items     = array(); // id => must_cover.
$jt_covered   = array();

foreach ( (array) $jt_manifest['rest']['endpoints'] as $jt_ep ) {
	$jt_pattern = '#^' . str_replace( '#', '\#', $jt_ns . $jt_ep['route'] ) . '$#';
	foreach ( (array) $jt_ep['methods'] as $jt_method ) {
		$jt_method = strtoupper( $jt_method );
		$jt_id     = "rest:{$jt_method} {$jt_ep['route']}";
		$jt_items[ $jt_id ] = 'GET' !== $jt_method || (bool) preg_match( $jt_sensitive, $jt_ep['route'] );
		foreach ( array_keys( $jt_rest ) as $jt_hit ) {
			[ $jt_hm, $jt_hp ] = explode( ' ', $jt_hit, 2 );
			if ( $jt_hm === $jt_method && preg_match( $jt_pattern, $jt_hp ) ) {
				$jt_covered[ $jt_id ] = true;
				break;
			}
		}
	}
}
foreach ( (array) $jt_manifest['ajax'] as $jt_a ) {
	$jt_id              = "ajax:{$jt_a['action']}";
	$jt_items[ $jt_id ] = false;
	if ( isset( $jt_hooks[ 'wp_ajax_' . $jt_a['action'] ] ) ) {
		$jt_covered[ $jt_id ] = true;
	}
}
foreach ( (array) $jt_manifest['hooks_fired'] as $jt_h ) {
	$jt_id              = "hook:{$jt_h['name']}";
	$jt_items[ $jt_id ] = false;
	if ( isset( $jt_hooks[ $jt_h['name'] ] ) ) {
		$jt_covered[ $jt_id ] = true;
	}
}
foreach ( (array) $jt_manifest['cron'] as $jt_c ) {
	$jt_id              = "cron:{$jt_c['hook']}";
	$jt_items[ $jt_id ] = false;
	if ( isset( $jt_hooks[ $jt_c['hook'] ] ) ) {
		$jt_covered[ $jt_id ] = true;
	}
}

$jt_uncovered = array_keys( array_diff_key( $jt_items, $jt_covered ) );
sort( $jt_uncovered );
$jt_must      = array_values( array_filter( $jt_uncovered, static fn( $id ) => $jt_items[ $id ] ) );
$jt_ratcheted = array_values( array_diff( $jt_uncovered, $jt_must ) );

foreach ( $jt_must as $jt_id ) {
	$jt_failures[] = "must-cover, never reached: {$jt_id}";
}

$jt_baseline_file = $jt_root . '/audit/qa-runtime-baseline.json';
if ( $jt_opts['update'] ) {
	file_put_contents(
		$jt_baseline_file,
		json_encode(
			array(
				'about'     => 'Uncovered items the runtime coverage gate tolerates. It may only shrink: cover an item, then remove it with bin/qa-runtime-coverage.php --update-baseline. Must-cover items can never be listed here.',
				'uncovered' => $jt_ratcheted,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n"
	);
	echo 'qa-runtime-coverage: baseline written with ' . count( $jt_ratcheted ) . " uncovered item(s)\n";
} else {
	$jt_base = json_decode( (string) @file_get_contents( $jt_baseline_file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$jt_base = (array) ( $jt_base['uncovered'] ?? array() );
	foreach ( array_diff( $jt_ratcheted, $jt_base ) as $jt_id ) {
		$jt_failures[] = "newly uncovered (not in the baseline): {$jt_id}";
	}
	foreach ( array_diff( $jt_base, $jt_ratcheted ) as $jt_id ) {
		$jt_failures[] = "baseline is stale, now covered or gone: {$jt_id} (run with --update-baseline to shrink it)";
	}
}

printf(
	"qa-runtime-coverage: %d of %d items reached (%d%%); %d uncovered, %d of them must-cover; %d request(s) matched no route\n",
	count( $jt_covered ),
	count( $jt_items ),
	count( $jt_items ) ? (int) round( count( $jt_covered ) / count( $jt_items ) * 100 ) : 0,
	count( $jt_uncovered ),
	count( $jt_must ),
	count( $jt_noroute )
);
if ( $jt_failures ) {
	echo implode( "\n", $jt_failures ), "\n";
	exit( 1 );
}
exit( 0 );
