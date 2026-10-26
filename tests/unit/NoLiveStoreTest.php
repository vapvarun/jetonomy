<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

/**
 * The test suite never reaches the live store (tests/bootstrap.php). Before this guard, every CI job ran the
 * free-key activation as http://example.org and each push sent dozens of activations to wbcomdesigns.com.
 */
class NoLiveStoreTest extends WP_UnitTestCase {

	public function test_store_hosts_are_refused(): void {
		foreach ( array( 'https://wbcomdesigns.com/', 'https://updates.wbcomdesigns.com/' ) as $url ) {
			$response = wp_remote_get( $url );
			$this->assertWPError( $response, $url );
			$this->assertSame( 'jetonomy_tests_no_store', $response->get_error_code(), $url );
		}
	}

	public function test_preset_activation_is_switched_off(): void {
		$this->assertTrue( defined( 'JETONOMY_NO_LICENSE_PHONE_HOME' ) && JETONOMY_NO_LICENSE_PHONE_HOME );
	}

	public function test_licence_request_gets_no_reply(): void {
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
