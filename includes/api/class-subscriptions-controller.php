<?php
/**
 * Subscriptions REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Models\Space;
use Jetonomy\Models\Subscription;

class Subscriptions_Controller extends Base_Controller {

	protected $rest_base = 'subscriptions';

	/**
	 * Register all REST routes for subscriptions.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Collection.
		register_rest_route(
			$ns,
			'/subscriptions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => $this->get_create_args(),
				),
			)
		);

		// Single item.
		register_rest_route(
			$ns,
			'/subscriptions/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			)
		);
	}

	/**
	 * GET /subscriptions — List subscriptions for the current user.
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];

		// ?object_type=&object_id= narrows to that one object. The follow buttons
		// delete whatever this returns first, so ignoring the filter unfollowed the
		// member's newest subscription instead of the topic they clicked.
		$object_type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( $request->get_param( 'object_id' ) );
		if ( '' !== $object_type || $object_id ) {
			$row    = in_array( $object_type, array( 'space', 'post' ), true ) && $object_id
				? Subscription::find_for( $user_id, $object_type, $object_id )
				: null;
			$rows   = $row ? array( $row ) : array();
			$total  = count( $rows );
			$offset = 0;
		} else {
			$rows  = Subscription::list_for_user( $user_id, $limit, $offset );
			$total = Subscription::count_for_user( $user_id );
		}

		$items = array_map( array( $this, 'prepare_subscription' ), $rows );
		$items = $this->attach_subscription_targets( $items );
		$items = Subscription::attach_delivery( $items, $user_id );

		return $this->paginated_response(
			$items,
			array(
				'total'  => $total,
				'offset' => $offset,
			)
		);
	}

	/**
	 * Attach the title of whatever each subscription points at.
	 *
	 * The rows carry only object_type + object_id, so a client had nothing
	 * human-readable to show and could only render a row of identical "Open post"
	 * buttons — a subscriptions list that cannot tell you WHAT you subscribed to
	 * is not a list, it's a row of mystery links.
	 *
	 * Batch-loaded: two queries for the whole page (one for posts, one for
	 * spaces), never a lookup per row.
	 *
	 * @param array[] $items Prepared subscription rows.
	 * @return array[]
	 */
	private function attach_subscription_targets( array $items ): array {
		// Moved to the model (1.9.0) so the My Subscriptions template and this
		// controller share ONE resolver - including the soft-delete and
		// title-column lessons documented there.
		return Subscription::attach_targets( $items );
	}

	/**
	 * POST /subscriptions — Subscribe to a space or post.
	 */
	public function create_item( $request ) {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$object_type = sanitize_key( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( $request->get_param( 'object_id' ) );

		if ( ! in_array( $object_type, array( 'space', 'post' ), true ) ) {
			// jt-terms-ok: literal object_type parameter values for API callers.
			return $this->validation_error( __( 'object_type must be "space" or "post".', 'jetonomy' ) );
		}

		if ( ! $object_id ) {
			return $this->validation_error( __( 'A valid object_id is required.', 'jetonomy' ) );
		}

		$via = sanitize_key( (string) ( $request->get_param( 'via' ) ?? 'both' ) );
		if ( ! in_array( $via, array( 'web', 'email', 'both' ), true ) ) {
			$via = 'both';
		}

		if ( 'post' === $object_type ) {
			$live = \Jetonomy\Permissions\Content_Gate::target_is_live( 'post', $object_id, $user_id );
			if ( is_wp_error( $live ) ) {
				return $live;
			}
		} elseif ( ! Space::find( $object_id ) || ! \Jetonomy\Permissions\Permission_Engine::can( $user_id, 'read', $object_id ) ) {
			// Same answer for a missing space and one this member may not read.
			return $this->not_found( 'Space' );
		}

		$id = Subscription::subscribe( $user_id, $object_type, $object_id, $via );

		// When INSERT IGNORE fires on a duplicate, insert_id is 0.
		// Fetch the row regardless to return a consistent response.
		$row = Subscription::find_for( $user_id, $object_type, $object_id );

		$status = $id > 0 ? 201 : 200;

		$item = $row ? Subscription::attach_delivery( array( $this->prepare_subscription( $row ) ), $user_id )[0] : array();

		return new WP_REST_Response( $item, $status );
	}

	/**
	 * DELETE /subscriptions/{id} — Remove a subscription.
	 */
	public function delete_item( $request ) {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$id = absint( $request->get_param( 'id' ) );

		$subscription = Subscription::find( $id );

		if ( ! $subscription ) {
			return $this->not_found( 'Subscription' );
		}

		// Verify ownership.
		if ( (int) $subscription->user_id !== $user_id ) {
			return $this->permission_error();
		}

		Subscription::unsubscribe(
			$user_id,
			$subscription->object_type,
			(int) $subscription->object_id
		);

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	/**
	 * Format a subscription row for API output.
	 */
	private function prepare_subscription( ?object $subscription ): array {
		if ( ! $subscription ) {
			return array();
		}

		return array(
			'id'          => (int) $subscription->id,
			'user_id'     => (int) $subscription->user_id,
			'object_type' => $subscription->object_type ?? '',
			'object_id'   => (int) $subscription->object_id,
			// Effective delivery ('both'|'web'|'email'|'none'), overwritten by
			// Subscription::attach_delivery() from the member's notification
			// preferences. The stored notify_via is only a fallback for an
			// object type with no mapped notification.
			'via'         => $subscription->notify_via ?? 'both',
			'created_at'  => $subscription->created_at ?? null,
		);
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return array(
			'object_type' => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => array( 'space', 'post' ),
			),
			'object_id'   => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
			// Accepted and stored for back-compat; it does not change delivery.
			// Channels follow the member's notification preferences, and the
			// response's `via` reports that effective channel.
			'via'         => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'web', 'email', 'both' ),
				'default'  => 'both',
			),
		);
	}
}
