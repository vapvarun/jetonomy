<?php
/**
 * Refusals the admin journeys promise, proven here instead of probed on a live
 * site: each test makes the forbidden request as the wrong role and checks the
 * answer AND that nothing changed. Check ids from docs/qa/walks are named on
 * each test.
 *
 * @package Jetonomy\Tests\Security
 */

namespace Jetonomy\Tests\Security;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WP_REST_Request;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\InviteLink;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;

/**
 * @covers-existing Every refusal below already held before this file; the file
 * exists to prove each one in the test DB (see the invite-link test for the one
 * that did not).
 */
class AdminRefusalProofTest extends WP_Ajax_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		Schema::create_tables();
		Restriction::reset_memo();
		do_action( 'rest_api_init' );
		new \Jetonomy\Admin\Ajax\Import_Handler();
		new \Jetonomy\Admin\Ajax\Setup_Handler();
		new \Jetonomy\Admin\Ajax\Users_Handler();
	}

	private function member(): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		UserProfile::find_or_create( $id );
		return $id;
	}

	private function space( array $data = array() ): int {
		return (int) Space::create(
			array_merge(
				array(
					'title' => 'RP ' . uniqid(),
					'slug'  => 'rp-' . uniqid(),
				),
				$data
			)
		);
	}

	private function rest( string $method, string $route, array $body, int $as ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	private function ajax( string $action, array $post, string $nonce_action, int $as ): array {
		wp_set_current_user( $as );
		$_POST = array_merge( array( 'nonce' => wp_create_nonce( $nonce_action ) ), $post );
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the JSON response is asserted.
		}
		$response             = json_decode( $this->_last_response, true );
		$this->_last_response = '';
		return is_array( $response ) ? $response : array();
	}

	private function rows( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Jetonomy\table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
	}

	/** c-admin-category-and-space-crud.16 */
	public function test_space_admin_elsewhere_cannot_delete_another_space(): void {
		$editor = $this->member();
		SpaceMember::add( $this->space(), $editor, 'admin' );
		$target = $this->space();

		// Purge with space-admin purging allowed: only the "admin of THIS space"
		// check stands between her and the delete (transfer has a second, owner-only check).
		update_option( 'jetonomy_settings', array_merge( (array) get_option( 'jetonomy_settings', array() ), array( 'allow_space_admin_purge' => 1 ) ) );
		foreach ( array( 'transfer', 'purge' ) as $mode ) {
			$res = $this->rest( 'DELETE', "/spaces/{$target}", array( 'mode' => $mode ), $editor );
			$this->assertSame( 403, $res->get_status(), $mode );
			$this->assertSame( 'jetonomy_forbidden', $res->as_error()->get_error_code(), $mode );
			$this->assertNotNull( Space::find( $target ), "the space is still there ({$mode})" );
		}
	}

	/** c-admin-category-and-space-crud.17 and .23 */
	public function test_member_creating_a_space_or_category_gets_one_code(): void {
		$member = $this->member();
		$spaces = $this->rows( 'spaces' );
		$cats   = $this->rows( 'categories' );

		$space = $this->rest( 'POST', '/spaces', array( 'title' => 'Sneaky space' ), $member );
		$cat   = $this->rest( 'POST', '/categories', array( 'name' => 'Sneaky category' ), $member );
		foreach ( array( $space, $cat ) as $res ) {
			$this->assertSame( 403, $res->get_status() );
			$this->assertSame( 'jetonomy_forbidden', $res->as_error()->get_error_code() );
		}
		$this->assertSame( $spaces, $this->rows( 'spaces' ) );
		$this->assertSame( $cats, $this->rows( 'categories' ) );
	}

	/** c-admin-import.7 */
	public function test_member_import_batch_is_refused_and_imports_nothing(): void {
		$cats   = $this->rows( 'categories' );
		$result = $this->ajax(
			'jetonomy_import_batch',
			array(
				'source'  => 'bbpress',
				'phase'   => 'forums',
				'new_run' => 1,
			),
			'jetonomy_admin',
			$this->member()
		);
		$this->assertFalse( $result['success'] ?? true );
		$this->assertSame( $cats, $this->rows( 'categories' ) );
		$this->assertSame( 0, $this->rows( 'import_map' ) );
	}

	/**
	 * c-admin-invite-links.11 and .17: a listed private space may say 403; a
	 * hidden one answers exactly like a missing id (owner policy).
	 */
	public function test_non_member_invite_gets_one_code_for_hidden_and_missing_spaces(): void {
		$private  = $this->space(
			array(
				'visibility'  => 'private',
				'join_policy' => 'invite',
			)
		);
		$hidden   = $this->space(
			array(
				'visibility'  => 'hidden',
				'join_policy' => 'invite',
			)
		);
		$outsider = $this->member();

		$listed  = $this->rest( 'POST', "/spaces/{$private}/invite", array(), $outsider );
		$real    = $this->rest( 'POST', "/spaces/{$hidden}/invite", array(), $outsider );
		$missing = $this->rest( 'POST', '/spaces/999999/invite', array(), $outsider );
		$this->assertSame( 403, $listed->get_status() );
		$this->assertSame( 404, $real->get_status() );
		$this->assertSame( $real->get_data(), $missing->get_data() );
		$this->assertSame( 0, InviteLink::count_by_space( $private ) + InviteLink::count_by_space( $hidden ) );
	}

	/** c-admin-recount.9 */
	public function test_member_recount_is_refused_and_changes_nothing(): void {
		$space = $this->space();
		Space::update( $space, array( 'post_count' => 77 ) );

		$res = $this->rest( 'POST', '/admin/recount', array( 'type' => 'all' ), $this->member() );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_forbidden', $res->as_error()->get_error_code() );
		\Jetonomy\Cache::flush();
		$this->assertSame( 77, (int) Space::find( $space )->post_count, 'the stale counter was not recounted' );
	}

	/** c-admin-setup-wizard.8 */
	public function test_member_setup_wizard_actions_are_refused(): void {
		$member = $this->member();
		$spaces = $this->rows( 'spaces' );
		$cats   = $this->rows( 'categories' );
		delete_option( 'jetonomy_setup_complete' );

		$save   = $this->ajax( 'jetonomy_setup_save', array( 'category_name' => 'X', 'space_name' => 'Y' ), 'jetonomy_setup', $member );
		$sample = $this->ajax( 'jetonomy_setup_create_sample', array(), 'jetonomy_setup', $member );
		$this->assertFalse( $save['success'] ?? true );
		$this->assertFalse( $sample['success'] ?? true );
		$this->assertSame( $spaces, $this->rows( 'spaces' ) );
		$this->assertSame( $cats, $this->rows( 'categories' ) );
		$this->assertFalse( get_option( 'jetonomy_setup_complete' ) );
	}

	/** c-admin-user-management.10 */
	public function test_non_administrator_staff_cannot_ban_an_ip(): void {
		$moderator = $this->member();
		get_user_by( 'id', $moderator )->add_cap( 'jetonomy_moderate' );
		$space_admin = $this->member();
		SpaceMember::add( $this->space(), $space_admin, 'admin' );

		foreach ( array( $moderator, $space_admin ) as $actor ) {
			$res = $this->rest(
				'POST',
				'/moderation/ban',
				array(
					'type' => 'ip_ban',
					'ip'   => '203.0.113.10',
				),
				$actor
			);
			$this->assertSame( 403, $res->get_status() );
		}
		$this->assertSame( 0, Restriction::count_active( array( 'type' => 'ip_ban' ) ) );
	}

	/** c-admin-user-management.11 */
	public function test_delegated_user_manager_cannot_raise_self_or_other_staff(): void {
		$manager = $this->member();
		get_user_by( 'id', $manager )->add_cap( 'jetonomy_manage_users' );
		$other = $this->member();
		get_user_by( 'id', $other )->add_cap( 'jetonomy_manage_users' );

		foreach ( array( $manager, $other ) as $target ) {
			$res = $this->ajax(
				'jetonomy_change_trust_level',
				array(
					'user_id'     => $target,
					'trust_level' => 4,
				),
				'jetonomy_admin',
				$manager
			);
			$this->assertFalse( $res['success'] ?? true );
			$this->assertSame( 0, (int) UserProfile::find_by_user( $target )->trust_level );
		}
	}

	/** c-admin-user-management.17 */
	public function test_member_bulk_trust_level_is_refused_and_changes_nothing(): void {
		$target = $this->member();
		$res    = $this->rest(
			'POST',
			'/admin/users/trust-level',
			array(
				'user_ids' => array( $target ),
				'level'    => 4,
			),
			$this->member()
		);
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'jetonomy_forbidden', $res->as_error()->get_error_code() );
		$this->assertSame( 0, (int) UserProfile::find_by_user( $target )->trust_level );
	}
}
