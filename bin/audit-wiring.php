<?php
/**
 * audit-wiring.php — "last hop never connected" gate.
 *
 * Catches the dangling-wire bug class that bit 1.4.4/1.5.0-dev: a feature whose
 * backend is built but whose final hop is missing. Two checks:
 *
 *   1. (HARD) Interactivity actions. Every `data-wp-on--<event>="actions.<name>"`
 *      rendered in a PHP template or markup string MUST have a matching
 *      `<name>(` action defined in the plugins' JS (view.js / pro-view.js, inside
 *      the shared `store('jetonomy', { actions: { ... } })`). A button wired to
 *      `actions.useAiSuggestion` with no JS handler does nothing — that was the
 *      AI "Use as reply" bug.
 *
 *   2. (INFO) do_action listeners. Every `do_action('jetonomy_*', ...)` fired in
 *      plugin code is cross-referenced against `add_action('jetonomy_*', ...)`
 *      anywhere in free+pro. Hooks with no in-repo listener are REPORTED, not
 *      failed — many are deliberate third-party extension points (e.g.
 *      `jetonomy_after_post_article`). Use the list to eyeball: is this hook one
 *      the plugin's OWN code was supposed to consume? Web Push (arity) and
 *      Reply-by-Email (no listener) would have surfaced here.
 *
 * Scans BOTH plugins regardless of which one you point at — wiring crosses the
 * free/pro boundary (pro JS actions live in the same `jetonomy` store; free
 * fires hooks pro listens to).
 *
 * Usage (run from either plugin root):
 *   php bin/audit-wiring.php
 *   php bin/audit-wiring.php /abs/path/to/wp-content/plugins
 *
 * Exit code: 0 = no dead JS actions, 1 = at least one dead action (hard check).
 * The INFO check never changes the exit code.
 *
 * @package Jetonomy
 * @since   1.5.0
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "audit-wiring.php must be run from the CLI.\n" );
	exit( 1 );
}

/*
 * Resolve the two plugin roots. Default: this script lives in
 * <plugins>/jetonomy/bin/, so the plugins dir is two levels up.
 */
$plugins_dir = $argv[1] ?? dirname( __DIR__, 2 );
$plugins_dir = rtrim( $plugins_dir, '/' );

$roots = array();
foreach ( array( 'jetonomy', 'jetonomy-pro' ) as $slug ) {
	$path = $plugins_dir . '/' . $slug;
	if ( is_dir( $path ) ) {
		$roots[ $slug ] = $path;
	}
}
if ( empty( $roots ) ) {
	fwrite( STDERR, "No jetonomy / jetonomy-pro found under: {$plugins_dir}\n" );
	exit( 1 );
}

/**
 * Recursively collect files with the given extensions, skipping vendor / node /
 * build / minified output (minified JS is derived, never the source of truth).
 *
 * @param string   $dir  Directory to walk.
 * @param string[] $exts Extensions without the dot (e.g. ['php']).
 * @return string[] Absolute file paths.
 */
function jt_collect( string $dir, array $exts ): array {
	$out = array();
	$skip = array( '/vendor/', '/node_modules/', '/build/', '/dist/', '/tests/', '/audit/' );
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		$p = $file->getPathname();
		$rel = str_replace( '\\', '/', $p );
		foreach ( $skip as $s ) {
			if ( strpos( $rel, $s ) !== false ) {
				continue 2;
			}
		}
		if ( preg_match( '/\.min\.(js|css)$/', $p ) ) {
			continue;
		}
		$ext = strtolower( pathinfo( $p, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $exts, true ) ) {
			$out[] = $p;
		}
	}
	return $out;
}

$php_files = array();
$js_files  = array();
foreach ( $roots as $root ) {
	$php_files = array_merge( $php_files, jt_collect( $root, array( 'php' ) ) );
	$js_files  = array_merge( $js_files, jt_collect( $root, array( 'js' ) ) );
}

/* ---------------------------------------------------------------------------
 * CHECK 1 (HARD): data-wp-on--*="actions.X"  →  X defined in JS
 * ------------------------------------------------------------------------- */

// Collect every referenced action name + where it was referenced.
$referenced = array(); // name => [ 'file:line', ... ]
foreach ( array_merge( $php_files, $js_files ) as $file ) {
	$lines = file( $file, FILE_IGNORE_NEW_LINES );
	foreach ( $lines as $i => $line ) {
		if ( preg_match_all( '/data-wp-on--[a-z-]+=(?:"|\\\\?\')actions\.([A-Za-z0-9_]+)/', $line, $m ) ) {
			foreach ( $m[1] as $name ) {
				$referenced[ $name ][] = jt_rel( $file, $plugins_dir ) . ':' . ( $i + 1 );
			}
		}
	}
}

