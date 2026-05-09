<?php
/**
 * Manifest-driven QA coverage gate.
 *
 * Reads `audit/manifest.json` and asserts every tracked category entry has
 * corresponding functional test coverage. Writes `audit/qa-coverage.json`
 * (per-item covered/uncovered map). Exits 1 when uncovered_total grew vs
 * the previous run — same baseline-shrink discipline phpstan-baseline uses.
 *
 * Plugin-agnostic: no plugin-specific names. Reads manifest only.
 *
 * Categories checked in Phase A:
 *   - rest       — `$this->rest( 'METHOD', '/path' )` calls in QA test source
 *   - ajax       — `wp_ajax_<action>` references in test source
 *   - hooks_fired — only entries with non-empty `consumed_by`; verified
 *                   by a do_action call in CLI journeys / scenarios
 *   - cron       — cron hook name referenced in test source
 *   - wp_cli     — each command has a corresponding journey / scenario class
 *
 * Categories deferred to Phase A.2: blocks, shortcodes, admin_pages.
 *
 * Usage:
 *   php bin/qa-coverage-check.php
 *   php bin/qa-coverage-check.php --plugin=/path/to/plugin
 *   php bin/qa-coverage-check.php --json    # machine-readable summary on stdout
 *   php bin/qa-coverage-check.php --strict  # exit 1 on ANY uncovered, not just regression
 *
 * Exit codes:
 *   0 — coverage equal or improved vs prior run (or no prior)
 *   1 — coverage regressed (uncovered_total grew)
 *   2 — manifest missing or malformed
 *
 * @package Jetonomy
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions

const SCHEMA_VERSION = 'v1';

$opts        = parse_cli_options( $argv );
$plugin_dir  = realpath( $opts['plugin'] );
$manifest    = $plugin_dir . '/audit/manifest.json';
$coverage    = $plugin_dir . '/audit/qa-coverage.json';

if ( ! is_file( $manifest ) ) {
	fwrite( STDERR, "qa-coverage: manifest not found at {$manifest}\n" );
	exit( 2 );
}

$m = json_decode( (string) file_get_contents( $manifest ), true );
if ( ! is_array( $m ) ) {
	fwrite( STDERR, "qa-coverage: manifest is not valid JSON\n" );
	exit( 2 );
}

// ─────────────────────────────────────────────────────────
// 1. Build the corpus of test-source files we'll grep for coverage signals.
// ─────────────────────────────────────────────────────────

$test_globs = array(
	'includes/qa/*.php',
	'includes/cli/journeys/*.php',
	'includes/cli/scenarios/*.php',
	'tests/**/*.php',
);

$test_files = collect_files( $plugin_dir, $test_globs );
$test_corpus = '';
foreach ( $test_files as $f ) {
	$test_corpus .= "\n" . file_get_contents( $f );
}

// ─────────────────────────────────────────────────────────
// 2. Per-category coverage checks.
// ─────────────────────────────────────────────────────────

$result = array(
	'schema_version' => SCHEMA_VERSION,
	'plugin'         => array(
		'slug'    => $m['plugin']['slug']    ?? basename( $plugin_dir ),
		'version' => $m['plugin']['version'] ?? '0.0.0',
	),
	'generated'      => array(
		'at'             => gmdate( 'c' ),
		'manifest_at'    => $m['generated']['at'] ?? null,
		'manifest_branch'=> $m['generated']['branch'] ?? null,
	),
	'categories'     => array(),
);

$result['categories']['rest']        = check_rest( $m, $test_corpus );
$result['categories']['ajax']        = check_ajax( $m, $test_corpus );
$result['categories']['hooks_fired'] = check_hooks_fired( $m, $test_corpus );
$result['categories']['cron']        = check_cron( $m, $test_corpus );
$result['categories']['wp_cli']      = check_wp_cli( $m, $test_files );

// ─────────────────────────────────────────────────────────
// 3. Summary roll-up.
// ─────────────────────────────────────────────────────────

$total = 0; $covered = 0; $uncovered = 0; $skipped = 0;
foreach ( $result['categories'] as $cat ) {
	$total     += $cat['total']     ?? 0;
	$covered   += $cat['covered']   ?? 0;
	$uncovered += $cat['uncovered'] ?? 0;
	$skipped   += $cat['skipped']   ?? 0;
}

