<?php
namespace Jetonomy\Tests\Integration;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WP_REST_Request;
use Jetonomy\Admin\Admin;
use Jetonomy\CLI\Commands\Mod_Command;
use Jetonomy\CLI\Journeys\Moderation_Journey;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\UserProfile;
use Jetonomy\Permissions\Capabilities;

/**
 * wp-admin fixes from the 2026-10-17 QA walk: restriction labels and lift
 * messages, IP bans shown as addresses, owner terminology on the Permissions
 * table, CLI ban flags, REST enum validation, and stale-tab saves.
 */
class AdminQaWalkFixesTest extends WP_Ajax_UnitTestCase {

	private const IP = '5.6.7.8';

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		// _handleAjax() fires admin_init; keep core's update checks off the network.
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		Schema::create_tables();
		Restriction::reset_memo();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account     = new \WP_User( $this->admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		wp_set_current_user( $this->admin );

		new \Jetonomy\Admin\Ajax\Users_Handler();
		new \Jetonomy\Admin\Ajax\Spaces_Handler();
		new \Jetonomy\Admin\Ajax\Categories_Handler();
	}

	public function tear_down(): void {
		$_GET = array();
		remove_all_filters( 'pre_option_jetonomy_settings' );
		parent::tear_down();
	}

	private function ajax( string $action, array $post ): array {
		$_POST = array_merge( array( 'nonce' => wp_create_nonce( 'jetonomy_admin' ) ), $post );
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_send_json_* dies; the response is asserted.
		}
		$response            = json_decode( $this->_last_response, true );
		$this->_last_response = '';
		return is_array( $response ) ? $response : array();
	}

	private function member(): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		UserProfile::find_or_create( $id );
		return $id;
	}

	// Finding 1: no toast is fired right before the page goes away.
	public function test_admin_js_never_toasts_right_before_a_reload(): void {
		$js = (string) file_get_contents( JETONOMY_DIR . 'assets/js/admin-common.js' );
		$this->assertDoesNotMatchRegularExpression( '/toast\(res\.data\.message\);\s*(window\.)?location\.(reload\(|href\s*=)/', $js );
		$this->assertStringContainsString( 'showPendingToast', $js );
	}

	// Finding 2a: each row names the active restriction, from one batched lookup.
	public function test_users_list_labels_banned_and_silenced_members(): void {
		$banned   = $this->member();
		$silenced = $this->member();
		$clean    = $this->member();
		Restriction::ban( $banned, 'global_ban', $this->admin );
		Restriction::ban( $silenced, 'silence', $this->admin );

		ob_start();
		( new Admin() )->render_users();
		$html = (string) ob_get_clean();

		$row = static function ( string $html, int $id ): string {
			preg_match( '#<tr[^>]*data-user-id="' . $id . '".*?</tr>#s', $html, $m );
			return $m[0] ?? '';
		};
		$this->assertStringContainsString( '>Banned<', $row( $html, $banned ) );
		$this->assertStringContainsString( '>Silenced<', $row( $html, $silenced ) );
		$this->assertNotEmpty( $row( $html, $clean ) );
		$this->assertStringNotContainsString( 'jetonomy-restriction-label', $row( $html, $clean ) );
		// After a reload a restricted row offers Lift restriction, not a second Ban.
		$this->assertStringContainsString( 'jetonomy-unban-user', $row( $html, $banned ) );
		$this->assertStringNotContainsString( 'jetonomy-ban-trigger', $row( $html, $banned ) );
		$this->assertStringContainsString( 'jetonomy-ban-trigger', $row( $html, $clean ) );
	}

	// Finding 2b: lifting answers with what was lifted.
	public function test_lifting_a_silence_says_silence_lifted(): void {
		$id = Restriction::ban( $this->member(), 'silence', $this->admin );
		$r  = $this->ajax( 'jetonomy_unban_user', array( 'restriction_id' => (string) $id ) );
		$this->assertTrue( $r['success'] ?? false );
		$this->assertSame( 'Silence lifted.', $r['data']['message'] );

		$id = Restriction::ban( $this->member(), 'global_ban', $this->admin );
		$r  = $this->ajax( 'jetonomy_unban_user', array( 'restriction_id' => (string) $id ) );
		$this->assertSame( 'Ban lifted.', $r['data']['message'] );
	}

	// Finding 3: the Permissions table follows the owner's terminology.
	public function test_capability_labels_use_owner_terminology(): void {
		// Read through pre_option: the registered settings sanitizer may rewrite a direct update_option().
		add_filter(
			'pre_option_jetonomy_settings',
			static fn() => array(
				'topic_label_singular' => 'Thread',
				'topic_label_plural'   => 'Threads',
				'reply_label_singular' => 'Comment',
				'reply_label_plural'   => 'Comments',
			)
		);
		$labels = Capabilities::labels();
		$this->assertSame( 'Create threads', $labels['jetonomy_create_posts'] );
		$this->assertSame( 'Edit own threads and comments', $labels['jetonomy_edit_own_posts'] );
		foreach ( $labels as $cap => $label ) {
			$this->assertStringNotContainsString( 'posts', $label, $cap );
		}
	}

	// Finding 4: --ip without --type=ip_ban, and ip_ban without --ip, are refused.
	public function test_cli_ban_refuses_flags_it_would_ignore(): void {
		$this->assertNotNull( Mod_Command::ban_flag_error( array( 'type' => 'global_ban', 'ip' => self::IP ) ) );
		$this->assertStringContainsString( '--ip=', (string) Mod_Command::ban_flag_error( array( 'type' => 'ip_ban' ) ) );
		$this->assertNotNull( Mod_Command::ban_flag_error( array( 'type' => 'silence', 'space' => '3' ) ) );
		$this->assertNull( Mod_Command::ban_flag_error( array( 'type' => 'ip_ban', 'ip' => self::IP ) ) );
		$this->assertNull( Mod_Command::ban_flag_error( array( 'type' => 'space_ban', 'space' => '3' ) ) );
	}

	public function test_cli_ban_journey_explains_an_invalid_ip(): void {
		$result = ( new Moderation_Journey() )->ban_user( $this->member(), $this->admin, 'ip_ban', null, 'not-an-ip' );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'valid IPv4 or IPv6', (string) $result->first_error() );
	}

	// Finding 5: an IP ban is listed as the address and does not restrict the member.
	public function test_ip_ban_is_listed_as_the_address_not_the_member(): void {
		$member = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Grace Qawalk',
			)
		);
		$this->assertGreaterThan( 0, Restriction::ban( $member, 'ip_ban', $this->admin, null, self::IP ) );
		$this->assertFalse( Restriction::is_banned( $member ) );
		$this->assertFalse( Restriction::is_silenced( $member ) );

		$_GET = array( 'tab' => 'banned' );
		ob_start();
		( new Admin() )->render_moderation();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<code>' . self::IP . '</code>', $html );
		$this->assertStringNotContainsString( 'Grace Qawalk', $html );
	}

	public function test_rest_ban_list_returns_an_ip_ban_as_its_address(): void {
		do_action( 'rest_api_init' );
		Restriction::ban( $this->member(), 'ip_ban', $this->admin, null, self::IP );

		$request = new WP_REST_Request( 'GET', '/jetonomy/v1/moderation/ban' );
		$request->set_param( 'type', 'ip_ban' );
		$data  = rest_do_request( $request )->get_data();
		$items = $data['data'] ?? $data['items'] ?? $data;
		$this->assertSame( self::IP, $items[0]['ip'] ?? null, wp_json_encode( $data ) );
		$this->assertNull( $items[0]['reason'] );
	}

	public function test_profile_restriction_lookup_ignores_ip_bans(): void {
		$member = $this->member();
		Restriction::ban( $member, 'ip_ban', $this->admin, null, self::IP );
		$this->assertArrayNotHasKey( $member, Restriction::active_rows_for_users( array( $member ) ) );

		$silence = Restriction::ban( $member, 'silence', $this->admin );
		$row     = Restriction::active_rows_for_users( array( $member ) )[ $member ];
		$this->assertSame( array( $silence, 'silence' ), array( $row->id, $row->type ) );
	}

	public function test_reply_by_email_refused_is_an_editable_email_type(): void {
		$this->assertArrayHasKey( 'reply_by_email_refused', Admin::email_types() );
		$this->assertFalse( Admin::email_types()['reply_by_email_refused']['toggle'] );

		$clean = ( new Admin() )->sanitize_email_templates(
			array(
				'_submitted'             => '1',
				'reply_by_email_refused' => array(
					'subject' => 'Not posted',
					'body'    => 'Body',
				),
			)
		);
		$this->assertArrayHasKey( 'reply_by_email_refused', $clean );
	}

	// Finding 6: unknown enum values are a 400, not a silent fallback.
	public function test_unknown_enum_values_are_rejected(): void {
		do_action( 'rest_api_init' );

		$recount = new WP_REST_Request( 'POST', '/jetonomy/v1/admin/recount' );
		$recount->set_param( 'type', 'bogus' );
		$response = rest_do_request( $recount );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );

		$board = new WP_REST_Request( 'GET', '/jetonomy/v1/leaderboards' );
		$board->set_param( 'period', 'bogus' );
		$this->assertSame( 400, rest_do_request( $board )->get_status() );

		$queue = new WP_REST_Request( 'GET', '/jetonomy/v1/moderation/queue' );
		$queue->set_param( 'status', 'bogus' );
		$this->assertSame( 400, rest_do_request( $queue )->get_status() );
	}

	// Finding 8: a signed-in member without the capability gets one refusal code on every route.
	public function test_member_without_capability_gets_jetonomy_forbidden(): void {
		do_action( 'rest_api_init' );
		wp_set_current_user( $this->member() );

		$routes = array(
			array( 'POST', '/jetonomy/v1/categories' ),
			array( 'POST', '/jetonomy/v1/admin/recount' ),
			array( 'POST', '/jetonomy/v1/admin/users/trust-level' ),
			array( 'GET', '/jetonomy/v1/media' ),
		);
		foreach ( $routes as $route ) {
			$request = new WP_REST_Request( $route[0], $route[1] );
			// Args are validated before the permission check, so send a valid body.
			$request->set_body_params( array( 'name' => 'QW', 'user_ids' => array( 1 ), 'level' => 1 ) );
			$response = rest_do_request( $request );
			$this->assertSame( 403, $response->get_status(), implode( ' ', $route ) );
			$this->assertSame( 'jetonomy_forbidden', $response->as_error()->get_error_code(), implode( ' ', $route ) );
		}

		// Guests keep the 401 contract.
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/jetonomy/v1/media' ) )->get_status() );
		$guest = new WP_REST_Request( 'POST', '/jetonomy/v1/categories' );
		$guest->set_body_params( array( 'name' => 'QW' ) );
		$this->assertSame( 401, rest_do_request( $guest )->get_status() );
	}

	// Finding 7: a stale tab cannot revert a newer save.
	public function test_stale_space_form_cannot_overwrite_a_newer_save(): void {
		$cat_id   = Category::create( array( 'name' => 'QW Cat', 'slug' => 'qw-cat-' . uniqid() ) );
		$space_id = Space::create(
			array(
				'title'       => 'Original',
				'slug'        => 'qw-space-' . uniqid(),
				'category_id' => $cat_id,
			)
		);
		$token = \Jetonomy\Models\Model::edit_token( Space::find( $space_id ), \Jetonomy\Admin\Ajax\Spaces_Handler::EDIT_FIELDS );

		// Tab A saves first; its answer carries the token for its next save.
		$a = $this->ajax( 'jetonomy_update_space', array( 'id' => (string) $space_id, 'edit_token' => $token, 'title' => 'Tab A' ) );
		$this->assertTrue( $a['success'] ?? false, wp_json_encode( $a ) );

		// Tab B still holds the token it loaded with.
		$b = $this->ajax( 'jetonomy_update_space', array( 'id' => (string) $space_id, 'edit_token' => $token, 'title' => 'Tab B stale' ) );
		$this->assertFalse( $b['success'] ?? true );
		$this->assertStringContainsString( 'changed by someone else', (string) $b['data'] );
		$this->assertSame( 'Tab A', Space::find( $space_id )->title );

		// A new topic bumps updated_at but is not an edit of the form's fields.
		Space::update( $space_id, array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );
		$again = $this->ajax( 'jetonomy_update_space', array( 'id' => (string) $space_id, 'edit_token' => $a['data']['edit_token'], 'title' => 'Tab A again' ) );
		$this->assertTrue( $again['success'] ?? false, wp_json_encode( $again ) );
	}

	public function test_stale_space_settings_tab_cannot_overwrite_a_newer_save(): void {
		$cat_id   = Category::create( array( 'name' => 'QW Cat', 'slug' => 'qw-cat3-' . uniqid() ) );
		$space_id = Space::create(
			array(
				'title'       => 'Settings',
				'slug'        => 'qw-space3-' . uniqid(),
				'category_id' => $cat_id,
			)
		);
		$token = \Jetonomy\Admin\Ajax\Spaces_Handler::edit_token( Space::find( $space_id ), true );

		$a = $this->ajax( 'jetonomy_update_space', array( 'id' => (string) $space_id, 'edit_token' => $token, 'settings' => wp_json_encode( array( 'allow_voting' => '0' ) ) ) );
		$this->assertTrue( $a['success'] ?? false, wp_json_encode( $a ) );

		$b = $this->ajax( 'jetonomy_update_space', array( 'id' => (string) $space_id, 'edit_token' => $token, 'settings' => wp_json_encode( array( 'allow_voting' => '1' ) ) ) );
		$this->assertFalse( $b['success'] ?? true );

		// A key Pro stores in the same column is not this form's conflict.
		$stored             = json_decode( (string) Space::find( $space_id )->settings, true );
		$stored['seo_note'] = 'pro';
		Space::update( $space_id, array( 'settings' => wp_json_encode( $stored ) ) );
		$c = $this->ajax( 'jetonomy_update_space', array( 'id' => (string) $space_id, 'edit_token' => $a['data']['edit_token'], 'settings' => wp_json_encode( array( 'allow_voting' => '1' ) ) ) );
		$this->assertTrue( $c['success'] ?? false, wp_json_encode( $c ) );
	}

	public function test_stale_category_modal_cannot_overwrite_a_newer_save(): void {
		$cat_id = Category::create( array( 'name' => 'Original', 'slug' => 'qw-cat2-' . uniqid() ) );
		$token  = \Jetonomy\Models\Model::edit_token( Category::find( $cat_id ), \Jetonomy\Admin\Ajax\Categories_Handler::EDIT_FIELDS );

		$a = $this->ajax( 'jetonomy_update_category', array( 'id' => (string) $cat_id, 'edit_token' => $token, 'name' => 'Tab A' ) );
		$this->assertTrue( $a['success'] ?? false, wp_json_encode( $a ) );

		$b = $this->ajax( 'jetonomy_update_category', array( 'id' => (string) $cat_id, 'edit_token' => $token, 'name' => 'Tab B stale' ) );
		$this->assertFalse( $b['success'] ?? true );
		$this->assertSame( 'Tab A', Category::find( $cat_id )->name );
	}
}
