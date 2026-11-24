<?php
// phpcs:ignoreFile -- dev CLI gate shared across the plugin family; never loaded by a plugin at runtime.
/**
 * Extension guard gate: every call into an optional PHP extension in shipped code is guarded just before it.
 *
 * Family gate, kept with its extension-guard-check.test.php (copy both together). Another copy lives in
 * ~/.claude/skills/wp-plugin-development/scripts/; `diff` the two before assuming they match. The self-test needs
 * only this folder.
 *
 * Usage: php extension-guard-check.php <plugin-dir> [dir ...]
 *   Scans <plugin-dir>/*.php plus the listed sub-directories (default: includes templates src).
 *   Prints one line per finding and exits 1 when there is any; prints nothing and exits 0 otherwise.
 *
 * What counts as a use: a call to an extension function (mb_* except WordPress's polyfilled mb_strlen, mb_substr and
 * mb_str_split; ctype_*; finfo_*; mime_content_type; iconv*; simplexml_*; libxml_*; xml_*; dom_import_simplexml;
 * zip_*), `new X` or `X::` (not `X::class`) for an extension class (DOMDocument, DOMXPath, DOMElement, DOMNode,
 * SimpleXMLElement, XMLReader, XMLWriter, ZipArchive, finfo). Leading backslashes are ignored.
 *
 * What counts as a guard, read from PHP tokens, so comments and string literals never count:
 *   - function_exists( 'f' ) / class_exists( 'C' ) where f or C belongs to the SAME extension, or
 *     extension_loaded( 'name' ) naming that extension. Either quote style.
 *   - it sits before the use and no more than WINDOW lines above it, and
 *   - it is in the SAME function body as the use (file top level counts as one body). A guard in an enclosing body
 *     also covers a use inside a closure or arrow function defined there, because that code is created after the
 *     guard ran. It never crosses a named function or method boundary: those run whenever they are called, so a
 *     guard in a sibling function, an outer function, or at file level does not cover them. A guard inside a closure
 *     covers only that closure.
 *   - WINDOW stays as an extra limit inside the body: function scope alone would accept a guard at the top of a long
 *     method whose `if` block closed long before the use, and a guard a reviewer cannot see beside the call is one
 *     nobody keeps in step when the code moves.
 *   - `->function_exists(`, `::function_exists(` and declarations named like a guard never count; neither does a
 *     guard whose argument is not a literal (`extension_loaded( $ext )`) or defined().
 *   - an extension that needs another proves it: simplexml, dom, xmlreader and xmlwriter guards cover libxml_*.
 * finfo handles: every finfo_* call other than finfo_open() must have its first argument, a variable, checked for
 * false in the window and the same body first: `$h ?`, `$h &&`, `! $h`, `false === $h` / `!== $h`, `$h instanceof`, or `if ( $h`.
 * finfo_open() can return false even with fileinfo loaded, and finfo_file( false ) is a TypeError on PHP 8. The
 * handle check also proves the extension, so no function_exists() is needed there (finfo_open() itself needs one).
 *
 * Aliases are followed: `use function mb_strtolower as lower;` makes `lower()` a use, `use ZipArchive as Zip;` makes
 * `new Zip` one.
 *
 * Not caught (a token gate cannot know the name): calls through a variable or a string (`$f( ... )`,
 * call_user_func( 'mb_strtolower', ... )), group-use aliases (`use X\{ A as B }`), methods on an object whose class
 * came from elsewhere, and a guard inside a nested `if` block that closed before the use in the same body.
 *
 * Not scanned, on purpose: libs/ and vendor/ (third-party code, fixed upstream and re-bundled, never edited here),
 * bin/, tools/ and tests/ (developer tooling run on a full PHP build, never shipped or never run on a customer
 * site), build/, dist/ and node_modules/ (generated copies of src/, which is scanned).
 */

namespace Wbcom\ExtensionGuard;

const WINDOW = 12;

const FUNCTION_FAMILIES = array(
	'mb_'                  => 'mbstring',
	'ctype_'               => 'ctype',
	'finfo_'               => 'fileinfo',
	'mime_content_type'    => 'fileinfo',
	'iconv'                => 'iconv',
	'simplexml_'           => 'simplexml',
	'libxml_'              => 'libxml',
	'xml_'                 => 'xml',
	'dom_import_simplexml' => 'dom',
	'zip_'                 => 'zip',
);
const POLYFILLED       = array( 'mb_strlen', 'mb_substr', 'mb_str_split' );
const CLASS_FAMILIES   = array(
	'domdocument'      => 'dom',
	'domxpath'         => 'dom',
	'domelement'       => 'dom',
	'domnode'          => 'dom',
	'simplexmlelement' => 'simplexml',
	'xmlreader'        => 'xmlreader',
	'xmlwriter'        => 'xmlwriter',
	'ziparchive'       => 'zip',
	'finfo'            => 'fileinfo',
);
const IMPLIES          = array(
	'simplexml' => array( 'libxml' ),
	'dom'       => array( 'libxml' ),
	'xmlreader' => array( 'libxml' ),
	'xmlwriter' => array( 'libxml' ),
);
const SKIP             = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

