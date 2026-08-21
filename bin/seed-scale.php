<?php
/**
 * Seed a dataset big enough for the caching gate to mean something.
 *
 * Why this exists
 * ---------------
 * The caching gate (Basecamp 10161156405) refuses to accept a caching change
 * verified on a handful of rows, for two separate reasons:
 *
 *   1. On a default install `wp_cache_*` is per-request memory. Every caching
 *      test passes trivially - including one with a completely broken
 *      invalidation path - because nothing survives the request boundary.
 *      That is why the gate demands a real drop-in and why the runner checks
 *      wp_using_ext_object_cache() before it starts.
 *   2. A query that looks fine over 20 rows can be the wrong shape at 2000.
 *      A cache that "works" on a thread with three replies has not been shown
 *      to invalidate correctly on one with two hundred.
 *
 * So the numbers here are the gate's numbers: 1000+ posts and replies spread
 * across 20+ spaces and 50+ users, with the volume deliberately UNEVEN - a few
 * busy spaces and a long tail of quiet ones, a few heavily-replied threads and
 * many with none. An evenly-distributed fixture hides exactly the hot-row
 * problems this is meant to expose.
 *
 * Usage
 * -----
 *   wp eval-file wp-content/plugins/jetonomy/bin/seed-scale.php
 *   wp eval-file wp-content/plugins/jetonomy/bin/seed-scale.php cleanup
 *
 * Everything it creates is prefixed `jt-scale-` / `jt_scale_` so cleanup can
 * find it without touching real content. Re-running tops the dataset up rather
 * than duplicating it.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit( 1 );

use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

/** @var array<int,string> $args */
$cleanup = isset( $args[0] ) && 'cleanup' === $args[0];

global $wpdb;
$spaces_t  = \Jetonomy\table( 'spaces' );
$posts_t   = \Jetonomy\table( 'posts' );
$replies_t = \Jetonomy\table( 'replies' );
$members_t = \Jetonomy\table( 'space_members' );

// ---------------------------------------------------------------- cleanup ---
if ( $cleanup ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ids = $wpdb->get_col( "SELECT id FROM {$spaces_t} WHERE slug LIKE 'jt-scale-%'" );
	if ( $ids ) {
		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( "DELETE FROM {$replies_t} WHERE post_id IN (SELECT id FROM {$posts_t} WHERE space_id IN ({$in}))" );
		$wpdb->query( "DELETE FROM {$posts_t} WHERE space_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$members_t} WHERE space_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$spaces_t} WHERE id IN ({$in})" );
	}
	foreach ( get_users( [ 'search' => 'jt_scale_*', 'fields' => 'ID', 'number' => 500 ] ) as $uid ) {
		wp_delete_user( (int) $uid );
	}
	// phpcs:enable
	echo "SCALE cleaned\n";
	return;
}

$t0 = microtime( true );

// ------------------------------------------------------------------ users ---
$user_ids = get_users(
	[
		'search'       => 'jt_scale_*',
		'fields'       => 'ID',
		'number'       => 500,
		'search_columns' => [ 'user_login' ],
	]
);
$user_ids = array_map( 'intval', $user_ids );

for ( $i = count( $user_ids ); $i < 55; $i++ ) {
	$uid = wp_insert_user(
		[
			'user_login'   => 'jt_scale_' . $i,
			'user_pass'    => wp_generate_password(),
			'user_email'   => 'jt_scale_' . $i . '@example.test',
			'display_name' => 'Scale Member ' . $i,
			'role'         => 'subscriber',
		]
	);
	if ( ! is_wp_error( $uid ) ) {
		$user_ids[] = (int) $uid;
	}
}

// --------------------------------------------------------------- category ---
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$cat = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \Jetonomy\table( 'categories' ) . ' WHERE slug = %s', 'jt-scale' ) );
if ( ! $cat ) {
	$cat = (int) Category::create(
		[
			'name' => 'Scale fixtures',
			'slug' => 'jt-scale',
		]
	);
}

