<?php
namespace Jetonomy\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\InviteLink;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Restriction;
use Jetonomy\Moderation\Moderation_Permissions;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

/**
 * Server-side fixes from the 2.0.3 release walk: a Join press with a stale
 * nonce says so, a Users-list restriction row carries the member lift_error()
 * judges, and deleting notifications returns the fresh tab counts.
 */
class ReleaseWalkFiveFixesTest extends WP_UnitTestCase {

	private int $admin;
	private int $member;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down(): void {
		unset( $_SERVER['REQUEST_METHOD'], $_POST['jt_invite_nonce'], $_GET['view'] );
		parent::tear_down();
	}

	private function space(): object {
		$id = (int) Space::create(
			array(
				'title'      => 'Weekend Hikers',
				'slug'       => 'rw5-' . uniqid(),
				'visibility' => 'public',
			),
			$this->admin
		);
		return Space::find( $id );
	}

	public function test_join_press_with_a_bad_nonce_shows_a_notice_and_joins_nobody(): void {
		$space = $this->space();
		$data  = array( 'slug' => InviteLink::generate( (int) $space->id, $this->admin ) );
		wp_set_current_user( $this->member );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['jt_invite_nonce']  = 'stale';

		ob_start();
		include JETONOMY_DIR . 'templates/views/invite.php';
		$html = (string) ob_get_clean();

		$this->assertFalse( SpaceMember::is_member( (int) $space->id, $this->member ), 'nothing stored' );
		$this->assertStringContainsString( 'jt-notice jt-notice-warning', $html );
		$this->assertStringContainsString( 'This page expired before you joined. Please try again.', $html );
		$this->assertStringContainsString( 'name="jt_invite_nonce"', $html, 'a fresh Join button to try again' );
	}

