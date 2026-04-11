<?php
namespace Jetonomy\Tests\Unit\CLI\Scenarios;

use Jetonomy\CLI\Scenarios\Notification_Delivery_Sweep;
use Jetonomy\CLI\Scenarios\Post_With_Flags_For_Moderation;
use Jetonomy\CLI\Scenarios\Scenario_Result;
use Jetonomy\CLI\Scenarios\Scenario_Runner;
use Jetonomy\CLI\Scenarios\Space_With_Pending_Join_Request;
use Jetonomy\DB\Schema;
use WP_UnitTestCase;

require_once __DIR__ . '/Failing_Scenario.php';

/**
 * Exercises the Scenario_Runner registry and the built-in scenarios end-to-end.
 *
 * Scenarios build their own fixtures (category, space, users, content, etc.)
 * per call using unique slugs so parallel tests within the same DB do not
 * collide. We assert on {@see Scenario_Result} — the same surface the CLI
 * formatter consumes — so any regression that reshapes the DTO also breaks
 * these tests.
 */
class ScenarioRunnerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_runner_lists_registered_scenarios(): void {
		$runner = new Scenario_Runner();
		$list   = $runner->list();

		$this->assertNotEmpty( $list, 'Default scenarios should be registered.' );

		$slugs = array_column( $list, 'name' );
		$this->assertContains( 'space-with-pending-join-request', $slugs );
		$this->assertContains( 'post-with-flags-for-moderation', $slugs );
		$this->assertContains( 'multi-user-voting-thread', $slugs );
		$this->assertContains( 'full-membership-approval-flow', $slugs );
		$this->assertContains( 'notification-delivery-sweep', $slugs );

		foreach ( $list as $row ) {
			$this->assertArrayHasKey( 'description', $row );
			$this->assertArrayHasKey( 'class', $row );
			$this->assertNotEmpty( $row['description'] );
		}
	}

	public function test_runner_rejects_unknown_scenario(): void {
		$runner = new Scenario_Runner();
		$result = $runner->run( 'not-a-real-scenario' );

		$this->assertInstanceOf( Scenario_Result::class, $result );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'not-a-real-scenario', $result->scenario );
		$this->assertStringContainsString( 'Unknown scenario', (string) $result->first_error() );
		$this->assertEmpty( $result->steps );
	}

	public function test_runner_short_circuits_on_first_failure(): void {
		$runner = new Scenario_Runner(
			[
				Failing_Scenario::name() => Failing_Scenario::class,
			]
		);

		$result = $runner->run( Failing_Scenario::name() );

		$this->assertFalse( $result->is_success() );
		$this->assertCount( 2, $result->steps, 'Short-circuit must stop after the failing step.' );

		$first  = $result->steps[0];
		$second = $result->steps[1];

		$this->assertSame( 'ok-first', $first['name'] );
		$this->assertTrue( $first['result']->is_success() );

		$this->assertSame( 'fail-second', $second['name'] );
		$this->assertFalse( $second['result']->is_success() );

		$names = array_column( $result->steps, 'name' );
		$this->assertNotContains( 'never-run-third', $names );

		$this->assertNotEmpty( $result->errors );
		$this->assertStringContainsString( 'intentional failure', $result->errors[0] );
	}

	public function test_space_with_pending_join_request_scenario_succeeds(): void {
		$runner = new Scenario_Runner();
		$result = $runner->run( Space_With_Pending_Join_Request::name() );

		$this->assertTrue(
			$result->is_success(),
			'Scenario failed: ' . implode( ' | ', $result->errors )
		);

		$fixtures = $result->fixtures;
		$this->assertGreaterThan( 0, (int) ( $fixtures['category_id'] ?? 0 ) );
		$this->assertGreaterThan( 0, (int) ( $fixtures['space_id'] ?? 0 ) );
		$this->assertGreaterThan( 0, (int) ( $fixtures['user_id'] ?? 0 ) );
		$this->assertGreaterThan( 0, (int) ( $fixtures['request_id'] ?? 0 ) );

		$cleanup = $runner->cleanup( Space_With_Pending_Join_Request::name(), $fixtures );
		$this->assertNotEmpty( $cleanup->steps );
	}

	public function test_post_with_flags_scenario_succeeds(): void {
		$runner = new Scenario_Runner();
		$result = $runner->run( Post_With_Flags_For_Moderation::name() );

		$this->assertTrue(
			$result->is_success(),
			'Scenario failed: ' . implode( ' | ', $result->errors )
		);

		$fixtures = $result->fixtures;
		$this->assertGreaterThan( 0, (int) ( $fixtures['post_id'] ?? 0 ) );
		$this->assertCount( 2, (array) ( $fixtures['flag_ids'] ?? [] ) );
		$this->assertCount( 2, (array) ( $fixtures['reporter_ids'] ?? [] ) );

		$runner->cleanup( Post_With_Flags_For_Moderation::name(), $fixtures );
	}

	public function test_notification_delivery_sweep_scenario_creates_rows(): void {
		$runner = new Scenario_Runner();
		$result = $runner->run( Notification_Delivery_Sweep::name() );

		$this->assertTrue(
			$result->is_success(),
			'Scenario failed: ' . implode( ' | ', $result->errors )
		);

		$fixtures = $result->fixtures;
		$ids      = (array) ( $fixtures['notification_ids'] ?? [] );
		$this->assertCount( 9, $ids );
		foreach ( $ids as $id ) {
			$this->assertGreaterThan( 0, (int) $id );
		}
		$this->assertGreaterThanOrEqual( 9, (int) ( $fixtures['unread_delta'] ?? 0 ) );

		$runner->cleanup( Notification_Delivery_Sweep::name(), $fixtures );
	}
}
