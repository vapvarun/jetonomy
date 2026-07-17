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
		$last_item   = end( $items );
		$cursor_next = $last_item
			? ( is_object( $last_item ) ? (int) $last_item->id : (int) ( $last_item['id'] ?? 0 ) )
			: null;

		$response = new WP_REST_Response(
			[
				'data' => $items,
				'meta' => array_merge(
					[
						'count'       => count( $items ),
						'has_more'    => isset( $meta['total'] ) ? ( ( $meta['offset'] ?? 0 ) + count( $items ) ) < (int) $meta['total'] : false,
						'cursor_next' => $cursor_next,
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
		return [
			'limit'  => (int) ( $request->get_param( 'limit' ) ?? 20 ),
			'offset' => (int) ( $request->get_param( 'offset' ) ?? 0 ),
			'sort'   => $request->get_param( 'sort' ) ?? 'latest',
			'after'  => (int) ( $request->get_param( 'after' ) ?? 0 ),
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
				'description' => 'Return items after this ID (cursor-based pagination)',
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
		if ( ! empty( $ids ) ) {
			// Core: fills the user + usermeta caches in two queries for the set.
			cache_users( $ids );
			// Ours: fills UserProfile's cache in one, which Avatar reads per person.
			UserProfile::prime( $ids );
		}

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
				'author_name'   => $author_name ?: __( 'Anonymous', 'jetonomy' ),
				// display_url() returns '' when the member has no real avatar, so
				// API clients (mobile app) render initials from author_name — the
				// same fallback the web templates use. Never the Gravatar mystery-man.
				'author_avatar' => $user ? \Jetonomy\Avatar::display_url( $uid, 64 ) : '',
				'author_login'  => $user ? $user->user_login : '',
				'trust_level'   => $profile ? (int) $profile->trust_level : 0,
				'reputation'    => $profile ? (int) $profile->reputation : 0,
				'profile_url'   => $uid ? \Jetonomy\get_profile_url( $uid ) : '',
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
}
