#!/usr/bin/env php
<?php
/**
 * Jetonomy — PHP format-string gate.
 *
 * A positional specifier written as `%1\$s` inside a SINGLE-quoted string keeps
 * the backslash literally, and sprintf() throws
 * `ValueError: Unknown format specifier "\"` — a hard fatal on whatever page
 * renders it. Only DOUBLE-quoted strings need the backslash, to stop PHP
 * interpolating `$s` as a variable.
 *
 * Tokenizing (rather than grepping) is the only reliable way to know which
 * quote opened the string: an apostrophe in ordinary English copy ("didn't")
 * makes any regex read the rest of the line as single-quoted.
 *
 * Basecamp #10264484098 — shipped in 1.9.4, fataled the Categories admin page.
 *
 * Usage: php bin/check-format-strings.php [dir ...]   (default: includes templates)
 * Exits 1 if any offender is found.
 *
 * @package Jetonomy
 */

$roots  = array_slice( $argv, 1 ) ?: array( 'includes', 'templates' );
$base   = dirname( __DIR__ );
$failed = array();

foreach ( $roots as $root ) {
	$path = $base . '/' . $root;
	if ( ! is_dir( $path ) ) {
		continue;
	}
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );
	foreach ( $files as $file ) {
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$tokens = token_get_all( file_get_contents( $file->getPathname() ) );
		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
				continue;
			}
			// Single-quoted only — double-quoted strings legitimately escape the $.
			if ( "'" !== $token[1][0] ) {
				continue;
			}
			if ( preg_match( '/%\d+\\\\\$/', $token[1] ) ) {
				$failed[] = sprintf(
					'%s:%d: %s',
					str_replace( $base . '/', '', $file->getPathname() ),
					$token[2],
					trim( $token[1] )
				);
			}
		}
	}
}

if ( $failed ) {
	echo "FAILED: backslash-escaped %N\$s inside single-quoted format string(s).\n";
	echo "sprintf() will throw ValueError: Unknown format specifier \"\\\\\".\n";
	echo "Drop the backslash, or switch the string to double quotes.\n\n";
	echo implode( "\n", $failed ) . "\n";
	exit( 1 );
}

echo "OK: no malformed PHP format strings.\n";
