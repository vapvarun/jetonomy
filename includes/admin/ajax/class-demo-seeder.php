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
 * Demo_Seeder — generates a realistic, multi-user model community.
 *
 * Consumes the curated data arrays in {@see Demo_Content} and builds the
 * 4-category / 20-space "model community" used for documentation screenshots
 * and the media kit: ~20 members, ~220 topics, ~700–900 replies spread over
 * ~90 days of backdated timestamps, with a fixed seed for reproducible builds.
 *
 * Covers: users, categories, spaces, posts, replies, votes, accepted answers,
 * idea roadmap statuses, flags, badges, and Pro data (reactions, polls, DM
 * threads) when Jetonomy Pro is active. Everything created is tracked in the
 * returned manifest so {@see self::cleanup()} can remove it.
 *
 * Usage:
 *   $manifest = Demo_Seeder::seed( get_current_user_id() );
 *   Demo_Seeder::cleanup( get_option( 'jetonomy_demo_data', [] ) );
 *   Demo_Seeder::reset_all(); // dev-only full wipe of all Jetonomy content.
 */
class Demo_Seeder {

	/** Fixed RNG seed — keeps the generated model community reproducible. */
	private const SEED = 20260613;

	/** Number of days the seeded content is backdated across. */
	private const SPREAD_DAYS = 90;

	/** Target topics per space (anchors + templated backfill). */
	private const TOPICS_PER_SPACE = 11;

	// ── Entry point ────────────────────────────────────────────────────────────