	/** @covers-existing Opening the link (GET) shows no warning. */
	public function test_opening_the_invite_link_shows_no_notice(): void {
		$space = $this->space();
		$data  = array( 'slug' => InviteLink::generate( (int) $space->id, $this->admin ) );
		wp_set_current_user( $this->member );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		ob_start();
		include JETONOMY_DIR . 'templates/views/invite.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="jt_invite_nonce"', $html );
		$this->assertStringNotContainsString( 'jt-notice', $html );
	}

	public function test_lift_check_on_a_users_list_row_judges_the_restricted_member(): void {
		$moderator = self::factory()->user->create( array( 'role' => 'editor' ) );
		$peer      = self::factory()->user->create( array( 'role' => 'editor' ) );
		Restriction::ban( $peer, 'global_ban', $this->admin );
		Restriction::ban( $this->member, 'silence', $this->admin );

		$rows = Restriction::active_rows_for_users( array( $peer, $this->member ) );

		$this->assertSame( $peer, $rows[ $peer ]->user_id ?? null );
		$refused = Moderation_Permissions::lift_error( $moderator, $rows[ $peer ] );
		$this->assertWPError( $refused, 'a moderator may not lift a peer moderator\'s ban' );
		$this->assertSame( 'jetonomy_cannot_ban_peer_moderator', $refused->get_error_code() );
		$this->assertNull( Moderation_Permissions::lift_error( $moderator, $rows[ $this->member ] ) );
	}

	public function test_moderation_queue_honours_per_page_and_keeps_the_total(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		$space = $this->space();
		for ( $i = 0; $i < 5; $i++ ) {
			Post::create(
				array(
					'space_id'  => (int) $space->id,
					'author_id' => $this->member,
					'title'     => 'Held for review ' . $i,
					'slug'      => 'held-' . $i . '-' . uniqid(),
					'content'   => '<p>Waiting for a moderator.</p>',
					'status'    => 'pending',
				)
			);
		}
		wp_set_current_user( $this->admin );

		$request = new WP_REST_Request( 'GET', '/jetonomy/v1/moderation/queue' );
		$request->set_param( 'per_page', 3 );
		$body = rest_do_request( $request )->get_data();
		$wp_rest_server = null;

		$this->assertCount( 3, $body['data'] ?? array() );
		$this->assertSame( 5, (int) ( $body['meta']['total'] ?? 0 ) );
		$this->assertTrue( $body['meta']['has_more'] ?? false );
	}

	public function test_space_flag_card_labels_the_reporter(): void {
		$reporter = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Riley Reporter',
			)
		);
		$flag     = (object) array(
			'id'          => 1,
			'object_type' => 'user',
			'object_id'   => $this->member,
			'reporter_id' => $reporter,
			'reason'      => 'spam',
			'description' => '',
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
		);
		wp_set_current_user( $this->admin );

		ob_start();
		\Jetonomy\Template_Loader::partial(
			'moderation/flag-card',
			array(
				'flag'             => $flag,
				'resolve_endpoint' => '/wp-json/jetonomy/v1/moderation/flags/',
				'base'             => \Jetonomy\base_url(),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Reported by Riley Reporter', $html );
	}

	public function test_space_trash_opens_on_the_tab_that_holds_items(): void {
		$space = $this->space();
		$post  = (int) Post::create(
			array(
				'space_id'  => (int) $space->id,
				'author_id' => $this->admin,
				'title'     => 'Gear swap',
				'slug'      => 'gear-' . uniqid(),
				'content'   => '<p>Selling a tent.</p>',
				'status'    => 'publish',
			)
		);
		Reply::create(
			array(
				'post_id'   => $post,
				'author_id' => $this->member,
				'content'   => '<p>Binned reply about boots.</p>',
				'status'    => 'trash',
			)
		);
		wp_set_current_user( $this->admin );
		$_GET['view'] = 'trash';
		$data         = array( 'slug' => $space->slug );

		ob_start();
		include JETONOMY_DIR . 'templates/views/space-moderation.php';
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'in the trash.', $html, 'the trash does not look empty' );
		$this->assertStringContainsString( 'Binned reply about boots.', $html );
	}

	public function test_importer_category_slug_has_no_timestamp(): void {
		$importer = new class() extends \Jetonomy\Import\Importer {
			public function get_source_name(): string {
				return 'Test';
			}
			public function is_source_available(): bool {
				return true;
			}
			public function get_source_stats(): array {
				return array();
			}
			public function run( array $options = array() ): array {
				return array();
			}
			public function get_total_count(): int {
				return 0;
			}
			public function run_batch( string $phase, int $offset, int $batch_size ): array {
				return array();
			}
			protected function map_source(): string {
				return 'rw5test';
			}
			public function category( string $name ): int {
				return $this->import_category( 'default', 'imported-rw5test', array( 'name' => $name ) );
			}
		};

		// The prefix stays: it is how a map-less re-run recognises the category.
		$this->assertSame( 'imported-rw5test', Category::find( $importer->category( 'Imported from Test Forum' ) )->slug );
	}

	public function test_deleting_notifications_returns_the_fresh_tab_counts(): void {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );

		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = (int) Notification::create(
				array(
					'user_id'     => $this->member,
					'actor_id'    => 0,
					'type'        => 'badge_earned',
					'object_type' => 'badge',
					'object_id'   => $i + 1,
					'message'     => 'Earned a badge',
				)
			);
		}
		wp_set_current_user( $this->member );
		$this->assertSame( 3, Notification::counts_by_filter( $this->member )['all'], 'cached before the delete' );

		$bulk = new WP_REST_Request( 'POST', '/jetonomy/v1/notifications/bulk' );
		$bulk->set_body_params(
			array(
				'action' => 'delete',
				'ids'    => array( $ids[0], $ids[1] ),
			)
		);
		$counts = rest_do_request( $bulk )->get_data()['counts'] ?? null;
		$this->assertSame( 1, $counts['all'] ?? null );
		$this->assertSame( 1, $counts['unread'] ?? null );
		$this->assertSame( 1, $counts['badges'] ?? null );

		$single = rest_do_request( new WP_REST_Request( 'DELETE', '/jetonomy/v1/notifications/' . $ids[2] ) )->get_data();
		$this->assertSame( 0, $single['counts']['all'] ?? null );
		$wp_rest_server = null;
	}
}
