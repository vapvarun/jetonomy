<?php
/**
 * bbPress importer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post as JtPost;
use Jetonomy\Models\Reply as JtReply;
use Jetonomy\Models\UserProfile;
use function Jetonomy\now;

class BBPress_Importer extends Importer {

	public function get_source_name(): string {
		return 'bbPress';
	}

	public function is_source_available(): bool {
		global $wpdb;
		// Check if bbPress post types exist
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('forum', 'topic', 'reply')"
		);
		return $count > 0;
	}

	public function get_source_stats(): array {
		global $wpdb;
		return [
			'forums'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status = 'publish'" ),
			'topics'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status = 'publish'" ),
			'replies' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status = 'publish'" ),
		];
	}

	public function get_total_count(): int {
		global $wpdb;
		$forums  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status = 'publish'" );
		$topics  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status = 'publish'" );
		$replies = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status = 'publish'" );
		return $forums + $topics + $replies;
	}

	/**
	 * Carry a bbPress topic/reply's attachments onto the imported object.
	 *
	 * Nothing like wpForo's. Verified against a real bbPress 2.6 + GD bbPress
	 * Attachments 4.9 install by uploading through the plugin's own front-end form
	 * and reading what it wrote:
	 *
	 *   - The attachment IS an ordinary WP media item. wp_insert_attachment() is
	 *     called with the topic/reply id as the parent, so `post_parent` points at
	 *     the topic or the reply (never the forum), plus a `_bbp_attachment` meta
	 *     flag. That post_parent lookup is the plugin's own accessor
	 *     (d4p_get_post_attachments(), code/public.php), so it is the contract.
	 *   - The file already lives in uploads/YYYY/MM. Nothing to recover, nothing
	 *     to sideload — it is in the media library the moment it is uploaded.
	 *   - The body carries NO attachment markup, so there is nothing to strip.
	 *
	 * So this is purely a re-link: the file survives untouched either way, and all
	 * that is missing is the row that makes Jetonomy render it. That link is Pro, so
	 * on a free site nothing happens here and no file is lost — the media items are
	 * already in the library, and enabling Pro later reveals them.
	 *
	 * @param string $object_type   'post' or 'reply'.
	 * @param int    $source_post_id bbPress topic/reply post ID.
	 * @param int    $object_id      Imported Jetonomy post/reply id.
	 * @return int Attachments linked.
	 */
	private function migrate_bbpress_attachments( string $object_type, int $source_post_id, int $object_id ): int {
		if ( ! $source_post_id || ! $object_id || ! $this->attachments_available() ) {
			return 0;
		}

		$attachments = get_posts(
			[
				'post_type'        => 'attachment',
				'post_parent'      => $source_post_id,
				'posts_per_page'   => -1,
				'post_status'      => 'inherit',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			]
		);

		$linked = 0;
		$sort   = 0;

		foreach ( $attachments as $attachment_id ) {
			if ( $this->link_attachment( $object_type, $object_id, (int) $attachment_id, $sort ) ) {
				++$linked;
				++$sort;
			}
		}

		return $linked;
	}

	/**
	 * Also drop the import category id on a fresh run so a restarted import does
	 * not leave an orphan option behind. parent handles the shared id_map +
	 * processed counter.
	 */
	public function reset_run_state(): void {
		parent::reset_run_state();
		delete_option( 'jetonomy_import_bbpress_cat_id' );
	}

	public function run_batch( string $phase, int $offset, int $batch_size ): array {
		global $wpdb;

		switch ( $phase ) {
			case 'forums':
				$forums = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status = 'publish' ORDER BY menu_order ASC, ID ASC LIMIT %d OFFSET %d",
						$batch_size,
						$offset
					)
				);

				if ( empty( $forums ) ) {
					return [
						'phase'     => 'topics',
						'offset'    => 0,
						'done'      => false,
						'processed' => 0,
					];
				}

				// First batch: create import category.
				if ( 0 === $offset ) {
					$cat_id = Category::create(
						[
							'name'       => __( 'Imported from bbPress', 'jetonomy' ),
							'slug'       => 'imported-bbpress-' . time(),
							'visibility' => 'public',
						]
					);
					update_option( 'jetonomy_import_bbpress_cat_id', $cat_id );
				}

				$cat_id = (int) get_option( 'jetonomy_import_bbpress_cat_id', 0 );

				foreach ( $forums as $forum ) {
					$space_id = Space::create(
						[
							'category_id' => $cat_id,
							'author_id'   => (int) $forum->post_author ?: 1,
							'type'        => 'forum',
							'title'       => $forum->post_title,
							'slug'        => $forum->post_name ?: sanitize_title( $forum->post_title ),
							'description' => wp_strip_all_tags( $forum->post_content ),
							'visibility'  => 'public',
							'join_policy' => 'open',
						]
					);
					if ( $space_id ) {
						$this->map_id( 'forum', $forum->ID, $space_id );
						++$this->imported;
					}
				}

				update_option( 'jetonomy_import_id_map', $this->id_map, false );

				$has_more = count( $forums ) >= $batch_size;
				return [
					'phase'     => $has_more ? 'forums' : 'topics',
					'offset'    => $has_more ? $offset + $batch_size : 0,
					'done'      => false,
					'processed' => count( $forums ),
				];

			case 'topics':
				$this->id_map = get_option( 'jetonomy_import_id_map', [] );

				$topics = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status = 'publish' ORDER BY ID ASC LIMIT %d OFFSET %d",
						$batch_size,
						$offset
					)
				);

				if ( empty( $topics ) ) {
					return [
						'phase'     => 'replies',
						'offset'    => 0,
						'done'      => false,
						'processed' => 0,
					];
				}

				foreach ( $topics as $topic ) {
					$forum_id = (int) $topic->post_parent;
					$space_id = $this->get_mapped_id( 'forum', $forum_id );
					if ( ! $space_id ) {
						++$this->skipped;
						continue;
					}

					$is_sticky = (int) get_post_meta( $topic->ID, '_bbp_topic_sticky', true );

					$post_id = JtPost::create(
						[
							'space_id'      => $space_id,
							'author_id'     => (int) $topic->post_author,
							'type'          => 'topic',
							'title'         => $topic->post_title,
							'slug'          => $topic->post_name ?: sanitize_title( $topic->post_title ),
							'content'       => wp_kses_post( $topic->post_content ),
							'content_plain' => wp_strip_all_tags( $topic->post_content ),
							'status'        => 'publish',
							'is_sticky'     => $is_sticky ? 1 : 0,
							'created_at'    => $topic->post_date_gmt ?: now(),
						]
					);

					if ( is_wp_error( $post_id ) ) {
						++$this->skipped;
						continue;
					}

					if ( $post_id ) {
						$this->map_id( 'topic', $topic->ID, $post_id );
						$this->migrate_bbpress_attachments( 'post', (int) $topic->ID, (int) $post_id );
						++$this->imported;
					}
				}

				update_option( 'jetonomy_import_id_map', $this->id_map, false );

				$has_more = count( $topics ) >= $batch_size;
				return [
					'phase'     => $has_more ? 'topics' : 'replies',
					'offset'    => $has_more ? $offset + $batch_size : 0,
					'done'      => false,
					'processed' => count( $topics ),
				];

			case 'replies':
				$this->id_map = get_option( 'jetonomy_import_id_map', [] );

				$replies = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status = 'publish' ORDER BY ID ASC LIMIT %d OFFSET %d",
						$batch_size,
						$offset
					)
				);

				if ( empty( $replies ) ) {
					return [
						'phase'     => 'profiles',
						'offset'    => 0,
						'done'      => false,
						'processed' => 0,
					];
				}

				foreach ( $replies as $reply ) {
					$topic_id = (int) $reply->post_parent;
					$post_id  = $this->get_mapped_id( 'topic', $topic_id );
					if ( ! $post_id ) {
						++$this->skipped;
						continue;
					}

					$reply_id = JtReply::create(
						[
							'post_id'       => $post_id,
							'author_id'     => (int) $reply->post_author,
							'content'       => wp_kses_post( $reply->post_content ),
							'content_plain' => wp_strip_all_tags( $reply->post_content ),
							'status'        => 'publish',
							'created_at'    => $reply->post_date_gmt ?: now(),
						]
					);

					if ( is_wp_error( $reply_id ) ) {
						++$this->skipped;
						continue;
					}

					if ( $reply_id ) {
						// The batched path never mapped replies (the legacy run() path
						// did). Nothing downstream could resolve an imported reply --
						// including its attachments.
						$this->map_id( 'reply', $reply->ID, $reply_id );
						$this->migrate_bbpress_attachments( 'reply', (int) $reply->ID, (int) $reply_id );
						++$this->imported;
					}
				}

				update_option( 'jetonomy_import_id_map', $this->id_map, false );

				$has_more = count( $replies ) >= $batch_size;
				return [
					'phase'     => $has_more ? 'replies' : 'profiles',
					'offset'    => $has_more ? $offset + $batch_size : 0,
					'done'      => false,
					'processed' => count( $replies ),
				];

			case 'profiles':
				$author_ids = $wpdb->get_col(
					"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ('topic', 'reply') AND post_status = 'publish' AND post_author > 0"
				);
				foreach ( $author_ids as $uid ) {
					UserProfile::find_or_create( (int) $uid );
				}
				return [
					'phase'     => 'recount',
					'offset'    => 0,
					'done'      => false,
					'processed' => count( $author_ids ),
				];

			case 'recount':
				$this->recount();
				delete_option( 'jetonomy_import_id_map' );
				delete_option( 'jetonomy_import_bbpress_cat_id' );
				flush_rewrite_rules();
				return [
					'phase'     => 'complete',
					'offset'    => 0,
					'done'      => true,
					'processed' => 0,
				];

			default:
				return [
					'phase'     => 'complete',
					'offset'    => 0,
					'done'      => true,
					'processed' => 0,
				];
		}
	}

	public function run( array $options = [] ): array {
		// 1. Create a default category for imported forums
		if ( ! $this->dry_run ) {
			$cat_id = Category::create(
				[
					'name'        => __( 'Imported from bbPress', 'jetonomy' ),
					'slug'        => 'imported-bbpress',
					'description' => __( 'Forums imported from bbPress', 'jetonomy' ),
				]
			);
		} else {
			$cat_id = 0; // Simulate
		}

		// 2. Import forums as spaces
		$this->import_forums( $cat_id );

		// 3. Import topics as posts
		$this->import_topics();

		// 4. Import replies
		$this->import_replies();

		// 5. Create user profiles for all authors (skip in dry-run)
		if ( ! $this->dry_run ) {
			$this->create_profiles();
		}

		// 6. Recount all denormalized fields (skip in dry-run)
		if ( ! $this->dry_run ) {
			$this->recount();
		}

		return $this->results();
	}

	private function import_forums( int $cat_id ): void {
		global $wpdb;

		$forums = $wpdb->get_results(
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status = 'publish' ORDER BY menu_order ASC, ID ASC"
		);

		foreach ( $forums as $forum ) {
			if ( ! $this->dry_run ) {
				$space_id = Space::create(
					[
						'category_id' => $cat_id,
						'author_id'   => (int) $forum->post_author ?: 1,
						'type'        => 'forum',
						'title'       => $forum->post_title,
						'slug'        => $forum->post_name ?: sanitize_title( $forum->post_title ),
						'description' => wp_strip_all_tags( $forum->post_content ),
						'visibility'  => 'public',
						'join_policy' => 'open',
					]
				);
			} else {
				$space_id = 0; // Simulate
			}

			if ( $space_id || $this->dry_run ) {
				$this->map_id( 'forum', $forum->ID, $space_id );
				++$this->imported;
			} else {
				$this->log_error( 'forum', $forum->ID, 'Failed to create space' );
				++$this->skipped;
			}
		}
	}

	private function import_topics(): void {
		global $wpdb;

		$topics = $wpdb->get_results(
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status = 'publish' ORDER BY ID ASC"
		);

		foreach ( $topics as $topic ) {
			$forum_id = (int) $topic->post_parent;
			$space_id = $this->get_mapped_id( 'forum', $forum_id );

			if ( ! $space_id ) {
				$this->log_error( 'topic', $topic->ID, "Parent forum {$forum_id} not imported" );
				++$this->skipped;
				continue;
			}

			$is_sticky = (int) get_post_meta( $topic->ID, '_bbp_topic_sticky', true );

			if ( ! $this->dry_run ) {
				$post_id = JtPost::create(
					[
						'space_id'      => $space_id,
						'author_id'     => (int) $topic->post_author,
						'type'          => 'topic',
						'title'         => $topic->post_title,
						'slug'          => $topic->post_name ?: sanitize_title( $topic->post_title ),
						'content'       => wp_kses_post( $topic->post_content ),
						'content_plain' => wp_strip_all_tags( $topic->post_content ),
						'status'        => 'publish',
						'is_sticky'     => $is_sticky ? 1 : 0,
						'created_at'    => $topic->post_date_gmt ?: now(),
					]
				);

				if ( is_wp_error( $post_id ) ) {
					$this->log_error( 'topic', $topic->ID, $post_id->get_error_message() );
					++$this->skipped;
					continue;
				}
			} else {
				$post_id = 0; // Simulate
			}

			if ( $post_id || $this->dry_run ) {
				$this->map_id( 'topic', $topic->ID, $post_id );
				if ( ! $this->dry_run ) {
					$this->migrate_bbpress_attachments( 'post', (int) $topic->ID, (int) $post_id );
				}
				++$this->imported;
			} else {
				$this->log_error( 'topic', $topic->ID, 'Failed to create post' );
				++$this->skipped;
			}
		}
	}

	private function import_replies(): void {
		global $wpdb;

		$replies = $wpdb->get_results(
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status = 'publish' ORDER BY ID ASC"
		);

		foreach ( $replies as $reply ) {
			// bbPress reply's post_parent is the topic ID
			$topic_id = (int) $reply->post_parent;
			$post_id  = $this->get_mapped_id( 'topic', $topic_id );

			if ( ! $post_id ) {
				// Try grandparent (nested reply)
				++$this->skipped;
				continue;
			}

			if ( ! $this->dry_run ) {
				$reply_id = JtReply::create(
					[
						'post_id'       => $post_id,
						'author_id'     => (int) $reply->post_author,
						'content'       => wp_kses_post( $reply->post_content ),
						'content_plain' => wp_strip_all_tags( $reply->post_content ),
						'status'        => 'publish',
						'created_at'    => $reply->post_date_gmt ?: now(),
					]
				);

				if ( is_wp_error( $reply_id ) ) {
					++$this->skipped;
					continue;
				}
			} else {
				$reply_id = 0; // Simulate
			}

			if ( $reply_id || $this->dry_run ) {
				$this->map_id( 'reply', $reply->ID, $reply_id );
				if ( ! $this->dry_run ) {
					$this->migrate_bbpress_attachments( 'reply', (int) $reply->ID, (int) $reply_id );
				}
				++$this->imported;
			} else {
				++$this->skipped;
			}
		}
	}

	private function create_profiles(): void {
		global $wpdb;

		$author_ids = $wpdb->get_col(
			"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ('topic', 'reply') AND post_status = 'publish' AND post_author > 0"
		);

		foreach ( $author_ids as $uid ) {
			$this->ensure_profile( (int) $uid );
		}
	}

	private function recount(): void {
		global $wpdb;
		$posts_table   = \Jetonomy\table( 'posts' );
		$replies_table = \Jetonomy\table( 'replies' );
		$spaces_table  = \Jetonomy\table( 'spaces' );

		// Recount reply counts on posts
		$wpdb->query( "UPDATE {$posts_table} p SET p.reply_count = (SELECT COUNT(*) FROM {$replies_table} r WHERE r.post_id = p.id AND r.status = 'publish')" );

		// Recount post counts on spaces
		$wpdb->query( "UPDATE {$spaces_table} s SET s.post_count = (SELECT COUNT(*) FROM {$posts_table} p WHERE p.space_id = s.id AND p.status = 'publish')" );

		// Update last_reply_at on posts
		$wpdb->query( "UPDATE {$posts_table} p SET p.last_reply_at = (SELECT MAX(r.created_at) FROM {$replies_table} r WHERE r.post_id = p.id AND r.status = 'publish')" );
	}
}
