<?php
/**
 * Fail on translatable strings that hardcode a terminology noun.
 *
 * Owners rename Space / Topic / Reply / Member / Category under Settings ->
 * Terminology, and the docs promise the rename applies everywhere. A string
 * like __( 'Delete this space' ) silently ignores it. User-facing text must
 * build the noun from \Jetonomy\jetonomy_label() / count_label() in PHP, or
 * window.jetonomyLabels in JS.
 *
 * A line that genuinely must keep the default word (the label defaults
 * themselves, block names fixed in block.json, space ROLE names) carries
 * `jt-terms-ok: <reason>` on the same line or the line above.
 *
 * Usage: php bin/check-terminology.php [file-or-dir ...]
 *   default: includes templates jetonomy.php assets/js
 *
 * @package Jetonomy
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI tool, no WordPress loaded.

$root  = dirname( __DIR__ );
$paths = array_slice( $argv, 1 );
if ( ! $paths ) {
	$paths = array( 'includes', 'templates', 'jetonomy.php', 'assets/js' );
}

// Not user-facing, or text fixed outside our control.
$skip = array(
	'includes/cli/',          // WP-CLI output for developers.
	'includes/qa/',           // QA harness.
	'includes/db/',           // Schema and migrations.
	'includes/class-abilities.php',     // Abilities API schemas for AI agents and integrations (machine-facing).
	'assets/js/blocks-editor.js',       // Block names/descriptions mirror static block.json.
	'assets/js/compose-topic-block.js', // Same: block editor registration.
);

$nouns = '/\b(spaces?|topics?|repl(?:y|ies)|members?|categor(?:y|ies))\b/i';
// Matched against the whole file, so a call whose string starts on the next
// line (`_n(\n 'text'`) is still seen. /s lets `.` cross escaped newlines.
$php    = '/\b(?:__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_html_x|esc_attr__|esc_attr_e|esc_attr_x)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s';
$js     = '/\b(?:__|_x|_n)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s';
$plural = '/\b_nx?\(\s*([\'"])(?:\\\\.|(?!\1).)*\1\s*,\s*([\'"])((?:\\\\.|(?!\2).)*)\2/s';

$files = array();
foreach ( $paths as $p ) {
	$abs = $root . '/' . ltrim( $p, '/' );
	if ( is_file( $abs ) ) {
		$files[] = $abs;
	} elseif ( is_dir( $abs ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $abs, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$files[] = $f->getPathname();
		}
	}
}

$hits = array();
foreach ( $files as $file ) {
	$rel = ltrim( substr( $file, strlen( $root ) ), '/' );
	$ext = pathinfo( $file, PATHINFO_EXTENSION );
	if ( ! in_array( $ext, array( 'php', 'js' ), true ) || str_ends_with( $rel, '.min.js' ) || str_contains( $rel, 'node_modules/' ) || str_contains( $rel, 'vendor/' ) ) {
		continue;
	}
	foreach ( $skip as $s ) {
		if ( str_starts_with( $rel, $s ) ) {
			continue 2;
		}
	}
	$src   = (string) file_get_contents( $file );
	$lines = explode( "\n", $src );
	$found = array(); // [ offset, text ] of every singular and plural string.
	preg_match_all( 'js' === $ext ? $js : $php, $src, $m, PREG_OFFSET_CAPTURE );
	foreach ( $m[2] as $g ) {
		$found[] = $g;
	}
	preg_match_all( $plural, $src, $m, PREG_OFFSET_CAPTURE );
	foreach ( $m[3] as $g ) {
		$found[] = $g;
	}
	foreach ( $found as list( $text, $offset ) ) {
		if ( ! preg_match( $nouns, $text ) ) {
			continue;
		}
		$i = substr_count( $src, "\n", 0, $offset ); // 0-based line of the string itself.
		if ( str_contains( $lines[ $i ], 'jt-terms-ok' ) || ( $i > 0 && str_contains( $lines[ $i - 1 ], 'jt-terms-ok' ) ) ) {
			continue;
		}
		$hits[] = sprintf( '%s:%d: %s', $rel, $i + 1, trim( $text ) );
	}
}

if ( $hits ) {
	echo "Hardcoded terminology nouns (use jetonomy_label() / count_label() or window.jetonomyLabels; mark a true exception with jt-terms-ok: <reason>):\n";
	echo implode( "\n", $hits ), "\n";
	echo count( $hits ), " string(s).\n";
	exit( 1 );
}
echo "OK: no hardcoded terminology nouns.\n";
