<?php
namespace Jetonomy\Tests\Integration\API;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\API\Posts_Controller;

/**
 * Single-post .27: a link to a host the preview will not fetch answers "no
 * preview" (200, empty card data) rather than a 400 that every reader's
 * browser logs as a console error. Every refusal gets the same answer, so the
 * SSRF guard still cannot be used to tell "no DNS" from "internal address".
 */
class LinkPreviewRefusalTest extends WP_UnitTestCase {

	private function preview( string $url ): \WP_REST_Response|\WP_Error {
		$req = new WP_REST_Request( 'GET', '/jetonomy/v1/link-preview' );
		$req->set_param( 'url', $url );
		return ( new Posts_Controller() )->link_preview( $req );
	}

	public function test_unfetchable_urls_answer_an_empty_preview_without_fetching(): void {
		add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'a refused URL must never be fetched' );
			}
		);

		$shapes = array();
		foreach ( array( 'http://no-such-host.invalid/page', 'http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/' ) as $url ) {
			$res = $this->preview( $url );
			$this->assertInstanceOf( \WP_REST_Response::class, $res, $url . ' is not an error' );
			$this->assertSame( 200, $res->get_status(), $url );
			$data = $res->get_data();
			$this->assertSame( '', $data['title'] . $data['description'] . $data['image'] . $data['embed_html'], $url . ' has no card content' );
			unset( $data['url'], $data['original_url'], $data['domain'] );
			$shapes[] = $data;
		}
		$this->assertSame( $shapes[0], $shapes[1], 'no DNS and loopback answer alike' );
		$this->assertSame( $shapes[0], $shapes[2], 'no DNS and cloud metadata answer alike' );
	}
}
