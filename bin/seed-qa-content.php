<?php
/**
 * Seed the CONTENT fixtures the smoke runbook needs.
 *
 * Why this exists
 * ---------------
 * The 1.9.4 combo smoke executed 70 rows and SKIPPED 53 - 57% coverage on a
 * report that still counted as green. Reading the skip reasons, most were not
 * runbook problems or agent laziness. They were missing fixtures: "no Q&A-typed
 * space fixture set up this run", "exact posts_per_page=1 scroll-to-load
 * fixture", "exact 'E2E time picker test' typeahead fixture". The walker could
 * not test accept-answer because nothing on the site was a Q&A space.
 *
 * seed-qa-users.php creates the five access-matrix USERS and seed-qa-pages.php
 * creates one page per shortcode/block. Neither creates community CONTENT, so
 * every row that needs a particular KIND of space had nothing to stand on.
 *
 * What it creates (upsert by slug - re-running changes nothing)
 * ------------------------------------------------------------
 *   jt-qa-qna        type=qa     + one question and one un-accepted answer
 *   jt-qa-ideas      type=ideas  + one idea at the default status
 *   jt-qa-feed       type=feed   + one short-form entry
 *   jt-qa-paging     type=forum  + THREE posts and settings.posts_per_page = 1,
 *                                 so "Load More" is reachable in one click
 *                                 instead of needing 20+ seeded posts
 *   plus one post titled with a deliberately unique string so the search
 *   typeahead row has something that cannot collide with real content.
 *
 * Usage
 * -----
 *   wp eval-file wp-content/plugins/jetonomy/bin/seed-qa-content.php
 *   wp eval-file wp-content/plugins/jetonomy/bin/seed-qa-content.php cleanup
 *
 * Run it from the site root with WP-CLI. On a Local by Flywheel install a bare
 * `wp --path=...` resolves localhost to the WRONG database - pin the site's
 * mysqld socket with -d mysqli.default_socket, or use the Local-aware runner.
 *
 * Prints a single `FIXTURES {json}` line with every id, matching the contract
 * seed-qa-users.php already established so a runner can parse either the same
 * way: `awk '/^FIXTURES /{print $2}'`.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit( 1 );

use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;

/**
 * WP-CLI passes positional args in $args when running eval-file.
 *
 * @var array<int,string> $args
 */
$cleanup = isset( $args[0] ) && 'cleanup' === $args[0];

/** Every space this script owns, keyed by slug. */
$specs = array(
	'jt-qa-qna'    => array(
		'title' => 'QA Q&A space',
		'type'  => 'qa',
		'desc'  => 'Fixture for the accepted-answer flow. Safe to delete.',
	),
	'jt-qa-ideas'  => array(
		'title' => 'QA Ideas space',
		'type'  => 'ideas',
		'desc'  => 'Fixture for idea status and the roadmap view. Safe to delete.',
	),
	'jt-qa-feed'   => array(
		'title' => 'QA Feed space',
		'type'  => 'feed',
		'desc'  => 'Fixture for short-form feed rendering. Safe to delete.',
	),
	'jt-qa-paging' => array(
		'title' => 'QA Paging space',
		'type'  => 'forum',
		'desc'  => 'Fixture with posts_per_page = 1 so Load More is one click away. Safe to delete.',
	),
);

/** Unique enough that the typeahead row cannot match real content. */
const JT_QA_SEARCH_NEEDLE = 'Zarquon paging needle QA-1904';

global $wpdb;
$spaces_t  = \Jetonomy\table( 'spaces' );
$posts_t   = \Jetonomy\table( 'posts' );
$replies_t = \Jetonomy\table( 'replies' );

// ---------------------------------------------------------------- cleanup ---
if ( $cleanup ) {
	$removed = array(
		'spaces'  => 0,
		'posts'   => 0,
		'replies' => 0,
	);

	foreach ( array_keys( $specs ) as $slug ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$spaces_t} WHERE slug = %s", $slug ) );
		if ( ! $space_id ) {
			continue;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$posts_t} WHERE space_id = %d", $space_id ) );
		foreach ( $post_ids as $pid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$removed['replies'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$replies_t} WHERE post_id = %d", (int) $pid ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed['posts'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$posts_t} WHERE space_id = %d", $space_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed['spaces'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$spaces_t} WHERE id = %d", $space_id ) );
	}

	echo 'FIXTURES ' . wp_json_encode( array( 'cleaned' => $removed ) ) . "\n";
	return;
}