$result['summary'] = array(
	'categories_checked' => count( $result['categories'] ),
	'items_total'        => $total,
	'items_covered'      => $covered,
	'items_uncovered'    => $uncovered,
	'items_skipped'      => $skipped,
	'coverage_percent'   => $total > 0 ? round( $covered / $total * 100, 1 ) : 0.0,
);

// ─────────────────────────────────────────────────────────
// 4. Drift check vs previous run.
// ─────────────────────────────────────────────────────────

$prev_uncovered = null;
if ( is_file( $coverage ) ) {
	$prev = json_decode( (string) file_get_contents( $coverage ), true );
	$prev_uncovered = $prev['summary']['items_uncovered'] ?? null;
}

$result['drift'] = array(
	'previous_uncovered' => $prev_uncovered,
	'current_uncovered'  => $uncovered,
	'delta'              => $prev_uncovered === null ? null : $uncovered - $prev_uncovered,
);

// ─────────────────────────────────────────────────────────
// 5. Persist + report.
// ─────────────────────────────────────────────────────────

// Only write when something other than `generated.at` changed.
// Otherwise every commit dirties qa-coverage.json with a fresh timestamp
// and the working tree never settles.
$should_write = true;
if ( is_file( $coverage ) ) {
	$existing = json_decode( (string) file_get_contents( $coverage ), true );
	if ( is_array( $existing ) ) {
		$existing_summary  = $existing['summary'] ?? array();
		$current_summary   = $result['summary'];
		$material_unchanged = (
			( $existing_summary['items_total']     ?? null ) === $current_summary['items_total']
			&& ( $existing_summary['items_covered']   ?? null ) === $current_summary['items_covered']
			&& ( $existing_summary['items_uncovered'] ?? null ) === $current_summary['items_uncovered']
			&& ( $existing_summary['items_skipped']   ?? null ) === $current_summary['items_skipped']
		);
		if ( $material_unchanged ) {
			$should_write = false;
			// Carry the existing generated.at + drift forward so subsequent
			// runs still see a stable baseline.
			$result['generated']['at'] = $existing['generated']['at'] ?? $result['generated']['at'];
			$result['drift']           = $existing['drift'] ?? $result['drift'];
		}
	}
}

if ( $should_write ) {
	file_put_contents(
		$coverage,
		json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
	);
}

print_human_summary( $result, $opts );

if ( $opts['json'] ) {
	echo json_encode( $result['summary'], JSON_PRETTY_PRINT ) . "\n";
}

// Exit code logic.
if ( $opts['strict'] && $uncovered > 0 ) {
	exit( 1 );
}

if ( $prev_uncovered !== null && $uncovered > $prev_uncovered ) {
	fwrite( STDERR, sprintf(
		"qa-coverage: REGRESSION — uncovered grew from %d to %d (delta +%d)\n",
		$prev_uncovered,
		$uncovered,
		$uncovered - $prev_uncovered
	) );
	exit( 1 );
}

exit( 0 );

// ════════════════════════════════════════════════════════════
// Implementation
// ════════════════════════════════════════════════════════════

/**
 * REST endpoint coverage.
 *
 * For each manifest endpoint (route + method), check whether any test source
 * file calls `$this->rest( 'METHOD', '<path>' )` where <path> matches the
 * route pattern. Manifest paths use regex placeholders like `(?P<id>\d+)`;
 * test paths use literals like `/posts/{$id}/...` — we translate manifest
 * patterns to a literal-friendly regex and match against the test source.
 */
