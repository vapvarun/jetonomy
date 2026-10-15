<?php
/**
 * Seed a REALISTIC community for QA and screenshots.
 *
 * Why this exists
 * ---------------
 * Testing on fixtures named "QA-RFT Lap 1..26" proves a code path but hides
 * what an owner actually sees: long lists, missing avatars, empty counts,
 * awkward labels. This loads Jetonomy's own demo community (the one site
 * owners get from Setup -> Load demo data: 19 members with bios, 4 categories,
 * 20 spaces, ~220 topics over 90 days) and adds the shapes it lacks:
 *
 *   - Subcategories: Community > Local Chapters (14 city spaces, uneven, 5
 *     empty) and Product & Engineering > Labs (one archived space).
 *   - Every visibility / join mix: private+open (Partner Program),
 *     private+invite (Customer Advisory Board), hidden (Moderators' Room),
 *     private+approval with a pending request (Insiders / Beta, from Carlos).
 *   - Avatars for most members (Grace, Omar, Finn left on the fallback).
 *   - A silenced member (Finn), a space ban (Omar), a -4 scored post.
 *   - The access-matrix personas (bin/seed-qa-users.php) renamed to real
 *     people with their own content: test_subscriber = Riley Thompson,
 *     qatester = Jordan Ellis, test_author = Nina Alvarez, test_editor =
 *     Ethan Brooks (space admin of Help Desk + Troubleshooting),
 *     test_moderator = Olivia Grant (Editor), test_admin = Morgan Hale.
 *
 * Usage (local / staging only)
 * -----
 *   wp eval-file wp-content/plugins/jetonomy/bin/seed-qa-users.php
 *   wp eval-file wp-content/plugins/jetonomy/bin/seed-demo-community.php
 *   wp eval-file wp-content/plugins/jetonomy/bin/seed-demo-community.php reset
 *
 * `reset` first wipes ALL Jetonomy content (Demo_Seeder::reset_all(), refused
 * on production) - use it to clear junk fixtures. Without it the demo set is
 * replaced and the extras are added idempotently. Remove everything with
 * Setup -> Remove demo data, or `reset` again.
 *
 * @package Jetonomy
 */

use Jetonomy\Admin\Ajax\Demo_Seeder;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Vote;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\JoinRequest;

/** @var array<int,string> $args */
if ( 'production' === wp_get_environment_type() ) {
	echo "Refusing to seed demo data on a production site.\n";
	return;
}
if ( isset( $args[0] ) && 'reset' === $args[0] ) {
	Demo_Seeder::reset_all();
}
$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1, 'orderby' => 'ID' ) );
Demo_Seeder::import( (int) ( $admins[0] ?? 1 ) );

mt_srand( 20261015 );
$u = static fn( string $login ): int => (int) ( get_user_by( 'login', $login )->ID ?? 0 );

require_once ABSPATH . 'wp-admin/includes/user.php';

// Personas get real names, bios and avatars so screens read like a real site.
$personas = array(
	'test_subscriber' => array( 'Riley Thompson', 'Runs a small photography club. Here to learn the ropes.', 12 ),
	'qatester'        => array( 'Jordan Ellis', 'Weekend tinkerer, mostly reading.', 33 ),
	'test_author'     => array( 'Nina Alvarez', 'Writes tutorials for our members.', 45 ),
	'test_editor'     => array( 'Ethan Brooks', 'Space admin for Help Desk and Troubleshooting.', 51 ),
	'test_moderator'  => array( 'Olivia Grant', 'Site moderator. Be kind, stay on topic.', 26 ),
	'test_admin'      => array( 'Morgan Hale', 'Community owner.', 60 ),
);
foreach ( $personas as $login => [ $name, $bio, $img ] ) {
	$id = $u( $login );
	if ( ! $id ) {
		continue;
	}
	$parts = explode( ' ', $name );
	wp_update_user( array( 'ID' => $id, 'display_name' => $name, 'first_name' => $parts[0], 'last_name' => $parts[1], 'nickname' => $name ) );
	UserProfile::find_or_create( $id );
	UserProfile::update_profile( $id, array( 'bio' => $bio, 'avatar_url' => "https://i.pravatar.cc/300?img={$img}" ) );
}

