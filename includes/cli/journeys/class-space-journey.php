<?php
/**
 * Space journey — create, update, delete, and configure spaces.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Space;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper covering the space lifecycle: create, update, delete,
 * lookup, visibility, join policy, settings, and listing by category.
 *
 * Pure PHP — no WP-CLI calls, no output side effects. Every method takes
 * plain primitives or assoc arrays, delegates to {@see Space}, and returns
 * a {@see Journey_Result}. Commands format the result for the terminal;
 * PHPUnit tests read the same fields and assert on them.
 *
 * Enum values (type, visibility, join_policy) are validated before the
 * model layer so callers get a clear error message instead of a silent
 * database constraint failure.
 */
final class Space_Journey {

	private const ALLOWED_TYPES         = [ 'forum', 'qa', 'ideas', 'chat' ];
	private const ALLOWED_JOIN_POLICIES = [ 'open', 'approval', 'invite' ];

	/**
	 * Create a space under a category.
	 *
	 * Required input keys: `title`, `slug`, `category_id`.
	 * Optional: `description`, `type`, `visibility`, `join_policy`.
	 *
	 * @param array<string,mixed> $input Create payload.
	 */
	public function create( array $input ): Journey_Result {
		$start = microtime( true );

		$missing = $this->require_keys( $input, [ 'title', 'slug', 'category_id' ] );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$type        = (string) ( $input['type'] ?? 'forum' );
		$visibility  = (string) ( $input['visibility'] ?? 'public' );
		$join_policy = (string) ( $input['join_policy'] ?? 'open' );

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return Journey_Result::fail( 'type must be one of: ' . implode( ', ', self::ALLOWED_TYPES ) );
		}
		if ( ! in_array( $visibility, Space::visibility_values(), true ) ) {
			return Journey_Result::fail( 'visibility must be one of: ' . implode( ', ', Space::visibility_values() ) );
		}
		if ( ! in_array( $join_policy, self::ALLOWED_JOIN_POLICIES, true ) ) {
			return Journey_Result::fail( 'join_policy must be one of: ' . implode( ', ', self::ALLOWED_JOIN_POLICIES ) );
		}

		$combo = Space::validate_visibility_join_policy( $visibility, $join_policy );
		if ( is_wp_error( $combo ) ) {
			return Journey_Result::fail( $combo->get_error_message() );
		}

		$data = [
			'category_id' => (int) $input['category_id'],
			'title'       => (string) $input['title'],
			'slug'        => (string) $input['slug'],
			'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
			'type'        => $type,
			'visibility'  => $visibility,
			'join_policy' => $join_policy,
		];

		// Importer seam: forward a validated backdate; the model default (now)
		// applies otherwise (see Journey_Backdate).
		$backdate = Journey_Backdate::resolve( $input );
		if ( null !== $backdate ) {
			$data['created_at'] = $backdate;
		}

		$id = Space::create( $data );
		if ( ! $id ) {
			return Journey_Result::fail( 'Space::create() returned 0 — insert failed.' );
		}

		$row = Space::find( (int) $id );

