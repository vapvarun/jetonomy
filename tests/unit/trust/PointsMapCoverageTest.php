<?php
/**
 * Coverage test for the Reputation POINTS_MAP.
 *
 * Iterates every entry in the POINTS_MAP and asserts the public Reputation
 * facade applies the correct delta and fires `jetonomy_reputation_changed`
 * exactly once with the matching action key. Acts as a guard so that any
 * future POINTS_MAP addition either ships a working wiring or breaks this
 * test before merge.
 *
 * @package Jetonomy\Tests\Unit\Trust
 */

namespace Jetonomy\Tests\Unit\Trust;

use WP_UnitTestCase;
use Jetonomy\Trust\Reputation;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;
use ReflectionClass;

class PointsMapCoverageTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	/**
	 * Snapshot of POINTS_MAP read via reflection.
	 *
	 * Test fixture intentionally re-reads the constant on each call so a
	 * future addition to POINTS_MAP is picked up automatically without
	 * editing the data provider.
	 *
	 * @return array<string,int>
	 */
	private static function points_map(): array {
		$reflect = new ReflectionClass( Reputation::class );
		$map     = $reflect->getConstant( 'POINTS_MAP' );
		return is_array( $map ) ? $map : [];
	}

	/**
	 * Data provider — one row per POINTS_MAP entry.
	 *
	 * @return array<string, array{0:string,1:int}>
	 */
	public function points_map_keys(): array {
		$cases = [];
		foreach ( self::points_map() as $action => $delta ) {
			$cases[ $action ] = [ (string) $action, (int) $delta ];
		}
		return $cases;
	}

	/**
	 * Every POINTS_MAP key returns its declared delta via points_for().
	 *
	 * @dataProvider points_map_keys
	 */
	public function test_points_for_matches_map( string $action, int $expected ): void {
		$this->assertSame( $expected, Reputation::points_for( $action ) );
	}

	/**
	 * Every POINTS_MAP key actually mutates the user profile by its declared delta.
	 *
	 * @dataProvider points_map_keys
	 */
	public function test_award_applies_expected_delta( string $action, int $expected ): void {
		$user_id = $this->factory()->user->create();
		UserProfile::find_or_create( $user_id );

		// Seed a buffer so negative deltas don't underflow any min-zero guard
		// the model might add later. Today UserProfile::_apply_reputation_delta
		// allows negative balances, but pinning to a positive baseline keeps
		// the test honest regardless of that policy.
		UserProfile::_apply_reputation_delta( $user_id, 100 );
		$before = (int) UserProfile::find_by_user( $user_id )->reputation;

		$result = Reputation::award( $user_id, $action );

		$this->assertSame( $expected, $result, "Reputation::award returned wrong delta for {$action}" );

		$after = (int) UserProfile::find_by_user( $user_id )->reputation;
		$this->assertSame( $before + $expected, $after, "Reputation balance moved by wrong amount for {$action}" );
	}

	/**
	 * Every POINTS_MAP key fires `jetonomy_reputation_changed` exactly once
	 * with the matching action label and delta.
	 */
	public function test_hook_fires_for_each_action(): void {
		foreach ( self::points_map() as $action => $delta ) {
			$user_id = $this->factory()->user->create();
			UserProfile::find_or_create( $user_id );

			$calls = [];
			$cb    = function ( $uid, $a, $d, $ctx ) use ( &$calls ) {
				$calls[] = compact( 'uid', 'a', 'd', 'ctx' );
			};
			add_action( 'jetonomy_reputation_changed', $cb, 10, 4 );

			Reputation::award( $user_id, (string) $action );

			remove_action( 'jetonomy_reputation_changed', $cb, 10 );

			$this->assertCount( 1, $calls, "Expected exactly one hook fire for {$action}" );
			$this->assertSame( $user_id, $calls[0]['uid'], "Wrong user_id passed for {$action}" );
			$this->assertSame( (string) $action, $calls[0]['a'], "Wrong action label passed for {$action}" );
			$this->assertSame( (int) $delta, $calls[0]['d'], "Wrong delta passed for {$action}" );
			$this->assertSame( [], $calls[0]['ctx'], "Expected empty context for {$action}" );
		}
	}

	/**
	 * Sanity: the POINTS_MAP isn't empty (guards against an accidental
	 * refactor that wipes the constant and silently passes the suite).
	 */
	public function test_points_map_is_non_empty(): void {
		$this->assertNotEmpty( self::points_map(), 'POINTS_MAP must declare at least one action' );
	}
}
