<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use function Jetonomy\table;

class Setup_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_setup_save',          [ $this, 'ajax_setup_save' ] );
		add_action( 'wp_ajax_jetonomy_setup_create_sample', [ $this, 'ajax_setup_create_sample' ] );
		add_action( 'wp_ajax_jetonomy_cleanup_sample_data', [ $this, 'ajax_cleanup_sample_data' ] );
	}

	public function ajax_setup_save(): void {
		check_ajax_referer( 'jetonomy_setup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$settings = get_option( 'jetonomy_settings', [] );
		$settings['base_slug']    = sanitize_title( $_POST['base_slug'] ?? 'community' );
		$settings['default_type'] = sanitize_text_field( $_POST['default_type'] ?? 'forum' );
		$settings['guest_read']   = true;
		update_option( 'jetonomy_settings', $settings );

		// Create category + space.
		$cat_name   = sanitize_text_field( $_POST['category_name'] ?? 'General' );
		$space_name = sanitize_text_field( $_POST['space_name'] ?? 'Community Discussion' );
		$space_desc = sanitize_textarea_field( $_POST['space_description'] ?? '' );
		$space_type = $settings['default_type'];

		$cat_id = Category::create( [
			'name'       => $cat_name,
			'slug'       => sanitize_title( $cat_name ),
			'visibility' => 'public',
		] );

		$space_id = Space::create( [
			'category_id' => $cat_id,
			'author_id'   => get_current_user_id(),
			'type'        => $space_type,
			'title'       => $space_name,
			'slug'        => sanitize_title( $space_name ),
			'description' => $space_desc,
			'visibility'  => 'public',
			'join_policy' => 'open',
		] );

		// Add admin as space member.
		SpaceMember::add( $space_id, get_current_user_id(), 'admin' );

		// Create user profile for admin.
		UserProfile::find_or_create( get_current_user_id() );

		// Flush rewrite rules with new base slug.
		flush_rewrite_rules();

		// Mark setup as complete.
		update_option( 'jetonomy_setup_complete', true );

		wp_send_json_success( [ 'category_id' => $cat_id, 'space_id' => $space_id ] );
	}

	public function ajax_setup_create_sample(): void {
		check_ajax_referer( 'jetonomy_setup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$uid = get_current_user_id();
		UserProfile::find_or_create( $uid );

		// Track all IDs for cleanup.
		$demo = [ 'categories' => [], 'spaces' => [], 'posts' => [], 'replies' => [] ];

		$settings = get_option( 'jetonomy_settings', [] );
		$settings['base_slug']    = sanitize_title( $_POST['base_slug'] ?? 'community' );
		$settings['default_type'] = sanitize_text_field( $_POST['default_type'] ?? 'forum' );
		$settings['guest_read']   = true;
		update_option( 'jetonomy_settings', $settings );

		// ── Categories ──

		$cat1 = Category::create( [
			'name'        => 'Product & Engineering',
			'slug'        => 'product-engineering',
			'description' => 'Technical discussions, bug reports, and development workflows.',
			'visibility'  => 'public',
		] );
		$demo['categories'][] = $cat1;

		$cat2 = Category::create( [
			'name'        => 'Community',
			'slug'        => 'community-hub',
			'description' => 'Everything about our community — introductions, events, and general chat.',
			'visibility'  => 'public',
		] );
		$demo['categories'][] = $cat2;

		// ── Spaces ──

		$s_welcome = Space::create( [
			'category_id' => $cat2, 'author_id' => $uid, 'type' => 'forum',
			'title'       => 'Welcome & Introductions',
			'slug'        => 'welcome',
			'description' => 'New here? Introduce yourself and say hello to the community.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_welcome;

		$s_general = Space::create( [
			'category_id' => $cat2, 'author_id' => $uid, 'type' => 'forum',
			'title'       => 'General Discussion',
			'slug'        => 'general-discussion',
			'description' => 'Off-topic conversations, industry news, and anything that doesn\'t fit elsewhere.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_general;

		$s_help = Space::create( [
			'category_id' => $cat1, 'author_id' => $uid, 'type' => 'qa',
			'title'       => 'Help & Support',
			'slug'        => 'help-support',
			'description' => 'Ask questions and get answers from experienced community members.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_help;

		$s_ideas = Space::create( [
			'category_id' => $cat1, 'author_id' => $uid, 'type' => 'ideas',
			'title'       => 'Feature Requests',
			'slug'        => 'feature-requests',
			'description' => 'Submit ideas, vote on what matters, and shape our roadmap together.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_ideas;

		$s_tips = Space::create( [
			'category_id' => $cat1, 'author_id' => $uid, 'type' => 'forum',
			'title'       => 'Tips & Best Practices',
			'slug'        => 'tips-best-practices',
			'description' => 'Share workflows, shortcuts, and hard-won lessons with fellow members.',
			'visibility'  => 'public', 'join_policy' => 'open',
		] );
		$demo['spaces'][] = $s_tips;

		// Memberships.
		foreach ( $demo['spaces'] as $sid ) {
			SpaceMember::add( $sid, $uid, 'admin' );
		}

		// ── Posts with realistic content ──

		$posts_data = [
			// Welcome space.
			[
				'space_id' => $s_welcome, 'type' => 'topic',
				'title'    => 'Welcome to our community — here\'s how it works',
				'content'  => '<p>Hey everyone! We\'re excited to have you here.</p><p>This community is built for real conversations — no algorithms, no noise. Here\'s a quick orientation:</p><ul><li><strong>Spaces</strong> are topic-specific areas. Browse the ones that interest you and join freely.</li><li><strong>Reputation</strong> grows as you contribute. Higher trust levels unlock more features.</li><li><strong>Voting</strong> helps the best content rise to the top. Use it generously.</li></ul><p>Don\'t be shy — introduce yourself below or jump straight into a discussion. Welcome aboard!</p>',
			],
			[
				'space_id' => $s_welcome, 'type' => 'topic',
				'title'    => 'Community guidelines — the short version',
				'content'  => '<p>We keep things simple. Three principles:</p><ol><li><strong>Be respectful.</strong> Disagree with ideas, not with people. No personal attacks.</li><li><strong>Be helpful.</strong> If someone asks a question, try to answer it — or point them in the right direction.</li><li><strong>Stay on topic.</strong> Each space has a purpose. Use General Discussion for everything else.</li></ol><p>Moderators are here to keep conversations productive. If you see something that doesn\'t belong, use the flag button. Thanks for helping us build a great community.</p>',
			],
			// General Discussion.
			[
				'space_id' => $s_general, 'type' => 'topic',
				'title'    => 'What\'s everyone working on this week?',
				'content'  => '<p>I always find it motivating to hear what others are building. I\'m currently migrating a client\'s legacy forum to this platform — the import tools have been surprisingly smooth.</p><p>What\'s on your plate? Drop a quick update below.</p>',
			],
			[
				'space_id' => $s_general, 'type' => 'topic',
				'title'    => 'Interesting article: the future of online communities',
				'content'  => '<p>Came across a thoughtful piece about how community platforms are shifting away from engagement metrics toward meaningful interactions. The core argument is that smaller, focused communities consistently outperform large social networks for professional learning.</p><p>Curious what you all think — does that match your experience?</p>',
			],
			// Help & Support (Q&A).
			[
				'space_id' => $s_help, 'type' => 'question',
				'title'    => 'How do I customize the notification settings?',
				'content'  => '<p>I\'m getting email notifications for every reply in spaces I\'ve joined. Is there a way to set it to daily digest instead? I looked in my profile settings but couldn\'t find the option.</p><p>Running the latest version on WordPress 6.9 with the BuddyX theme.</p>',
			],
			[
				'space_id' => $s_help, 'type' => 'question',
				'title'    => 'Can I restrict a space to specific membership levels?',
				'content'  => '<p>We have a premium membership tier using MemberPress. I want to create a space that\'s only visible to members at the "Pro" level and above.</p><p>I see there\'s an Access Rules section in the space settings — is that the right place? What should the configuration look like?</p>',
			],
			[
				'space_id' => $s_help, 'type' => 'question',
				'title'    => 'Best approach for migrating from bbPress?',
				'content'  => '<p>We have about 3,000 topics and 12,000 replies in bbPress. Before I hit the import button, a few questions:</p><ul><li>Does the importer preserve the original post dates?</li><li>What happens to forum categories — do they become spaces?</li><li>Is there a way to do a dry run first?</li></ul><p>Any migration tips from people who\'ve done this would be really helpful.</p>',
			],
			// Feature Requests.
			[
				'space_id' => $s_ideas, 'type' => 'idea',
				'title'    => 'Dark mode toggle in user preferences',
				'content'  => '<p>It would be great if users could switch to a dark color scheme directly from their profile preferences, independent of the system theme. Many of us work late and a dark mode would reduce eye strain significantly.</p><p>Ideally it should respect the theme\'s dark palette if one exists, and fall back to a sensible default otherwise.</p>',
			],
			[
				'space_id' => $s_ideas, 'type' => 'idea',
				'title'    => 'Saved/bookmarked posts for quick reference',
				'content'  => '<p>I often find great answers in Q&A threads but have no way to save them for later. A simple "bookmark" or "save" button on posts and replies would be incredibly useful.</p><p>Bonus points if there\'s a "My Saved" page where I can see all my bookmarks organized by space.</p>',
			],
			// Tips & Best Practices.
			[
				'space_id' => $s_tips, 'type' => 'topic',
				'title'    => 'Setting up your community for the first 100 members',
				'content'  => '<p>After launching three communities over the past two years, here\'s what I\'ve learned about the critical first 100 members:</p><ol><li><strong>Seed the content yourself.</strong> Nobody wants to post in an empty forum. Write 10-15 quality topics across different spaces before inviting anyone.</li><li><strong>Personal invitations beat mass emails.</strong> Send individual messages to people you know will contribute.</li><li><strong>Respond to everything.</strong> For the first month, reply to every single post. People come back when they feel heard.</li><li><strong>Celebrate first-time posters.</strong> A simple "Great first post, welcome!" goes a long way.</li></ol><p>The goal isn\'t growth — it\'s establishing the culture. Get the first 100 right and the next 1,000 takes care of itself.</p>',
			],
			[
				'space_id' => $s_tips, 'type' => 'topic',
				'title'    => 'Keyboard shortcuts you might not know about',
				'content'  => '<p>Quick productivity tip — this platform has built-in keyboard shortcuts:</p><ul><li><code>j</code> / <code>k</code> — Navigate between topics</li><li><code>l</code> — Upvote the current topic</li><li><code>r</code> — Open the reply composer</li><li><code>n</code> — New post</li><li><code>/</code> — Focus the search bar</li><li><code>?</code> — Show the full shortcut help</li></ul><p>Try pressing <code>?</code> anywhere on the community pages to see the complete list.</p>',
			],
		];

		foreach ( $posts_data as $p ) {
			$pid = Post::create( [
				'space_id'      => $p['space_id'],
				'author_id'     => $uid,
				'type'          => $p['type'],
				'title'         => $p['title'],
				'slug'          => sanitize_title( $p['title'] ),
				'content'       => $p['content'],
				'content_plain' => wp_strip_all_tags( $p['content'] ),
				'status'        => 'publish',
			] );
			$demo['posts'][] = $pid;
		}

		// ── Replies that form actual conversations ──

		$replies_data = [
			// Welcome post — 3 replies.
			[ 'post_idx' => 0, 'content' => '<p>This is exactly the kind of community space I\'ve been looking for. Clean interface, no distractions. Happy to be here!</p>' ],
			[ 'post_idx' => 0, 'content' => '<p>Love the reputation system — it\'s a smart way to build trust gradually. Looking forward to contributing.</p>' ],
			[ 'post_idx' => 0, 'content' => '<p>Just joined today. Coming from a Discourse community that got too noisy. This feels much more focused already.</p>' ],

			// Guidelines — 2 replies.
			[ 'post_idx' => 1, 'content' => '<p>Simple and clear — the best kind of community guidelines. Bookmarked for reference.</p>' ],
			[ 'post_idx' => 1, 'content' => '<p>Appreciate that flagging is encouraged. In my experience that\'s the best way to keep forums healthy without over-moderating.</p>' ],

			// What are you working on? — 3 replies.
			[ 'post_idx' => 2, 'content' => '<p>Currently setting up a knowledge base for our support team. We\'re using the Q&A space type which is perfect for structured answers. The voting system helps surface the best solutions.</p>' ],
			[ 'post_idx' => 2, 'content' => '<p>Building out a members-only community for our online course. The MemberPress integration was a game-changer — took about 10 minutes to set up space-level access rules.</p>' ],
			[ 'post_idx' => 2, 'content' => '<p>Rebuilding our company intranet forum. We had bbPress before and the migration import handled 8,000+ posts without a hitch. Pretty impressed so far.</p>' ],

			// Notification settings Q&A — 2 replies.
			[ 'post_idx' => 4, 'content' => '<p>Go to your profile → Edit → scroll down to the <strong>Notification Preferences</strong> section. You can choose between instant, daily digest, and weekly digest for each type of notification.</p><p>If the section isn\'t visible, make sure you\'re running at least version 1.0. The email digest feature requires Pro.</p>' ],
			[ 'post_idx' => 4, 'content' => '<p>Adding to the above — you can also unsubscribe from individual spaces by clicking the bell icon on the space page. That way you only get notifications for spaces you actively follow.</p>' ],

			// Access rules Q&A — 2 replies.
			[ 'post_idx' => 5, 'content' => '<p>Yes, Access Rules is the right section. Here\'s the setup:</p><ol><li>Edit the space → Access Rules tab</li><li>Click "Add Rule"</li><li>Set Type to "Membership", Level to "Pro"</li><li>Set the space visibility to "Private"</li></ol><p>Members at the Pro level and above will see the space automatically. Others won\'t even know it exists.</p>' ],
			[ 'post_idx' => 5, 'content' => '<p>One thing to note — if you\'re using the PMPro adapter instead of MemberPress, the setup is identical. The adapter pattern means all membership plugins work the same way from the community side.</p>' ],

			// bbPress migration — 2 replies.
			[ 'post_idx' => 6, 'content' => '<p>I migrated about 5,000 topics last week. To answer your questions:</p><ul><li>Yes, original dates are preserved. Posts appear in the correct chronological order.</li><li>bbPress forums become spaces; forum categories become Jetonomy categories.</li><li>Use the CLI command <code>wp jetonomy import --source=bbpress --dry-run</code> to preview without actually importing.</li></ul><p>Tip: run a database backup first. The importer is non-destructive (doesn\'t delete bbPress data) but better safe than sorry.</p>' ],
			[ 'post_idx' => 6, 'content' => '<p>Did the same migration last month. The batched import was a lifesaver — we have 12,000 replies and it handled them in chunks with a progress bar. No timeouts. Took about 4 minutes total.</p>' ],

			// Dark mode idea — 2 replies.
			[ 'post_idx' => 7, 'content' => '<p>Fully support this. A lot of developer communities default to dark mode now. It would be great to see it built into the user preferences rather than relying on browser extensions.</p>' ],
			[ 'post_idx' => 7, 'content' => '<p>If the theme supports a dark palette via theme.json, would it make sense to just toggle the CSS custom properties? That way it stays consistent with the overall site design.</p>' ],

			// Bookmarks idea — 1 reply.
			[ 'post_idx' => 8, 'content' => '<p>Yes please! I find myself copying URLs into a note-taking app which is not ideal. A native bookmark system would save me so much time. Especially in Q&A spaces where the accepted answers are gold.</p>' ],

			// First 100 members — 2 replies.
			[ 'post_idx' => 9, 'content' => '<p>Point 3 is so true. Early on, I was the only person replying in our community. It felt slow, but within a month people started replying to each other. That transition from "founder answers everything" to "community helps itself" is magical when it happens.</p>' ],
			[ 'post_idx' => 9, 'content' => '<p>Great advice. I\'d add one more: <strong>create rituals</strong>. A weekly "What are you working on?" thread or a monthly AMA gives people a reason to come back regularly. Consistency beats novelty.</p>' ],
		];

		foreach ( $replies_data as $rd ) {
			$post_id = $demo['posts'][ $rd['post_idx'] ] ?? 0;
			if ( ! $post_id ) {
				continue;
			}
			$rid = Reply::create( [
				'post_id'       => $post_id,
				'author_id'     => $uid,
				'content'       => $rd['content'],
				'content_plain' => wp_strip_all_tags( $rd['content'] ),
				'status'        => 'publish',
			] );
			$demo['replies'][] = $rid;
		}

		// ── Seed badges if Pro is active ──

		if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
			global $wpdb;
			$badges_t = $wpdb->prefix . 'jt_badges';

			// Check if badges table exists.
			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$badges_t}'" );
			if ( $table_exists ) {
				$now = current_time( 'mysql' );
				$demo['badges'] = [];

				$badge_data = [
					[ 'name' => 'First Post',       'description' => 'Created your first post in the community.',                   'icon' => 'pencil',  'tier' => 'bronze', 'criteria_type' => 'post_count',  'criteria_value' => 1 ],
					[ 'name' => 'Conversation Starter', 'description' => 'Started 10 discussions that got replies.',                'icon' => 'chat',    'tier' => 'silver', 'criteria_type' => 'post_count',  'criteria_value' => 10 ],
					[ 'name' => 'Helpful Member',    'description' => 'Posted 25 replies that helped fellow members.',                'icon' => 'heart',   'tier' => 'bronze', 'criteria_type' => 'reply_count', 'criteria_value' => 25 ],
					[ 'name' => 'Community Pillar',   'description' => 'Reached 100 reputation points through quality contributions.', 'icon' => 'star',    'tier' => 'gold',   'criteria_type' => 'reputation',  'criteria_value' => 100 ],
					[ 'name' => 'Rising Star',        'description' => 'Earned Trust Level 2 through consistent participation.',       'icon' => 'rocket',  'tier' => 'silver', 'criteria_type' => 'trust_level', 'criteria_value' => 2 ],
					[ 'name' => 'Veteran',            'description' => 'Been an active member for over 30 days.',                     'icon' => 'shield',  'tier' => 'bronze', 'criteria_type' => 'days_active', 'criteria_value' => 30 ],
					[ 'name' => 'Top Contributor',    'description' => 'Reached 500 reputation — a true community leader.',            'icon' => 'trophy',  'tier' => 'gold',   'criteria_type' => 'reputation',  'criteria_value' => 500 ],
					[ 'name' => 'Early Adopter',      'description' => 'Joined during the community launch period.',                   'icon' => 'flag',    'tier' => 'silver', 'criteria_type' => 'manual',      'criteria_value' => 0 ],
				];

				foreach ( $badge_data as $b ) {
					$wpdb->insert( $badges_t, [
						'name'           => $b['name'],
						'description'    => $b['description'],
						'icon'           => $b['icon'],
						'tier'           => $b['tier'],
						'criteria_type'  => $b['criteria_type'],
						'criteria_value' => $b['criteria_value'],
						'is_active'      => 1,
						'created_at'     => $now,
					] );
					$demo['badges'][] = (int) $wpdb->insert_id;
				}

				// Award "Early Adopter" + "First Post" to the admin user.
				$user_badges_t = $wpdb->prefix . 'jt_user_badges';
				$ub_exists     = $wpdb->get_var( "SHOW TABLES LIKE '{$user_badges_t}'" );
				if ( $ub_exists && ! empty( $demo['badges'] ) ) {
					// Early Adopter = last badge.
					$wpdb->insert( $user_badges_t, [ 'user_id' => $uid, 'badge_id' => end( $demo['badges'] ), 'awarded_at' => $now ] );
					// First Post = first badge.
					$wpdb->insert( $user_badges_t, [ 'user_id' => $uid, 'badge_id' => $demo['badges'][0], 'awarded_at' => $now ] );
				}
			}
		}

		// Store demo data IDs for cleanup.
		update_option( 'jetonomy_demo_data', $demo );

		flush_rewrite_rules();
		update_option( 'jetonomy_setup_complete', true );

		wp_send_json_success( [ 'message' => __( 'Sample community created with realistic content.', 'jetonomy' ) ] );
	}

	/**
	 * Remove all demo data created by the setup wizard.
	 */
	public function ajax_cleanup_sample_data(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$demo = get_option( 'jetonomy_demo_data', [] );
		if ( empty( $demo ) ) {
			wp_send_json_error( __( 'No demo data found to clean up.', 'jetonomy' ) );
		}

		global $wpdb;

		// Delete replies first (foreign key safety).
		if ( ! empty( $demo['replies'] ) ) {
			$ids = implode( ',', array_map( 'absint', $demo['replies'] ) );
			$wpdb->query( "DELETE FROM " . table( 'replies' ) . " WHERE id IN ({$ids})" );
		}

		// Delete posts.
		if ( ! empty( $demo['posts'] ) ) {
			$ids = implode( ',', array_map( 'absint', $demo['posts'] ) );
			$wpdb->query( "DELETE FROM " . table( 'posts' ) . " WHERE id IN ({$ids})" );
		}

		// Remove space memberships and spaces.
		if ( ! empty( $demo['spaces'] ) ) {
			$ids = implode( ',', array_map( 'absint', $demo['spaces'] ) );
			$wpdb->query( "DELETE FROM " . table( 'space_members' ) . " WHERE space_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM " . table( 'spaces' ) . " WHERE id IN ({$ids})" );
		}

		// Delete categories.
		if ( ! empty( $demo['categories'] ) ) {
			$ids = implode( ',', array_map( 'absint', $demo['categories'] ) );
			$wpdb->query( "DELETE FROM " . table( 'categories' ) . " WHERE id IN ({$ids})" );
		}

		// Delete badges (Pro).
		if ( ! empty( $demo['badges'] ) ) {
			$badges_t      = $wpdb->prefix . 'jt_badges';
			$user_badges_t = $wpdb->prefix . 'jt_user_badges';
			$ids           = implode( ',', array_map( 'absint', $demo['badges'] ) );
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$user_badges_t}'" ) ) {
				$wpdb->query( "DELETE FROM {$user_badges_t} WHERE badge_id IN ({$ids})" );
			}
			if ( $wpdb->get_var( "SHOW TABLES LIKE '{$badges_t}'" ) ) {
				$wpdb->query( "DELETE FROM {$badges_t} WHERE id IN ({$ids})" );
			}
		}

		delete_option( 'jetonomy_demo_data' );

		wp_send_json_success( [ 'message' => __( 'All demo data has been removed.', 'jetonomy' ) ] );
	}
}