	/**
	 * Seed the full model-community dataset and return a manifest for cleanup.
	 *
	 * @param int $admin_id WP user ID of the site administrator.
	 * @return array Manifest keyed by entity type.
	 */
	public static function seed( int $admin_id ): array {
		// Deterministic output — same seed every run for reproducible builds.
		// wp_rand() cannot be seeded, so the seeder intentionally uses the
		// Mersenne Twister directly for the structural randomness (reply counts,
		// author distribution). This is demo content, never a security context.
		mt_srand( self::SEED ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand

		$now  = current_time( 'mysql', true );
		$demo = array(
			'users'      => array(),
			'categories' => array(),
			'spaces'     => array(),
			'posts'      => array(),
			'replies'    => array(),
			'flags'      => array(),
			'polls'      => array(),
			'badges'     => array(),
			'threads'    => array(),
		);

		// ── Users ────────────────────────────────────────────────────────────

		$u             = self::create_users( $now );
		$demo['users'] = array_values( $u );

		// login → id, with the administrator addressable as 'admin'.
		$by_login          = $u;
		$by_login['admin'] = $admin_id;

		$member_ids = array_values( $u );
		$all_ids    = array_merge( array( $admin_id ), $member_ids );

		// ── Categories + spaces ────────────────────────────────────────────────

		$space_meta = array(); // space_id → [type, slug] for later generation.

		foreach ( Demo_Content::taxonomy() as $cat_def ) {
			$cat_id = Category::create(
				array(
					'name'        => $cat_def['name'],
					'slug'        => $cat_def['slug'],
					'description' => $cat_def['description'],
					'visibility'  => 'public',
				)
			);
			if ( $cat_id <= 0 ) {
				continue;
			}
			$demo['categories'][] = $cat_id;

			foreach ( $cat_def['spaces'] as $space_def ) {
				$space_id = Space::create(
					array(
						'category_id' => $cat_id,
						'author_id'   => $admin_id,
						'type'        => $space_def['type'],
						'title'       => $space_def['title'],
						'slug'        => $space_def['slug'],
						'description' => $space_def['description'],
						'icon'        => $space_def['icon'],
						'visibility'  => $space_def['visibility'],
						'join_policy' => $space_def['join_policy'],
					),
					$admin_id
				);
				if ( $space_id <= 0 ) {
					continue;
				}
				$demo['spaces'][]        = $space_id;
				$space_meta[ $space_id ] = array(
					'type'    => $space_def['type'],
					'slug'    => $space_def['slug'],
					'title'   => $space_def['title'],
					'private' => 'private' === $space_def['visibility'],
				);
			}
		}

		// ── Membership ──────────────────────────────────────────────────────────
		// Every member joins every PUBLIC space. The private Insiders space is
		// joined only by the admin + a subset (~6 members) so the access screenshot
		// reads correctly.

		$insider_subset = array_slice( $member_ids, 0, 6 );

		foreach ( $space_meta as $space_id => $meta ) {
			$joiners = $meta['private']
				? array_merge( array( $admin_id ), $insider_subset )
				: $all_ids;

			foreach ( $joiners as $i => $uid ) {
				$role   = ( 0 === $i ) ? 'admin' : 'member';
				$result = SpaceMember::add( $space_id, $uid, $role );
				if ( is_wp_error( $result ) ) {
					continue;
				}
			}
		}

		// ── Posts + replies ──────────────────────────────────────────────────────

		$anchors          = Demo_Content::anchor_topics();
		$accepted_bodies  = Demo_Content::accepted_answers();
		$reply_pool       = Demo_Content::reply_pool();
		$accepted_cursor  = 0;
		$idea_status_ring = array( 'planned', 'in_progress', 'completed' );
		$idea_status_idx  = 0;

		// Track post ids that should receive long-tail votes / flags later.
		$votable_posts   = array();
		$votable_replies = array();

		foreach ( $space_meta as $space_id => $meta ) {
			$type        = $meta['type'];
			$slug        = $meta['slug'];
			$title       = $meta['title'];
			$post_type   = self::post_type_for( $type );
			$space_posts = self::build_space_topics( $slug, $title, $type, $anchors );

			$topic_index = 0;
			foreach ( $space_posts as $topic ) {
				++$topic_index;
				$author_id  = $by_login[ $topic['author'] ] ?? self::pick( $member_ids );
				$created_at = self::backdate( $now );
				$post_id    = Post::create(
					array(
						'space_id'      => $space_id,
						'author_id'     => $author_id,
						'type'          => $post_type,
						'title'         => $topic['title'],
						'slug'          => sanitize_title( $topic['title'] ) . '-' . $space_id . '-' . $topic_index,
						'content'       => $topic['content'],
						'content_plain' => wp_strip_all_tags( $topic['content'] ),
						'status'        => 'publish',
						'created_at'    => $created_at,
					)
				);
				if ( is_wp_error( $post_id ) || ! $post_id ) {
					continue;
				}
				$demo['posts'][] = (int) $post_id;

				// Roadmap statuses on idea spaces — spread across the kanban.
				if ( 'idea' === $post_type && 0 === $topic_index % 2 ) {
					$status = $idea_status_ring[ $idea_status_idx % count( $idea_status_ring ) ];
					++$idea_status_idx;
					Post::set_idea_status( (int) $post_id, $status );
				}

				// One "hero thread" per space (the first topic) gets 20–40
				// replies for the single-topic screenshot; the rest get 1–6.
				$is_hero     = ( 1 === $topic_index );
				$reply_count = $is_hero ? wp_rand( 20, 40 ) : wp_rand( 1, 6 );

				// Feed-type status posts stay short — no reply storms.
				if ( 'status' === $post_type ) {
					$reply_count = $is_hero ? wp_rand( 4, 8 ) : wp_rand( 0, 3 );
				}

				$thread_reply_ids = self::seed_replies(
					(int) $post_id,
					$created_at,
					$reply_count,
					$member_ids,
					$reply_pool,
					$now
				);
				foreach ( $thread_reply_ids as $rid ) {
					$demo['replies'][] = $rid;
				}

				// Q&A: ~40% of questions get an accepted answer. Deterministic
				// 2-in-5 selection keyed on the topic index so the ratio is
				// stable across reproducible builds.
				$accepted_bodies_count = count( $accepted_bodies );
				if ( 'question' === $post_type && ! empty( $thread_reply_ids ) && $topic_index % 5 < 2 ) {
					$body = $accepted_bodies[ $accepted_cursor % $accepted_bodies_count ];
					++$accepted_cursor;
					$answer_author = self::pick( $member_ids );
					$answer_at     = self::after( $created_at, $now );
					$answer_id     = Reply::create(
						array(
							'post_id'       => (int) $post_id,
							'author_id'     => $answer_author,
							'content'       => $body,
							'content_plain' => wp_strip_all_tags( $body ),
							'status'        => 'publish',
							'created_at'    => $answer_at,
						)
					);
					if ( ! is_wp_error( $answer_id ) && $answer_id ) {
						$demo['replies'][] = (int) $answer_id;
						Reply::mark_accepted( (int) $answer_id );
						Post::accept_reply( (int) $post_id, (int) $answer_id );
						$votable_replies[] = (int) $answer_id;
					}
				}

				// Collect candidates for long-tail votes (popular topics + a
				// random thread reply).
				if ( $is_hero || 0 === $topic_index % 3 ) {
					$votable_posts[] = (int) $post_id;
				}
				if ( ! empty( $thread_reply_ids ) ) {
					$votable_replies[] = (int) $thread_reply_ids[ array_rand( $thread_reply_ids ) ];
				}
			}
		}

		// ── Long-tail votes ───────────────────────────────────────────────────

		foreach ( $votable_posts as $pid ) {
			self::batch_vote( 'post', $pid, self::sample( $all_ids, wp_rand( 2, 9 ) ), 1 );
		}
		foreach ( $votable_replies as $rid ) {
			self::batch_vote( 'reply', $rid, self::sample( $member_ids, wp_rand( 1, 6 ) ), 1 );
		}

		// ── Pending flags for the moderation queue (~4–6) ─────────────────────

		$demo['flags'] = self::seed_flags( $demo['posts'], $member_ids );

		// ── Badges ─────────────────────────────────────────────────────────────

		$demo['badges'] = self::seed_badges( $all_ids, $by_login, $now );

		// ── Pro data ───────────────────────────────────────────────────────────

		if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
			$pro  = self::seed_pro( $admin_id, $u, $demo, $now );
			$demo = array_merge( $demo, $pro );
		}

		return $demo;
	}

