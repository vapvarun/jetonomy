<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Vote;
use Jetonomy\Models\UserProfile;

class Votes_Controller extends Base_Controller {

	protected string $rest_base = 'votes';

	// Reputation deltas.
	private const REP_POST_UPVOTE   = 10;
	private const REP_REPLY_UPVOTE  = 5;
	private const REP_DOWNVOTE      = -2;

	/**
	 * Register all REST routes for votes.
	 */
	public function register_routes(): void {
		$ns = $this->namespace;

		register_rest_route( $ns, '/posts/(?P<id>\d+)/vote', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'vote_post' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_vote_args(),
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'unvote_post' ],
				'permission_callback' => '__return_true',
			],
		] );

		register_rest_route( $ns, '/replies/(?P<id>\d+)/vote', [
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'vote_reply' ],
				'permission_callback' => '__return_true',
				'args'                => $this->get_vote_args(),
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'unvote_reply' ],
				'permission_callback' => '__return_true',
			],
		] );
	}

	/**
	 * POST /posts/{id}/vote
	 */
	public function vote_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_vote( 'post', absint( $request->get_param( 'id' ) ), $request );
	}

	/**
	 * DELETE /posts/{id}/vote — toggle off by re-casting the same value.
	 */
	public function unvote_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_unvote( 'post', absint( $request->get_param( 'id' ) ) );
	}

	/**
	 * POST /replies/{id}/vote
	 */
	public function vote_reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_vote( 'reply', absint( $request->get_param( 'id' ) ), $request );
	}

	/**
	 * DELETE /replies/{id}/vote — toggle off by re-casting the same value.
	 */
	public function unvote_reply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->handle_unvote( 'reply', absint( $request->get_param( 'id' ) ) );
	}

	/**
	 * Shared vote handler for both posts and replies.
	 *
	 * @param string          $type    'post' or 'reply'.
	 * @param int             $id      Object ID.
	 * @param WP_REST_Request $request
	 */
	private function handle_vote( string $type, int $id, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$object = $this->load_object( $type, $id );
		if ( is_wp_error( $object ) ) {
			return $object;
		}

		// Resolve space_id — replies don't have space_id directly.
		if ( 'reply' === $type ) {
			$parent_post = Post::find( (int) $object->post_id );
			$space_id    = $parent_post ? (int) $parent_post->space_id : 0;
		} else {
			$space_id = (int) ( $object->space_id ?? 0 );
		}

		if ( ! $this->check_permission( 'vote', $space_id ) ) {
			return $this->permission_error();
		}

		// Rate limit check.
		$profile = UserProfile::find_or_create( $user_id );
		$trust   = (int) ( $profile->trust_level ?? 0 );
		if ( ! \Jetonomy\Permissions\Rate_Limiter::check( $user_id, 'vote', $trust ) ) {
			return $this->validation_error( __( 'Rate limit exceeded. Please try again later.', 'jetonomy' ) );
		}

		$value = (int) $request->get_param( 'value' );
		if ( ! in_array( $value, [ 1, -1 ], true ) ) {
			return $this->validation_error( __( 'Vote value must be 1 or -1.', 'jetonomy' ) );
		}

		$result = Vote::cast( $user_id, $type, $id, $value );

		// Increment rate limit counter.
		\Jetonomy\Permissions\Rate_Limiter::increment( $user_id, 'vote' );

		// Fire action for Notifier.
		do_action( 'jetonomy_after_vote', $type, $id, $user_id );

		// Award or adjust reputation on the object author.
		$this->maybe_adjust_reputation( $type, $value, $result, (int) $object->author_id );

		// Re-fetch current score.
		$updated = $this->load_object( $type, $id );
		$score   = is_wp_error( $updated ) ? 0 : (int) ( $updated->vote_score ?? 0 );

		return new WP_REST_Response(
			array_merge( $result, [ 'score' => $score ] ),
			200
		);
	}

	/**
	 * Handle DELETE /vote by re-casting the existing vote value (toggle off).
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Object ID.
	 */
	private function handle_unvote( string $type, int $id ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$object = $this->load_object( $type, $id );
		if ( is_wp_error( $object ) ) {
			return $object;
		}

		// Resolve space_id — replies don't have space_id directly.
		if ( 'reply' === $type ) {
			$parent_post = Post::find( (int) $object->post_id );
			$space_id    = $parent_post ? (int) $parent_post->space_id : 0;
		} else {
			$space_id = (int) ( $object->space_id ?? 0 );
		}

		if ( ! $this->check_permission( 'vote', $space_id ) ) {
			return $this->permission_error();
		}

		$existing = Vote::get_user_vote( $user_id, $type, $id );
		if ( null === $existing ) {
			return new WP_REST_Response( [ 'action' => 'none', 'score' => (int) ( $object->vote_score ?? 0 ) ], 200 );
		}

		// Re-casting the same value toggles the vote off (handled in Vote::cast).
		$result = Vote::cast( $user_id, $type, $id, $existing );

		$updated = $this->load_object( $type, $id );
		$score   = is_wp_error( $updated ) ? 0 : (int) ( $updated->vote_score ?? 0 );

		return new WP_REST_Response(
			array_merge( $result, [ 'score' => $score ] ),
			200
		);
	}

	/**
	 * Load a post or reply object by type and ID.
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id
	 * @return object|WP_Error
	 */
	private function load_object( string $type, int $id ): object|WP_Error {
		if ( 'post' === $type ) {
			$object = Post::find( $id );
			$label  = 'Post';
		} else {
			$object = Reply::find( $id );
			$label  = 'Reply';
		}

		if ( ! $object ) {
			return $this->not_found( $label );
		}

		return $object;
	}

	/**
	 * Apply reputation changes to the object author based on the vote result.
	 *
	 * @param string $type      'post' or 'reply'.
	 * @param int    $value     The vote value (1 or -1).
	 * @param array  $result    The result from Vote::cast().
	 * @param int    $author_id The object author's user ID.
	 */
	private function maybe_adjust_reputation( string $type, int $value, array $result, int $author_id ): void {
		if ( ! $author_id ) {
			return;
		}

		$action    = $result['action'];
		$old_value = $result['old_value'];

		// No reputation change if nothing happened.
		if ( 'none' === $action ) {
			return;
		}

		$base_rep = $this->reputation_delta_for( $type, $value );

		if ( 'created' === $action ) {
			// New vote: apply full reputation delta.
			UserProfile::adjust_reputation( $author_id, $base_rep );
			return;
		}

		if ( 'removed' === $action ) {
			// Undo vote: reverse the reputation.
			UserProfile::adjust_reputation( $author_id, -$base_rep );
			return;
		}

		if ( 'updated' === $action && null !== $old_value ) {
			// Changed vote direction: reverse old and apply new.
			$old_rep = $this->reputation_delta_for( $type, $old_value );
			UserProfile::adjust_reputation( $author_id, -$old_rep + $base_rep );
		}
	}

	/**
	 * Return the reputation delta for a given object type and vote direction.
	 *
	 * @param string $type  'post' or 'reply'.
	 * @param int    $value 1 (upvote) or -1 (downvote).
	 * @return int
	 */
	private function reputation_delta_for( string $type, int $value ): int {
		if ( $value > 0 ) {
			return 'post' === $type ? self::REP_POST_UPVOTE : self::REP_REPLY_UPVOTE;
		}

		return self::REP_DOWNVOTE;
	}

	/**
	 * Shared args for vote endpoints.
	 */
	private function get_vote_args(): array {
		return [
			'value' => [
				'type'     => 'integer',
				'required' => true,
				'enum'     => [ 1, -1 ],
			],
		];
	}
}
