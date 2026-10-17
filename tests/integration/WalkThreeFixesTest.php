<?php
/**
 * Fixes from the third QA walk (2026-10-17): category visibility follows the
 * spaces inside it, membership markers on space cards, honest HTTP statuses
 * on invite and banned-topic pages, the topic list `orderby`, the composer
 * picker, the QA page seeder, admin list search and sort, the anonymous-reveal
 * log row, the setup wizard, stand-alone IP bans, CLI create side effects,
 * guest-only counts, 429 for the new-member limit and default email copy.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WP_REST_Request;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\InviteLink;
use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\Permissions\Capabilities;

class WalkThreeFixesTest extends WP_Ajax_UnitTestCase {

	private int $admin;
	private int $member;

	public function set_up(): void {
		parent::set_up();
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		Schema::create_tables();
		Restriction::reset_memo();
		\Jetonomy\Cache::flush();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account     = new \WP_User( $this->admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		$this->member = $this->user();

		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		remove_all_filters( 'status_header' );
		remove_all_filters( 'pre_wp_mail' );
		parent::tear_down();
	}

	private function user(): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		UserProfile::find_or_create( $id );
		return $id;
	}

	private function space( array $data = array() ): int {
		$id = Space::create(
			array_merge(
				array(
					'title'      => 'W3 ' . uniqid(),
					'slug'       => 'w3-' . uniqid(),
					'visibility' => 'public',
				),
				$data
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	private function topic( int $space_id, int $author, array $data = array() ): int {
		return (int) Post::create(
			array_merge(
				array(
					'space_id'  => $space_id,
					'author_id' => $author,
					'title'     => 'W3 topic ' . uniqid(),
					'slug'      => 'w3-topic-' . uniqid(),
					'content'   => '<p>Body</p>',
					'status'    => 'publish',
				),
				$data
			)
		);
	}

	private function rest( string $method, string $route, array $params, int $as ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_do_request( $request );
	}

	/** HTTP status Template_Loader decides for a route, captured from status_header(). */
	private function route_status( string $route, string $slug, int $as ): int {
		wp_set_current_user( $as );
		$status = 200;
		add_filter(
			'status_header',
			static function ( $header, $code ) use ( &$status ) {
				$status = (int) $code;
				return $header;
			},
			10,
			2
		);
		$method = new \ReflectionMethod( \Jetonomy\Template_Loader::class, 'maybe_set_404' );
		$method->invoke(
			null,
			array(
				'route' => $route,
				'slug'  => $slug,
			)
		);
		remove_all_filters( 'status_header' );
		return $status;
	}

	private function ajax( string $action, array $post, string $nonce_action = 'jetonomy_admin' ): array {
		$_POST = array_merge( array( 'nonce' => wp_create_nonce( $nonce_action ) ), $post );
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the JSON response is asserted.
		}
		$response             = json_decode( $this->_last_response, true );
		$this->_last_response = '';
		return is_array( $response ) ? $response : array();
	}

	// 20: a category whose every space is hidden from the viewer does not exist for them.
	public function test_category_with_no_space_the_viewer_can_see_is_not_found(): void {
		$staff = Category::create( array( 'name' => 'W3 Staff', 'slug' => 'w3-staff-' . uniqid() ) );
		$this->space(
			array(
				'category_id' => $staff,
				'visibility'  => 'hidden',
				'join_policy' => 'invite',
			)
		);
		$open = Category::create( array( 'name' => 'W3 Open', 'slug' => 'w3-open-' . uniqid() ) );
		$sub  = Category::create( array( 'name' => 'W3 Sub', 'slug' => 'w3-sub-' . uniqid(), 'parent_id' => $open ) );
		$this->space( array( 'category_id' => $sub ) );

		foreach ( array( 0, $this->member ) as $viewer ) {
			wp_set_current_user( $viewer );
			$this->assertNull( Category::find_by_slug( Category::find( $staff )->slug, $viewer ), 'page route' );
			$this->assertSame( 404, $this->rest( 'GET', "/categories/{$staff}", array(), $viewer )->get_status(), 'REST single' );
			$listed = array_column( (array) $this->rest( 'GET', '/categories', array( 'per_page' => 100 ), $viewer )->get_data()['data'], 'id' );
			$this->assertNotContains( $staff, array_map( 'intval', $listed ), 'REST list' );
			$this->assertContains( $open, array_map( 'intval', $listed ), 'a parent whose space sits in a sub-category stays listed' );
			$this->assertNotContains( $staff, array_map( 'intval', array_column( Category::list_top_level( $viewer ), 'id' ) ), 'home and sidebar list' );
		}

		$this->assertSame( 200, $this->rest( 'GET', "/categories/{$staff}", array(), $this->admin )->get_status(), 'managers see every category' );
		$this->assertContains( $staff, array_map( 'intval', array_column( Category::list_tree( $this->member ), 'id' ) ), 'the picker still offers it to file a space into' );
	}

	// 21: a member's card says they are in, not how to get in.
	public function test_space_card_shows_the_viewer_membership(): void {
		$space = $this->space(
			array(
				'visibility'  => 'private',
				'join_policy' => 'approval',
			)
		);
		SpaceMember::add( $space, $this->member, 'admin' );
		$render = static function ( int $as ) use ( $space ): string {
			wp_set_current_user( $as );
			ob_start();
			jetonomy_render_space_grid( array( Space::find( $space ) ), \Jetonomy\base_url() );
			return (string) ob_get_clean();
		};

		$mine = $render( $this->member );
		$this->assertStringContainsString( 'jt-space-card-badge-member', $mine );
		$this->assertStringContainsString( 'Admin', $mine );
		$this->assertStringNotContainsString( 'Approval required', $mine );

		$this->assertStringContainsString( 'Approval required', $render( $this->user() ), 'a non-member still sees how to get in' );
	}

	// 22: the invite landing answers 404 for an unknown link and 410 for a used-up one.
	public function test_invite_landing_status_codes(): void {
		$space = $this->space();
		$token = InviteLink::generate( $space, $this->admin, 1 );
		$this->assertSame( 200, $this->route_status( 'invite', $token, 0 ) );
		InviteLink::use_invite( (int) InviteLink::find_by_token( $token )->id );
		$this->assertSame( 410, $this->route_status( 'invite', $token, 0 ) );
		$this->assertSame( 404, $this->route_status( 'invite', 'no-such-token', 0 ) );
	}

	// 24: orderby is honoured with the sort values, and an unknown one is a 400.
	public function test_space_topic_list_honours_orderby(): void {
		$space = $this->space();
		$old   = $this->topic( $space, $this->admin, array( 'created_at' => '2026-01-01 00:00:00', 'last_reply_at' => '2026-01-01 00:00:00' ) );
		$this->topic( $space, $this->admin );
		Post::update( $old, array( 'vote_score' => 9 ) );

		$this->assertSame( 400, $this->rest( 'GET', "/spaces/{$space}/posts", array( 'orderby' => 'bogus' ), 0 )->get_status() );
		$popular = $this->rest( 'GET', "/spaces/{$space}/posts", array( 'orderby' => 'popular' ), 0 );
		$this->assertSame( 200, $popular->get_status() );
		$this->assertSame( $old, (int) $popular->get_data()['data'][0]['id'], 'most voted first' );
	}

	// 28: a member banned from a public space is told so on its topics, not "not found".
	public function test_space_banned_member_gets_the_ban_on_a_public_topic(): void {
		$space = $this->space();
		$post  = $this->topic( $space, $this->admin );
		$slug  = Post::find( $post )->slug;
		Restriction::ban( $this->member, 'space_ban', $this->admin, $space );
		Restriction::reset_memo();

		$this->assertSame( 403, $this->route_status( 'post', $slug, $this->member ) );
		$this->assertInstanceOf( \WP_Error::class, \Jetonomy\Template_Loader::topic_ban_error( Post::find( $post ), $this->member ) );

		$private = $this->space( array( 'visibility' => 'private' ) );
		$hidden  = Post::find( $this->topic( $private, $this->admin ) );
		Restriction::ban( $this->member, 'space_ban', $this->admin, $private );
		Restriction::reset_memo();
		$this->assertSame( 404, $this->route_status( 'post', $hidden->slug, $this->member ), 'a private space keeps the 404' );
	}

	// 32: the composer's space picker leaves out spaces that take no topics.
	public function test_postable_picker_leaves_out_archived_spaces(): void {
		$live     = $this->space();
		$archived = $this->space( array( 'status' => 'archived' ) );
		SpaceMember::add( $live, $this->member );
		SpaceMember::add( $archived, $this->member );

		$ids = array_map( 'intval', array_column( (array) $this->rest( 'GET', '/spaces', array( 'postable_by_me' => true ), $this->member )->get_data()['data'], 'id' ) );
		$this->assertContains( $live, $ids );
		$this->assertNotContains( $archived, $ids );
	}

	// 33: the QA page seeder points the space-members shortcode at a real space.
	public function test_qa_page_seeder_uses_a_real_space_slug(): void {
		$this->space( array( 'slug' => 'general-discussion' ) );
		$args = array();
		ob_start();
		include dirname( __DIR__, 2 ) . '/bin/seed-qa-pages.php';
		ob_end_clean();

		$page = get_page_by_path( 'jt-qa-sc-space-members', OBJECT, 'page' );
		$this->assertStringContainsString( 'space_id="general-discussion"', (string) $page->post_content );
	}

	// 34: admin Spaces and Content lists search and sort in SQL.
	public function test_admin_lists_search_and_sort(): void {
		$alpha = $this->space( array( 'title' => 'W3 Alpha Lounge' ) );
		$beta  = $this->space( array( 'title' => 'W3 Beta Lounge' ) );
		Space::update( $alpha, array( 'post_count' => 1 ) );
		Space::update( $beta, array( 'post_count' => 50 ) );

		$found = Space::list_for_admin( array( 'search' => 'Alpha Lounge' ), 20, 0 );
		$this->assertSame( array( $alpha ), array_map( 'intval', array_column( $found, 'id' ) ) );
		$this->assertSame( 1, Space::count_for_admin( array( 'search' => 'Alpha Lounge' ) ) );

		$by_posts = Space::list_for_admin(
			array(
				'search'  => 'Lounge',
				'orderby' => 'post_count',
				'order'   => 'DESC',
			),
			20,
			0
		);
		$this->assertSame( $beta, (int) $by_posts[0]->id );

		$space = $this->space();
		$zed   = $this->topic( $space, $this->admin, array( 'title' => 'Zed W3' ) );
		$this->topic( $space, $this->admin, array( 'title' => 'Abe W3' ) );
		$rows = Post::list_for_admin(
			array(
				'space_id' => $space,
				'orderby'  => 'title',
				'order'    => 'DESC',
			),
			20,
			0
		);
		$this->assertSame( $zed, (int) $rows[0]->id );
	}

	// 37: the reveal log row names the real author, or says they are gone.
	public function test_reveal_log_row_names_the_author(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		$table = new \Jetonomy\Admin\Activity_List_Table();
		$named = self::factory()->user->create( array( 'display_name' => 'Rhea Revealed' ) );
		$row   = (object) array(
			'action'   => 'anonymous_author_revealed',
			'metadata' => wp_json_encode( array( 'real_author' => $named ) ),
		);
		$html  = $table->column_snippet( $row );
		$this->assertStringContainsString( 'Rhea Revealed', $html );
		$this->assertStringContainsString( '<a href=', $html );

		$row->metadata = wp_json_encode( array( 'real_author' => 999999 ) );
		$this->assertStringContainsString( 'Deleted', $table->column_snippet( $row ) );
	}

	// 39: running the setup wizard again with the same names reuses them.
	public function test_setup_wizard_reuses_existing_names(): void {
		wp_set_current_user( $this->admin );
		new \Jetonomy\Admin\Ajax\Setup_Handler();
		$post = array(
			'category_name' => 'W3 Wizard Cat',
			'space_name'    => 'W3 Wizard Space',
		);
		$this->ajax( 'jetonomy_setup_save', $post, 'jetonomy_setup' );
		$second = $this->ajax( 'jetonomy_setup_save', $post, 'jetonomy_setup' );
		$this->assertTrue( $second['success'] ?? false );

		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Jetonomy\table( 'categories' ) . ' WHERE name = %s', 'W3 Wizard Cat' ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Jetonomy\table( 'spaces' ) . ' WHERE title = %s', 'W3 Wizard Space' ) ) );
	}

	// 40: an IP ban needs no member and stores none.
	public function test_ip_ban_stands_alone(): void {
		$res = $this->rest(
			'POST',
			'/moderation/ban',
			array(
				'type' => 'ip_ban',
				'ip'   => '203.0.113.40',
			),
			$this->admin
		);
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 0, (int) Restriction::find( (int) $res->get_data()['restriction_id'] )->user_id );

		$named = $this->rest(
			'POST',
			'/moderation/ban',
			array(
				'type'    => 'ip_ban',
				'ip'      => '203.0.113.41',
				'user_id' => $this->member,
			),
			$this->admin
		);
		$this->assertSame( 0, (int) Restriction::find( (int) $named->get_data()['restriction_id'] )->user_id, 'a member sent along is not attached' );
		$this->assertFalse( Restriction::is_banned( $this->member ), 'the member is not restricted' );
	}

	// 41: a CLI reply reaches the topic author like a REST reply does.
	public function test_cli_reply_notifies_the_topic_author(): void {
		$space  = $this->space();
		$author = $this->user();
		$post   = $this->topic( $space, $author );
		$before = Notification::count_for_user( $author );

		$result = ( new \Jetonomy\CLI\Journeys\Content_Journey() )->create_reply(
			array(
				'post_id'   => $post,
				'author_id' => $this->member,
				'content'   => '<p>From the terminal</p>',
			)
		);
		$this->assertTrue( $result->success, (string) $result->first_error() );
		$this->assertSame( $before + 1, Notification::count_for_user( $author ) );
	}

	// 42: the guest home pulse counts only topics a guest can read.
	public function test_home_pulse_counts_only_guest_readable_topics(): void {
		$public  = $this->space();
		$private = $this->space( array( 'visibility' => 'private' ) );
		$this->topic( $public, $this->admin );
		$this->topic( $private, $this->admin );
		$this->topic( $private, $this->admin );
		\Jetonomy\Recount::run( 'all' );
		delete_transient( 'jetonomy_community_pulse' );

		$pulse = jetonomy_community_pulse();
		$this->assertSame( (int) Space::find( $public )->post_count, $pulse['posts'] - $this->other_public_posts( $public ) );
		$this->assertSame( 1, $pulse['posts_week'] - $this->other_public_week( $public ) );
	}

	/** Guest-readable topics outside the test's public space (the suite DB is shared). */
	private function other_public_posts( int $exclude ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(post_count),0) FROM ' . \Jetonomy\table( 'spaces' ) . " WHERE visibility = 'public' AND id <> %d", $exclude ) );
	}

	private function other_public_week( int $exclude ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Jetonomy\table( 'posts' ) . ' p JOIN ' . \Jetonomy\table( 'spaces' ) . " s ON s.id = p.space_id WHERE s.visibility = 'public' AND p.status = 'publish' AND p.created_at >= %s AND s.id <> %d", gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ), $exclude ) );
	}

	// 43: the new-member daily limit is a 429, not a validation error.
	public function test_new_member_limit_is_429(): void {
		$settings                = get_option( 'jetonomy_settings', array() );
		$settings['rate_limits'] = array(
			'posts'   => 1,
			'replies' => 1,
			'votes'   => 1,
		);
		update_option( 'jetonomy_settings', $settings );
		$space = $this->space();
		\Jetonomy\Permissions\Rate_Limiter::increment( $this->member, 'create_posts' );

		$res = $this->rest(
			'POST',
			"/spaces/{$space}/posts",
			array(
				'title'   => 'Over the limit',
				'content' => '<p>Body</p>',
			),
			$this->member
		);
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'jetonomy_rate_limited', $res->as_error()->get_error_code() );
	}

	// Owner decision A: a blank template field sends the default Settings > Email shows, for every email type.
	public function test_blank_email_template_sends_the_shown_default(): void {
		$types = array_keys( \Jetonomy\Admin\Admin::email_types() );
		$this->assertContains( 'reply_by_email_refused', $types );
		update_option( 'jetonomy_email_templates', array_fill_keys( $types, array( 'subject' => '', 'body' => '' ) ) );
		$sent = array();
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) use ( &$sent ) {
				$sent[] = $atts;
				return true;
			},
			10,
			2
		);
		$map = array(
			'{site}'               => get_bloginfo( 'name' ),
			'{actor_display_name}' => 'Ana',
			'{message}'            => 'The message text',
			'{user}'               => get_userdata( $this->member )->display_name,
		);

		foreach ( $types as $type ) {
			$sent = array();
			\Jetonomy\Notifications\Notifier::send_email_notification( $this->member, $type, 'The message text', '', 0, home_url( '/' ), array( 'actor_display_name' => 'Ana' ) );
			$this->assertCount( 1, $sent, $type );
			$default = \Jetonomy\Notifications\Notifier::get_default_template( $type );
			$this->assertNotSame( '', $default['subject'], "{$type} has a default on the settings screen" );
			// Tokens with no value in this send ({space_title}, {post_title}) render empty.
			$this->assertSame( preg_replace( '/\{[a-z_]+\}/', '', strtr( $default['subject'], $map ) ), $sent[0]['subject'], $type );
			$this->assertStringContainsString( strtok( preg_replace( '/\{[a-z_]+\}/', '', strtr( $default['body'], $map ) ), "\n" ), wp_strip_all_tags( (string) $sent[0]['message'] ), "{$type} body" );
		}
	}
}
