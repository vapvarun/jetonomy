<?php
/**
 * Base REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Cache;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\Models\UserProfile;

abstract class Base_Controller extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'jetonomy/v1';
	}

	/**
	 * Check if current user can perform action in space.
	 */
	protected function check_permission( string $action, ?int $space_id = null ): bool {
		return Permission_Engine::can( get_current_user_id(), $action, $space_id );
	}

	/**
	 * Standard permission denied error.
	 */
	protected function permission_error(): WP_Error {
		return new WP_Error(
			'jetonomy_forbidden',
			__( 'You do not have permission to perform this action.', 'jetonomy' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Standard not found error.
	 */
	protected function not_found( string $what = 'Resource' ): WP_Error {
		return new WP_Error(
			'jetonomy_not_found',
			/* translators: %s: the singular space label. */
			sprintf( __( '%s not found.', 'jetonomy' ), $what ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Standard validation error.
	 */
	protected function validation_error( string $message ): WP_Error {
		return new WP_Error(
			'jetonomy_validation',
			$message,
			[ 'status' => 400 ]
		);
	}

	/**
	 * Is this user trusted enough that we should skip Akismet / content spam checks?
	 *
	 * Site admins (manage_options) and space admins/moderators are whitelisted:
	 * they own the moderation workflow, so running their writes through Akismet
	 * creates false positives and blocks staff responses. Lower-trust members
	 * still get the full spam pipeline.
	 *
	 * Thin alias over the engine's privilege check so there is exactly one
	 * definition of "space staff" (1.5.0 consolidation, audit B).
	 */
	protected function author_bypasses_spam_check( int $user_id, int $space_id ): bool {
		return \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $user_id, $space_id );
	}

	/**
	 * Should this write be held for moderation under the space's
	 * require_approval setting?
	 *
	 * One shared definition for the post + reply create paths (1.5.0
	 * consolidation — the previous copy-pasted blocks also checked
	 * current_user_can() instead of the AUTHOR's capabilities, which
	 * diverges on imports and on-behalf writes; audit B).
	 *
	 * @param string $requested_status Status the caller asked for ('' = default publish).
	 * @param int    $space_id         Space ID.
	 * @param int    $author_id        Content author user ID.
	 * @return bool True when the content must be created as `pending`.
	 */
	protected function should_hold_for_approval( string $requested_status, int $space_id, int $author_id ): bool {
		if ( '' !== $requested_status && 'publish' !== $requested_status ) {
			return false; // Drafts/scheduled content is not publish-bound yet.
		}

		$settings = \Jetonomy\Models\Space::get_settings( $space_id );
		if ( empty( $settings['require_approval'] ) ) {
			return false;
		}

		return ! \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $author_id, $space_id );
	}

	/**
	 * Validate and normalize a backdate string (e.g. `published_at`) to a UTC
	 * `Y-m-d H:i:s` for storage.
	 *
	 * Timezone contract (matches the WordPress core post scheduler):
	 *  - A naive wall-clock value (`Y-m-d H:i:s`, `Y-m-dTH:i:s`, or date-only
	 *    `Y-m-d`) is interpreted in the SITE timezone — the Settings -> General
	 *    timezone via {@see wp_timezone()} — then converted to UTC. So "3 PM"
	 *    means 3 PM in the site's timezone regardless of the author's location
	 *    or the server's clock.
	 *  - A value carrying an explicit offset or `Z` (`...+05:30`, `...Z`) is an
	 *    absolute instant; its own offset is honoured and converted to UTC.
	 *
	 * Returns `null` when the input is empty (treat as "use default"), or
	 * `WP_Error` when non-empty but unparsable.
	 *
	 * Gated to users who can `manage_options` — normal authors cannot forge dates via
	 * the public API. Moderators with `edit_others_posts` also pass via the caller check.
	 */
	protected function sanitize_backdate( $raw ): string|null|WP_Error {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return null;
		}

		$utc = new \DateTimeZone( 'UTC' );

		// An explicit timezone designator (trailing `Z` or `±HH:MM` / `±HHMM`)
		// makes the value an absolute instant — parse in UTC and let the offset
		// in the string do the work. Everything else is a naive wall-clock value
		// interpreted in the SITE timezone so the WP timezone setting is always
		// respected.
		$has_tz     = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $raw );
		$parse_zone = $has_tz ? $utc : wp_timezone();

		// Leading `!` resets every field not present in the format to the Unix
		// epoch, so a date-only input ('Y-m-d') yields 00:00:00 instead of
		// leaking the server's current time-of-day.
		$formats = $has_tz
			? array( '!Y-m-d\TH:i:sP', '!Y-m-d\TH:i:s\Z' )
			: array( '!Y-m-d H:i:s', '!Y-m-d\TH:i:s', '!Y-m-d' );

		foreach ( $formats as $format ) {
			$dt = \DateTimeImmutable::createFromFormat( $format, $raw, $parse_zone );
			if ( $dt instanceof \DateTimeImmutable ) {
				return $dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
			}
		}

		// Fallback: hand the raw string to the parser, still anchoring a naive
		// value to the site timezone before normalizing to UTC.
		try {
			$dt = new \DateTimeImmutable( $raw, $parse_zone );
			return $dt->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'jetonomy_invalid_published_at',
				__( 'Invalid published_at: expected Y-m-d H:i:s or ISO 8601.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}
	}

	/**
	 * Build a paginated response with cursor support.
	 */
	protected function paginated_response( array $items, array $meta = [] ): WP_REST_Response {
		// has_more is (offset + count) < total. If a paginating caller passes total
		// but forgets offset, offset silently reads 0 and every page compares
		// against page 1 — has_more is stuck true, a "Load more" that never ends.
		// The per-space moderation route shipped exactly this (Basecamp 10105626990).
		//
		// offset is not optional when the response actually paginates, so say so out
		// loud rather than assume it. The fix is not "add the missing line to one
		// route" — that leaves the next route free to forget — but making the
		// omission impossible to miss in dev/CI. Costs nothing in production.
		//
		// Gated on count < total on purpose: a non-paginated list passes
		// total = count(items) (one page), where offset cannot change has_more, so
		// warning there would be a false positive that teaches developers to ignore
		// the warning. Only the genuinely-paginated, offset-missing case trips it.
		if ( isset( $meta['total'] ) && ! array_key_exists( 'offset', $meta ) && count( $items ) < (int) $meta['total'] ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( "paginated_response() paginates (count < total) but was given no 'offset'; has_more will be stuck true past page 1. Pass 'offset' from get_pagination()." ),
				'1.8.0'
			);
		}

		// cursor_next is the NEXT OFFSET, never a row id.
		//
		// It used to be the last item's id, which is only meaningful if the query
		// filters on `id > cursor`. None of our list queries can: they sort by
		// is_sticky/vote_score/last_reply_at/created_at (and `hot` is computed at
		// query time and has no column at all), so an id cursor cannot advance a
		// keyset. Worse, the id filter ran as `id > %d` against a DESC sort — the
		// filter direction contradicted the sort direction, so every "next page"
		// returned the top of the list again and the client looped forever
		// (Basecamp 10161324385 / 10161324235).
		//
		// Offset is the only scheme that works uniformly here, so it lives in the
		// shared helper rather than being re-derived per controller — that
		// divergence is exactly how /feed ended up fixed while /users/{id}/posts
		// and /spaces/{id}/posts stayed broken.
		$offset   = (int) ( $meta['offset'] ?? 0 );
		$has_more = isset( $meta['total'] ) ? ( $offset + count( $items ) ) < (int) $meta['total'] : false;

		$response = new WP_REST_Response(
			[
				'data' => $items,
				'meta' => array_merge(
					[
						'count'       => count( $items ),
						'has_more'    => $has_more,
						'cursor_next' => $has_more ? $offset + count( $items ) : null,
					],
					$meta
				),
			]
		);

		if ( isset( $meta['total'] ) ) {
			$response->header( 'X-WP-Total', (string) $meta['total'] );
		}

		return $response;
	}

	/**
	 * Get pagination params from request (supports cursor + legacy offset).
	 */
	protected function get_pagination( WP_REST_Request $request ): array {
		$after  = (int) ( $request->get_param( 'after' ) ?? 0 );
		$offset = (int) ( $request->get_param( 'offset' ) ?? 0 );

		// `after` is the forward cursor handed back by paginated_response(), and it
		// carries an OFFSET (see the note there). Fold it into `offset` here so every
		// controller gets cursor support for free by reading `offset` alone — several
		// (e.g. /users/{id}/posts) only ever read `offset`, so a client paginating
		// with `after` was pinned to page 1 forever. `after` wins when both are sent.
		return [
			'limit'  => (int) ( $request->get_param( 'limit' ) ?? 20 ),
			'offset' => $after > 0 ? $after : $offset,
			'sort'   => $request->get_param( 'sort' ) ?? 'latest',
			'after'  => $after,
			'before' => (int) ( $request->get_param( 'before' ) ?? 0 ),
		];
	}

	/**
	 * Get current user ID or return error if not logged in.
	 */
	protected function require_auth(): int|WP_Error {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error(
				'jetonomy_unauthorized',
				__( 'You must be logged in.', 'jetonomy' ),
				[ 'status' => 401 ]
			);
		}
		return $user_id;
	}

	/**
	 * Per-IP rate limit shared across every REST mutation that needs one
	 * (login / register / lost-password on Auth_Controller, delete-account
	 * on Users_Controller, …). Promoted here from Auth_Controller (1.7.1) so
	 * every controller can enforce the same throttle without a duplicate
	 * copy — the limiter logic must never drift between callers.
	 *
	 * Defaults to 5 attempts per minute. Caller can override for tighter or
	 * looser buckets.
	 *
	 * @param string $bucket  Unique bucket key, e.g. 'login' / 'delete_account'.
	 * @param int    $max     Max attempts in the window.
	 * @param int    $seconds Window size in seconds.
	 * @return bool True when within limit, false when exhausted.
	 */
	protected static function check_rate_limit( string $bucket, int $max = 5, int $seconds = MINUTE_IN_SECONDS ): bool {
		$ip  = \Jetonomy\client_ip() ?: 'unknown';
		$key = 'jt_auth_' . $bucket . '_' . md5( $ip );
		$now = time();

		// Store BOTH the hit count AND a fixed expiry timestamp in the
		// transient value, then re-`set_transient` with only the REMAINING
		// seconds. Calling `set_transient($key, $hits+1, $seconds)` on every
		// hit extends the window every time, so an attacker pacing themselves
		// under $max could go indefinitely. Now the TTL collapses toward
		// $expires_at on every increment, the window is fixed once, and the
		// throttle is real.
		$record = get_transient( $key );
		if ( ! is_array( $record ) || ! isset( $record['expires_at'], $record['hits'] ) || $now >= (int) $record['expires_at'] ) {
			set_transient(
				$key,
				array(
					'hits'       => 1,
					'expires_at' => $now + $seconds,
				),
				$seconds
			);
			return true;
		}

		if ( (int) $record['hits'] >= $max ) {
			return false;
		}

		$remaining = max( 1, (int) $record['expires_at'] - $now );
		set_transient(
			$key,
			array(
				'hits'       => (int) $record['hits'] + 1,
				'expires_at' => (int) $record['expires_at'],
			),
			$remaining
		);
		return true;
	}

	/**
	 * Standard pagination args for route registration — supports cursor and legacy offset.
	 */
	public function get_collection_params(): array {
		return [
			'limit'  => [
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			],
			'after'  => [
				'type'        => 'integer',
				'default'     => 0,
				'description' => 'Forward cursor: send back the `meta.cursor_next` from the previous page. Opaque to clients — treat it as a token, not a row ID.',
			],
			'before' => [
				'type'        => 'integer',
				'default'     => 0,
				'description' => 'Return items before this ID',
			],
			'offset' => [
				'type'        => 'integer',
				'default'     => 0,
				'minimum'     => 0,
				'description' => 'Legacy offset-based pagination (use after/before instead)',
			],
			'sort'   => [
				'type'    => 'string',
				'default' => 'latest',
				'enum'    => [ 'latest', 'popular', 'oldest', 'newest' ],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Batch / eager-loading helpers
	// -------------------------------------------------------------------------

	/**
	 * Batch-fetch WP user rows for a set of IDs, using the object cache.
	 *
	 * @param int[] $ids
	 * @return array<int, object> Keyed by user ID.
	 */
	/**
	 * Attach reporter + resolver identity to a page of moderation flags.
	 *
	 * Lives here because there are TWO moderation queues — the global one
	 * (/moderation/flags) and the per-space one (/spaces/{id}/moderation/flags) —
	 * showing the same rows to the same moderator. This enrichment was written
	 * inline in the global controller, so the per-space screen never got it and
	 * rendered "Reported by unknown" long after the global screen was fixed
	 * (Basecamp 10092652706, 10092724637). One implementation, both callers: the
	 * next queue cannot inherit the bug by being written somewhere else.
	 *
	 * The raw flag row carries only reporter_id. A moderation queue that cannot
	 * name the reporter is close to useless — you cannot judge a report without
	 * knowing who filed it.
	 *
	 * Batch-loaded on purpose: one lookup for the whole page, never a
	 * get_userdata() inside the loop. These pages are 20-50 rows.
	 *
	 * @param object[] $flags Flag rows, mutated in place.
	 * @return object[] The same rows, enriched.
	 */
	protected function enrich_flag_actors( array $flags ): array {
		$user_ids = [];
		foreach ( $flags as $flag ) {
			if ( ! empty( $flag->reporter_id ) ) {
				$user_ids[] = (int) $flag->reporter_id;
			}
			if ( ! empty( $flag->resolved_by ) ) {
				$user_ids[] = (int) $flag->resolved_by;
			}
		}

		$users = $this->batch_load_users( $user_ids );

		foreach ( $flags as $flag ) {
			$reporter_id = (int) ( $flag->reporter_id ?? 0 );
			$reporter    = $users[ $reporter_id ] ?? null;
			$resolver    = $users[ (int) ( $flag->resolved_by ?? 0 ) ] ?? null;

			$flag->reporter = $reporter
				? [
					'id'           => $reporter_id,
					'display_name' => $reporter->display_name ?? '',
					'user_login'   => $reporter->user_login ?? '',
					'avatar_url'   => get_avatar_url( $reporter_id, [ 'size' => 48 ] ),
				]
				: null;

			$flag->resolved_by_name = $resolver->display_name ?? '';
		}

		return $flags;
	}

	protected function batch_load_users( array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}
		$ids = array_unique( array_map( 'intval', $ids ) );

		$cached  = [];
		$missing = [];

		foreach ( $ids as $id ) {
			$user = Cache::get( "user:{$id}" );
			if ( false !== $user ) {
				$cached[ $id ] = $user;
			} else {
				$missing[] = $id;
			}
		}

		if ( ! empty( $missing ) ) {
			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->users} WHERE ID IN ({$placeholders})", ...$missing ) );
			foreach ( $rows as $row ) {
				$cached[ (int) $row->ID ] = $row;
				Cache::set( "user:{$row->ID}", $row, 300 );
			}
		}

		// Warm what the CALLERS reach for next, not just what we return.
		//
		// This method's own batching was always real — one WHERE ID IN (...), no
		// get_userdata() in a loop. But every caller then builds an avatar in the
		// same loop, and get_avatar_url() -> pre_get_avatar_data -> Avatar reaches
		// for UserProfile + the core user/meta caches, none of which this had
		// filled. So the batch saved one query and the loop spent three per person:
		// measured cold on /moderation/flags, 1 reporter = 6 queries, 20 = 63.
		//
		// It hid for two reasons worth remembering. The cost lives three layers
		// below the loop that causes it, so the loop looks innocent; and it only
		// appears COLD — measured warm, in a process that has already seen those
		// users, the same code reads clean. That is how "batch-loaded, no N+1"
		// passed review twice, mine included: a fixture where every row shared one
		// reporter cannot see a per-person cost at all (Basecamp 10105928436).
		//
		// Priming here rather than in each caller: this is the one place that
		// already knows the whole id set, and a fix living in the callers is a fix
		// the next caller will not get.
		// $ids is non-empty here — the empty guard at the top already returned.
		// Core: fills the user + usermeta caches in two queries for the set.
		cache_users( $ids );
		// Ours: fills UserProfile's cache in one, which Avatar reads per person.
		UserProfile::prime( $ids );

		return $cached;
	}

	/**
	 * Batch-fetch Jetonomy user-profile rows for a set of user IDs.
	 *
	 * @param int[] $ids
	 * @return array<int, object> Keyed by user_id.
	 */
	protected function batch_load_profiles( array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}
		$ids = array_unique( array_map( 'intval', $ids ) );

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . \Jetonomy\table( 'user_profiles' ) . " WHERE user_id IN ({$placeholders})", ...$ids ) );

		$map = [];
		foreach ( $rows as $row ) {
			$map[ (int) $row->user_id ] = $row;
		}
		return $map;
	}

	/**
	 * Seed viewer-relative state (is_bookmarked, viewer_vote) onto a list of
	 * POST rows in two batched queries, so prepare_post() can read the
	 * pre-resolved value instead of running a per-row lookup (N+1).
	 *
	 * Lives on the base class (moved from Posts_Controller, plan WP3.4) so
	 * the search and user-posts lists can batch too. TYPE-GATED to post
	 * rows: the vote map is keyed object_type='post', and search's reply/
	 * space rows carry their own `id` column — enriching those would pin a
	 * false "you voted" state onto any reply whose id collides with a post
	 * id the viewer voted on (safety review, WP3.4). Rows without space_id
	 * are not post rows; bail rather than mislabel.
	 *
	 * No-op for logged-out callers — prepare_post() then falls back to the
	 * safe defaults (false / 0).
	 *
	 * @since 1.6.0
	 * @param object[] $posts Post row objects (mutated in place).
	 * @return object[] The same array with viewer_vote + is_bookmarked set.
	 */
	protected function enrich_viewer_state( array $posts ): array {
		$uid = get_current_user_id();
		if ( ! $uid || empty( $posts ) ) {
			return $posts;
		}

		foreach ( $posts as $p ) {
			if ( ! is_object( $p ) || ! property_exists( $p, 'space_id' ) ) {
				return $posts;
			}
		}

		$ids = array_map( static fn( $p ) => (int) $p->id, $posts );

		$bookmarked = array_fill_keys(
			\Jetonomy\Models\Bookmark::bookmarked_ids( $uid, $ids ),
			true
		);
		$votes      = \Jetonomy\Models\Vote::user_votes_map( $uid, 'post', $ids );
		// Warms the Flag memo for the page so prepare_post()'s viewer_flagged
		// costs one query per page rather than one per row.
		$flagged = \Jetonomy\Models\Flag::reporter_flag_map( $uid, 'post', $ids );
		// Warm the subscription memo so prepare_post()'s is_subscribed flag
		// (plan WP6.2) costs zero per-row queries on every list path.
		\Jetonomy\Models\Subscription::warm_viewer_subscriptions( $uid, 'post', $ids );

		foreach ( $posts as $post ) {
			$pid                  = (int) $post->id;
			$post->is_bookmarked  = isset( $bookmarked[ $pid ] );
			$post->viewer_vote    = isset( $votes[ $pid ] ) ? (int) $votes[ $pid ] : 0;
			$post->viewer_flagged = isset( $flagged[ $pid ] );
		}

		return $posts;
	}

	/**
	 * Enrich a list of post/reply objects with author data in a single batch.
	 *
	 * Skips enrichment if the data already exists on the object (e.g., previously
	 * enriched items are passed to prepare_* methods individually).
	 *
	 * @param array  $items      Array of objects or associative arrays.
	 * @param string $author_key The field name that holds the author user ID.
	 * @return array The same array with author fields merged in.
	 */
	protected function enrich_with_author( array $items, string $author_key = 'author_id' ): array {
		$author_ids = array_unique(
			array_filter(
				array_map(
					function ( $item ) use ( $author_key ) {
						return is_object( $item )
							? (int) ( $item->$author_key ?? 0 )
							: (int) ( $item[ $author_key ] ?? 0 );
					},
					$items
				)
			)
		);

		$users    = $this->batch_load_users( $author_ids );
		$profiles = $this->batch_load_profiles( $author_ids );

		foreach ( $items as &$item ) {
			$uid     = is_object( $item ) ? (int) $item->$author_key : (int) $item[ $author_key ];
			$user    = $users[ $uid ] ?? null;
			$profile = $profiles[ $uid ] ?? null;

			// author_name routes through the SAME seam every other render
			// surface uses (\Jetonomy\Author::for_display()) so a tombstoned
			// (author_id = 0, deleted-account) row reads "[deleted]" here too,
			// instead of drifting to "Anonymous" the way this list endpoint
			// used to render it. Passing the already-batch-loaded $user avoids
			// a redundant get_userdata() per row (author_id > 0 case).
			$item_obj    = is_object( $item ) ? $item : (object) $item;
			$author_name = \Jetonomy\Author::for_display( $uid, $item_obj, $user )['name'];

			$enrichment = [
				'author_name'         => $author_name ?: __( 'Anonymous', 'jetonomy' ),
				// display_url() returns '' when the member has no real avatar, so
				// API clients (mobile app) render initials from author_name — the
				// same fallback the web templates use. Never the Gravatar mystery-man.
				'author_avatar'       => $user ? \Jetonomy\Avatar::display_url( $uid, 64 ) : '',
				'author_login'        => $user ? $user->user_login : '',
				'trust_level'         => $profile ? (int) $profile->trust_level : 0,
				'reputation'          => $profile ? (int) $profile->reputation : 0,
				'profile_url'         => $uid ? \Jetonomy\get_profile_url( $uid ) : '',
				// Presence source for API clients: the app derives an "online" dot
				// from this (last_seen_at within 5 min), matching the web's badge.
				'author_last_seen_at' => $profile ? $profile->last_seen_at : null,
			];

			if ( is_object( $item ) ) {
				foreach ( $enrichment as $k => $v ) {
					$item->$k = $v;
				}
			} else {
				$item = array_merge( $item, $enrichment );
			}
		}

		return $items;
	}

	/**
	 * Cast the numeric columns of a raw DB row to int.
	 *
	 * Every wpdb column comes back as a string, so a row handed straight to
	 * json_encode ships `"id":"1","post_count":"12"`. A typed client doing
	 * `post_count > 0` or sorting numerically then gets wrong answers
	 * (Basecamp 10161324553).
	 *
	 * For rows that have a real serializer, use it — this is the fallback for
	 * shapes that do not (search's reply rows carry joined post/space columns;
	 * tag rows are flat). Matching is by column-name convention, which is why it
	 * stays a narrow fallback rather than the general path.
	 *
	 * @param array $row Raw row as an associative array.
	 * @return array
	 */
	protected function normalize_row_numerics( array $row ): array {
		foreach ( $row as $key => $value ) {
			if ( ! is_string( $value ) || ! is_numeric( $value ) ) {
				continue;
			}
			if ( 'id' === $key
				|| str_ends_with( $key, '_id' )
				|| str_ends_with( $key, '_count' )
				|| str_ends_with( $key, '_score' )
				|| str_starts_with( $key, 'is_' )
			) {
				$row[ $key ] = (int) $value;
			}
		}
		return $row;
	}

	/**
	 * Serialize a space row for API output.
	 *
	 * Lives here, not on Spaces_Controller, because more than one controller
	 * emits spaces. /search used to return `(array) $row` straight from the DB,
	 * which meant every numeric came out as a STRING ("id":"1","post_count":"1")
	 * and internal columns (`settings` as a raw JSON string, `sort_order`,
	 * `parent_id`) leaked to clients — a typed client doing `post_count > 0` or
	 * sorting numerically got wrong answers (Basecamp 10161324553). One
	 * serializer means a space cannot have two different shapes depending on
	 * which endpoint returned it.
	 *
	 * @param object $space Raw space row.
	 * @return array
	 */
	protected function prepare_space( object $space ): array {
		$data = [
			'id'               => (int) $space->id,
			'category_id'      => $space->category_id ? (int) $space->category_id : null,
			'title'            => $space->title,
			'slug'             => $space->slug,
			'description'      => $space->description ?? '',
			'type'             => $space->type ?? 'forum',
			'visibility'       => $space->visibility ?? 'public',
			'join_policy'      => $space->join_policy ?? 'open',
			'icon'             => $space->icon ?? '',
			'cover_image'      => $space->cover_image ?? '',
			'settings'         => ! empty( $space->settings ) ? json_decode( $space->settings, true ) : [],
			'member_count'     => (int) ( $space->member_count ?? 0 ),
			'post_count'       => (int) ( $space->post_count ?? 0 ),
			'sort_order'       => (int) ( $space->sort_order ?? 0 ),
			'author_id'        => $space->author_id ? (int) $space->author_id : null,
			'created_at'       => $space->created_at ?? null,
			'created_at_gmt'   => \Jetonomy\to_iso8601_z( $space->created_at ?? null ),
			'updated_at'       => $space->updated_at ?? null,
			'last_activity_at' => $space->last_activity_at ?? null,
		];

		// Viewer-relative membership context (additive, 1.6.0). All three are
		// null-safe for logged-out callers and resolve to indexed point
		// lookups, so the per-row cost on the spaces list (≤ page size) stays
		// bounded.
		$uid                   = get_current_user_id();
		$data['is_member']     = $uid ? \Jetonomy\Models\SpaceMember::is_member( (int) $space->id, $uid ) : false;
		$data['viewer_role']   = $uid ? \Jetonomy\Models\SpaceMember::get_role( (int) $space->id, $uid ) : null;
		$data['is_subscribed'] = $uid ? \Jetonomy\Models\Subscription::is_subscribed( $uid, 'space', (int) $space->id ) : false;

		/**
		 * Filter the REST response data for a single space.
		 *
		 * @param array  $data    Prepared response data.
		 * @param object $space   Raw space row object.
		 * @param null   $request WP_REST_Request (null in non-request contexts).
		 */
		return apply_filters( 'jetonomy_rest_prepare_space', $data, $space, null );
	}

	/**
	 * Serialize a post row for API output.
	 *
	 * Promoted here from Posts_Controller in 1.9.1 so /search can emit the same
	 * shape as /feed and /spaces/{id}/posts instead of casting the raw DB row.
	 * Raw rows meant string-typed numerics, no _gmt twins and no author
	 * enrichment, so the same post had two shapes depending on which endpoint
	 * returned it (Basecamp 10161324553).
	 *
	 * Self-contained by design - it calls no $this-> helpers, and falls back to
	 * per-item author lookup when the row was not batch-enriched.
	 */
	protected function prepare_post( object $post ): array {
		$author_id = (int) ( $post->author_id ?? 0 );
		$space     = \Jetonomy\Models\Space::find( (int) $post->space_id );

		// The REAL author, captured before the anonymous mask below rewrites
		// $author_id to 0. Votes_Controller enforces the self-downvote rule
		// against the row, not the masked shape, so `can_downvote` has to be
		// computed from this — otherwise the author of an ANONYMOUS post sees a
		// downvote control the server will answer with a 400.
		$real_author_id = $author_id;

		// Blocked-author tombstone — applied to the ROW, before any field is
		// read into $data, so every caller of this shape (list, feed, get_item)
		// is scrubbed by construction rather than by each one remembering to.
		// blocked_ids() is memoized per request, so this is not an N+1 on lists.
		\Jetonomy\Models\Post::apply_block_tombstone(
			$post,
			\Jetonomy\Models\BlockedUser::blocked_ids( get_current_user_id() )
		);

		// Use pre-enriched data if present, otherwise fall back to per-item lookup.
		// The companions are read with `??` rather than bare property access: only
		// author_name is isset-guarded, and enrich_with_author() is not the only
		// thing that can set it, so a partially-enriched row must not fatal.
		if ( isset( $post->author_name ) ) {
			$author_name   = $post->author_name;
			$author_avatar = $post->author_avatar ?? '';
			$author_login  = $post->author_login ?? '';
			$trust_level   = $post->trust_level ?? 0;
			$reputation    = $post->reputation ?? 0;
			$profile_url   = $post->profile_url ?? '';
		} else {
			$author  = $author_id ? get_userdata( $author_id ) : null;
			$profile = $author_id ? \Jetonomy\Models\UserProfile::find_by_user( $author_id ) : null;
			// Routed through the same seam as everywhere else so a deleted
			// account (author_id = 0, tombstoned by Privacy::on_user_delete())
			// reads "[deleted]" here too instead of "Anonymous". $author is
			// already fetched above — passed through to avoid a second query.
			$author_name   = \Jetonomy\Author::for_display( $author_id, $post, $author ?: null )['name'] ?: __( 'Anonymous', 'jetonomy' );
			$author_avatar = $author ? \Jetonomy\Avatar::display_url( $author_id, 64 ) : '';
			$author_login  = $author ? $author->user_login : '';
			$trust_level   = $profile ? (int) $profile->trust_level : 0;
			$reputation    = $profile ? (int) $profile->reputation : 0;
			$profile_url   = $author_id ? \Jetonomy\get_profile_url( $author_id ) : '';
		}

		// Anonymous masking — one place, overrides both the enriched batch path
		// and the per-item lookup above. Real author_id is kept on the row.
		$display = \Jetonomy\Author::for_display( $author_id, $post );
		if ( ! empty( $post->is_anonymous ) && 0 === $display['id'] ) {
			$author_id     = 0;
			$author_name   = $display['name'];
			$author_avatar = $display['avatar'];
			$author_login  = '';
			$trust_level   = 0;
			$reputation    = 0;
			$profile_url   = $display['url'];
		}

		// Resolve prefix color from space settings.
		$prefix_name  = $post->prefix ?? null;
		$prefix_color = null;
		if ( $prefix_name && $space ) {
			$space_settings = \Jetonomy\Models\Space::get_settings( (int) $space->id );
			$prefix_list    = $space_settings['prefixes'] ?? array();
			foreach ( $prefix_list as $pfx ) {
				if ( ( $pfx['name'] ?? '' ) === $prefix_name ) {
					$prefix_color = $pfx['color'] ?? null;
					break;
				}
			}
		}

		$data = array(
			'id'                      => (int) $post->id,
			'space_id'                => (int) $post->space_id,
			'author_id'               => $author_id,
			'prefix'                  => $prefix_name,
			'prefix_color'            => $prefix_color,
			'title'                   => $post->title ?? '',
			'slug'                    => $post->slug ?? '',
			'content'                 => \Jetonomy\Embeds::process( $post->content ?? '' ),
			'content_plain'           => $post->content_plain ?? '',
			'type'                    => $post->type ?? 'topic',
			'status'                  => $post->status ?? 'publish',
			'is_sticky'               => (bool) ( $post->is_sticky ?? false ),
			'is_private'              => (bool) ( $post->is_private ?? false ),
			'is_closed'               => (bool) ( $post->is_closed ?? false ),
			'is_resolved'             => (bool) ( $post->is_resolved ?? false ),
			'idea_status'             => isset( $post->idea_status ) && '' !== (string) $post->idea_status ? (string) $post->idea_status : null,
			'accepted_reply_id'       => $post->accepted_reply_id ? (int) $post->accepted_reply_id : null,
			'view_count'              => (int) ( $post->view_count ?? 0 ),
			'reply_count'             => (int) ( $post->reply_count ?? 0 ),
			'vote_score'              => (int) ( $post->vote_score ?? 0 ),
			'last_reply_at'           => $post->last_reply_at ?? null,
			'edited_at'               => $post->edited_at ?? null,
			'edited_by'               => $post->edited_by ? (int) $post->edited_by : null,
			'published_at'            => $post->published_at ?? null,
			'created_at'              => $post->created_at ?? null,
			'updated_at'              => $post->updated_at ?? null,
			// Additive UTC ISO-8601 (`Z`) instants for app clients; columns are already UTC.
			'created_at_gmt'          => \Jetonomy\to_iso8601_z( $post->created_at ?? null ),
			'updated_at_gmt'          => \Jetonomy\to_iso8601_z( $post->updated_at ?? null ),
			'last_reply_at_gmt'       => \Jetonomy\to_iso8601_z( $post->last_reply_at ?? null ),
			'edited_at_gmt'           => \Jetonomy\to_iso8601_z( $post->edited_at ?? null ),
			'published_at_gmt'        => \Jetonomy\to_iso8601_z( $post->published_at ?? null ),
			// Enriched author data (for app clients + JS rendering)
			'author_name'             => $author_name,
			'author_avatar'           => $author_avatar,
			'author_login'            => $author_login,
			'author_last_seen_at'     => $post->author_last_seen_at ?? null,
			'author_last_seen_at_gmt' => \Jetonomy\to_iso8601_z( $post->author_last_seen_at ?? null ),
			'trust_level'             => $trust_level,
			'reputation'              => $reputation,
			'time_ago'                => $post->created_at ? human_time_diff( strtotime( $post->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) : '',
			'profile_url'             => $profile_url,
			// Space context
			'space_title'             => $space ? $space->title : '',
			'space_slug'              => $space ? $space->slug : '',
			// Has the VIEWER blocked this post's (real, pre-anonymization) author?
			// Deep-link / by-ID fetches (get_item()) bypass the list-query SQL
			// filters entirely, so the client needs this flag to render a
			// tombstone instead of a 404 — deep links and moderation must still
			// work. The flag is now advisory ONLY: as of 1.8.0 the title and body
			// above are already empty when it is true, so a client that ignores
			// it still cannot show the blocked author's words.
			'blocked_author'          => ! empty( $post->is_blocked_author ),
		);

		// Viewer-relative state (additive, 1.6.0). Null-safe for logged-out
		// callers. When a batch enrich pre-set the values on the row
		// (enrich_viewer_state, used by list + feed) use them; otherwise fall
		// back to a per-item lookup for the single get_item path.
		$uid                   = get_current_user_id();
		$data['is_bookmarked'] = isset( $post->is_bookmarked )
			? (bool) $post->is_bookmarked
			: ( $uid ? \Jetonomy\Models\Bookmark::is_bookmarked( $uid, (int) $post->id ) : false );
		$data['viewer_vote']   = isset( $post->viewer_vote )
			? (int) $post->viewer_vote
			: ( $uid ? (int) ( \Jetonomy\Models\Vote::get_user_vote( $uid, 'post', (int) $post->id ) ?? 0 ) : 0 );
		// Additive (1.9.3): can THIS viewer downvote this post? Votes_Controller
		// rejects a self-downvote with a 400, and the web templates already hide
		// the control on own content (templates/partials/post-card.php). Clients
		// that only see the API had no way to mirror that rule — the app rendered
		// the chevron on the viewer's own posts and the tap 400'd (Basecamp
		// 10199587514). Server-owned rule, server-published flag: no client
		// re-derives it, and the anonymous case stays correct.
		$data['can_downvote'] = $uid > 0 && $real_author_id !== $uid;

		/*
		 * Additive (1.9.4): may THIS viewer block this post's author?
		 *
		 * BlockedUser::block() refuses two targets outright - yourself, and any
		 * moderator or administrator, because a member who could block the
		 * moderators would make them unreachable to themselves. The API
		 * published nothing to read that rule from, so the app rendered a Block
		 * control on staff posts and the tap failed
		 * (Basecamp 10207937443 / 10203753031).
		 *
		 * Same shape, and the same fix, as can_downvote directly above: a
		 * server-owned rule gets a server-published flag rather than every
		 * client re-deriving it. Clients that predate this field fall back to
		 * their old behaviour, so the flag is additive, never required.
		 */
		$data['can_block_author'] = $uid > 0
			&& $real_author_id > 0
			&& $real_author_id !== $uid
			&& ! user_can( $real_author_id, 'manage_options' )
			&& ! user_can( $real_author_id, 'jetonomy_moderate' );
		// Server-authoritative ban eligibility (mobile app). Bans are a
		// moderator action, so unlike can_block_author this also requires the
		// VIEWER to hold jetonomy_moderate. Staff authors are never bannable -
		// this is what stops the app offering "Ban" on an admin's post
		// (Basecamp: ban button shown for administrators). Absent before 1.9.4,
		// so the app falls back to a local check on older sites.
		$data['can_ban'] = $uid > 0
			&& user_can( $uid, 'jetonomy_moderate' )
			&& $real_author_id > 0
			&& $real_author_id !== $uid
			&& ! user_can( $real_author_id, 'manage_options' )
			&& ! user_can( $real_author_id, 'jetonomy_moderate' );
		// Additive (1.9.3): has THIS viewer already reported this post? A second
		// report is answered 409 jetonomy_already_flagged, but the API published
		// nothing to read that state from, so the app kept "reported" in local
		// component state - lost on every refresh, after which the control drew
		// itself un-reported and the re-tap silently 409'd (Basecamp
		// 10202766654). Batched by enrich_viewer_state() on list paths.
		$data['viewer_flagged'] = isset( $post->viewer_flagged )
			? (bool) $post->viewer_flagged
			: \Jetonomy\Models\Flag::has_reported( $uid, 'post', (int) $post->id );
		// Additive (plan WP6.2): the web JS fetched a whole /subscriptions
		// list per topic view just to render the follow toggle. Batched on
		// list paths by enrich_viewer_state()'s subscription warm; the memo
		// keeps unwarmed single-item paths at one query. prepare_space
		// already ships the same flag — posts now match.
		$data['is_subscribed'] = $uid
			? \Jetonomy\Models\Subscription::is_subscribed( $uid, 'post', (int) $post->id )
			: false;

		/**
		 * Filter the REST response data for a single post.
		 *
		 * @param array  $data    Prepared response data.
		 * @param object $post    Raw post row object.
		 * @param null   $request WP_REST_Request (null in non-request contexts).
		 */
		$data = apply_filters( 'jetonomy_rest_prepare_post', $data, $post, null );

		/**
		 * Alias filter matching the Pro custom-fields listener contract —
		 * lets extensions append per-post payload (custom field values, etc.)
		 * to the API response. Context carries object_type + object_id so
		 * generic extensions can route on type.
		 *
		 * @since 1.4.1
		 * @param array $data    Prepared response data.
		 * @param array $context { object_type: 'post', object_id: int }
		 */
		$data = apply_filters(
			'jetonomy_post_response',
			$data,
			array(
				'object_type' => 'post',
				'object_id'   => (int) $post->id,
			)
		);

		return $data;
	}
}
