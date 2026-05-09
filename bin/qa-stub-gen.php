<?php
/**
 * QA test stub generator (Phase B).
 *
 * Reads `audit/manifest.json`, finds the requested category + identifier,
 * appends a starter test block to the appropriate test source file. The
 * stub fails immediately with TODO assertions — human fills in the
 * fixture-specific bits, then the entry moves from uncovered → covered
 * on the next `qa-coverage-check.php` run.
 *
 * Plugin-agnostic: no plugin-specific names. Reads manifest only.
 *
 * Usage:
 *   php bin/qa-stub-gen.php rest GET /spaces
 *   php bin/qa-stub-gen.php rest POST "/posts/(?P<id>\d+)/idea-status"
 *   php bin/qa-stub-gen.php ajax jetonomy_ban_user
 *   php bin/qa-stub-gen.php hook jetonomy_user_left_space
 *   php bin/qa-stub-gen.php cron jetonomy_prune_activity
 *   php bin/qa-stub-gen.php --plugin=/path/to/plugin rest GET /foo
 *
 * Exit codes:
 *   0 — stub generated and appended
 *   1 — manifest entry not found OR target test file missing
 *   2 — invalid arguments
 *
 * @package Jetonomy
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.WP.AlternativeFunctions

if ( $argc < 2 ) {
	usage_exit();
}

$opts = array( 'plugin' => getcwd() );
$pos  = array();
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( strpos( $a, '--plugin=' ) === 0 ) {
		$opts['plugin'] = substr( $a, strlen( '--plugin=' ) );
	} elseif ( '--help' === $a || '-h' === $a ) {
		usage_exit( 0 );
	} else {
		$pos[] = $a;
	}
}

if ( count( $pos ) < 2 ) {
	usage_exit();
}

$category = $pos[0];
$args     = array_slice( $pos, 1 );

$plugin_dir = realpath( $opts['plugin'] );
$manifest   = $plugin_dir . '/audit/manifest.json';
if ( ! is_file( $manifest ) ) {
	fwrite( STDERR, "qa-stub-gen: manifest not found at {$manifest}\n" );
	exit( 1 );
}

$m = json_decode( (string) file_get_contents( $manifest ), true );
if ( ! is_array( $m ) ) {
	fwrite( STDERR, "qa-stub-gen: manifest is not valid JSON\n" );
	exit( 1 );
}

switch ( $category ) {
	case 'rest':
		[ $method, $route ] = array_pad( $args, 2, '' );
		exit( gen_rest_stub( $plugin_dir, $m, $method, $route ) );
	case 'ajax':
		exit( gen_ajax_stub( $plugin_dir, $m, $args[0] ) );
	case 'hook':
		exit( gen_hook_stub( $plugin_dir, $m, $args[0] ) );
	case 'cron':
		exit( gen_cron_stub( $plugin_dir, $m, $args[0] ) );
	default:
		fwrite( STDERR, "qa-stub-gen: unknown category '{$category}'\n" );
		usage_exit();
}

// ════════════════════════════════════════════════════════════
// Generators
// ════════════════════════════════════════════════════════════

function gen_rest_stub( string $plugin_dir, array $m, string $method, string $route ): int {
	$method = strtoupper( $method );
	if ( '' === $method || '' === $route ) {
		fwrite( STDERR, "qa-stub-gen: rest stub needs METHOD and ROUTE\n" );
		return 2;
	}

	$endpoint = null;
	foreach ( $m['rest']['endpoints'] ?? array() as $ep ) {
		if ( $ep['route'] === $route && in_array( $method, (array) ( $ep['methods'] ?? array() ), true ) ) {
			$endpoint = $ep;
			break;
		}
	}
	if ( null === $endpoint ) {
		fwrite( STDERR, "qa-stub-gen: rest endpoint {$method} {$route} not found in manifest\n" );
		return 1;
	}

	$test_file = $plugin_dir . '/includes/qa/class-rest-tests.php';
	if ( ! is_file( $test_file ) ) {
		fwrite( STDERR, "qa-stub-gen: test file not found at {$test_file}\n" );
		return 1;
	}

	$next_id = next_test_id( file_get_contents( $test_file ), 'B' );
	$slug    = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( $route, '/' ) ) );

	$stub = <<<PHP


	/**
	 * @generated qa-stub-gen — fill in fixture-specific assertions
	 * @covers {$method} {$route}
	 *
	 * Endpoint purpose: {$endpoint['purpose']}
	 * Permission:       {$endpoint['permission']}
	 */
	private function test_{$slug}_{$next_id}(): void {
		\$r = \$this->rest( '{$method}', '{$route}' );
		\$data = \$r->get_data();
		\$this->check( '{$next_id}: {$method} {$route} → 200', 200 === \$r->get_status(), "HTTP {\$r->get_status()}" );
		// TODO: assert response shape matches prepare_*() output
		// TODO: assert auth gate (call as anon → expect 401)
		// TODO: assert error case (invalid input → expect 4xx)
	}
