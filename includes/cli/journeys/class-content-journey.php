<?php
/**
 * Content journey — create, update, delete, vote, flag, accept answer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\Vote;
use Jetonomy\Moderation\Moderation_Service;
use Jetonomy\Permissions\Content_Gate;

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
 * Member writes (create post/reply, vote, flag) run the same shared gates as
 * REST - Content_Gate and Moderation_Service - for the acting member, so the
 * CLI cannot write where the site would refuse that member.
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

		$missing = $this->require_keys( $input, array( 'space_id', 'author_id', 'title', 'content' ) );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$unknown = Journey_Input::error( $input, array( 'space_id', 'author_id', 'title', 'content', 'status', 'slug', 'created_at' ) );
		if ( '' !== $unknown ) {
			return Journey_Result::fail( $unknown );
		}

		$space_id  = (int) $input['space_id'];
		$author_id = (int) $input['author_id'];

		// The same gate as REST: restrictions, archived/locked space, permission.
		$gate = Content_Gate::check_post( $author_id, $space_id );
		if ( is_wp_error( $gate ) ) {
			return Journey_Result::from_wp_error( $gate );
		}

		$data = array(
			'space_id'  => $space_id,
			'author_id' => $author_id,
			'title'     => sanitize_text_field( (string) $input['title'] ),
			'content'   => jetonomy_sanitize_editor_content( (string) $input['content'] ),
			'status'    => (string) ( $input['status'] ?? 'publish' ),
			'slug'      => isset( $input['slug'] ) ? (string) $input['slug'] : '',
		);
		if ( '' === $data['title'] || '' === $data['content'] ) {
			return Journey_Result::fail( 'title and content must not be empty after sanitizing.' );
		}

		$screened = Moderation_Service::screen_new_content( 'post', $data, $space_id, $author_id );
		if ( is_wp_error( $screened ) ) {
			return Journey_Result::from_wp_error( $screened );
		}
		$data['status'] = $screened['status'];

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

		if ( $screened['flag'] ) {
			Moderation_Service::auto_flag( 'post', (int) $result );
		}

		// The side effects REST runs after a create: the author follows the
		// topic, and the create hook drives activity, notifications, mentions
		// and the Pro listeners (null request, as every non-REST writer passes).
		if ( 'draft' !== $data['status'] ) {
			Subscription::subscribe( $author_id, 'post', (int) $result );
			do_action( 'jetonomy_after_create_post', (int) $result, $space_id, null );
		}

		$post = Post::find( (int) $result );

		return Journey_Result::ok(
			array(
				'id'       => (int) $result,
				'slug'     => $post->slug ?? null,
				'space_id' => (int) $input['space_id'],
				'author'   => (int) $input['author_id'],
				'status'   => $post->status ?? 'publish',
			),
			array(),
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

		$allowed = array( 'title', 'content', 'status', 'slug' );
		$patch   = array_intersect_key( $changes, array_flip( $allowed ) );

		if ( empty( $patch ) ) {
			return Journey_Result::fail( sprintf( 'No updatable fields provided. Allowed: %s', implode( ', ', $allowed ) ) );
		}

		// Same normalize+kses contract as create and the REST controllers -
		// the update path was the last writer that could persist raw div soup
		if ( isset( $patch['content'] ) ) {
			$patch['content'] = jetonomy_sanitize_editor_content( (string) $patch['content'] );
		}

		$ok = Post::update( $id, $patch );
		if ( ! $ok ) {
			return Journey_Result::fail( sprintf( 'Post::update(%d) returned false.', $id ) );
		}

		return Journey_Result::ok(
			array(
				'id'      => $id,
				'updated' => array_keys( $patch ),
			),
			array(),
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

		return Journey_Result::ok( array( 'id' => $id ), array(), $this->duration_ms( $start ) );
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

		return Journey_Result::ok( (array) $post, array(), $this->duration_ms( $start ) );
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

		$missing = $this->require_keys( $input, array( 'post_id', 'author_id', 'content' ) );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$unknown = Journey_Input::error( $input, array( 'post_id', 'author_id', 'content', 'status', 'parent_id', 'created_at' ) );
		if ( '' !== $unknown ) {
			return Journey_Result::fail( $unknown );
		}

		$post = Post::find( (int) $input['post_id'] );
		if ( ! $post ) {
			return Journey_Result::fail( sprintf( 'Post %d not found.', (int) $input['post_id'] ) );
		}

		// The same gate as REST: restrictions, closed topic, archived space, permission.
		$gate = Content_Gate::check( (int) $input['author_id'], $post, (int) ( $input['parent_id'] ?? 0 ) );
		if ( is_wp_error( $gate ) ) {
			return Journey_Result::from_wp_error( $gate );
		}

		$data = array(
			'post_id'   => (int) $input['post_id'],
			'author_id' => (int) $input['author_id'],
			'content'   => jetonomy_sanitize_editor_content( (string) $input['content'] ),
			'status'    => (string) ( $input['status'] ?? 'publish' ),
		);
		if ( '' === $data['content'] ) {
			return Journey_Result::fail( 'content must not be empty after sanitizing.' );
		}
		if ( ! empty( $input['parent_id'] ) ) {
			$data['parent_id'] = (int) $input['parent_id'];
		}

		$screened = Moderation_Service::screen_new_content( 'reply', $data, (int) $post->space_id, (int) $input['author_id'] );
		if ( is_wp_error( $screened ) ) {
			return Journey_Result::from_wp_error( $screened );
		}
		$data['status'] = $screened['status'];

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

		if ( $screened['flag'] ) {
			Moderation_Service::auto_flag( 'reply', (int) $result );
		}

		// Same create hook REST fires: the topic author's and followers'
		// notifications, activity and mentions hang off it.
		do_action( 'jetonomy_after_create_reply', (int) $result, (int) $input['post_id'], null );

		return Journey_Result::ok(
			array(
				'id'      => (int) $result,
				'post_id' => (int) $input['post_id'],
				'author'  => (int) $input['author_id'],
				'status'  => $data['status'],
			),
			array(),
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

		return Journey_Result::ok( array( 'id' => $id ), array(), $this->duration_ms( $start ) );
	}

	/**
	 * Mark a reply as the accepted answer for a post.
	 *
	 * Runs the same Reply::accept_as_answer() transaction as
	 * POST /replies/{id}/accept: reply is_accepted + post accepted_reply_id /
	 * is_resolved, jetonomy_reply_accepted, reputation. Q&A spaces only.
	 *
	 * @param int $post_id  Parent post ID (must own the reply).
	 * @param int $reply_id Reply to accept.
	 */
	public function accept_reply( int $post_id, int $reply_id ): Journey_Result {
		return $this->answer_transition( $post_id, $reply_id, true );
	}

	/**
	 * Clear a reply's accepted-answer state (reverse of accept_reply()).
	 *
	 * Mirrors DELETE /replies/{id}/accept via Reply::unaccept_as_answer().
	 *
	 * @param int $post_id  Parent post ID (must own the reply).
	 * @param int $reply_id Currently accepted reply.
	 */
	public function unaccept_reply( int $post_id, int $reply_id ): Journey_Result {
		return $this->answer_transition( $post_id, $reply_id, false );
	}

	/**
	 * Shared body of accept_reply() / unaccept_reply().
	 *
	 * @param int  $post_id  Parent post ID.
	 * @param int  $reply_id Reply ID.
	 * @param bool $accept   True to accept, false to un-accept.
	 */
	private function answer_transition( int $post_id, int $reply_id, bool $accept ): Journey_Result {
		$start = microtime( true );

		if ( $post_id <= 0 || $reply_id <= 0 ) {
			return Journey_Result::fail( 'post_id and reply_id must both be positive.' );
		}

		$reply = Reply::find( $reply_id );
		if ( $reply && (int) $reply->post_id !== $post_id ) {
			return Journey_Result::fail( sprintf( 'Reply %d belongs to post %d, not %d.', $reply_id, (int) $reply->post_id, $post_id ) );
		}

		$actor  = get_current_user_id();
		$result = $accept ? Reply::accept_as_answer( $reply_id, $actor ) : Reply::unaccept_as_answer( $reply_id, $actor );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}

		$reply = Reply::find( $reply_id );
		$post  = Post::find( $post_id );

		return Journey_Result::ok(
			array(
				'post_id'           => $post_id,
				'reply_id'          => $reply_id,
				'is_accepted'       => (int) ( $reply->is_accepted ?? 0 ),
				'accepted_reply_id' => (int) ( $post->accepted_reply_id ?? 0 ),
				'is_resolved'       => (int) ( $post->is_resolved ?? 0 ),
			),
			array(),
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
		if ( ! in_array( $object_type, array( 'post', 'reply' ), true ) ) {
			return Journey_Result::fail( "object_type must be 'post' or 'reply'." );
		}
		if ( ! in_array( $value, array( 1, -1 ), true ) ) {
			return Journey_Result::fail( 'value must be 1 or -1.' );
		}

		// A repeat of the active vote withdraws it, which REST leaves ungated too.
		if ( Vote::get_user_vote( $user_id, $object_type, $object_id ) !== $value ) {
			$gate = Content_Gate::check_vote( $user_id, $object_type, $object_id, $value );
			if ( is_wp_error( $gate ) ) {
				return Journey_Result::from_wp_error( $gate );
			}
		}

		$result = Vote::cast( $user_id, $object_type, $object_id, $value );
		if ( is_wp_error( $result ) ) {
			return Journey_Result::from_wp_error( $result );
		}

		return Journey_Result::ok(
			array(
				'user_id'     => $user_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'value'       => $value,
				'action'      => $result['action'],
				'old_value'   => $result['old_value'],
			),
			array(),
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

		$missing = $this->require_keys( $input, array( 'object_type', 'object_id', 'reporter_id', 'reason' ) );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$unknown = Journey_Input::error( $input, array( 'object_type', 'object_id', 'reporter_id', 'reason', 'description' ) );
		if ( '' !== $unknown ) {
			return Journey_Result::fail( $unknown );
		}

		$object_type = (string) $input['object_type'];
		if ( ! in_array( $object_type, array( 'post', 'reply', 'user' ), true ) ) {
			return Journey_Result::fail( "object_type must be 'post', 'reply', or 'user'." );
		}

		$reason = (string) $input['reason'];
		if ( ! in_array( $reason, array( 'spam', 'offensive', 'off_topic', 'harassment', 'other' ), true ) ) {
			return Journey_Result::fail( 'reason must be one of: spam, offensive, off_topic, harassment, other.' );
		}

		// The same create path as REST and the abilities API (gate, note length, reputation, hooks).
		$id = Moderation_Service::create_flag(
			(int) $input['reporter_id'],
			$object_type,
			(int) $input['object_id'],
			$reason,
			isset( $input['description'] ) ? (string) $input['description'] : ''
		);
		if ( is_wp_error( $id ) ) {
			return Journey_Result::from_wp_error( $id );
		}

		return Journey_Result::ok(
			array(
				'id'          => (int) $id,
				'object_type' => $object_type,
				'object_id'   => (int) $input['object_id'],
				'reporter_id' => (int) $input['reporter_id'],
				'status'      => 'pending',
			),
			array(),
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
		$missing = array();
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