		return Journey_Result::ok(
			[
				'id'          => (int) $id,
				'slug'        => $row->slug ?? $data['slug'],
				'category_id' => (int) $data['category_id'],
				'type'        => $type,
				'visibility'  => $visibility,
				'join_policy' => $join_policy,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Update mutable fields on an existing space.
	 *
	 * Only a whitelist of safe columns is forwarded to Space::update() — this
	 * prevents the caller from reassigning category or author via a typo.
	 *
	 * @param int                 $id      Space row ID.
	 * @param array<string,mixed> $changes Column → new value map.
	 */
	public function update( int $id, array $changes ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Space id must be positive.' );
		}

		$allowed = [ 'title', 'description', 'type', 'visibility', 'join_policy', 'status', 'sort_order' ];
		$patch   = array_intersect_key( $changes, array_flip( $allowed ) );

		if ( empty( $patch ) ) {
			return Journey_Result::fail( sprintf( 'No updatable fields provided. Allowed: %s', implode( ', ', $allowed ) ) );
		}

		if ( isset( $patch['type'] ) && ! in_array( $patch['type'], self::ALLOWED_TYPES, true ) ) {
			return Journey_Result::fail( 'type must be one of: ' . implode( ', ', self::ALLOWED_TYPES ) );
		}
		if ( isset( $patch['visibility'] ) && ! in_array( $patch['visibility'], Space::visibility_values(), true ) ) {
			return Journey_Result::fail( 'visibility must be one of: ' . implode( ', ', Space::visibility_values() ) );
		}
		if ( isset( $patch['join_policy'] ) && ! in_array( $patch['join_policy'], self::ALLOWED_JOIN_POLICIES, true ) ) {
			return Journey_Result::fail( 'join_policy must be one of: ' . implode( ', ', self::ALLOWED_JOIN_POLICIES ) );
		}

		// Cross-validate visibility + join_policy after overlaying the patch
		// onto the existing space row so partial updates that touch only
		// one of the two fields still trip the rule.
		if ( isset( $patch['visibility'] ) || isset( $patch['join_policy'] ) ) {
			$existing              = Space::find( $id );
			$effective_visibility  = (string) ( $patch['visibility'] ?? ( $existing->visibility ?? 'public' ) );
			$effective_join_policy = (string) ( $patch['join_policy'] ?? ( $existing->join_policy ?? 'open' ) );
			$combo                 = Space::validate_visibility_join_policy( $effective_visibility, $effective_join_policy );
			if ( is_wp_error( $combo ) ) {
				return Journey_Result::fail( $combo->get_error_message() );
			}
		}

		$ok = Space::update( $id, $patch );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'Space::update(%d) returned false.', $id ) );
		}

		return Journey_Result::ok(
			[
				'id'      => $id,
				'updated' => array_keys( $patch ),
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Delete a space by ID.
	 *
	 * Space does not override Model::delete(), so this resolves to the
	 * inherited method which returns bool|WP_Error.
	 *
	 * @param int $id Space row ID.
	 */
	public function delete( int $id ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Space id must be positive.' );
		}

		$result = Space::delete( $id );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}
		if ( ! $result ) {
			return Journey_Result::fail( sprintf( 'Space::delete(%d) returned false.', $id ) );
		}

		return Journey_Result::ok( [ 'id' => $id ], [], $this->duration_ms( $start ) );
	}

	/**
	 * Fetch a space by ID for inspection from CLI or tests.
	 *
	 * @param int $id Space row ID.
	 */
	public function get( int $id ): Journey_Result {
		$start = microtime( true );

		$row = Space::find( $id );
		if ( ! $row ) {
			return Journey_Result::fail( sprintf( 'Space %d not found.', $id ) );
		}

		return Journey_Result::ok( (array) $row, [], $this->duration_ms( $start ) );
	}

	/**
	 * Fetch a space by slug.
	 *
	 * @param string $slug Space slug.
	 */
	public function get_by_slug( string $slug ): Journey_Result {
		$start = microtime( true );

		if ( '' === $slug ) {
			return Journey_Result::fail( 'slug must not be empty.' );
		}

		$row = Space::find_by_slug( $slug );
		if ( ! $row ) {
			return Journey_Result::fail( sprintf( 'Space with slug "%s" not found.', $slug ) );
		}

		return Journey_Result::ok( (array) $row, [], $this->duration_ms( $start ) );
	}

	/**
	 * Change the join policy for a space.
	 *
	 * @param int    $id     Space row ID.
	 * @param string $policy One of open/approval/invite.
	 */
	public function set_join_policy( int $id, string $policy ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Space id must be positive.' );
		}
		if ( ! in_array( $policy, self::ALLOWED_JOIN_POLICIES, true ) ) {
			return Journey_Result::fail( 'join_policy must be one of: ' . implode( ', ', self::ALLOWED_JOIN_POLICIES ) );
		}

		$existing = Space::find( $id );
		$combo    = Space::validate_visibility_join_policy(
			(string) ( $existing->visibility ?? 'public' ),
			$policy
		);
		if ( is_wp_error( $combo ) ) {
			return Journey_Result::fail( $combo->get_error_message() );
		}

		$ok = Space::update( $id, [ 'join_policy' => $policy ] );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'Space::update(%d) returned false.', $id ) );
		}

		return Journey_Result::ok(
			[
				'id'          => $id,
				'join_policy' => $policy,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Change the visibility for a space.
	 *
	 * @param int    $id         Space row ID.
	 * @param string $visibility One of public/private/hidden.
	 */
	public function set_visibility( int $id, string $visibility ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Space id must be positive.' );
		}
		if ( ! in_array( $visibility, Space::visibility_values(), true ) ) {
			return Journey_Result::fail( 'visibility must be one of: ' . implode( ', ', Space::visibility_values() ) );
		}

		$existing = Space::find( $id );
		$combo    = Space::validate_visibility_join_policy(
			$visibility,
			(string) ( $existing->join_policy ?? 'open' )
		);
		if ( is_wp_error( $combo ) ) {
			return Journey_Result::fail( $combo->get_error_message() );
		}

		$ok = Space::update( $id, [ 'visibility' => $visibility ] );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'Space::update(%d) returned false.', $id ) );
		}

		return Journey_Result::ok(
			[
				'id'         => $id,
				'visibility' => $visibility,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Merge new key/values into the space's JSON settings column.
	 *
	 * Fetches the existing settings, merges (new values win), and writes
	 * the re-encoded JSON back via Space::update(). This matches the
	 * "always merge, never replace" pattern enforced in admin UI.
	 *
	 * @param int                 $id       Space row ID.
	 * @param array<string,mixed> $settings Key/value pairs to merge.
	 */
	public function set_settings( int $id, array $settings ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Space id must be positive.' );
		}
		if ( empty( $settings ) ) {
			return Journey_Result::fail( 'settings must not be empty.' );
		}

		$merged = Space::merge_settings( $id, $settings );

		$ok = Space::update( $id, [ 'settings' => wp_json_encode( $merged ) ] );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'Space::update(%d) returned false.', $id ) );
		}

		return Journey_Result::ok(
			[
				'id'       => $id,
				'settings' => $merged,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List spaces in a category, shaped for render_list().
	 *
	 * @param int $category_id Category row ID.
	 */
	public function list_by_category( int $category_id ): Journey_Result {
		$start = microtime( true );

		if ( $category_id <= 0 ) {
			return Journey_Result::fail( 'category_id must be positive.' );
		}

		$rows = Space::list_by_category( $category_id );

		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'id'          => (int) $row->id,
				'title'       => (string) $row->title,
				'slug'        => (string) $row->slug,
				'type'        => (string) ( $row->type ?? '' ),
				'visibility'  => (string) ( $row->visibility ?? '' ),
				'join_policy' => (string) ( $row->join_policy ?? '' ),
				'post_count'  => (int) ( $row->post_count ?? 0 ),
			];
		}

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'id', 'title', 'slug', 'type', 'visibility', 'join_policy', 'post_count' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Return any required keys that are missing or empty in the input array.
	 *
	 * @param array<string,mixed> $input Input payload.
	 * @param array<int,string>   $keys  Required key names.
	 * @return array<int,string> Missing key names; empty if all present.
	 */
	private function require_keys( array $input, array $keys ): array {
		$missing = [];
		foreach ( $keys as $key ) {
			if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/**
	 * Elapsed time in whole milliseconds since the given start (microtime(true)).
	 */
	private function duration_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