// Demo members: avatars for most, a few left on the fallback like real sites.
$no_avatar = array( 'grace', 'omar', 'finn' );
$img       = 1;
foreach ( (array) get_option( 'jetonomy_demo_data', array() )['users'] ?? array() as $id ) {
	$user = get_userdata( (int) $id );
	if ( $user && ! in_array( $user->user_login, $no_avatar, true ) ) {
		UserProfile::update_profile( (int) $id, array( 'avatar_url' => 'https://i.pravatar.cc/300?img=' . ( 61 + ( $img++ % 9 ) ) ) );
	}
}
$members = array_values( array_filter( array_map( $u, array( 'maya', 'theo', 'alice', 'bob', 'priya', 'david', 'sofia', 'jamal', 'lena', 'carlos', 'aisha', 'noah', 'yuki', 'grace', 'marco', 'elena', 'omar', 'hana', 'finn' ) ) ) );
$owner   = $u( 'maya' );

$cat_id = static function ( string $name ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \Jetonomy\table( 'categories' ) . ' WHERE name = %s', $name ) );
};
$space_id = static function ( string $title ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \Jetonomy\table( 'spaces' ) . ' WHERE title = %s', $title ) );
};
$make_space = static function ( array $d ) use ( $owner, $space_id ) {
	return $space_id( $d['title'] ) ?: (int) Space::create( $d + array( 'author_id' => $owner, 'type' => 'forum', 'visibility' => 'public', 'join_policy' => 'open', 'status' => 'active' ), $owner );
};
$days_ago = static fn( int $d ) => gmdate( 'Y-m-d H:i:s', time() - $d * DAY_IN_SECONDS - mt_rand( 0, 80000 ) );
$post     = static function ( int $sid, int $author, string $title, string $body, array $replies = array(), array $extra = array() ) use ( $days_ago ) {
	global $wpdb;
	// Re-runs add nothing: a topic with this title in this space already exists.
	if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \Jetonomy\table( 'posts' ) . ' WHERE space_id = %d AND title = %s', $sid, $title ) ) ) {
		return 0;
	}
	$when = $days_ago( mt_rand( 2, 60 ) );
	$pid  = Post::create( array( 'space_id' => $sid, 'author_id' => $author, 'title' => $title, 'content' => "<p>{$body}</p>", 'status' => 'publish', 'created_at' => $when, 'published_at' => $when ) + $extra );
	if ( is_wp_error( $pid ) ) {
		return 0;
	}
	foreach ( $replies as [ $ra, $rb ] ) {
		Reply::create( array( 'post_id' => (int) $pid, 'author_id' => $ra, 'content' => "<p>{$rb}</p>", 'status' => 'publish' ) );
	}
	return (int) $pid;
};

