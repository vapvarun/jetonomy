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

	/**
	 * bbPress guards every create with $dry_run, so a dry run here is real.
	 */
	public function supports_dry_run(): bool {
		return true;
	}

	public function is_source_available(): bool {
		global $wpdb;
		// Check if bbPress post types exist
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('forum', 'topic', 'reply')"
		);
		return $count > 0;
	}

	/**
	 * The bbPress post_statuses we import, per post type.
	 *
	 * Every query in this importer used to hard-filter `post_status =
	 * 'publish'`, which silently dropped three things a real board is full of:
	 * a **closed** topic (bbPress marks it `closed`, not `publish`), and
	 * **private** and **hidden** forums. Two customers migrated with content
	 * missing and no way to know.
	 *
	 * The counts below use the same sets, which is the other half of the bug:
	 * the pre-import estimate applied the identical filter, so it agreed with
	 * the import exactly and the shortfall was invisible from both ends.
	 *
	 * THE single source of truth for "what we take" - the counts, the batch
	 * queries and the full-run queries all read it, so they cannot drift apart
	 * again.
	 *
	 * @param string $type One of forum, topic, reply.
	 * @return string[] bbPress post_status values to import.
	 */
	private function source_statuses( string $type ): array {
		$map = [
			// `private`/`hidden` carry real meaning and now have somewhere to
			// land: Jetonomy space visibility. See status_to_visibility().
			'forum' => [ 'publish', 'private', 'hidden' ],
			// A closed topic is still a topic - it imports and lands closed.
			'topic' => [ 'publish', 'closed' ],
			'reply' => [ 'publish' ],
		];

		/**
		 * Filter the bbPress post_statuses imported for a given type.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $statuses bbPress post_status values.
		 * @param string   $type     forum|topic|reply.
		 */
		return (array) apply_filters( 'jetonomy_bbpress_import_statuses', $map[ $type ] ?? [ 'publish' ], $type );
	}

	/**
	 * SQL `IN (...)` fragment of the statuses we import for a type.
	 *
	 * @param string $type One of forum, topic, reply.
	 * @return string Quoted, comma-separated list safe for interpolation.
	 */
	private function status_sql( string $type ): string {
		$statuses = array_map( 'sanitize_key', $this->source_statuses( $type ) );

		return "'" . implode( "','", $statuses ) . "'";
	}

	/**
	 * Map a bbPress forum post_status onto Jetonomy space visibility.
	 *
	 * Imported forums used to be created `public` unconditionally, which would
	 * turn a staff-only or paid-tier forum into an open one the moment it was
	 * migrated - a worse outcome than skipping it. Now that categories and
	 * spaces actually enforce visibility, these values mean what they say.
	 *
	 * @param string $status bbPress post_status.
	 * @return string One of public|private|hidden.
	 */
	private function status_to_visibility( string $status ): string {
		$map = [
			'private' => 'private',
			'hidden'  => 'hidden',
		];

		return $map[ $status ] ?? 'public';
	}

	public function get_source_stats(): array {
		global $wpdb;
		return [
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- status_sql() emits sanitize_key()'d literals.
			'forums'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status IN (" . $this->status_sql( 'forum' ) . ')' ),
			'topics'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status IN (" . $this->status_sql( 'topic' ) . ')' ),
			'replies' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status IN (" . $this->status_sql( 'reply' ) . ')' ),
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		];
	}

	public function get_total_count(): int {
		return array_sum( $this->get_source_stats() );
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
	 * that is missing is the row that makes Jetonomy render it.
	 *
	 * That link is FREE as of 1.7.1 — this comment used to say it was Pro, and that
	 * "enabling Pro later reveals them". Both halves are now false, and the second
	 * was false in a way that mattered: it promised a reveal that no code performed,
	 * because link rows were only ever written during import (Basecamp 10093054077).
	 * Attachments moved to free (Attachments::register() is in the unconditional
	 * bootstrap; the importer links via the free Attachment model regardless of
	 * whether Pro is active), so the rows are written here on every site and free
	 * renders them immediately. Turning Pro off costs previews and the PDF viewer,
	 * not the attachments.
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

	/**
	 * Create the space for one bbPress forum row, resolving its parent.
	 *
	 * Shared by BOTH import paths (run_batch() and run()) on purpose. The parent
	 * mapping below was missing from each of them independently; giving them one
	 * body means the next change to forum->space mapping cannot land on one path
	 * and miss the other.
	 *
	 * Callers must have created the parent forum already — see
	 * sort_rows_parents_first(). A parent that still doesn't resolve (orphan row)
	 * falls back to top level rather than failing the import.
	 *
	 * @param object $forum  bbPress forum post row.
	 * @param int    $cat_id Import category id.
	 * @return int Space id, or 0 on failure.
	 */
	/**
	 * The space a previous import already created for this forum, if any.
	 *
	 * Re-running an import used to look idempotent for the wrong reason: the
	 * second run tried to create each space again, Space::create() failed on
	 * the duplicate slug, and the forum was recorded as "Failed to create
	 * space". Nothing was duplicated - but the forum never entered the id_map
	 * either, so every topic beneath it was then skipped with "Parent forum N
	 * not imported".
	 *
	 * That is why an owner who migrated before the status fix could not simply
	 * re-run to recover their closed topics: the re-run could never reach them.
	 * Adopting the existing space instead puts the forum back in the map, so
	 * the topics under it resolve their parent and the ones that were missed
	 * the first time are imported now.
	 *
	 * Matched on slug, which is what bbPress's post_name produced on the first
	 * run and what Space::create() collides on.
	 *
	 * @param object $forum bbPress forum row.
	 * @return int Existing space id, or 0 when this forum has not been imported.
	 */
	private function find_existing_space( object $forum ): int {
		$slug = $forum->post_name ?: sanitize_title( $forum->post_title );
		if ( '' === $slug ) {
			return 0;
		}

		$existing = Space::find_by_slug( $slug );

		return $existing ? (int) $existing->id : 0;
	}

	/**
	 * The post a previous import already created for this topic, if any.
	 *
	 * Same purpose as find_existing_space(): once forums are adopted on a
	 * re-run, topics beneath them resolve their parent and would otherwise be
	 * created a second time. Matched on slug, which is what bbPress's
	 * post_name produced on the first run.
	 *
	 * @param object $topic bbPress topic row.
	 * @return int Existing post id, or 0.
	 */
	private function find_existing_post( object $topic ): int {
		$slug = $topic->post_name ?: sanitize_title( $topic->post_title );
		if ( '' === $slug ) {
			return 0;
		}

		$existing = JtPost::find_by_slug( $slug );

		return $existing ? (int) $existing->id : 0;
	}

	/**
	 * Has this bbPress reply already been imported onto this post?
	 *
	 * Replies carry no slug, so there is no natural key to collide on and a
	 * re-run duplicated every one of them. Matched on the triple that IS
	 * stable across runs: the destination post, the author, and the source's
	 * own timestamp, which the importer carries into created_at verbatim.
	 *
	 * @param int    $post_id    Destination Jetonomy post id.
	 * @param int    $author_id  Author.
	 * @param string $created_at Source post_date_gmt carried onto the reply.
	 * @return bool
	 */
	private function reply_already_imported( int $post_id, int $author_id, string $created_at ): bool {
		global $wpdb;

		$table = \Jetonomy\table( 'replies' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table() is a trusted prefixed name.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE post_id = %d AND author_id = %d AND created_at = %s LIMIT 1",
				$post_id,
				$author_id,
				$created_at
			)
		);
	}

	private function create_space_from_forum( object $forum, int $cat_id ): int {
		// bbPress nests forums through the ordinary WP post_parent column. Both
		// paths used to ignore it, so every sub-forum was created as a top-level
		// space and the customer's whole board structure was flattened on import.
		$parent_space_id = 0;
		if ( (int) $forum->post_parent > 0 ) {
			$mapped = $this->get_mapped_id( 'forum', (int) $forum->post_parent );
			if ( $mapped ) {
				$parent_space_id = $mapped;
			}
		}

		return (int) Space::create(
			[
				'category_id' => $cat_id,
				'parent_id'   => $parent_space_id,
				'author_id'   => (int) $forum->post_author ?: 1,
				'type'        => 'forum',
				'title'       => $forum->post_title,
				'slug'        => $forum->post_name ?: sanitize_title( $forum->post_title ),
				'description' => wp_strip_all_tags( $forum->post_content ),
				// Carry the source forum's privacy across. Creating every
				// imported forum `public` would expose a staff-only or paid-tier
				// board the moment it migrated - worse than the skip it replaces.
				'visibility'  => $this->status_to_visibility( (string) $forum->post_status ),
				'join_policy' => 'open',
			]
		);
	}

	public function run_batch( string $phase, int $offset, int $batch_size ): array {
		global $wpdb;

		switch ( $phase ) {
			case 'forums':
				// Forums must be created parents-first so a sub-forum can resolve its
				// parent's new space id, and that ordering has to hold ACROSS batches —
				// which SQL paging cannot express. So the whole set is ordered once and
				// then sliced. Forums are a small set (tens; wpForo and Asgaros load
				// theirs whole for the same reason), unlike the topic and reply phases
				// below, which stay paged in SQL because they run to thousands.
				$all_forums = $wpdb->get_results(
					"SELECT * FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status IN (" . $this->status_sql( 'forum' ) . ') ORDER BY menu_order ASC, ID ASC'
				);
				$all_forums = $this->sort_rows_parents_first( (array) $all_forums, 'ID', 'post_parent' );
				$forums     = array_slice( $all_forums, $offset, $batch_size );

				if ( empty( $forums ) ) {
					return [
						'phase'     => 'topics',
						'offset'    => 0,
						'done'      => false,
						'processed' => 0,
					];
				}

				// Carry forward what earlier batches mapped. Every batch runs in its own
				// request with a fresh instance, so id_map starts empty; without this the
				// update_option() below replaced the entire map with only THIS batch's
				// forums. Two consequences, both silent: a child forum could never see a
				// parent created in an earlier batch, and every topic under an earlier
				// batch's forum was skipped as "parent not imported". Verified: 5 forums
				// at batch_size 2 persisted only the last batch's mapping and imported 0
				// of 1 topics.
				$this->id_map = get_option( 'jetonomy_import_id_map', [] );

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
					// Already imported by an earlier run? Adopt it, so topics
					// beneath it can resolve their parent and anything missed
					// last time still gets imported. Counted as skipped, not
					// imported - nothing new was created.
					$existing = $this->find_existing_space( $forum );
					if ( $existing ) {
						$this->map_id( 'forum', $forum->ID, $existing );
						++$this->skipped;
						continue;
					}

					$space_id = $this->create_space_from_forum( $forum, $cat_id );
					if ( $space_id ) {
						$this->map_id( 'forum', $forum->ID, $space_id );
						++$this->imported;
					}
				}

				update_option( 'jetonomy_import_id_map', $this->id_map, false );

				$has_more = count( $all_forums ) > $offset + $batch_size;
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
						"SELECT * FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status IN (" . $this->status_sql( 'topic' ) . ') ORDER BY ID ASC LIMIT %d OFFSET %d',
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

					// Adopt a topic an earlier run already imported: map it so its
					// replies resolve, and do not create it twice.
					$already = $this->find_existing_post( $topic );
					if ( $already ) {
						$this->map_id( 'topic', $topic->ID, $already );
						++$this->skipped;
						continue;
					}

					$is_sticky = (int) get_post_meta( $topic->ID, '_bbp_topic_sticky', true );

					$post_id = JtPost::create(
						[
							'space_id'      => $space_id,
							'author_id'     => (int) $topic->post_author,
							'type'          => \Jetonomy\compose_post_type( 'forum' ),
							'title'         => $topic->post_title,
							'slug'          => $topic->post_name ?: sanitize_title( $topic->post_title ),
							'content'       => wp_kses_post( $topic->post_content ),
							'content_plain' => \jetonomy_content_to_plain( $topic->post_content ),
							'status'        => 'publish',
							'is_sticky'     => $is_sticky ? 1 : 0,
							// bbPress stores "closed" as the post_status; Jetonomy
							// keeps the topic published and flags it closed, so the
							// thread stays readable but takes no new replies.
							'is_closed'     => 'closed' === $topic->post_status ? 1 : 0,
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
						"SELECT * FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status IN (" . $this->status_sql( 'reply' ) . ') ORDER BY ID ASC LIMIT %d OFFSET %d',
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

					$reply_created_at = $reply->post_date_gmt ?: now();
					if ( $this->reply_already_imported( (int) $post_id, (int) $reply->post_author, (string) $reply_created_at ) ) {
						++$this->skipped;
						continue;
					}

					$reply_id = JtReply::create(
						[
							'post_id'       => $post_id,
							'author_id'     => (int) $reply->post_author,
							'content'       => wp_kses_post( $reply->post_content ),
							'content_plain' => \jetonomy_content_to_plain( $reply->post_content ),
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
					"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ('topic', 'reply') AND post_status IN (" . $this->status_sql( 'topic' ) . ',' . $this->status_sql( 'reply' ) . ') AND post_author > 0'
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
			$cat_id = self::DRY_RUN_ID; // Simulate
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
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status IN (" . $this->status_sql( 'forum' ) . ') ORDER BY menu_order ASC, ID ASC'
		);

		// Parents before children, so each sub-forum can resolve its parent's new
		// space id below. Same reason as the batched path.
		$forums = $this->sort_rows_parents_first( (array) $forums, 'ID', 'post_parent' );

		foreach ( $forums as $forum ) {
			// See the batched path: adopt a forum an earlier run already
			// created rather than failing on its duplicate slug, or every topic
			// beneath it is skipped as "parent not imported" and a re-run can
			// never recover what the first run missed.
			$existing = $this->dry_run ? 0 : $this->find_existing_space( $forum );
			if ( $existing ) {
				$this->map_id( 'forum', $forum->ID, $existing );
				++$this->skipped;
				continue;
			}

			if ( ! $this->dry_run ) {
				$space_id = $this->create_space_from_forum( $forum, $cat_id );
			} else {
				$space_id = self::DRY_RUN_ID; // Simulate
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
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status IN (" . $this->status_sql( 'topic' ) . ') ORDER BY ID ASC'
		);

		foreach ( $topics as $topic ) {
			$forum_id = (int) $topic->post_parent;
			$space_id = $this->get_mapped_id( 'forum', $forum_id );

			if ( ! $space_id ) {
				$this->log_error( 'topic', $topic->ID, "Parent forum {$forum_id} not imported" );
				++$this->skipped;
				continue;
			}

			// Adopt a topic an earlier run already imported: map it so its
			// replies resolve, and do not create it twice.
			$already = $this->find_existing_post( $topic );
			if ( $already ) {
				$this->map_id( 'topic', $topic->ID, $already );
				++$this->skipped;
				continue;
			}

			$is_sticky = (int) get_post_meta( $topic->ID, '_bbp_topic_sticky', true );

			if ( ! $this->dry_run ) {
				$post_id = JtPost::create(
					[
						'space_id'      => $space_id,
						'author_id'     => (int) $topic->post_author,
						'type'          => \Jetonomy\compose_post_type( 'forum' ),
						'title'         => $topic->post_title,
						'slug'          => $topic->post_name ?: sanitize_title( $topic->post_title ),
						'content'       => wp_kses_post( $topic->post_content ),
						'content_plain' => \jetonomy_content_to_plain( $topic->post_content ),
						'status'        => 'publish',
						'is_sticky'     => $is_sticky ? 1 : 0,
						// bbPress stores "closed" as the post_status; Jetonomy keeps
						// the topic published and flags it closed, so the thread stays
						// readable but takes no new replies.
						'is_closed'     => 'closed' === $topic->post_status ? 1 : 0,
						'created_at'    => $topic->post_date_gmt ?: now(),
					]
				);

				if ( is_wp_error( $post_id ) ) {
					$this->log_error( 'topic', $topic->ID, $post_id->get_error_message() );
					++$this->skipped;
					continue;
				}
			} else {
				$post_id = self::DRY_RUN_ID; // Simulate
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
			"SELECT * FROM {$wpdb->posts} WHERE post_type = 'reply' AND post_status IN (" . $this->status_sql( 'reply' ) . ') ORDER BY ID ASC'
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
				$reply_created_at = $reply->post_date_gmt ?: now();
				if ( $this->reply_already_imported( (int) $post_id, (int) $reply->post_author, (string) $reply_created_at ) ) {
					++$this->skipped;
					continue;
				}

				$reply_id = JtReply::create(
					[
						'post_id'       => $post_id,
						'author_id'     => (int) $reply->post_author,
						'content'       => wp_kses_post( $reply->post_content ),
						'content_plain' => \jetonomy_content_to_plain( $reply->post_content ),
						'status'        => 'publish',
						'created_at'    => $reply->post_date_gmt ?: now(),
					]
				);

				if ( is_wp_error( $reply_id ) ) {
					++$this->skipped;
					continue;
				}
			} else {
				$reply_id = self::DRY_RUN_ID; // Simulate
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
			"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type IN ('topic', 'reply') AND post_status IN (" . $this->status_sql( 'topic' ) . ',' . $this->status_sql( 'reply' ) . ') AND post_author > 0'
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

		// spaces.post_count above backs space:{id}; a set-based UPDATE names no ids
		// (Caching Standard §4d). This is a one-shot import, so flush the group.
		\Jetonomy\Cache::flush();
	}
}
