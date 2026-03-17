<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Flag;
use Jetonomy\Models\UserProfile;
use function Jetonomy\table;

class Moderation_Controller extends Base_Controller {

	protected string $rest_base = 'moderation';

	/**
	 * Register REST routes for moderation.
	 */
	public function register_routes(): void {
		$ns = $this->namespace;

		// GET moderation queue.
		register_rest_route( $ns, '/moderation/queue', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_queue' ],
			'permission_callback' => [ $this, 'require_moderate' ],
		] );

		// Approve a post or reply.
		register_rest_route( $ns, '/moderation/approve/(?P<type>post|reply)/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'approve' ],
			'permission_callback' => [ $this, 'require_moderate' ],
		] );

		// Mark a post or reply as spam.
		register_rest_route( $ns, '/moderation/spam/(?P<type>post|reply)/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'mark_spam' ],
			'permission_callback' => [ $this, 'require_moderate' ],
		] );

		// Create a flag report (requires jetonomy_flag, not moderate).
		register_rest_route( $ns, '/flags', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_flag' ],
			'permission_callback' => [ $this, 'require_flag' ],
			'args'                => [
				'object_type' => [
					'type'     => 'string',
					'required' => true,
					'enum'     => [ 'post', 'reply', 'user' ],
				],
				'object_id'   => [
					'type'     => 'integer',
					'required' => true,
					'minimum'  => 1,
				],
				'reason'      => [
					'type'     => 'string',
					'required' => true,
					'enum'     => [ 'spam', 'offensive', 'off_topic', 'harassment', 'other' ],
				],
				'description' => [
					'type'     => 'string',
					'required' => false,
				],
			],
		] );

		// List pending flags.
		register_rest_route( $ns, '/moderation/flags', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_flags' ],
			'permission_callback' => [ $this, 'require_moderate' ],
		] );
	}

	/**
	 * Permission callback: requires jetonomy_moderate capability.
	 */
	public function require_moderate(): bool|WP_Error {
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/**
	 * Permission callback: requires auth + jetonomy_flag capability.
	 */
	public function require_flag(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		if ( ! current_user_can( 'jetonomy_flag' ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/**
	 * GET /moderation/queue — Fetch pending posts, replies, and flag count.
	 */
	public function get_queue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$posts_table   = table( 'posts' );
		$replies_table = table( 'replies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending_posts = $wpdb->get_results(
			"SELECT *, 'post' AS object_type FROM {$posts_table} WHERE status = 'pending' ORDER BY created_at DESC"
		) ?: [];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending_replies = $wpdb->get_results(
			"SELECT *, 'reply' AS object_type FROM {$replies_table} WHERE status = 'pending' ORDER BY created_at DESC"
		) ?: [];

		// Merge and sort by created_at DESC.
		$merged = array_merge( $pending_posts, $pending_replies );
		usort( $merged, function( $a, $b ) {
			return strcmp( $b->created_at, $a->created_at );
		} );

		$pending_flags_count = count( Flag::list_pending() );

		return new WP_REST_Response( [
			'data'                => $merged,
			'pending_flags_count' => $pending_flags_count,
			'meta'                => [
				'count'   => count( $merged ),
				'has_more' => false,
			],
		], 200 );
	}

	/**
	 * POST /moderation/approve/{type}/{id} — Approve a pending post or reply.
	 */
	public function approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );
		$id   = absint( $request->get_param( 'id' ) );

		$result = $this->set_status( $type, $id, 'publish' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( [
			'approved'    => true,
			'object_type' => $type,
			'id'          => $id,
		], 200 );
	}

	/**
	 * POST /moderation/spam/{type}/{id} — Mark a post or reply as spam.
	 *
	 * Applies a -20 reputation penalty to the author.
	 */
	public function mark_spam( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );
		$id   = absint( $request->get_param( 'id' ) );

		global $wpdb;
		$table = table( 'post' === $type ? 'posts' : 'replies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		if ( ! $row ) {
			return $this->not_found( 'post' === $type ? 'Post' : 'Reply' );
		}

		$result = $this->set_status( $type, $id, 'spam' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Apply reputation penalty.
		$author_id = (int) $row->author_id;
		if ( $author_id ) {
			UserProfile::adjust_reputation( $author_id, -20 );
		}

		return new WP_REST_Response( [
			'marked_spam' => true,
			'object_type' => $type,
			'id'          => $id,
		], 200 );
	}

	/**
	 * POST /flags — Create a flag report.
	 */
	public function create_flag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();

		$object_type = sanitize_text_field( (string) $request->get_param( 'object_type' ) );
		$object_id   = absint( $request->get_param( 'object_id' ) );
		$reason      = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$description = sanitize_textarea_field( (string) ( $request->get_param( 'description' ) ?? '' ) );

		$flag_id = Flag::create( [
			'reporter_id' => $user_id,
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'reason'      => $reason,
			'description' => $description,
		] );

		if ( ! $flag_id ) {
			return new WP_Error(
				'jetonomy_flag_failed',
				__( 'Failed to create flag.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response( [
			'created' => true,
			'id'      => $flag_id,
		], 201 );
	}

	/**
	 * GET /moderation/flags — List all pending flags.
	 */
	public function list_flags( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$flags = Flag::list_pending();

		return $this->paginated_response( $flags, [
			'total'    => count( $flags ),
			'has_more' => false,
		] );
	}

	/**
	 * Set the status of a post or reply by ID.
	 *
	 * @param string $type   'post' or 'reply'.
	 * @param int    $id     Row ID.
	 * @param string $status New status value.
	 * @return true|WP_Error
	 */
	private function set_status( string $type, int $id, string $status ): true|WP_Error {
		global $wpdb;
		$table = table( 'post' === $type ? 'posts' : 'replies' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
		if ( ! $exists ) {
			return $this->not_found( 'post' === $type ? 'Post' : 'Reply' );
		}

		$wpdb->update( $table, [ 'status' => $status ], [ 'id' => $id ] );

		return true;
	}
}