// ------------------------------------------------------------------- seed ---
$author = (int) get_users(
	array(
		'role'    => 'administrator',
		'number'  => 1,
		'fields'  => 'ID',
		'orderby' => 'ID',
	)
)[0];

$out = array( 'author_id' => $author, 'spaces' => array(), 'posts' => array() );

foreach ( $specs as $slug => $spec ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$space_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$spaces_t} WHERE slug = %s", $slug ) );

	if ( ! $space_id ) {
		$space_id = (int) Space::create(
			array(
				'title'       => $spec['title'],
				'slug'        => $slug,
				'description' => $spec['desc'],
				// 0, not null: jt_spaces.category_id is NOT NULL, and
				// "uncategorized" is represented as 0 - which is why the model
				// queries `category_id IS NULL OR category_id = 0`. Passing null
				// here fails the insert with "Column 'category_id' cannot be null".
				'category_id' => 0,
				'author_id'   => $author,
				'type'        => $spec['type'],
				'visibility'  => 'public',
				'join_policy' => 'open',
				'status'      => 'active',
			),
			$author
		);
	}

	if ( ! $space_id ) {
		continue;
	}
	$out['spaces'][ $slug ] = $space_id;

	// One post per space, so every type has something to render.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_t} WHERE space_id = %d", $space_id ) );

	if ( 'jt-qa-paging' === $slug ) {
		// posts_per_page = 1 makes Load More reachable after ONE post instead
		// of needing 20+ seeded rows. Space::update merges settings JSON rather
		// than replacing it, so an unrelated key set by another fixture lives.
		$settings                   = Space::get_settings( $space_id );
		$settings['posts_per_page'] = 1;
		Space::update( $space_id, array( 'settings' => wp_json_encode( $settings ) ) );

		for ( $i = $existing; $i < 3; $i++ ) {
			$title = 0 === $i
				? JT_QA_SEARCH_NEEDLE
				: sprintf( 'QA paging fixture post %d', $i + 1 );

			$pid = Post::create(
				array(
					'space_id'  => $space_id,
					'author_id' => $author,
					'title'     => $title,
					'content'   => '<p>Seeded by seed-qa-content.php. Safe to delete.</p>',
					'status'    => 'publish',
					'type'      => 'topic',
				)
			);
			if ( ! is_wp_error( $pid ) ) {
				$out['posts'][] = (int) $pid;
			}
		}
		continue;
	}

	if ( $existing > 0 ) {
		continue;
	}

	$type_map = array(
		'qa'    => 'question',
		'ideas' => 'idea',
		'feed'  => 'status',
	);

	$pid = Post::create(
		array(
			'space_id'  => $space_id,
			'author_id' => $author,
			'title'     => $spec['title'] . ' fixture topic',
			'content'   => '<p>Seeded by seed-qa-content.php. Safe to delete.</p>',
			'status'    => 'publish',
			'type'      => $type_map[ $spec['type'] ] ?? 'topic',
		)
	);

	if ( is_wp_error( $pid ) ) {
		continue;
	}
	$out['posts'][] = (int) $pid;

	// The Q&A space needs an answer that is NOT yet accepted, so the
	// accept-answer row has something to act on.
	if ( 'qa' === $spec['type'] ) {
		$rid = Reply::create(
			array(
				'post_id'   => (int) $pid,
				'author_id' => $author,
				'content'   => '<p>Candidate answer seeded for the accept-answer flow.</p>',
				'status'    => 'publish',
			)
		);
		if ( ! is_wp_error( $rid ) ) {
			$out['qa_reply_id'] = (int) $rid;
		}
	}
}

$out['search_needle'] = JT_QA_SEARCH_NEEDLE;

echo 'FIXTURES ' . wp_json_encode( $out ) . "\n";
