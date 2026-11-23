<?php
/**
 * Admin REST API controller — site-wide maintenance operations.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Admin\Ajax\Demo_Seeder;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Recount;

class Admin_Controller extends Base_Controller {

	protected $rest_base = 'admin';

	/**
	 * Register all admin REST routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/admin/recount',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'recount' ),
				'permission_callback' => REST_Auth::auth_mutation( 'manage_options' ),
				'args'                => array(
					'type' => array(
						'type'     => 'string',
						'required' => false,
						'default'  => 'all',
						// No sanitize_callback: a custom one replaces rest_parse_request_arg(), so the enum would never be checked.
						'enum'     => array( 'all', 'posts', 'spaces', 'votes', 'users' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/users/trust-level',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_set_trust_level' ),
				'permission_callback' => REST_Auth::auth_mutation( 'jetonomy_manage_users' ),
				'args'                => array(
					'user_ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
					'level'    => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
						'maximum'  => 5,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/admin/demo-data',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'demo_data_status' ),
					'permission_callback' => REST_Auth::auth_mutation( 'manage_options' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_demo_data' ),
					'permission_callback' => REST_Auth::auth_mutation( 'manage_options' ),
					'args'                => array(
						'confirm' => array(
							'description' => __( 'Must be true: importing replaces the current demo set.', 'jetonomy' ),
							'type'        => 'boolean',
							'required'    => true,
						),
						'force'   => array(
							'description' => __( 'True to import even though the community already has its own spaces or topics.', 'jetonomy' ), // jt-terms-ok: REST schema description for API developers.
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_demo_data' ),
					'permission_callback' => REST_Auth::auth_mutation( 'manage_options' ),
					'args'                => array(
						'confirm' => array(
							'description' => __( 'Must be true: removing the demo set cannot be undone.', 'jetonomy' ),
							'type'        => 'boolean',
							'required'    => true,
						),
					),
				),
			)
		);

		// Site settings (jetonomy_settings). manage_options on purpose: stricter than
		// the form's delegated jetonomy_manage_settings, because an app token reaches
		// further than a logged-in screen. Role capabilities are not reachable here.
		register_rest_route(
			$ns,
			'/admin/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => REST_Auth::auth_mutation( 'manage_options' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => REST_Auth::auth_mutation( 'manage_options' ),
					'args'                => array(
						'settings' => array(
							'description' => __( 'Setting key => new value. Only keys GET /admin/settings lists; at most 50 per request.', 'jetonomy' ),
							'type'        => 'object',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /admin/settings - Every setting the API can change, secrets masked.
	 *
	 * @since 2.1.0
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( array( 'settings' => \Jetonomy\Settings::read() ), 200 );
	}

	/**
	 * POST /admin/settings - Change settings through the admin form's sanitizer.
	 *
	 * Answers the cleaned values of the keys sent, plus any notice the sanitizer
	 * raised (an address it refused and kept the old value for, for example).
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = \Jetonomy\Settings::write( (array) $request->get_param( 'settings' ), 'rest' );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /admin/demo-data - Whether demo data is loaded, and how much.
	 */
	public function demo_data_status(): WP_REST_Response {
		return new WP_REST_Response( Demo_Seeder::summary(), 200 );
	}

	/**
	 * POST /admin/demo-data - Load the demo community (replaces an earlier set). Needs confirm=true, and force=true
	 * when the community already has its own spaces or topics, so a stray request never seeds a live community.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function import_demo_data( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'jetonomy_confirm_required', __( 'Send confirm=true to import demo data.', 'jetonomy' ), array( 'status' => 400 ) );
		}
		$demo = Demo_Seeder::import( get_current_user_id(), true === $request->get_param( 'force' ) );
		if ( is_wp_error( $demo ) ) {
			return $demo;
		}
		$data            = Demo_Seeder::summary();
		$data['message'] = __( 'Demo data imported.', 'jetonomy' );
		return new WP_REST_Response( $data, 201 );
	}

	/**
	 * DELETE /admin/demo-data - Remove the demo set; owner content is untouched.
	 */
	public function remove_demo_data( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( true !== $request->get_param( 'confirm' ) ) {
			return new WP_Error( 'jetonomy_confirm_required', __( 'Send confirm=true to remove the demo data.', 'jetonomy' ), array( 'status' => 400 ) );
		}
		if ( ! Demo_Seeder::remove() ) {
			return new WP_Error( 'jetonomy_no_demo_data', __( 'No demo data found to clean up.', 'jetonomy' ), array( 'status' => 404 ) );
		}
		$data            = Demo_Seeder::summary();
		$data['message'] = __( 'All demo data has been removed.', 'jetonomy' );
		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * POST /admin/recount — Rebuild denormalized counters.
	 */
	public function recount( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type  = (string) $request->get_param( 'type' );
		$stats = Recount::run( $type );

		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'type'    => $type,
				'updated' => $stats,
			),
			200
		);
	}

	/**
	 * POST /admin/users/trust-level — Bulk-promote/demote members to a trust level.
	 *
	 * Payload: { "user_ids": [1, 2, 3], "level": 0-5 }.
	 * Useful after migrations, onboarding imports, or granting a batch of long-standing
	 * members a higher tier. Site-admin only; pairs with the existing per-user
	 * `ajax_change_trust_level` AJAX handler used from wp-admin.
	 */
	public function bulk_set_trust_level( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_ids = (array) $request->get_param( 'user_ids' );
		$level    = max( 0, min( 5, (int) $request->get_param( 'level' ) ) );

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( empty( $user_ids ) ) {
			return new WP_Error(
				'jetonomy_empty_user_ids',
				__( 'At least one user_id is required.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

		$results = array(
			'updated' => array(),
			'skipped' => array(),
		);
		foreach ( $user_ids as $uid ) {
			if ( ! get_userdata( $uid ) ) {
				$results['skipped'][] = array(
					'user_id' => $uid,
					'reason'  => 'not_found',
				);
				continue;
			}
			$set = \Jetonomy\Trust\Trust_Levels::set_manually( $uid, $level, get_current_user_id() );
			if ( is_wp_error( $set ) ) {
				$results['skipped'][] = array(
					'user_id' => $uid,
					'reason'  => 'jetonomy_trust_failed' === $set->get_error_code() ? 'update_failed' : 'forbidden',
				);
				continue;
			}
			$results['updated'][] = $uid;
		}

		return new WP_REST_Response(
			array(
				'status'        => 'ok',
				'level'         => $level,
				'updated_count' => count( $results['updated'] ),
				'updated'       => $results['updated'],
				'skipped'       => $results['skipped'],
			),
			200
		);
	}
}
