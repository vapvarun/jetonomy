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
use Jetonomy\Models\Subscription;
use function Jetonomy\table;

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
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_items' ],
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
					'args'                => $this->get_create_args(),
				],
			]
		);

		// Single item.
		register_rest_route(
			$ns,
			'/subscriptions/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_item' ],
				'permission_callback' => REST_Auth::auth_mutation( 'read' ),
			]
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

		global $wpdb;
		$tbl = table( 'subscriptions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$tbl} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			)
		) ?: [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$tbl} WHERE user_id = %d",
				$user_id
			)
		);

		$items = array_map( [ $this, 'prepare_subscription' ], $rows );
		$items = $this->attach_subscription_targets( $items );

		return $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $offset,
			]
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
		$post_ids  = [];
		$space_ids = [];
		foreach ( $items as $item ) {
			if ( 'post' === ( $item['object_type'] ?? '' ) ) {
				$post_ids[] = (int) $item['object_id'];
			} elseif ( 'space' === ( $item['object_type'] ?? '' ) ) {
				$space_ids[] = (int) $item['object_id'];
			}
		}

		global $wpdb;
		$titles = [];

		if ( $post_ids ) {
			$posts_tbl = table( 'posts' );
			$ph        = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			// Exclude the statuses that mean "gone". DELETE /posts/{id} is a SOFT
			// delete — the REST controller does it, not the model
			// (class-posts-controller.php:852 calls Post::update($id, status=>trash);
			// Post::delete() itself is a HARD delete). Without this filter a deleted
			// post still resolved to a live title and `exists` stayed true — the
			// subscription looked tappable and led nowhere. `exists` only ever
			// caught HARD-deleted rows, which is why the card's suggestion of
			// "rely on exists" could not work: the REST delete never hard-deletes.
			//
			// Excluding rather than allow-listing `publish` on purpose. A pending or
			// draft post is not gone — its author is subscribed to their own post
			// while it waits on moderation — and rendering that as "no longer
			// available" would be the same silent mis-render as the name/title bug
			// above, just pointed the other way.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, slug FROM {$posts_tbl} WHERE id IN ({$ph}) AND status NOT IN ('trash','spam')",
					...$post_ids
				)
			) ?: [];
			foreach ( $rows as $row ) {
				$titles[ 'post:' . (int) $row->id ] = [
					'title' => $row->title ?? '',
					'slug'  => $row->slug ?? '',
				];
			}
		}

		if ( $space_ids ) {
			$spaces_tbl = table( 'spaces' );
			$ph         = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			// The column is `title`, not `name` — jt_spaces has never had a `name`.
			// The wrong name did not throw: the query errored, get_results() returned
			// [], and the loop simply enriched nothing. So every space subscription
			// came back title='' slug='' exists=false and the app drew a LIVE space
			// as "Space no longer available", greyed and untappable — a silent
			// mis-render rather than a visible failure (Basecamp 10092766769).
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, slug FROM {$spaces_tbl} WHERE id IN ({$ph})", ...$space_ids ) ) ?: [];
			foreach ( $rows as $row ) {
				$titles[ 'space:' . (int) $row->id ] = [
					'title' => $row->title ?? '',
					'slug'  => $row->slug ?? '',
				];
			}
		}

		foreach ( $items as &$item ) {
			$key           = ( $item['object_type'] ?? '' ) . ':' . (int) ( $item['object_id'] ?? 0 );
			$target        = $titles[ $key ] ?? null;
			$item['title'] = $target['title'] ?? '';
			$item['slug']  = $target['slug'] ?? '';
			// The target may have been deleted since the subscription was made.
			$item['exists'] = null !== $target;
		}
		unset( $item );

		return $items;
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

		if ( ! in_array( $object_type, [ 'space', 'post' ], true ) ) {
			return $this->validation_error( __( 'object_type must be "space" or "post".', 'jetonomy' ) );
		}

		if ( ! $object_id ) {
			return $this->validation_error( __( 'A valid object_id is required.', 'jetonomy' ) );
		}

		$via = sanitize_key( (string) ( $request->get_param( 'via' ) ?? 'both' ) );
		if ( ! in_array( $via, [ 'web', 'email', 'both' ], true ) ) {
			$via = 'both';
		}

		$id = Subscription::subscribe( $user_id, $object_type, $object_id, $via );

		// When INSERT IGNORE fires on a duplicate, insert_id is 0.
		// Fetch the row regardless to return a consistent response.
		global $wpdb;
		$tbl = table( 'subscriptions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$tbl} WHERE user_id = %d AND object_type = %s AND object_id = %d",
				$user_id,
				$object_type,
				$object_id
			)
		);

		$status = $id > 0 ? 201 : 200;

		return new WP_REST_Response( $this->prepare_subscription( $row ), $status );
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

		global $wpdb;
		$tbl = table( 'subscriptions' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$subscription = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$tbl} WHERE id = %d",
				$id
			)
		);

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
			[
				'deleted' => true,
				'id'      => $id,
			],
			200
		);
	}

	/**
	 * Format a subscription row for API output.
	 */
	private function prepare_subscription( ?object $subscription ): array {
		if ( ! $subscription ) {
			return [];
		}

		return [
			'id'          => (int) $subscription->id,
			'user_id'     => (int) $subscription->user_id,
			'object_type' => $subscription->object_type ?? '',
			'object_id'   => (int) $subscription->object_id,
			'via'         => $subscription->notify_via ?? 'both',
			'created_at'  => $subscription->created_at ?? null,
		];
	}

	/**
	 * Args for create_item.
	 */
	private function get_create_args(): array {
		return [
			'object_type' => [
				'type'     => 'string',
				'required' => true,
				'enum'     => [ 'space', 'post' ],
			],
			'object_id'   => [
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			],
			'via'         => [
				'type'     => 'string',
				'required' => false,
				'enum'     => [ 'web', 'email', 'both' ],
				'default'  => 'both',
			],
		];
	}
}
