<?php
/**
 * Admin-side Pro routes tell the truth:
 * - a manual badge award refused by the badge's award limit is a 409, not "awarded",
 *   and the CLI award goes through the same limit;
 * - keyword moderation rules match whole words only ("ass" never hits "class");
 * - deleting a rule that does not exist is a 404;
 * - an unusable custom analytics range is a 400, not a silent 30 days.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;

class ProAdminRoutesTest extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private int $admin_id;
	private \Jetonomy_Pro\Extensions\Custom_Badges\Extension $badges;
	private \Jetonomy_Pro\Extensions\Advanced_Moderation\Extension $moderation;

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		Schema::create_tables();

		$this->badges = new \Jetonomy_Pro\Extensions\Custom_Badges\Extension();
		$this->badges->activate();

		$this->moderation = new \Jetonomy_Pro\Extensions\Advanced_Moderation\Extension();
		$this->moderation->activate();
		$this->moderation->boot();

		$analytics = new \Jetonomy_Pro\Extensions\Analytics\Extension();
		$analytics->activate();
		$analytics->boot();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_user_by( 'id', $this->admin_id )->add_cap( 'jetonomy_view_analytics' );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function make_badge( int $repeatable, int $max_awards ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'jt_pro_badges',
			array(
				'name'          => 'Limit ' . uniqid(),
				'slug'          => 'limit-' . uniqid(),
				'tier'          => 'bronze',
				'is_repeatable' => $repeatable,
				'max_awards'    => $max_awards,
				'is_active'     => 1,
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function award( int $badge_id, int $user_id ) {
		$request = new WP_REST_Request( 'POST', "/jetonomy/v1/badges/{$badge_id}/award" );
		$request->set_param( 'id', $badge_id );
		$request->set_param( 'user_id', $user_id );
		return $this->badges->manual_award( $request );
	}

	public function test_manual_award_past_max_awards_is_a_409(): void {
		$user  = self::factory()->user->create();
		$badge = $this->make_badge( 1, 1 );

		$this->assertSame( 201, $this->award( $badge, $user )->get_status() );

		$again = $this->award( $badge, $user );
		$this->assertInstanceOf( \WP_Error::class, $again );
		$this->assertSame( 409, $again->get_error_data()['status'] );
	}

	/** @covers-existing A once-only badge already held is a 409. */
	public function test_manual_award_of_a_held_once_only_badge_is_a_409(): void {
		$user  = self::factory()->user->create();
		$badge = $this->make_badge( 0, 0 );

		$this->assertSame( 201, $this->award( $badge, $user )->get_status() );
		$this->assertSame( 409, $this->award( $badge, $user )->get_error_data()['status'] );
	}

	public function test_cli_award_respects_max_awards_through_the_extension(): void {
		// CLI classes load only under WP-CLI.
		require_once JETONOMY_DIR . 'includes/cli/class-journey-result.php';
		require_once JETONOMY_PRO_DIR . 'includes/cli/journeys/class-custom-badges-journey.php';
		global $wpdb;
		$user    = self::factory()->user->create();
		$badge   = $this->make_badge( 1, 1 );
		$journey = new \Jetonomy_Pro\CLI\Journeys\Custom_Badges_Journey();

		$first  = $journey->award_badge( $badge, $user, $this->admin_id );
		$second = $journey->award_badge( $badge, $user, $this->admin_id );

		$this->assertFalse( $first->data['already_awarded'] );
		$this->assertTrue( $second->data['already_awarded'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_user_badges WHERE user_id = %d AND badge_id = %d", $user, $badge ) ) );
	}

	private function keyword_rule( string $pattern ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'jt_pro_mod_rules',
			array(
				'name'       => 'Words',
				'type'       => 'keyword',
				'pattern'    => $pattern,
				'action'     => 'hold',
				'scope'      => 'global',
				'is_active'  => 1,
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	private function evaluate( string $content, string $title = '' ): ?string {
		return $this->moderation->evaluate_content( null, array( 'content' => $content, 'title' => $title ), 1, $this->admin_id );
	}

	public function test_keyword_rule_does_not_match_inside_a_longer_word(): void {
		$this->keyword_rule( 'ass, spam offer' );

		$this->assertNull( $this->evaluate( 'Join the class, it is a classic.' ) );
		$this->assertNull( $this->evaluate( 'An assessment of the passage.' ) );
	}

	public function test_keyword_rule_matches_whole_words_and_phrases(): void {
		$this->keyword_rule( 'ass, spam offer, café' );

		$this->assertSame( 'hold', $this->evaluate( 'What an ASS.' ) );
		$this->assertSame( 'hold', $this->evaluate( 'body', 'Spam offer inside' ) );
		$this->assertSame( 'hold', $this->evaluate( 'Meet at the Café today' ) );
		$this->assertNull( $this->evaluate( 'cafés are closed' ) );
	}

	public function test_deleting_a_missing_rule_is_a_404(): void {
		$request = new WP_REST_Request( 'DELETE', '/jetonomy/v1/moderation/rules/999999' );
		$res     = $this->server->dispatch( $request );

		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'jetonomy_not_found', $res->get_data()['code'] );
	}

	/** @dataProvider invalid_custom_ranges */
	public function test_unusable_custom_analytics_range_is_a_400( array $params ): void {
		$request = new WP_REST_Request( 'GET', '/jetonomy/v1/analytics/overview' );
		$request->set_query_params( array_merge( array( 'range' => 'custom' ), $params ) );
		$res = $this->server->dispatch( $request );

		$this->assertSame( 400, $res->get_status() );
		$this->assertStringContainsString( 'start and end date', wp_json_encode( $res->get_data() ) );
	}

	public function invalid_custom_ranges(): array {
		return array(
			'no dates'       => array( array() ),
			'garbage'        => array( array( 'start' => 'yesterday-ish', 'end' => 'soon' ) ),
			'end before start' => array( array( 'start' => '2026-05-10', 'end' => '2026-05-01' ) ),
		);
	}

	/** @covers-existing A valid custom range still answers. */
	public function test_valid_custom_analytics_range_is_accepted(): void {
		$request = new WP_REST_Request( 'GET', '/jetonomy/v1/analytics/overview' );
		$request->set_query_params( array( 'range' => 'custom', 'start' => '2026-05-01', 'end' => '2026-05-10' ) );
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
	}
}
