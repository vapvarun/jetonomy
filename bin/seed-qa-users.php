<?php
/**
 * Seed QA test users for v1.4.1 access-matrix testing.
 *
 * Creates the six fixture users referenced by `bin/access-matrix-check.sh`
 * (anon is just "no cookie", so only five users get created here):
 *
 *   test_subscriber  WP role: subscriber                — vanilla logged-in user
 *   test_author      WP role: author                    — has jetonomy_create_posts + upload_media
 *   test_editor      WP role: subscriber + space-admin  — space-admin role on the test space
 *                                                         (NOT WP editor — we use space-role grant
 *                                                         so we can isolate "space-scoped admin"
 *                                                         from "site-wide moderator")
 *   test_moderator   WP role: editor                    — picks up jetonomy_moderate by default
 *   test_admin       WP role: administrator
 *
 * Idempotent — running twice is a no-op (upserts by login).
 *
 * Also stamps the standard fixtures (a public space + a published post) the
 * runner targets so the matrix has a stable URL set even on a fresh DB.
 *
 * Usage:
 *   wp --path="/Users/varundubey/Local Sites/forums/app/public" \
 *      eval-file wp-content/plugins/jetonomy/bin/seed-qa-users.php
 *
 * To clean up:
 *   wp --path="..." eval-file wp-content/plugins/jetonomy/bin/seed-qa-users.php cleanup
 *
 * Exit value: prints a single JSON line on stdout with all the IDs the
 * runner needs (user IDs, space IDs, post ID). The runner parses this line
 * via `awk '/^FIXTURES /{print $2}'`.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit( 1 );

$cleanup = isset( $args[0] ) && 'cleanup' === $args[0];

// Five test fixtures. Anon is implicit (no cookie).
$users = array(
	array(
		'login' => 'test_subscriber',
		'email' => 'test_subscriber@jetonomy-qa.invalid',
		'role'  => 'subscriber',
	),
	array(
		'login' => 'test_author',
		'email' => 'test_author@jetonomy-qa.invalid',
		'role'  => 'author',
	),
	array(
		'login' => 'test_editor',
		'email' => 'test_editor@jetonomy-qa.invalid',
		// Subscriber WP role + space-admin grant (set after creation, below).
		// We deliberately do NOT use the WP `editor` role so this fixture
		// stays isolated from the test_moderator one (editor auto-gets
		// jetonomy_moderate via cap defaults).
		'role'  => 'subscriber',
	),
	array(
		'login' => 'test_moderator',
		'email' => 'test_moderator@jetonomy-qa.invalid',
		// WP editor → picks up jetonomy_moderate by default.
		'role'  => 'editor',
	),
	array(
		'login' => 'test_admin',
		'email' => 'test_admin@jetonomy-qa.invalid',
		'role'  => 'administrator',
	),
);

if ( $cleanup ) {
	foreach ( $users as $u ) {
		$existing = get_user_by( 'login', $u['login'] );
		if ( $existing ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( (int) $existing->ID );
			fwrite( STDOUT, "deleted user: {$u['login']}\n" );
		}
	}
	delete_option( 'jetonomy_qa_fixtures' );
	fwrite( STDOUT, "QA users cleanup complete.\n" );
	return;
}

$ids = array();
$app_passwords = array();
foreach ( $users as $u ) {
	$existing = get_user_by( 'login', $u['login'] );
	if ( $existing ) {
		// Make sure role is up-to-date.
		$wp_user = new WP_User( (int) $existing->ID );
		if ( ! in_array( $u['role'], (array) $wp_user->roles, true ) ) {
			$wp_user->set_role( $u['role'] );
		}
		$ids[ $u['login'] ] = (int) $existing->ID;
		$user_id_for_pw       = (int) $existing->ID;
		fwrite( STDOUT, "ok   user {$u['login']} -> #{$existing->ID} (existing, role={$u['role']})\n" );
	} else {
		$user_id = wp_create_user(
			$u['login'],
			'jetonomy-qa-pass-1234!',
			$u['email']
		);
		if ( is_wp_error( $user_id ) ) {
			fwrite( STDERR, "FAILED {$u['login']}: " . $user_id->get_error_message() . "\n" );
			continue;
		}
		$wp_user = new WP_User( (int) $user_id );
		$wp_user->set_role( $u['role'] );
		$ids[ $u['login'] ] = (int) $user_id;
		$user_id_for_pw       = (int) $user_id;
		fwrite( STDOUT, "ok   user {$u['login']} -> #{$user_id} (created, role={$u['role']})\n" );
	}

	// Mint an Application Password for the runner. Existing matrix-runner
	// passwords are revoked first so re-running the seed gives a fresh,
	// known credential. Application Passwords let curl authenticate with
	// Basic auth — sidesteps the wp_create_nonce/session-token mismatch
	// you hit when generating cookies in CLI context.
	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		require_once ABSPATH . 'wp-includes/class-wp-application-passwords.php';
	}
	$existing_pws = WP_Application_Passwords::get_user_application_passwords( $user_id_for_pw );
	foreach ( (array) $existing_pws as $pw ) {
		if ( isset( $pw['name'] ) && 'matrix-runner' === $pw['name'] ) {
			WP_Application_Passwords::delete_application_password( $user_id_for_pw, $pw['uuid'] );
		}
	}
	$created = WP_Application_Passwords::create_new_application_password(
		$user_id_for_pw,
		array( 'name' => 'matrix-runner' )
	);
	if ( is_wp_error( $created ) ) {
		fwrite( STDERR, "FAILED app-pw {$u['login']}: " . $created->get_error_message() . "\n" );
		continue;
	}
	// $created is array( password_string, item_data ) — first element is the
	// plaintext we need to hand to curl Basic auth.
	$app_passwords[ $u['login'] ] = (string) $created[0];
	fwrite( STDOUT, "ok   app-pw minted for {$u['login']}\n" );
}

// Discover (or seed) the canonical public test space.
global $wpdb;
$public_space = $wpdb->get_row( "SELECT id FROM {$wpdb->prefix}jt_spaces WHERE visibility = 'public' ORDER BY id ASC LIMIT 1" );
if ( ! $public_space ) {
	fwrite( STDERR, "no public space found — runner needs at least one. Run wp jetonomy demo seed first.\n" );
	$public_space_id = 0;
} else {
	$public_space_id = (int) $public_space->id;
}

// Discover (or seed) the canonical published test post in that space.
$post_row = null;
if ( $public_space_id > 0 ) {
	$post_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}jt_posts WHERE space_id = %d AND status = 'publish' ORDER BY id ASC LIMIT 1",
			$public_space_id
		)
	);
}
$post_id = $post_row ? (int) $post_row->id : 0;

// Make test_editor a space-admin of the public space so the matrix's
// "space-admin" expectations resolve. SpaceMember::add_member is the
// model entry point; we use the role string the codebase recognises
// ("admin" — see Permission_Engine::is_space_admin).
if ( $public_space_id > 0 && isset( $ids['test_editor'] ) ) {
	if ( class_exists( '\\Jetonomy\\Models\\SpaceMember' ) ) {
		$already = \Jetonomy\Models\SpaceMember::is_member( $public_space_id, $ids['test_editor'] );
		if ( ! $already ) {
			\Jetonomy\Models\SpaceMember::add( $public_space_id, $ids['test_editor'], 'admin' );
			fwrite( STDOUT, "ok   test_editor added as space-admin of space #{$public_space_id}\n" );
		} else {
			// Promote to admin if currently lower role.
			$wpdb->update(
				$wpdb->prefix . 'jt_space_members',
				array( 'role' => 'admin' ),
				array(
					'space_id' => $public_space_id,
					'user_id'  => $ids['test_editor'],
				),
				array( '%s' ),
				array( '%d', '%d' )
			);
			fwrite( STDOUT, "ok   test_editor promoted to space-admin of space #{$public_space_id}\n" );
		}
	}

	// Author should be a regular member of the public space so create_posts
	// permission resolves cleanly.
	if ( isset( $ids['test_author'] ) && class_exists( '\\Jetonomy\\Models\\SpaceMember' ) ) {
		$already = \Jetonomy\Models\SpaceMember::is_member( $public_space_id, $ids['test_author'] );
		if ( ! $already ) {
			\Jetonomy\Models\SpaceMember::add( $public_space_id, $ids['test_author'], 'member' );
			fwrite( STDOUT, "ok   test_author added as member of space #{$public_space_id}\n" );
		}
	}
}

$fixtures = array(
	'users'         => $ids,
	'app_passwords' => $app_passwords,
	'space_id'      => $public_space_id,
	'post_id'       => $post_id,
);
update_option( 'jetonomy_qa_fixtures', $fixtures, false );

fwrite( STDOUT, "\n" );
fwrite( STDOUT, 'FIXTURES ' . wp_json_encode( $fixtures ) . "\n" );