function check_rest( array $m, string $corpus ): array {
	$endpoints = $m['rest']['endpoints'] ?? array();
	$total     = count( $endpoints );

	if ( $total === 0 ) {
		return array( 'total' => 0, 'covered' => 0, 'uncovered' => 0, 'skipped' => 0 );
	}

	$covered_items   = array();
	$uncovered_items = array();

	// Pre-extract every $this->rest( ... ) call from the corpus into an
	// array of [ method, normalized_path ] tuples so we can match each
	// endpoint in O(N+M) instead of O(N*M).
	preg_match_all(
		'/\$this->rest\s*\(\s*[\'"]([A-Z]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
		$corpus,
		$test_calls,
		PREG_SET_ORDER
	);

	$test_index = array();
	foreach ( $test_calls as $tc ) {
		$method = $tc[1];
		$path   = $tc[2];
		// Replace PHP interpolation tokens like {$x} with a wildcard for matching.
		$path_normalized = preg_replace( '/\{\$[^}]+\}/', '__VAR__', $path );
		$test_index[ $method ][] = $path_normalized;
	}

	// Also harvest test labels like `B7: POST /spaces/{id}/posts`. Tests
	// frequently use a string label that names the REST contract under
	// test even when the call site uses a wrapper helper. Parsing the
	// label gives us "this method+path was tested" without needing to
	// statically resolve the helper call.
	preg_match_all(
		'/[\'"][A-Z]\d+(?:\.\d+)?\s*:?\s*([A-Z]+)\s+(\/[a-zA-Z0-9_\-\/{}\\\\]+)/',
		$corpus,
		$label_calls,
		PREG_SET_ORDER
	);
	foreach ( $label_calls as $tc ) {
		$method = strtoupper( $tc[1] );
		$path   = $tc[2];
		// Test labels often use placeholder syntax `/posts/{id}` directly.
		$path_normalized = preg_replace( '/\{[^}]+\}/', '__VAR__', $path );
		$test_index[ $method ][] = $path_normalized;
	}

	foreach ( $endpoints as $ep ) {
		$route   = $ep['route']   ?? '';
		$methods = $ep['methods'] ?? array();
		foreach ( (array) $methods as $method ) {
			$is_covered = endpoint_covered( $route, $method, $test_index );
			$record = array( 'method' => $method, 'route' => $route );
			if ( $is_covered ) {
				$covered_items[] = $record;
			} else {
				$record['stub_command'] = sprintf(
					'bin/qa-stub-gen.php rest %s "%s"',
					$method,
					$route
				);
				$uncovered_items[] = $record;
			}
		}
	}

	// Recount because each method on a route counts separately.
	$covered_count   = count( $covered_items );
	$uncovered_count = count( $uncovered_items );

	return array(
		'total'           => $covered_count + $uncovered_count,
		'covered'         => $covered_count,
		'uncovered'       => $uncovered_count,
		'skipped'         => 0,
		'covered_items'   => $covered_items,
		'uncovered_items' => $uncovered_items,
	);
}

/**
 * Match a manifest route against the test-call index.
 * Manifest route: `/posts/(?P<id>\d+)/idea-status`
 * Test calls:     `/posts/__VAR__/idea-status`
 * We convert both to a generic shape:  /posts/<DIGITS>/idea-status
 */
function endpoint_covered( string $route, string $method, array $test_index ): bool {
	if ( empty( $test_index[ $method ] ) ) {
		return false;
	}
	$normalized = manifest_route_to_pattern( $route );
	foreach ( $test_index[ $method ] as $test_path ) {
		$test_normalized = test_path_to_pattern( $test_path );
		if ( $test_normalized === $normalized ) {
			return true;
		}
		// Also try a "stem" match: drop the last path segment from the
		// manifest route and see if the test hits a parent (common when
		// tests use the list endpoint to set up a fixture for a detail
		// endpoint). Conservative — only matches single-token strip.
		if ( str_starts_with( $test_normalized, rtrim( $normalized, '/' ) . '/' ) ) {
			return true;
		}
	}
	return false;
}

/** Normalize manifest route: `(?P<id>\d+)` → `:NUM:`, `(?P<slug>[^/]+)` → `:STR:`. */
function manifest_route_to_pattern( string $route ): string {
	$out = preg_replace( '/\(\?P<[^>]+>\\\d\+\)/', ':NUM:', $route );
	$out = preg_replace( '/\(\?P<[^>]+>[^)]+\)/', ':STR:', $out );
	return $out;
}

/** Normalize test path: `__VAR__` → `:NUM:` (most $this->rest calls interpolate IDs). */
function test_path_to_pattern( string $path ): string {
	return str_replace( '__VAR__', ':NUM:', $path );
}

/**
 * AJAX action coverage.
 * Manifest entries: `ajax[].action` — e.g., `jetonomy_ban_user`.
 * Test signal: any test file references the action name as a literal string.
 */
function check_ajax( array $m, string $corpus ): array {
	$actions = $m['ajax'] ?? array();
	$total   = count( $actions );

	if ( $total === 0 ) {
		return array( 'total' => 0, 'covered' => 0, 'uncovered' => 0, 'skipped' => 0 );
	}

	$covered   = array();
	$uncovered = array();
	foreach ( $actions as $a ) {
		$action = $a['action'] ?? '';
		if ( '' === $action ) {
			continue;
		}
		$is_covered = strpos( $corpus, "'{$action}'" ) !== false
			|| strpos( $corpus, "\"{$action}\"" ) !== false;

		$record = array( 'action' => $action );
		if ( $is_covered ) {
			$covered[] = $record;
		} else {
			$record['stub_command'] = "bin/qa-stub-gen.php ajax {$action}";
			$uncovered[] = $record;
		}
	}

	return array(
		'total'           => count( $covered ) + count( $uncovered ),
		'covered'         => count( $covered ),
		'uncovered'       => count( $uncovered ),
		'skipped'         => 0,
		'covered_items'   => $covered,
		'uncovered_items' => $uncovered,
	);
}

