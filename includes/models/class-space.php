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
		$keys = [ "space:{$id}" ];
		if ( ! empty( $slug ) ) {
			$keys[] = "space:slug:{$slug}";
		}
		Cache::delete_many( $keys );
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
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$slug_changing = ! empty( $data['slug'] );
		$old_slug      = $slug_changing ? ( parent::find( $id )->slug ?? null ) : null;

		$result = parent::update( $id, $data );

		self::bust_cache( $id );
		if ( $slug_changing && $data['slug'] !== $old_slug ) {
			$keys = [ "space:slug:{$data['slug']}" ];
			if ( $old_slug ) {
				$keys[] = "space:slug:{$old_slug}";
			}
			Cache::delete_many( $keys );
		}
		return $result;
	}

	/**
	 * Delete a space, then invalidate its cache (both keys).
	 *
	 * Model::delete() does not touch the cache, so without this override a
	 * deleted space kept serving from space:{id} / space:slug:{slug} until the
	 * TTL. Deletion funnels through here (admin AJAX handler, journeys), so this
	 * is the single chokepoint.
	 *
	 * @param int $id Space ID.
	 * @return bool|\WP_Error Mirrors Model::delete() — do not narrow to bool, or a
	 *                        WP_Error return would TypeError and callers that
	 *                        is_wp_error() the result become dead code.
	 */
	public static function delete( int $id ): bool|\WP_Error {
		$slug   = parent::find( $id )->slug ?? null;
		$result = parent::delete( $id );
		self::bust_cache( $id, $slug );
		return $result;
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
	 * @return int Inserted row ID.
	 */
	public static function create( array $data, ?int $creator_user_id = null ): int {
		$now  = now();
		$data = array_merge(
			[
				'created_at' => $now,
				'updated_at' => $now,
			],
			$data
		);

		$id = static::insert( $data );

		if ( $id <= 0 ) {
			return 0;
		}

		if ( ! empty( $data['category_id'] ) ) {
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
			return [ '1=1', [] ];
		}

		if ( $user_id <= 0 ) {
			return [ "{$col}visibility = 'public'", [] ];
		}

		$members_table = \Jetonomy\table( 'space_members' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $members_table is a trusted prefixed name.
		$fragment = "({$col}visibility = 'public' OR {$col}id IN (SELECT space_id FROM {$members_table} WHERE user_id = %d))";
		return [ $fragment, [ $user_id ] ];
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
	 * Replaces the old "public OR member" listing rule, under which `private`
	 * spaces were as invisible as `hidden` ones — collapsing two distinct
	 * visibility states into one (Basecamp: "Private+open spaces invisible").
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
			$result = [ '1=1', [] ];
		} elseif ( $user_id <= 0 ) {
			$result = [ "{$col}visibility = 'public'", [] ];
		} else {
			$members_table = \Jetonomy\table( 'space_members' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $members_table is a trusted prefixed name.
			$fragment = "({$col}visibility IN ('public','private') OR {$col}id IN (SELECT space_id FROM {$members_table} WHERE user_id = %d))";
			$result   = [ $fragment, [ $user_id ] ];
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
	 * Should this space's EXISTENCE be concealed from the viewer?
	 *
	 * A `hidden` space promises "only members can find this space". Listings
	 * already honour that (listing_visibility_sql), but a direct URL used to
	 * answer HTTP 200 with the space's title — disclosing that the space
	 * exists and what it is called (Basecamp 10105630168; product decision:
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
		if ( 'hidden' !== ( $space->visibility ?? '' ) ) {
			return false;
		}
		if ( user_can( (int) $user_id, 'manage_options' ) ) {
			return false;
		}
		return ! ( $user_id && SpaceMember::is_member( (int) $space->id, (int) $user_id ) );
	}

	/**
	 * List top-level spaces in a category for a given viewer.
	 *
	 * @param int      $category_id Category row ID.
	 * @param int|null $user_id     Viewer ID; null = current user.
	 * @return object[]
	 */
	public static function list_by_category( int $category_id, ?int $user_id = null ): array {
		[ $vis_where, $vis_values ] = self::visibility_predicate_for( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from visibility_predicate_for() with literal SQL + %d placeholders.
		$sql    = 'SELECT * FROM ' . static::table() . " WHERE category_id = %d AND (parent_id IS NULL OR parent_id = 0) AND {$vis_where} ORDER BY sort_order ASC, title ASC";
		$values = array_merge( [ $category_id ], $vis_values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above with bound %d values.
		return static::db()->get_results( static::db()->prepare( $sql, ...$values ) ) ?: [];
	}

	/**
	 * List uncategorized top-level spaces visible to a given viewer.
	 *
	 * @param int|null $user_id Viewer ID; null = current user.
	 * @return object[]
	 */
	public static function list_uncategorized( ?int $user_id = null ): array {
		[ $vis_where, $vis_values ] = self::visibility_predicate_for( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $vis_where comes from visibility_predicate_for() with literal SQL + %d placeholders.
		$sql = 'SELECT * FROM ' . static::table() . " WHERE (category_id IS NULL OR category_id = 0) AND (parent_id IS NULL OR parent_id = 0) AND {$vis_where} ORDER BY sort_order ASC, title ASC";

		if ( empty( $vis_values ) ) {
			return static::db()->get_results( $sql ) ?: [];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared with bound %d values.
		return static::db()->get_results( static::db()->prepare( $sql, ...$vis_values ) ) ?: [];
	}

	/**
	 * List child spaces for a given parent space.
	 *
	 * @param int $parent_id
	 * @return object[]
	 */
	public static function list_children( int $parent_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE parent_id = %d ORDER BY sort_order ASC, title ASC',
				$parent_id
			)
		) ?: [];
	}

	/**
	 * Increment the post_count and update activity timestamps.
	 *
	 * @param int $id Space ID.
	 * @param int $by Amount to add (use negative value to decrement).
	 */
	public static function increment_post_count( int $id, int $by = 1 ): void {
		$now = now();
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET post_count = GREATEST(post_count + %d, 0), last_activity_at = %s, updated_at = %s WHERE id = %d',
				$by,
				$now,
				$now,
				$id
			)
		);
		self::bust_cache( $id );
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
			) ?: [];
		}
		return static::db()->get_results(
			static::db()->prepare( $base, $status )
		) ?: [];
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

		$where  = [];
		$values = [];

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
		// viewer gate below: passing visibility=hidden|private previously
		// skipped the gate entirely and exposed every such space to guests.
		if ( $visibility ) {
			$where[]  = 's.visibility = %s';
			$values[] = $visibility;
		}

		// Viewer visibility gate — ALWAYS applied, regardless of any explicit
		// visibility filter, so the filter can only ever return a subset of the
		// caller's visible spaces (fail closed).
		//
		// This used to hand-roll the rule ("public OR member") while every other
		// listing surface — the community home, search, fulltext — went through
		// listing_visibility_sql(). The two drifted: `private` spaces are
		// deliberately DISCOVERABLE (see that method's contract), so /community/
		// showed them while REST /spaces did not. Same question, two answers,
		// measured live: 32 spaces rendered vs 26 returned for the same viewer.
		// One predicate now, so REST, the app, and the web cannot disagree again —
		// and the jetonomy_space_listing_visibility_sql filter finally reaches REST
		// too, instead of silently applying to only half the surfaces.
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
		// subquery, so the LEFT JOIN that used to back the inline gate is gone —
		// and with it the DISTINCT that existed only to guard the join's fan-out.
		// One fewer join and no de-duplication pass on the space listing, which
		// matters on the installs that have thousands of spaces.

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
		$spaces = $db->get_results( $db->prepare( $data_sql, ...$all_values ) ) ?: [];

		return [
			'spaces' => $spaces,
			'total'  => $total,
		];
	}

	/**
	 * Return the decoded settings array for a space.
	 *
	 * @param int $id Space ID.
	 * @return array Settings key/value pairs, or empty array if none.
	 */
	public static function get_settings( int $id ): array {
		$row = static::find( $id );
		if ( ! $row || empty( $row->settings ) ) {
			return [];
		}

		$decoded = json_decode( $row->settings, true );
		return is_array( $decoded ) ? $decoded : [];
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

		$global = get_option( 'jetonomy_settings', [] );
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
	 *   surface resolving it with `??` (Basecamp 10118693115). Absent means
	 *   "fall through to global", and only absent says it unambiguously.
	 *
	 * @param int   $space_id Space ID.
	 * @param array $incoming Settings keys the caller wants to change.
	 * @return array Merged, normalized settings ready to encode.
	 */
	public static function merge_settings( int $space_id, array $incoming ): array {
		if ( isset( $incoming['prefixes'] ) && is_array( $incoming['prefixes'] ) ) {
			$clean_prefixes = [];
			foreach ( $incoming['prefixes'] as $prefix ) {
				$name  = sanitize_text_field( $prefix['name'] ?? '' );
				$color = sanitize_hex_color( $prefix['color'] ?? '' );
				if ( $name && $color ) {
					$clean_prefixes[] = [
						'name'  => $name,
						'color' => $color,
					];
				}
			}
			$incoming['prefixes'] = $clean_prefixes;
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
		return [
			'public'  => [
				'label'       => __( 'Public', 'jetonomy' ),
				'description' => __( 'Anyone can find this space and read it.', 'jetonomy' ),
			],
			'private' => [
				'label'       => __( 'Private', 'jetonomy' ),
				'description' => __( 'Anyone can find this space, but only members can read it.', 'jetonomy' ),
			],
			'hidden'  => [
				'label'       => __( 'Hidden', 'jetonomy' ),
				'description' => __( 'Only members can find this space. It is invite-only.', 'jetonomy' ),
			],
		];
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
				__( 'Hidden spaces must use the invite-only join policy.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}
		return true;
	}
}
