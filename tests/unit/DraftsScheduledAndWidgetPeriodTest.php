<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Template_Loader;
use Jetonomy\Widgets\Leaderboard_Widget;

/**
 * My drafts shows a scheduled topic's release time (site timezone), and the
 * leaderboard widget saves an instance that carries no period without a PHP warning.
 */
class DraftsScheduledAndWidgetPeriodTest extends WP_UnitTestCase {

	private function badges( object $post ): string {
		ob_start();
		Template_Loader::partial( 'post-badges', array( 'post' => $post, 'space' => null ) );
		return (string) ob_get_clean();
	}

	public function test_scheduled_draft_card_shows_release_time_in_site_timezone(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );

		$html = $this->badges( (object) array( 'id' => 1, 'status' => 'draft', 'published_at' => '2026-12-01 09:00:00' ) );

		$this->assertStringContainsString( 'Scheduled: December 1, 2026 2:30 pm', $html );
	}

	/**
	 * Negative control: pins behaviour that already worked, so it passes before and after the change.
	 *
	 * @covers-existing
	 */
	public function test_plain_draft_and_published_topic_show_no_schedule(): void {
		$this->assertStringNotContainsString( 'Scheduled:', $this->badges( (object) array( 'id' => 2, 'status' => 'draft', 'published_at' => null ) ) );
		$this->assertStringNotContainsString( 'Scheduled:', $this->badges( (object) array( 'id' => 3, 'status' => 'publish', 'published_at' => '2026-12-01 09:00:00' ) ) );
	}

	public function test_widget_instance_without_period_saves_as_all_time(): void {
		$saved = ( new Leaderboard_Widget() )->update( array( 'title' => 'Top helpers', 'count' => 5 ), array() );
		$this->assertSame( 'all', $saved['period'] );
		$this->assertSame( 'week', ( new Leaderboard_Widget() )->update( array( 'period' => 'week' ), array() )['period'] );
		$this->assertSame( 'all', ( new Leaderboard_Widget() )->update( array( 'period' => 'year' ), array() )['period'] );
	}
}
