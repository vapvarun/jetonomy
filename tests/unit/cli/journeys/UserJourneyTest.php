<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\User_Journey;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\UserProfile;
use Jetonomy\DB\Schema;

/**
 * Exercises every User_Journey method against the real model layer.
 *
 * Each run provisions a fresh subscriber fixture under a unique login suffix
 * and materializes their Jetonomy profile via UserProfile::find_or_create so
 * the trust_level / reputation / patch paths all have a row to update. The
 * journey is pure PHP with no WP-CLI coupling, so these tests run through the
 * standard WP_UnitTestCase bootstrap.
 */
class UserJourneyTest extends WP_UnitTestCase {

	private User_Journey $journey;

	private int $user_id;

	private string $suffix;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->journey = new User_Journey();
		$this->suffix  = uniqid( 'uj_', true );
		$this->user_id = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_login' => 'uj_fixture_' . $this->suffix,
				'user_email' => 'uj_fixture_' . $this->suffix . '@example.test',
			]
		);
		UserProfile::find_or_create( $this->user_id );
	}

	public function test_create_with_trust_level_creates_user_and_profile(): void {
		$login = 'uj_create_' . $this->suffix;
		$email = $login . '@example.test';

		$result = $this->journey->create_with_trust_level(
			[
				'login'        => $login,
				'email'        => $email,
				'trust_level'  => 2,
				'display_name' => 'Journey Alice',
			]
		);

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertGreaterThan( 0, $result->data['user_id'] );
		$this->assertSame( $login, $result->data['login'] );
		$this->assertSame( 2, $result->data['trust_level'] );

		$user_id = (int) $result->data['user_id'];
		$profile = UserProfile::find_by_user( $user_id );
		$this->assertNotNull( $profile );
		$this->assertSame( 2, (int) $profile->trust_level );
		$this->assertSame( 'Journey Alice', (string) $profile->display_name );
	}

	public function test_create_fails_when_login_or_email_missing(): void {
		$result = $this->journey->create_with_trust_level(
			[
				'login' => 'uj_missing_' . $this->suffix,
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Missing required fields', $result->first_error() );
		$this->assertStringContainsString( 'email', $result->first_error() );
	}

	public function test_create_fails_on_duplicate_login(): void {
		$login = 'uj_dup_' . $this->suffix;
		$email = $login . '@example.test';

		$first = $this->journey->create_with_trust_level(
			[
				'login' => $login,
				'email' => $email,
			]
		);
		$this->assertTrue( $first->is_success() );

		$second = $this->journey->create_with_trust_level(
			[
				'login' => $login,
				'email' => 'other_' . $email,
			]
		);
		$this->assertFalse( $second->is_success() );
		$this->assertNotEmpty( $second->errors );
	}

	public function test_set_trust_level_persists(): void {
		$result = $this->journey->set_trust_level( $this->user_id, 4 );

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertSame( 4, $result->data['trust_level'] );
		$this->assertSame( 'Leader', $result->data['level_name'] );

		$profile = UserProfile::find_by_user( $this->user_id );
		$this->assertSame( 4, (int) $profile->trust_level );
	}

	public function test_set_trust_level_rejects_out_of_range(): void {
		$low  = $this->journey->set_trust_level( $this->user_id, -1 );
		$high = $this->journey->set_trust_level( $this->user_id, 6 );

		$this->assertFalse( $low->is_success() );
		$this->assertFalse( $high->is_success() );
		$this->assertStringContainsString( '0 and 5', $low->first_error() );
		$this->assertStringContainsString( '0 and 5', $high->first_error() );
	}

	public function test_get_trust_level_returns_level_and_requirements(): void {
		$this->journey->set_trust_level( $this->user_id, 0 );

		$result = $this->journey->get_trust_level( $this->user_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 0, $result->data['trust_level'] );
		$this->assertSame( 'Newcomer', $result->data['level_name'] );
		$this->assertIsArray( $result->data['requirements'] );
		// Level 1 has non-empty requirements.
		$this->assertNotEmpty( $result->data['requirements'] );
		$this->assertArrayHasKey( 'posts', $result->data['requirements'] );
	}

	public function test_update_profile_whitelists_fields(): void {
		$result = $this->journey->update_profile(
			$this->user_id,
			[
				'bio'          => 'hello',
				'display_name' => 'UJ Test',
				'trust_level'  => 5, // Should be stripped.
				'reputation'   => 9999, // Should be stripped.
			]
		);

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertContains( 'bio', $result->data['updated'] );
		$this->assertContains( 'display_name', $result->data['updated'] );
		$this->assertNotContains( 'trust_level', $result->data['updated'] );
		$this->assertNotContains( 'reputation', $result->data['updated'] );

		$profile = UserProfile::find_by_user( $this->user_id );
		$this->assertSame( 'hello', (string) $profile->bio );
		$this->assertSame( 'UJ Test', (string) $profile->display_name );
		// trust_level remained at the default 0, not 5.
		$this->assertSame( 0, (int) $profile->trust_level );
	}

	public function test_update_profile_rejects_empty_patch(): void {
		$result = $this->journey->update_profile( $this->user_id, [ 'nope' => 'x' ] );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'No updatable fields', $result->first_error() );
	}

	public function test_adjust_reputation_applies_delta(): void {
		$up = $this->journey->adjust_reputation( $this->user_id, 25 );
		$this->assertTrue( $up->is_success() );
		$this->assertSame( 25, $up->data['new_reputation'] );

		$down = $this->journey->adjust_reputation( $this->user_id, -10 );
		$this->assertTrue( $down->is_success() );
		$this->assertSame( 15, $down->data['new_reputation'] );
	}

	public function test_get_profile_returns_row(): void {
		$result = $this->journey->get_profile( $this->user_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $this->user_id, (int) $result->data['user_id'] );
		$this->assertArrayHasKey( 'trust_level', $result->data );
		$this->assertArrayHasKey( 'reputation', $result->data );
	}

	public function test_ban_delegates_to_moderation_journey(): void {
		$issuer_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$result = $this->journey->ban_user( $this->user_id, $issuer_id, 'spam' );

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertSame( 'global_ban', $result->data['type'] );
		$this->assertSame( $this->user_id, (int) $result->data['user_id'] );
		$this->assertTrue( Restriction::is_banned( $this->user_id ) );
	}
}
