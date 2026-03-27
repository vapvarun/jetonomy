<?php
/**
 * Moderation REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\UserProfile;
use function Jetonomy\table;

class Moderation_Controller extends Base_Controller {

	protected $rest_base = 'moderation';

	/**
	 * Register REST routes for moderation.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// GET moderation queue.
		register_rest_route(
			$ns,
			'/moderation/queue',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_queue' ],
				'permission_callback' => [ $this, 'require_moderate' ],
			]
		);

		// Approve a post or reply.
		register_rest_route(
			$ns,
			'/moderation/approve/(?P<type>post|reply)/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'approve' ],
				'permission_callback' => [ $this, 'require_moderate' ],
			]
		);

		// Mark a post or reply as spam.
		register_rest_route(
			$ns,
			'/moderation/spam/(?P<type>post|reply)/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'mark_spam' ],
				'permission_callback' => [ $this, 'require_moderate' ],
			]
		);

		// Create a flag report (requires jetonomy_flag, not moderate).
		register_rest_route(
			$ns,
			'/flags',
			[
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
			]
		);

		// List pending flags.
		register_rest_route(
			$ns,
			'/moderation/flags',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_flags' ],
				'permission_callback' => [ $this, 'require_moderate' ],
			]
		);

		// Trash content.
		register_rest_route(
			$ns,
			'/moderation/trash/(?P<type>post|reply)/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'trash_content' ],
				'permission_callback' => [ $this, 'require_moderate' ],
			]
		);

		// Resolve flag.
		register_rest_route(
			$ns,
			'/moderation/flags/(?P<id>\d+)/resolve',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'resolve_flag' ],
				'permission_callback' => [ $this, 'require_moderate' ],
				'args'                => [
					'status' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'valid', 'dismissed' ],
					],
				],
			]
		);

		// Ban user.
		register_rest_route(
			$ns,
			'/moderation/ban',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'ban_user' ],
				'permission_callback' => [ $this, 'require_moderate' ],
				'args'                => [
					'user_id'    => [
						'type'     => 'integer',
						'required' => true,
					],
					'type'       => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'global_ban', 'space_ban', 'silence' ],
					],
					'reason'     => [ 'type' => 'string' ],
					'space_id'   => [ 'type' => 'integer' ],
					'expires_at' => [ 'type' => 'string' ],
				],
			]
		);

		// Unban user.
		register_rest_route(
			$ns,
			'/moderation/ban/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'unban_user' ],
				'permission_callback' => [ $this, 'require_moderate' ],
			]
		);
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
		if ( Restriction::is_silenced( $user_id ) ) {
			return new WP_Error( 'silenced', __( 'You are currently silenced.', 'jetonomy' ), [ 'status' => 403 ] );
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
		usort(
			$merged,
			function ( $a, $b ) {
				return strcmp( $b->created_at, $a->created_at );
			}
		);

		$pending_flags_count = count( Flag::list_pending() );

		return new WP_REST_Response(
			[
				'data'                => $merged,
				'pending_flags_count' => $pending_flags_count,
				'meta'                => [
					'count'    => count( $merged ),
					'has_more' => false,
				],
			],
			200
		);
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

		do_action( 'jetonomy_content_moderated', 'approved', $type, $id, get_current_user_id() );

		return new WP_REST_Response(
			[
				'approved'    => true,
				'object_type' => $type,
				'id'          => $id,
			],
			200
		);
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

		do_action( 'jetonomy_content_moderated', 'spam', $type, $id, get_current_user_id() );

		return new WP_REST_Response(
			[
				'marked_spam' => true,
				'object_type' => $type,
				'id'          => $id,
			],
			200
		);
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

		$flag_id = Flag::create(
			[
				'reporter_id' => $user_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'reason'      => $reason,
				'description' => $description,
			]
		);

		if ( ! $flag_id ) {
			return new WP_Error(
				'jetonomy_flag_failed',
				__( 'Failed to create flag.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		do_action( 'jetonomy_flag_created', $flag_id, $object_type );

		return new WP_REST_Response(
			[
				'created' => true,
				'id'      => $flag_id,
			],
			201
		);
	}

	/**
	 * GET /moderation/flags — List all pending flags.
	 */
	public function list_flags( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$flags = Flag::list_pending();

		return $this->paginated_response(
			$flags,
			[
				'total'    => count( $flags ),
				'has_more' => false,
			]
		);
	}

	/**
	 * POST /moderation/trash/{type}/{id} — Trash (soft-delete) a post or reply.
	 */
	public function trash_content( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type = $request->get_param( 'type' );
		$id   = absint( $request->get_param( 'id' ) );

		$result = $this->set_status( $type, $id, 'trash' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		do_action( 'jetonomy_content_moderated', 'trash', $type, $id, get_current_user_id() );

		return new WP_REST_Response(
			[
				'trashed'     => true,
				'object_type' => $type,
				'id'          => $id,
			],
			200
		);
	}

	/**
	 * POST /moderation/flags/{id}/resolve — Resolve a flag as valid or dismissed.
	 */
	public function resolve_flag( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = absint( $request->get_param( 'id' ) );
		$status = sanitize_text_field( (string) $request->get_param( 'status' ) );

		$flag = Flag::find( $id );
		if ( ! $flag ) {
			return $this->not_found( 'Flag' );
		}

		$resolved = Flag::resolve( $id, get_current_user_id(), $status );

		if ( ! $resolved ) {
			return new WP_Error(
				'jetonomy_resolve_failed',
				__( 'Failed to resolve flag.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'resolved' => true,
				'id'       => $id,
				'status'   => $status,
			],
			200
		);
	}

	/**
	 * POST /moderation/ban — Issue a ban, space ban, or silence.
	 */
	public function ban_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id    = absint( $request->get_param( 'user_id' ) );
		$type       = sanitize_text_field( (string) $request->get_param( 'type' ) );
		$reason     = $request->get_param( 'reason' ) ? sanitize_textarea_field( (string) $request->get_param( 'reason' ) ) : null;
		$space_id   = $request->get_param( 'space_id' ) ? absint( $request->get_param( 'space_id' ) ) : null;
		$expires_at = $request->get_param( 'expires_at' ) ? sanitize_text_field( (string) $request->get_param( 'expires_at' ) ) : null;

		if ( ! get_userdata( $user_id ) ) {
			return $this->not_found( 'User' );
		}

		$restriction_id = Restriction::ban(
			$user_id,
			$type,
			get_current_user_id(),
			$space_id,
			$reason,
			$expires_at
		);

		if ( ! $restriction_id ) {
			return new WP_Error(
				'jetonomy_ban_failed',
				__( 'Failed to issue restriction.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'banned'         => true,
				'restriction_id' => $restriction_id,
				'user_id'        => $user_id,
				'type'           => $type,
			],
			201
		);
	}

	/**
	 * DELETE /moderation/ban/{id} — Lift a restriction by its ID.
	 */
	public function unban_user( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = absint( $request->get_param( 'id' ) );

		$restriction = Restriction::find( $id );
		if ( ! $restriction ) {
			return $this->not_found( 'Restriction' );
		}

		$removed = Restriction::remove_ban( $id );

		if ( ! $removed ) {
			return new WP_Error(
				'jetonomy_unban_failed',
				__( 'Failed to remove restriction.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		return new WP_REST_Response(
			[
				'removed' => true,
				'id'      => $id,
			],
			200
		);
	}

	/**
	 * Set the status of a post or reply by ID.
	 *
	 * @param string $type   'post' or 'reply'.
	 * @param int    $id     Row ID.
	 * @param string $status New status value.
	 * @return bool|WP_Error
	 */
	private function set_status( string $type, int $id, string $status ): bool|WP_Error {
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
