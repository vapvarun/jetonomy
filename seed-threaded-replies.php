<?php
/**
 * Seed threaded replies for post 8 to demonstrate nested threading.
 *
 * Run via WP-CLI: wp eval-file wp-content/plugins/jetonomy/seed-threaded-replies.php
 * Or load once via browser by requiring from a mu-plugin.
 *
 * Safe to run multiple times — checks for existing nested replies first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow running from WP-CLI.
	if ( ! defined( 'WP_CLI' ) ) {
		exit;
	}
}

use Jetonomy\Models\Reply;

// Check if nested replies already exist for post 8.
global $wpdb;
$table = $wpdb->prefix . 'jt_replies';
$existing_nested = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$table} WHERE post_id = 8 AND parent_id IS NOT NULL AND parent_id > 0"
);

if ( $existing_nested > 0 ) {
	if ( defined( 'WP_CLI' ) ) {
		WP_CLI::log( "Nested replies already exist for post 8 ({$existing_nested} found). Skipping." );
	}
	return;
}

$now = current_time( 'mysql' );

// Level 1: Reply to reply #5 (David Chen's structured concurrency comment).
$r1 = Reply::create( [
	'post_id'       => 8,
	'parent_id'     => 5,
	'author_id'     => 3, // Mike Ross
	'content'       => '<p>Structured concurrency is a game changer. We switched to TaskGroups last month and our error handling code is so much cleaner now. No more orphaned tasks!</p>',
	'content_plain' => 'Structured concurrency is a game changer. We switched to TaskGroups last month and our error handling code is so much cleaner now. No more orphaned tasks!',
	'status'        => 'publish',
] );

// Level 2: Reply to Mike's reply.
$r2 = Reply::create( [
	'post_id'       => 8,
	'parent_id'     => $r1,
	'author_id'     => 9, // David Chen
	'content'       => '<p>Exactly! The key insight is that TaskGroups enforce structured lifetimes. When the group exits, you know all tasks are done. No more tracking tasks manually.</p>',
	'content_plain' => 'Exactly! The key insight is that TaskGroups enforce structured lifetimes. When the group exits, you know all tasks are done. No more tracking tasks manually.',
	'status'        => 'publish',
] );

// Level 3: Reply to David's nested reply.
$r3 = Reply::create( [
	'post_id'       => 8,
	'parent_id'     => $r2,
	'author_id'     => 4, // Anika Patel
	'content'       => '<p>One caveat though: exception groups in Python 3.11+ can be tricky. Make sure you handle <code>ExceptionGroup</code> properly or you will miss errors silently.</p>',
	'content_plain' => 'One caveat though: exception groups in Python 3.11+ can be tricky. Make sure you handle ExceptionGroup properly or you will miss errors silently.',
	'status'        => 'publish',
] );

// Level 1: Reply to reply #6 (Anika's FastAPI comment).
$r4 = Reply::create( [
	'post_id'       => 8,
	'parent_id'     => 6,
	'author_id'     => 2, // Sarah Kim
	'content'       => '<p>The FastAPI dependency injection is great but watch out for circular dependencies in large projects. We had to refactor our DI graph twice.</p>',
	'content_plain' => 'The FastAPI dependency injection is great but watch out for circular dependencies in large projects. We had to refactor our DI graph twice.',
	'status'        => 'publish',
] );

// Level 2: Reply to Sarah's comment.
$r5 = Reply::create( [
	'post_id'       => 8,
	'parent_id'     => $r4,
	'author_id'     => 6, // Elena Martinez
	'content'       => '<p>We solved that by using a factory pattern for dependencies. Each module registers its own deps and the container resolves them lazily.</p>',
	'content_plain' => 'We solved that by using a factory pattern for dependencies. Each module registers its own deps and the container resolves them lazily.',
	'status'        => 'publish',
] );

// Level 1: Another reply to reply #7 (timeout discussion).
$r6 = Reply::create( [
	'post_id'       => 8,
	'parent_id'     => 7,
	'author_id'     => 5, // James Liu
	'content'       => '<p>Great point on <code>asyncio.timeout()</code>. We also wrap our external API calls with retry + exponential backoff using tenacity. Works beautifully with async.</p>',
	'content_plain' => 'Great point on asyncio.timeout(). We also wrap our external API calls with retry + exponential backoff using tenacity. Works beautifully with async.',
	'status'        => 'publish',
] );

// Level 2: Reply to James.
$r7 = Reply::create( [
	'post_id'       => 8,
	'parent_id'     => $r6,
	'author_id'     => 15, // Original author of #7
	'content'       => '<p>Tenacity with async is solid. Make sure you set <code>wait=wait_exponential(multiplier=1, max=60)</code> to avoid hammering the upstream service.</p>',
	'content_plain' => 'Tenacity with async is solid. Make sure you set wait=wait_exponential(multiplier=1, max=60) to avoid hammering the upstream service.',
	'status'        => 'publish',
] );

// Level 3: One more deep reply.
$r8 = Reply::create( [
	'post_id'       => 8,
	'parent_id'     => $r7,
	'author_id'     => 10, // Priya Sharma
	'content'       => '<p>We also add jitter to the backoff to prevent thundering herd. <code>wait_random(0, 2)</code> combined with exponential works perfectly.</p>',
	'content_plain' => 'We also add jitter to the backoff to prevent thundering herd. wait_random(0, 2) combined with exponential works perfectly.',
	'status'        => 'publish',
] );

$count = 8;
$msg = "Seeded {$count} nested threaded replies for post 8 (IDs: {$r1}, {$r2}, {$r3}, {$r4}, {$r5}, {$r6}, {$r7}, {$r8}).";

if ( defined( 'WP_CLI' ) ) {
	WP_CLI::success( $msg );
} else {
	echo $msg . "\n";
}
