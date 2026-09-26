<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Template_Loader;

/**
 * One capped, validated `jt_viewed` cookie replaces jt_viewed_<id> per topic
 * (Basecamp 10344014243).
 */
class ViewedCookieTest extends WP_UnitTestCase {

	public function test_parses_live_window(): void {
		$now = 1000000;
		$this->assertSame( array( $now + 60, array( 3, 7 ) ), Template_Loader::parse_viewed_cookie( ( $now + 60 ) . ':3,7,3', $now ) );
	}

	public function test_rejects_expired_forged_and_malformed(): void {
		$now = 1000000;
		$this->assertSame( array( 0, array() ), Template_Loader::parse_viewed_cookie( ( $now - 1 ) . ':3', $now ) );
		$this->assertSame( array( 0, array() ), Template_Loader::parse_viewed_cookie( ( $now + 2 * DAY_IN_SECONDS ) . ':3', $now ) );
		$this->assertSame( array( 0, array() ), Template_Loader::parse_viewed_cookie( ( $now + 60 ) . ':3,<script>', $now ) );
		$this->assertSame( array( 0, array() ), Template_Loader::parse_viewed_cookie( '1', $now ) );
	}

	public function test_caps_id_list(): void {
		$now = 1000000;
		$ids = range( 1, 80 );
		list( , $parsed ) = Template_Loader::parse_viewed_cookie( ( $now + 60 ) . ':' . implode( ',', $ids ), $now );
		$this->assertCount( Template_Loader::VIEWED_COOKIE_MAX, $parsed );
		$this->assertSame( 80, end( $parsed ) );
	}
}
