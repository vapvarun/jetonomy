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
	// Sits in a sub-category, so the community index draws a category chip
	// group (chip link + chevron toggle) for the control-contrast crawl.
	'jt-qa-chapter' => array(
		'title'    => 'QA Chapter space',
		'type'     => 'forum',
		'desc'     => 'Fixture for the sub-category chips. Safe to delete.',
		'category' => 'jt-qa-chapter-north',
	),
);

/** Parent and child category for the chip group, keyed by slug (child => parent slug). */
$categories = array(
	'jt-qa-chapters'      => array( 'QA Chapters', '' ),
	'jt-qa-chapter-north' => array( 'QA Chapter North', 'jt-qa-chapters' ),
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

	foreach ( array_reverse( array_keys( $categories ) ) as $cat_slug ) {
		$cat = \Jetonomy\Models\Category::find_by_slug( $cat_slug );
		if ( $cat ) {
			\Jetonomy\Models\Category::delete( (int) $cat->id );
		}
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

$category_ids = array();
foreach ( $categories as $cat_slug => list( $cat_name, $parent_slug ) ) {
	$cat                        = \Jetonomy\Models\Category::find_by_slug( $cat_slug );
	$category_ids[ $cat_slug ] = $cat ? (int) $cat->id : (int) \Jetonomy\Models\Category::create(
		array(
			'name'      => $cat_name,
			'slug'      => $cat_slug,
			'parent_id' => $parent_slug ? ( $category_ids[ $parent_slug ] ?? 0 ) : 0,
		)
	);
}

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
				'category_id' => $category_ids[ $spec['category'] ?? '' ] ?? 0,
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

// The Q&A question also carries the states the control-contrast crawl needs to
// see: a reaction chip of the admin's own (is-mine) and one of another member,
// and an image attachment (opens the lightbox). Reactions and attachments are
// Pro, so each step runs only when its route or model is there.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$qa_post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.id FROM {$posts_t} p INNER JOIN {$spaces_t} s ON s.id = p.space_id WHERE s.slug = %s ORDER BY p.id ASC LIMIT 1", 'jt-qa-qna' ) );
if ( $qa_post_id && isset( rest_get_server()->get_routes()['/jetonomy/v1/posts/(?P<id>\d+)/reactions'] ) ) {
	$member  = (int) ( get_users( array( 'role' => 'subscriber', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) )[0] ?? 0 );
	$restore = get_current_user_id();
	foreach ( array( $author => 'heart', $member => 'thumbsup' ) as $uid => $emoji ) {
		if ( ! $uid ) {
			continue;
		}
		wp_set_current_user( $uid );
		$have = rest_do_request( new WP_REST_Request( 'GET', "/jetonomy/v1/posts/{$qa_post_id}/reactions" ) )->get_data();
		// The POST toggles, so only add a reaction this user does not have yet.
		if ( ! in_array( $emoji, (array) ( $have['user_reactions'] ?? array() ), true ) ) {
			$req = new WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$qa_post_id}/reactions" );
			$req->set_param( 'emoji', $emoji );
			rest_do_request( $req );
		}
	}
	wp_set_current_user( $restore );
	$out['reactions_on'] = $qa_post_id;
}
if ( $qa_post_id && defined( 'JETONOMY_PRO_VERSION' ) && 0 === \Jetonomy\Models\Attachment::count_for( 'post', $qa_post_id ) ) {
	$image = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 1, 'fields' => 'ids' ) );
	if ( ! $image ) {
		// Empty media library (fresh install): make a placeholder PNG so the
		// lightbox open state has an image to open.
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			fwrite( STDERR, "seed-qa-content: no image in the media library and no GD to make one; the lightbox step will fail.\n" );
			exit( 1 );
		}
		$img = imagecreatetruecolor( 400, 300 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 59, 125, 216 ) );
		ob_start();
		imagepng( $img );
		$upload = wp_upload_bits( 'jt-qa-image.png', null, ob_get_clean() );
		$img_id = empty( $upload['error'] ) ? wp_insert_attachment(
			array(
				'post_title'     => 'jt-qa-image.png',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			),
			$upload['file']
		) : 0;
		if ( ! $img_id || is_wp_error( $img_id ) ) {
			fwrite( STDERR, "seed-qa-content: could not create the placeholder image; the lightbox step will fail.\n" );
			exit( 1 );
		}
		$image = array( $img_id );
	}
	\Jetonomy\Models\Attachment::link( 'post', $qa_post_id, (int) $image[0] );
	$out['image_on'] = $qa_post_id;
}
// A plain file renders Pro's download chip, a bordered link (second attachment).
if ( $qa_post_id && defined( 'JETONOMY_PRO_VERSION' ) && \Jetonomy\Models\Attachment::count_for( 'post', $qa_post_id ) < 2 ) {
	$upload = wp_upload_bits( 'jt-qa-notes.txt', null, "QA fixture file. Safe to delete.\n" );
	if ( empty( $upload['error'] ) ) {
		$file_id = wp_insert_attachment(
			array(
				'post_title'     => 'jt-qa-notes.txt',
				'post_mime_type' => 'text/plain',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( $file_id && ! is_wp_error( $file_id ) ) {
			\Jetonomy\Models\Attachment::link( 'post', $qa_post_id, (int) $file_id );
			$out['file_on'] = $qa_post_id;
		}
	}
}

// A PDF renders Pro's PDF card (a button with an edge and an "Open viewer"
// link on the subtle surface): third attachment.
if ( $qa_post_id && defined( 'JETONOMY_PRO_VERSION' ) && \Jetonomy\Models\Attachment::count_for( 'post', $qa_post_id ) < 3 ) {
	$pdf    = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
	$upload = wp_upload_bits( 'jt-qa-notes.pdf', null, $pdf );
	if ( empty( $upload['error'] ) ) {
		$file_id = wp_insert_attachment(
			array(
				'post_title'     => 'jt-qa-notes.pdf',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( $file_id && ! is_wp_error( $file_id ) ) {
			\Jetonomy\Models\Attachment::link( 'post', $qa_post_id, (int) $file_id );
			$out['pdf_on'] = $qa_post_id;
		}
	}
}

// A tag on the fixture topic puts tag chips (.jt-tags > a.jt-tag, with a
// hover tint) in the search results for "dark". Idempotent (INSERT IGNORE).
if ( $qa_post_id ) {
	\Jetonomy\Models\Tag::attach_to_post( $qa_post_id, \Jetonomy\Models\Tag::find_or_create( 'dark-mode' ) );
	$out['tag_on'] = $qa_post_id;
}

$out['search_needle'] = JT_QA_SEARCH_NEEDLE;

echo 'FIXTURES ' . wp_json_encode( $out ) . "\n";
