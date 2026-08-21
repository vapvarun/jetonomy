<?php
/**
 * The caching gate (Basecamp 10161156405).
 *
 * Nothing in the Jetonomy caching set counts as done until this passes against
 * a REAL persistent object cache. The failure mode it exists to catch: on a
 * default install `wp_cache_*` is per-request memory, so every caching test
 * passes trivially - including one with a completely broken invalidation path -
 * because nothing survives the request boundary. A caching change verified
 * without a drop-in has not been verified.
 *
 * So check 0 is not a formality. If it fails, the rest of the run is
 * meaningless and the script refuses to continue rather than printing a green
 * nobody should trust.
 *
 * Usage
 * -----
 *   wp eval-file wp-content/plugins/jetonomy/bin/cache-gate.php
 *
 * Expects the dataset from bin/seed-scale.php (1000+ posts and replies across
 * 20+ spaces and 50+ users).
 *
 * Reading the output: a FAIL here is not necessarily a regression. Several
 * checks assert that a read path IS cached, and some of them are not yet - the
 * gate is also the to-do list for the caching card.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit( 1 );

use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;

global $wpdb;

$pass = 0;
$fail = 0;
$results = [];

/**
 * Record one check.
 *
 * @param string $name    Check label.
 * @param bool   $ok      Whether it passed.
 * @param string $detail  Evidence, printed either way.
 */
$check = function ( string $name, bool $ok, string $detail ) use ( &$pass, &$fail, &$results ): void {
	$ok ? $pass++ : $fail++;
	$results[] = sprintf( '  [%s] %-46s %s', $ok ? 'PASS' : 'FAIL', $name, $detail );
};

/** Count queries run by a callable, with caches cold. */
$measure = function ( callable $fn ) use ( $wpdb ): array {
	$before = $wpdb->num_queries;
	$out    = $fn();
	return [ $wpdb->num_queries - $before, $out ];
};

echo "══ Jetonomy caching gate ══\n\n";

// ── 0. Precondition ───────────────────────────────────────────────────────
$persistent = wp_using_ext_object_cache();
wp_cache_set( 'jt_gate_boundary', 'x', 'jt', 60 );
$check(
	'0. persistent object cache in use',
	$persistent,
	$persistent ? 'wp_using_ext_object_cache() = true' : 'NO DROP-IN — every result below would be meaningless'
);
if ( ! $persistent ) {
	echo implode( "\n", $results ) . "\n\n  ABORTED: stand up a Redis/Memcached drop-in first.\n";
	return;
}

// ── fixtures ──────────────────────────────────────────────────────────────
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$posts_t  = \Jetonomy\table( 'posts' );
$spaces_t = \Jetonomy\table( 'spaces' );
$busiest  = (int) $wpdb->get_var( "SELECT p.id FROM {$posts_t} p INNER JOIN {$spaces_t} s ON s.id = p.space_id LEFT JOIN " . \Jetonomy\table( 'replies' ) . " r ON r.post_id = p.id WHERE s.slug LIKE 'jt-scale-%' GROUP BY p.id ORDER BY COUNT(r.id) DESC LIMIT 1" );
$space_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$posts_t} WHERE id = %d", $busiest ) );
// phpcs:enable

if ( ! $busiest ) {
	echo "  No scale fixtures found — run bin/seed-scale.php first.\n";
	return;
}

// ── 1. Repeat thread view is cheaper the second time ──────────────────────
wp_cache_flush();
list( $q1 ) = $measure(
	static function () use ( $busiest ) {
		Post::find( $busiest );
		return Reply::get_threaded( $busiest );
	}
);
list( $q2 ) = $measure(
	static function () use ( $busiest ) {
		Post::find( $busiest );
		return Reply::get_threaded( $busiest );
	}
);
$check(
	'1. second thread view costs fewer queries',
	$q2 < $q1,
	sprintf( 'cold=%d warm=%d', $q1, $q2 )
);

// ── 2. Replying invalidates the thread ────────────────────────────────────
$before_count = count( Reply::get_threaded( $busiest ) );
$new_reply    = Reply::create(
	[
		'post_id'   => $busiest,
		'author_id' => 1,
		'content'   => '<p>Gate invalidation probe.</p>',
		'status'    => 'publish',
	]
);
$after = Reply::get_threaded( $busiest );

// get_threaded() returns OBJECTS with nested `children`, so a scalar walk
// never matches - it just casts stdClass to int and warns. Recurse properly.
$collect = static function ( array $nodes ) use ( &$collect ): array {
	$ids = [];
	foreach ( $nodes as $node ) {
		$ids[] = (int) ( is_object( $node ) ? ( $node->id ?? 0 ) : ( $node['id'] ?? 0 ) );
		$kids  = is_object( $node ) ? ( $node->children ?? [] ) : ( $node['children'] ?? [] );
		if ( $kids ) {
			$ids = array_merge( $ids, $collect( (array) $kids ) );
		}
	}
	return $ids;
};
$found = in_array( (int) $new_reply, $collect( $after ), true );
$check(
	'2. new reply appears without waiting out a TTL',
	$found,
	$found ? 'invalidation fired' : sprintf( 'reply %d missing from the re-read thread', (int) $new_reply )
);
if ( ! is_wp_error( $new_reply ) ) {
	Reply::delete( (int) $new_reply );
}

// ── 3. Null-hit regression ────────────────────────────────────────────────
// Redis materialises a stored null as an empty string; remember() treats that
// as a hit and the caller fatals with a TypeError. remember_object() exists
// precisely for this, so a deleted post must be requestable twice in safety.
$ghost = 999999999;
$err   = '';
try {
	Post::find( $ghost );
	Post::find( $ghost );
	$ok = true;
} catch ( \Throwable $e ) {
	$ok  = false;
	$err = get_class( $e ) . ': ' . $e->getMessage();
}
$check(
	'3. deleted/missing post survives a second read',
	$ok,
	$ok ? 'no TypeError on the cached miss' : $err
);

