<?php
/**
 * Content journey — create, update, delete, vote, flag, accept answer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Vote;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper covering every content interaction a user or moderator can
 * perform: posts, replies, votes, flags, and accepted-answer marking.
 *
 * Pure PHP — no WP-CLI calls, no output side effects. Every method takes a
 * plain assoc array or primitive inputs, delegates to the underlying model
 * class, and returns a {@see Journey_Result}. Commands format the result for
 * the terminal; PHPUnit tests read the same fields and assert on them.
 *
 * All permission checks are delegated upstream to Permission_Engine via the
 * model filters (`jetonomy_before_create_post`, etc.), so this class can be
 * called in both authenticated and impersonated contexts without duplicating
 * capability logic.
 */
final class Content_Journey {

	/**
	 * Create a post in a space.
	 *
	 * Required input keys: `space_id`, `author_id`, `title`, `content`.
	 * Optional: `status` (default `publish`), `slug` (auto-generated from
	 * title), `created_at` (backdated UTC timestamp — importer seam, see
	 * {@see Journey_Backdate}; a backdated topic also backdates its
	 * last_reply_at so it does not claim activity "now").
	 *
	 * @param array<string,mixed> $input Create payload.
	 */
	public function create_post( array $input ): Journey_Result {
		$start = microtime( true );

		$missing = $this->require_keys( $input, [ 'space_id', 'author_id', 'title', 'content' ] );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$data = [
			'space_id'  => (int) $input['space_id'],
			'author_id' => (int) $input['author_id'],
			'title'     => (string) $input['title'],
			'content'   => (string) $input['content'],
			'status'    => (string) ( $input['status'] ?? 'publish' ),
			'slug'      => isset( $input['slug'] ) ? (string) $input['slug'] : '',
		];

		$backdate = Journey_Backdate::resolve( $input );
		if ( null !== $backdate ) {
			$data['created_at']    = $backdate;
			$data['last_reply_at'] = $backdate;
		}

		$result = Post::create( $data );

		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}

		if ( ! $result ) {
			return Journey_Result::fail( 'Post::create() returned 0 — insert failed.' );
		}

		$post = Post::find( (int) $result );

		return Journey_Result::ok(
			[
				'id'       => (int) $result,
				'slug'     => $post->slug ?? null,
				'space_id' => (int) $input['space_id'],
				'author'   => (int) $input['author_id'],
				'status'   => $post->status ?? 'publish',
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Update mutable fields on an existing post.
	 *
	 * Only a whitelist of safe columns is forwarded to Post::update() — this
	 * prevents the caller from reassigning author or space via a typo.
	 *
	 * @param int                 $id      Post row ID.
	 * @param array<string,mixed> $changes Column → new value map.
	 */
	public function update_post( int $id, array $changes ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Post id must be positive.' );
		}

		$allowed = [ 'title', 'content', 'status', 'slug' ];
		$patch   = array_intersect_key( $changes, array_flip( $allowed ) );

		if ( empty( $patch ) ) {
			return Journey_Result::fail( sprintf( 'No updatable fields provided. Allowed: %s', implode( ', ', $allowed ) ) );
		}

		$ok = Post::update( $id, $patch );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'Post::update(%d) returned false.', $id ) );
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
	 * Delete a post by ID.
	 *
	 * @param int $id Post row ID.
	 */
	public function delete_post( int $id ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Post id must be positive.' );
		}