// Collect every action name DEFINED in JS. Actions live as `name(...)` or
// `name: function` / `*name(...)` (generators) inside the store's actions object.
$defined = array();
foreach ( $js_files as $file ) {
	$src = file_get_contents( $file );
	// method shorthand:  useAiSuggestion( / *useAiSuggestion( / async useAiSuggestion(
	if ( preg_match_all( '/(?:^|[\s,{])\*?\s*([A-Za-z0-9_]+)\s*\([^)]*\)\s*\{/', $src, $m ) ) {
		foreach ( $m[1] as $name ) {
			$defined[ $name ] = true;
		}
	}
	// property style:  useAiSuggestion: function / useAiSuggestion: ( ) =>
	if ( preg_match_all( '/([A-Za-z0-9_]+)\s*:\s*(?:async\s+)?(?:function|\()/', $src, $m ) ) {
		foreach ( $m[1] as $name ) {
			$defined[ $name ] = true;
		}
	}
}

$dead = array();
foreach ( $referenced as $name => $sites ) {
	if ( empty( $defined[ $name ] ) ) {
		$dead[ $name ] = $sites;
	}
}

/* ---------------------------------------------------------------------------
 * CHECK 2 (INFO): do_action('jetonomy_*')  →  add_action('jetonomy_*')
 * ------------------------------------------------------------------------- */

$fired   = array(); // hook => [ 'file:line', ... ]
$listened = array(); // hook => true
foreach ( $php_files as $file ) {
	$lines = file( $file, FILE_IGNORE_NEW_LINES );
	foreach ( $lines as $i => $line ) {
		if ( preg_match_all( '/do_action(?:_deprecated)?\(\s*[\'"](jetonomy[A-Za-z0-9_]*)[\'"]/', $line, $m ) ) {
			foreach ( $m[1] as $hook ) {
				$fired[ $hook ][] = jt_rel( $file, $plugins_dir ) . ':' . ( $i + 1 );
			}
		}
		if ( preg_match_all( '/add_action\(\s*[\'"](jetonomy[A-Za-z0-9_]*)[\'"]/', $line, $m ) ) {
			foreach ( $m[1] as $hook ) {
				$listened[ $hook ] = true;
			}
		}
	}
}

$unlistened = array();
foreach ( $fired as $hook => $sites ) {
	if ( empty( $listened[ $hook ] ) ) {
		$unlistened[ $hook ] = $sites;
	}
}

/* ---------------------------------------------------------------------------
 * Report
 * ------------------------------------------------------------------------- */

/**
 * Relative path from the plugins dir, for readable output.
 */
function jt_rel( string $file, string $plugins_dir ): string {
	return ltrim( str_replace( $plugins_dir, '', str_replace( '\\', '/', $file ) ), '/' );
}

echo "== Jetonomy wiring audit ==\n";
echo 'Scanned ' . count( $php_files ) . ' PHP + ' . count( $js_files ) . " JS files across: " . implode( ', ', array_keys( $roots ) ) . "\n\n";

echo '[1] Interactivity actions (HARD): ' . count( $referenced ) . " referenced, " . count( $defined ) . " defined in JS\n";
if ( empty( $dead ) ) {
	echo "    OK — every data-wp-on action has a JS handler.\n\n";
} else {
	echo '    DEAD ACTIONS (' . count( $dead ) . "):\n";
	foreach ( $dead as $name => $sites ) {
		echo "      actions.{$name}  — not defined in any JS store. Referenced at:\n";
		foreach ( $sites as $s ) {
			echo "        - {$s}\n";
		}
	}
	echo "\n";
}

echo '[2] do_action listeners (INFO): ' . count( $fired ) . " jetonomy_* hooks fired, " . count( $unlistened ) . " with no in-repo add_action\n";
if ( ! empty( $unlistened ) ) {
	echo "    Review these — fine if a deliberate third-party extension point,\n";
	echo "    a bug if the plugin's own code was meant to consume it:\n";
	foreach ( $unlistened as $hook => $sites ) {
		echo "      {$hook}  ({$sites[0]})\n";
	}
	echo "\n";
}

if ( ! empty( $dead ) ) {
	fwrite( STDERR, 'FAIL: ' . count( $dead ) . " dead Interactivity action(s).\n" );
	exit( 1 );
}
echo "PASS (no dead JS actions).\n";
exit( 0 );
