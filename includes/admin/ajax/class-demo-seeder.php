<?php
/**
 * Demo data seeder.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Vote;
use Jetonomy\Models\Flag;
use function Jetonomy\table;

/**
 * Demo_Seeder — seeds a realistic, multi-user demo community.
 *
 * Covers: users, spaces, posts, replies, votes, flags, badges,
 * and Pro data (reactions, polls) when Jetonomy Pro is active.
 *
 * Usage:
 *   $manifest = Demo_Seeder::seed( get_current_user_id() );
 *   Demo_Seeder::cleanup( get_option( 'jetonomy_demo_data', [] ) );
 */
class Demo_Seeder {

	// ── Entry point ────────────────────────────────────────────────────────────

	/**
	 * Seed the full demo dataset and return a manifest for cleanup.
	 *
	 * @param int $admin_id WP user ID of the site administrator.
	 * @return array Manifest keyed by entity type.
	 */
	public static function seed( int $admin_id ): array {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$demo = [
			'users'      => [],
			'categories' => [],
			'spaces'     => [],
			'posts'      => [],
			'replies'    => [],
			'flags'      => [],
			'polls'      => [],
			'badges'     => [],
		];

		// ── Demo users ─────────────────────────────────────────────────────────

		$u             = self::create_users( $now );
		$demo['users'] = array_values( $u );

		$alice = $u['jt_demo_alice'] ?? $admin_id;
		$bob   = $u['jt_demo_bob'] ?? $admin_id;
		$carol = $u['jt_demo_carol'] ?? $admin_id;
		$david = $u['jt_demo_david'] ?? $admin_id;
		$eve   = $u['jt_demo_eve'] ?? $admin_id;

		// ── Categories ─────────────────────────────────────────────────────────

		$cat1                 = Category::create(
			[
				'name'        => 'Product & Engineering',
				'slug'        => 'product-engineering',
				'description' => 'Technical discussions, bug reports, and development workflows.',
				'visibility'  => 'public',
			]
		);
		$demo['categories'][] = $cat1;

		$cat2                 = Category::create(
			[
				'name'        => 'Community',
				'slug'        => 'community-hub',
				'description' => 'Everything about our community — introductions, events, and general chat.',
				'visibility'  => 'public',
			]
		);
		$demo['categories'][] = $cat2;

		// ── Spaces ────────────────────────────────────────────────────────────

		$s_welcome        = Space::create(
			[
				'category_id' => $cat2,
				'author_id'   => $admin_id,
				'type'        => 'forum',
				'title'       => 'Welcome & Introductions',
				'slug'        => 'welcome',
				'description' => 'New here? Introduce yourself and say hello to the community.',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
		$demo['spaces'][] = $s_welcome;

		$s_general        = Space::create(
			[
				'category_id' => $cat2,
				'author_id'   => $alice,
				'type'        => 'forum',
				'title'       => 'General Discussion',
				'slug'        => 'general-discussion',
				'description' => "Off-topic conversations, industry news, and anything that doesn't fit elsewhere.",
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
		$demo['spaces'][] = $s_general;

		$s_help           = Space::create(
			[
				'category_id' => $cat1,
				'author_id'   => $admin_id,
				'type'        => 'qa',
				'title'       => 'Help & Support',
				'slug'        => 'help-support',
				'description' => 'Ask questions and get answers from experienced community members.',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
		$demo['spaces'][] = $s_help;

		$s_ideas          = Space::create(
			[
				'category_id' => $cat1,
				'author_id'   => $alice,
				'type'        => 'ideas',
				'title'       => 'Feature Requests',
				'slug'        => 'feature-requests',
				'description' => 'Submit ideas, vote on what matters, and shape our roadmap together.',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
		$demo['spaces'][] = $s_ideas;

		$s_tips           = Space::create(
			[
				'category_id' => $cat1,
				'author_id'   => $admin_id,
				'type'        => 'forum',
				'title'       => 'Tips & Best Practices',
				'slug'        => 'tips-best-practices',
				'description' => 'Share workflows, shortcuts, and hard-won lessons with fellow members.',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
		$demo['spaces'][] = $s_tips;

		// All demo users join every space.
		foreach ( $demo['spaces'] as $sid ) {
			$result = SpaceMember::add( $sid, $admin_id, 'admin' );
			if ( is_wp_error( $result ) ) {
				continue; // Skip this space in demo data.
			}
			foreach ( array_values( $u ) as $uid ) {
				$result = SpaceMember::add( $sid, $uid, 'member' );
				if ( is_wp_error( $result ) ) {
					continue; // Skip this member in demo data.
				}
			}
		}

		// ── Posts ──────────────────────────────────────────────────────────────

		$posts_raw = [
			[
				'space'   => $s_welcome,
				'type'    => 'topic',
				'author'  => $admin_id,
				'title'   => "Welcome to our community — here's how it works",
				'content' => "<p>Hey everyone! We're excited to have you here.</p><p>This community is built for real conversations — no algorithms, no noise. Here's a quick orientation:</p><ul><li><strong>Spaces</strong> are topic-specific areas. Browse the ones that interest you and join freely.</li><li><strong>Reputation</strong> grows as you contribute. Higher trust levels unlock more features.</li><li><strong>Voting</strong> helps the best content rise to the top. Use it generously.</li></ul><p>Don't be shy — introduce yourself below or jump straight into a discussion. Welcome aboard!</p>",
			],
			[
				'space'   => $s_welcome,
				'type'    => 'topic',
				'author'  => $admin_id,
				'title'   => 'Community guidelines — the short version',
				'content' => '<p>We keep things simple. Three principles:</p><ol><li><strong>Be respectful.</strong> Disagree with ideas, not with people. No personal attacks.</li><li><strong>Be helpful.</strong> If someone asks a question, try to answer it — or point them in the right direction.</li><li><strong>Stay on topic.</strong> Each space has a purpose. Use General Discussion for everything else.</li></ol><p>Moderators are here to keep conversations productive. If you see something that doesn\'t belong, use the flag button.</p>',
			],
			[
				'space'   => $s_general,
				'type'    => 'topic',
				'author'  => $alice,
				'title'   => "What's everyone working on this week?",
				'content' => "<p>I always find it motivating to hear what others are building. I'm currently migrating a client's legacy forum to this platform — the import tools have been surprisingly smooth.</p><p>What's on your plate? Drop a quick update below.</p>",
			],
			[
				'space'   => $s_general,
				'type'    => 'topic',
				'author'  => $bob,
				'title'   => 'Interesting article: the future of online communities',
				'content' => '<p>Came across a thoughtful piece about how community platforms are shifting away from engagement metrics toward meaningful interactions. The core argument is that smaller, focused communities consistently outperform large social networks for professional learning.</p><p>Curious what you all think — does that match your experience?</p>',
			],
			[
				'space'   => $s_help,
				'type'    => 'question',
				'author'  => $carol,
				'title'   => 'How do I customize the notification settings?',
				'content' => "<p>I'm getting email notifications for every reply in spaces I've joined. Is there a way to set it to daily digest instead? I looked in my profile settings but couldn't find the option.</p><p>Running the latest version on WordPress 6.9 with the BuddyX theme.</p>",
			],
			[
				'space'   => $s_help,
				'type'    => 'question',
				'author'  => $david,
				'title'   => 'Can I restrict a space to specific membership levels?',
				'content' => '<p>We have a premium membership tier using MemberPress. I want to create a space that\'s only visible to members at the "Pro" level and above.</p><p>I see there\'s an Access Rules section in the space settings — is that the right place? What should the configuration look like?</p>',
			],
			[
				'space'   => $s_help,
				'type'    => 'question',
				'author'  => $bob,
				'title'   => 'Best approach for migrating from bbPress?',
				'content' => '<p>We have about 3,000 topics and 12,000 replies in bbPress. Before I hit the import button, a few questions:</p><ul><li>Does the importer preserve the original post dates?</li><li>What happens to forum categories — do they become spaces?</li><li>Is there a way to do a dry run first?</li></ul><p>Any migration tips from people who\'ve done this would be really helpful.</p>',
			],
			[
				'space'   => $s_ideas,
				'type'    => 'idea',
				'author'  => $carol,
				'title'   => 'Dark mode toggle in user preferences',
				'content' => "<p>It would be great if users could switch to a dark color scheme directly from their profile preferences, independent of the system theme. Many of us work late and a dark mode would reduce eye strain significantly.</p><p>Ideally it should respect the theme's dark palette if one exists, and fall back to a sensible default otherwise.</p>",
			],
			[
				'space'   => $s_ideas,
				'type'    => 'idea',
				'author'  => $alice,
				'title'   => 'Saved/bookmarked posts for quick reference',
				'content' => '<p>I often find great answers in Q&A threads but have no way to save them for later. A simple "bookmark" or "save" button on posts and replies would be incredibly useful.</p><p>Bonus points if there\'s a "My Saved" page where I can see all my bookmarks organized by space.</p>',
			],
			[
				'space'   => $s_tips,
				'type'    => 'topic',
				'author'  => $alice,
				'title'   => 'Setting up your community for the first 100 members',
				'content' => "<p>After launching three communities over the past two years, here's what I've learned about the critical first 100 members:</p><ol><li><strong>Seed the content yourself.</strong> Nobody wants to post in an empty forum. Write 10–15 quality topics across different spaces before inviting anyone.</li><li><strong>Personal invitations beat mass emails.</strong> Send individual messages to people you know will contribute.</li><li><strong>Respond to everything.</strong> For the first month, reply to every single post. People come back when they feel heard.</li><li><strong>Celebrate first-time posters.</strong> A simple \"Great first post, welcome!\" goes a long way.</li></ol><p>The goal isn't growth — it's establishing the culture. Get the first 100 right and the next 1,000 takes care of itself.</p>",
			],
			[
				'space'   => $s_tips,
				'type'    => 'topic',
				'author'  => $bob,
				'title'   => 'Keyboard shortcuts you might not know about',
				'content' => '<p>Quick productivity tip — this platform has built-in keyboard shortcuts:</p><ul><li><code>j</code> / <code>k</code> — Navigate between topics</li><li><code>l</code> — Upvote the current topic</li><li><code>r</code> — Open the reply composer</li><li><code>n</code> — New post</li><li><code>/</code> — Focus the search bar</li><li><code>?</code> — Show the full shortcut help</li></ul><p>Try pressing <code>?</code> anywhere on the community pages to see the complete list.</p>',
			],
		];

		foreach ( $posts_raw as $p ) {
			$pid = Post::create(
				[
					'space_id'      => $p['space'],
					'author_id'     => $p['author'],
					'type'          => $p['type'],
					'title'         => $p['title'],
					'slug'          => sanitize_title( $p['title'] ),
					'content'       => $p['content'],
					'content_plain' => wp_strip_all_tags( $p['content'] ),
					'status'        => 'publish',
				]
			);
			if ( is_wp_error( $pid ) ) {
				$pid = 0; // Skip this post in demo data.
			}
			$demo['posts'][] = $pid;
		}

		// ── Replies ────────────────────────────────────────────────────────────

		$replies_raw = [
			// Welcome (post 0) — alice, bob, carol.
			[
				'post'    => 0,
				'author'  => $alice,
				'content' => "<p>This is exactly the kind of community space I've been looking for. Clean interface, no distractions. Happy to be here!</p>",
			],
			[
				'post'    => 0,
				'author'  => $bob,
				'content' => "<p>Love the reputation system — it's a smart way to build trust gradually. Looking forward to contributing.</p>",
			],
			[
				'post'    => 0,
				'author'  => $carol,
				'content' => '<p>Just joined today. Coming from a Discourse community that got too noisy. This feels much more focused already.</p>',
			],
			// Guidelines (post 1) — david, eve.
			[
				'post'    => 1,
				'author'  => $david,
				'content' => '<p>Simple and clear — the best kind of community guidelines. Bookmarked for reference.</p>',
			],
			[
				'post'    => 1,
				'author'  => $eve,
				'content' => "<p>Appreciate that flagging is encouraged. In my experience that's the best way to keep forums healthy without over-moderating.</p>",
			],
			// What are you working on? (post 2) — carol, david, bob.
			[
				'post'    => 2,
				'author'  => $carol,
				'content' => "<p>Currently setting up a knowledge base for our support team. We're using the Q&A space type which is perfect for structured answers. The voting system helps surface the best solutions.</p>",
			],
			[
				'post'    => 2,
				'author'  => $david,
				'content' => '<p>Building out a members-only community for our online course. The MemberPress integration was a game-changer — took about 10 minutes to set up space-level access rules.</p>',
			],
			[
				'post'    => 2,
				'author'  => $bob,
				'content' => '<p>Rebuilding our company intranet forum. We had bbPress before and the migration import handled 8,000+ posts without a hitch. Pretty impressed so far.</p>',
			],
			// Notification Q&A (post 4) — alice (accepted), bob.
			[
				'post'     => 4,
				'author'   => $alice,
				'accepted' => true,
				'content'  => '<p>Go to your profile → Edit → scroll down to the <strong>Notification Preferences</strong> section. You can choose between instant, daily digest, and weekly digest for each type of notification.</p><p>If the section isn\'t visible, make sure you\'re running at least version 1.0. The email digest feature requires Pro.</p>',
			],
			[
				'post'    => 4,
				'author'  => $bob,
				'content' => '<p>Adding to the above — you can also unsubscribe from individual spaces by clicking the bell icon on the space page. That way you only get notifications for spaces you actively follow.</p>',
			],
			// Access rules Q&A (post 5) — alice (accepted), carol.
			[
				'post'     => 5,
				'author'   => $alice,
				'accepted' => true,
				'content'  => "<p>Yes, Access Rules is the right section. Here's the setup:</p><ol><li>Edit the space → Access Rules tab</li><li>Click \"Add Rule\"</li><li>Set Type to \"Membership\", Level to \"Pro\"</li><li>Set the space visibility to \"Private\"</li></ol><p>Members at the Pro level and above will see the space automatically. Others won't even know it exists.</p>",
			],
			[
				'post'    => 5,
				'author'  => $carol,
				'content' => "<p>One thing to note — if you're using the PMPro adapter instead of MemberPress, the setup is identical. The adapter pattern means all membership plugins work the same way from the community side.</p>",
			],
			// bbPress migration (post 6) — alice, david.
			[
				'post'    => 6,
				'author'  => $alice,
				'content' => '<p>I migrated about 5,000 topics last week. To answer your questions:</p><ul><li>Yes, original dates are preserved. Posts appear in the correct chronological order.</li><li>bbPress forums become spaces; forum categories become Jetonomy categories.</li><li>Use the CLI command <code>wp jetonomy import bbpress --dry-run</code> to preview without actually importing.</li></ul><p>Tip: run a database backup first. The importer is non-destructive but better safe than sorry.</p>',
			],
			[
				'post'    => 6,
				'author'  => $david,
				'content' => '<p>Did the same migration last month. The batched import was a lifesaver — we have 12,000 replies and it handled them in chunks with a progress bar. No timeouts. Took about 4 minutes total.</p>',
			],
			// Dark mode idea (post 7) — bob, alice.
			[
				'post'    => 7,
				'author'  => $bob,
				'content' => '<p>Fully support this. A lot of developer communities default to dark mode now. It would be great to see it built into the user preferences rather than relying on browser extensions.</p>',
			],
			[
				'post'    => 7,
				'author'  => $alice,
				'content' => '<p>If the theme supports a dark palette via theme.json, would it make sense to just toggle the CSS custom properties? That way it stays consistent with the overall site design.</p>',
			],
			// Bookmarks idea (post 8) — david.
			[
				'post'    => 8,
				'author'  => $david,
				'content' => '<p>Yes please! I find myself copying URLs into a note-taking app which is not ideal. A native bookmark system would save me so much time. Especially in Q&A spaces where the accepted answers are gold.</p>',
			],
			// First 100 members (post 9) — carol, eve.
			[
				'post'    => 9,
				'author'  => $carol,
				'content' => '<p>Point 3 is so true. Early on, I was the only person replying in our community. It felt slow, but within a month people started replying to each other. That transition from "founder answers everything" to "community helps itself" is magical when it happens.</p>',
			],
			[
				'post'    => 9,
				'author'  => $eve,
				'content' => "<p>Great advice. I'd add one more: <strong>create rituals</strong>. A weekly \"What are you working on?\" thread or a monthly AMA gives people a reason to come back regularly. Consistency beats novelty.</p>",
			],
		];

		$replies_t = table( 'replies' );
		foreach ( $replies_raw as $rd ) {
			$post_id = $demo['posts'][ $rd['post'] ] ?? 0;
			if ( ! $post_id ) {
				continue;
			}
			$rid = Reply::create(
				[
					'post_id'       => $post_id,
					'author_id'     => $rd['author'],
					'content'       => $rd['content'],
					'content_plain' => wp_strip_all_tags( $rd['content'] ),
					'status'        => 'publish',
				]
			);
			if ( is_wp_error( $rid ) ) {
				$rid = 0; // Skip this reply in demo data.
			}
			$demo['replies'][] = $rid;

			if ( $rid && ! empty( $rd['accepted'] ) ) {
				$wpdb->update( $replies_t, [ 'is_accepted' => 1 ], [ 'id' => $rid ] );
			}
		}

		// ── Votes ──────────────────────────────────────────────────────────────

		// Welcome post — alice, bob, carol, david upvote.
		self::batch_vote( 'post', $demo['posts'][0] ?? 0, [ $alice, $bob, $carol, $david ], 1 );
		// "First 100 members" tip — bob, carol, david, eve upvote.
		self::batch_vote( 'post', $demo['posts'][9] ?? 0, [ $bob, $carol, $david, $eve ], 1 );
		// Notification Q&A post — alice, bob, david upvote.
		self::batch_vote( 'post', $demo['posts'][4] ?? 0, [ $alice, $bob, $david ], 1 );
		// Dark mode idea — admin, alice, bob, david, eve upvote.
		self::batch_vote( 'post', $demo['posts'][7] ?? 0, [ $admin_id, $alice, $bob, $david, $eve ], 1 );
		// Alice's notification answer (reply index 8) — carol, david, bob upvote.
		self::batch_vote( 'reply', $demo['replies'][8] ?? 0, [ $carol, $david, $bob ], 1 );
		// Alice's access rules answer (reply index 10) — david, carol, eve upvote.
		self::batch_vote( 'reply', $demo['replies'][10] ?? 0, [ $david, $carol, $eve ], 1 );

		// ── Flags (moderation test data) ──────────────────────────────────────

		// Eve flags the keyboard shortcuts post as off-topic.
		if ( ! empty( $demo['posts'][10] ) && $eve ) {
			$demo['flags'][] = Flag::create(
				[
					'object_type' => 'post',
					'object_id'   => $demo['posts'][10],
					'reporter_id' => $eve,
					'reason'      => 'off_topic',
					'description' => 'This post seems more like a feature announcement than a community tip.',
				]
			);
		}

		// Carol flags the "interesting article" post as spam (no link provided).
		if ( ! empty( $demo['posts'][3] ) && $carol ) {
			$demo['flags'][] = Flag::create(
				[
					'object_type' => 'post',
					'object_id'   => $demo['posts'][3],
					'reporter_id' => $carol,
					'reason'      => 'spam',
					'description' => 'No link to the actual article referenced.',
				]
			);
		}

		// ── Badges ─────────────────────────────────────────────────────────────

		$badges_t      = $wpdb->prefix . 'jt_badges';
		$user_badges_t = $wpdb->prefix . 'jt_user_badges';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$badges_t}'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$badge_defs = [
				[
					'name'           => 'First Post',
					'description'    => 'Created your first post in the community.',
					'icon'           => 'pencil',
					'tier'           => 'bronze',
					'criteria_type'  => 'post_count',
					'criteria_value' => 1,
				],
				[
					'name'           => 'Conversation Starter',
					'description'    => 'Started 10 discussions that got replies.',
					'icon'           => 'chat',
					'tier'           => 'silver',
					'criteria_type'  => 'post_count',
					'criteria_value' => 10,
				],
				[
					'name'           => 'Helpful Member',
					'description'    => 'Posted 25 replies that helped fellow members.',
					'icon'           => 'heart',
					'tier'           => 'bronze',
					'criteria_type'  => 'reply_count',
					'criteria_value' => 25,
				],
				[
					'name'           => 'Community Pillar',
					'description'    => 'Reached 100 reputation points through quality contributions.',
					'icon'           => 'star',
					'tier'           => 'gold',
					'criteria_type'  => 'reputation',
					'criteria_value' => 100,
				],
				[
					'name'           => 'Rising Star',
					'description'    => 'Earned Trust Level 2 through consistent participation.',
					'icon'           => 'rocket',
					'tier'           => 'silver',
					'criteria_type'  => 'trust_level',
					'criteria_value' => 2,
				],
				[
					'name'           => 'Veteran',
					'description'    => 'Active member for over 30 days.',
					'icon'           => 'shield',
					'tier'           => 'bronze',
					'criteria_type'  => 'days_active',
					'criteria_value' => 30,
				],
				[
					'name'           => 'Top Contributor',
					'description'    => 'Reached 500 reputation — a true community leader.',
					'icon'           => 'trophy',
					'tier'           => 'gold',
					'criteria_type'  => 'reputation',
					'criteria_value' => 500,
				],
				[
					'name'           => 'Early Adopter',
					'description'    => 'Joined during the community launch period.',
					'icon'           => 'flag',
					'tier'           => 'silver',
					'criteria_type'  => 'manual',
					'criteria_value' => 0,
				],
			];

			foreach ( $badge_defs as $b ) {
				$wpdb->insert(
					$badges_t,
					[
						'name'           => $b['name'],
						'description'    => $b['description'],
						'icon'           => $b['icon'],
						'tier'           => $b['tier'],
						'criteria_type'  => $b['criteria_type'],
						'criteria_value' => $b['criteria_value'],
						'is_active'      => 1,
						'created_at'     => $now,
					]
				);
				$demo['badges'][] = (int) $wpdb->insert_id;
			}

			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$user_badges_t}'" ) && ! empty( $demo['badges'] ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$award = static function ( int $uid, int $badge_id ) use ( $wpdb, $user_badges_t, $now ) {
					$wpdb->insert(
						$user_badges_t,
						[
							'user_id'    => $uid,
							'badge_id'   => $badge_id,
							'awarded_at' => $now,
						]
					);
				};

				$badge_early  = end( $demo['badges'] );       // Early Adopter.
				$badge_first  = $demo['badges'][0];            // First Post.
				$badge_helper = $demo['badges'][2];            // Helpful Member.
				$badge_pillar = $demo['badges'][3];            // Community Pillar.
				$badge_rising = $demo['badges'][4];            // Rising Star.

				foreach ( array_merge( [ $admin_id ], $demo['users'] ) as $uid ) {
					$award( $uid, $badge_early );
					$award( $uid, $badge_first );
				}
				$award( $alice, $badge_helper );
				$award( $alice, $badge_pillar );
				$award( $bob, $badge_rising );
			}
		}

		// ── Pro data ───────────────────────────────────────────────────────────

		if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
			$pro_data = self::seed_pro( $admin_id, $u, $demo, $now );
			$demo     = array_merge( $demo, $pro_data );
		}

		return $demo;
	}

	// ── Pro-specific seeding ───────────────────────────────────────────────────

	/**
	 * Seed reactions and polls if the Pro tables exist.
	 */
	private static function seed_pro( int $admin_id, array $u, array $demo, string $now ): array {
		global $wpdb;

		$alice = $u['jt_demo_alice'] ?? $admin_id;
		$bob   = $u['jt_demo_bob'] ?? $admin_id;
		$carol = $u['jt_demo_carol'] ?? $admin_id;
		$david = $u['jt_demo_david'] ?? $admin_id;

		$pro = [ 'polls' => [] ];

		// ── Reactions ──────────────────────────────────────────────────────────

		$reactions_t = $wpdb->prefix . 'jt_pro_reactions';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$reactions_t}'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$welcome_post = $demo['posts'][0] ?? 0;
			if ( $welcome_post ) {
				foreach ( [
					[ $admin_id, 'post', $welcome_post, 'thumbsup' ],
					[ $alice, 'post', $welcome_post, 'heart' ],
					[ $bob, 'post', $welcome_post, 'thumbsup' ],
					[ $carol, 'post', $welcome_post, 'hooray' ],
					[ $david, 'post', $welcome_post, 'heart' ],
				] as [ $uid, $ot, $oid, $emoji ] ) {
					$wpdb->insert(
						$reactions_t,
						[
							'user_id'     => $uid,
							'object_type' => $ot,
							'object_id'   => $oid,
							'emoji'       => $emoji,
							'created_at'  => $now,
						]
					);
				}
			}

			$notif_reply = $demo['replies'][8] ?? 0;
			if ( $notif_reply ) {
				foreach ( [
					[ $carol, 'reply', $notif_reply, 'thumbsup' ],
					[ $david, 'reply', $notif_reply, 'heart' ],
					[ $bob, 'reply', $notif_reply, 'rocket' ],
				] as [ $uid, $ot, $oid, $emoji ] ) {
					$wpdb->insert(
						$reactions_t,
						[
							'user_id'     => $uid,
							'object_type' => $ot,
							'object_id'   => $oid,
							'emoji'       => $emoji,
							'created_at'  => $now,
						]
					);
				}
			}
		}

		// ── Poll ───────────────────────────────────────────────────────────────

		$polls_t   = $wpdb->prefix . 'jt_pro_polls';
		$options_t = $wpdb->prefix . 'jt_pro_poll_options';
		$pvotes_t  = $wpdb->prefix . 'jt_pro_poll_votes';

		$have_polls = $wpdb->get_var( "SHOW TABLES LIKE '{$polls_t}'" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			&& $wpdb->get_var( "SHOW TABLES LIKE '{$options_t}'" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			&& $wpdb->get_var( "SHOW TABLES LIKE '{$pvotes_t}'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$dark_post = $demo['posts'][7] ?? 0;

		if ( $have_polls && $dark_post ) {
			$wpdb->insert(
				$polls_t,
				[
					'post_id'     => $dark_post,
					'question'    => 'How important is dark mode to you?',
					'type'        => 'single',
					'allow_other' => 0,
					'created_by'  => $carol,
					'created_at'  => $now,
				]
			);
			$poll_id        = (int) $wpdb->insert_id;
			$pro['polls'][] = $poll_id;

			$option_labels = [
				"Critical — I can't use apps without it",
				'Nice to have — would use it often',
				'Indifferent — I follow system settings',
				'Not needed — light mode is fine',
			];
			$option_ids    = [];
			foreach ( $option_labels as $i => $label ) {
				$wpdb->insert(
					$options_t,
					[
						'poll_id'    => $poll_id,
						'label'      => $label,
						'sort_order' => $i,
						'vote_count' => 0,
					]
				);
				$option_ids[] = (int) $wpdb->insert_id;
			}

			$vote_counts = [];
			foreach ( [
				$admin_id => 1,
				$alice    => 0,
				$bob      => 1,
				$carol    => 0,
				$david    => 2,
			] as $voter => $opt_idx ) {
				if ( ! isset( $option_ids[ $opt_idx ] ) ) {
					continue;
				}
				$opt_id = $option_ids[ $opt_idx ];
				$wpdb->insert(
					$pvotes_t,
					[
						'poll_id'    => $poll_id,
						'option_id'  => $opt_id,
						'user_id'    => $voter,
						'created_at' => $now,
					]
				);
				$vote_counts[ $opt_id ] = ( $vote_counts[ $opt_id ] ?? 0 ) + 1;
			}
			foreach ( $vote_counts as $opt_id => $count ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$options_t} SET vote_count = vote_count + %d WHERE id = %d", $count, $opt_id ) );
			}
		}

		return $pro;
	}

	// ── Cleanup ────────────────────────────────────────────────────────────────

	/**
	 * Delete all entities referenced in $manifest.
	 * Handles old manifests (missing keys) gracefully.
	 *
	 * @param array $manifest The value previously returned by seed().
	 */
	public static function cleanup( array $manifest ): void {
		global $wpdb;

		$post_ids  = array_filter( array_map( 'absint', $manifest['posts'] ?? [] ) );
		$reply_ids = array_filter( array_map( 'absint', $manifest['replies'] ?? [] ) );
		$user_ids  = array_filter( array_map( 'absint', $manifest['users'] ?? [] ) );
		$poll_ids  = array_filter( array_map( 'absint', $manifest['polls'] ?? [] ) );
		$badge_ids = array_filter( array_map( 'absint', $manifest['badges'] ?? [] ) );

		// --- Pro: Reactions ---

		$reactions_t = $wpdb->prefix . 'jt_pro_reactions';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$reactions_t}'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::delete_in( $reactions_t, 'object_id', $post_ids, "AND object_type = 'post'" );
			self::delete_in( $reactions_t, 'object_id', $reply_ids, "AND object_type = 'reply'" );
		}

		// --- Pro: Polls ---

		$polls_t   = $wpdb->prefix . 'jt_pro_polls';
		$options_t = $wpdb->prefix . 'jt_pro_poll_options';
		$pvotes_t  = $wpdb->prefix . 'jt_pro_poll_votes';

		if ( $poll_ids ) {
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$pvotes_t}'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::delete_in( $pvotes_t, 'poll_id', $poll_ids );
			}
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$options_t}'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::delete_in( $options_t, 'poll_id', $poll_ids );
			}
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$polls_t}'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::delete_in( $polls_t, 'id', $poll_ids );
			}
		}

		// --- Badges ---

		$badges_t      = $wpdb->prefix . 'jt_badges';
		$user_badges_t = $wpdb->prefix . 'jt_user_badges';

		if ( $badge_ids ) {
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$user_badges_t}'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::delete_in( $user_badges_t, 'badge_id', $badge_ids );
			}
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$badges_t}'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::delete_in( $badges_t, 'id', $badge_ids );
			}
		}

		// --- Flags ---

		$flags_t = table( 'flags' );
		self::delete_in( $flags_t, 'object_id', $post_ids, "AND object_type = 'post'" );
		self::delete_in( $flags_t, 'object_id', $reply_ids, "AND object_type = 'reply'" );

		// --- Votes ---

		$votes_t = table( 'votes' );
		self::delete_in( $votes_t, 'object_id', $post_ids, "AND object_type = 'post'" );
		self::delete_in( $votes_t, 'object_id', $reply_ids, "AND object_type = 'reply'" );

		// --- Replies / Posts ---

		self::delete_in( table( 'replies' ), 'id', $reply_ids );
		self::delete_in( table( 'posts' ), 'id', $post_ids );

		// --- Spaces ---

		$space_ids = array_filter( array_map( 'absint', $manifest['spaces'] ?? [] ) );
		if ( $space_ids ) {
			self::delete_in( table( 'space_members' ), 'space_id', $space_ids );
			self::delete_in( table( 'spaces' ), 'id', $space_ids );
		}

		// --- Categories ---

		$cat_ids = array_filter( array_map( 'absint', $manifest['categories'] ?? [] ) );
		self::delete_in( table( 'categories' ), 'id', $cat_ids );

		// --- Users ---

		$profiles_t = table( 'user_profiles' );
		foreach ( $user_ids as $uid ) {
			$wpdb->delete( $profiles_t, [ 'user_id' => $uid ], [ '%d' ] );
			wp_delete_user( $uid );
		}
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Create 5 demo WordPress users with Jetonomy profiles.
	 *
	 * @return array<string, int> Map of login → user_id.
	 */
	private static function create_users( string $now ): array {
		global $wpdb;

		$profiles_t = table( 'user_profiles' );

		$defs = [
			[
				'login'       => 'jt_demo_alice',
				'display'     => 'Alice Chen',
				'email'       => 'alice.demo@jetonomy.local',
				'trust_level' => 3,
				'reputation'  => 320,
				'bio'         => 'Community manager and content strategist. I help online communities grow.',
			],
			[
				'login'       => 'jt_demo_bob',
				'display'     => 'Bob Martinez',
				'email'       => 'bob.demo@jetonomy.local',
				'trust_level' => 2,
				'reputation'  => 145,
				'bio'         => 'Full-stack developer. Love building tools that connect people.',
			],
			[
				'login'       => 'jt_demo_carol',
				'display'     => 'Carol Thompson',
				'email'       => 'carol.demo@jetonomy.local',
				'trust_level' => 1,
				'reputation'  => 58,
				'bio'         => 'Product designer. Obsessed with good UX and accessible interfaces.',
			],
			[
				'login'       => 'jt_demo_david',
				'display'     => 'David Kim',
				'email'       => 'david.demo@jetonomy.local',
				'trust_level' => 1,
				'reputation'  => 42,
				'bio'         => 'WordPress consultant. I migrate communities for a living.',
			],
			[
				'login'       => 'jt_demo_eve',
				'display'     => 'Eve Johnson',
				'email'       => 'eve.demo@jetonomy.local',
				'trust_level' => 0,
				'reputation'  => 5,
				'bio'         => 'Just joined! Excited to learn from this community.',
			],
		];

		$users = [];
		foreach ( $defs as $def ) {
			$uid = username_exists( $def['login'] );
			if ( ! $uid ) {
				$uid = wp_insert_user(
					[
						'user_login'   => $def['login'],
						'user_email'   => $def['email'],
						'display_name' => $def['display'],
						'user_pass'    => wp_generate_password( 24, true, true ),
						'role'         => 'subscriber',
					]
				);
				if ( is_wp_error( $uid ) ) {
					continue;
				}
			}

			UserProfile::find_or_create( (int) $uid );
			$wpdb->update(
				$profiles_t,
				[
					'trust_level' => $def['trust_level'],
					'reputation'  => $def['reputation'],
					'bio'         => $def['bio'],
					'updated_at'  => $now,
				],
				[ 'user_id' => (int) $uid ]
			);

			$users[ $def['login'] ] = (int) $uid;
		}

		return $users;
	}

	/**
	 * Cast the same vote value for multiple users on one object.
	 */
	private static function batch_vote( string $type, int $object_id, array $user_ids, int $value ): void {
		if ( ! $object_id ) {
			return;
		}
		foreach ( $user_ids as $uid ) {
			if ( $uid ) {
				$result = Vote::cast( $uid, $type, $object_id, $value );
				if ( is_wp_error( $result ) ) {
					continue; // Skip this vote in demo data.
				}
			}
		}
	}

	/**
	 * DELETE FROM $table WHERE $col IN ($ids) [$extra_sql].
	 */
	private static function delete_in( string $table, string $col, array $ids, string $extra_sql = '' ): void {
		if ( empty( $ids ) ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$col} IN ({$placeholders}) {$extra_sql}", ...$ids ) );
	}
}
