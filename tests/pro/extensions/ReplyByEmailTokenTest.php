<?php
namespace Jetonomy\Tests\Pro\Extensions;

use WP_UnitTestCase;

/**
 * A bad reply token is the sender's fault, so it must reach REST as a 400.
 *
 * decode_token() returned WP_Errors with no status, and the public inbound
 * webhook passed them straight through, where WordPress defaults to 500 -
 * every malformed inbound email paged uptime monitoring.
 */
class ReplyByEmailTokenTest extends WP_UnitTestCase {

	private const CLASS_NAME = 'Jetonomy_Pro\\Extensions\\Reply_By_Email\\Extension';

	private function decode( string $token ): \WP_Error {
		if ( ! class_exists( self::CLASS_NAME ) ) {
			$this->markTestSkipped( 'Jetonomy Pro reply-by-email extension not loaded.' );
		}
		$extension = ( new \ReflectionClass( self::CLASS_NAME ) )->newInstanceWithoutConstructor();
		$method    = new \ReflectionMethod( self::CLASS_NAME, 'decode_token' );
		$method->setAccessible( true );

		$result = $method->invoke( $extension, $token );
		$this->assertInstanceOf( \WP_Error::class, $result );
		return $result;
	}

	private function signed_token( int $expires ): string {
		$data      = [
			'u' => 1,
			'p' => 1,
			't' => 1,
			'e' => $expires,
		];
		$data['h'] = wp_hash( $data['u'] . ':' . $data['p'] . ':' . $data['t'] . ':' . $data['e'] );
		return rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function test_every_bad_token_is_a_400_not_a_500(): void {
		$cases = [
			'malformed'      => '%%%',
			'bad structure'  => 'e30',
			'bad signature'  => 'eyJ1IjoxLCJwIjoxLCJ0IjoxLCJlIjoxLCJoIjoieCJ9',
			'signed, expired' => $this->signed_token( time() - 60 ),
		];

		foreach ( $cases as $label => $token ) {
			$response = rest_convert_error_to_response( $this->decode( $token ) );
			$this->assertSame( 400, $response->get_status(), $label );
		}
	}
}
