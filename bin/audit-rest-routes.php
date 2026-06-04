<?php
/**
 * audit-rest-routes.php — REST mutation route auth gate.
 *
 * Scans the supplied path for `register_rest_route()` calls and verifies that
 * every mutation route (POST / PUT / PATCH / DELETE / CREATABLE / EDITABLE /
 * DELETABLE) uses one of the approved permission_callback factories:
 *
 *   - Jetonomy\API\REST_Auth::auth_mutation
 *   - Jetonomy\API\REST_Auth::auth_public_write
 *   - REST_Auth::auth_mutation       (short form inside Jetonomy\API ns)
 *   - REST_Auth::auth_public_write
 *
 * Routes registered from the allowlist below are exempted (webhook receivers
 * that authenticate via signature, etc).
 *
 * Usage:
 *   php bin/audit-rest-routes.php includes/
 *   php bin/audit-rest-routes.php ../jetonomy-pro/includes/
 *
 * Exit code: 0 = clean, 1 = violations printed to STDERR.
 *
 * NOTE — 1.4.3 WS2-A: this audit ships BEFORE the route-migration wave (WS2-B).
 * Running it against the current `includes/` will report every existing
 * mutation route as a violation, which is expected: WS2-A introduces only the
 * helper + this gate. The gate is intended to be enforced in CI starting with
 * WS2-B once the migration completes. Until then, treat the output as a
 * progress meter, not a build break.
 *
 * @package Jetonomy
 * @since   1.4.3
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "audit-rest-routes.php must be run from the CLI.\n" );
	exit( 1 );
}

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php bin/audit-rest-routes.php <path>\n" );
	exit( 1 );
}

$target = $argv[1];
if ( ! is_dir( $target ) && ! is_file( $target ) ) {
	fwrite( STDERR, "Path not found: {$target}\n" );
	exit( 1 );
}

/**
 * Files exempted from the mutation-auth gate. Path suffixes — matched with
 * `str_ends_with` against the relative path from the audit target — so the
 * same allowlist works whether the audit is pointed at `includes/` or at the
 * absolute project root.
 *
 * Add an entry ONLY when the route validates auth out-of-band (webhook
 * signature, HMAC, etc).
 */
$allowlist = array(
	// Reply-by-email webhook — signature-validated payload. The handler
	// authenticates by reading the HMAC signature header before doing any
	// work, so the route legitimately accepts `__return_true` — no WP
	// login or nonce exists for the upstream mail relay.
	'includes/extensions/reply-by-email/class-extension.php',
);

/** Approved permission_callback static calls (case-sensitive). */
$approved_callbacks = array(
	'REST_Auth::auth_mutation',
	'REST_Auth::auth_public_write',
	'Jetonomy\\API\\REST_Auth::auth_mutation',
	'Jetonomy\\API\\REST_Auth::auth_public_write',
	'\\Jetonomy\\API\\REST_Auth::auth_mutation',
	'\\Jetonomy\\API\\REST_Auth::auth_public_write',
	// Pro extensions call this thin base-class delegate
	// (Jetonomy_Pro\Extension::rest_auth_mutation) instead of REST_Auth::auth_mutation
	// directly. It resolves REST_Auth lazily at request time behind class_exists()
	// — preventing the route-registration fatal when the free helper is an older
	// build (BC card 9953887096) — and otherwise falls back to an inline login +
	// nonce + capability check that fails closed. Same auth contract as
	// auth_mutation(), so it is an approved mutation callback.
	'rest_auth_mutation',
);

/** Tokens / strings that mark a route as a mutation. */
$mutation_methods = array(
	"'POST'",
	'"POST"',
	"'PUT'",
	'"PUT"',
	"'PATCH'",
	'"PATCH"',
	"'DELETE'",
	'"DELETE"',
	'CREATABLE',
	'EDITABLE',
	'DELETABLE',
);

/**
 * Recursively collect PHP files from $target.
 */