// 1. Subcategory with a long list: Community > Local Chapters, 14 city spaces.
$community = $cat_id( 'Community' );
$chapters  = $cat_id( 'Local Chapters' ) ?: (int) Category::create( array( 'name' => 'Local Chapters', 'slug' => 'local-chapters', 'parent_id' => $community, 'description' => 'Meet members near you. Each chapter runs its own meetups.' ) );
$cities    = array( 'London', 'Berlin', 'Toronto', 'Lagos', 'Bengaluru', 'São Paulo', 'Sydney', 'Tokyo', 'Austin', 'Amsterdam', 'Nairobi', 'Lisbon', 'Seoul', 'Mexico City' );
foreach ( $cities as $i => $city ) {
	$sid = $make_space( array( 'title' => "{$city} Chapter", 'slug' => sanitize_title( "{$city}-chapter" ), 'category_id' => $chapters, 'description' => "Meetups, coworking days and local news for members in {$city}." ) );
	$n   = max( 2, (int) ( 18 / ( $i + 1 ) ) ); // Uneven: London busy, the tail quiet.
	for ( $m = 0; $m < $n; $m++ ) {
		SpaceMember::add( $sid, $members[ ( $i + $m ) % count( $members ) ], 'member', 'manual' );
	}
	if ( $i < 9 ) { // Last five chapters are brand new and empty.
		$post( $sid, $members[ $i % count( $members ) ], "{$city} meetup this month: who is in?", "Thinking of a casual evening meetup in {$city} near the end of the month. Reply with the evenings that work for you.", array( array( $members[ ( $i + 3 ) % count( $members ) ], 'Count me in, any weekday after 6 works.' ), array( $members[ ( $i + 5 ) % count( $members ) ], 'Thursday is best for me. Happy to book a table.' ) ) );
	}
	if ( $i < 3 ) {
		$post( $sid, $members[ ( $i + 2 ) % count( $members ) ], "Good coworking spots in {$city}?", 'Looking for somewhere with decent wifi and a quiet corner for calls. Recommendations welcome.', array( array( $members[ ( $i + 7 ) % count( $members ) ], 'The library near the station has free desks and great wifi.' ) ) );
	}
}

// 2. Second subcategory: Product & Engineering > Labs (two spaces, one archived).
$labs = $cat_id( 'Labs' ) ?: (int) Category::create( array( 'name' => 'Labs', 'slug' => 'labs', 'parent_id' => $cat_id( 'Product & Engineering' ), 'description' => 'Experiments that may or may not ship.' ) );
$make_space( array( 'title' => 'AI Assistant Experiments', 'slug' => 'ai-assistant-experiments', 'category_id' => $labs, 'type' => 'ideas', 'description' => 'Try the experimental AI features and tell us what breaks.' ) );
$make_space( array( 'title' => '2025 Hackathon', 'slug' => '2025-hackathon', 'category_id' => $labs, 'status' => 'archived', 'description' => 'Archive of last year\'s hackathon projects. Read-only.' ) );

// 3. Visibility / join-policy mix the cards are about.
$pe      = $cat_id( 'Product & Engineering' );
$help    = $cat_id( 'Help & Support' );
$partner = $make_space( array( 'title' => 'Partner Program', 'slug' => 'partner-program', 'category_id' => $pe, 'visibility' => 'private', 'join_policy' => 'open', 'description' => 'For agencies and freelancers building on the platform. Open to any member who joins.' ) );
$cab     = $make_space( array( 'title' => 'Customer Advisory Board', 'slug' => 'customer-advisory-board', 'category_id' => $community, 'visibility' => 'private', 'join_policy' => 'invite', 'description' => 'Invite-only group of long-time customers who review roadmap drafts.' ) );
$modroom = $make_space( array( 'title' => "Moderators' Room", 'slug' => 'moderators-room', 'category_id' => $help, 'visibility' => 'hidden', 'join_policy' => 'invite', 'description' => 'Private coordination for the moderation team.' ) );
foreach ( array( 'alice', 'bob', 'david', 'marco' ) as $l ) {
	SpaceMember::add( $partner, $u( $l ), 'member', 'manual' );
}
SpaceMember::add( $partner, $u( 'bob' ), 'admin', 'manual' );
foreach ( array( 'maya', 'priya', 'sofia', 'lena' ) as $l ) {
	SpaceMember::add( $cab, $u( $l ), 'member', 'manual' );
}
foreach ( array( 'maya', 'theo', 'test_moderator' ) as $l ) {
	SpaceMember::add( $modroom, $u( $l ), 'moderator', 'manual' );
}
$post( $partner, $u( 'bob' ), 'Referral fees for 2026: what changed', 'We moved to a flat 20% on the first year. Details and FAQ inside.', array( array( $u( 'alice' ), 'Does this apply to renewals signed before January?' ), array( $u( 'bob' ), 'Yes, existing referrals keep the old rate until they renew.' ) ) );
$post( $cab, $u( 'maya' ), 'Q1 roadmap draft for review', 'Here is the draft. Please flag anything that would block your team.', array( array( $u( 'priya' ), 'The import changes are the big one for us. Can we see a timeline?' ) ) );
$post( $modroom, $u( 'theo' ), 'Handling the self-promotion wave in Show & Tell', 'Three new accounts posted the same link this week. Proposal: first post held for review until trust level 1.', array( array( $u( 'maya' ), 'Agreed. Let us also pin the policy post for a week.' ) ) );

