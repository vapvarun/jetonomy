<?php
/**
 * Space access rules and private-space privacy, from the 2.0.3 gating walk:
 * access ends the moment the rule that granted it goes, the rule builder says
 * what is stored and what exists, space admins are told why rules are not
 * theirs, a private space a guest cannot see is not named in the tab title, and
 * search refuses a space the way every other route does.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use WP_REST_Request;
use WPDieException;
use Jetonomy\Adapters\Adapter_Registry;
use Jetonomy\Adapters\Membership_Adapter;
use Jetonomy\DB\Schema;
use Jetonomy\Membership_Roster_Sync;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\Notifications\Notifier;
use Jetonomy\Permissions\Capabilities;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\Queue;
use Jetonomy\Template_Loader;

class GatingFixesTest extends WP_UnitTestCase {

	private int $admin;
	private int $member;

	/** @var array<string, Membership_Adapter> */
	private array $saved_adapters = array();

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account     = new \WP_User( $this->admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		$this->member = $this->user();

		$this->saved_adapters = $this->adapters();
		$this->set_adapters( array() );

		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		$this->set_adapters( $this->saved_adapters );
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'document_title_parts' );
		$_GET = array();
		parent::tear_down();
	}

	private function adapters(): array {
		return ( new \ReflectionProperty( Adapter_Registry::class, 'membership' ) )->getValue();
	}

	private function set_adapters( array $adapters ): void {
		( new \ReflectionProperty( Adapter_Registry::class, 'membership' ) )->setValue( null, $adapters );
	}

	private function user( string $role = 'subscriber' ): int {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		UserProfile::find_or_create( $id );
		return $id;
	}

	private function space( array $data = array() ): int {
		return (int) Space::create(
			array_merge(
				array(
					'title'       => 'Gate ' . uniqid(),
					'slug'        => 'gate-' . uniqid(),
					'visibility'  => 'private',
					'join_policy' => 'invite',
				),
				$data
			)
		);
	}

	private function topic( int $space_id, int $author ): int {
		return (int) Post::create(
			array(
				'space_id'  => $space_id,
				'author_id' => $author,
				'title'     => 'Gate topic ' . uniqid(),
				'slug'      => 'gate-topic-' . uniqid(),
				'content'   => '<p>Body</p>',
				'status'    => 'publish',
			)
		);
	}

	private function rule( int $space_id, string $type, string $grants = 'participate', string $value = '' ): int {
		return AccessRule::create(
			array(
				'space_id'   => $space_id,
				'rule_type'  => $type,
				'rule_value' => '' !== $value ? $value : null,
				'grants'     => $grants,
				'space_role' => 'member',
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

	private function fake_adapter( bool $active = true ): Membership_Adapter {
		return new class( $active ) implements Membership_Adapter {
			public function __construct( private bool $active ) {}
			public function is_active(): bool {
				return $this->active;
			}
			public function get_user_levels( int $user_id ): array {
				return array();
			}
			public function user_has_level( int $user_id, string $level_id ): bool {
				return false;
			}
			public function get_all_levels(): array {
				return array(
					array(
						'id'    => 'gold',
						'label' => 'Gold',
					),
				);
			}
			public function get_level_label( string $level_id ): string {
				return 'Gold';
			}
			public function register_hooks(): void {}
		};
	}

	/** The wp-admin Access Rules tab as the owner sees it. */
	private function access_tab( int $space_id ): string {
		wp_set_current_user( $this->admin );
		$_GET = array(
			'page'     => 'jetonomy-spaces',
			'action'   => 'edit',
			'space_id' => (string) $space_id,
			'tab'      => 'access',
		);
		ob_start();
		( new \Jetonomy\Admin\Admin() )->render_spaces();
		return (string) ob_get_clean();
	}

	/** Title WordPress would print for a route, as a page load builds it. */
	private function title_for( string $route, string $slug ): string {
		( new \ReflectionProperty( Template_Loader::class, 'not_found' ) )->setValue( null, false );
		$data = array(
			'route' => $route,
			'slug'  => $slug,
		);
		foreach ( array( 'maybe_set_404', 'set_seo_meta' ) as $method ) {
			( new \ReflectionMethod( Template_Loader::class, $method ) )->invoke( null, $data );
		}
		$parts = apply_filters( 'document_title_parts', array( 'title' => '' ) );
		remove_all_filters( 'document_title_parts' );
		return (string) $parts['title'];
	}

	// 1: a synced roster row does not outlive the rule that put it there.
	public function test_deleting_the_rule_ends_access_for_a_synced_member_at_once(): void {
		$space = $this->space();
		$rule  = $this->rule( $space, 'logged_in' );
		SpaceMember::add( $space, $this->member, 'member', 'tier' );
		$this->assertTrue( Permission_Engine::can( $this->member, 'create_posts', $space ), 'the rule lets the synced member post' );

		AccessRule::delete( $rule );
		\Jetonomy\Cache::flush();

		$this->assertFalse( Permission_Engine::can( $this->member, 'create_posts', $space ), 'access ends with the rule, not at the next reconcile' );
		$this->assertFalse( Permission_Engine::can( $this->member, 'read', $space ) );
		$this->assertFalse( SpaceMember::is_member( $space, $this->member ), 'a synced row whose rule is gone is not a membership' );

		$created = $this->rest( 'POST', "/spaces/{$space}/posts", array( 'title' => 'After the rule went', 'content' => '<p>Still here?</p>' ), $this->member );
		$this->assertSame( 403, $created->get_status() );
	}

	// 1: a narrower rule left behind caps what the synced row can do.
	public function test_narrowing_the_rules_narrows_a_synced_member(): void {
		$space = $this->space();
		$wide  = $this->rule( $space, 'logged_in', 'participate' );
		$this->rule( $space, 'logged_in', 'read' );
		SpaceMember::add( $space, $this->member, 'member', 'tier' );

		AccessRule::delete( $wide );
		\Jetonomy\Cache::flush();

		$this->assertTrue( Permission_Engine::can( $this->member, 'read', $space ), 'the Read rule still lets them in' );
		$this->assertFalse( Permission_Engine::can( $this->member, 'create_posts', $space ), 'but the synced Member row no longer lets them post' );
		$this->assertSame( 'viewer', SpaceMember::get_role( $space, $this->member ) );
	}

	// 1: the stale rows are swept in the background, and a manual row is kept.
	public function test_deleting_a_rule_queues_a_roster_sweep_for_that_space(): void {
		$space  = $this->space();
		$rule   = $this->rule( $space, 'logged_in' );
		$manual = $this->user();
		SpaceMember::add( $space, $this->member, 'member', 'tier' );
		SpaceMember::add( $space, $manual, 'member', 'manual' );

		AccessRule::delete( $rule );
		$this->assertTrue( Queue::is_queued( Membership_Roster_Sync::SWEEP_HOOK, array( $space ) ), 'deleting a rule queues the sweep for its space' );

		do_action( Membership_Roster_Sync::SWEEP_HOOK, $space );

		$this->assertNull( SpaceMember::find_source( $space, $this->member ), 'the synced row is removed' );
		$this->assertSame( 'manual', SpaceMember::find_source( $space, $manual ), 'a manual row is never swept' );
	}

	// 1: an owner adding the person by hand takes over the stale synced row.
	public function test_adding_by_hand_over_a_stale_synced_row_makes_it_manual(): void {
		$space = $this->space();
		SpaceMember::add( $space, $this->member, 'member', 'tier' );
		$this->assertFalse( SpaceMember::is_member( $space, $this->member ), 'no rule grants this synced row' );

		SpaceMember::add( $space, $this->member, 'member', 'manual' );

		$this->assertTrue( SpaceMember::is_member( $space, $this->member ) );
		$this->assertSame( 'manual', SpaceMember::find_source( $space, $this->member ), 'the next sweep must not remove a person added by hand' );
	}

	// 1: once access ends, a reply to the person's own topic sends no email.
	public function test_no_reply_email_after_access_ended(): void {
		$space = $this->space();
		SpaceMember::add( $space, $this->member, 'member', 'manual' );
		$post = $this->topic( $space, $this->member );
		SpaceMember::remove( $space, $this->member );

		$reply = (int) Reply::create(
			array(
				'post_id'   => $post,
				'author_id' => $this->admin,
				'content'   => '<p>Answers coming this week.</p>',
				'status'    => 'publish',
			)
		);

		$to = '';
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) use ( &$to ) {
				$to .= ' ' . implode( ',', (array) $atts['to'] );
				return true;
			},
			10,
			2
		);
		( new Notifier() )->on_reply_created( $reply, $post );

		$this->assertStringNotContainsString( get_userdata( $this->member )->user_email, $to, 'no email about a space the person can no longer read' );
	}

	// 2: Full is Member for every rule type: stored, listed and explained.
	public function test_full_rule_is_member_for_every_rule_type(): void {
		$this->set_adapters( array( 'fake' => $this->fake_adapter() ) );
		$space = $this->space();
		AccessRule::create(
			array(
				'space_id'   => $space,
				'rule_type'  => 'membership',
				'rule_value' => 'gold',
				'grants'     => 'full',
				'space_role' => 'moderator',
			)
		);
		$created = $this->rest(
			'POST',
			"/spaces/{$space}/access-rules",
			array(
				'rule_type'  => 'role',
				'rule_value' => 'subscriber',
				'grants'     => 'full',
				'space_role' => 'admin',
			),
			$this->admin
		);
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'member', $created->get_data()['data']->space_role, 'a rule never stores more than Member' );

		foreach ( array( 'membership', 'role', 'capability', 'trust_level', 'logged_in' ) as $type ) {
			$this->assertSame( 'member', AccessRule::cap_space_role( 'full', 'admin', $type ), "{$type}: Full is Member" );
		}

		$html = $this->access_tab( $space );
		$row  = substr( $html, (int) strpos( $html, 'id="jetonomy-rules-table"' ) );
		$this->assertStringNotContainsString( 'Moderator', strip_tags( $row ), 'no rule row says Moderator' );
		$this->assertStringNotContainsString( 'Admin', strip_tags( $row ) );
		$this->assertStringContainsString( 'Participate and Full as Member.', $html );
	}

	// 3: the Membership type is offered, disabled with a reason, when no membership plugin runs.
	public function test_membership_type_is_shown_disabled_without_a_membership_plugin(): void {
		$space = $this->space();

		$html = $this->access_tab( $space );
		$this->assertMatchesRegularExpression( '/<option value="membership" disabled>[^<]*needs a membership plugin such as MemberPress or Paid Memberships Pro/', $html );

		$this->set_adapters( array( 'fake' => $this->fake_adapter() ) );
		$html = $this->access_tab( $space );
		$this->assertStringNotContainsString( 'needs a membership plugin', $html, 'with a membership plugin active, its own entries are offered instead' );
	}

	// 4: the "who the rule catches" note and the tab labels reach 4.5:1 on the admin background.
	public function test_rule_note_and_tab_labels_meet_contrast(): void {
		$css    = (string) file_get_contents( JETONOMY_DIR . 'assets/css/admin.css' );
		$tokens = array();
		preg_match_all( '/(--jt-admin-[a-z0-9-]+):\s*(#[0-9a-f]{6})/i', $css, $m, PREG_SET_ORDER );
		foreach ( $m as $hit ) {
			$tokens[ $hit[1] ] ??= $hit[2];
		}
		$lum      = static function ( string $hex ): float {
			$c = array_map(
				static function ( $v ) {
					$v /= 255;
					return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
				},
				sscanf( $hex, '#%02x%02x%02x' )
			);
			return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
		};
		$contrast = static fn( string $a, string $b ): float => ( max( $lum( $a ), $lum( $b ) ) + 0.05 ) / ( min( $lum( $a ), $lum( $b ) ) + 0.05 );

		foreach ( array( '.jetonomy-admin .jt-rule-preview__note', '.jetonomy-admin .nav-tab' ) as $selector ) {
			$this->assertMatchesRegularExpression( '/' . preg_quote( $selector, '/' ) . '\s*\{[^}]*color:\s*var\((--jt-admin-[a-z0-9-]+)/', $css );
			preg_match( '/' . preg_quote( $selector, '/' ) . '\s*\{[^}]*color:\s*var\((--jt-admin-[a-z0-9-]+)/', $css, $used );
			$this->assertGreaterThanOrEqual( 4.5, $contrast( $tokens[ $used[1] ], '#f0f0f1' ), "{$selector} must reach 4.5:1 on the wp-admin background" );
		}
	}

	// 5: a space admin is told why, on REST and on the wp-admin screen.
	public function test_space_admin_is_told_only_site_admins_manage_rules(): void {
		$space = $this->space();
		$owner = $this->user( 'editor' );
		SpaceMember::add( $space, $owner, 'admin', 'manual' );
		$message = 'Only site administrators can manage access rules for this space.';

		$create = $this->rest( 'POST', "/spaces/{$space}/access-rules", array( 'rule_type' => 'logged_in' ), $owner );
		$this->assertSame( 403, $create->get_status() );
		$this->assertSame( 'jetonomy_forbidden', $create->get_data()['code'] );
		$this->assertSame( $message, $create->get_data()['message'] );
		$this->assertSame( $message, $this->rest( 'GET', "/spaces/{$space}/access-rules", array(), $owner )->get_data()['message'] );

		new \Jetonomy\Admin\Admin(); // Registers the wp-admin hooks, as an admin page load does.
		wp_set_current_user( $owner );
		$_GET = array(
			'page'     => 'jetonomy-spaces',
			'action'   => 'edit',
			'space_id' => (string) $space,
			'tab'      => 'access',
		);
		try {
			do_action( 'admin_page_access_denied' );
			$this->fail( 'the space admin gets the explanation, not the bare WordPress refusal' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( $message, $e->getMessage() );
		}

		// Somebody who is not this space's admin still gets WordPress's own answer.
		wp_set_current_user( $this->member );
		do_action( 'admin_page_access_denied' );
		$this->addToAssertionCount( 1 );
	}

	// 6: the owner's help text says what Read allows: read and report.
	public function test_read_rule_help_says_read_and_report(): void {
		$this->assertStringContainsString( 'Lets matching people in to read and report.', $this->access_tab( $this->space() ) );
	}

	// 7: a private space a guest is not shown is not named in the tab title.
	public function test_private_space_without_preview_is_not_named_in_the_title(): void {
		wp_set_current_user( 0 );
		update_option( 'jetonomy_settings', array( 'guest_private_teasers' => false ) );
		$space = $this->space(
			array(
				'title' => 'Partner Program',
				'slug'  => 'partner-program',
			)
		);
		$post  = Post::find( $this->topic( $space, $this->admin ) );

		foreach ( array( 'space', 'space-members', 'new-post' ) as $route ) {
			$this->assertSame( \Jetonomy\community_title(), $this->title_for( $route, 'partner-program' ), "{$route} must not name the space" );
		}
		$topic_title = $this->title_for( 'post', (string) $post->slug );
		$this->assertStringNotContainsString( 'Partner', $topic_title );
		$this->assertStringNotContainsStringIgnoringCase( 'gate topic', $topic_title );

		// With the locked preview on, the guest already sees the name on the card.
		update_option( 'jetonomy_settings', array( 'guest_private_teasers' => true ) );
		$this->assertStringContainsString( 'Partner Program', $this->title_for( 'space', 'partner-program' ) );
	}

	/** The <head> the composer route prints, as a page load builds it. */
	private function new_topic_head( string $slug ): string {
		update_option( 'jetonomy_pro_extensions', array() ); // Free owns the composer's head.
		remove_all_actions( 'wp_head' );
		( new \ReflectionProperty( Template_Loader::class, 'not_found' ) )->setValue( null, false );
		( new \ReflectionMethod( Template_Loader::class, 'set_seo_meta' ) )->invoke(
			null,
			array(
				'route' => 'new-post',
				'slug'  => $slug,
			)
		);
		ob_start();
		do_action( 'wp_head' );
		$head = (string) ob_get_clean();
		remove_all_actions( 'wp_head' );
		return $head;
	}

	// 7: the composer's og/meta never names a hidden space, or a private one a guest is not shown.
	public function test_new_topic_head_never_names_a_withheld_space(): void {
		update_option( 'jetonomy_settings', array( 'guest_private_teasers' => false ) );
		$this->space(
			array(
				'title'      => 'Moderators Room',
				'slug'       => 'moderators-room',
				'visibility' => 'hidden',
				'type'       => 'qa',
			)
		);
		$this->space(
			array(
				'title' => 'Partner Program',
				'slug'  => 'partner-program',
			)
		);

		wp_set_current_user( $this->member );
		$head = $this->new_topic_head( 'moderators-room' );
		$this->assertStringContainsString( 'og:title', $head, 'the head was printed' );
		// og:url echoes the URL the viewer typed; the name must not appear anywhere else.
		$this->assertStringNotContainsString( 'Moderators Room', $head );
		$this->assertStringNotContainsString( 'Ask a Question', $head, 'nor its type' );

		wp_set_current_user( 0 );
		$head = $this->new_topic_head( 'partner-program' );
		$this->assertStringContainsString( 'og:title', $head );
		$this->assertStringNotContainsString( 'Partner Program', $head );

		// A member of a private space sees its name.
		SpaceMember::add( (int) Space::find_by_slug( 'partner-program' )->id, $this->member, 'member', 'manual' );
		wp_set_current_user( $this->member );
		$this->assertStringContainsString( 'Partner Program', $this->new_topic_head( 'partner-program' ) );
	}

	// 8: search refuses a space the way the other routes do.
	public function test_search_and_space_posts_refuse_like_the_space_route(): void {
		$private = $this->space();
		$hidden  = $this->space( array( 'visibility' => 'hidden' ) );

		foreach ( array( "/spaces/{$private}", "/spaces/{$private}/posts" ) as $route ) {
			$this->assertSame( 403, $this->rest( 'GET', $route, array(), $this->member )->get_status(), $route );
		}
		$this->assertSame( 403, $this->rest( 'GET', '/search', array( 'q' => 'roadmap', 'space_id' => $private ), $this->member )->get_status(), 'a listed private space may answer 403' );

		$missing = $this->rest( 'GET', '/spaces/999999', array(), $this->member );
		$this->assertSame( 404, $missing->get_status() );
		foreach ( array( "/spaces/{$hidden}", "/spaces/{$hidden}/posts", '/spaces/999999/posts' ) as $route ) {
			$got = $this->rest( 'GET', $route, array(), $this->member );
			$this->assertSame( 404, $got->get_status(), $route );
			$this->assertSame( $missing->get_data(), $got->get_data(), "{$route} answers exactly like a missing space" );
		}
		foreach ( array( $hidden, 999999 ) as $id ) {
			$got = $this->rest( 'GET', '/search', array( 'q' => 'roadmap', 'space_id' => $id ), $this->member );
			$this->assertSame( 404, $got->get_status() );
			$this->assertSame( $missing->get_data(), $got->get_data() );
		}

		$this->assertSame( 200, $this->rest( 'GET', '/search', array( 'q' => 'roadmap', 'space_id' => $private ), $this->admin )->get_status(), 'the owner still searches it' );
	}
}