function collect_php_files( string $target ): array {
	if ( is_file( $target ) ) {
		return array( $target );
	}
	$files = array();
	$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, RecursiveDirectoryIterator::SKIP_DOTS ) );
	foreach ( $iter as $f ) {
		/** @var SplFileInfo $f */
		if ( $f->isFile() && substr( $f->getFilename(), -4 ) === '.php' ) {
			// Skip vendor/node_modules just in case.
			$path = $f->getPathname();
			if ( strpos( $path, '/vendor/' ) !== false || strpos( $path, '/node_modules/' ) !== false ) {
				continue;
			}
			$files[] = $path;
		}
	}
	sort( $files );
	return $files;
}

/**
 * Pretty-print a permission_callback expression for the violation report.
 * Joins token strings; drops T_WHITESPACE to keep lines tight.
 */
function stringify_tokens( array $tokens ): string {
	$out = '';
	foreach ( $tokens as $t ) {
		if ( is_array( $t ) ) {
			if ( $t[0] === T_WHITESPACE ) {
				$out .= ' ';
				continue;
			}
			$out .= $t[1];
		} else {
			$out .= $t;
		}
	}
	return trim( preg_replace( '/\s+/', ' ', $out ) );
}

/**
 * Inspect one PHP file's tokens, return an array of violation rows:
 *   [ [ 'line' => int, 'method' => string, 'callback' => string ], ... ]
 */
function audit_file( string $path, array $approved, array $mutation_markers ): array {
	$src    = file_get_contents( $path );
	$tokens = token_get_all( $src );
	$count  = count( $tokens );
	$violations = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$tok = $tokens[ $i ];
		if ( ! is_array( $tok ) ) {
			continue;
		}
		// Match the function name `register_rest_route` as a T_STRING token.
		if ( $tok[0] !== T_STRING || $tok[1] !== 'register_rest_route' ) {
			continue;
		}
		$call_line = $tok[2];

		// Find the opening parenthesis.
		$j = $i + 1;
		while ( $j < $count && ( ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) ) ) {
			$j++;
		}
		if ( $j >= $count || $tokens[ $j ] !== '(' ) {
			continue;
		}

		// Walk forward to the matching close-paren, tracking nesting depth so
		// we capture nested array(...) / [ ... ] / closures correctly.
		$depth         = 1;
		$start         = $j + 1;
		$body_tokens   = array();
		for ( $k = $start; $k < $count; $k++ ) {
			$t = $tokens[ $k ];
			if ( $t === '(' ) {
				$depth++;
				$body_tokens[] = $t;
				continue;
			}
			if ( $t === ')' ) {
				$depth--;
				if ( $depth === 0 ) {
					break;
				}
				$body_tokens[] = $t;
				continue;
			}
			if ( $t === '[' ) {
				$depth++;
				$body_tokens[] = $t;
				continue;
			}
			if ( $t === ']' ) {
				$depth--;
				$body_tokens[] = $t;
				continue;
			}
			$body_tokens[] = $t;
		}

		// Reduce body_tokens to a flat string we can scan textually. Method
		// detection and permission_callback extraction both work on a
		// printable form — clear, simple, and resilient to formatting.
		$body_str = stringify_tokens( $body_tokens );

		// Determine if any mutation marker appears in the args.
		$is_mutation = false;
		foreach ( $mutation_markers as $marker ) {
			if ( stripos( $body_str, $marker ) !== false ) {
				$is_mutation = true;
				break;
			}
		}
		if ( ! $is_mutation ) {
			continue;
		}

		// register_rest_route() accepts either a single-method route array
		// (`[ 'methods' => ..., 'permission_callback' => ... ]`) or a
		// multi-method block (`[ [ ... ], [ ... ] ]`). We need to inspect
		// each method/permission_callback pair individually so that a route
		// block with a READABLE callback followed by a CREATABLE callback
		// only flags violations on the CREATABLE half.
		//
		// Approach — walk every `'methods' => <expr>` and pair it with the
		// next `'permission_callback' => <expr>`. Methods come immediately
		// before permission_callback in WP route registration, so a pair-up
		// pass over their positions is sufficient and resilient to commas
		// embedded inside the `callback` expression (e.g. `[ $this, 'm' ]`).
		preg_match_all(
			'/[\'"]methods[\'"]\s*=>\s*([^,]+?)\s*,/s',
			$body_str,
			$method_matches,
			PREG_OFFSET_CAPTURE
		);
		preg_match_all(
			// `permission_callback` value runs from `=>` until either
			//   - the next `,\s*'<word>' =>` (another key in the same array)
			//   - or `,\s*]` / `]` (end of the route-config array).
			// `\w+[\'"]\s*=>` is the terminator that previously chopped off
			// `'read' )` from inside `auth_mutation( 'read' )` — by adding a
			// preceding `\s` requirement before the next-key `'<word>'` we
			// keep the terminator anchored to key-value boundaries instead.
			'/[\'"]permission_callback[\'"]\s*=>\s*(.+?)(?:,\s+[\'"]\w+[\'"]\s*=>|,\s*[\]\)]|\s*[\]\)])/s',
			$body_str,
			$perm_matches,
			PREG_OFFSET_CAPTURE
		);

		$method_pairs = $method_matches[1] ?? array();
		$perm_pairs   = $perm_matches[1]   ?? array();

		if ( empty( $perm_pairs ) ) {
			// No permission_callback at all in a route the parser considers a
			// mutation — flag once with `(unresolved)` for human review.
			$violations[] = array(
				'line'     => $call_line,
				'callback' => '(unresolved)',
			);
			continue;
		}

		// Pair each `permission_callback` with the most recent preceding
		// `methods` expression. If there are no `methods` matches at all
		// (defensive), fall back to validating every permission_callback.
		foreach ( $perm_pairs as $perm_match ) {
			$perm_offset   = (int) $perm_match[1];
			$callback_text = trim( (string) $perm_match[0] );

			$methods_text = '';
			foreach ( $method_pairs as $method_match ) {
				$method_offset = (int) $method_match[1];
				if ( $method_offset < $perm_offset ) {
					$methods_text = trim( (string) $method_match[0] );
				} else {
					break;
				}
			}

			$pair_is_mutation = false;
			foreach ( $mutation_markers as $marker ) {
				if ( stripos( $methods_text, $marker ) !== false ) {
					$pair_is_mutation = true;
					break;
				}
			}
			if ( ! $pair_is_mutation ) {
				continue;
			}

			$approved_match = false;
			foreach ( $approved as $needle ) {
				if ( strpos( $callback_text, $needle ) !== false ) {
					$approved_match = true;
					break;
				}
			}
			if ( ! $approved_match ) {
				$violations[] = array(
					'line'     => $call_line,
					'callback' => $callback_text !== '' ? $callback_text : '(unresolved)',
				);
			}
		}
	}

	return $violations;
}