	// ── Full reset (dev-gated) ───────────────────────────────────────────────

	/**
	 * Truncate ALL Jetonomy content and delete prior demo users.
	 *
	 * Destructive. Guarded so it never runs on production. Used by the
	 * `--model` CLI flag to give the model community a clean slate. Wipes every
	 * content table (categories, spaces, posts, replies, votes, flags, members,
	 * subscriptions, read_status, tags + maps, activity_log, join_requests,
	 * notifications, revisions, bookmarks) plus Pro content tables when present,
	 * then deletes the demo users tracked in the jetonomy_demo_data option.
	 *
	 * @return bool True if the reset ran, false if blocked by environment.
	 */
	public static function reset_all(): bool {
		if ( 'production' === wp_get_environment_type() ) {
			return false;
		}

		global $wpdb;

		// Delete demo users (and their profiles) tracked from a prior seed
		// before we truncate the profile table out from under them.
		$prior      = get_option( 'jetonomy_demo_data', array() );
		$user_ids   = array_filter( array_map( 'absint', $prior['users'] ?? array() ) );
		$profiles_t = table( 'user_profiles' );
		foreach ( $user_ids as $uid ) {
			$wpdb->delete( $profiles_t, array( 'user_id' => $uid ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_delete_user( $uid );
		}
		delete_option( 'jetonomy_demo_data' );

		// Free content tables.
		$free_tables = array(
			'categories',
			'spaces',
			'posts',
			'replies',
			'votes',
			'flags',
			'space_members',
			'subscriptions',
			'read_status',
			'tags',
			'post_tags',
			'space_tags',
			'space_tag_map',
			'user_interests',
			'activity_log',
			'access_rules',
			'restrictions',
			'join_requests',
			'invite_links',
			'notifications',
			'revisions',
			'bookmarks',
		);
		foreach ( $free_tables as $suffix ) {
			self::truncate( table( $suffix ) );
		}

		// Pro + badge tables (prefixed names, truncated only when present).
		$prefixed_tables = array(
			'jt_pro_reactions',
			'jt_pro_polls',
			'jt_pro_poll_options',
			'jt_pro_poll_votes',
			'jt_pro_conversations',
			'jt_pro_conversation_participants',
			'jt_pro_messages',
			'jt_user_badges',
			'jt_badges',
		);
		foreach ( $prefixed_tables as $table_suffix ) {
			self::truncate( $wpdb->prefix . $table_suffix );
		}

		return true;
	}

	// ── Pro-specific seeding ───────────────────────────────────────────────────

	/**
	 * Seed reactions, polls, and DM threads if the Pro tables exist.
	 *
	 * @param int    $admin_id Administrator user ID.
	 * @param array  $u        login → id map of demo members.
	 * @param array  $demo     Manifest built so far (posts/replies available).
	 * @param string $now      Current UTC datetime.
	 * @return array Pro manifest delta (polls, threads).
	 */
	private static function seed_pro( int $admin_id, array $u, array $demo, string $now ): array {
		global $wpdb;

		$member_ids = array_values( $u );
		$all_ids    = array_merge( array( $admin_id ), $member_ids );
		$pro        = array(
			'polls'   => array(),
			'threads' => array(),
		);

		// ── Reactions on a handful of popular posts + replies ──────────────────

		$reactions_t = $wpdb->prefix . 'jt_pro_reactions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reactions_t ) ) === $reactions_t ) {
			$emojis        = array( 'thumbsup', 'heart', 'hooray', 'rocket', 'eyes' );
			$popular_posts = array_slice( $demo['posts'], 0, 12 );
			foreach ( $popular_posts as $pid ) {
				foreach ( self::sample( $all_ids, wp_rand( 2, 6 ) ) as $uid ) {
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$reactions_t,
						array(
							'user_id'     => $uid,
							'object_type' => 'post',
							'object_id'   => $pid,
							'emoji'       => $emojis[ array_rand( $emojis ) ],
							'created_at'  => $now,
						)
					);
				}
			}
			$popular_replies = array_slice( $demo['replies'], 0, 10 );
			foreach ( $popular_replies as $rid ) {
				foreach ( self::sample( $member_ids, wp_rand( 1, 4 ) ) as $uid ) {
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$reactions_t,
						array(
							'user_id'     => $uid,
							'object_type' => 'reply',
							'object_id'   => $rid,
							'emoji'       => $emojis[ array_rand( $emojis ) ],
							'created_at'  => $now,
						)
					);
				}
			}
		}