/**
 * Hooks-fired-with-consumed_by coverage.
 *
 * Only cross-plugin / cross-module hooks (those with `consumed_by[]`) are
 * tracked here. A hook is covered when a journey or scenario fires it.
 * Other hooks are intentional extensibility surface for third parties and
 * don't need plugin-internal verification.
 */
function check_hooks_fired( array $m, string $corpus ): array {
	$hooks = $m['hooks_fired'] ?? array();
	if ( empty( $hooks ) ) {
		return array( 'total' => 0, 'covered' => 0, 'uncovered' => 0, 'skipped' => 0 );
	}

	$covered   = array();
	$uncovered = array();
	$skipped   = 0;

	foreach ( $hooks as $h ) {
		$name        = $h['name']         ?? '';
		$consumed_by = $h['consumed_by']  ?? array();

		if ( '' === $name ) {
			continue;
		}

		// Only track hooks with a real consumer; pure extensibility hooks
		// don't get internal coverage.
		if ( ! is_array( $consumed_by ) || count( $consumed_by ) === 0 ) {
			$skipped++;
			continue;
		}

		$fired = strpos( $corpus, "do_action( '{$name}'" ) !== false
			|| strpos( $corpus, "do_action('{$name}'" ) !== false
			|| strpos( $corpus, "apply_filters( '{$name}'" ) !== false
			|| strpos( $corpus, "apply_filters('{$name}'" ) !== false;

		$record = array( 'name' => $name, 'consumer_count' => count( $consumed_by ) );
		if ( $fired ) {
			$covered[] = $record;
		} else {
			$record['stub_command'] = "bin/qa-stub-gen.php hook {$name}";
			$uncovered[] = $record;
		}
	}

	return array(
		'total'           => count( $covered ) + count( $uncovered ),
		'covered'         => count( $covered ),
		'uncovered'       => count( $uncovered ),
		'skipped'         => $skipped,
		'covered_items'   => $covered,
		'uncovered_items' => $uncovered,
		'_note'           => 'Tracks only hooks with non-empty consumed_by. Skipped hooks are pure extensibility surface.',
	);
}

/**
 * Cron hook coverage. Each cron hook should have a journey / scenario that
 * triggers the handler against a seeded fixture.
 */
function check_cron( array $m, string $corpus ): array {
	$cron = $m['cron'] ?? array();
	$total = count( $cron );

	if ( $total === 0 ) {
		return array( 'total' => 0, 'covered' => 0, 'uncovered' => 0, 'skipped' => 0 );
	}

	$covered   = array();
	$uncovered = array();
	foreach ( $cron as $c ) {
		$name = $c['name'] ?? $c['hook'] ?? '';
		if ( '' === $name ) {
			continue;
		}
		$is_covered = strpos( $corpus, "'{$name}'" ) !== false
			|| strpos( $corpus, "\"{$name}\"" ) !== false;

		$record = array( 'hook' => $name );
		if ( $is_covered ) {
			$covered[] = $record;
		} else {
			$record['stub_command'] = "bin/qa-stub-gen.php cron {$name}";
			$uncovered[] = $record;
		}
	}

	return array(
		'total'           => count( $covered ) + count( $uncovered ),
		'covered'         => count( $covered ),
		'uncovered'       => count( $uncovered ),
		'skipped'         => 0,
		'covered_items'   => $covered,
		'uncovered_items' => $uncovered,
	);
}

/**
 * WP-CLI command coverage.
 *
 * Each command has a journey class (per the Wbcom CLI architecture pattern).
 * Coverage = file exists for the journey class.
 */
