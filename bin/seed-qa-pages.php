<?php
/**
 * Seed dedicated QA pages for v1.4.0 browser testing.
 *
 * Creates one WP page per shortcode, widget, and block so each surface
 * gets verified in isolation (no inter-feature interference). Re-runnable —
 * upserts by slug, never duplicates.
 *
 * Usage:
 *   wp --path="/Users/varundubey/Local Sites/forums/app/public" \
 *      eval-file wp-content/plugins/jetonomy/bin/seed-qa-pages.php
 *
 * To clean up:
 *   wp --path="..." eval-file wp-content/plugins/jetonomy/bin/seed-qa-pages.php cleanup
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit( 1 );

$cleanup = isset( $args[0] ) && 'cleanup' === $args[0];

// Each entry: [ slug, title, content_block_or_shortcode, scope_label ].
$pages = array(

	// ── Shortcodes (7) — one page each ─────────────────────────────────────
	array( 'jt-qa-sc-recent-posts', 'QA · Shortcode · Recent Posts', '[jetonomy_recent_posts count="10"]', 'shortcode' ),
	array( 'jt-qa-sc-trending-posts', 'QA · Shortcode · Trending Posts', '[jetonomy_trending_posts count="10"]', 'shortcode' ),
	array( 'jt-qa-sc-spaces', 'QA · Shortcode · Spaces', '[jetonomy_spaces]', 'shortcode' ),
	array( 'jt-qa-sc-leaderboard', 'QA · Shortcode · Leaderboard', '[jetonomy_leaderboard limit="10"]', 'shortcode' ),
	array( 'jt-qa-sc-user-profile', 'QA · Shortcode · User Profile', '[jetonomy_user_profile login="admin"]', 'shortcode' ),
	array( 'jt-qa-sc-space-members', 'QA · Shortcode · Space Members', '[jetonomy_space_members slug="announcements"]', 'shortcode' ),
	array( 'jt-qa-sc-compose-topic', 'QA · Shortcode · Compose Topic', '[jetonomy_compose_topic]', 'shortcode' ),

	// ── Blocks (8) — one page each, raw block markup ──────────────────────
	array( 'jt-qa-block-forum-feed', 'QA · Block · Forum Feed', "<!-- wp:jetonomy/forum-feed /-->", 'block' ),
	array( 'jt-qa-block-trending', 'QA · Block · Trending', "<!-- wp:jetonomy/trending /-->", 'block' ),
	array( 'jt-qa-block-space-list', 'QA · Block · Space List', "<!-- wp:jetonomy/space-list /-->", 'block' ),
	array( 'jt-qa-block-leaderboard', 'QA · Block · Leaderboard', "<!-- wp:jetonomy/leaderboard /-->", 'block' ),
	array( 'jt-qa-block-navigation', 'QA · Block · Navigation', "<!-- wp:jetonomy/navigation /-->", 'block' ),
	array( 'jt-qa-block-user-panel', 'QA · Block · User Panel', "<!-- wp:jetonomy/user-panel /-->", 'block' ),
	array( 'jt-qa-block-login', 'QA · Block · Login', "<!-- wp:jetonomy/login /-->", 'block' ),
	array( 'jt-qa-block-compose-topic', 'QA · Block · Compose Topic', "<!-- wp:jetonomy/compose-topic /-->", 'block' ),

	// ── Widgets (4) — Legacy Widget block, one per page ───────────────────
	// `idBase` matches the widget class's first __construct() argument.
	array( 'jt-qa-widget-recent-posts', 'QA · Widget · Recent Posts',
		jt_qa_legacy_widget_block( 'jetonomy_recent_posts', array( 'title' => 'Recent Posts (widget)', 'count' => 8 ) ),
		'widget' ),
	array( 'jt-qa-widget-leaderboard', 'QA · Widget · Leaderboard',
		jt_qa_legacy_widget_block( 'jetonomy_leaderboard', array( 'title' => 'Leaderboard (widget)', 'count' => 8 ) ),
		'widget' ),
	array( 'jt-qa-widget-active-spaces', 'QA · Widget · Active Spaces',
		jt_qa_legacy_widget_block( 'jetonomy_active_spaces', array( 'title' => 'Active Spaces (widget)', 'count' => 8 ) ),
		'widget' ),
	array( 'jt-qa-widget-user-stats', 'QA · Widget · User Stats',
		jt_qa_legacy_widget_block( 'jetonomy_user_stats', array( 'title' => 'User Stats (widget)' ) ),
		'widget' ),
);

// Index page that links to every QA page so a tester can walk the full set.
$index_links = array();
foreach ( $pages as $row ) {
	list( $slug, $title, , $scope ) = $row;
	$index_links[ $scope ][] = sprintf( '<li><a href="/?page_id=__ID__%s">%s</a></li>', $slug, esc_html( $title ) );
}

if ( $cleanup ) {
	foreach ( $pages as $row ) {
		$existing = get_page_by_path( $row[0], OBJECT, 'page' );
		if ( $existing ) {
			wp_delete_post( (int) $existing->ID, true );
			fwrite( STDOUT, "deleted: {$row[0]}\n" );
		}
	}
	$idx = get_page_by_path( 'jt-qa-index', OBJECT, 'page' );
	if ( $idx ) {
		wp_delete_post( (int) $idx->ID, true );
		fwrite( STDOUT, "deleted: jt-qa-index\n" );
	}
	delete_option( 'jetonomy_qa_pages' );
	fwrite( STDOUT, "QA pages cleanup complete.\n" );
	return;
}

$created = 0;
$updated = 0;
$ids     = array();
foreach ( $pages as $row ) {
	list( $slug, $title, $content, $scope ) = $row;
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	$payload  = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
		'meta_input'   => array( '_jetonomy_qa_scope' => $scope ),
	);
	if ( $existing ) {
		$payload['ID'] = $existing->ID;
		$id            = wp_update_post( $payload, true );
		++$updated;
	} else {
		$id = wp_insert_post( $payload, true );
		++$created;
	}
	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, "FAILED {$slug}: " . $id->get_error_message() . "\n" );
		continue;
	}
	$ids[ $slug ] = (int) $id;
	fwrite( STDOUT, "ok   {$slug} -> page #{$id}\n" );
}

// Build / refresh the index page.
$buckets = array(
	'shortcode' => 'Shortcodes',
	'block'     => 'Blocks',
	'widget'    => 'Widgets',
);
$index_html  = '<p>This index lists every QA page seeded for v1.4.0 browser testing. Each page renders one feature in isolation so failures point at exactly one surface.</p>';
foreach ( $buckets as $key => $label ) {
	$index_html .= '<h2>' . esc_html( $label ) . '</h2><ul>';
	foreach ( $pages as $row ) {
		if ( $row[3] !== $key ) {
			continue;
		}
		$pid = $ids[ $row[0] ] ?? 0;
		if ( ! $pid ) {
			continue;
		}
		$index_html .= sprintf(
			'<li><a href="%s">%s</a> &mdash; <code>%s</code></li>',
			esc_url( get_permalink( $pid ) ),
			esc_html( $row[1] ),
			esc_html( $row[0] )
		);
	}
	$index_html .= '</ul>';
}

$idx_existing = get_page_by_path( 'jt-qa-index', OBJECT, 'page' );
$idx_payload  = array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'QA Index — Jetonomy 1.4.0',
	'post_name'    => 'jt-qa-index',
	'post_content' => $index_html,
);
if ( $idx_existing ) {
	$idx_payload['ID'] = $idx_existing->ID;
	$idx_id            = wp_update_post( $idx_payload, true );
} else {
	$idx_id = wp_insert_post( $idx_payload, true );
}
if ( ! is_wp_error( $idx_id ) ) {
	$ids['jt-qa-index'] = (int) $idx_id;
	fwrite( STDOUT, "ok   jt-qa-index -> page #{$idx_id} (" . get_permalink( (int) $idx_id ) . ")\n" );
}

update_option( 'jetonomy_qa_pages', $ids, false );

fwrite( STDOUT, sprintf( "\nDone. created=%d updated=%d total=%d\n", $created, $updated, count( $pages ) ) );

/**
 * Build a Legacy Widget block for `the_widget()` rendering of a registered
 * widget id_base. WordPress serialises the instance values as base64'd
 * PHP-serialised data — we mimic that here so the block resolves at render
 * time without needing the widget editor UI.
 *
 * @param string $id_base  Widget class first __construct argument.
 * @param array  $instance Widget instance settings.
 * @return string Block markup.
 */
function jt_qa_legacy_widget_block( string $id_base, array $instance ): string {
	$encoded = base64_encode( serialize( $instance ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	$attrs   = wp_json_encode(
		array(
			'idBase'  => $id_base,
			'instance' => array( 'encoded' => $encoded, 'hash' => '' ),
		)
	);
	return '<!-- wp:legacy-widget ' . $attrs . ' /-->';
}