// 4. Persona roles and content.
$help_desk = $space_id( 'Help Desk' );
$trouble   = $space_id( 'Troubleshooting' );
$general   = $space_id( 'General Discussion' );
$requests  = $space_id( 'Feature Requests' );
$bugs      = $space_id( 'Bug Reports' );
SpaceMember::add( $help_desk, $u( 'test_editor' ), 'admin', 'manual' );
SpaceMember::add( $trouble, $u( 'test_editor' ), 'admin', 'manual' );
SpaceMember::add( $bugs, $u( 'test_moderator' ), 'moderator', 'manual' );
foreach ( array( 'test_subscriber', 'qatester', 'test_author' ) as $l ) {
	foreach ( array( $general, $help_desk, $requests ) as $sid ) {
		SpaceMember::add( $sid, $u( $l ), 'member', 'manual' );
	}
}
$q = $post( $help_desk, $u( 'test_subscriber' ), 'Members cannot see the private photo critique space', 'I set the space to private and approved two members, but they still see "You do not have access". What am I missing?', array( array( $u( 'theo' ), 'Check that they were approved in that space, not just added to the site. Members > Pending requests.' ), array( $u( 'test_subscriber' ), 'That was it, thank you!' ) ) );
$post( $general, $u( 'test_subscriber' ), 'Favourite lens for street photography?', 'Shooting mostly at dusk. Torn between a 35mm and a 50mm prime.', array( array( $u( 'yuki' ), '35mm. You will get closer to the action and it is more forgiving.' ), array( $u( 'qatester' ), 'I went 50mm and never looked back, but I shoot portraits more.' ) ) );
$post( $general, $u( 'qatester' ), 'Is anyone else getting duplicate email digests?', 'Since last week I get the weekly digest twice on Monday mornings.', array( array( $u( 'alice' ), 'Same here, started after I changed my notification settings.' ) ) );
$post( $requests, $u( 'test_author' ), 'Let members schedule posts', 'Writers on our site want to queue tutorials for the morning. A schedule option on the composer would cover it.', array( array( $u( 'priya' ), '+1, we post across timezones.' ) ) );

// A post the community disliked, so negative scores exist.
$neg = $post( $general, $u( 'finn' ), 'Check out my SEO service, 50% off this week', 'DM me for a discount on backlinks.' );
foreach ( $neg ? array( 'maya', 'alice', 'bob', 'priya' ) : array() as $l ) {
	Vote::cast( $u( $l ), 'post', $neg, -1 );
}

// 5. Restrictions and a pending join request.
Restriction::is_silenced( $u( 'finn' ) ) || Restriction::ban( $u( 'finn' ), 'silence', $u( 'theo' ), null, 'Repeated self-promotion after a warning.', gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) );
Restriction::is_space_banned( $u( 'omar' ), $space_id( 'Off-Topic Lounge' ) ) || Restriction::ban( $u( 'omar' ), 'space_ban', $u( 'theo' ), $space_id( 'Off-Topic Lounge' ), 'Heated thread, taking a break.', gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ) );
if ( ! JoinRequest::find_pending( $space_id( 'Insiders / Beta' ), $u( 'carlos' ) ) ) {
	JoinRequest::create_request( $space_id( 'Insiders / Beta' ), $u( 'carlos' ), 'I run a 4,000-member hobby forum and would love to test the import tools early.' );
}

\Jetonomy\Cache::flush(); wp_cache_flush();
echo "COMMUNITY seeded: demo community + realistic extras. Log in with ?autologin=<login>.\n";