		// ── Polls (2–3) on popular discussion posts ────────────────────────────

		$polls_t   = $wpdb->prefix . 'jt_pro_polls';
		$options_t = $wpdb->prefix . 'jt_pro_poll_options';
		$pvotes_t  = $wpdb->prefix . 'jt_pro_poll_votes';

		$have_polls = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $polls_t ) ) === $polls_t
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $options_t ) ) === $options_t
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pvotes_t ) ) === $pvotes_t;

		if ( $have_polls && count( $demo['posts'] ) >= 3 ) {
			$poll_defs = array(
				array(
					'question' => 'How important is dark mode to you?',
					'options'  => array(
						"Critical — I can't use apps without it",
						'Nice to have — would use it often',
						'Indifferent — I follow system settings',
						'Not needed — light mode is fine',
					),
				),
				array(
					'question' => 'Which feature should we build next?',
					'options'  => array(
						'Weekly digest email',
						'Bookmarks / saved posts',
						'Scheduled posts',
						'Bulk member import',
					),
				),
				array(
					'question' => 'How large is your community?',
					'options'  => array(
						'Under 100 members',
						'100 to 1,000 members',
						'1,000 to 10,000 members',
						'Over 10,000 members',
					),
				),
			);

			$post_targets = self::sample( $demo['posts'], count( $poll_defs ) );
			foreach ( $poll_defs as $i => $def ) {
				$target_post = $post_targets[ $i ] ?? $demo['posts'][0];
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$polls_t,
					array(
						'post_id'     => $target_post,
						'question'    => $def['question'],
						'type'        => 'single',
						'allow_other' => 0,
						'created_by'  => self::pick( $member_ids ),
						'created_at'  => $now,
					)
				);
				$poll_id        = (int) $wpdb->insert_id;
				$pro['polls'][] = $poll_id;

				$option_ids = array();
				foreach ( $def['options'] as $sort => $label ) {
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$options_t,
						array(
							'poll_id'    => $poll_id,
							'label'      => $label,
							'sort_order' => $sort,
							'vote_count' => 0,
						)
					);
					$option_ids[] = (int) $wpdb->insert_id;
				}

				// Spread votes across the option set for a realistic chart.
				$counts = array();
				foreach ( self::sample( $all_ids, wp_rand( 6, 14 ) ) as $voter ) {
					$opt_id            = $option_ids[ array_rand( $option_ids ) ];
					$counts[ $opt_id ] = ( $counts[ $opt_id ] ?? 0 ) + 1;
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$pvotes_t,
						array(
							'poll_id'    => $poll_id,
							'option_id'  => $opt_id,
							'user_id'    => $voter,
							'created_at' => $now,
						)
					);
				}
				foreach ( $counts as $opt_id => $count ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					$wpdb->query( $wpdb->prepare( "UPDATE {$options_t} SET vote_count = vote_count + %d WHERE id = %d", $count, $opt_id ) );
				}
			}
		}

		// ── DM threads (a couple) when the messaging tables exist ──────────────

		$conv_t  = $wpdb->prefix . 'jt_pro_conversations';
		$part_t  = $wpdb->prefix . 'jt_pro_conversation_participants';
		$msg_t   = $wpdb->prefix . 'jt_pro_messages';
		$have_dm = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $msg_t ) ) === $msg_t
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv_t ) ) === $conv_t
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $part_t ) ) === $part_t;

		if ( $have_dm && count( $member_ids ) >= 4 ) {
			$dm_scripts = array(
				array(
					'a'    => $member_ids[0],
					'b'    => $member_ids[2],
					'msgs' => array(
						array(
							'from' => 'a',
							'body' => 'Hey, loved your migration write-up. Mind if I link it from our Knowledge Base?',
						),
						array(
							'from' => 'b',
							'body' => 'Of course, go for it! Happy it was useful.',
						),
						array(
							'from' => 'a',
							'body' => 'Thanks! Will credit you. The dry-run tip alone saved me hours.',
						),
					),
				),
				array(
					'a'    => $member_ids[1],
					'b'    => $member_ids[3],
					'msgs' => array(
						array(
							'from' => 'a',
							'body' => 'Are you going to the AMA next week? I have a webhook question I keep forgetting to ask.',
						),
						array(
							'from' => 'b',
							'body' => 'Planning to. Drop it in Events & Office Hours too so others can see the answer.',
						),
					),
				),
			);

			foreach ( $dm_scripts as $script ) {
				$last_body = $script['msgs'][ count( $script['msgs'] ) - 1 ]['body'];
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$conv_t,
					array(
						'type'                 => 'direct',
						'created_by'           => $script['a'],
						'last_message_at'      => $now,
						'last_message_preview' => mb_substr( $last_body, 0, 255 ),
						'message_count'        => count( $script['msgs'] ),
						'created_at'           => $now,
					)
				);
				$conv_id = (int) $wpdb->insert_id;
				if ( ! $conv_id ) {
					continue;
				}
				$pro['threads'][] = $conv_id;

				foreach ( array( $script['a'], $script['b'] ) as $participant ) {
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$part_t,
						array(
							'conversation_id' => $conv_id,
							'user_id'         => $participant,
							'last_read_at'    => $now,
							'joined_at'       => $now,
						)
					);
				}

				foreach ( $script['msgs'] as $m ) {
					$sender = 'a' === $m['from'] ? $script['a'] : $script['b'];
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$msg_t,
						array(
							'conversation_id' => $conv_id,
							'sender_id'       => $sender,
							'content'         => $m['body'],
							'content_plain'   => wp_strip_all_tags( $m['body'] ),
							'created_at'      => $now,
						)
					);
				}
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

		$post_ids   = array_filter( array_map( 'absint', $manifest['posts'] ?? array() ) );
		$reply_ids  = array_filter( array_map( 'absint', $manifest['replies'] ?? array() ) );
		$user_ids   = array_filter( array_map( 'absint', $manifest['users'] ?? array() ) );
		$poll_ids   = array_filter( array_map( 'absint', $manifest['polls'] ?? array() ) );
		$badge_ids  = array_filter( array_map( 'absint', $manifest['badges'] ?? array() ) );
		$thread_ids = array_filter( array_map( 'absint', $manifest['threads'] ?? array() ) );

		// --- Pro: DM threads ---

		$conv_t = $wpdb->prefix . 'jt_pro_conversations';
		$part_t = $wpdb->prefix . 'jt_pro_conversation_participants';
		$msg_t  = $wpdb->prefix . 'jt_pro_messages';

		if ( $thread_ids ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $msg_t ) ) === $msg_t ) {
				self::delete_in( $msg_t, 'conversation_id', $thread_ids );
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $part_t ) ) === $part_t ) {
				self::delete_in( $part_t, 'conversation_id', $thread_ids );
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $conv_t ) ) === $conv_t ) {
				self::delete_in( $conv_t, 'id', $thread_ids );
			}
		}

		// --- Pro: Reactions ---

		$reactions_t = $wpdb->prefix . 'jt_pro_reactions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reactions_t ) ) === $reactions_t ) {
			self::delete_in( $reactions_t, 'object_id', $post_ids, "AND object_type = 'post'" );
			self::delete_in( $reactions_t, 'object_id', $reply_ids, "AND object_type = 'reply'" );
		}

		// --- Pro: Polls ---

		$polls_t   = $wpdb->prefix . 'jt_pro_polls';
		$options_t = $wpdb->prefix . 'jt_pro_poll_options';
		$pvotes_t  = $wpdb->prefix . 'jt_pro_poll_votes';

		if ( $poll_ids ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pvotes_t ) ) === $pvotes_t ) {
				self::delete_in( $pvotes_t, 'poll_id', $poll_ids );
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $options_t ) ) === $options_t ) {
				self::delete_in( $options_t, 'poll_id', $poll_ids );
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $polls_t ) ) === $polls_t ) {
				self::delete_in( $polls_t, 'id', $poll_ids );
			}
		}

		// --- Badges (Pro custom-badges tables) ---
		// Awards are removed by demo user_id (so awards of any reused Pro-default
		// badge to non-demo members are left intact). Badge definitions are
		// removed only for ids this seeder actually inserted, never Pro defaults.

		$badges_t      = $wpdb->prefix . 'jt_pro_badges';
		$user_badges_t = $wpdb->prefix . 'jt_pro_user_badges';

		if ( $user_ids && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $user_badges_t ) ) === $user_badges_t ) {
			self::delete_in( $user_badges_t, 'user_id', $user_ids );
		}
		if ( $badge_ids && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $badges_t ) ) === $badges_t ) {
			self::delete_in( $badges_t, 'id', $badge_ids );
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

		$space_ids = array_filter( array_map( 'absint', $manifest['spaces'] ?? array() ) );
		if ( $space_ids ) {
			self::delete_in( table( 'space_members' ), 'space_id', $space_ids );
			self::delete_in( table( 'spaces' ), 'id', $space_ids );
		}

		// --- Categories ---

		$cat_ids = array_filter( array_map( 'absint', $manifest['categories'] ?? array() ) );
		self::delete_in( table( 'categories' ), 'id', $cat_ids );

		// --- Users ---

		$profiles_t = table( 'user_profiles' );
		foreach ( $user_ids as $uid ) {
			$wpdb->delete( $profiles_t, array( 'user_id' => $uid ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_delete_user( $uid );
		}
	}

	// ── Generation helpers ───────────────────────────────────────────────────

	/**
	 * Map a space type to its post type.
	 */
	private static function post_type_for( string $space_type ): string {
		switch ( $space_type ) {
			case 'qa':
				return 'question';
			case 'ideas':
				return 'idea';
			case 'feed':
				return 'status';
			case 'forum':
			default:
				return 'topic';
		}
	}

	/**
	 * Build the topic list for one space: curated anchors first, then
	 * templated backfill up to TOPICS_PER_SPACE.
	 *
	 * @param string $slug    Space slug (anchor key).
	 * @param string $title   Space title (template subject).
	 * @param string $type    Space type (pool selector).
	 * @param array  $anchors All anchor topics keyed by slug.
	 * @return array<int, array{author:string,title:string,content:string}>
	 */
	private static function build_space_topics( string $slug, string $title, string $type, array $anchors ): array {
		$topics     = $anchors[ $slug ] ?? array();
		$pool       = Demo_Content::topic_pool( $type );
		$pool_count = count( $pool );
		$pool_i     = 0;

		// Lowercase the subject for natural-reading templated titles.
		$subject     = strtolower( $title );
		$topic_total = count( $topics );

		while ( $topic_total < self::TOPICS_PER_SPACE && $pool_count > 0 ) {
			$tpl = $pool[ $pool_i % $pool_count ];
			++$pool_i;
			++$topic_total;
			$topics[] = array(
				'author'  => '', // Generator assigns a random member.
				'title'   => sprintf( $tpl['title'], $subject ),
				'content' => $tpl['content'],
			);
		}

		return $topics;
	}

	/**
	 * Seed N replies on a post, distributing authorship and backdating each
	 * reply after the parent post's creation.
	 *
	 * @param int    $post_id    Parent post.
	 * @param string $post_dt    Parent created_at.
	 * @param int    $count      How many replies.
	 * @param array  $member_ids Candidate authors.
	 * @param array  $reply_pool Reply snippet pool.
	 * @param string $now        Upper datetime bound.
	 * @return array<int, int> Created reply ids.
	 */
	private static function seed_replies( int $post_id, string $post_dt, int $count, array $member_ids, array $reply_pool, string $now ): array {
		$ids       = array();
		$last_seen = array(); // author → true, to avoid back-to-back same author.

		for ( $i = 0; $i < $count; $i++ ) {
			// Pick an author distinct from the immediately previous one.
			$author = self::pick( $member_ids );
			if ( count( $member_ids ) > 1 ) {
				$guard = 0;
				while ( isset( $last_seen['id'] ) && $author === $last_seen['id'] && $guard < 5 ) {
					$author = self::pick( $member_ids );
					++$guard;
				}
			}
			$last_seen['id'] = $author;

			$body = $reply_pool[ array_rand( $reply_pool ) ];
			$rid  = Reply::create(
				array(
					'post_id'       => $post_id,
					'author_id'     => $author,
					'content'       => $body,
					'content_plain' => wp_strip_all_tags( $body ),
					'status'        => 'publish',
					'created_at'    => self::after( $post_dt, $now ),
				)
			);
			if ( ! is_wp_error( $rid ) && $rid ) {
				$ids[] = (int) $rid;
			}
		}

		return $ids;
	}

	/**
	 * Create 4–6 pending flags across posts for the moderation queue.
	 *
	 * @param array $post_ids   All seeded post ids.
	 * @param array $member_ids Candidate reporters.
	 * @return array<int, int> Created flag ids.
	 */
	private static function seed_flags( array $post_ids, array $member_ids ): array {
		if ( empty( $post_ids ) || empty( $member_ids ) ) {
			return array();
		}

		// Reasons map to the jt_flags.reason ENUM:
		// spam | offensive | off_topic | harassment | other.
		$reasons = array(
			array( 'spam', 'This reads like a promotion, not a contribution.' ),
			array( 'off_topic', 'This belongs in the Off-Topic Lounge, not here.' ),
			array( 'spam', 'No link or context for the resource being referenced.' ),
			array( 'offensive', 'The tone here feels dismissive toward newcomers.' ),
			array( 'off_topic', 'Interesting, but unrelated to this space.' ),
			array( 'other', 'Possible duplicate of an earlier topic.' ),
		);

		$targets = self::sample( $post_ids, wp_rand( 4, 6 ) );
		$flags   = array();
		foreach ( $targets as $i => $pid ) {
			$r       = $reasons[ $i % count( $reasons ) ];
			$flag_id = Flag::create(
				array(
					'object_type' => 'post',
					'object_id'   => $pid,
					'reporter_id' => self::pick( $member_ids ),
					'reason'      => $r[0],
					'description' => $r[1],
				)
			);
			if ( $flag_id ) {
				$flags[] = (int) $flag_id;
			}
		}

		return $flags;
	}

	/**
	 * Define and award the badge set. Returns the created badge ids.
	 *
	 * @param array  $all_ids  Admin + members.
	 * @param array  $by_login login → id map (admin addressable).
	 * @param string $now      Current datetime.
	 * @return array<int, int> Badge ids.
	 */
	private static function seed_badges( array $all_ids, array $by_login, string $now ): array {
		global $wpdb;

		// Badges live in the Pro custom-badges tables. The schema carries a
		// UNIQUE slug, a category enum, and a JSON criteria column; awards key
		// on earned_at. Skip cleanly when Pro / its tables are absent.
		$badges_t      = $wpdb->prefix . 'jt_pro_badges';
		$user_badges_t = $wpdb->prefix . 'jt_pro_user_badges';
		$badge_ids     = array(); // All resolved ids (created + reused) for awarding.
		$created_ids   = array(); // Only ids this seeder inserted — cleanup deletes these.

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $badges_t ) ) !== $badges_t ) {
			return $created_ids;
		}

		// name, slug, description, icon, tier, category, criteria_type, criteria_value, reputation_bonus.
		$badge_defs = array(
			array( 'First Post', 'first-post', 'Created your first post in the community.', 'pencil', 'bronze', 'participation', 'post_count', 1, 5 ),
			array( 'Conversation Starter', 'conversation-starter', 'Started 10 discussions that got replies.', 'message-square', 'silver', 'participation', 'post_count', 10, 25 ),
			array( 'Helpful Member', 'helpful-member', 'Posted 25 replies that helped fellow members.', 'heart', 'bronze', 'quality', 'reply_count', 25, 25 ),
			array( 'Community Pillar', 'community-pillar', 'Reached 100 reputation points through quality contributions.', 'star', 'gold', 'community', 'reputation', 100, 50 ),
			array( 'Rising Star', 'rising-star', 'Earned Trust Level 2 through consistent participation.', 'rocket', 'silver', 'participation', 'trust_level', 2, 20 ),
			array( 'Veteran', 'veteran', 'Active member for over 30 days.', 'shield', 'bronze', 'community', 'days_active', 30, 15 ),
			array( 'Top Contributor', 'top-contributor', 'Reached 500 reputation — a true community leader.', 'trophy', 'gold', 'quality', 'reputation', 500, 100 ),
			array( 'Early Adopter', 'early-adopter', 'Joined during the community launch period.', 'flag', 'silver', 'special', 'manual', 0, 10 ),
		);

		foreach ( $badge_defs as $b ) {
			// The Pro custom-badges extension pre-seeds its own defaults on
			// activation, several sharing these slugs. Reuse an existing badge
			// rather than colliding on the UNIQUE slug; only insert new ones.
			$existing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT id FROM {$badges_t} WHERE slug = %s", $b[1] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( $existing > 0 ) {
				$badge_ids[] = $existing;
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$badges_t,
				array(
					'name'             => $b[0],
					'slug'             => $b[1],
					'description'      => $b[2],
					'icon'             => $b[3],
					'tier'             => $b[4],
					'category'         => $b[5],
					'criteria'         => wp_json_encode(
						array(
							'type'  => $b[6],
							'value' => $b[7],
						)
					),
					'reputation_bonus' => $b[8],
					'is_repeatable'    => 0,
					'is_active'        => 1,
					'created_at'       => $now,
				)
			);
			$new_id        = (int) $wpdb->insert_id;
			$badge_ids[]   = $new_id;
			$created_ids[] = $new_id;
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $user_badges_t ) ) !== $user_badges_t ) {
			return $created_ids;
		}

		$award = static function ( int $uid, int $badge_id ) use ( $wpdb, $user_badges_t, $now ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$user_badges_t,
				array(
					'user_id'   => $uid,
					'badge_id'  => $badge_id,
					'earned_at' => $now,
				)
			);
		};

		$badge_early  = end( $badge_ids );   // Early Adopter.
		$badge_first  = $badge_ids[0];        // First Post.
		$badge_helper = $badge_ids[2];        // Helpful Member.
		$badge_pillar = $badge_ids[3];        // Community Pillar.
		$badge_rising = $badge_ids[4];        // Rising Star.
		$badge_top    = $badge_ids[6];        // Top Contributor.

		// Everyone gets Early Adopter + First Post.
		foreach ( $all_ids as $uid ) {
			$award( $uid, $badge_early );
			$award( $uid, $badge_first );
		}

		// Higher achievements to the moderators + top members.
		foreach ( array( 'maya', 'theo', 'alice', 'bob' ) as $login ) {
			if ( isset( $by_login[ $login ] ) ) {
				$award( $by_login[ $login ], $badge_helper );
				$award( $by_login[ $login ], $badge_pillar );
			}
		}
		foreach ( array( 'maya', 'theo' ) as $login ) {
			if ( isset( $by_login[ $login ] ) ) {
				$award( $by_login[ $login ], $badge_top );
			}
		}
		if ( isset( $by_login['priya'] ) ) {
			$award( $by_login['priya'], $badge_rising );
		}

		return $created_ids;
	}

	/**
	 * Create the demo WordPress users (2 moderators + ~17 members) with
	 * Jetonomy profiles and varied trust levels / reputation / bios.
	 *
	 * @param string $now Current datetime.
	 * @return array<string, int> Map of login → user_id.
	 */
	private static function create_users( string $now ): array {
		global $wpdb;

		$profiles_t = table( 'user_profiles' );
		$users      = array();

		foreach ( Demo_Content::users() as $def ) {
			$uid = username_exists( $def['login'] );
			if ( ! $uid ) {
				$uid = wp_insert_user(
					array(
						'user_login'   => $def['login'],
						'user_email'   => $def['email'],
						'display_name' => $def['display'],
						'user_pass'    => wp_generate_password( 24, true, true ),
						'role'         => 'subscriber',
					)
				);
				if ( is_wp_error( $uid ) ) {
					continue;
				}
			}

			UserProfile::find_or_create( (int) $uid );

			// Stagger the join date so the profile / leaderboard reads
			// realistically across the ~90-day window.
			$joined = self::backdate( $now );

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$profiles_t,
				array(
					'trust_level' => $def['trust_level'],
					'reputation'  => $def['reputation'],
					'bio'         => $def['bio'],
					'created_at'  => $joined,
					'updated_at'  => $now,
				),
				array( 'user_id' => (int) $uid )
			);

			$users[ $def['login'] ] = (int) $uid;
		}

		return $users;
	}

	/**
	 * Cast the same vote value for multiple users on one object.
	 *
	 * @param string $type      'post' or 'reply'.
	 * @param int    $object_id Target id.
	 * @param array  $user_ids  Voters.
	 * @param int    $value     Vote value (+1 / -1).
	 */
	private static function batch_vote( string $type, int $object_id, array $user_ids, int $value ): void {
		if ( ! $object_id ) {
			return;
		}
		foreach ( $user_ids as $uid ) {
			if ( $uid ) {
				$result = Vote::cast( $uid, $type, $object_id, $value );
				if ( is_wp_error( $result ) ) {
					continue;
				}
			}
		}
	}

	/**
	 * DELETE FROM $table WHERE $col IN ($ids) [$extra_sql].
	 *
	 * @param string $table     Full table name.
	 * @param string $col       Column to match.
	 * @param array  $ids       Integer ids.
	 * @param string $extra_sql Optional trailing predicate.
	 */
	private static function delete_in( string $table, string $col, array $ids, string $extra_sql = '' ): void {
		if ( empty( $ids ) ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$col} IN ({$placeholders}) {$extra_sql}", ...$ids ) );
	}

	/**
	 * TRUNCATE a table by its full name. No-op when the table is absent — some
	 * installs predate (or skip) optional tables, and the dead space-tag tables
	 * were dropped in 1.5.0, so a blind TRUNCATE would only emit log noise.
	 *
	 * @param string $table Full table name (already prefixed).
	 */
	private static function truncate( string $table ): void {
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	// ── Small RNG helpers (seeded via mt_srand in seed()) ─────────────────────

	/**
	 * Pick one element at random.
	 *
	 * @param array $items Candidates.
	 * @return mixed One element, or 0 if empty.
	 */
	private static function pick( array $items ) {
		if ( empty( $items ) ) {
			return 0;
		}
		return $items[ array_rand( $items ) ];
	}

	/**
	 * Take up to $n distinct random elements from $items.
	 *
	 * @param array $items Candidates.
	 * @param int   $n     How many.
	 * @return array Subset (order randomized).
	 */
	private static function sample( array $items, int $n ): array {
		$items = array_values( $items );
		$count = count( $items );
		if ( 0 === $count ) {
			return array();
		}
		$n    = min( $n, $count );
		$keys = (array) array_rand( $items, $n );
		$out  = array();
		foreach ( $keys as $k ) {
			$out[] = $items[ $k ];
		}
		return $out;
	}

	/**
	 * Return a MySQL datetime backdated 1..SPREAD_DAYS days before $now.
	 *
	 * @param string $now Upper bound (MySQL UTC).
	 * @return string Backdated MySQL datetime.
	 */
	private static function backdate( string $now ): string {
		$ts     = strtotime( $now );
		$offset = wp_rand( DAY_IN_SECONDS, self::SPREAD_DAYS * DAY_IN_SECONDS );
		return gmdate( 'Y-m-d H:i:s', $ts - $offset );
	}

	/**
	 * Return a MySQL datetime randomly between $after and $now.
	 *
	 * @param string $after Lower bound (MySQL UTC).
	 * @param string $now   Upper bound (MySQL UTC).
	 * @return string Datetime between the two bounds.
	 */
	private static function after( string $after, string $now ): string {
		$lo = strtotime( $after );
		$hi = strtotime( $now );
		if ( $hi <= $lo ) {
			return $now;
		}
		return gmdate( 'Y-m-d H:i:s', wp_rand( $lo, $hi ) );
	}
}
