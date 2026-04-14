<?php
/**
 * Scenario: end-to-end membership approval flow — space → request → approve → post.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Scenarios;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Content_Journey;
use Jetonomy\CLI\Journeys\Member_Journey;
use Jetonomy\CLI\Journeys\Space_Journey;
use Jetonomy\CLI\Journeys\Taxonomy_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the full membership approval pipeline in one scenario: fresh category,
 * approval-gated space, subscriber user, submitted join request, admin approval,
 * then a post from the newly-approved user so the activity log and feed can be
 * verified end-to-end.
 *
 * Admin approval uses user id 1 (the admin seeded by WordPress core's install
 * routine and the WP test suite) as the reviewer. If the site has no id-1 user
 * the approval step will fail cleanly with a model-level error.
 */
final class Full_Membership_Approval_Flow extends Abstract_Scenario {

	public static function name(): string {
		return 'full-membership-approval-flow';
	}

	public static function description(): string {
		return 'End-to-end approval: create space + request + approval + first post from the approved user.';
	}

	public function run( array $options = [] ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$suffix     = uniqid();
		$taxonomy   = new Taxonomy_Journey();
		$space_srv  = new Space_Journey();
		$member_srv = new Member_Journey();
		$content    = new Content_Journey();

		$fixtures = [
			'category_id' => 0,
			'space_id'    => 0,
			'user_id'     => 0,
			'request_id'  => 0,
			'post_id'     => 0,
		];

		$cat = $this->step(
			'create-category',
			static fn (): Journey_Result => $taxonomy->create_category(
				[
					'name' => 'Approval flow cat',
					'slug' => 'fmaf-cat-' . $suffix,
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
					'title'       => 'Approval flow space',
					'slug'        => 'fmaf-space-' . $suffix,
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
				$login = 'fmaf_user_' . $suffix;
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
				'Approval flow scenario request'
			)
		);
		if ( null === $request ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['request_id'] = (int) $request->data['id'];

		$approved = $this->step(
			'approve-join-request',
			static fn (): Journey_Result => $member_srv->approve_join_request(
				(int) $request->data['id'],
				1
			)
		);
		if ( null === $approved ) {
			return $this->finalize( $fixtures, $start );
		}

		$post = $this->step(
			'create-first-post',
			static fn (): Journey_Result => $content->create_post(
				[
					'space_id'  => (int) $space->data['id'],
					'author_id' => (int) $user->data['id'],
					'title'     => 'Hello from approval flow',
					'content'   => 'This post was created after the approval flow completed.',
				]
			)
		);
		if ( null === $post ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['post_id'] = (int) $post->data['id'];

		return $this->finalize( $fixtures, $start );
	}

	public function cleanup( array $fixtures ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$content    = new Content_Journey();
		$member_srv = new Member_Journey();
		$space_srv  = new Space_Journey();
		$taxonomy   = new Taxonomy_Journey();

		$post_id  = (int) ( $fixtures['post_id'] ?? 0 );
		$user_id  = (int) ( $fixtures['user_id'] ?? 0 );
		$space_id = (int) ( $fixtures['space_id'] ?? 0 );
		$cat_id   = (int) ( $fixtures['category_id'] ?? 0 );

		if ( $post_id > 0 ) {
			$this->step(
				'delete-post',
				static fn (): Journey_Result => $content->delete_post( $post_id )
			);
			$this->failed = false;
		}

		if ( $space_id > 0 && $user_id > 0 ) {
			$this->step(
				'leave-space',
				static fn (): Journey_Result => $member_srv->leave( $space_id, $user_id )
			);
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
