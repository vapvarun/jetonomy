<?php
/**
 * Admin REST API controller — site-wide maintenance operations.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Jetonomy\API\REST_Auth;
use Jetonomy\Recount;
use Jetonomy\Models\UserProfile;

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
						'type'              => 'string',
						'required'          => false,
						'default'           => 'all',
						'enum'              => array( 'all', 'posts', 'spaces', 'votes', 'users' ),
						'sanitize_callback' => 'sanitize_text_field',
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
				'permission_callback' => REST_Auth::auth_mutation( 'manage_options' ),
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
			UserProfile::find_or_create( $uid );
			$profile   = UserProfile::find_by_user( $uid );
			$old_level = $profile ? (int) ( $profile->trust_level ?? 0 ) : 0;

			// Nothing to do when the level already matches — keeps the activity log clean.
			if ( $old_level === $level ) {
				$results['updated'][] = $uid;
				continue;
			}

			$ok = UserProfile::update_profile( $uid, array( 'trust_level' => $level ) );
			if ( $ok ) {
				$results['updated'][] = $uid;
				do_action( 'jetonomy_trust_level_changed', $uid, $old_level, $level );
			} else {
				$results['skipped'][] = array(
					'user_id' => $uid,
					'reason'  => 'update_failed',
				);
			}
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
