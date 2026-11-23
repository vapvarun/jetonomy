<?php
// phpcs:ignoreFile -- dev self-test for the shared extension guard gate.
/**
 * Self-test for extension-guard-check.php (keep beside it; copy both together).
 *
 * Run: php extension-guard-check.test.php   -> "extension guard gate: N cases pass", exit 0; a failing case exits 1.
 * Each case is a PHP snippet and the number of findings the gate must report for it.
 */

namespace Wbcom\ExtensionGuard;

require __DIR__ . '/extension-guard-check.php';

$pad   = str_repeat( "\$x = 1;\n", 12 ); // 12 lines: puts a guard 13 lines above the call.
$cases = array(
	// Bare calls are caught.
	'bare mb_strtoupper'                 => array( '<?php mb_strtoupper( $s );', 1 ),
	'fully qualified \ctype_digit'       => array( '<?php namespace A; \ctype_digit( $s );', 1 ),
	'new \DOMDocument'                   => array( '<?php namespace A; $d = new \DOMDocument();', 1 ),
	'zip_open()'                         => array( '<?php zip_open( $p );', 1 ),
	'ZipArchive::CREATE static use'      => array( '<?php $f = \ZipArchive::CREATE;', 1 ),
	'iconv()'                            => array( '<?php iconv( "a", "b", $s );', 1 ),
	// Guards that must NOT count.
	'guard 13 lines above'               => array( "<?php if ( function_exists( 'mb_strtolower' ) ) {\n{$pad}mb_strtolower( \$s ); }", 1 ),
	'guard in a // comment'              => array( "<?php // function_exists( 'mb_strtolower' )\nmb_strtolower( \$s );", 1 ),
	'guard in a /* */ comment'           => array( "<?php /* function_exists( 'mb_strtolower' ) */ mb_strtolower( \$s );", 1 ),
	'guard inside a string literal'      => array( "<?php \$a = \"function_exists( 'mb_strtolower' )\"; mb_strtolower( \$s );", 1 ),
	'wrong-extension guard'              => array( "<?php if ( function_exists( 'ctype_digit' ) ) { mb_strtolower( \$s ); }", 1 ),
	'extension_loaded of another ext'    => array( "<?php if ( extension_loaded( 'simplexml' ) ) { \$d = new DOMDocument(); }", 1 ),
	'simplexml guard does not cover dom' => array( "<?php if ( function_exists( 'simplexml_load_string' ) ) { \$d = new DOMDocument(); }", 1 ),
	'guard after the call'               => array( "<?php mb_strtolower( \$s ); function_exists( 'mb_strtolower' );", 1 ),
	// finfo handles.
	'finfo_file on unchecked handle'     => array( "<?php if ( function_exists( 'finfo_open' ) ) { \$f = finfo_open( 1 ); \$m = finfo_file( \$f, \$p ); }", 1 ),
	'finfo_file on a call result'        => array( "<?php if ( function_exists( 'finfo_open' ) ) { \$m = finfo_file( finfo_open( 1 ), \$p ); }", 1 ),
	'unguarded finfo_open'               => array( '<?php $f = finfo_open( 1 );', 1 ),
	// Guards that count.
	'same-line ternary guard'            => array( "<?php \$a = function_exists( 'mb_strtolower' ) ? mb_strtolower( \$s ) : strtolower( \$s );", 0 ),
	'double-quoted guard'                => array( "<?php if ( function_exists( \"mb_strtolower\" ) ) { mb_strtolower( \$s ); }", 0 ),
	'fully qualified guard'              => array( "<?php namespace A; if ( \\function_exists( 'mb_strtolower' ) ) { \\mb_strtolower( \$s ); }", 0 ),
	'same-extension guard'               => array( "<?php if ( function_exists( 'mb_check_encoding' ) ) { mb_convert_encoding( \$s, 'UTF-8' ); }", 0 ),
	'class_exists ZipArchive'            => array( "<?php if ( class_exists( '\\\\ZipArchive' ) ) { \$z = new \\ZipArchive(); \$c = \\ZipArchive::CREATE; }", 0 ),
	'extension_loaded named'             => array( "<?php if ( extension_loaded( 'dom' ) ) { \$d = new DOMDocument(); }", 0 ),
	'simplexml guard covers libxml'      => array( "<?php if ( ! function_exists( 'simplexml_load_string' ) ) { return; }\nlibxml_use_internal_errors( true ); \$x = simplexml_load_string( \$b );", 0 ),
	'guard 12 lines above'               => array( "<?php if ( function_exists( 'mb_strtolower' ) ) {\n" . str_repeat( "\$x = 1;\n", 11 ) . 'mb_strtolower( $s ); }', 0 ),
	'finfo handle ternary'               => array( "<?php if ( function_exists( 'finfo_open' ) ) { \$f = finfo_open( 1 ); \$m = \$f ? finfo_file( \$f, \$p ) : false; }", 0 ),
	'finfo handle early return'          => array( "<?php if ( function_exists( 'finfo_open' ) ) { \$f = finfo_open( 1 ); if ( false === \$f ) { return; } finfo_file( \$f, \$p ); finfo_close( \$f ); }", 0 ),
	// Not uses.
	'polyfilled mb_strlen'               => array( '<?php mb_strlen( $s ); mb_substr( $s, 1 );', 0 ),
	'ZipArchive::class'                  => array( '<?php $c = \ZipArchive::class;', 0 ),
	'method named like a function'       => array( '<?php $o->mb_strtolower( $s ); Foo::ctype_digit( $s );', 0 ),
	'function declaration'               => array( '<?php function mb_helper_x() {} function zip_name() {}', 0 ),
	'call in a comment'                  => array( '<?php // mb_strtoupper( $s )', 0 ),
	// Function scope: a guard counts only in the same body, or in an enclosing body for a closure or arrow function.
	'guard in another function nearby'   => array( "<?php function a() { if ( ! function_exists( 'mb_strtolower' ) ) { return; } }\nfunction b() { mb_strtolower( \$s ); }", 1 ),
	'guard in a sibling method'          => array( "<?php class C { function a() { return function_exists( 'mb_strtolower' ); }\nfunction b() { return mb_strtolower( \$s ); } }", 1 ),
	'guard in a closure, use outside'    => array( "<?php function a() { \$f = function () { return function_exists( 'mb_strtolower' ); };\nmb_strtolower( \$s ); }", 1 ),
	'guard in arrow fn, use outside'     => array( "<?php \$ok = fn() => extension_loaded( 'mbstring' );\nmb_strtolower( \$s );", 1 ),
	'guard in sibling closure'           => array( "<?php \$a = function () { function_exists( 'mb_strtolower' ); }; \$b = function () { mb_strtolower( \$s ); };", 1 ),
	'file-level guard, use in function'  => array( "<?php if ( ! function_exists( 'mb_strtolower' ) ) { return; }\nfunction b() { mb_strtolower( \$s ); }", 1 ),
	'outer method guard, nested method'  => array( "<?php function a() { if ( function_exists( 'mb_strtolower' ) ) { function b() { mb_strtolower( \$s ); } } }", 1 ),
	'finfo handle checked in other func' => array( "<?php function a( \$f ) { if ( ! \$f ) { return; } }\nfunction b( \$f ) { if ( function_exists( 'finfo_open' ) ) { finfo_file( \$f, \$p ); } }", 1 ),
	'enclosing method guard, closure use' => array( "<?php class C { function a( \$l ) { if ( ! function_exists( 'mb_strtolower' ) ) { return \$l; }\nreturn array_map( function ( \$s ) { return mb_strtolower( \$s ); }, \$l ); } }", 0 ),
	'enclosing guard, arrow fn use'      => array( "<?php function a( \$l ) { if ( ! extension_loaded( 'mbstring' ) ) { return \$l; }\nreturn array_map( fn( \$s ) => mb_strtolower( \$s ), \$l ); }", 0 ),
	'guard inside the closure'           => array( "<?php \$f = function ( \$s ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( \$s ) : \$s; };", 0 ),
	'early return in the same function'  => array( "<?php function a( \$s ) { if ( ! function_exists( 'mb_strtolower' ) ) { return \$s; }\n\$s = trim( \$s );\nreturn mb_strtolower( \$s ); }", 0 ),
	'guard after an earlier function'    => array( "<?php function a() { return 1; }\nfunction b( \$s ) { return function_exists( 'mb_strtolower' ) ? mb_strtolower( \$s ) : \$s; }", 0 ),
	'abstract method then guarded use'   => array( "<?php abstract class C { abstract function a();\nfunction b( \$s ) { if ( ! function_exists( 'mb_strtolower' ) ) { return; } mb_strtolower( \$s ); } }", 0 ),
	// Lookalikes and syntax corners.
	'method guard does not count'        => array( "<?php if ( \$o->function_exists( 'mb_strtolower' ) ) { mb_strtolower( \$s ); }", 1 ),
	'static guard does not count'        => array( "<?php if ( Env::extension_loaded( 'mbstring' ) ) { mb_strtolower( \$s ); }", 1 ),
	'extension_loaded( \$var )'          => array( "<?php \$e = 'mbstring'; if ( extension_loaded( \$e ) ) { mb_strtolower( \$s ); }", 1 ),
	'defined() is not a guard'           => array( "<?php if ( defined( 'MB_CASE_TITLE' ) ) { mb_convert_case( \$s, MB_CASE_TITLE ); }", 1 ),
	'nullsafe and static lookalikes'     => array( '<?php $o?->mb_strtoupper( $s ); $o->a()->mb_strtoupper(); Str::mb_strtoupper( $s ); static::ctype_alpha( $s );', 0 ),
	'heredoc text is not a call'         => array( "<?php \$h = <<<EOT\nmb_strtoupper( \$s ) and new DOMDocument()\nEOT;\n\$n = <<<'EOT'\nzip_open( \$p )\nEOT;", 0 ),
	'\\function_exists guard counts'     => array( "<?php namespace A; function a( \$s ) { if ( ! \\function_exists( 'mb_strtolower' ) ) { return \$s; } return \\mb_strtolower( \$s ); }", 0 ),
	'use function import is not a call'  => array( '<?php namespace A; use function mb_strtolower;', 0 ),
	'use function then bare call'        => array( "<?php namespace A; use function mb_strtolower;\nmb_strtolower( \$s );", 1 ),
	'use function alias call'            => array( "<?php namespace A; use function mb_strtolower as lower;\nlower( \$s );", 1 ),
	'use function alias, guarded'        => array( "<?php namespace A; use function mb_strtolower as lower;\nif ( function_exists( 'mb_strtolower' ) ) { lower( \$s ); }", 0 ),
	'class alias new'                    => array( "<?php namespace A; use ZipArchive as Zip;\n\$z = new Zip();", 1 ),
	'@ suppressed call'                  => array( '<?php @mb_strtoupper( $s );', 1 ),
	'inline <?= ?> call'                 => array( '<p><?= mb_strtoupper( $s ) ?></p>', 1 ),
	'inline <?= ?> guarded in template'  => array( "<?php if ( function_exists( 'mb_strtoupper' ) ) : ?>\n<p><?= mb_strtoupper( \$s ) ?></p>\n<?php endif; ?>", 0 ),
	'closure use() clause not a body'    => array( "<?php function a( \$l, \$x ) { if ( ! function_exists( 'mb_strtolower' ) ) { return; }\nreturn array_map( function ( \$s ) use ( \$x ) { return mb_strtolower( \$s . \$x ); }, \$l ); }", 0 ),
	'string interpolation braces'        => array( "<?php function a( \$o ) { if ( ! function_exists( 'mb_strtolower' ) ) { return; } \$m = \"{\$o->x} \${y}\"; return mb_strtolower( \$m ); }", 0 ),
	// Documented blind spot: a call through a variable cannot be caught by a token gate.
	'variable call (known blind spot)'   => array( "<?php \$f = 'mb_strtolower'; \$f( \$s );", 0 ),
);

$failed = 0;
foreach ( $cases as $label => $case ) {
	$got = count( scan_code( $case[0] ) );
	if ( $got !== $case[1] ) {
		echo "FAIL $label: expected {$case[1]} finding(s), got $got\n";
		++$failed;
	}
}
echo $failed ? "extension guard gate: $failed case(s) failed\n" : 'extension guard gate: ' . count( $cases ) . " cases pass\n";
exit( $failed ? 1 : 0 );
