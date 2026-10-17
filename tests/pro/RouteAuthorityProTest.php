<?php
/**
 * Every Pro REST route declares who may reach it, and the code is held to it.
 *
 * Same gate as Free's RouteAuthorityTest, over the routes whose handler lives
 * in Jetonomy Pro, against jetonomy-pro/audit/route-authority.json. Pro mounts
 * most routes inside jetonomy/v1, so ownership is the handler's file, never
 * the namespace.
 *
 * Every extension is booted here, so a route that is missing is missing - an
 * extension's routes are declared "conditional" with the extension that mounts
 * them, and that claim is checked against where the handler actually lives.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy\Tests\Unit\Support\Route_Authority_Checks;

defined( 'ABSPATH' ) || exit;

/**
 * @covers-existing Release gate G2: pins the authority every Pro route already
 * has, proven by mutation rather than by the base run.
 */
class RouteAuthorityProTest extends WP_UnitTestCase {

	use Route_Authority_Checks;

	protected function owner(): string {
		return 'pro';
	}

	protected function manifest_file(): string {
		return JETONOMY_PRO_DIR . 'audit/route-authority.json';
	}

	protected function mount(): void {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		foreach ( \Jetonomy_Pro::instance()->get_extensions() as $extension ) {
			if ( ! $extension->is_enabled() ) {
				$extension->boot();
			}
		}
	}

	public function test_a_conditional_route_is_mounted_by_the_extension_it_names(): void {
		$problems = array();

		foreach ( array_intersect_key( $this->manifest, $this->live ) as $key => $entry ) {
			$handler = $this->live[ $key ];
			$file    = $this->handler_file( $key );
			$in_ext  = preg_match( '#/includes/extensions/([a-z0-9-]+)/#', $file, $m ) ? $m[1] : '';

			if ( '' === $in_ext && ! empty( $entry['conditional'] ) ) {
				$problems[] = $key . ' - declared conditional, but its handler is not in an extension: ' . $file;
			}
			if ( '' !== $in_ext && ( empty( $entry['conditional'] ) || 'jetonomy_pro_extensions:' . $in_ext !== ( $entry['conditional_on'] ?? '' ) ) ) {
				$problems[] = $key . " - mounted by the {$in_ext} extension; declare conditional_on jetonomy_pro_extensions:{$in_ext}.";
			}
			unset( $handler );
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	/**
	 * GET /jetonomy-pro/v1/attachments/{id}/download is public and exit()s
	 * after streaming, so it cannot be dispatched in-process. Its only access
	 * control is viewer_can_read_link(): prove the handler calls it, and that
	 * it refuses a stranger and an outsider a file on a private-space topic
	 * while admitting the author (so the refusal is not vacuous).
	 */
	public function test_attachment_download_refuses_a_file_on_a_private_topic(): void {
		$rest   = new \Jetonomy_Pro\Extensions\Attachments\Rest();
		$method = new \ReflectionMethod( $rest, 'download' );
		$source = implode( '', array_slice( (array) file( (string) $method->getFileName() ), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
		$this->assertMatchesRegularExpression( '/(?<![\w>:$])\$this->viewer_can_read_link\(\s*\$link\s*\)/', $source, 'download() no longer calls viewer_can_read_link(), so nothing guards the file.' );

		$gate = new \ReflectionMethod( $rest, 'viewer_can_read_link' );

		$private = (object) array(
			'object_type' => 'post',
			'object_id'   => (int) $this->fx['private_post'],
		);
		$open    = (object) array(
			'object_type' => 'post',
			'object_id'   => (int) $this->fx['post'],
		);
		$reply   = (object) array(
			'object_type' => 'reply',
			'object_id'   => (int) $this->fx['private_reply'],
		);

		wp_set_current_user( 0 );
		$this->assertTrue( $gate->invoke( $rest, $open ), 'A file on a public topic is refused to a stranger, so the private refusal below proves nothing.' );
		$this->assertFalse( $gate->invoke( $rest, $private ), 'A stranger can download a file attached to a private-space topic.' );
		$this->assertFalse( $gate->invoke( $rest, $reply ), 'A stranger can download a file attached to a private-space reply.' );

		wp_set_current_user( (int) $this->fx['outsider'] );
		$this->assertFalse( $gate->invoke( $rest, $private ), 'A non-member can download a file attached to a private-space topic.' );

		wp_set_current_user( (int) $this->fx['author'] );
		$this->assertTrue( $gate->invoke( $rest, $private ), 'The author cannot reach their own file, so the refusals above prove nothing.' );
	}

	/**
	 * POST /jetonomy/v1/reply-by-email/inbound is public by design (a mail
	 * provider calls it); the webhook signature is its whole access control.
	 * With the webhook switched on, an unsigned and a wrongly signed delivery
	 * must both be refused before any reply is written.
	 */
	public function test_inbound_email_refuses_an_unsigned_delivery(): void {
		update_option(
			'jetonomy_pro_reply_by_email',
			array(
				'enabled'        => true,
				'method'         => 'webhook',
				'webhook_secret' => 'g2-route-authority-secret',
			)
		);

		$before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}jt_replies" );

		foreach ( array(
			''                => 'unsigned',
			'sha256=deadbeef' => 'wrongly signed',
		) as $signature => $label ) {
			wp_set_current_user( 0 );
			$request = new WP_REST_Request( 'POST', '/jetonomy/v1/reply-by-email/inbound' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'To'   => 'reply+g2token@example.org',
						'text' => 'Posted without a signature',
					)
				)
			);
			if ( '' !== $signature ) {
				$request->set_header( 'X-Mailgun-Signature', $signature );
			}
			$status = rest_do_request( $request )->get_status();
			$this->assertContains( $status, array( 403 ), "An {$label} inbound delivery answered {$status}." );
		}

		$this->assertSame( $before, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}jt_replies" ), 'An unsigned delivery wrote a reply.' );
	}

	private function handler_file( string $key ): string {
		foreach ( rest_get_server()->get_routes()[ $this->live[ $key ]['route'] ] ?? array() as $handler ) {
			if ( array_keys( array_filter( (array) $handler['methods'] ) ) !== $this->live[ $key ]['methods'] ) {
				continue;
			}
			$callback = $handler['callback'];
			$ref      = is_array( $callback ) ? new \ReflectionMethod( $callback[0], (string) $callback[1] ) : new \ReflectionFunction( $callback );
			return (string) $ref->getFileName();
		}
		return '';
	}
}
