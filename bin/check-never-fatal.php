<?php
/**
 * Never-fatal grep gate: calls that fatal or deprecate on a supported host.
 *
 * Fails when, under <plugin-dir>:
 *   - setAccessible( appears in includes/ or tests/ (a no-op since PHP 8.1,
 *     deprecated in 8.5; reflection reaches private members without it);
 *   - includes/, templates/ or the main file call an mb_* function WordPress
 *     does not polyfill, a ctype_* function, new DOMDocument or finfo_open()
 *     without a function_exists()/class_exists() guard in the 25 lines above
 *     (or on the same line). Use \Jetonomy\lower() / is_digits() instead.
 *
 * Usage: php bin/check-never-fatal.php [plugin-dir]   (default: this plugin)
 *
 * @package Jetonomy
 */

$root = rtrim( $argv[1] ?? dirname( __DIR__ ), '/' );

// WordPress polyfills these in wp-includes/compat.php.
$polyfilled = array( 'mb_substr', 'mb_strlen', 'mb_str_split' );

$files = static function ( array $dirs ) use ( $root ): array {
	$out = array();
	foreach ( $dirs as $dir ) {
		$path = $root . '/' . $dir;
		if ( is_file( $path ) ) {
			$out[] = $path;
			continue;
		}
		if ( ! is_dir( $path ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( 'php' === $f->getExtension() && ! str_contains( $f->getPathname(), '/vendor/' ) && ! str_contains( $f->getPathname(), '/libs/' ) ) {
				$out[] = $f->getPathname();
			}
		}
	}
	return $out;
};

$problems = array();
$rel      = static fn( string $f ): string => substr( $f, strlen( $root ) + 1 );

foreach ( $files( array( 'includes', 'tests' ) ) as $file ) {
	foreach ( file( $file ) as $n => $line ) {
		if ( str_contains( $line, 'setAccessible(' ) ) {
			$problems[] = $rel( $file ) . ':' . ( $n + 1 ) . ' setAccessible() (no-op since 8.1, deprecated in 8.5)';
		}
	}
}

foreach ( $files( array( 'includes', 'templates', 'jetonomy.php', 'jetonomy-pro.php' ) ) as $file ) {
	$lines = file( $file );
	foreach ( $lines as $n => $line ) {
		$code = ltrim( $line );
		if ( str_starts_with( $code, '*' ) || str_starts_with( $code, '//' ) || str_starts_with( $code, '/*' ) ) {
			continue;
		}
		if ( ! preg_match_all( '/(?<![\w>$:\\\\])(mb_\w+|ctype_\w+|finfo_open)\s*\(|new\s+\\\\?(DOMDocument)\b/', $line, $m, PREG_SET_ORDER ) ) {
			continue;
		}
		$context = implode( '', array_slice( $lines, max( 0, $n - 25 ), min( 26, $n + 1 ) ) );
		foreach ( $m as $hit ) {
			$name = '' !== ( $hit[1] ?? '' ) ? $hit[1] : 'DOMDocument';
			if ( in_array( $name, $polyfilled, true ) ) {
				continue;
			}
			$guard = 'DOMDocument' === $name
				? '/class_exists\(\s*[\'"]\\\\{0,2}DOMDocument[\'"]/'
				: '/function_exists\(\s*[\'"]' . preg_quote( $name, '/' ) . '[\'"]/';
			if ( ! preg_match( $guard, $context ) ) {
				$problems[] = $rel( $file ) . ':' . ( $n + 1 ) . " {$name} without a guard";
			}
		}
	}
}

if ( $problems ) {
	fwrite( STDERR, implode( "\n", $problems ) . "\n\nnever-fatal: " . count( $problems ) . " problem(s). Guard the call or use \\Jetonomy\\lower() / is_digits().\n" );
	exit( 1 );
}
echo "never-fatal: OK\n";