// ── 4. Space listing repeat read ──────────────────────────────────────────
wp_cache_flush();
list( $s1 ) = $measure( static fn () => Space::find( $space_id ) );
list( $s2 ) = $measure( static fn () => Space::find( $space_id ) );
$check(
	'4. repeat space read is cached',
	$s2 < $s1,
	sprintf( 'cold=%d warm=%d', $s1, $s2 )
);

// ── 5. Nothing outlives its declared TTL ──────────────────────────────────
wp_cache_set( 'jt_gate_ttl', 'v', 'jt', 1 );
sleep( 2 );
// The drop-in keeps a per-process copy in front of Redis, so a plain get here
// answers from local memory and "passes" whatever Redis did with the TTL.
// Drop the runtime layer first so the read actually reaches the server.
if ( function_exists( 'wp_cache_flush_runtime' ) ) {
	wp_cache_flush_runtime();
}
$expired = wp_cache_get( 'jt_gate_ttl', 'jt' );
$check(
	'5. cached value does not outlive its TTL',
	false === $expired || '' === $expired || null === $expired,
	false === $expired ? 'expired on schedule' : 'still present after TTL: ' . var_export( $expired, true )
);

// ── 6. A write survives the runtime layer ─────────────────────────────────
// Set AFTER the flushes above - the first version of this check asserted a key
// that checks 1 and 4 had already wiped, and reported the harness's own
// bookkeeping as a product failure.
wp_cache_set( 'jt_gate_persist', 'survives', 'jt', 300 );
if ( function_exists( 'wp_cache_flush_runtime' ) ) {
	wp_cache_flush_runtime();
}
$persisted = wp_cache_get( 'jt_gate_persist', 'jt' );
$check(
	'6. write reaches the server, not just local memory',
	'survives' === $persisted,
	'survives' === $persisted ? 'read back after dropping the runtime layer' : 'got ' . var_export( $persisted, true )
);

// ── 7. Per-viewer state must not be shared ────────────────────────────────
// The gate's original seven checks all passed while a thread cache was handing
// one viewer's permissions to every other viewer, because none of them read the
// same key as two different people. This is that check.
$leak_post  = Post::create(
	array(
		'space_id'  => $space_id,
		'author_id' => 1,
		'title'     => 'Gate per-viewer probe',
		'content'   => '<p>q</p>',
		'status'    => 'publish',
	)
);
$leak_reply = Reply::create(
	array(
		'post_id'    => (int) $leak_post,
		'author_id'  => 1,
		'content'    => '<p>GATE-PRIVATE-BODY</p>',
		'status'     => 'publish',
		'is_private' => 1,
	)
);

$body_for = static function ( int $viewer ) use ( $leak_post, $leak_reply ): string {
	wp_set_current_user( $viewer );
	foreach ( Reply::get_threaded( (int) $leak_post ) as $node ) {
		if ( (int) $node->id === (int) $leak_reply ) {
			return (string) ( $node->content ?? '' );
		}
	}
	return '';
};

$as_author = $body_for( 1 );  // primes the entry
$as_guest  = $body_for( 0 );  // reads the same key
$again     = $body_for( 1 );  // did the guest's read poison it?
wp_set_current_user( 1 );

$check(
	'7. private reply not served to a guest from cache',
	false === strpos( $as_guest, 'GATE-PRIVATE-BODY' ),
	false === strpos( $as_guest, 'GATE-PRIVATE-BODY' ) ? 'scrubbed per viewer' : 'LEAKED the private body'
);
$check(
	'8. priming viewer keeps their own access',
	false !== strpos( $as_author, 'GATE-PRIVATE-BODY' ) && false !== strpos( $again, 'GATE-PRIVATE-BODY' ),
	'author sees it before and after the guest read'
);

// ── 9. A block applies on the next read, not at TTL ──────────────────────
$blocker = 1;
$blocked = (int) $wpdb->get_var( "SELECT author_id FROM {$posts_t} WHERE id = " . (int) $busiest );
if ( $blocked && $blocked !== $blocker ) {
	wp_set_current_user( $blocker );
	Reply::get_threaded( $busiest ); // warm it while NOT blocking
	\Jetonomy\Models\BlockedUser::block( $blocker, $blocked );

	$tombstoned = false;
	foreach ( Reply::get_threaded( $busiest ) as $node ) {
		// BlockedUser::apply_tombstone() sets is_blocked_author and blanks the
		// body - NOT is_blocked_hidden, which is the private-reply flag.
		if ( (int) ( $node->author_id ?? 0 ) === $blocked && ! empty( $node->is_blocked_author ) && '' === (string) ( $node->content ?? '' ) ) {
			$tombstoned = true;
			break;
		}
	}
	\Jetonomy\Models\BlockedUser::unblock( $blocker, $blocked );

	$check(
		'9. new block applies to a warm thread immediately',
		$tombstoned,
		$tombstoned ? 'tombstoned on the next read' : 'still visible - waiting out the TTL'
	);
}

if ( ! is_wp_error( $leak_reply ) ) {
	Reply::delete( (int) $leak_reply );
}
if ( ! is_wp_error( $leak_post ) ) {
	Post::delete( (int) $leak_post );
}

echo implode( "\n", $results ) . "\n\n";
printf( "  %d passed, %d failed\n", $pass, $fail );
echo $fail > 0
	? "\n  Gate NOT met. Failures above are the caching card's remaining work.\n"
	: "\n  Gate met.\n";
