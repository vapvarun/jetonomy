<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;

/**
 * A private space shows a guest a generic prompt, and names itself only when
 * the owner turns guest teasers on; a topic
 * inside it answers exactly like a missing topic, so a guessed topic URL
 * confirms nothing.
 *
 * single-post.php declares a function, so it can be included once per process:
 * each render runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PrivateSpaceGuestPagesTest extends WP_UnitTestCase {

	private object $space;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->space = Space::find(
			(int) Space::create(
				array(
					'title'       => 'Partner Program',
					'slug'        => 'psg-' . uniqid(),
					'description' => 'Where agency partners plan launches.',
					'visibility'  => 'private',
				),
				$admin
			)
		);
		wp_set_current_user( 0 );
	}

	private function render( string $view, string $slug ): string {
		$data = array( 'slug' => $slug );
		ob_start();
		include JETONOMY_DIR . 'templates/views/' . $view . '.php';
		return (string) ob_get_clean();
	}

	public function test_a_topic_in_a_listed_private_space_reads_like_a_missing_topic(): void {
		$post = Post::find(
			(int) Post::create(
				array(
					'space_id'  => (int) $this->space->id,
					'author_id' => 1,
					'title'     => 'Q1 roadmap draft',
					'slug'      => 'q1-' . uniqid(),
					'content'   => '<p>Draft.</p>',
					'status'    => 'publish',
				)
			)
		);

		// Rendered once: the view declares a function, so a second include fatals.
		$private = $this->render( 'single-post', $post->slug );

		$this->assertSame( 404, http_response_code() );
		$this->assertStringContainsString( 'Topic not found.', $private );
		$this->assertStringContainsString( 'may have been removed, or the link is wrong.', $private );
		$this->assertStringNotContainsString( 'private', $private );
	}

	public function test_the_guest_gate_withholds_the_name_by_default(): void {
		$html = $this->render( 'space', $this->space->slug );

		$this->assertStringContainsString( 'is private. Log in to see it.', $html );
		$this->assertStringNotContainsString( 'Partner Program', $html );
		$this->assertStringNotContainsString( 'Where agency partners plan launches.', $html );
	}

	/** @covers-existing Teasers on already named the space; this pins that the 2.0.4 default does not remove it. */
	public function test_the_guest_gate_names_the_private_space_when_teasers_are_on(): void {
		update_option( 'jetonomy_settings', array( 'guest_private_teasers' => 1 ) );
		\Jetonomy\Cache::flush();
		$html = $this->render( 'space', $this->space->slug );

		$this->assertStringContainsString( 'Partner Program is private.', $html );
		$this->assertStringContainsString( 'Where agency partners plan launches.', $html );
	}
}
