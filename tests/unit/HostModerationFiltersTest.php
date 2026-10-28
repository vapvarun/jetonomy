<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Cache;
use Jetonomy\Models\BlockedUser;
use Jetonomy\Models\Restriction;
use Jetonomy\DB\Schema;

/**
 * A host plugin (BuddyNext) bans and blocks with its own records. Its answer
 * must count wherever Jetonomy asks, so a member is never banned or blocked twice.
 */
class HostModerationFiltersTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function tear_down(): void {
		remove_all_filters( 'jetonomy_user_is_banned' );
		remove_all_filters( 'jetonomy_user_is_silenced' );
		remove_all_filters( 'jetonomy_blocked_user_ids' );
		Cache::reset_memos();
		parent::tear_down();
	}

	public function test_a_host_ban_or_silence_counts(): void {
		$user = self::factory()->user->create();
		$this->assertFalse( Restriction::is_banned( $user ) );
		$this->assertFalse( Restriction::is_silenced( $user ) );

		add_filter( 'jetonomy_user_is_banned', fn( $banned, $id ) => $banned || $id === $user, 10, 2 );
		add_filter( 'jetonomy_user_is_silenced', fn( $silenced, $id ) => $silenced || $id === $user, 10, 2 );

		$this->assertTrue( Restriction::is_banned( $user ) );
		$this->assertTrue( Restriction::is_silenced( $user ) );
		$this->assertFalse( Restriction::is_banned( self::factory()->user->create() ), 'Only the host-banned member.' );
	}

	public function test_a_host_block_counts_in_every_block_check(): void {
		$blocker = self::factory()->user->create();
		$blocked = self::factory()->user->create();
		$this->assertFalse( BlockedUser::is_blocked( $blocker, $blocked ) );

		add_filter(
			'jetonomy_blocked_user_ids',
			fn( $ids, $viewer ) => $viewer === $blocker ? array_merge( $ids, array( $blocked ) ) : $ids,
			10,
			2
		);
		Cache::reset_memos();

		$this->assertTrue( BlockedUser::is_blocked( $blocker, $blocked ) );
		$this->assertFalse( BlockedUser::is_blocked( $blocked, $blocker ), 'Blocking stays one-way.' );
		$this->assertTrue( BlockedUser::is_blocked_between( $blocked, $blocker ), 'Either direction closes a conversation.' );
	}

	public function test_own_blocks_still_count(): void {
		$blocker = self::factory()->user->create();
		$blocked = self::factory()->user->create();
		BlockedUser::block( $blocker, $blocked );
		$this->assertTrue( BlockedUser::is_blocked( $blocker, $blocked ) );
		BlockedUser::unblock( $blocker, $blocked );
		$this->assertFalse( BlockedUser::is_blocked( $blocker, $blocked ), 'An unblock is seen in the same request.' );
	}
}
