<?php
/**
 * Sync Members derives the roster role from the stored rule only. Without a
 * matching rule it refuses, rather than writing whatever role the request named.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Permissions\Capabilities;

class AccessRuleSyncRefusalTest extends WP_Ajax_UnitTestCase {

	public function test_sync_without_a_rule_writes_no_role_from_the_request(): void {
		Schema::create_tables();
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account = new \WP_User( $admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		$learner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$space   = (int) Space::create(
			array(
				'title'      => 'Sync ' . uniqid(),
				'slug'       => 'sync-' . uniqid(),
				'visibility' => 'private',
			)
		);
		wp_set_current_user( $admin );
		new \Jetonomy\Admin\Ajax\Spaces_Handler();

		// wp-roles is always active, so the level 'subscriber' matches the learner.
		$_POST = array(
			'nonce'      => wp_create_nonce( 'jetonomy_admin' ),
			'space_id'   => $space,
			'rule_value' => 'subscriber',
			'rule_id'    => 0,
			'space_role' => 'admin',
		);
		try {
			$this->_handleAjax( 'jetonomy_sync_access_rule' );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the JSON response is asserted.
		}
		$response = json_decode( $this->_last_response, true );

		$this->assertFalse( $response['success'] );
		$this->assertNull( SpaceMember::get_role( $space, $learner ), 'nobody is written onto the roster' );
	}
}