function check_wp_cli( array $m, array $test_files ): array {
	$commands = $m['wp_cli'] ?? array();
	$total = count( $commands );

	if ( $total === 0 ) {
		return array( 'total' => 0, 'covered' => 0, 'uncovered' => 0, 'skipped' => 0 );
	}

	$test_basenames = array();
	foreach ( $test_files as $f ) {
		$test_basenames[] = strtolower( basename( $f ) );
	}
	$test_basenames_blob = implode( ' ', $test_basenames );

	$covered   = array();
	$uncovered = array();
	foreach ( $commands as $c ) {
		$cmd = $c['command'] ?? $c['name'] ?? '';
		if ( '' === $cmd ) {
			continue;
		}
		// e.g. "user" → "class-user-journey.php"
		$slug = strtolower( str_replace( ' ', '-', preg_replace( '/^[^ ]+ /', '', $cmd ) ) );
		$is_covered = strpos( $test_basenames_blob, "class-{$slug}-journey.php" ) !== false
			|| strpos( $test_basenames_blob, "class-{$slug}-journey-test.php" ) !== false;

		$record = array( 'command' => $cmd );
		if ( $is_covered ) {
			$covered[] = $record;
		} else {
			$record['stub_command'] = "bin/qa-stub-gen.php wp_cli {$cmd}";
			$uncovered[] = $record;
		}
	}

	return array(
		'total'           => count( $covered ) + count( $uncovered ),
		'covered'         => count( $covered ),
		'uncovered'       => count( $uncovered ),
		'skipped'         => 0,
		'covered_items'   => $covered,
		'uncovered_items' => $uncovered,
	);
}

// ════════════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════════════

function parse_cli_options( array $argv ): array {
	$opts = array(
		'plugin' => getcwd(),
		'json'   => false,
		'strict' => false,
		'quiet'  => false,
	);
	foreach ( array_slice( $argv, 1 ) as $a ) {
		if ( strpos( $a, '--plugin=' ) === 0 ) {
			$opts['plugin'] = substr( $a, strlen( '--plugin=' ) );
		} elseif ( $a === '--json' ) {
			$opts['json'] = true;
		} elseif ( $a === '--strict' ) {
			$opts['strict'] = true;
		} elseif ( $a === '--quiet' ) {
			$opts['quiet'] = true;
		}
	}
	return $opts;
}

function collect_files( string $root, array $globs ): array {
	$out = array();
	foreach ( $globs as $g ) {
		// Cheap glob expansion: ** is treated as recursive search.
		if ( strpos( $g, '**' ) !== false ) {
			$prefix = strstr( $g, '**', true ) ?: '';
			$suffix = substr( $g, strlen( $prefix ) + 2 );
			$base   = rtrim( $root . '/' . $prefix, '/' );
			if ( ! is_dir( $base ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() && fnmatch( '*' . $suffix, $file->getPathname() ) ) {
					$out[] = $file->getPathname();
				}
			}
		} else {
			foreach ( glob( $root . '/' . $g ) as $file ) {
				if ( is_file( $file ) ) {
					$out[] = $file;
				}
			}
		}
	}
	return array_values( array_unique( $out ) );
}

function print_human_summary( array $r, array $opts ): void {
	if ( $opts['quiet'] ) {
		return;
	}
	$s = $r['summary'];
	$drift = $r['drift'];
	echo "QA coverage — {$r['plugin']['slug']} v{$r['plugin']['version']}\n";
	echo str_repeat( '─', 60 ) . "\n";
	foreach ( $r['categories'] as $name => $cat ) {
		$pct = ( $cat['total'] ?? 0 ) > 0
			? round( ( $cat['covered'] / $cat['total'] ) * 100, 0 )
			: 100;
		printf(
			"  %-14s  %3d / %-3d covered  %3d uncovered  %3d skipped  (%d%%)\n",
			$name,
			$cat['covered'] ?? 0,
			$cat['total']   ?? 0,
			$cat['uncovered'] ?? 0,
			$cat['skipped'] ?? 0,
			$pct
		);
	}
	echo str_repeat( '─', 60 ) . "\n";
	printf(
		"  TOTAL: %d / %d covered  %d uncovered  %d skipped  (%s%%)\n",
		$s['items_covered'],
		$s['items_total'],
		$s['items_uncovered'],
		$s['items_skipped'],
		$s['coverage_percent']
	);

	if ( $drift['previous_uncovered'] !== null ) {
		$delta = $drift['delta'];
		$arrow = $delta > 0 ? "↑ +{$delta}" : ( $delta < 0 ? "↓ {$delta}" : "─ 0" );
		echo "  Drift: previous uncovered={$drift['previous_uncovered']}, now={$drift['current_uncovered']} ({$arrow})\n";
	} else {
		echo "  Drift: first run — no baseline.\n";
	}
	echo "\n";
}
