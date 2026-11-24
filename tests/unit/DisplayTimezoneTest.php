<?php
/**
 * Every stored UTC time is shown, and edited, through one timezone.
 *
 * date_label() and display_timezone() replace the mixed wp_date / date_i18n / mysql2date / get_date_from_gmt calls, so a
 * time near midnight says the same day on every screen, and an edit field round-trips without moving the time.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Feed;
use Jetonomy\Models\InviteLink;
use function Jetonomy\date_label;
use function Jetonomy\datetime_local_input;
use function Jetonomy\display_timezone;

defined( 'ABSPATH' ) || exit;

class DisplayTimezoneTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Asia/Kolkata' ); // UTC+5:30, no DST.
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
	}

	public function test_a_stored_utc_time_is_shown_in_the_site_timezone(): void {
		// 22:00 UTC on the 24th is 03:30 on the 25th in Kolkata: the day changes.
		$this->assertSame( '2026-11-25', date_label( '2026-11-24 22:00:00' ) );
		$this->assertSame( '2026-11-25 03:30', date_label( '2026-11-24 22:00:00', '', 'Y-m-d H:i' ) );
		$this->assertSame( '2026-11-25', date_label( strtotime( '2026-11-24 22:00:00 UTC' ) ), 'A Unix timestamp too.' );
		$this->assertSame( '2026-11-25', date_label( '2026-11-24T22:00:00+00:00' ), 'A string with its own offset is honoured.' );
	}

	public function test_a_blank_zero_or_unparseable_time_returns_the_fallback(): void {
		$this->assertSame( 'never', date_label( '', 'never' ) );
		$this->assertSame( 'never', date_label( null, 'never' ) );
		$this->assertSame( 'never', date_label( '0000-00-00 00:00:00', 'never' ) );
		$this->assertSame( 'never', date_label( 'not a date', 'never' ) );
	}

	public function test_a_site_can_show_each_member_their_own_timezone(): void {
		$tz = static fn( $zone, $user_id ) => 7 === $user_id ? new \DateTimeZone( 'America/New_York' ) : $zone;
		add_filter( 'jetonomy_display_timezone', $tz, 10, 2 );

		$this->assertSame( '2026-11-24 17:00', date_label( '2026-11-24 22:00:00', '', 'Y-m-d H:i', 7 ), 'Member 7 sees New York time.' );
		$this->assertSame( '2026-11-25 03:30', date_label( '2026-11-24 22:00:00', '', 'Y-m-d H:i' ), 'Everyone else sees the site timezone.' );
		$this->assertSame( 'America/New_York', display_timezone( 7 )->getName() );
	}

	public function test_an_edit_field_shows_the_same_time_the_save_parses(): void {
		$this->assertSame( '2026-11-25T03:30', datetime_local_input( '2026-11-24 22:00:00' ) );
		$this->assertSame( '', datetime_local_input( '' ) );

		// What the editor sees is what the save reads back: parse it as typed, store UTC, show it again.
		$tomorrow = gmdate( 'Y-m-d H:i', time() + 3 * DAY_IN_SECONDS );
		$utc      = InviteLink::expiry_utc( $tomorrow );
		$this->assertIsString( $utc );
		$this->assertSame( $tomorrow, datetime_local_input( $utc ) === '' ? '' : str_replace( 'T', ' ', datetime_local_input( $utc ) ), 'No drift through a save in the site timezone.' );

		// And the same through a member timezone, because the parser uses display_timezone() too.
		add_filter( 'jetonomy_display_timezone', static fn() => new \DateTimeZone( 'Pacific/Auckland' ) );
		$utc = InviteLink::expiry_utc( $tomorrow );
		$this->assertSame( $tomorrow, str_replace( 'T', ' ', datetime_local_input( $utc ) ), 'No drift through a save in a member timezone either.' );
	}

	public function test_the_rss_dates_are_utc_whatever_the_site_timezone(): void {
		$rss  = new \ReflectionMethod( Feed::class, 'rss_date' );
		$http = new \ReflectionMethod( Feed::class, 'http_date' );
		$rss->setAccessible( true );
		$http->setAccessible( true );

		$this->assertSame( 'Tue, 24 Nov 2026 22:00:00 +0000', $rss->invoke( null, '2026-11-24 22:00:00' ), 'Labelled with the site offset (+0530) before.' );
		$this->assertSame( 'Tue, 24 Nov 2026 22:00:00 GMT', $http->invoke( null, '2026-11-24 22:00:00' ) );
		$this->assertSame( '', $rss->invoke( null, 'garbage' ) );
	}
}