$files       = collect_php_files( $target );
$root_anchor = is_dir( $target ) ? realpath( $target ) : dirname( realpath( $target ) );
$violations  = array();

foreach ( $files as $file ) {
	$real = realpath( $file ) ?: $file;

	// Allowlist check — strip everything before the suffix match.
	$skip = false;
	foreach ( $allowlist as $suffix ) {
		// Normalise both sides to forward slashes.
		$needle = str_replace( '\\', '/', $suffix );
		$hay    = str_replace( '\\', '/', $real );
		if ( substr( $hay, -strlen( $needle ) ) === $needle ) {
			$skip = true;
			break;
		}
	}
	if ( $skip ) {
		continue;
	}

	$rows = audit_file( $file, $approved_callbacks, $mutation_methods );
	if ( empty( $rows ) ) {
		continue;
	}
	foreach ( $rows as $row ) {
		$violations[] = sprintf(
			'%s:%d  permission_callback = %s',
			$file,
			$row['line'],
			$row['callback']
		);
	}
}

if ( empty( $violations ) ) {
	fwrite( STDOUT, "audit-rest-routes: OK (no mutation routes missing REST_Auth)\n" );
	exit( 0 );
}

fwrite(
	STDERR,
	"audit-rest-routes: " . count( $violations ) . " violation(s) — mutation routes must use REST_Auth::auth_mutation or auth_public_write:\n"
);
foreach ( $violations as $v ) {
	fwrite( STDERR, "  {$v}\n" );
}
fwrite( STDERR, "\nFix: replace the permission_callback with \\Jetonomy\\API\\REST_Auth::auth_mutation( <caps> ).\n" );
fwrite( STDERR, "WS2-A note: this gate becomes enforcing in CI after WS2-B route migration completes.\n" );
exit( 1 );
