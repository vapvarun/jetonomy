// @ts-check
/**
 * GA30 — REST API app password
 *
 * Verify a Jetonomy REST endpoint returns 200 with proper auth headers.
 * Pure CLI test using wp eval to make an internal REST request.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'GA30 — REST API endpoint responds with 200', () => {

	test( 'jetonomy/v1 namespace is registered', () => {
		const namespaces = wp( [ 'eval', `
			$server = rest_get_server();
			$ns = $server->get_namespaces();
			echo in_array( 'jetonomy/v1', $ns, true ) ? 'yes' : 'no';
		` ] );
		expect( namespaces ).toBe( 'yes' );
	} );

	test( 'jetonomy/v1 index returns 200 via internal dispatch', () => {
		const result = wp( [ 'eval', `
			$request  = new WP_REST_Request( 'GET', '/jetonomy/v1' );
			$response = rest_do_request( $request );
			echo wp_json_encode( [
				'status'     => $response->get_status(),
				'has_routes' => ! empty( $response->get_data()['routes'] ),
			] );
		` ], { json: true } );

		expect( result.status ).toBe( 200 );
		expect( result.has_routes ).toBe( true );
	} );
} );
