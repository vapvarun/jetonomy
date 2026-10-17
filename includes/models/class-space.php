<?php
/**
 * Space model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use Jetonomy\Cache;

class Space extends Model {

	/** Settings key set by hand_over() when it archives; reserved, never editable. */
	public const HANDOVER_ARCHIVE = '_handover_archive';


	protected static function table_name(): string {
		return 'spaces';
	}

	/**
	 * Find a space by ID, with 5-minute object-cache.
	 *
	 * @param int $id Space ID.
	 * @return object|null
	 */
	public static function find( int $id ): ?object {
		return Cache::remember_object(
			"space:{$id}",
			fn() => parent::find( $id ),
			300
		);
	}

	/**
	 * Load several spaces into the find() cache with one query, so a list of
	 * topics from many spaces (tag page, drafts, search) does not run one
	 * find() query per space. Already cached ids are
	 * skipped, so priming twice never re-queries.
	 *
	 * @param int[] $ids Space ids.
	 */
	public static function prime( array $ids ): void {
		$missing = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 && false === Cache::get( "space:{$id}" ) ) {
				$missing[] = $id;
			}
		}
		if ( ! $missing ) {
			return;
		}

		$ph = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = static::db()->get_results( static::db()->prepare( 'SELECT * FROM ' . static::table() . " WHERE id IN ({$ph})", ...$missing ) ) ?: array();
		foreach ( $rows as $row ) {
			Cache::set( "space:{$row->id}", $row, 300 );
		}
	}

	/**
	 * Bust every object-cache key that serves a space row.
	 *
	 * Row data lives once, under space:{id} (find()); find_by_slug() caches only
	 * the stable slug->id mapping under space:slug:{slug}. A row-data change
	 * therefore busts only space:{id}; the slug key is dropped only when the
	 * mapping itself changes — a slug rename or a delete (Caching Standard §2b).
	 * Callers bust AFTER the DB write — busting first is a re-prime race, and the
	 * slug-keyed disclosure gap (a space turned private still readable by URL) is
	 * exactly the member-visible staleness §4b forbids.
	 *
	 * @param int         $id   Space id.
	 * @param string|null $slug Slug whose mapping to also drop (rename/delete only).
	 */
	public static function bust_cache( int $id, ?string $slug = null ): void {
		$keys = array( "space:{$id}" );
		if ( ! empty( $slug ) ) {
			$keys[] = "space:slug:{$slug}";
		}
		Cache::delete_many( $keys );
		// The decoded-settings memo sits above the row cache — clear it with
		// the row or merge_settings()'s read-modify-write reads stale.
		unset( self::$settings_memo[ $id ] );
		// The category tree holds row COPIES (title, visibility, counts) —
		// a rename/visibility change must go cold with the row.
		self::bump_tree_generation();
		// Visibility / join-policy / settings changes alter permission
		// verdicts — clear the request-scope verdict memo.
		\Jetonomy\Permissions\Permission_Engine::reset_memo();
	}

	/**
	 * Update a space, then invalidate its cache (after the write).
	 *
	 * Busting before the write is a re-prime race: a concurrent read between the
	 * delete and the write re-caches the OLD row. So we write first, then bust
	 * space:{id}. Only when the slug changes do we also drop the old + new
	 * slug->id mappings (we read the old slug just for that case).
	 *
	 * @param int   $id   Space ID.
	 * @param array $data Column data.
	 * @return bool|\WP_Error WP_Error (400) when `type` is not a valid space type.
	 */
	public static function update( int $id, array $data ): bool|\WP_Error {
		$invalid = self::check_type( $data );
		if ( $invalid ) {
			return $invalid;
		}

		// The hand-over marker lets an owner undo the archive their hand-over
		// made. Every status change clears it, whatever else the write carries
		// (the wp-admin form saves settings alongside status); hand_over() sets
		// it afterwards in a settings-only write.
		if ( array_key_exists( 'status', $data ) ) {
			$settings = array_key_exists( 'settings', $data )
				? (array) ( json_decode( (string) $data['settings'], true ) ?: array() )
				: self::get_settings( $id );
			if ( isset( $settings[ self::HANDOVER_ARCHIVE ] ) || array_key_exists( 'settings', $data ) ) {
				unset( $settings[ self::HANDOVER_ARCHIVE ] );
				$data['settings'] = $settings ? wp_json_encode( $settings ) : '';
			}
		}

		$slug_changing = ! empty( $data['slug'] );
		$type_changing = ! empty( $data['type'] );
		// A space counts toward a category only while it is active and in one,
		// so BOTH a category move and a status change can alter two counters.
		$count_changing = array_key_exists( 'category_id', $data ) || array_key_exists( 'status', $data );
		// Visibility and join_policy together decide whether this space takes
		// join requests at all (see join_mode()).
		$access_changing = array_key_exists( 'join_policy', $data ) || array_key_exists( 'visibility', $data );

		// One read covers every comparison below.
		$existing = ( $slug_changing || $type_changing || $count_changing || $access_changing ) ? parent::find( $id ) : null;
		$old_slug = $slug_changing ? ( $existing->slug ?? null ) : null;
		$old_type = $type_changing ? ( $existing->type ?? null ) : null;

		// Private always means request or invite (owner decision, 2.0.2): an
		// "open" join policy on a private space is stored as approval, so the
		// saved value, the gate and the admin form all say the same thing.
		if ( $access_changing && $existing
			&& 'private' === ( $data['visibility'] ?? $existing->visibility ?? 'public' )
			&& 'open' === ( $data['join_policy'] ?? $existing->join_policy ?? 'open' ) ) {
			$data['join_policy'] = 'approval';
		}

		$result = parent::update( $id, $data );

		/*
		 * Keep jt_categories.space_count honest.
		 *
		 * Only create() incremented it and only purge decremented it, so
		 * moving a space between categories left both counters wrong - the old
		 * one too high, the new one too low - and archiving a space never
		 * decremented at all, while Recount counts active spaces only. Measured
		 * on a demo install: one category stored 23 against 5 actual spaces.
		 * `wp jetonomy recount` repaired it and the next move broke it again,
		 * which is the signature of a missing write-path adjustment rather than
		 * a bad backfill.
		 *
		 * Expressed as "which category was this space counted in, before and
		 * after" so one comparison covers a move, an archive, a restore, and a
		 * move-and-archive in the same call.
		 */
		if ( $result && $count_changing && $existing ) {
			$counted_in = static function ( $category_id, $status ): int {
				return ( 'active' === (string) $status ) ? (int) $category_id : 0;
			};

			$was = $counted_in( $existing->category_id ?? 0, $existing->status ?? '' );
			$now = $counted_in(
				array_key_exists( 'category_id', $data ) ? $data['category_id'] : ( $existing->category_id ?? 0 ),
				array_key_exists( 'status', $data ) ? $data['status'] : ( $existing->status ?? '' )
			);

			if ( $was !== $now ) {
				if ( $was > 0 ) {
					Category::increment_space_count( $was, -1 );
				}
				if ( $now > 0 ) {
					Category::increment_space_count( $now, 1 );
				}
			}
		}

		// Retype the posts inside when the space changes what it is. Post type
		// drives real behaviour, not just a label: schema markup only emits
		// QAPage with acceptedAnswer for `question`, so a forum space switched
		// to Q&A must not keep every post as `topic` and lose the rich result. Ideas
		// spaces are the same.
		if ( $type_changing && $old_type && $old_type !== $data['type'] ) {
			self::retype_posts( $id, (string) $data['type'] );
		}

		/*
		 * Settle the request queue when the space stops taking requests.
		 * Pending rows would otherwise linger after approval -> open/invite, looking
		 * unprocessed in the owner's queue forever.
		 * Every writer - REST PATCH, admin AJAX, WP-CLI - funnels through
		 * here, so this is the one place that sees all of them.
		 */
		if ( $result && $access_changing && $existing ) {
			$was = self::join_mode( (string) ( $existing->visibility ?? 'public' ), (string) ( $existing->join_policy ?? 'open' ) );
			$now = self::join_mode(
				(string) ( $data['visibility'] ?? ( $existing->visibility ?? 'public' ) ),
				(string) ( $data['join_policy'] ?? ( $existing->join_policy ?? 'open' ) )
			);
			if ( 'request' === $was && 'request' !== $now ) {
				JoinRequest::resolve_pending_for_space( $id, 'open' === $now, get_current_user_id() );
			}
		}

		self::bust_cache( $id );
		if ( $slug_changing && $data['slug'] !== $old_slug ) {
			$keys = array( "space:slug:{$data['slug']}" );
			if ( $old_slug ) {
				$keys[] = "space:slug:{$old_slug}";
			}
			Cache::delete_many( $keys );
		}
		return $result;
	}

	/**
	 * Rewrite every post in a space to the type its new space type creates.
	 *
	 * Post type is behavioural, not cosmetic: schema markup emits QAPage with
	 * acceptedAnswer only for `question`, the composer and roadmap key off it,
	 * and listings label by it. A space switched to Q&A whose posts stayed
	 * `topic` therefore looked converted and behaved like a forum.
	 *
	 * Uses \Jetonomy\compose_post_type() rather than a local map so this can
	 * never disagree with what the composer and REST create.
	 *
	 * @param int    $space_id   Space whose posts to retype.
	 * @param string $space_type New space type.
	 * @return int Rows updated.
	 */
	protected static function retype_posts( int $space_id, string $space_type ): int {
		$post_type = \Jetonomy\compose_post_type( $space_type );
		$table     = \Jetonomy\table( 'posts' );

		$updated = (int) static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from table().
				"UPDATE {$table} SET type = %s WHERE space_id = %d AND type <> %s",
				$post_type,
				$space_id,
				$post_type
			)
		);

		if ( $updated > 0 ) {
			// Post's own object caches (postcount:*, feed:*) are TTL-only by
			// design - their key space is unbounded per space, so no write can
			// enumerate them. A retype therefore surfaces within the ≤120s TTL,
			// same as any other bulk post change. What DOES need clearing is the
			// request-scope slug memo, since a row this request already read now
			// carries a different type.
			Post::reset_slug_memo();

			/**
			 * Fires after a space type change retypes the posts inside it.
			 *
			 * @since 1.9.3
			 *
			 * @param int    $space_id  The space.
			 * @param string $post_type The type every post now carries.
			 * @param int    $updated   Rows changed.
			 */
			do_action( 'jetonomy_space_posts_retyped', $space_id, $post_type, $updated );
		}

		return $updated;
	}

	/**
	 * Delete a space and everything it owns.
	 *
	 * A bare row delete orphaned every topic and reply in the space (700 topics
	 * after one imported space), so this is the same purge the admin screen,
	 * REST and WP-CLI run: a small space is gone on return, a large one is
	 * locked and drained in the background. Space_Purge removes the space row
	 * and busts its cache itself.
	 *
	 * @param int $id Space ID.
	 * @return bool|\WP_Error True when the space is gone or its background
	 *                        purge is under way, false when there is no such
	 *                        space, WP_Error when the database refused (the
	 *                        space and whatever it still holds are kept, and
	 *                        the purge retries).
	 */
	public static function delete( int $id ): bool|\WP_Error {
		if ( ! parent::find( $id ) ) {
			return false;
		}

		\Jetonomy\Space_Purge::queue( $id );
		if ( '' !== \Jetonomy\Space_Purge::last_error() ) {
			return new \WP_Error(
				'jetonomy_space_delete_failed',
				/* translators: %s: space label, lowercase (e.g. "space"). */
				sprintf( __( 'The %s could not be deleted, so it was kept with its content. Deletion will be retried shortly.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', false, true ) ),
				array( 'status' => 500 )
			);
		}
		return true;
	}

	/**
	 * Move ownership of a space to another member.
	 *
	 * The mechanics shared by every hand-over: an owner archiving their space,
	 * a site admin doing it from wp-admin or WP-CLI, and account deletion or a
	 * GDPR erase. `author_id` is attribution and the `space_members` admin row
	 * is the actual power, so both are written and both are checked; the hook
	 * fires only when the space really changed hands.
	 *
	 * @param int  $space_id        Space to hand over.
	 * @param int  $to_user_id      New owner.
	 * @param bool $archive         Park the space as well as reassigning it.
	 * @param bool $demote_previous Make the former owner a moderator (an
	 *                              explicit hand-over; account deletion has no
	 *                              former owner left to demote).
	 * @return true|\WP_Error
	 */
	public static function hand_over( int $space_id, int $to_user_id, bool $archive = false, bool $demote_previous = false ) {
		$space = $space_id > 0 ? self::find( $space_id ) : null;
		if ( ! $space ) {
			return new \WP_Error( 'jetonomy_space_not_found', sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ), array( 'status' => 404 ) );
		}

		$from_user_id = (int) $space->author_id;
		if ( $to_user_id <= 0 || $to_user_id === $from_user_id ) {
			return new \WP_Error( 'jetonomy_transfer_noop', sprintf( /* translators: 1: the singular Member label, lowercase, 2: the singular Space label, lowercase. */ __( 'This %1$s already owns the %2$s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', false, true ), \Jetonomy\space_label( false, true ) ), array( 'status' => 409 ) );
		}

		// Power first: if the new owner cannot be made an admin (a join filter
		// can veto it), nothing else changes.
		$heir_role = SpaceMember::get_role( $space_id, $to_user_id );
		$added     = SpaceMember::add( $space_id, $to_user_id, 'admin' );
		if ( is_wp_error( $added ) ) {
			return $added;
		}

		// A hand-over that reports failure must leave the space as it found it:
		// the heir goes back to the role they had (or out of the space).
		$failed = static function () use ( $space_id, $to_user_id, $heir_role ): \WP_Error {
			if ( null === $heir_role ) {
				SpaceMember::remove( $space_id, $to_user_id );
			} elseif ( 'admin' !== $heir_role ) {
				SpaceMember::set_role( $space_id, $to_user_id, $heir_role );
			}
			return new \WP_Error( 'jetonomy_transfer_failed', sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'The %s could not be handed over. Please try again.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ), array( 'status' => 500 ) );
		};

		$data = array( 'author_id' => $to_user_id );
		if ( $archive ) {
			$data['status'] = 'archived';
		}
		$written = self::update( $space_id, $data );

		$after = self::find( $space_id );
		if ( true !== $written || ! $after || (int) $after->author_id !== $to_user_id || ( $archive && 'archived' !== (string) $after->status ) ) {
			return $failed();
		}

		// The marker records that this hand-over archived the space, so it is
		// stamped only once the archive is confirmed. Without it the new owner
		// could not unarchive, so a missing marker undoes the whole hand-over.
		if ( $archive ) {
			$marked = self::update( $space_id, array( 'settings' => wp_json_encode( array_merge( self::get_settings( $space_id ), array( self::HANDOVER_ARCHIVE => 1 ) ) ) ) );
			if ( true !== $marked || empty( self::get_settings( $space_id )[ self::HANDOVER_ARCHIVE ] ) ) {
				self::update(
					$space_id,
					array(
						'author_id' => $from_user_id,
						'status'    => (string) $space->status,
					)
				);
				return $failed();
			}
		}

		if ( $demote_previous && $from_user_id > 0 && 'admin' === SpaceMember::get_role( $space_id, $from_user_id ) ) {
			SpaceMember::set_role( $space_id, $from_user_id, 'moderator' );
		}

		/**
		 * A space changed hands.
		 *
		 * @since 1.9.4
		 *
		 * @param int $space_id     The space.
		 * @param int $from_user_id Previous owner (0 when the space had none).
		 * @param int $to_user_id   New owner.
		 */
		do_action( 'jetonomy_space_transferred', $space_id, $from_user_id, $to_user_id );

		return true;
	}

	/**
	 * Archive a space and hand it to its successor (an explicit hand-over).
	 *
	 * The former owner stays on as a moderator. Callers check can_hand_over()
	 * first; WP-CLI runs as a trusted operator.
	 *
	 * @param int $space_id Space to hand over.
	 * @return int|\WP_Error New owner's user ID.
	 */
	public static function archive_and_hand_over( int $space_id ) {
		$space = self::find( $space_id );
		if ( ! $space ) {
			return new \WP_Error( 'jetonomy_space_not_found', sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ), array( 'status' => 404 ) );
		}
		if ( 'archived' === ( $space->status ?? '' ) ) {
			return new \WP_Error( 'jetonomy_space_archived', sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'This %s is already archived.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ), array( 'status' => 409 ) );
		}

		$successor = self::resolve_successor( $space_id );
		if ( ! $successor ) {
			return new \WP_Error(
				'jetonomy_no_successor',
				sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'No one else can take over this %s, so it cannot be transferred. An administrator must delete it permanently instead.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
				array( 'status' => 409 )
			);
		}

		$result = self::hand_over( $space_id, $successor, true, true );
		return is_wp_error( $result ) ? $result : $successor;
	}

	/**
	 * Whether a user may archive and hand over a space: its owner or a site
	 * admin. Other space admins may not, or ownership could bounce between them.
	 *
	 * @param object $space   Space row.
	 * @param int    $user_id User.
	 * @return bool
	 */
	public static function can_hand_over( object $space, int $user_id ): bool {
		if ( $user_id <= 0 || 'archived' === ( $space->status ?? '' ) ) {
			return false;
		}
		return user_can( $user_id, 'manage_options' ) || self::is_acting_owner( $space, $user_id );
	}

	/**
	 * Whether a user owns a space AND still holds its power: an admin row and
	 * no ban from it. author_id alone is attribution, so an owner who was
	 * removed or banned from their space cannot act on it as owner.
	 *
	 * @param object $space   Space row.
	 * @param int    $user_id User.
	 * @return bool
	 */
	private static function is_acting_owner( object $space, int $user_id ): bool {
		return (int) $space->author_id === $user_id
			&& 'admin' === SpaceMember::get_role( (int) $space->id, $user_id )
			&& ! Restriction::is_banned( $user_id )
			&& ! Restriction::is_space_banned( $user_id, (int) $space->id );
	}

	/**
	 * Whether a user may bring an archived space back: a site admin, or the
	 * owner when the archive came from a hand-over (never one a site admin
	 * imposed).
	 *
	 * @param object $space   Space row.
	 * @param int    $user_id User.
	 * @return bool
	 */
	public static function can_unarchive( object $space, int $user_id ): bool {
		if ( $user_id <= 0 || 'archived' !== ( $space->status ?? '' ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return self::is_acting_owner( $space, $user_id ) && ! empty( self::get_settings( (int) $space->id )[ self::HANDOVER_ARCHIVE ] );
	}

	/**
	 * Who should own this space if the current owner goes away.
	 *
	 * One rule for every hand-over:
	 *   1. A surviving space admin - earliest joined_at, user_id as tiebreak.
	 *      They already hold the admin row, so the space keeps working as-is.
	 *   2. The lowest-id site administrator.
	 * The current owner, the user named in $excluding and anyone banned from
	 * the site or this space never qualify.
	 *
	 * @param int $space_id  Space to find an heir for.
	 * @param int $excluding Another user to exclude (a departing member).
	 * @return int Successor user ID, or 0 when nobody qualifies.
	 */
	public static function resolve_successor( int $space_id, int $excluding = 0 ): int {
		return static::surviving_admin( $space_id, $excluding ) ?: static::fallback_owner( $excluding, $space_id );
	}

	/**
	 * The surviving space admin who inherits, if any (precedence step 1).
	 *
	 * Split out so a caller can tell "a space admin is still running this space"
	 * from "nobody is, fall back to the site admin". Any role counts here, site
	 * administrator included: the question is only whether a space admin row
	 * survives.
	 *
	 * @param int $space_id  Space to look in.
	 * @param int $excluding Another user to exclude (a departing member).
	 * @return int Space admin user ID, or 0 when none survives.
	 */
	public static function surviving_admin( int $space_id, int $excluding = 0 ): int {
		$members = \Jetonomy\table( 'space_members' );
		$space   = self::find( $space_id );
		$owner   = $space ? (int) $space->author_id : 0;

		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from table(), ban clause prepared by Restriction.
				"SELECT user_id FROM {$members} WHERE space_id = %d AND user_id NOT IN ( %d, %d ) AND role = 'admin'" . Restriction::not_banned_sql( 'user_id', $space_id ) . ' ORDER BY joined_at ASC, user_id ASC LIMIT 1',
				$space_id,
				$excluding,
				$owner
			)
		);
	}

	/**
	 * Lowest-id site administrator who is not banned, the owner of last
	 * resort (precedence step 2).
	 *
	 * @param int $excluding User to exclude (the departing owner).
	 * @param int $space_id  Space being handed over: its owner never qualifies
	 *                       and its own bans count.
	 * @return int User ID, or 0 when no other site administrator qualifies.
	 */
	public static function fallback_owner( int $excluding = 0, int $space_id = 0 ): int {
		$space   = $space_id ? self::find( $space_id ) : null;
		$exclude = array_filter( array( $excluding, $space ? (int) $space->author_id : 0 ) );

		$admins = get_users(
			array(
				'capability' => 'manage_options',
				'exclude'    => $exclude,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'number'     => 20,
				'fields'     => 'ID',
			)
		);

		foreach ( $admins as $admin_id ) {
			$admin_id = (int) $admin_id;
			if ( ! Restriction::is_banned( $admin_id ) && ( ! $space_id || ! Restriction::is_space_banned( $admin_id, $space_id ) ) ) {
				return $admin_id;
			}
		}
		return 0;
	}

	/**
	 * Next free manual-order position within a category.
	 *
	 * @param int $category_id Category the space belongs to (0 = uncategorised).
	 * @return int One past the highest position currently in use.
	 */
	protected static function next_sort_order( int $category_id ): int {
		$table = static::table();
		$max   = static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from static::table().
				"SELECT MAX(sort_order) FROM {$table} WHERE category_id = %d",
				$category_id
			)
		);

		return null === $max ? 0 : ( (int) $max + 1 );
	}

	/**
	 * Create a new space.
	 *
	 * Sets created_at and updated_at if absent, increments the parent
	 * category's space_count after a successful insert, and seeds the
	 * creator as a space admin so the new owner can run their space
	 * from the moment it exists.
	 *
	 * The seeding step is the behaviour change in 1.4.0. Prior to this,
	 * only the Abilities flow seeded the creator; REST POST /spaces and
	 * the wp-admin AJAX path created spaces with no admin row, leaving
	 * legitimate owners locked out of role management until they were
	 * added by another admin.
	 *
	 * Callers that intentionally create spaces on behalf of someone
	 * else (importers, BuddyPress group sync, demo seeders running
	 * unattended) can pass an explicit user ID, or pass 0 to skip
	 * seeding when the seed cannot be determined.
	 *
	 * @param array    $data            Column data.
	 * @param int|null $creator_user_id Optional. User ID to seed as
	 *                                  space admin. Defaults to the
	 *                                  current logged-in user. Pass 0
	 *                                  to skip seeding entirely.
	 * @return int|\WP_Error Inserted row ID (0 when the insert failed), or
	 *                       WP_Error (400) when `type` is not a valid space type.
	 */
	public static function create( array $data, ?int $creator_user_id = null ): int|\WP_Error {
		$invalid = self::check_type( $data );
		if ( $invalid ) {
			return $invalid;
		}

		$now  = now();
		$data = array_merge(
			array(
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);

		// Land new spaces at the END of their category's manual order. The
		// column defaults to 0, and listings sort `sort_order ASC, title ASC`,
		// so without this a space created after an owner arranged their
		// category would jump straight to the front - ahead of everything they
		// deliberately placed. Only computed when the caller did not set a
		// position explicitly.
		if ( ! isset( $data['sort_order'] ) ) {
			$data['sort_order'] = static::next_sort_order( (int) ( $data['category_id'] ?? 0 ) );
		}

		// Private always means request or invite; see update().
		if ( 'private' === ( $data['visibility'] ?? 'public' ) && 'open' === ( $data['join_policy'] ?? 'open' ) ) {
			$data['join_policy'] = 'approval';
		}

		$id = static::insert( $data );

		if ( $id <= 0 ) {
			return 0;
		}

		// find_by_slug() caches the slug->id mapping INCLUDING the id=0 miss.
		// Without this bust, a probe for a slug that didn't exist yet (the
		// REST unique_slug() duplicate guard does exactly that) left a cached
		// "no such slug" for 300s — so a second create in that window minted a
		// duplicate slug on persistent-cache installs.
		if ( ! empty( $data['slug'] ) ) {
			Cache::delete( 'space:slug:' . (string) $data['slug'] );
		}

		// A new space must appear in the cached category tree.
		self::bump_tree_generation();

		// Counted as update() counts: only active spaces (a space created
		// archived or locked is not in its category's count).
		if ( ! empty( $data['category_id'] ) && 'active' === (string) ( $data['status'] ?? 'active' ) ) {
			Category::increment_space_count( (int) $data['category_id'] );
		}

		$seed_user_id = $creator_user_id ?? get_current_user_id();
		if ( $seed_user_id > 0 ) {
			SpaceMember::add( $id, $seed_user_id, 'admin' );
		}

		return $id;
	}

	/**
	 * Find a space by its slug, with 5-minute object-cache.
	 *
	 * @param string $slug
	 * @return object|null
	 */
	public static function find_by_slug( string $slug ): ?object {
		// Cache only the STABLE slug->id mapping here; the mutable row data lives
		// once, under space:{id} via find(). Caching the whole row under the slug
		// key too would double-store it and force every count change to bust this
		// key (an extra read on hot paths). The mapping only changes on a slug
		// rename or a delete, so it rarely needs busting. (Caching Standard §2b.)
		$id = (int) Cache::remember(
			"space:slug:{$slug}",
			function () use ( $slug ) {
				return (int) static::db()->get_var(
					static::db()->prepare(
						'SELECT id FROM ' . static::table() . ' WHERE slug = %s',
						$slug
					)
				);
			},
			300
		);

		return $id > 0 ? self::find( $id ) : null;
	}

	/**
	 * A slug guaranteed unique among spaces — appends -1, -2, … until free.
	 *
	 * The single source of truth for slug uniqueness. The `slug` column has a
	 * UNIQUE key, so any caller that inserts a space MUST route its slug through
	 * here first; inserting a colliding slug silently fails the INSERT (returns
	 * 0). Used by the REST create path and the Pro course→space provisioner so
	 * neither can drift.
	 *
	 * @param string $base_slug Sanitized starting slug (e.g. sanitize_title($title)).
	 * @return string A slug with no existing space.
	 */
	public static function unique_slug( string $base_slug ): string {
		$slug    = $base_slug;
		$counter = 1;

		while ( self::find_by_slug( $slug ) ) {
			$slug = $base_slug . '-' . $counter;
			++$counter;
		}

		return $slug;
	}

	/**
	 * Canonical CONTENT-visibility predicate — "can this viewer READ content in the space."
	 *
	 * SQL form of {@see \Jetonomy\Permissions\Permission_Engine::can()} with
	 * action 'read', so every cross-space content query (full-text search,
	 * feeds, recent-post lists, oEmbed list paths) applies the same gate and
	 * cannot surface a post whose parent space the viewer may not read:
	 *   - WP admin: every space.
	 *   - Guest: public spaces only.
	 *   - Logged-in: public spaces, plus any space they are a member of
	 *     (covers PRIVATE and HIDDEN spaces they belong to).
	 *
	 * A space also inherits its CATEGORY's concealment ({@see
	 * self::parent_category_clause()}). A public space inside a `hidden`
	 * category is not readable by a stranger, because the category promised the
	 * owner that nothing in it is reachable yet. Without this, `hidden` was
	 * cosmetic on every read surface: the space's topics still surfaced in
	 * search, tag pages, the sidebar, profile activity and the Pro digest,
	 * because only the LISTING predicate consulted the parent.
	 *
	 * Fails CLOSED relative to can(): AccessRule grants and per-user bans are
	 * intentionally not modelled in SQL — the predicate is the membership-based
	 * common case and may only ever UNDER-include (never leak). Single-item
	 * reads still pass through Permission_Engine::can_read_post() for the
	 * authoritative decision. The per-post `is_private` flag is a separate axis
	 * layered on top by the search adapter, not here.
	 *
	 * @param int|null $user_id Viewer ID. Null = current user; pass 0 to force guest.
	 * @param string   $alias   Spaces-table alias without trailing dot (e.g. 's'); '' if unaliased.
	 * @return array{0:string,1:array} [SQL fragment, bind values]
	 */
	public static function content_visibility_sql( ?int $user_id, string $alias = '' ): array {
		$user_id = $user_id ?? get_current_user_id();
		$col     = '' !== $alias ? $alias . '.' : '';

		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return array( '1=1', array() );
		}

		if ( $user_id <= 0 ) {
			return array( "{$col}visibility = 'public'" . self::parent_category_and( $user_id, $col ), array() );
		}

		$members_table = \Jetonomy\table( 'space_members' );
		$parent        = self::parent_category_clause( $user_id, $col );
		$readable      = "{$col}visibility = 'public'";
		if ( '' !== $parent ) {
			$readable = "({$readable} AND {$parent})";
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $members_table is a trusted prefixed name.
		$fragment = "({$readable} OR {$col}id IN (SELECT space_id FROM {$members_table} WHERE user_id = %d))";
		return array( $fragment, array( $user_id ) );
	}

	/**
	 * Canonical LISTING-visibility predicate — "should this space appear in a directory."
	 *
	 * Deliberately BROADER than {@see self::content_visibility_sql()}: a
	 * `private` space is DISCOVERABLE — its card shows in listings (with a
	 * Join / Request / Invite-only call to action) while its content stays
	 * gated behind membership. That discoverability is exactly what separates
	 * `private` from `hidden`; only `hidden` spaces are withheld from
	 * non-members:
	 *   - WP admin: every space.
	 *   - Guest: public spaces only (no anonymous exposure of private names).
	 *   - Logged-in: public + private spaces, plus any HIDDEN space they belong to.
	 *
	 * Private and hidden are distinct visibility states: collapsing them would make
	 * "Private+open" spaces invisible.
	 *
	 * Site owners can widen (e.g. show private to guests) or narrow (e.g. hide
	 * invite-only) per site via the `jetonomy_space_listing_visibility_sql`
	 * filter without forking the query.
	 *
	 * @param int|null $user_id Viewer ID. Null = current user; pass 0 to force guest.
	 * @param string   $alias   Spaces-table alias without trailing dot (e.g. 's'); '' if unaliased.
	 * @return array{0:string,1:array} [SQL fragment, bind values]
	 */
	public static function listing_visibility_sql( ?int $user_id, string $alias = '' ): array {
		$user_id = $user_id ?? get_current_user_id();
		$col     = '' !== $alias ? $alias . '.' : '';

		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			$result = array( '1=1', array() );
		} elseif ( $user_id <= 0 ) {
			// Settings -> Access Control can list private spaces to guests as
			// locked teasers; their topics stay behind can( 'read' ).
			$guest_vis = ! empty( get_option( 'jetonomy_settings', array() )['guest_private_teasers'] ) ? "IN ('public','private')" : "= 'public'";
			$result    = array( "{$col}visibility {$guest_vis}" . self::parent_category_and( $user_id, $col ), array() );
		} else {
			$members_table = \Jetonomy\table( 'space_members' );
			$parent        = self::parent_category_clause( $user_id, $col );
			$discoverable  = "{$col}visibility IN ('public','private')";
			if ( '' !== $parent ) {
				$discoverable = "({$discoverable} AND {$parent})";
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $members_table is a trusted prefixed name.
			$fragment = "({$discoverable} OR {$col}id IN (SELECT space_id FROM {$members_table} WHERE user_id = %d))";
			$result   = array( $fragment, array( $user_id ) );
		}

		/**
		 * Filter the space-listing visibility SQL predicate.
		 *
		 * @param array{0:string,1:array} $result  [ SQL fragment, bind values ].
		 * @param int|null                $user_id Viewer ID (resolved; 0 for guest).
		 * @param string                  $alias   Spaces-table alias without trailing dot.
		 */
		return apply_filters( 'jetonomy_space_listing_visibility_sql', $result, $user_id, $alias );
	}

	/**
	 * Back-compat shim for the former listing predicate.
	 *
	 * Historically this returned the "public OR member" fragment and was used
	 * by every list surface — which is why `private` spaces were wrongly
	 * hidden. It now delegates to {@see self::listing_visibility_sql()} so the
	 * listing surfaces gain `private` discoverability. Kept private and thin to
	 * avoid churning every caller in one commit.
	 *
	 * @param int|null $user_id Viewer ID.
	 * @return array{0:string,1:array}
	 */
	private static function visibility_predicate_for( ?int $user_id ): array {
		return self::listing_visibility_sql( $user_id );
	}

	/**
	 * Effective-visibility clause: a space is no more visible than its category.
	 *
	 * An owner who marks a category `hidden` reasonably reads that as covering
	 * what is inside it — nothing in the admin UI says otherwise — but a space
	 * carries its own `visibility` column and nothing ever consulted the
	 * parent, so a `public` space in a `hidden` category was served to
	 * anonymous visitors.
	 *
	 * Resolved at READ time rather than cascaded onto rows on save: the value
	 * then stays correct when a category's visibility changes later, and we
	 * never rewrite a `visibility` the owner did not edit. The cost is this
	 * clause on listing queries; `category_id` is indexed and the subquery is
	 * over a table with tens of rows, not thousands.
	 *
	 * Uncategorised spaces (`category_id` NULL or 0) are unaffected — there is
	 * no parent to inherit from.
	 *
	 * MEMBERSHIP OVERRIDES IT. `hidden` means the same thing on a category as
	 * it has always meant on a space: only members can find this. A hidden
	 * SPACE stays listed for its own members, so a space inside a hidden
	 * CATEGORY must too — otherwise the two states the owner reads as one word
	 * behave differently, and a member of a private space loses it from their
	 * own directory because the owner tidied the category it sits in. That is
	 * why callers compose this into the public/private disjunct rather than
	 * ANDing it across the whole predicate; the membership disjunct is not
	 * subject to it. {@see self::concealed_by_category()} exempts the same
	 * viewers on the read side, so a card in a listing always opens.
	 *
	 * @param int|null $user_id Viewer ID (0/null for guests).
	 * @param string   $col     Spaces-table alias prefix, including trailing dot, or ''.
	 * @return string Bare SQL condition, or '' when unrestricted.
	 */
	private static function parent_category_clause( ?int $user_id, string $col ): string {
		[ $cat_where ] = \Jetonomy\Models\Category::listing_visibility_sql( $user_id );

		if ( '1=1' === $cat_where ) {
			return '';
		}

		$categories_table = \Jetonomy\table( 'categories' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $categories_table is a trusted prefixed name; $cat_where is literal SQL from Category::listing_visibility_sql() (no placeholders in the guest/member branches).
		return "({$col}category_id IS NULL OR {$col}category_id = 0 OR {$col}category_id IN (SELECT id FROM {$categories_table} WHERE {$cat_where}))";
	}

	/**
	 * `AND <clause>` form of {@see self::parent_category_clause()}, for a
	 * predicate with no membership disjunct to keep outside it (a guest).
	 *
	 * @param int|null $user_id Viewer ID.
	 * @param string   $col     Alias prefix including trailing dot, or ''.
	 * @return string
	 */
	private static function parent_category_and( ?int $user_id, string $col ): string {
		$clause = self::parent_category_clause( $user_id, $col );

		return '' === $clause ? '' : ' AND ' . $clause;
	}

	/**
	 * Should this space's EXISTENCE be concealed from the viewer?
	 *
	 * A `hidden` space promises "only members can find this space". Listings
	 * already honour that (listing_visibility_sql), but a direct URL would
	 * answer HTTP 200 with the space's title — disclosing that the space
	 * exists and what it is called (product decision:
	 * a non-member gets a 404, the stronger reading of "hidden").
	 *
	 * Semantics deliberately mirror the single-post space gate
	 * (templates/views/single-post.php): visible to members and
	 * `manage_options` only. `private` spaces stay discoverable by design —
	 * this concerns `hidden` exclusively.
	 *
	 * @param object   $space   Space row (needs ->id and ->visibility).
	 * @param int|null $user_id Viewer ID (0/null for guests).
	 * @return bool True when the viewer must see a 404, not a gate page.
	 */
	public static function concealed_from_viewer( object $space, ?int $user_id ): bool {
		if ( user_can( (int) $user_id, 'manage_options' ) ) {
			return false;
		}

		// A space inside a category the viewer cannot see is concealed whatever
		// the space's OWN visibility says. A public space in a `hidden`
		// category answered HTTP 200 with its title to anyone holding the URL,
		// which made a hidden category cosmetic - the owner staged content in
		// it and it was publicly reachable the whole time. Members of the space
		// itself are exempt (concealed_by_category() checks admitted() first);
		// everyone else gets the hidden category's answer.
		if ( self::concealed_by_category( $space, $user_id ) ) {
			return true;
		}

		if ( 'hidden' !== ( $space->visibility ?? '' ) ) {
			return false;
		}
		if ( ! $user_id ) {
			return true;
		}
		// A viewer admitted by an access rule (e.g. a paid tier) is not a
		// stranger - concealing the space from them would 404 the very people
		// the rule exists to let in.
		return ! self::admitted( (int) $space->id, (int) $user_id );
	}

	/**
	 * Does this space's parent category conceal it from the viewer?
	 *
	 * Same predicate the category's own listing uses, asked about one row, so
	 * the rule lives in exactly one place ({@see
	 * Category::listing_visibility_sql()}). Uncategorised spaces have no parent
	 * to inherit from and are never concealed this way.
	 *
	 * @param object   $space   Space row (needs ->category_id).
	 * @param int|null $user_id Viewer ID (0/null for guests).
	 * @return bool
	 */
	public static function concealed_by_category( object $space, ?int $user_id ): bool {
		$category_id = (int) ( $space->category_id ?? 0 );

		if ( $category_id <= 0 ) {
			return false;
		}

		$user_id = $user_id ?? get_current_user_id();

		// Membership overrides the parent, exactly as it does in the listing
		// predicate: a hidden category withholds its spaces from strangers, not
		// from the members of a space inside it. Checked before the memo
		// because the answer is per space, not per category.
		if ( $user_id > 0 && self::admitted( (int) ( $space->id ?? 0 ), (int) $user_id ) ) {
			return false;
		}

		$memo = (int) $user_id . ':' . $category_id;

		// Memoised for the request. Permission_Engine::can( 'read', $space )
		// asks this once per space on a topic list, and the answer for one
		// (viewer, category) pair cannot change inside a request - without the
		// memo a 50-row list that spans 6 categories runs 50 queries where 6
		// would do.
		if ( isset( self::$category_conceal_memo[ $memo ] ) ) {
			return self::$category_conceal_memo[ $memo ];
		}

		[ $cat_where, $cat_values ] = Category::listing_visibility_sql( $user_id );

		if ( '1=1' === $cat_where ) {
			self::$category_conceal_memo[ $memo ] = false;
			return false;
		}

		$categories_table = \Jetonomy\table( 'categories' );
		$sql              = "SELECT id FROM {$categories_table} WHERE id = %d AND {$cat_where}";
		$values           = array_merge( array( $category_id ), $cat_values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $categories_table is a trusted prefixed name; $cat_where is literal SQL from Category::listing_visibility_sql().
		$visible = (bool) static::db()->get_var( static::db()->prepare( $sql, ...$values ) );

		self::$category_conceal_memo[ $memo ] = ! $visible;

		return ! $visible;
	}

	/**
	 * Per-request memo for {@see self::concealed_by_category()}.
	 *
	 * Keyed "{user_id}:{category_id}". Request-scoped on purpose: a category's
	 * visibility change lands on the next request, and nothing here is worth a
	 * cache key to invalidate.
	 *
	 * @var array<string, bool>
	 */
	private static array $category_conceal_memo = array();

	/**
	 * Is this viewer admitted to this space?
	 *
	 * THE admission question, and the only place the answer is spelled out.
	 *
	 * Jetonomy derives access from the rule at read time and deliberately keeps
	 * no copy of the roster - that is why a rule added later works
	 * retroactively, and why a roster row is not the test. A learner enrolled
	 * BEFORE a course got its space has no membership row and never will, yet
	 * the rule admits them and the space itself lets them straight in.
	 *
	 * @param int $space_id Space.
	 * @param int $user_id  Viewer; 0 for anonymous.
	 * @return bool
	 */
	public static function admitted( int $space_id, int $user_id ): bool {
		if ( $space_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		return SpaceMember::is_member( $space_id, $user_id )
			|| AccessRule::grants_access( $user_id, $space_id );
	}

	/**
	 * May this viewer READ this space and the things that describe it?
	 *
	 * Public spaces are readable by anyone. Private and hidden ones require
	 * admission - which is membership OR a rule, never membership alone.
	 *
	 * It exists because the REST layer answered this question in its own words
	 * and got it wrong in the direction that matters: a learner holding the
	 * course's access rule was admitted by the web page and refused a 403 by
	 * the API for the same space, so the app could not show a room the browser
	 * could. Two surfaces disagreeing about one viewer is the defect, and one
	 * predicate is what stops a third surface inventing a third answer.
	 *
	 * NOT for roster questions. "Are you already a member", "is this user on
	 * the roster to remove or promote" are about the membership row itself and
	 * must keep asking SpaceMember directly - a rule-admitted viewer has no row
	 * to remove, and answering those with admission would be wrong.
	 *
	 * @param object $space   Space row (needs ->id and ->visibility).
	 * @param int    $user_id Viewer; 0 for anonymous.
	 * @return bool
	 */
	public static function readable_by_viewer( object $space, int $user_id ): bool {
		// The parent category is asked FIRST and is not overridable by
		// admission: a public space inside a `hidden` category must not answer
		// GET /spaces/{id} with 200 to a stranger holding the id, or the category's promise
		// would be cosmetic on the API while the browser path (concealed_from_viewer)
		// honours it. Same question, one answer.
		if ( self::concealed_by_category( $space, $user_id ) ) {
			return false;
		}

		if ( ! in_array( (string) ( $space->visibility ?? '' ), array( 'private', 'hidden' ), true ) ) {
			return true;
		}

		// The owner reads every space. admitted() deliberately does NOT say
		// this - it answers "is this viewer admitted", and an administrator who
		// is not a member is not admitted; that method also backs roster
		// decisions where claiming otherwise would be wrong. So the bypass
		// belongs here, on the READ question. Without it GET /spaces/{id}
		// answered 403 to an administrator for a private space they were not a
		// member of, while the space page in the browser (which checks
		// manage_options directly) let the same person straight in - the app
		// could not open a space the owner was already looking at.
		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return self::admitted( (int) ( $space->id ?? 0 ), $user_id );
	}

	/**
	 * List top-level spaces in a category for a given viewer.
	 *
	 * @param int      $category_id Category row ID.
	 * @param int|null $user_id     Viewer ID; null = current user.
	 * @return object[]
	 */
	/**
	 * ALL top-level spaces visible to the viewer, grouped by category_id in
	 * ONE query. Key 0 collects uncategorized spaces. Replaces
	 * the per-category list_by_category()/list_visible() loops on the home
	 * page, the navigation block (which also ran a discarded COUNT per
	 * category) and GET /categories.
	 *
	 * Cached 600s per viewer bucket via a generation key:
	 * guest and admin are shared classes, every other viewer gets a
	 * per-user key (the membership subquery binds their id). The key
	 * embeds cat_tree_gen, bumped by bump_tree_generation() from
	 * Space::bust_cache()/create() and the Category admin CRUD — a space
	 * rename busts space:{id} but this tree holds row COPIES, so without
	 * the bump it would keep serving the old name. Caching is SKIPPED
	 * entirely when a third party filters
	 * jetonomy_space_listing_visibility_sql — the bucket cannot model an
	 * arbitrary predicate.
	 *
	 * @param int|null $user_id Viewer ID; null = current user.
	 * @return array<int, object[]> category_id => space rows (sort_order, title).
	 */
	public static function visible_by_category( ?int $user_id = null ): array {
		$user_id   = $user_id ?? get_current_user_id();
		$cacheable = ! has_filter( 'jetonomy_space_listing_visibility_sql' );

		[ $vis_where, $vis_values ] = self::visibility_predicate_for( $user_id );

		$key = '';
		if ( $cacheable ) {
			$bucket = 'guest';
			if ( $user_id > 0 ) {
				$bucket = ( '1=1' === $vis_where ) ? 'admin' : "u{$user_id}";
			}
			$gen = (int) Cache::get( 'cat_tree_gen' );
			$key = "cat_tree:{$gen}:{$bucket}";

			$cached = Cache::get( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from visibility_predicate_for() with literal SQL + %d placeholders.
		$sql  = 'SELECT * FROM ' . static::table() . " WHERE {$vis_where} ORDER BY sort_order ASC, title ASC";
		$rows = empty( $vis_values )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			? static::db()->get_results( $sql )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: static::db()->get_results( static::db()->prepare( $sql, ...$vis_values ) );

		$grouped = array();
		foreach ( $rows ?: array() as $row ) {
			$grouped[ (int) ( $row->category_id ?? 0 ) ][] = $row;
		}

		if ( $cacheable ) {
			Cache::set( $key, $grouped, 600 );
		}

		return $grouped;
	}

	/**
	 * Bump the tree generation so every cat_tree:{gen}:{bucket} key goes
	 * cold — the per-user bucket set is unenumerable, so a
	 * generation bump is the sanctioned versioned-key invalidation. A
	 * missing/evicted gen re-seeds at 1; a lingering old key under a reused
	 * gen is bounded by the 600s data TTL (documented, same worst case as
	 * TTL-only).
	 */
	public static function bump_tree_generation(): void {
		$gen = (int) Cache::get( 'cat_tree_gen' );
		Cache::set( 'cat_tree_gen', $gen + 1, 0 );
	}

	/**
	 * Top-level spaces in one category, visible to the viewer.
	 *
	 * $limit defaults to 0 (no bound) so existing callers are unchanged, but
	 * every VIEW should pass one: a category page rendered every space it held,
	 * which is fine at five and unusable at two thousand.
	 *
	 * A CHILD space is listed here too, under the category it was assigned.
	 * The query does not filter on `parent_id`, so a child space is listed under its own
	 * category and an imported sub-forum is reachable from the directory (nothing else
	 * nests children: `list_children()` has no callers in free, Pro or the templates).
	 *
	 * A child therefore appears exactly ONCE, under its own category. It is
	 * deliberately not also nested under its parent: the same space listed
	 * twice on one page reads as a bug.
	 *
	 * @param int      $category_id Category id.
	 * @param int|null $user_id     Viewer; null = current user.
	 * @param int      $limit       0 = all.
	 * @param int      $offset      Rows to skip.
	 * @return object[]
	 */
	public static function list_by_category( int $category_id, ?int $user_id = null, int $limit = 0, int $offset = 0 ): array {
		[ $vis_where, $vis_values ] = self::visibility_predicate_for( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from visibility_predicate_for() with literal SQL + %d placeholders.
		$sql    = 'SELECT * FROM ' . static::table() . " WHERE category_id = %d AND {$vis_where} ORDER BY sort_order ASC, title ASC";
		$values = array_merge( array( $category_id ), $vis_values );

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$values[] = $limit;
			$values[] = max( 0, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above with bound %d values.
		return static::db()->get_results( static::db()->prepare( $sql, ...$values ) ) ?: array();
	}

	/**
	 * Count top-level spaces in a category, for pagination totals.
	 *
	 * @param int      $category_id Category id.
	 * @param int|null $user_id     Viewer; null = current user.
	 * @return int
	 */
	public static function count_by_category( int $category_id, ?int $user_id = null ): int {
		[ $vis_where, $vis_values ] = self::visibility_predicate_for( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from visibility_predicate_for().
		$sql    = 'SELECT COUNT(*) FROM ' . static::table() . " WHERE category_id = %d AND {$vis_where}";
		$values = array_merge( array( $category_id ), $vis_values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return (int) static::db()->get_var( static::db()->prepare( $sql, ...$values ) );
	}

	/**
	 * List uncategorized top-level spaces visible to a given viewer.
	 *
	 * @param int|null $user_id Viewer ID; null = current user.
	 * @return object[]
	 */
	public static function list_uncategorized( ?int $user_id = null, int $limit = 0, int $offset = 0 ): array {
		[ $vis_where, $vis_values ] = self::visibility_predicate_for( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from visibility_predicate_for() with literal SQL + %d placeholders.
		$sql = 'SELECT * FROM ' . static::table() . " WHERE (category_id IS NULL OR category_id = 0) AND {$vis_where} ORDER BY sort_order ASC, title ASC";

		$values = $vis_values;
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$values[] = $limit;
			$values[] = max( 0, $offset );
		}

		if ( empty( $values ) ) {
			return static::db()->get_results( $sql ) ?: array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared with bound %d values.
		return static::db()->get_results( static::db()->prepare( $sql, ...$values ) ) ?: array();
	}

	/**
	 * Count uncategorized top-level spaces, for pagination totals.
	 *
	 * @param int|null $user_id Viewer; null = current user.
	 * @return int
	 */
	public static function count_uncategorized( ?int $user_id = null ): int {
		[ $vis_where, $vis_values ] = self::visibility_predicate_for( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from visibility_predicate_for().
		$sql = 'SELECT COUNT(*) FROM ' . static::table() . " WHERE (category_id IS NULL OR category_id = 0) AND {$vis_where}";

		if ( empty( $vis_values ) ) {
			return (int) static::db()->get_var( $sql );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return (int) static::db()->get_var( static::db()->prepare( $sql, ...$vis_values ) );
	}

	/**
	 * List child spaces for a given parent space.
	 *
	 * @param int $parent_id
	 * @return object[]
	 */
	public static function list_children( int $parent_id, ?int $user_id = null ): array {
		if ( $parent_id <= 0 ) {
			return array();
		}

		// Visibility-filtered, like every other listing. This method had no
		// callers at all until the sub-space strip was built on it, so it had
		// never had to answer the question - and shipping the first caller
		// against an unfiltered query is how a private sub-forum would have
		// appeared under a public parent.
		[ $vis_sql, $vis_params ] = self::listing_visibility_sql( $user_id );

		$params = array_merge( array( $parent_id ), $vis_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_sql is literal SQL from listing_visibility_sql().
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table()
					. " WHERE parent_id = %d AND status = 'active' AND {$vis_sql}"
					. ' ORDER BY sort_order ASC, title ASC',
				$params
			)
		) ?: array();
	}

	/**
	 * Increment the post_count and update activity timestamps.
	 *
	 * @param int $id Space ID.
	 * @param int $by Amount to add (use negative value to decrement).
	 */
	public static function increment_post_count( int $id, int $by = 1 ): void {
		$now = now();
		// Only a new topic is activity: removing one (delete, trash, move out)
		// must not make a quiet space jump up the "active" listings.
		$activity = $by > 0 ? 'last_activity_at = %s, ' : '';
		$args     = $by > 0 ? array( $by, $now, $now, $id ) : array( $by, $now, $id );
		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $activity is one of two fixed fragments.
				'UPDATE ' . static::table() . " SET post_count = GREATEST(post_count + %d, 0), {$activity}updated_at = %s WHERE id = %d",
				...$args
			)
		);
		self::bust_cache( $id );
		// The space's cached JSON-LD lists its recent posts + post_count —
		// every publish transition and move lands here with the space id in
		// hand, making this the single bust point for it.
		Cache::delete( "schema:space:{$id}" );
	}

	/**
	 * Increment the member_count and update updated_at.
	 *
	 * @param int $id Space ID.
	 * @param int $by Amount to add (use negative value to decrement).
	 */
	public static function increment_member_count( int $id, int $by = 1 ): void {
		$now = now();
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET member_count = GREATEST(member_count + %d, 0), updated_at = %s WHERE id = %d',
				$by,
				$now,
				$id
			)
		);
		self::bust_cache( $id );
	}

	/**
	 * List all spaces filtered by status.
	 *
	 * @param string $status Row status value to filter by (e.g. 'active', 'archived').
	 * @param int    $limit  Max rows to return. 0 = unbounded (default,
	 *                       preserves pre-1.4.3 behaviour for every caller
	 *                       that did not opt in to pagination).
	 * @param int    $offset Row offset. Ignored when $limit = 0.
	 * @return object[]
	 */
	public static function list_all( string $status = 'active', int $limit = 0, int $offset = 0 ): array {
		$base = 'SELECT * FROM ' . static::table() . ' WHERE status = %s ORDER BY title ASC';
		if ( $limit > 0 ) {
			return static::db()->get_results(
				static::db()->prepare( $base . ' LIMIT %d OFFSET %d', $status, $limit, max( 0, $offset ) )
			) ?: array();
		}
		return static::db()->get_results(
			static::db()->prepare( $base, $status )
		) ?: array();
	}

	/**
	 * Hydrate space rows for a given set of IDs.
	 *
	 * Single indexed query — used by paths that already know which spaces they
	 * want (e.g. postable_by_me starts from `space_members`, then needs the
	 * row data). Keeps the order of the input IDs so callers can preserve their
	 * own ranking.
	 *
	 * @param int[] $ids
	 * @return object[] Sparse — only IDs that exist + are active are returned.
	 */
	public static function list_by_ids( array $ids ): array {
		$ids = array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 );
		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE id IN ({$placeholders})",
				...$ids
			)
		) ?: array();

		// Re-order to match input.
		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row->id ] = $row;
		}
		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}
		return $ordered;
	}

	/**
	 * List spaces visible to a given user, with pagination.
	 *
	 * Visibility rules (all resolved in SQL — no PHP-side filtering):
	 * - Logged-out users: only public spaces.
	 * - Logged-in non-admins: public spaces + private/hidden spaces where the user
	 *   is a member (LEFT JOIN on space_members).
	 * - WP admins (manage_options): all spaces regardless of visibility.
	 *
	 * @param int         $user_id     Current user ID (0 for guests).
	 * @param int|null    $category_id Optional category filter.
	 * @param string|null $type        Optional type filter (e.g. 'forum', 'qa').
	 * @param string|null $visibility  Optional explicit visibility filter.
	 * @param int         $per_page    Items per page.
	 * @param int         $offset      SQL OFFSET.
	 * @param string      $order_by    ORDER BY clause (pre-sanitised).
	 * @return array{spaces: object[], total: int}
	 */
	public static function list_visible(
		int $user_id,
		?int $category_id = null,
		?string $type = null,
		?string $visibility = null,
		int $per_page = 20,
		int $offset = 0,
		string $order_by = 'sort_order ASC, title ASC'
	): array {
		$db           = static::db();
		$spaces_table = static::table();

		// $members_table and $is_admin are gone with the membership JOIN and the
		// hand-rolled gate: listing_visibility_sql() resolves the admin case ("1=1")
		// and the membership subquery itself.

		$where  = array();
		$values = array();

		// Category filter.
		if ( $category_id ) {
			$where[]  = 's.category_id = %d';
			$values[] = $category_id;
		}

		// Type filter.
		if ( $type ) {
			$where[]  = 's.type = %s';
			$values[] = $type;
		}

		// Explicit visibility filter — NARROWS the result set within what the
		// viewer is already allowed to see. It must never stand in for the
		// viewer gate below: passing visibility=hidden|private must not skip the gate, or
		// every such space is exposed to guests.
		if ( $visibility ) {
			$where[]  = 's.visibility = %s';
			$values[] = $visibility;
		}

		// Viewer visibility gate — ALWAYS applied, regardless of any explicit
		// visibility filter, so the filter can only ever return a subset of the
		// caller's visible spaces (fail closed).
		//
		// One predicate, listing_visibility_sql(), serves every listing surface - the
		// community home, search, fulltext, REST and the app - so they cannot disagree.
		// `private` spaces are deliberately DISCOVERABLE (see that method's contract), and
		// the jetonomy_space_listing_visibility_sql filter reaches REST too.
		//
		// Content stays gated by content_visibility_sql(): a private space's CARD
		// is discoverable, its posts are not.
		[ $vis_where, $vis_values ] = self::listing_visibility_sql( $user_id, 's' );

		$where[] = $vis_where;
		$values  = array_merge( $values, $vis_values );

		/**
		 * Filter space query parameters before execution.
		 *
		 * @param array    $args    Query parameters: where (clauses), values, order_by, per_page, offset.
		 * @param int      $user_id Current user ID (0 for guests).
		 */
		$args = apply_filters(
			'jetonomy_spaces_query_args',
			array(
				'where'    => $where,
				'values'   => $values,
				'order_by' => $order_by,
				'per_page' => $per_page,
				'offset'   => $offset,
			),
			$user_id
		);

		$where    = $args['where'];
		$values   = $args['values'];
		$order_by = $args['order_by'];
		$per_page = (int) $args['per_page'];
		$offset   = (int) $args['offset'];

		$where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// No membership JOIN: listing_visibility_sql() expresses membership as a
		// subquery, so there is no LEFT JOIN and no DISTINCT to guard its fan-out: one
		// fewer join and no de-duplication pass on the space listing, which matters on
		// installs with thousands of spaces.

		// Count query.
		$count_sql = "SELECT COUNT(s.id) FROM {$spaces_table} s {$where_sql}";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $db->get_var( $db->prepare( $count_sql, ...$values ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $db->get_var( $count_sql );
		}

		// Data query.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data_sql = "SELECT s.* FROM {$spaces_table} s {$where_sql} ORDER BY s.{$order_by} LIMIT %d OFFSET %d";

		$all_values   = $values;
		$all_values[] = $per_page;
		$all_values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$spaces = $db->get_results( $db->prepare( $data_sql, ...$all_values ) ) ?: array();

		return array(
			'spaces' => $spaces,
			'total'  => $total,
		);
	}

	/**
	 * Per-request memo of DECODED settings arrays, keyed by space id.
	 *
	 * The row itself rides the space:{id} cache; this only avoids
	 * re-json_decode-ing the same blob 20x per space page (one per card).
	 * Reset inside bust_cache() — merge_settings() is read-modify-write, so
	 * a memo the existing bust cannot reach would silently revert the first
	 * of two settings writes in one process.
	 *
	 * @var array<int, array>
	 */
	private static array $settings_memo = array();

	/**
	 * Whether the memo reset is registered with Cache::flush().
	 *
	 * @var bool
	 */
	private static bool $settings_memo_registered = false;

	/**
	 * Return the decoded settings array for a space.
	 *
	 * @param int $id Space ID.
	 * @return array Settings key/value pairs, or empty array if none.
	 */
	public static function get_settings( int $id ): array {
		if ( ! self::$settings_memo_registered ) {
			self::$settings_memo_registered = true;
			Cache::register_memo_reset(
				static function (): void {
					self::$settings_memo = array();
				}
			);
		}

		if ( array_key_exists( $id, self::$settings_memo ) ) {
			return self::$settings_memo[ $id ];
		}

		$row = static::find( $id );
		if ( ! $row || empty( $row->settings ) ) {
			$decoded = array();
		} else {
			$decoded = json_decode( $row->settings, true );
			$decoded = is_array( $decoded ) ? $decoded : array();
		}

		self::$settings_memo[ $id ] = $decoded;
		return $decoded;
	}

	/**
	 * Resolve posts_per_page for a space: space setting → global setting → 20.
	 *
	 * @param int $space_id Space ID.
	 * @return int Resolved posts per page.
	 */
	public static function get_posts_per_page( int $space_id ): int {
		$space_settings = self::get_settings( $space_id );
		if ( ! empty( $space_settings['posts_per_page'] ) ) {
			return (int) $space_settings['posts_per_page'];
		}

		$global = get_option( 'jetonomy_settings', array() );
		return (int) ( $global['posts_per_page'] ?? 20 );
	}

	/**
	 * Merge incoming settings into a space's stored settings, normalized.
	 *
	 * Every writer (wp-admin AJAX, REST PATCH, CLI) goes through here so the
	 * stored JSON has one shape. Two rules it enforces, both learned the hard
	 * way:
	 *
	 * - MERGE, never replace. A caller sending one key must not wipe prefixes,
	 *   SEO overrides, or feature toggles it never mentioned.
	 * - A cleared `posts_per_page` DROPS the key instead of storing `""` or 0.
	 *   The front-end edit form sends `""` for "use the default"; storing that
	 *   left a phantom per-space value that read back as a limit of 1 on any
	 *   surface resolving it with `??`. Absent means
	 *   "fall through to global", and only absent says it unambiguously.
	 *
	 * @param int   $space_id Space ID.
	 * @param array $incoming Settings keys the caller wants to change.
	 * @return array Merged, normalized settings ready to encode.
	 */
	public static function merge_settings( int $space_id, array $incoming ): array {
		// Keys starting with '_' are written by the plugin itself, never by an editor.
		foreach ( array_keys( $incoming ) as $key ) {
			if ( str_starts_with( (string) $key, '_' ) ) {
				unset( $incoming[ $key ] );
			}
		}

		if ( isset( $incoming['prefixes'] ) && is_array( $incoming['prefixes'] ) ) {
			$clean_prefixes = array();
			foreach ( $incoming['prefixes'] as $prefix ) {
				$name  = sanitize_text_field( $prefix['name'] ?? '' );
				$color = sanitize_hex_color( $prefix['color'] ?? '' );
				if ( $name && $color ) {
					$clean_prefixes[] = array(
						'name'  => $name,
						'color' => $color,
					);
				}
			}
			$incoming['prefixes'] = $clean_prefixes;
		}

		// An explicitly empty restriction means "no per-space restriction", so the
		// key has to be REMOVED, not stored as ''. Permission_Engine Layer 4 uses
		// ! empty() so '' would behave correctly today, but a stored '' is a
		// phantom value that reads as configured, and the same merge carries it
		// forward forever.
		$clear_restrictions = array();
		foreach ( array( 'who_can_post', 'who_can_reply' ) as $restriction_key ) {
			if ( array_key_exists( $restriction_key, $incoming ) && '' === (string) $incoming[ $restriction_key ] ) {
				unset( $incoming[ $restriction_key ] );
				$clear_restrictions[] = $restriction_key;
			}
		}

		$clear_per_page = false;
		if ( array_key_exists( 'posts_per_page', $incoming ) ) {
			$per_page = $incoming['posts_per_page'];
			if ( null === $per_page || '' === $per_page || (int) $per_page <= 0 ) {
				unset( $incoming['posts_per_page'] );
				$clear_per_page = true;
			} else {
				$incoming['posts_per_page'] = max( 1, min( 100, (int) $per_page ) );
			}
		}

		$merged = array_merge( self::get_settings( $space_id ), $incoming );

		foreach ( $clear_restrictions as $restriction_key ) {
			unset( $merged[ $restriction_key ] );
		}

		// The key was cleared, so strip what the merge carried over from the
		// previously stored value — otherwise "clear this" silently no-ops.
		if ( $clear_per_page ) {
			unset( $merged['posts_per_page'] );
		}

		return $merged;
	}

	/**
	 * Just the valid `visibility` values, for validation and REST/ability enums.
	 *
	 * Derived from visibility_levels() so a level can never be offerable in a
	 * form but rejected by a validator, or the reverse.
	 *
	 * @return string[]
	 */
	public static function visibility_values(): array {
		return array_keys( self::visibility_levels() );
	}

	/**
	 * The visibility levels a space can have, with the copy shown to whoever
	 * picks one.
	 *
	 * The single source for every surface that offers the choice. This list was
	 * hand-written in three places (wp-admin, the front-end space form, and the
	 * new-space form) and two of them had drifted: they offered only public and
	 * private. That drift was not cosmetic — it silently published hidden
	 * spaces. A form that cannot represent `hidden` renders no selected option,
	 * so the browser falls back to the first one (public), and saving anything
	 * at all PATCHed visibility to public. Deriving the list from one place is
	 * what stops a fourth surface from drifting the same way.
	 *
	 * The descriptions carry the distinction the levels are named for: private
	 * is discoverable but unreadable, hidden is neither. Owners were asking
	 * support which one hides a space; the form should answer that itself.
	 *
	 * @return array<string,array{label:string,description:string}> Keyed by the
	 *         `visibility` enum in jt_spaces (schema: public|private|hidden).
	 */
	public static function visibility_levels(): array {
		return array(
			'public'  => array(
				'label'       => __( 'Public', 'jetonomy' ),
				'description' => sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'Anyone can find this %s and read it.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
			),
			'private' => array(
				'label'       => __( 'Private', 'jetonomy' ),
				'description' => sprintf( /* translators: 1: the singular Space label, lowercase, 2: the plural Member label, lowercase. */ __( 'Anyone can find this %1$s, but only %2$s can read it.', 'jetonomy' ), \Jetonomy\space_label( false, true ), \Jetonomy\jetonomy_label( 'member', true, true ) ),
			),
			'hidden'  => array(
				'label'       => __( 'Hidden', 'jetonomy' ),
				'description' => sprintf( /* translators: 1: the plural Member label, lowercase, 2: the singular Space label, lowercase. */ __( 'Only %1$s can find this %2$s. It is invite-only.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ), \Jetonomy\space_label( false, true ) ),
			),
		);
	}

	/**
	 * Space types a space can be. Mirrors the `type` ENUM in class-schema.php;
	 * MySQL silently stores '' for anything else, so every writer validates
	 * against this list rather than a local copy.
	 *
	 * @return string[]
	 */
	public static function valid_types(): array {
		return array( 'forum', 'qa', 'ideas', 'feed' );
	}

	/**
	 * Refuse a write whose `type` is not a valid space type.
	 *
	 * The one gate for every writer (REST, admin AJAX, CLI, Abilities,
	 * importers, integrations): without it MySQL coerces an unknown value to
	 * '' and the space loses every type-driven view.
	 *
	 * @param array $data Column data about to be written.
	 * @return \WP_Error|null Null when `type` is absent or valid.
	 */
	private static function check_type( array $data ): ?\WP_Error {
		if ( ! array_key_exists( 'type', $data ) || in_array( $data['type'], self::valid_types(), true ) ) {
			return null;
		}
		return new \WP_Error(
			'jetonomy_invalid_space_type',
			sprintf(
				/* translators: 1: the singular Space label, lowercase, 2: rejected type, 3: comma-separated list of valid types. */
				__( 'Invalid %1$s type "%2$s". Use one of: %3$s.', 'jetonomy' ),
				\Jetonomy\space_label( false, true ),
				is_scalar( $data['type'] ) ? (string) $data['type'] : gettype( $data['type'] ),
				implode( ', ', self::valid_types() )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * How a non-member gets into a space, from its visibility + join_policy.
	 *
	 * - 'invite'  : hidden, or invite-only - only an invite link admits.
	 * - 'request' : approval policy, or a private space - joining files a
	 *               JoinRequest for an admin to review.
	 * - 'open'    : anyone may join directly.
	 *
	 * The single statement of the rule SpaceMember::join() enforces, so the
	 * join gate and the request-queue cleanup in update() cannot disagree.
	 *
	 * @param string $visibility  'public' | 'private' | 'hidden'.
	 * @param string $join_policy 'open' | 'approval' | 'invite'.
	 * @return string 'invite' | 'request' | 'open'
	 */
	public static function join_mode( string $visibility, string $join_policy ): string {
		// shortcut: 'private' => request also covers rows saved before 2.0.2
		// normalised private+open to approval; drop it once no such rows can exist.
		if ( 'invite' === $join_policy || 'hidden' === $visibility ) {
			return 'invite';
		}
		if ( 'approval' === $join_policy || 'private' === $visibility ) {
			return 'request';
		}
		return 'open';
	}

	/**
	 * The join policy a viewer actually meets on this space: 'invite' |
	 * 'approval' | 'open', derived from join_mode() so templates, badges and the
	 * join endpoint can never disagree (a hidden space is invite-only, a private
	 * one never open).
	 *
	 * @param object $space Space row.
	 * @return string
	 */
	public static function effective_join_policy( object $space ): string {
		$mode = self::join_mode( (string) ( $space->visibility ?? 'public' ), (string) ( $space->join_policy ?? 'open' ) );
		return 'request' === $mode ? 'approval' : $mode;
	}

	/**
	 * Validate the cross-field rule between visibility and join_policy.
	 *
	 * Hidden spaces must be invite-only. A hidden space with an "open" or
	 * "approval" join_policy is a contract contradiction: the listing
	 * pretends the space does not exist, while the gate happily lets any
	 * logged-in user with the slug self-join. Logged-in users can discover
	 * hidden slugs via shared links, browser history, or the admin area,
	 * so the only sane combination is hidden + invite.
	 *
	 * Each individual enum is still validated by the caller. This helper
	 * focuses purely on the cross-field rule so callers can run it after
	 * their own enum normalization without re-implementing it six times.
	 *
	 * @param string $visibility  'public' | 'private' | 'hidden'.
	 * @param string $join_policy 'open' | 'approval' | 'invite'.
	 * @return true|\WP_Error True when valid; WP_Error with status 400
	 *                        and code 'jetonomy_invalid_combo' otherwise.
	 */
	public static function validate_visibility_join_policy( string $visibility, string $join_policy ) {
		if ( 'hidden' === $visibility && 'invite' !== $join_policy ) {
			return new \WP_Error(
				'jetonomy_invalid_combo',
				sprintf( /* translators: %s: the plural Space label, lowercase. */ __( 'Hidden %s must use the invite-only join policy.', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
				array( 'status' => 400 )
			);
		}
		return true;
	}


	// ── wp-admin listings ──

	/**
	 * The wp-admin Spaces screen. A column sort (orderby: title, post_count,
	 * member_count) wins; otherwise, inside one category with no
	 * other filter, the order is the manual one members see; otherwise A to Z.
	 *
	 * @param array{category_id?:int,type?:string,status?:string,search?:string,orderby?:string,order?:string} $filters Filters.
	 * @param int                                                                                              $limit   Max rows.
	 * @param int                                                                                              $offset  Offset.
	 * @return object[]
	 */
	public static function list_for_admin( array $filters, int $limit, int $offset ): array {
		[ $where, $args ] = self::admin_where( $filters );
		$sortable         = array( 'title', 'post_count', 'member_count' );
		$manual           = ! empty( $filters['category_id'] ) && empty( $filters['type'] ) && empty( $filters['status'] ) && empty( $filters['search'] );
		if ( in_array( $filters['orderby'] ?? '', $sortable, true ) ) {
			$dir   = 'DESC' === strtoupper( (string) ( $filters['order'] ?? '' ) ) ? 'DESC' : 'ASC';
			$order = "{$filters['orderby']} {$dir}, id {$dir}";
		} else {
			$order = $manual ? 'sort_order ASC, title ASC' : 'title ASC';
		}
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order is built from an allow-listed column and ASC/DESC.
				...array_merge( $args, array( $limit, $offset ) )
			)
		) ?: array();
	}

	/**
	 * How many spaces list_for_admin() pages through.
	 *
	 * @param array{category_id?:int,type?:string,status?:string} $filters Filters.
	 * @return int
	 */
	public static function count_for_admin( array $filters ): int {
		[ $where, $args ] = self::admin_where( $filters );
		$sql              = 'SELECT COUNT(*) FROM ' . static::table() . " WHERE {$where}";
		return (int) ( $args ? static::db()->get_var( static::db()->prepare( $sql, ...$args ) ) : static::db()->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only when $args.
	}

	/**
	 * A space with exactly this title in a category, so an admin writer (the
	 * setup wizard) reuses it instead of adding a twin.
	 *
	 * @param string $title       Space title.
	 * @param int    $category_id Category id.
	 * @return object|null
	 */
	public static function find_by_title_in_category( string $title, int $category_id ): ?object {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE category_id = %d AND title = %s ORDER BY id ASC LIMIT 1',
				$category_id,
				$title
			)
		);
		return $row ?: null;
	}

	/**
	 * Every space's id and title, A to Z, for filter dropdowns.
	 *
	 * @return object[]
	 */
	public static function list_titles(): array {
		return static::db()->get_results( 'SELECT id, title FROM ' . static::table() . ' ORDER BY title ASC' ) ?: array();
	}

	/**
	 * WHERE for the wp-admin Spaces screen. Unknown types or statuses are ignored.
	 *
	 * @param array{category_id?:int,type?:string,status?:string,search?:string} $f Filters.
	 * @return array{0:string,1:array}
	 */
	private static function admin_where( array $f ): array {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['category_id'] ) ) {
			$where[] = 'category_id = %d';
			$args[]  = (int) $f['category_id'];
		}
		if ( ! empty( $f['type'] ) && in_array( $f['type'], self::valid_types(), true ) ) {
			$where[] = 'type = %s';
			$args[]  = (string) $f['type'];
		}
		if ( ! empty( $f['status'] ) && in_array( $f['status'], array( 'active', 'archived', 'locked' ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $f['status'];
		}
		if ( isset( $f['search'] ) && '' !== (string) $f['search'] ) {
			$where[] = '(title LIKE %s OR slug LIKE %s)';
			$like    = '%' . static::db()->esc_like( (string) $f['search'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
		}
		return array( implode( ' AND ', $where ), $args );
	}


	/**
	 * Spaces a user may link to something else (a BuddyPress group): every
	 * space for a site admin, otherwise the spaces they run. A to Z.
	 *
	 * @param int[] $exclude    Space ids to leave out (already linked).
	 * @param int   $manager_id User who must be a space admin or moderator, or 0 for all spaces.
	 * @return object[] Rows with id, title, slug.
	 */
	public static function list_linkable( array $exclude, int $manager_id = 0 ): array {
		$exclude = $exclude ? array_map( 'absint', $exclude ) : array( 0 );
		$in      = implode( ',', $exclude );
		if ( $manager_id <= 0 ) {
			return static::db()->get_results( 'SELECT id, title, slug FROM ' . static::table() . " WHERE id NOT IN ({$in}) ORDER BY title ASC" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		}
		$members = \Jetonomy\table( 'space_members' );
		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list and table names.
				'SELECT s.id, s.title, s.slug FROM ' . static::table() . " s
				 INNER JOIN {$members} sm ON sm.space_id = s.id AND sm.user_id = %d AND sm.role IN ('admin', 'moderator')
				 WHERE s.id NOT IN ({$in})
				 ORDER BY s.title ASC",
				$manager_id
			)
		) ?: array();
	}

	/**
	 * Spaces whose cover or icon contains a text fragment (e.g. an upload's file path).
	 *
	 * @param string $fragment Literal text, LIKE-escaped here.
	 * @return int
	 */
	public static function count_images_containing( string $fragment ): int {
		$like = '%' . static::db()->esc_like( $fragment ) . '%';
		return (int) static::db()->get_var(
			static::db()->prepare( 'SELECT COUNT(*) FROM ' . static::table() . ' WHERE cover_image LIKE %s OR icon LIKE %s', $like, $like )
		);
	}

	/**
	 * Ids of every space a guest cannot read, any status.
	 *
	 * Private and hidden spaces, plus public spaces concealed by a private or
	 * hidden category - the same guest predicate content_visibility_sql()
	 * applies. These are the candidates for "a site moderator may not be able
	 * to read this" (Moderation_Permissions::unreadable_space_ids()); every
	 * other space is readable by any signed-in member, so the list stays short.
	 *
	 * @param bool $all Return every space id instead (a globally banned viewer).
	 * @return int[]
	 */
	public static function guest_unreadable_ids( bool $all = false ): array {
		[ $guest_sql ] = self::content_visibility_sql( 0 );
		$where         = $all ? '1=1' : "NOT ({$guest_sql})";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the guest predicate has no bind values.
		return array_map( 'intval', (array) static::db()->get_col( 'SELECT id FROM ' . static::table() . " WHERE {$where}" ) );
	}

	/**
	 * Every sitemap space id (active spaces a guest can list), ascending.
	 *
	 * @return int[]
	 */
	public static function sitemap_ids(): array {
		// visibility = 'public' stays explicit in all three sitemap queries: the
		// guest listing predicate also admits private spaces when guest teasers
		// are on, and a teaser card is not an invitation for crawlers.
		[ $vis ] = self::listing_visibility_sql( 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) static::db()->get_col( 'SELECT id FROM ' . static::table() . " WHERE {$vis} AND visibility = 'public' AND status = 'active' ORDER BY id ASC" ) );
	}

	/**
	 * Newest activity or edit across sitemap spaces.
	 *
	 * @return string UTC MySQL datetime, or '' when there are none.
	 */
	public static function sitemap_lastmod(): string {
		[ $vis ] = self::listing_visibility_sql( 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) static::db()->get_var( "SELECT MAX(GREATEST(COALESCE(last_activity_at,'1970-01-01'), COALESCE(updated_at,'1970-01-01'))) FROM " . static::table() . " WHERE {$vis} AND visibility = 'public' AND status = 'active'" );
	}

	/**
	 * One keyset page of sitemap spaces after an id.
	 *
	 * @param int $start_after Last id of the previous page.
	 * @param int $limit       Page size.
	 * @return object[] Rows with id, slug, last_activity_at, updated_at.
	 */
	public static function list_sitemap_page( int $start_after, int $limit ): array {
		[ $vis ] = self::listing_visibility_sql( 0 );
		return (array) static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT id, slug, last_activity_at, updated_at FROM ' . static::table() . " WHERE {$vis} AND visibility = 'public' AND status = 'active' AND id > %d ORDER BY id ASC LIMIT %d",
				$start_after,
				$limit
			)
		);
	}

	/**
	 * Active public spaces with the most topics first, optionally in one category.
	 *
	 * @param int $limit       Max rows.
	 * @param int $category_id Category, or 0 for all.
	 * @return object[]
	 */
	public static function list_public_by_post_count( int $limit, int $category_id = 0 ): array {
		$where = "status = 'active' AND visibility = 'public'";
		$args  = array();
		if ( $category_id > 0 ) {
			$where .= ' AND category_id = %d';
			$args[] = $category_id;
		}
		$args[] = $limit;

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . " WHERE {$where} ORDER BY post_count DESC, id ASC LIMIT %d",
				...$args
			)
		) ?: array();
	}

	/**
	 * Title and slug of active public spaces, A to Z, for the nav-menu picker.
	 *
	 * @param int $limit Max rows.
	 * @return object[]
	 */
	public static function list_public_titles( int $limit = 50 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT title, slug FROM ' . static::table() . " WHERE visibility = 'public' AND status = 'active' ORDER BY title ASC LIMIT %d",
				$limit
			)
		) ?: array();
	}

	/**
	 * Sum of the denormalised topic counters (cheaper than counting topics).
	 *
	 * @param int|null $viewer Null = every space; a user id (0 = guest) counts
	 *                         only the spaces whose topics that viewer can read.
	 * @return int
	 */
	public static function sum_post_counts( ?int $viewer = null ): int {
		if ( null === $viewer ) {
			return (int) static::db()->get_var( 'SELECT COALESCE(SUM(post_count),0) FROM ' . static::table() );
		}
		[ $where, $values ] = self::content_visibility_sql( $viewer );
		$sql                = 'SELECT COALESCE(SUM(post_count),0) FROM ' . static::table() . " WHERE {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is literal SQL plus %d placeholders from content_visibility_sql().
		return (int) static::db()->get_var( $values ? static::db()->prepare( $sql, ...$values ) : $sql );
	}
}
