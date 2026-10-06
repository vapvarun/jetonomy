<?php
/**
 * bbPress importer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Import_Map;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post as JtPost;
use Jetonomy\Models\Reply as JtReply;
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
	 * bbPress itself loaded? The import works either way - see Importer::is_source_active().
	 */
	public function is_source_active(): bool {
		return class_exists( 'bbPress', false );
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
	 * Map a bbPress forum's post_status onto Jetonomy visibility + join policy.
	 *
	 * The two systems do not mean the same thing by "private", and getting this
	 * wrong costs members their access on migration day:
	 *
	 *  - bbPress `private`: any LOGGED-IN member can read.
	 *  - Jetonomy `private`: only MEMBERS of the space can read
	 *    (Space::content_visibility_sql - public spaces, plus spaces you belong
	 *    to).
	 *
	 * So a private forum maps to a private space AND its participants are
	 * imported as members (see grant_members()), or everyone who could
	 * read it yesterday cannot read it today. `approval` is the join policy for
	 * newcomers, since the source forum was not open to all.
	 *
	 * Hidden maps to `invite`, because Space::validate_visibility_join_policy()
	 * rejects any other combination - hidden + open was silently invalid.
	 *
	 * Routed through the SAME `jetonomy_import_space_visibility` filter the
	 * wpForo and Asgaros importers use, rather than a parallel mapping, so an
	 * owner has one place to override this for any source.
	 *
	 * @param object $forum bbPress forum row.
	 * @return array{visibility:string,join_policy:string}
	 */
	private function map_access( object $forum ): array {
		$status = (string) ( $forum->post_status ?? 'publish' );

		$defaults = array(
			'private' => array(
				'visibility'  => 'private',
				'join_policy' => 'approval',
			),
			'hidden'  => array(
				'visibility'  => 'hidden',
				'join_policy' => 'invite',
			),
		);

		$access = $defaults[ $status ] ?? array(
			'visibility'  => 'public',
			'join_policy' => 'open',
		);

		/** This filter is documented in includes/import/class-wpforo-importer.php */
		$access = (array) apply_filters( 'jetonomy_import_space_visibility', $access, 'bbpress', $forum );

		$visibility = in_array( $access['visibility'] ?? '', array( 'public', 'private', 'hidden' ), true )
			? $access['visibility']
			: 'public';
		$join       = in_array( $access['join_policy'] ?? '', array( 'open', 'approval', 'invite' ), true )
			? $access['join_policy']
			: 'open';

		// A filter cannot be allowed to persist a combination the model
		// rejects; hidden spaces must be invite-only.
		if ( is_wp_error( Space::validate_visibility_join_policy( $visibility, $join ) ) ) {
			$join = 'invite';
		}

		return array(
			'visibility'  => $visibility,
			'join_policy' => $join,
		);
	}

	/** Option holding this run's queued member grants between batches. */
	private const GRANTS_OPTION = 'jetonomy_import_bbpress_grants';

	/**
	 * People who must keep access to a space this request created or linked.
	 *
	 * Each entry is `[ 'space' => id, 'forum' => bbPress forum id ]` (the
	 * forum's participants) or `[ 'space' => id, 'group' => BP group id ]`
	 * (the group's members). See grant_members().
	 *
	 * @var array<int,array<string,int>>
	 */
	private array $grants = [];

	/**
	 * Grant one page of queued memberships.
	 *
	 * Two kinds of people keep access to what they could read yesterday:
	 *
	 *  - A private or hidden forum's participants - anyone who authored a
	 *    topic or reply in it. A Jetonomy private/hidden space is readable only
	 *    by its members (bbPress `private` meant any logged-in user), so without
	 *    this they lose the content on migration day.
	 *  - A BuddyPress group forum's confirmed, non-banned group members, with
	 *    the integration's role map (admin -> admin, mod -> moderator). Members
	 *    who never posted are not participants, so the group is the only list.
	 *
	 * Both used to be added inline, one SpaceMember::add() per person, inside
	 * the forums batch: fine at 13 members, a timeout at 10,000. They are now
	 * one ordered query over every queued grant, paged by the batch driver
	 * like any other phase. add() stays the writer so join hooks fire and an
	 * existing role is never lowered; deleted users are dropped in SQL.
	 *
	 * @param array $grants Queued grants.
	 * @param int   $offset Row offset across all grants.
	 * @param int   $limit  Page size.
	 * @return array{fetched:int, processed:int} processed < fetched means the time budget stopped the page early.
	 */
	private function grant_members( array $grants, int $offset, int $limit ): array {
		global $wpdb;

		$parts = [];
		foreach ( array_values( $grants ) as $job => $grant ) {
			if ( ! empty( $grant['group'] ) ) {
				if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'groups' ) ) {
					continue;
				}
				$bp_table = buddypress()->groups->table_name_members;
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- BP's own table name.
				$parts[] = $wpdb->prepare( "SELECT %d AS job, %d AS space_id, user_id, CASE WHEN is_admin = 1 THEN 'admin' WHEN is_mod = 1 THEN 'moderator' ELSE 'member' END AS role FROM {$bp_table} WHERE group_id = %d AND is_confirmed = 1 AND is_banned = 0", $job, (int) $grant['space'], (int) $grant['group'] );
			} elseif ( ! empty( $grant['forum'] ) ) {
				$parts[] = $wpdb->prepare(
					"SELECT %d AS job, %d AS space_id, a.user_id, 'member' AS role FROM (
					   SELECT t.post_author AS user_id FROM {$wpdb->posts} t
					    WHERE t.post_type = 'topic' AND t.post_parent = %d AND t.post_author > 0
					   UNION
					   SELECT r.post_author FROM {$wpdb->posts} r
					    INNER JOIN {$wpdb->posts} rt ON rt.ID = r.post_parent AND rt.post_type = 'topic'
					    WHERE r.post_type = 'reply' AND rt.post_parent = %d AND r.post_author > 0
					 ) a",
					$job,
					(int) $grant['space'],
					(int) $grant['forum'],
					(int) $grant['forum']
				);
			}
		}

		if ( ! $parts ) {
			return [
				'fetched'   => 0,
				'processed' => 0,
			];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- every part is prepared above; limit/offset are absint.
		$rows = (array) $wpdb->get_results(
			'SELECT g.space_id, g.user_id, g.role FROM ( ' . implode( ' UNION ALL ', $parts ) . " ) g
			 INNER JOIN {$wpdb->users} u ON u.ID = g.user_id
			 ORDER BY g.job ASC, g.user_id ASC LIMIT " . absint( $limit ) . ' OFFSET ' . absint( $offset )
		);

		$processed = 0;
		foreach ( $rows as $row ) {
			SpaceMember::add( (int) $row->space_id, (int) $row->user_id, (string) $row->role );
			++$processed;
			if ( $processed < count( $rows ) && $this->budget_spent() ) {
				break;
			}
		}

		return [
			'fetched'   => count( $rows ),
			'processed' => $processed,
		];
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

	/**
	 * What this import will actually do, in the owner's language.
	 *
	 * See Importer::get_import_notes(). Everything here is a consequence the
	 * owner cannot see from the raw counts: which statuses are now included,
	 * that private forums bring their members across, and whether this source
	 * has been imported before.
	 *
	 * @return string[]
	 */
	public function get_import_notes(): array {
		global $wpdb;

		$notes = array();

		$closed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status = 'closed'" );
		if ( $closed > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: number of closed topics. */
				_n(
					'%s closed topic will be imported and will stay closed.',
					'%s closed topics will be imported and will stay closed.',
					$closed,
					'jetonomy'
				),
				number_format_i18n( $closed )
			);
		}

		$private = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status = 'private'" );
		$hidden  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status = 'hidden'" );
		if ( $private > 0 || $hidden > 0 ) {
			$notes[] = sprintf(
				/* translators: 1: number of private forums, 2: number of hidden forums. */
				__( '%1$s private and %2$s hidden forums will keep their privacy. Everyone who posted in them is added as a member so they keep access; new members need approving.', 'jetonomy' ),
				number_format_i18n( $private ),
				number_format_i18n( $hidden )
			);
		}

		// Not the ones an earlier release already imported as spaces: a re-run
		// adopts those as they are.
		$containers = $this->container_forums( $this->forum_rows() );
		$containers = count( array_diff_key( $containers, Import_Map::find_many( self::SOURCE, 'space', array_keys( $containers ) ) ) );
		if ( $containers > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: number of bbPress forums that only contain other forums. */
				_n(
					'%s forum that only groups other forums (a bbPress category or the group forums root) becomes a category, not an empty space.',
					'%s forums that only group other forums (bbPress categories or the group forums root) become categories, not empty spaces.',
					$containers,
					'jetonomy'
				),
				number_format_i18n( $containers )
			);
		}

		$already = Import_Map::count_for_source( self::SOURCE );
		if ( $already > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: number of previously imported items. */
				__( 'This source has been imported before (%s items). Running it again adds only what is missing and does not duplicate anything.', 'jetonomy' ),
				number_format_i18n( $already )
			);
		}

		return $notes;
	}

	/**
	 * Source rows plus the profiles phase's authors, so progress ends at 100%
	 * instead of running past it once profiles are counted.
	 */
	public function get_total_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- authors_where() emits sanitize_key()'d literals.
		$authors = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE " . $this->authors_where() );

		return array_sum( $this->get_source_stats() ) + $authors;
	}

	/**
	 * WHERE clause for the authors the profiles phase creates profiles for.
	 *
	 * @return string
	 */
	private function authors_where(): string {
		return "post_type IN ('topic', 'reply') AND post_status IN (" . $this->status_sql( 'topic' ) . ',' . $this->status_sql( 'reply' ) . ') AND post_author > 0';
	}

	/**
	 * Distinct author ids, paged when $limit > 0.
	 *
	 * @param int $limit  Page size; 0 for all.
	 * @param int $offset Row offset.
	 * @return int[]
	 */
	private function author_ids( int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$sql = "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE " . $this->authors_where() . ' ORDER BY post_author ASC';
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . absint( $limit ) . ' OFFSET ' . absint( $offset );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- literals + absint only.
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * All importable forums, parents before children.
	 *
	 * Forums are a small set (tens), unlike topics and replies, and a child can
	 * only resolve its parent once the parent exists, so the whole set is
	 * ordered once and the batch path slices it.
	 *
	 * @return object[]
	 */
	private function forum_rows(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- status_sql() emits sanitize_key()'d literals.
		$forums = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_type = 'forum' AND post_status IN (" . $this->status_sql( 'forum' ) . ') ORDER BY menu_order ASC, ID ASC' );

		return $this->sort_rows_parents_first( (array) $forums, 'ID', 'post_parent' );
	}

	/**
	 * Importable topic or reply rows in id order, paged when $limit > 0.
	 *
	 * @param string $type   'topic' or 'reply'.
	 * @param int    $limit  Page size; 0 for all.
	 * @param int    $offset Row offset.
	 * @return object[]
	 */
	private function content_rows( string $type, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$sql = "SELECT * FROM {$wpdb->posts} WHERE post_type = '" . sanitize_key( $type ) . "' AND post_status IN (" . $this->status_sql( $type ) . ') ORDER BY ID ASC';
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . absint( $limit ) . ' OFFSET ' . absint( $offset );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- sanitize_key()'d literals + absint only.
		return (array) $wpdb->get_results( $sql );
	}

	/**
	 * Forums that only group other forums, keyed for isset().
	 *
	 * A bbPress category (`_bbp_forum_type` = category) and the BuddyPress
	 * "Group Forums" root hold forums, not topics. Imported as spaces they
	 * became empty spaces a member could post into; a Jetonomy category is
	 * the same idea - a heading that groups spaces - so they become one.
	 *
	 * Only when the forum really holds no importable topic (a forum switched to
	 * a category keeps its old topics) and sits at the top level or inside
	 * another container: a category nests under a category, never inside a
	 * space. Anything else stays a space, as before.
	 *
	 * @param object[] $forums Forum rows, parents first (forum_rows()).
	 * @return array<int,true>
	 */
	private function container_forums( array $forums ): array {
		global $wpdb;

		$ids = array_map( 'intval', array_column( $forums, 'ID' ) );
		if ( ! $ids ) {
			return [];
		}
		$in = implode( ',', $ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int list and sanitize_key()'d literals.
		$categories = array_flip( array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bbp_forum_type' AND meta_value = 'category' AND post_id IN ({$in})" ) ) );
		$has_topics = array_flip( array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'topic' AND post_status IN (" . $this->status_sql( 'topic' ) . ") AND post_parent IN ({$in})" ) ) );
		// phpcs:enable
		$group_root = (int) get_option( '_bbp_group_forums_root_id', 0 );

		$containers = [];
		foreach ( $forums as $forum ) {
			$id     = (int) $forum->ID;
			$parent = (int) $forum->post_parent;
			if ( ( isset( $categories[ $id ] ) || $id === $group_root )
				&& ! isset( $has_topics[ $id ] )
				&& ( 0 === $parent || isset( $containers[ $parent ] ) ) ) {
				$containers[ $id ] = true;
			}
		}

		return $containers;
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
	 * Also drop the category-id option releases before 2.0.1 kept between
	 * batches (the category is now resolved through jt_import_map), so an
	 * import aborted on an older version leaves nothing behind, and an aborted
	 * run's member-grant queue. parent handles the shared run state.
	 */
	public function reset_run_state(): void {
		parent::reset_run_state();
		delete_option( 'jetonomy_import_bbpress_cat_id' );
		delete_option( self::GRANTS_OPTION );
	}

	/**
	 * Everything that must happen once a forum's space exists.
	 *
	 * Recording the mapping is what makes a later re-run able to recognise its
	 * own work instead of guessing by slug. Queuing the participants is what
	 * stops a private forum's members losing access on migration day.
	 *
	 * @param object $forum    Source forum row.
	 * @param int    $space_id The space just created.
	 * @return void
	 */
	private function after_space_created( object $forum, int $space_id ): void {
		$this->remember( 'space', (int) $forum->ID, $space_id );

		if ( 'public' !== $this->map_access( $forum )['visibility'] ) {
			$this->grants[] = [
				'space' => $space_id,
				'forum' => (int) $forum->ID,
			];
		}

		$this->link_buddypress_group( $space_id, (int) $forum->ID );
	}

	/**
	 * Carry a BuddyPress group forum's group across: link it, queue its members.
	 *
	 * BP-bbPress ties a group to its forum through the forum's `_bbp_group_ids`
	 * meta. Without this the imported space was an orphan: the group's Forum
	 * tab did not show it, later joins/leaves did not sync (Jetonomy's
	 * BuddyPress integration keys both on the group -> space link), and a
	 * private or hidden group's members who had never posted lost access.
	 *
	 * Also runs for a forum an earlier run imported, since no release before
	 * 2.0.1 linked groups. A group already linked to any space (by the owner,
	 * or by a previous run) is left alone - including one the owner unlinked
	 * from the space on purpose and whose forum is then imported again: a
	 * re-import is an explicit request, so it links the group again. The
	 * members are granted by the paged members phase, see grant_members().
	 *
	 * @param int $space_id Imported space.
	 * @param int $forum_id Source bbPress forum ID.
	 * @return void
	 */
	private function link_buddypress_group( int $space_id, int $forum_id ): void {
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'groups' ) ) {
			return;
		}

		foreach ( array_map( 'intval', (array) get_post_meta( $forum_id, '_bbp_group_ids', true ) ) as $group_id ) {
			if ( $group_id <= 0 || groups_get_groupmeta( $group_id, \Jetonomy\Integrations\BuddyPress::META_KEY, true ) ) {
				continue;
			}
			\Jetonomy\Integrations\BuddyPress::link_group_to_space( $group_id, $space_id );
			$this->grants[] = [
				'space' => $space_id,
				'group' => $group_id,
			];
		}
	}

	/** Importer slug used as the `source` in jt_import_map. */
	private const SOURCE = 'bbpress';

	protected function map_source(): string {
		return self::SOURCE;
	}

	/**
	 * Which of these forums an earlier run already turned into spaces.
	 *
	 * Identity comes from the source id in jt_import_map, never from the slug
	 * alone: a bbPress forum called "general" must not adopt the owner's own
	 * "general" space. The slug is passed only as the legacy fingerprint for
	 * imports made before the map existed (see Import_Map::match_legacy()),
	 * which also requires the space to sit in an importer-created category.
	 *
	 * @param object[] $forums bbPress forum rows.
	 * @return array<int,int> forum ID => space id.
	 */
	private function find_imported_forums( array $forums ): array {
		$rows = [];
		foreach ( $forums as $forum ) {
			$rows[ (int) $forum->ID ] = [ 'slug' => $forum->post_name ?: sanitize_title( $forum->post_title ) ];
		}

		return $this->find_imported( 'space', $rows );
	}

	/**
	 * Which of these topics/replies an earlier run already imported.
	 *
	 * Rows whose parent was not imported are left out: they are skipped by the
	 * caller anyway. The fingerprint (parent, author, date) is what 1.9.x wrote
	 * on the Jetonomy row - see Import_Map::match_legacy().
	 *
	 * @param string   $type        'post' (topics) or 'reply'.
	 * @param object[] $rows        bbPress topic or reply rows.
	 * @param string   $parent_type id_map type of the parent: 'forum' or 'topic'.
	 * @return array<int,int> source ID => Jetonomy id.
	 */
	private function find_imported_children( string $type, array $rows, string $parent_type ): array {
		$fingerprints = [];
		foreach ( $rows as $row ) {
			$parent = $this->get_mapped_id( $parent_type, (int) $row->post_parent );
			if ( $parent ) {
				$fingerprints[ (int) $row->ID ] = [
					'parent'  => $parent,
					'author'  => (int) $row->post_author,
					'created' => (string) $row->post_date_gmt,
				];
			}
		}

		return $this->find_imported( $type, $fingerprints );
	}

	/**
	 * bbPress topic ids that are stuck, keyed for isset().
	 *
	 * There is no per-topic sticky flag in bbPress: bbp_stick_topic() stores the ids
	 * in the forum's `_bbp_sticky_topics` meta and super stickies in the
	 * `_bbp_super_sticky_topics` option. Earlier releases read a
	 * `_bbp_topic_sticky` post meta bbPress never writes, so every sticky
	 * imported unstuck. Read raw, so it works with bbPress deactivated.
	 * Jetonomy has no site-wide sticky, so a super sticky lands sticky in its
	 * own space.
	 *
	 * @param object[] $topics bbPress topic rows.
	 * @return array<int,true>
	 */
	private function sticky_topic_ids( array $topics ): array {
		$ids = (array) get_option( '_bbp_super_sticky_topics', [] );
		foreach ( array_unique( array_map( 'intval', array_column( $topics, 'post_parent' ) ) ) as $forum_id ) {
			$ids = array_merge( $ids, (array) get_post_meta( $forum_id, '_bbp_sticky_topics', true ) );
		}

		return array_fill_keys( array_map( 'intval', array_filter( $ids ) ), true );
	}

	/**
	 * Jetonomy parent reply for each threaded bbPress reply in this batch.
	 *
	 * The reply being answered is stored in `_bbp_reply_to` post meta.
	 * Earlier releases ignored it, so every threaded discussion imported flat.
	 * One meta query per batch; a parent an earlier batch or run imported is
	 * loaded from jt_import_map (load_mapped()), one created earlier in this
	 * batch is already in memory. A parent that was never imported (pending,
	 * spam) leaves the reply top-level rather than dropping it.
	 *
	 * Call it per reply AFTER earlier replies in the batch were created, since
	 * bbPress replies answer older (lower-id) replies in the same topic.
	 *
	 * @param object[] $replies bbPress reply rows.
	 * @return callable(int): ?int Source reply id => Jetonomy parent reply id, null for top level (the column default).
	 */
	private function reply_parent_resolver( array $replies ): callable {
		global $wpdb;

		$reply_to = [];
		$ids      = array_map( 'intval', array_column( $replies, 'ID' ) );
		if ( $ids ) {
			$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder list.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_bbp_reply_to' AND post_id IN ({$in})", ...$ids ) );
			foreach ( (array) $rows as $row ) {
				if ( (int) $row->meta_value > 0 ) {
					$reply_to[ (int) $row->post_id ] = (int) $row->meta_value;
				}
			}
		}

		$parents = array_unique( $reply_to );
		$this->load_mapped( 'reply', 'reply', array_combine( $parents, $parents ) );

		return function ( int $source_id ) use ( $reply_to ): ?int {
			$mapped = isset( $reply_to[ $source_id ] ) ? (int) $this->get_mapped_id( 'reply', $reply_to[ $source_id ] ) : 0;

			return $mapped > 0 ? $mapped : null;
		};
	}

	/**
	 * The category imported bbPress spaces are filed under.
	 *
	 * @return int
	 */
	private function bbpress_category(): int {
		return $this->import_category(
			'default',
			'imported-bbpress',
			[
				'name'        => __( 'Imported from bbPress', 'jetonomy' ),
				'description' => __( 'Forums imported from bbPress', 'jetonomy' ),
				'visibility'  => 'public',
			]
		);
	}

	/**
	 * The category a container forum becomes (see container_forums()).
	 *
	 * Recorded in jt_import_map under `forum-{ID}`, so a re-run reuses it. The
	 * slug keeps the `imported-bbpress` prefix every imported category carries.
	 *
	 * @param object $forum bbPress forum row.
	 * @return int Category id.
	 */
	private function container_category( object $forum ): int {
		$slug = self::clean_slug( $forum->post_name ?: $forum->post_title ) ?: 'category';

		return $this->import_category(
			'forum-' . (int) $forum->ID,
			'imported-bbpress-' . $slug . '-' . (int) $forum->ID,
			[
				'name'        => $forum->post_title,
				'description' => wp_strip_all_tags( $forum->post_content ),
				'parent_id'   => max( 0, (int) $this->get_mapped_id( 'forum_category', (int) $forum->post_parent ) ),
				'visibility'  => $this->map_access( $forum )['visibility'],
				'sort_order'  => (int) $forum->menu_order,
			]
		);
	}

	/**
	 * Create the space for one bbPress forum row, resolving its parent.
	 *
	 * Callers must have mapped the parent forum already - see
	 * sort_rows_parents_first(). A parent that still doesn't resolve (orphan
	 * row, or a container that became a category) leaves it top level.
	 *
	 * @param object $forum  bbPress forum post row.
	 * @param int    $cat_id Category to file it under.
	 * @return int Space id, or 0 on failure.
	 */
	private function create_space_from_forum( object $forum, int $cat_id ): int {
		$access = $this->map_access( $forum );

		// bbPress nests forums through the ordinary WP post_parent column. Both
		// paths used to ignore it, so every sub-forum was created as a top-level
		// space and the customer's whole board structure was flattened on import.
		$parent_space_id = (int) $this->get_mapped_id( 'forum', (int) $forum->post_parent );

		return (int) Space::create(
			[
				'category_id' => $cat_id,
				'parent_id'   => $parent_space_id,
				'author_id'   => (int) $forum->post_author ?: 1,
				'type'        => 'forum',
				'title'       => $forum->post_title,

				/*
				 * A colliding slug must not fail the import, and must not be
				 * resolved by adopting whatever already holds it. Identity now
				 * comes from jt_import_map, so the slug is free to be made
				 * unique: a source forum whose name matches one of the owner's
				 * own spaces imports alongside it as "<slug>-1" instead of
				 * merging into it or erroring out.
				 */
				'slug'        => Space::unique_slug( self::clean_slug( $forum->post_name ?: $forum->post_title ) ?: 'forum-' . (int) $forum->ID ),
				'description' => wp_strip_all_tags( $forum->post_content ),
				// Carry the source forum's privacy across, with a join policy
				// the model accepts. See map_access().
				'visibility'  => $access['visibility'],
				'join_policy' => $access['join_policy'],
			]
		);
	}

	/**
	 * Import forum rows: adopt what an earlier run made, turn containers into
	 * categories, create a space for everything else.
	 *
	 * Shared by BOTH import paths (run_batch() and run()) on purpose: the parent
	 * mapping, the adoption check and the group link were each once missing
	 * from one path only.
	 *
	 * @param object[]        $forums     Forum rows to import, parents first.
	 * @param array<int,true> $containers container_forums() over ALL forums.
	 * @return void
	 */
	private function import_forum_rows( array $forums, array $containers ): void {
		// Parents an earlier batch created, and categories an earlier batch or
		// run made for a parent or for a container in this slice.
		$parents  = array_filter( array_unique( array_map( 'intval', array_column( $forums, 'post_parent' ) ) ) );
		$cat_keys = [];
		foreach ( array_merge( $parents, array_intersect( array_map( 'intval', array_column( $forums, 'ID' ) ), array_keys( $containers ) ) ) as $forum_id ) {
			$cat_keys[ $forum_id ] = 'forum-' . $forum_id;
		}
		$this->load_mapped( 'forum', 'space', array_combine( $parents, $parents ) );
		$this->load_mapped( 'forum_category', 'category', $cat_keys );

		$existing = $this->find_imported_forums( $forums );
		$cat_id   = 0;

		foreach ( $forums as $forum ) {
			$id = (int) $forum->ID;

			// Already imported by an earlier run? Adopt it, so topics beneath
			// it resolve their parent and anything missed last time imports.
			if ( isset( $existing[ $id ] ) ) {
				$this->map_id( 'forum', $id, $existing[ $id ] );
				if ( ! $this->dry_run ) {
					// A group forum an older release imported was never linked to its group.
					$this->link_buddypress_group( $existing[ $id ], $id );
				}
				++$this->already;
				continue;
			}

			if ( isset( $containers[ $id ] ) ) {
				if ( $this->get_mapped_id( 'forum_category', $id ) ) {
					++$this->already;
					continue;
				}
				$this->map_id( 'forum_category', $id, $this->container_category( $forum ) );
				++$this->imported;
				continue;
			}

			$space_id = self::DRY_RUN_ID;
			if ( ! $this->dry_run ) {
				// A forum inside a container goes into that container's
				// category, a sub-forum into its parent space's category, and
				// everything else into the import category - resolved only once
				// a forum really needs creating, so a re-run with nothing new
				// leaves no empty category behind.
				$in_category  = (int) $this->get_mapped_id( 'forum_category', (int) $forum->post_parent );
				$parent_space = (int) $this->get_mapped_id( 'forum', (int) $forum->post_parent );
				if ( ! $in_category && $parent_space > 0 ) {
					$in_category = (int) ( Space::find( $parent_space )->category_id ?? 0 );
				}
				if ( ! $in_category ) {
					$cat_id      = $cat_id ?: $this->bbpress_category();
					$in_category = $cat_id;
				}
				$space_id = $this->create_space_from_forum( $forum, $in_category );
			}

			if ( ! $space_id ) {
				$this->log_error( 'forum', $id, 'Failed to create space' );
				++$this->skipped;
				continue;
			}

			$this->map_id( 'forum', $id, $space_id );
			if ( ! $this->dry_run ) {
				$this->after_space_created( $forum, $space_id );
			}
			++$this->imported;
		}
	}

	/**
	 * Import topic rows. Shared by both paths (see import_forum_rows()).
	 *
	 * @param object[] $topics bbPress topic rows.
	 * @return void
	 */
	private function import_topic_rows( array $topics ): void {
		$forums = array_unique( array_map( 'intval', array_column( $topics, 'post_parent' ) ) );
		$this->load_mapped( 'forum', 'space', array_combine( $forums, $forums ) );

		$existing = $this->find_imported_children( 'post', $topics, 'forum' );
		$sticky   = $this->sticky_topic_ids( $topics );

		foreach ( $topics as $topic ) {
			$space_id = $this->get_mapped_id( 'forum', (int) $topic->post_parent );
			if ( ! $space_id ) {
				$this->skip_orphan( 'topic' );
				continue;
			}

			// Adopt a topic an earlier run already imported: map it so its
			// replies resolve, and do not create it twice.
			if ( isset( $existing[ (int) $topic->ID ] ) ) {
				$this->map_id( 'topic', $topic->ID, $existing[ (int) $topic->ID ] );
				++$this->already;
				continue;
			}

			$post_id = $this->dry_run ? self::DRY_RUN_ID : JtPost::create(
				[
					'space_id'      => $space_id,
					'author_id'     => (int) $topic->post_author,
					'type'          => \Jetonomy\compose_post_type( 'forum' ),
					'title'         => $topic->post_title,
					'slug'          => self::clean_slug( $topic->post_name ?: $topic->post_title ) ?: 'topic-' . (int) $topic->ID,
					'content'       => wp_kses_post( $topic->post_content ),
					'content_plain' => \jetonomy_content_to_plain( $topic->post_content ),
					'status'        => 'publish',
					'is_sticky'     => isset( $sticky[ (int) $topic->ID ] ) ? 1 : 0,
					// bbPress stores "closed" as the post_status; Jetonomy keeps
					// the topic published and flags it closed, so the thread stays
					// readable but takes no new replies.
					'is_closed'     => 'closed' === $topic->post_status ? 1 : 0,
					'created_at'    => $topic->post_date_gmt ?: now(),
				]
			);

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				$this->log_error( 'topic', $topic->ID, is_wp_error( $post_id ) ? $post_id->get_error_message() : 'Failed to create post' );
				++$this->skipped;
				continue;
			}

			$this->map_id( 'topic', $topic->ID, (int) $post_id );
			$this->remember( 'post', (int) $topic->ID, (int) $post_id );
			if ( ! $this->dry_run ) {
				$this->migrate_bbpress_attachments( 'post', (int) $topic->ID, (int) $post_id );
			}
			++$this->imported;
		}
	}

	/**
	 * Import reply rows. Shared by both paths (see import_forum_rows()).
	 *
	 * @param object[] $replies bbPress reply rows, in id order.
	 * @return void
	 */
	private function import_reply_rows( array $replies ): void {
		$topics = array_unique( array_map( 'intval', array_column( $replies, 'post_parent' ) ) );
		$this->load_mapped( 'topic', 'post', array_combine( $topics, $topics ) );

		$existing  = $this->find_imported_children( 'reply', $replies, 'topic' );
		$parent_of = $this->reply_parent_resolver( $replies );
		$rethread  = [];

		foreach ( $replies as $reply ) {
			// bbPress reply's post_parent is the topic ID.
			$post_id = $this->get_mapped_id( 'topic', (int) $reply->post_parent );
			if ( ! $post_id ) {
				$this->skip_orphan( 'reply' );
				continue;
			}

			if ( isset( $existing[ (int) $reply->ID ] ) ) {
				++$this->already;
				$parent = $parent_of( (int) $reply->ID );
				if ( $parent ) {
					$rethread[ $existing[ (int) $reply->ID ] ] = $parent;
				}
				continue;
			}

			$reply_id = $this->dry_run ? self::DRY_RUN_ID : JtReply::create(
				[
					'post_id'       => $post_id,
					'parent_id'     => $parent_of( (int) $reply->ID ),
					'author_id'     => (int) $reply->post_author,
					'content'       => wp_kses_post( $reply->post_content ),
					'content_plain' => \jetonomy_content_to_plain( $reply->post_content ),
					'status'        => 'publish',
					'created_at'    => $reply->post_date_gmt ?: now(),
				]
			);

			if ( is_wp_error( $reply_id ) || ! $reply_id ) {
				$this->log_error( 'reply', $reply->ID, is_wp_error( $reply_id ) ? $reply_id->get_error_message() : 'Failed to create reply' );
				++$this->skipped;
				continue;
			}

			// Mapped so a later reply in this batch threads under it.
			$this->map_id( 'reply', $reply->ID, (int) $reply_id );
			$this->remember( 'reply', (int) $reply->ID, (int) $reply_id );
			if ( ! $this->dry_run ) {
				$this->migrate_bbpress_attachments( 'reply', (int) $reply->ID, (int) $reply_id );
			}
			++$this->imported;
		}

		$this->rethread_existing( $rethread );
	}

	/**
	 * Keep this request's queued grants for the members phase.
	 *
	 * @return void
	 */
	private function queue_grants(): void {
		if ( $this->grants ) {
			update_option( self::GRANTS_OPTION, array_merge( (array) get_option( self::GRANTS_OPTION, [] ), $this->grants ), false );
			$this->grants = [];
		}
	}

	/**
	 * Batch result helper.
	 *
	 * @param string $phase     Next phase.
	 * @param int    $offset    Next offset.
	 * @param int    $processed Rows this call counts toward progress.
	 * @param bool   $done      Import finished.
	 * @return array{phase:string, offset:int, done:bool, processed:int}
	 */
	private function step( string $phase, int $offset, int $processed, bool $done = false ): array {
		return [
			'phase'     => $phase,
			'offset'    => $offset,
			'done'      => $done,
			'processed' => $processed,
		];
	}

	/**
	 * Import one batch of one phase.
	 *
	 * Phase order: forums -> members (only when grants were queued) -> topics
	 * -> replies -> profiles -> recount. Nothing is carried between batches
	 * except jt_import_map and the grant queue: each batch loads the parents
	 * it needs (load_mapped()).
	 *
	 * @param string $phase      Current phase.
	 * @param int    $offset     Row offset within the phase.
	 * @param int    $batch_size Rows to process this call.
	 * @return array{phase:string, offset:int, done:bool, processed:int}
	 */
	public function run_batch( string $phase, int $offset, int $batch_size ): array {
		$batch_size = max( 1, $batch_size );
		$this->start_budget();

		switch ( $phase ) {
			case 'forums':
				$all_forums = $this->forum_rows();
				$forums     = array_slice( $all_forums, $offset, $batch_size );

				$this->import_forum_rows( $forums, $this->container_forums( $all_forums ) );
				$this->queue_grants();

				if ( count( $all_forums ) > $offset + $batch_size ) {
					return $this->step( 'forums', $offset + $batch_size, count( $forums ) );
				}

				return $this->step( get_option( self::GRANTS_OPTION ) ? 'members' : 'topics', 0, count( $forums ) );

			case 'members':
				// Not counted toward progress: how many people a forum's
				// participants or a group add up to is only known once the
				// forums phase has linked them, after the total was fixed.
				$r    = $this->grant_members( (array) get_option( self::GRANTS_OPTION, [] ), $offset, $batch_size );
				$more = $r['processed'] < $r['fetched'] || $r['fetched'] >= $batch_size;
				if ( ! $more ) {
					delete_option( self::GRANTS_OPTION );
				}

				return $more ? $this->step( 'members', $offset + $r['processed'], 0 ) : $this->step( 'topics', 0, 0 );

			case 'topics':
				$topics = $this->content_rows( 'topic', $batch_size, $offset );
				$this->import_topic_rows( $topics );

				return count( $topics ) >= $batch_size
					? $this->step( 'topics', $offset + $batch_size, count( $topics ) )
					: $this->step( 'replies', 0, count( $topics ) );

			case 'replies':
				$replies = $this->content_rows( 'reply', $batch_size, $offset );
				$this->import_reply_rows( $replies );

				return count( $replies ) >= $batch_size
					? $this->step( 'replies', $offset + $batch_size, count( $replies ) )
					: $this->step( 'profiles', 0, count( $replies ) );

			case 'profiles':
				// Paged like every other phase: a forum with 50k authors must not
				// create 50k profiles in one request.
				$authors = $this->author_ids( $batch_size, $offset );
				foreach ( $authors as $uid ) {
					$this->ensure_profile( $uid );
				}

				return count( $authors ) >= $batch_size
					? $this->step( 'profiles', $offset + $batch_size, count( $authors ) )
					: $this->step( 'recount', 0, count( $authors ) );

			case 'recount':
				$this->recount();
				flush_rewrite_rules();

				return $this->step( 'complete', 0, 0, true );

			default:
				return $this->step( 'complete', 0, 0, true );
		}
	}

	public function run( array $options = [] ): array {
		$forums = $this->forum_rows();
		$this->import_forum_rows( $forums, $this->container_forums( $forums ) );

		// One process, no request timeout: grant every queued membership now,
		// a page at a time so memory stays flat.
		$offset = 0;
		while ( $this->grants ) {
			$r       = $this->grant_members( $this->grants, $offset, 500 );
			$offset += $r['processed'];
			if ( $r['fetched'] < 500 ) {
				$this->grants = [];
			}
		}

		$this->import_topic_rows( $this->content_rows( 'topic' ) );
		$this->import_reply_rows( $this->content_rows( 'reply' ) );

		if ( ! $this->dry_run ) {
			foreach ( $this->author_ids() as $uid ) {
				$this->ensure_profile( $uid );
			}
			$this->recount();
		}

		return $this->results();
	}

	/**
	 * Restore threading on replies an earlier release imported flat.
	 *
	 * Releases before 2.0.1 ignored `_bbp_reply_to`, so every reply they
	 * created is top-level. A re-run recognises those rows and would otherwise
	 * skip them as they are. Only a reply with no parent yet is touched, so
	 * anything re-threaded since the import stays as it is. Stickies are left
	 * alone on purpose: an owner may have pinned or unpinned topics since.
	 *
	 * @param array<int,int> $parents Jetonomy reply id => Jetonomy parent reply id.
	 * @return void
	 */
	private function rethread_existing( array $parents ): void {
		if ( $parents && ! $this->dry_run ) {
			$this->rethreaded += JtReply::fill_missing_parents( $parents );
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
