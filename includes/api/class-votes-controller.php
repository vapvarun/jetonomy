<?php
/**
 * Votes REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Vote;
use Jetonomy\Models\UserProfile;

class Votes_Controller extends Base_Controller {

	protected $rest_base = 'votes';

	/**
	 * Register all REST routes for votes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/posts/(?P<id>\d+)/vote',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'vote_post' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => $this->get_vote_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unvote_post' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/replies/(?P<id>\d+)/vote',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'vote_reply' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => $this->get_vote_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unvote_reply' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				),
			)
		);
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

		// Live target, vote permission, value and self-vote rules: shared with WP-CLI.
		// handle_unvote() stays open so an old vote can still be withdrawn.
		$value = (int) $request->get_param( 'value' );
		$gate  = \Jetonomy\Permissions\Content_Gate::check_vote( $user_id, $type, $id, $value );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		// Pressing your active vote again takes it back (Vote::cast toggles it off).
		// A retraction is not a new vote: it must not be refused by, or count
		// toward, the daily limit, or a member at the cap could not undo a mistake.
		$is_retraction = Vote::get_user_vote( $user_id, $type, $id ) === $value;

		// Rate limit check.
		$profile = UserProfile::find_or_create( $user_id );
		$trust   = (int) ( $profile->trust_level ?? 0 );
		if ( ! $is_retraction && ! \Jetonomy\Permissions\Rate_Limiter::check( $user_id, 'vote', $trust ) ) {
			// Rate_Limiter::message() names the allowance and the real wait.
			// A bare "Rate limit exceeded. Please try again later." would not tell a member
			// whether "later" means a minute or a day.
			return \Jetonomy\Permissions\Rate_Limiter::error( $user_id, 'vote', $trust );
		}

		$result = Vote::record( $user_id, $type, $id, $value );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				403
			);
		}

		// Count new and changed votes only. Keyed on what cast() actually did, not
		// the pre-read above, so parallel requests cannot slip an uncounted vote in.
		if ( 'removed' !== $result['action'] ) {
			\Jetonomy\Permissions\Rate_Limiter::increment( $user_id, 'vote' );
		}

		// Re-fetch current score.
		$updated = $this->load_object( $type, $id );
		$score   = is_wp_error( $updated ) ? 0 : (int) ( $updated->vote_score ?? 0 );

		return new WP_REST_Response(
			array_merge( $result, array( 'score' => $score ) ),
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
			return new WP_REST_Response(
				array(
					'action' => 'none',
					'score'  => (int) ( $object->vote_score ?? 0 ),
				),
				200
			);
		}

		// Re-casting the same value toggles the vote off; record() also takes back
		// the author's reputation and the vote notification, as the POST toggle does.
		$result = Vote::record( $user_id, $type, $id, $existing );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				403
			);
		}

		$updated = $this->load_object( $type, $id );
		$score   = is_wp_error( $updated ) ? 0 : (int) ( $updated->vote_score ?? 0 );

		return new WP_REST_Response(
			array_merge( $result, array( 'score' => $score ) ),
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
	private function load_object( string $type, int $id ) {
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
	 * Shared args for vote endpoints.
	 */
	private function get_vote_args(): array {
		return array(
			'value' => array(
				'type'     => 'integer',
				'required' => true,
				'enum'     => array( 1, -1 ),
			),
		);
	}
}