/**
 * The extension a function belongs to, or null when it is not an optional-extension function.
 */
function function_family( string $name ): ?string {
	$name = strtolower( ltrim( $name, '\\' ) );
	if ( in_array( $name, POLYFILLED, true ) ) {
		return null;
	}
	foreach ( FUNCTION_FAMILIES as $prefix => $family ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			return $family;
		}
	}
	return null;
}

/**
 * The extension a class belongs to, or null.
 */
function class_family( string $name ): ?string {
	return CLASS_FAMILIES[ strtolower( ltrim( $name, '\\' ) ) ] ?? null;
}

/**
 * Whether a token is a name (function or class), and its text.
 */
function name_of( $token ): ?string {
	if ( ! is_array( $token ) ) {
		return null;
	}
	$types = array( T_STRING );
	if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
		$types[] = T_NAME_FULLY_QUALIFIED;
	}
	return in_array( $token[0], $types, true ) ? ltrim( $token[1], '\\' ) : null;
}

/**
 * Index of the next (step 1) or previous (step -1) code token, skipping whitespace and comments.
 */
function step( array $t, int $i, int $dir ): ?int {
	for ( $i += $dir; $i >= 0 && $i < count( $t ); $i += $dir ) {
		if ( ! is_array( $t[ $i ] ) || ! in_array( $t[ $i ][0], SKIP, true ) ) {
			return $i;
		}
	}
	return null;
}

/**
 * Whether the name just after token $prev is a method, a static member, a declaration or a class name after `new`.
 */
function not_a_call( array $t, ?int $prev ): bool {
	$types = array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CONST );
	if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
		$types[] = T_NULLSAFE_OBJECT_OPERATOR;
	}
	return null !== $prev && is_array( $t[ $prev ] ) && in_array( $t[ $prev ][0], $types, true );
}

/**
 * Token id: the type constant of an array token, the character of a plain one.
 */
function id_of( $token ) {
	return is_array( $token ) ? $token[0] : $token;
}

/**
 * The body of the arrow function whose `fn` is token $fn: [ index of `=>`, last token of its expression ].
 */
