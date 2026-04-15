<?php
/**
 * Scenario: approval-gated space with a single pending join request.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Scenarios;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Member_Journey;
use Jetonomy\CLI\Journeys\Space_Journey;
use Jetonomy\CLI\Journeys\Taxonomy_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Reproduces Basecamp bug 9725048839 in one command — seeds a fresh category,
 * an approval-gated space under it, a subscriber user, and a pending join
 * request from that user against the space, so QA can click through the
 * admin join-request UI without hand-building the fixtures.
 *
 * Every step runs via {@see Abstract_Scenario::step()} so a failure halts
 * execution and the remaining steps are skipped. Cleanup denies the pending
 * request (materializing nothing) and deletes the user, space, and category
 * in reverse creation order.
 */
final class Space_With_Pending_Join_Request extends Abstract_Scenario {

	public static function name(): string {
		return 'space-with-pending-join-request';
	}

	public static function description(): string {
		return 'Seeds an approval-gated space with one pending join request from a fresh subscriber.';
	}

	public function run( array $options = [] ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$suffix     = uniqid();
		$taxonomy   = new Taxonomy_Journey();
		$space_srv  = new Space_Journey();
		$member_srv = new Member_Journey();

		$fixtures = [
			'category_id' => 0,
			'space_id'    => 0,
			'user_id'     => 0,
			'request_id'  => 0,
		];

		$cat = $this->step(
			'create-category',
			static fn (): Journey_Result => $taxonomy->create_category(
				[
					'name' => 'Scenario cat',
					'slug' => 'sc-' . $suffix,
				]
			)
		);
		if ( null === $cat ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['category_id'] = (int) $cat->data['id'];

		$space = $this->step(
			'create-space',
			static fn (): Journey_Result => $space_srv->create(
				[
					'title'       => 'Scenario space',
					'slug'        => 'ss-' . $suffix,
					'category_id' => (int) $cat->data['id'],
					'type'        => 'forum',
					'visibility'  => 'public',
					'join_policy' => 'approval',
				]
			)
		);
		if ( null === $space ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['space_id'] = (int) $space->data['id'];

		$user = $this->step(
			'create-user',
			static function () use ( $suffix ): Journey_Result {
				$login = 'sc_user_' . $suffix;
				$uid   = wp_insert_user(
					[
						'user_login' => $login,
						'user_email' => $login . '@example.test',
						'user_pass'  => wp_generate_password( 16, false ),
						'role'       => 'subscriber',
					]
				);
				if ( is_wp_error( $uid ) ) {
					return Journey_Result::from_wp_error( $uid );
				}
				return Journey_Result::ok( [ 'id' => (int) $uid ] );
			}
		);
		if ( null === $user ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['user_id'] = (int) $user->data['id'];

		$request = $this->step(
			'submit-join-request',
			static fn (): Journey_Result => $member_srv->submit_join_request(
				(int) $space->data['id'],
				(int) $user->data['id'],
				'Please let me in'
			)
		);
		if ( null === $request ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['request_id'] = (int) $request->data['id'];

		return $this->finalize( $fixtures, $start );
	}

	public function cleanup( array $fixtures ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$space_srv  = new Space_Journey();
		$taxonomy   = new Taxonomy_Journey();
		$member_srv = new Member_Journey();

		$request_id = (int) ( $fixtures['request_id'] ?? 0 );
		$user_id    = (int) ( $fixtures['user_id'] ?? 0 );
		$space_id   = (int) ( $fixtures['space_id'] ?? 0 );
		$cat_id     = (int) ( $fixtures['category_id'] ?? 0 );

		if ( $request_id > 0 ) {
			$this->step(
				'deny-join-request',
				static fn (): Journey_Result => $member_srv->deny_join_request( $request_id, 1 )
			);
			// Deny failures are non-fatal — we still want to delete fixtures.
			$this->failed = false;
		}

		if ( $user_id > 0 ) {
			$this->step(
				'delete-user',
				static function () use ( $user_id ): Journey_Result {
					if ( ! function_exists( 'wp_delete_user' ) ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
					}
					$ok = wp_delete_user( $user_id );
					return $ok
						? Journey_Result::ok( [ 'id' => $user_id ] )
						: Journey_Result::fail( sprintf( 'wp_delete_user(%d) returned false.', $user_id ) );
				}
			);
			$this->failed = false;
		}

		if ( $space_id > 0 ) {
			$this->step(
				'delete-space',
				static fn (): Journey_Result => $space_srv->delete( $space_id )
			);
			$this->failed = false;
		}

		if ( $cat_id > 0 ) {
			$this->step(
				'delete-category',
				static fn (): Journey_Result => $taxonomy->delete_category( $cat_id )
			);
			$this->failed = false;
		}

		return $this->finalize( $fixtures, $start );
	}
}
