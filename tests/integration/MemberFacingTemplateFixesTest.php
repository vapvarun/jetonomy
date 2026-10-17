<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;

/**
 * Member-facing template fixes from the 2.0.3 wave-2 walk: search marks the
 * words a member searched for, the reply composer says why an empty reply was
 * not posted, user text carries its own direction, and the compose block
 * names a restriction instead of "join a space first".
 */
class MemberFacingTemplateFixesTest extends WP_UnitTestCase {

	private int $admin;
	private int $member;
	private int $cat;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->cat    = (int) Category::create(
			array(
				'name' => 'Template fixes',
				'slug' => 'tpl-fixes-' . uniqid(),
			)
		);
	}

	public function tear_down(): void {
		unset( $_GET['q'], $_GET['filter'] );
		parent::tear_down();
	}

	private function space( string $title, string $description = '' ): int {
		return (int) Space::create(
			array(
				'title'       => $title,
				'slug'        => 'tf-' . uniqid(),
				'description' => $description,
				'category_id' => $this->cat,
				'visibility'  => 'public',
				'join_policy' => 'open',
				'type'        => 'forum',
			),
			$this->admin
		);
	}

	private function render_view( string $view, array $data = array() ): string {
		ob_start();
		include JETONOMY_DIR . 'templates/views/' . $view . '.php';
		return (string) ob_get_clean();
	}

	private function render_partial( string $name, array $args ): string {
		ob_start();
		\Jetonomy\Template_Loader::partial( $name, $args );
		return (string) ob_get_clean();
	}

	public function test_search_marks_the_searched_words_ignoring_case_and_accents(): void {
		$this->space( 'Café <b>corner</b> meetups', 'Where the CAFE regulars plan the week.' );
		wp_set_current_user( $this->member );
		$_GET['q'] = 'cafe';

		$_GET['filter'] = 'spaces'; // Spaces search by LIKE; the test tables carry no FULLTEXT index.
		$html           = $this->render_view( 'search' );

		$this->assertStringContainsString( '<mark class="jt-hl">Café</mark>', $html, 'the accented title word is marked as written' );
		$this->assertStringContainsString( '<mark class="jt-hl">CAFE</mark>', $html, 'case does not matter' );
		$this->assertStringContainsString( '&lt;b&gt;corner&lt;/b&gt;', $html, 'the rest of the title is still escaped text' );
		$this->assertStringNotContainsString( '<b>corner</b>', $html, 'no stored markup is injected' );
	}

	public function test_search_marks_only_from_a_word_start_like_the_prefix_search(): void {
		$this->space( 'Decafe and cafeteria notes' );
		wp_set_current_user( $this->member );
		$_GET['q'] = 'cafe';

		$_GET['filter'] = 'spaces'; // Spaces search by LIKE; the test tables carry no FULLTEXT index.
		$html           = $this->render_view( 'search' );

		$this->assertStringContainsString( 'Decafe and <mark class="jt-hl">cafe</mark>teria', $html );
	}

	public function test_search_results_carry_their_own_text_direction(): void {
		$this->space( 'تجربتي مع مجموعات التصوير' );
		wp_set_current_user( $this->member );
		$_GET['q'] = 'تجربتي';

		$_GET['filter'] = 'spaces'; // Spaces search by LIKE; the test tables carry no FULLTEXT index.
		$html           = $this->render_view( 'search' );

		$this->assertMatchesRegularExpression( '/class="jt-space-card-title" dir="auto">.*تجربتي/u', $html );
	}

	public function test_reply_composer_has_an_announced_slot_for_the_empty_reply_error(): void {
		wp_set_current_user( $this->member );

		$html = $this->render_partial( 'composer', array( 'post_id' => 7 ) );

		$this->assertMatchesRegularExpression( '/<p class="jt-form-error[^"]*" role="alert" data-wp-bind--hidden="!context.error" data-wp-text="context.error"/', $html );
		$this->assertMatchesRegularExpression( '/data-wp-context=\'([^\']*)\'/', $html );
		preg_match( '/data-wp-context=\'([^\']*)\'/', $html, $m );
		$context = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		$this->assertSame( 7, $context['postId'] );
		$this->assertSame( '', $context['error'] );
		$this->assertNotEmpty( $context['emptyError'], 'the message is translated server side' );
		$this->assertStringContainsString( 'dir="auto"', $html, 'the editor takes the direction of what is typed' );
	}

	public function test_compose_block_shows_the_restriction_instead_of_the_form(): void {
		wp_set_current_user( $this->member );

		$html = $this->render_partial(
			'compose-topic-embed',
			array(
				'mode'        => 'picker',
				'postable'    => array(),
				'restriction' => 'Your account is currently silenced and cannot post.',
			)
		);

		$this->assertStringContainsString( 'Your account is currently silenced and cannot post.', $html );
		$this->assertStringNotContainsString( 'jt-compose-topic-form', $html, 'no form the server would refuse' );
		$this->assertStringNotContainsString( 'jt-compose-topic-empty', $html, 'no "join a space first" hint' );
	}

	/**
	 * @covers-existing The unrestricted picker still renders the form.
	 */
	public function test_compose_block_without_a_restriction_still_renders_the_form(): void {
		wp_set_current_user( $this->member );

		$html = $this->render_partial(
			'compose-topic-embed',
			array(
				'mode'     => 'picker',
				'postable' => array(),
			)
		);

		$this->assertStringContainsString( 'jt-compose-topic-form', $html );
	}

	/**
	 * The old view printed a meta refresh and exit()ed here, so on the old code
	 * this test ends the run instead of passing.
	 */
	public function test_invite_link_tells_an_existing_member_they_are_already_in(): void {
		$space = $this->space( 'Insiders / Beta' );
		\Jetonomy\Models\SpaceMember::add( $space, $this->member, 'member' );
		$token = \Jetonomy\Models\InviteLink::generate( $space, $this->admin );
		wp_set_current_user( $this->member );

		$html = $this->render_view( 'invite', array( 'slug' => $token ) );

		$this->assertStringContainsString( 'You are already a member of', $html );
		$this->assertStringContainsString( 'Insiders / Beta', $html );
		$this->assertStringContainsString( esc_url( \Jetonomy\route_url( 'space', Space::find( $space )->slug ) ), $html, 'a link into the space' );
		$this->assertStringNotContainsString( 'http-equiv="refresh"', $html );
	}
}
