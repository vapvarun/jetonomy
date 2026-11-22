<?php
/**
 * Script translations resolve: every languages/*.json names a script file that exists and is named by the md5 of
 * that path (what WordPress looks up), and every handle passed to script_translations() resolves to a script that
 * has a JSON file in each translated locale when the .pot holds strings from it.
 *
 * `wp i18n make-json` strips ".min.js" with an unescaped dot, so a script whose name ends in "...dmin.js"
 * (moderation-admin.js) gets source "moderation-a.js" and a hash nothing loads. --fix renames those files.
 *
 * Usage: php bin/check-script-translations.php [plugin-dir] [--fix]
 * Exit 0 = OK, 1 = problems (listed).
 *
 * @package Jetonomy
 */

$args = array_slice( $argv, 1 );
$fix  = in_array( '--fix', $args, true );
$dir  = rtrim( (string) ( array_values( array_diff( $args, array( '--fix' ) ) )[0] ?? dirname( __DIR__ ) ), '/' );
$pots = glob( "$dir/languages/*.pot" );
if ( ! $pots ) {
	fwrite( STDERR, "check-script-translations: no .pot in $dir/languages\n" );
	exit( 1 );
}
$domain = basename( $pots[0], '.pot' );

// JS files the .pot holds strings from.
preg_match_all( '/^#: (.+)$/m', (string) file_get_contents( $pots[0] ), $m );
$js_refs = array();
foreach ( $m[1] as $line ) {
	foreach ( preg_split( '/\s+/', trim( $line ) ) as $ref ) {
		$path = preg_replace( '/:\d+$/', '', $ref );
		if ( str_ends_with( $path, '.js' ) ) {
			$js_refs[ $path ] = true;
		}
	}
}

$problems = array();
$locales  = array();
foreach ( glob( "$dir/languages/$domain-*-*.json" ) as $file ) {
	if ( ! preg_match( '/^' . preg_quote( $domain, '/' ) . '-(.+)-([0-9a-f]{32})\.json$/', basename( $file ), $parts ) ) {
		continue;
	}
	$locales[ $parts[1] ] = true;
	$json                 = json_decode( (string) file_get_contents( $file ), true );
	$source               = (string) ( $json['source'] ?? '' );
	if ( '' !== $source && ! is_file( "$dir/$source" ) ) {
		// The make-json bug: find the script whose name it mangled.
		$real = '';
		foreach ( array_keys( $js_refs ) as $ref ) {
			if ( preg_replace( '/.min.js$/', '.js', $ref ) === $source && is_file( "$dir/$ref" ) ) {
				$real = $ref;
			}
		}
		if ( $fix && $real ) {
			$json['source'] = $real;
			file_put_contents( "$dir/languages/$domain-{$parts[1]}-" . md5( $real ) . '.json', json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			unlink( $file );
			echo "fixed: $source -> $real ({$parts[1]})\n";
			continue;
		}
		$problems[] = basename( $file ) . ": source $source does not exist" . ( $real ? " (it is $real; run with --fix)" : '' );
		continue;
	}
	if ( '' !== $source && md5( $source ) !== $parts[2] ) {
		$problems[] = basename( $file ) . ": named by the wrong hash (md5 of $source is " . md5( $source ) . ')';
	}
}

// Handles given to script_translations(), resolved to their registered file.
$php = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$dir/includes", FilesystemIterator::SKIP_DOTS ) );
$src   = '';
$files = array();
foreach ( $php as $f ) {
	if ( str_ends_with( (string) $f, '.php' ) ) {
		$files[ (string) $f ] = (string) file_get_contents( (string) $f );
		$src                 .= $files[ (string) $f ] . "\n";
	}
}
/**
 * The plugin-relative .js path a handle is registered with ( CONST_URL . 'path' or plugins_url( 'path', __FILE__ ) ).
 */
$registered = static function ( string $handle ) use ( $src, $files, $dir ): ?string {
	$open = "/wp_(?:register|enqueue)_script\\(\\s*'" . preg_quote( $handle, '/' ) . "',\\s*";
	if ( preg_match( $open . "[A-Z_]+_URL\\s*\\.\\s*'([^']+?)(?:\\.min)?\\.js'/", $src, $r ) ) {
		return $r[1] . '.js';
	}
	foreach ( $files as $file => $code ) {
		if ( preg_match( $open . "plugins_url\\(\\s*'([^']+?)(?:\\.min)?\\.js',\\s*__FILE__\\s*\\)/", $code, $r ) ) {
			return ltrim( substr( dirname( $file ), strlen( $dir ) ) . '/' . $r[1] . '.js', '/' );
		}
	}
	return null;
};
preg_match_all( '/(?<![\w])(?<!function )script_translations\(\s*([^)]*)\)/', $src, $calls );
$handles = array();
foreach ( $calls[1] as $list ) {
	preg_match_all( "/'([a-z0-9-]+)'/", $list, $h );
	foreach ( $h[1] as $handle ) {
		$handles[ $handle ] = true;
	}
}
foreach ( array_keys( $handles ) as $handle ) {
	$path = $registered( $handle );
	if ( null === $path ) {
		$block = glob( "$dir/{build,blocks}/*/block.json", GLOB_BRACE ) ?: array();
		$found = false;
		foreach ( $block as $b ) {
			$found = $found || str_contains( (string) file_get_contents( $b ), '"' . $handle . '"' );
		}
		if ( ! $found ) {
			$problems[] = "handle $handle: no wp_register_script/wp_enqueue_script with a plugin URL found";
		}
		continue;
	}
	if ( ! isset( $js_refs[ $path ] ) ) {
		continue; // No translatable strings in it yet: nothing to load.
	}
	foreach ( array_keys( $locales ) as $locale ) {
		if ( ! is_file( "$dir/languages/$domain-$locale-" . md5( $path ) . '.json' ) ) {
			$problems[] = "handle $handle ($path): no $locale translation file";
		}
	}
}

if ( $problems ) {
	fwrite( STDERR, 'check-script-translations: ' . count( $problems ) . " problem(s) in $domain\n  " . implode( "\n  ", $problems ) . "\n" );
	exit( 1 );
}
echo "check-script-translations: OK ($domain: " . count( $handles ) . ' handles, ' . count( $locales ) . " locales)\n";