PHP;

	append_before_closing_brace( $test_file, $stub );
	echo "Stub appended to {$test_file}\n";
	echo "  test ID:  {$next_id}\n";
	echo "  endpoint: {$method} {$route}\n";
	echo "  next:     fill in TODOs, run `wp jetonomy qa-actions`, verify pass.\n";
	return 0;
}

function gen_ajax_stub( string $plugin_dir, array $m, ?string $action ): int {
	if ( ! $action ) {
		fwrite( STDERR, "qa-stub-gen: ajax stub needs ACTION name\n" );
		return 2;
	}
	$found = null;
	foreach ( $m['ajax'] ?? array() as $a ) {
		if ( ( $a['action'] ?? '' ) === $action ) {
			$found = $a;
			break;
		}
	}
	if ( null === $found ) {
		fwrite( STDERR, "qa-stub-gen: ajax handler '{$action}' not found in manifest\n" );
		return 1;
	}

	$test_file = $plugin_dir . '/includes/qa/class-rest-tests.php';
	if ( ! is_file( $test_file ) ) {
		fwrite( STDERR, "qa-stub-gen: test file not found at {$test_file}\n" );
		return 1;
	}

	$next_id = next_test_id( file_get_contents( $test_file ), 'X' );
	$slug    = preg_replace( '/^[a-z]+_/', '', $action );

	$stub = <<<PHP


	/**
	 * @generated qa-stub-gen — fill in fixture-specific assertions
	 * @covers wp_ajax_{$action}
	 *
	 * Handler:    {$found['handler']}
	 * Nonce:      {$found['nonce']}
	 * Capability: {$found['capability']}
	 */
	private function test_ajax_{$slug}_{$next_id}(): void {
		\$_POST['action'] = '{$action}';
		\$_POST['_ajax_nonce'] = wp_create_nonce( '{$found['nonce']}' );
		// TODO: seed required POST params
		// TODO: invoke do_action( 'wp_ajax_{$action}' ) and capture wp_send_json_* output
		// TODO: assert response shape + side-effects
		\$this->check( '{$next_id}: wp_ajax_{$action} fires', false, 'TODO — fill stub' );
	}
PHP;

	append_before_closing_brace( $test_file, $stub );
	echo "Stub appended to {$test_file}\n";
	echo "  test ID: {$next_id}\n";
	echo "  action:  wp_ajax_{$action}\n";
	return 0;
}

function gen_hook_stub( string $plugin_dir, array $m, ?string $hook ): int {
	if ( ! $hook ) {
		fwrite( STDERR, "qa-stub-gen: hook stub needs HOOK name\n" );
		return 2;
	}

	$found = null;
	foreach ( $m['hooks_fired'] ?? array() as $h ) {
		if ( ( $h['name'] ?? '' ) === $hook ) {
			$found = $h;
			break;
		}
	}
	if ( null === $found ) {
		fwrite( STDERR, "qa-stub-gen: hook '{$hook}' not in manifest hooks_fired[]\n" );
		return 1;
	}

	$test_file = $plugin_dir . '/includes/qa/class-rest-tests.php';
	if ( ! is_file( $test_file ) ) {
		fwrite( STDERR, "qa-stub-gen: test file not found at {$test_file}\n" );
		return 1;
	}

	$next_id  = next_test_id( file_get_contents( $test_file ), 'H' );
	$slug     = $hook;
	$consumer_count = is_array( $found['consumed_by'] ?? null ) ? count( $found['consumed_by'] ) : 0;

	$stub = <<<PHP


	/**
	 * @generated qa-stub-gen — fill in fixture-specific assertions
	 * @covers do_action( '{$hook}' )
	 *
	 * Hook fired at: {$found['where']}
	 * Args:          {$found['args_count']} ({$next_id})
	 * Consumers:     {$consumer_count}
	 */
	private function test_hook_{$slug}_{$next_id}(): void {
		\$fired = false;
		add_action( '{$hook}', function() use ( &\$fired ) { \$fired = true; }, 1, {$found['args_count']} );
		// TODO: trigger the producing operation that should fire this hook
		// TODO: assert each consumer's side-effect is observable
		\$this->check( '{$next_id}: {$hook} fires from producer', \$fired, '{$hook} did not fire' );
	}
PHP;

	append_before_closing_brace( $test_file, $stub );
	echo "Stub appended to {$test_file}\n";
	echo "  test ID: {$next_id}\n";
	echo "  hook:    {$hook}\n";
	return 0;
}