		$result = Post::delete( $id );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}
		if ( ! $result ) {
			return Journey_Result::fail( sprintf( 'Post::delete(%d) returned false.', $id ) );
		}

		return Journey_Result::ok( [ 'id' => $id ], [], $this->duration_ms( $start ) );
	}

	/**
	 * Fetch a post by ID for inspection from CLI or tests.
	 *
	 * @param int $id Post row ID.
	 */
	public function get_post( int $id ): Journey_Result {
		$start = microtime( true );

		$post = Post::find( $id );
		if ( ! $post ) {
			return Journey_Result::fail( sprintf( 'Post %d not found.', $id ) );
		}

		return Journey_Result::ok( (array) $post, [], $this->duration_ms( $start ) );
	}

	/**
	 * Create a reply to an existing post.
	 *
	 * Required input keys: `post_id`, `author_id`, `content`.
	 * Optional: `status` (default `publish`), `parent_id` for threaded replies.
	 *
	 * @param array<string,mixed> $input Create payload.
	 */
	public function create_reply( array $input ): Journey_Result {
		$start = microtime( true );

		$missing = $this->require_keys( $input, [ 'post_id', 'author_id', 'content' ] );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$data = [
			'post_id'   => (int) $input['post_id'],
			'author_id' => (int) $input['author_id'],
			'content'   => (string) $input['content'],
			'status'    => (string) ( $input['status'] ?? 'publish' ),
		];
		if ( ! empty( $input['parent_id'] ) ) {
			$data['parent_id'] = (int) $input['parent_id'];
		}

		// Importer seam: forward a validated backdate; the model default (now)
		// applies otherwise. Reply::create() carries this into the parent
		// post's last_reply_at.
		$backdate = Journey_Backdate::resolve( $input );
		if ( null !== $backdate ) {
			$data['created_at'] = $backdate;
		}

		$result = Reply::create( $data );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}
		if ( ! $result ) {
			return Journey_Result::fail( 'Reply::create() returned 0 — insert failed.' );
		}

		return Journey_Result::ok(
			[
				'id'      => (int) $result,
				'post_id' => (int) $input['post_id'],
				'author'  => (int) $input['author_id'],
				'status'  => $data['status'],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Delete a reply by ID.
	 *
	 * @param int $id Reply row ID.
	 */
	public function delete_reply( int $id ): Journey_Result {
		$start = microtime( true );

		if ( $id <= 0 ) {
			return Journey_Result::fail( 'Reply id must be positive.' );
		}

		$result = Reply::delete( $id );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}
		if ( ! $result ) {
			return Journey_Result::fail( sprintf( 'Reply::delete(%d) returned false.', $id ) );
		}

		return Journey_Result::ok( [ 'id' => $id ], [], $this->duration_ms( $start ) );
	}

	/**
	 * Mark a reply as the accepted answer for a post.
	 *
	 * @param int $post_id  Parent post ID.
	 * @param int $reply_id Reply to accept.
	 */
	public function accept_reply( int $post_id, int $reply_id ): Journey_Result {
		$start = microtime( true );

		if ( $post_id <= 0 || $reply_id <= 0 ) {
			return Journey_Result::fail( 'post_id and reply_id must both be positive.' );
		}

		$ok = Post::accept_reply( $post_id, $reply_id );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'Post::accept_reply(%d, %d) returned false.', $post_id, $reply_id ) );
		}

		return Journey_Result::ok(
			[
				'post_id'  => $post_id,
				'reply_id' => $reply_id,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Cast a vote on a post or reply.
	 *
	 * Wraps Vote::cast() which handles the three-state logic (insert, undo,
	 * switch). Returns the action the model performed so callers can assert
	 * on whether the vote was created, updated, or removed.
	 *
	 * @param int    $user_id     Voting user.
	 * @param string $object_type 'post' or 'reply'.
	 * @param int    $object_id   Target row ID.
	 * @param int    $value       +1 or -1.
	 */
	public function vote( int $user_id, string $object_type, int $object_id, int $value ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 || $object_id <= 0 ) {
			return Journey_Result::fail( 'user_id and object_id must both be positive.' );
		}
		if ( ! in_array( $object_type, [ 'post', 'reply' ], true ) ) {
			return Journey_Result::fail( "object_type must be 'post' or 'reply'." );
		}
		if ( ! in_array( $value, [ 1, -1 ], true ) ) {
			return Journey_Result::fail( 'value must be 1 or -1.' );
		}

		$result = Vote::cast( $user_id, $object_type, $object_id, $value );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}

		return Journey_Result::ok(
			[
				'user_id'     => $user_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'value'       => $value,
				'action'      => $result['action'],
				'old_value'   => $result['old_value'],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * File a flag (content report) against a post, reply, or user.
	 *
	 * Required input keys: `object_type`, `object_id`, `reporter_id`, `reason`.
	 * The `reason` must be one of the schema enum values:
	 * spam, offensive, off_topic, harassment, other.
	 *
	 * @param array<string,mixed> $input Flag payload.
	 */
	public function flag( array $input ): Journey_Result {
		$start = microtime( true );

		$missing = $this->require_keys( $input, [ 'object_type', 'object_id', 'reporter_id', 'reason' ] );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$object_type = (string) $input['object_type'];
		if ( ! in_array( $object_type, [ 'post', 'reply', 'user' ], true ) ) {
			return Journey_Result::fail( "object_type must be 'post', 'reply', or 'user'." );
		}

		$reason = (string) $input['reason'];
		if ( ! in_array( $reason, [ 'spam', 'offensive', 'off_topic', 'harassment', 'other' ], true ) ) {
			return Journey_Result::fail( 'reason must be one of: spam, offensive, off_topic, harassment, other.' );
		}

		$id = Flag::create(
			[
				'object_type' => $object_type,
				'object_id'   => (int) $input['object_id'],
				'reporter_id' => (int) $input['reporter_id'],
				'reason'      => $reason,
				'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
			]
		);

		if ( ! $id ) {
			return Journey_Result::fail( 'Flag::create() returned 0 — insert failed.' );
		}

		return Journey_Result::ok(
			[
				'id'          => (int) $id,
				'object_type' => $object_type,
				'object_id'   => (int) $input['object_id'],
				'reporter_id' => (int) $input['reporter_id'],
				'status'      => 'pending',
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