function arrow_body( array $t, int $fn ): array {
	$n = count( $t );
	for ( $j = $fn + 1, $depth = 0; $j < $n; $j++ ) {
		$id = id_of( $t[ $j ] );
		$depth += '(' === $id ? 1 : ( ')' === $id ? -1 : 0 );
		if ( T_DOUBLE_ARROW === $id && 0 === $depth ) {
			break;
		}
	}
	$start = $j;
	for ( $j++, $depth = 0; $j < $n; $j++ ) {
		$id = id_of( $t[ $j ] );
		if ( in_array( $id, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
			++$depth;
		} elseif ( in_array( $id, array( ')', ']', '}' ), true ) ) {
			if ( 0 === $depth-- ) {
				break;
			}
		} elseif ( 0 === $depth && in_array( $id, array( ';', ',', T_CLOSE_TAG ), true ) ) {
			break;
		}
	}
	return array( $start, $j - 1 );
}

/**
 * Every function body: [ first token, last token, true for a closure or arrow function / false for a named one ].
 */
function bodies( array $t ): array {
	$out     = array();
	$braces  = array(); // One entry per open brace: [ start, closure? ] for a function body, null for any other brace.
	$pending = null;    // closure? of a `function` whose body brace is still to come.
	$depth   = 0;       // Paren depth since that `function`, so `use ( ... )` and defaults are skipped.
	foreach ( $t as $i => $token ) {
		$id = id_of( $token );
		if ( T_FUNCTION === $id ) {
			$p = step( $t, $i, -1 );
			if ( null === $p || T_USE !== id_of( $t[ $p ] ) ) { // `use function x;` imports, it declares nothing.
				$n = step( $t, $i, 1 );
				if ( null !== $n && '&' === ( is_array( $t[ $n ] ) ? $t[ $n ][1] : $t[ $n ] ) ) {
					$n = step( $t, $n, 1 );
				}
				$pending = null !== $n && '(' === $t[ $n ];
				$depth   = 0;
			}
		} elseif ( T_FN === $id ) {
			$out[] = array_merge( arrow_body( $t, $i ), array( true ) );
		} elseif ( '(' === $id || ')' === $id ) {
			$depth += '(' === $id ? 1 : -1;
		} elseif ( ';' === $id && 0 === $depth ) {
			$pending = null; // An abstract or interface method: no body.
		} elseif ( in_array( $id, array( '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
			$is_body  = '{' === $id && null !== $pending && 0 === $depth;
			$braces[] = $is_body ? array( $i, $pending ) : null;
			$pending  = $is_body ? null : $pending;
		} elseif ( '}' === $id ) {
			$open = array_pop( $braces );
			if ( $open ) {
				$out[] = array( $open[0], $i, $open[1] );
			}
		}
	}
	return $out;
}

/**
 * Scope map: [ body index owning each token (-1 = file level), parent body of each body, the bodies ].
 */
function scopes( array $t ): array {
	$bodies = bodies( $t );
	usort( $bodies, fn( $a, $b ) => $a[0] <=> $b[0] );
	$owner  = array_fill( 0, count( $t ), -1 );
	$parent = array();
	foreach ( $bodies as $k => $b ) { // Outer bodies start first, so an inner body overwrites its part of the outer.
		$parent[ $k ] = $owner[ $b[0] ];
		for ( $j = $b[0]; $j <= $b[1]; $j++ ) {
			$owner[ $j ] = $k;
		}
	}
	return array( $owner, $parent, $bodies );
}

/**
 * Whether code at token $g runs before the use at token $u in the same call: same body, or $u sits in closures or
 * arrow functions nested inside $g's body. Never across a named function or method.
 */
function covers( array $scopes, int $g, int $u ): bool {
	list( $owner, $parent, $bodies ) = $scopes;
	for ( $s = $owner[ $u ]; $s !== $owner[ $g ]; $s = $parent[ $s ] ) {
		if ( -1 === $s || ! $bodies[ $s ][2] ) {
			return false;
		}
	}
	return true;
}

/**
 * `use` aliases: lower-case alias => the imported name (`use function mb_strtolower as lower;`, `use ZipArchive as Z;`).
 */
function aliases( array $t ): array {
	$out = array();
	foreach ( $t as $i => $token ) {
		if ( T_USE !== id_of( $token ) ) {
			continue;
		}
		$n = step( $t, $i, 1 );
		$n = null !== $n && T_FUNCTION === id_of( $t[ $n ] ) ? step( $t, $n, 1 ) : $n;
		$as = null !== $n ? step( $t, $n, 1 ) : null;
		$to = null !== $as && T_AS === id_of( $t[ $as ] ) ? step( $t, $as, 1 ) : null;
		if ( null !== $to && null !== name_of( $t[ $n ] ) && null !== name_of( $t[ $to ] ) ) {
			$out[ strtolower( name_of( $t[ $to ] ) ) ] = name_of( $t[ $n ] );
		}
	}
	return $out;
}

/**
 * Every guard in the code: [ token index, line, family it proves ].
 */
function guards( array $t ): array {
	$out = array();
	foreach ( $t as $i => $token ) {
		$fn = strtolower( (string) name_of( $token ) );
		if ( ! in_array( $fn, array( 'function_exists', 'class_exists', 'extension_loaded' ), true ) || not_a_call( $t, step( $t, $i, -1 ) ) ) {
			continue;
		}
		$open = step( $t, $i, 1 );
		$arg  = null !== $open && '(' === $t[ $open ] ? step( $t, $open, 1 ) : null;
		if ( null === $arg || ! is_array( $t[ $arg ] ) || T_CONSTANT_ENCAPSED_STRING !== $t[ $arg ][0] ) {
			continue;
		}
		$value  = stripslashes( substr( $t[ $arg ][1], 1, -1 ) );
		$family = 'extension_loaded' === $fn ? strtolower( $value ) : ( 'class_exists' === $fn ? class_family( $value ) : function_family( $value ) );
		if ( null === $family ) {
			continue;
		}
		foreach ( array_merge( array( $family ), IMPLIES[ $family ] ?? array() ) as $proved ) {
			$out[] = array( $i, $token[2], $proved );
		}
	}
	return $out;
}

/**
 * Whether variable $var is checked for false/truthiness before token $before, at or below line $from, in its body.
 */
function handle_checked( array $t, array $scopes, string $var, int $before, int $from ): bool {
	for ( $i = 0; $i < $before; $i++ ) {
		if ( ! is_array( $t[ $i ] ) || T_VARIABLE !== $t[ $i ][0] || $var !== $t[ $i ][1] || $t[ $i ][2] < $from || ! covers( $scopes, $i, $before ) ) {
			continue;
		}
		$n  = step( $t, $i, 1 );
		$p  = step( $t, $i, -1 );
		$pp = null !== $p ? step( $t, $p, -1 ) : null;
		$next = null !== $n ? $t[ $n ] : null;
		$prev = null !== $p ? $t[ $p ] : null;
		if ( '?' === $next || ( is_array( $next ) && in_array( $next[0], array( T_BOOLEAN_AND, T_LOGICAL_AND, T_INSTANCEOF ), true ) ) ) {
			return true;
		}
		if ( '!' === $prev || ( is_array( $prev ) && in_array( $prev[0], array( T_IS_IDENTICAL, T_IS_NOT_IDENTICAL ), true ) ) ) {
			return true;
		}
		if ( '(' === $prev && null !== $pp && is_array( $t[ $pp ] ) && in_array( $t[ $pp ][0], array( T_IF, T_ELSEIF ), true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Findings for one file's source: list of [ line, message ].
 */
function scan_code( string $code ): array {
	$t        = token_get_all( $code );
	$guards   = guards( $t );
	$scopes   = scopes( $t );
	$alias    = aliases( $t );
	$real     = fn( string $n ) => $alias[ strtolower( $n ) ] ?? $n;
	$findings = array();

	foreach ( $t as $i => $token ) {
		$name = name_of( $token );
		if ( null === $name && ! ( is_array( $token ) && T_NEW === $token[0] ) ) {
			continue;
		}
		$next = step( $t, $i, 1 );
		$prev = step( $t, $i, -1 );
		$family = null;
		$what   = '';

		if ( is_array( $token ) && T_NEW === $token[0] ) {
			$cls = null !== $next ? name_of( $t[ $next ] ) : null;
			if ( null !== $cls && null !== ( $family = class_family( $real( $cls ) ) ) ) {
				$what = "new $cls";
			}
		} elseif ( null !== $next && is_array( $t[ $next ] ) && T_DOUBLE_COLON === $t[ $next ][0] ) {
			$after = step( $t, $next, 1 );
			$is_class_const = null !== $after && is_array( $t[ $after ] ) && T_CLASS === $t[ $after ][0];
			if ( ! $is_class_const && null !== ( $family = class_family( $real( $name ) ) ) ) {
				$what = "$name::";
			}
		} elseif ( null !== $next && '(' === $t[ $next ] ) {
			if ( ! not_a_call( $t, $prev ) && null !== ( $family = function_family( $real( $name ) ) ) ) {
				$what = "$name()";
			}
		}
		if ( null === $family || '' === $what ) {
			continue;
		}

		$line = $token[2];
		$from = $line - WINDOW;

		// finfo_* on a handle: the handle must be checked for false first.
		$fn = strtolower( $real( $name ?? '' ) );
		if ( 'fileinfo' === $family && 0 === strpos( $fn, 'finfo_' ) && 'finfo_open' !== $fn ) {
			$arg = step( $t, (int) $next, 1 );
			$var = null !== $arg && is_array( $t[ $arg ] ) && T_VARIABLE === $t[ $arg ][0] ? $t[ $arg ][1] : null;
			if ( null === $var || ! handle_checked( $t, $scopes, $var, $i, $from ) ) {
				$findings[] = array( $line, "$what uses a fileinfo handle " . ( $var ?? '(not a variable)' ) . ' not checked for false in the ' . WINDOW . ' lines before it in the same function (finfo_open() can return false)' );
			}
			continue;
		}

		$ok = false;
		foreach ( $guards as $g ) {
			if ( $g[0] < $i && $g[1] >= $from && $g[2] === $family && covers( $scopes, $g[0], $i ) ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			$findings[] = array( $line, "$what needs the $family extension: no function_exists/class_exists/extension_loaded guard for it in the " . WINDOW . ' lines before it in the same function' );
		}
	}
	return $findings;
}

/**
 * Findings for a plugin directory: list of "relative/path.php:line message".
 */
function scan_dir( string $plugin_dir, array $dirs ): array {
	$files = glob( $plugin_dir . '/*.php' ) ?: array();
	foreach ( $dirs as $dir ) {
		if ( ! is_dir( "$plugin_dir/$dir" ) ) {
			continue;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "$plugin_dir/$dir", \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
	}
	$out = array();
	foreach ( $files as $file ) {
		foreach ( scan_code( (string) file_get_contents( $file ) ) as $f ) {
			$out[] = substr( $file, strlen( $plugin_dir ) + 1 ) . ':' . $f[0] . ' ' . $f[1];
		}
	}
	return $out;
}

if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$plugin_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
	if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) ) {
		fwrite( STDERR, "usage: php extension-guard-check.php <plugin-dir> [dir ...]\n" );
		exit( 2 );
	}
	$dirs     = count( $argv ) > 2 ? array_slice( $argv, 2 ) : array( 'includes', 'templates', 'src' );
	$findings = scan_dir( $plugin_dir, $dirs );
	foreach ( $findings as $line ) {
		echo "Extension guard - $line\n";
	}
	exit( $findings ? 1 : 0 );
}
