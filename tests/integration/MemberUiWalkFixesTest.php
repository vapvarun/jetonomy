<?php
/**
 * Member-facing fixes from the 2.0.3 wave-3 walk (lane B): mention links read
 * correctly in right-to-left text, cross-space lists name the space, the My
 * Spaces card shows the whole description, join-request decisions confirm,
 * WordPress post embeds fall back to the link card, the connect-app screen can
 * be declined, search says why a 1-character term found nothing and counts
 * every match, and presence is exact only for the member and staff.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Bookmark;
use Jetonomy\Models\Category;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\Permissions\Capabilities;

class MemberUiWalkFixesTest extends WP_UnitTestCase {

	private int $admin;
	private int $member;
	private int $cat;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account     = new \WP_User( $this->admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		UserProfile::find_or_create( $this->member );
		$this->cat = (int) Category::create(
			array(
				'name' => 'Walk fixes',
				'slug' => 'walk-fixes-' . uniqid(),
			)
		);
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_GET['q'], $_GET['filter'], $_GET['scheme'] );
		remove_all_filters( 'pre_oembed_result' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function space( string $title, array $data = array() ): int {
		return (int) Space::create(
			array_merge(
				array(
					'title'       => $title,
					'slug'        => 'wf-' . uniqid(),
					'category_id' => $this->cat,
					'visibility'  => 'public',
					'join_policy' => 'open',
					'type'        => 'forum',
				),
				$data
			),
			$this->admin
		);
	}

	private function topic( int $space_id, int $author ): int {
		return (int) Post::create(
			array(
				'space_id'  => $space_id,
				'author_id' => $author,
				'title'     => 'Walk topic ' . uniqid(),
				'slug'      => 'walk-topic-' . uniqid(),
				'content'   => '<p>Body</p>',
				'status'    => 'publish',
			)
		);
	}

	private function rest( string $method, string $route, int $as, array $params = array() ): \WP_REST_Response {
		wp_set_current_user( $as );
		$request = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_do_request( $request );
	}

	private function render_view( string $view, array $data = array() ): string {
		ob_start();
		include JETONOMY_DIR . 'templates/views/' . $view . '.php';
		return (string) ob_get_clean();
	}

	private function seen( int $user_id, int $seconds_ago ): string {
		global $wpdb;
		$at = gmdate( 'Y-m-d H:i:s', time() - $seconds_ago );
		$wpdb->update( \Jetonomy\table( 'user_profiles' ), array( 'last_seen_at' => $at ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		\Jetonomy\Cache::flush();
		return $at;
	}

	public function test_mention_link_is_isolated_left_to_right_inside_rtl_text(): void {
		$omar = self::factory()->user->create( array( 'user_login' => 'omar' ) );
		$text = 'شكرا @omar على المساعدة';
		$html = \Jetonomy\Mentions::linkify( $text, \Jetonomy\Mentions::link_map( $text ) );

		$this->assertMatchesRegularExpression( '#<a href="[^"]+" class="jt-mention" dir="ltr">@omar</a>#', $html );
		$this->assertGreaterThan( 0, $omar );
	}

	public function test_bookmarks_list_names_each_topics_space(): void {
		$space = $this->space( 'Trail Reports' );
		Bookmark::toggle( $this->member, $this->topic( $space, $this->admin ) );

		wp_set_current_user( $this->member );
		$html = $this->render_view( 'bookmarks' );

		$this->assertMatchesRegularExpression( '#<a class="jt-row-space" href="[^"]+" dir="auto">Trail Reports</a>#', $html );
		$this->assertStringContainsString( 'data-jt-key="post-', $html, 'rows carry the key Load More dedupes on' );
	}

	public function test_my_spaces_card_carries_the_whole_description(): void {
		$description = str_repeat( 'Weekend hikes through the countryside around the city. ', 6 );
		$space       = $this->space( str_repeat( 'Greater Amsterdam Weekend Hikers ', 4 ), array( 'description' => $description ) );
		SpaceMember::add( $space, $this->member, 'member' );

		wp_set_current_user( $this->member );
		$html = $this->render_view( 'my-spaces' );

		$this->assertStringContainsString( esc_html( $description ), $html, 'full text, clamped by CSS only' );
		$this->assertStringNotContainsString( '…</p>', $html );
	}

	public function test_join_request_decisions_return_the_confirmation(): void {
		$space = $this->space( 'Mentorship', array( 'join_policy' => 'request' ) );
		$alice = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$bob   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$ra    = JoinRequest::create_request( $space, $alice, 'hi' );
		$rb    = JoinRequest::create_request( $space, $bob, 'hello' );

		$approved = $this->rest( 'POST', "/spaces/{$space}/join-requests/{$ra}/approve", $this->admin );
		$this->assertSame( 200, $approved->get_status(), wp_json_encode( $approved->get_data() ) );
		$this->assertSame( 'Join request approved.', $approved->get_data()['message'] );

		$denied = $this->rest( 'POST', "/spaces/{$space}/join-requests/{$rb}/deny", $this->admin );
		$this->assertSame( 200, $denied->get_status() );
		$this->assertSame( 'Join request denied.', $denied->get_data()['message'] );
	}

	public function test_a_wordpress_post_embed_stays_a_link_for_the_preview_card(): void {
		$url = 'https://wordpress.org/news/' . uniqid() . '/';
		add_filter(
			'pre_oembed_result',
			static function ( $result, $for ) use ( $url ) {
				return $for === $url
					? '<blockquote class="wp-embedded-content"><a href="' . $url . '">News</a></blockquote><iframe class="wp-embedded-content" sandbox="allow-scripts" src="' . $url . 'embed/"></iframe>'
					: $result;
			},
			10,
			2
		);

		$html = \Jetonomy\Embeds::process( '<p>' . $url . '</p>' );

		$this->assertStringNotContainsString( 'wp-embedded-content', $html );
		$this->assertStringContainsString( '<a href="' . $url . '" target="_blank" rel="noopener">' . $url . '</a>', $html );
	}

	public function test_connect_app_screen_offers_cancel_and_a_full_size_account_switch(): void {
		$_GET['scheme'] = \Jetonomy\Integrations\App_Connect::schemes()[0];
		wp_set_current_user( $this->member );
		$html = $this->render_view( 'connect-app' );

		$this->assertMatchesRegularExpression( '#<a class="jt-btn jt-btn-ghost" href="[^"]+">\s*Cancel\s*</a>#', $html );
		$this->assertStringContainsString( 'class="jt-connect-app-switch"', $html );
	}

	public function test_search_explains_the_two_character_minimum_only_when_it_applies(): void {
		wp_set_current_user( $this->member );

		$_GET['q'] = 'a';
		$this->assertStringContainsString( 'Search query must be at least 2 characters.', $this->render_view( 'search' ) );

		$_GET['q']      = 'zzqxnomatch';
		$_GET['filter'] = 'spaces'; // Spaces search by LIKE; the test tables carry no FULLTEXT index.
		$html           = $this->render_view( 'search' );
		$this->assertStringContainsString( 'No results for', $html );
		$this->assertStringNotContainsString( '2 characters', $html, 'an ordinary miss is not about length' );
	}

	public function test_search_header_counts_every_match_not_the_rows_shown(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->space( 'Birdwatch group ' . $i );
		}
		wp_set_current_user( $this->member );
		$_GET['q']      = 'birdwatch';
		$_GET['filter'] = 'spaces';

		$html = $this->render_view( 'search' );

		$this->assertMatchesRegularExpression( '#<span class="jt-search-result-count">\s*12 results\s*</span>#', $html, 'ten cards shown, twelve found' );
	}

	public function test_exact_last_seen_only_for_the_member_and_staff(): void {
		$space = $this->space( 'Presence' );
		SpaceMember::add( $space, $this->member, 'member' );
		$this->topic( $space, $this->member );
		$exact = $this->seen( $this->member, 3 * HOUR_IN_SECONDS );
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertNull( $this->rest( 'GET', "/users/{$this->member}", 0 )->get_data()['last_seen_at'], 'guest' );
		$this->assertNull( $this->rest( 'GET', "/users/{$this->member}", $other )->get_data()['last_seen_at'], 'another member' );
		$this->assertSame( $exact, $this->rest( 'GET', "/users/{$this->member}", $this->member )->get_data()['last_seen_at'], 'the member' );
		$this->assertSame( $exact, $this->rest( 'GET', "/users/{$this->member}", $this->admin )->get_data()['last_seen_at'], 'staff' );

		// Every other list that carries presence follows the same rule.
		$members = $this->rest( 'GET', "/spaces/{$space}/members", $other )->get_data();
		$row     = current( array_filter( $members['data'] ?? $members, fn( $m ) => (int) $m['user_id'] === $this->member ) );
		$this->assertNull( $row['last_seen_at'] );
		$this->assertNull( $row['last_seen_at_gmt'] );
		$posts = $this->rest( 'GET', "/spaces/{$space}/posts", $other )->get_data()['data'];
		$this->assertNull( $posts[0]['author_last_seen_at'] );
		$this->assertNull( $posts[0]['author_last_seen_at_gmt'] );
		$this->assertSame( $exact, $this->rest( 'GET', "/spaces/{$space}/posts", $this->member )->get_data()['data'][0]['author_last_seen_at'] );

		// Online now is what the dot shows, so others still learn that.
		$this->seen( $this->member, 30 );
		$this->assertNotNull( $this->rest( 'GET', "/users/{$this->member}", 0 )->get_data()['last_seen_at'] );
	}
}