function gen_cron_stub( string $plugin_dir, array $m, ?string $hook ): int {
	if ( ! $hook ) {
		fwrite( STDERR, "qa-stub-gen: cron stub needs HOOK name\n" );
		return 2;
	}

	$found = null;
	foreach ( $m['cron'] ?? array() as $c ) {
		if ( ( $c['name'] ?? $c['hook'] ?? '' ) === $hook ) {
			$found = $c;
			break;
		}
	}
	if ( null === $found ) {
		fwrite( STDERR, "qa-stub-gen: cron '{$hook}' not in manifest\n" );
		return 1;
	}

	$test_file = $plugin_dir . '/includes/qa/class-rest-tests.php';
	if ( ! is_file( $test_file ) ) {
		fwrite( STDERR, "qa-stub-gen: test file not found at {$test_file}\n" );
		return 1;
	}

	$next_id = next_test_id( file_get_contents( $test_file ), 'C' );

	$stub = <<<PHP


	/**
	 * @generated qa-stub-gen — fill in fixture-specific assertions
	 * @covers cron handler {$hook}
	 *
	 * Schedule: {$found['recurrence']}
	 */
	private function test_cron_{$hook}_{$next_id}(): void {
		// TODO: seed fixture data the handler is meant to act on
		// TODO: directly invoke the handler (do_action( '{$hook}' ))
		// TODO: assert side-effects (rows pruned, notifications sent, etc.)
		\$this->check( '{$next_id}: {$hook} processes seeded fixture', false, 'TODO — fill stub' );
	}
PHP;

	append_before_closing_brace( $test_file, $stub );
	echo "Stub appended to {$test_file}\n";
	echo "  test ID: {$next_id}\n";
	echo "  cron:    {$hook}\n";
	return 0;
}

// ════════════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════════════

function next_test_id( string $contents, string $prefix ): string {
	$max = 0;
	if ( preg_match_all( '/[\'"]' . preg_quote( $prefix, '/' ) . '(\d+)\b/', $contents, $matches ) ) {
		$max = max( array_map( 'intval', $matches[1] ) );
	}
	return $prefix . ( $max + 1 );
}

function append_before_closing_brace( string $file, string $stub ): void {
	$contents = (string) file_get_contents( $file );
	$last_brace = strrpos( $contents, '}' );
	if ( false === $last_brace ) {
		fwrite( STDERR, "qa-stub-gen: no closing brace found in {$file}\n" );
		exit( 1 );
	}
	$out = substr( $contents, 0, $last_brace ) . $stub . "\n" . substr( $contents, $last_brace );
	file_put_contents( $file, $out );
}

function usage_exit( int $code = 2 ): void {
	fwrite( $code === 0 ? STDOUT : STDERR, <<<USAGE
qa-stub-gen — generate QA test stubs from manifest entries

Usage:
  php bin/qa-stub-gen.php rest METHOD PATH
  php bin/qa-stub-gen.php ajax ACTION
  php bin/qa-stub-gen.php hook HOOK_NAME
  php bin/qa-stub-gen.php cron HOOK_NAME

Options:
  --plugin=PATH    plugin root (default: cwd)

Examples:
  php bin/qa-stub-gen.php rest POST "/posts/(?P<id>\d+)/idea-status"
  php bin/qa-stub-gen.php ajax jetonomy_ban_user
  php bin/qa-stub-gen.php hook jetonomy_user_left_space
  php bin/qa-stub-gen.php cron jetonomy_prune_activity

Stubs are appended to includes/qa/class-rest-tests.php with TODO markers.
Fill in the fixture-specific assertions, then re-run qa-actions.

USAGE
	);
	exit( $code );
}
