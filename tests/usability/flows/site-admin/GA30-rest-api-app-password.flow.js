// @ts-check
/**
 * GA30 — REST API app password
 *
 * Verify the Jetonomy REST API namespace is registered, the index endpoint
 * returns 200, and a specific endpoint (spaces list) returns valid data.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA30 — REST API endpoint responds with 200', () => {

	const specId = 'GA30';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'jetonomy/v1 namespace is registered', () => {
		const namespaces = wp( [ 'eval', `
			$server = rest_get_server();
			$ns = $server->get_namespaces();
			echo in_array( 'jetonomy/v1', $ns, true ) ? 'yes' : 'no';
		` ] );
		expect( namespaces ).toBe( 'yes' );
	} );

	test( 'jetonomy/v1 index returns 200 with routes via internal dispatch', () => {
		const result = wp( [ 'eval', `
			$request  = new WP_REST_Request( 'GET', '/jetonomy/v1' );
			$response = rest_do_request( $request );
			echo wp_json_encode( [
				'status'     => $response->get_status(),
				'has_routes' => ! empty( $response->get_data()['routes'] ),
				'route_count' => count( $response->get_data()['routes'] ?? [] ),
			] );
		` ], { json: true } );

		expect( result.status ).toBe( 200 );
		expect( result.has_routes ).toBe( true );
		expect( result.route_count ).toBeGreaterThan( 0 );
	} );

	test( 'jetonomy/v1/spaces endpoint returns 200 with data', () => {
		const result = wp( [ 'eval', `
			wp_set_current_user( 1 );
			$request  = new WP_REST_Request( 'GET', '/jetonomy/v1/spaces' );
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			echo wp_json_encode( [
				'status'     => $response->get_status(),
				'is_array'   => is_array( $data ),
				'item_count' => is_array( $data ) ? count( $data ) : 0,
			] );
		` ], { json: true } );

		expect( result.status ).toBe( 200 );
		expect( result.is_array ).toBe( true );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			namespace_registered: true,
			index_status_200: true,
			has_routes: true,
			spaces_endpoint_returns_200: result.status === 200,
			spaces_endpoint_returns_array: result.is_array,
		} );
	} );
} );