// ----------------------------------------------------------------- spaces ---
$types      = [ 'forum', 'qa', 'ideas', 'feed' ];
$space_ids  = [];
for ( $i = 0; $i < 24; $i++ ) {
	$slug = 'jt-scale-' . $i;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$sid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$spaces_t} WHERE slug = %s", $slug ) );
	if ( ! $sid ) {
		$sid = (int) Space::create(
			[
				'title'       => 'Scale space ' . $i,
				'slug'        => $slug,
				'description' => 'Load fixture. Safe to delete.',
				'category_id' => $cat,
				'author_id'   => $user_ids[0],
				'type'        => $types[ $i % 4 ],
				'visibility'  => 'public',
				'join_policy' => 'open',
				'status'      => 'active',
			],
			$user_ids[0]
		);
	}
	if ( $sid ) {
		$space_ids[] = $sid;
	}
}

// Membership is uneven on purpose: space 0 is crowded, the tail is quiet.
foreach ( $space_ids as $idx => $sid ) {
	$want = 0 === $idx ? count( $user_ids ) : max( 3, (int) ( count( $user_ids ) / ( $idx + 2 ) ) );
	for ( $u = 0; $u < $want; $u++ ) {
		SpaceMember::add( $sid, $user_ids[ $u % count( $user_ids ) ], 'member', 'manual' );
	}
}

// ------------------------------------------------------------ posts/replies ---
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$have_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$posts_t} p INNER JOIN {$spaces_t} s ON s.id = p.space_id WHERE s.slug LIKE 'jt-scale-%'" );

$made_posts   = 0;
$made_replies = 0;
$target_posts = 1100;

for ( $n = $have_posts; $n < $target_posts; $n++ ) {
	// Front-load the first few spaces so some are genuinely hot.
	$sid = $space_ids[ $n % 3 === 0 ? 0 : ( $n % count( $space_ids ) ) ];

	$pid = Post::create(
		[
			'space_id'  => $sid,
			'author_id' => $user_ids[ $n % count( $user_ids ) ],
			'title'     => 'Scale topic ' . $n . ' ' . wp_generate_password( 6, false ),
			'content'   => '<p>Body for scale topic ' . $n . '. Seeded by seed-scale.php.</p>',
			'status'    => 'publish',
		]
	);
	if ( is_wp_error( $pid ) ) {
		continue;
	}
	++$made_posts;

	// A long tail of quiet threads and a handful of very busy ones — the
	// distribution a cache actually meets in production.
	$reply_count = 0 === $n % 50 ? 60 : ( 0 === $n % 7 ? 5 : ( 0 === $n % 3 ? 1 : 0 ) );
	for ( $r = 0; $r < $reply_count; $r++ ) {
		$rid = Reply::create(
			[
				'post_id'   => (int) $pid,
				'author_id' => $user_ids[ ( $n + $r ) % count( $user_ids ) ],
				'content'   => '<p>Reply ' . $r . ' on topic ' . $n . '.</p>',
				'status'    => 'publish',
			]
		);
		if ( ! is_wp_error( $rid ) ) {
			++$made_replies;
		}
	}
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$total_posts   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$posts_t} p INNER JOIN {$spaces_t} s ON s.id = p.space_id WHERE s.slug LIKE 'jt-scale-%'" );
$total_replies = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$replies_t} r INNER JOIN {$posts_t} p ON p.id = r.post_id INNER JOIN {$spaces_t} s ON s.id = p.space_id WHERE s.slug LIKE 'jt-scale-%'" );
$busiest       = $wpdb->get_row( "SELECT p.id, COUNT(r.id) c FROM {$posts_t} p INNER JOIN {$spaces_t} s ON s.id = p.space_id LEFT JOIN {$replies_t} r ON r.post_id = p.id WHERE s.slug LIKE 'jt-scale-%' GROUP BY p.id ORDER BY c DESC LIMIT 1" );
// phpcs:enable

echo 'SCALE ' . wp_json_encode(
	[
		'spaces'        => count( $space_ids ),
		'users'         => count( $user_ids ),
		'posts'         => $total_posts,
		'replies'       => $total_replies,
		'new_posts'     => $made_posts,
		'new_replies'   => $made_replies,
		'busiest_post'  => $busiest ? (int) $busiest->id : 0,
		'busiest_count' => $busiest ? (int) $busiest->c : 0,
		'seconds'       => round( microtime( true ) - $t0, 1 ),
	]
) . "\n";
