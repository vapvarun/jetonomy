<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

/**
 * The test suite never reaches the live store (tests/bootstrap.php). Before this guard, every CI job ran the
 * free-key activation as http://example.org and each push sent dozens of activations to wbcomdesigns.com.
 */
class NoLiveStoreTest extends WP_UnitTestCase {

	public function test_no_licence_call_reaches_the_live_store(): void {
		// The preset activation is switched off for the whole suite.
		$this->assertTrue( defined( 'JETONOMY_NO_LICENSE_PHONE_HOME' ) && JETONOMY_NO_LICENSE_PHONE_HOME );

		// Both store hosts are refused before any request leaves the machine.
		foreach ( array( 'https://wbcomdesigns.com/', 'https://updates.wbcomdesigns.com/' ) as $url ) {
			$response = wp_remote_get( $url );
			$this->assertWPError( $response, $url );
			$this->assertSame( 'jetonomy_tests_no_store', $response->get_error_code(), $url );
		}

		// The licence helper (the one path every licence call takes) therefore gets no reply.
		$this->assertFalse(
			jetonomy_licence_request(
				array(
					'edd_action' => 'check_license',
					'item_id'    => 1660320,
				)
			)
		);
	}
}
