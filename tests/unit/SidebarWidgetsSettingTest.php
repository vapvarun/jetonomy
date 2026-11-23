<?php
/**
 * Community sidebar widgets: on/off and order from Settings > Appearance.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use Jetonomy\Settings;
use WP_UnitTestCase;

/**
 * The owner's list decides which widgets render and in what order; the
 * before/after hooks always fire; a switched-off widget runs no query.
 */
class SidebarWidgetsSettingTest extends WP_UnitTestCase {

	/** @var string[] Hook names in the order they fired. */
	private array $fired = array();

	public function tear_down(): void {
		delete_option( 'jetonomy_settings' );
		parent::tear_down();
	}

	private function set_widgets( array $widgets ): void {
		update_option( 'jetonomy_settings', array( 'sidebar_widgets' => $widgets ) );
	}

	private function render(): string {
		foreach ( Settings::SIDEBAR_WIDGETS as $id ) {
			foreach ( array( 'before', 'after' ) as $when ) {
				$hook = "jetonomy_sidebar_{$when}_{$id}";
				add_action(
					$hook,
					function () use ( $hook ) {
						$this->fired[] = $hook;
					}
				);
			}
		}
		ob_start();
		\Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => null ) );
		return (string) ob_get_clean();
	}

	/**
	 * Render the sidebar and return the Jetonomy-table queries it ran.
	 *
	 * @return string[]
	 */
	private function jt_queries_while_rendering(): array {
		global $wpdb;
		$seen = array();
		$spy  = static function ( $sql ) use ( &$seen, $wpdb ) {
			if ( false !== strpos( (string) $sql, $wpdb->prefix . 'jt_' ) ) {
				$seen[] = $sql;
			}
			return $sql;
		};
		wp_cache_flush();
		add_filter( 'query', $spy );
		$this->render();
		remove_filter( 'query', $spy );
		return $seen;
	}

	private function all_off(): array {
		return array_map(
			static fn( string $id ): array => array(
				'id'      => $id,
				'enabled' => false,
			),
			Settings::SIDEBAR_WIDGETS
		);
	}

	public function test_sanitize_drops_unknown_and_duplicate_ids_and_appends_missing_ones(): void {
		$clean = Settings::sidebar_widgets(
			array(
				array(
					'id'      => 'popular_tags',
					'enabled' => '0',
					'order'   => 9,
				),
				array(
					'id'      => 'bogus',
					'enabled' => true,
				),
				array(
					'id'      => 'popular_tags',
					'enabled' => true,
				),
				array(
					'id'      => 'trending',
					'enabled' => '1',
				),
			)
		);
		$this->assertSame(
			array(
				array(
					'id'      => 'popular_tags',
					'enabled' => false,
				),
				array(
					'id'      => 'trending',
					'enabled' => true,
				),
				array(
					'id'      => 'top_members',
					'enabled' => true,
				),
			),
			$clean
		);
	}

	public function test_absent_setting_is_todays_sidebar(): void {
		delete_option( 'jetonomy_settings' );
		$this->assertSame(
			array(
				array(
					'id'      => 'trending',
					'enabled' => true,
				),
				array(
					'id'      => 'top_members',
					'enabled' => true,
				),
				array(
					'id'      => 'popular_tags',
					'enabled' => true,
				),
			),
			jetonomy_sidebar_widgets()
		);
	}

	public function test_hooks_fire_in_the_owner_order(): void {
		$this->set_widgets(
			array(
				array(
					'id'      => 'popular_tags',
					'enabled' => true,
				),
				array(
					'id'      => 'trending',
					'enabled' => false,
				),
				array(
					'id'      => 'top_members',
					'enabled' => true,
				),
			)
		);
		$this->render();
		$this->assertSame(
			array(
				'jetonomy_sidebar_before_popular_tags',
				'jetonomy_sidebar_after_popular_tags',
				'jetonomy_sidebar_before_trending',
				'jetonomy_sidebar_after_trending',
				'jetonomy_sidebar_before_top_members',
				'jetonomy_sidebar_after_top_members',
			),
			$this->fired,
			'Before and after fire for every widget, the switched-off one included, in the saved order.'
		);
	}

	public function test_switched_off_widgets_run_no_query_and_leave_no_card(): void {
		$this->set_widgets( $this->all_off() );
		$this->assertSame( array(), $this->jt_queries_while_rendering(), 'No widget data is fetched when every widget is off.' );
		$html = $this->render();
		$this->assertStringNotContainsString( 'jt-card', $html );
		$this->assertStringContainsString( 'jt-sidebar', $html, 'The aside still renders for hooks and WordPress widgets.' );
	}

	public function test_enabled_widget_fetches_its_data(): void {
		$widgets               = $this->all_off();
		$widgets[0]['enabled'] = true; // Trending.
		$this->set_widgets( $widgets );
		$this->assertNotEmpty( $this->jt_queries_while_rendering() );
	}

	public function test_show_filter_still_hides_an_enabled_widget(): void {
		$widgets               = $this->all_off();
		$widgets[0]['enabled'] = true; // Trending.
		$this->set_widgets( $widgets );
		add_filter( 'jetonomy_show_sidebar_trending', '__return_false' );
		$this->assertSame( array(), $this->jt_queries_while_rendering() );
	}

	public function test_code_can_switch_on_through_the_list_filter_only(): void {
		$this->set_widgets( $this->all_off() );
		add_filter( 'jetonomy_show_sidebar_trending', '__return_true' );
		$this->assertFalse( jetonomy_sidebar_widgets()[0]['enabled'], 'The show filter cannot override the owner.' );
		add_filter(
			'jetonomy_sidebar_widgets',
			static function ( array $w ): array {
				$w[0]['enabled'] = true;
				return $w;
			}
		);
		$this->assertTrue( jetonomy_sidebar_widgets()[0]['enabled'] );
	}

	public function test_appearance_form_saves_order_and_unchecked_boxes(): void {
		$clean = Settings::sanitize(
			array(
				'accent_color'    => '#0073aa',
				'sidebar_widgets' => array(
					array(
						'id'      => 'top_members',
						'enabled' => '1',
					),
					array(
						'id'      => 'popular_tags',
						'enabled' => '0',
					),
					array(
						'id'      => 'trending',
						'enabled' => '1',
					),
				),
			)
		);
		$this->assertSame( array( 'top_members', 'popular_tags', 'trending' ), wp_list_pluck( $clean['sidebar_widgets'], 'id' ) );
		$this->assertSame( array( true, false, true ), wp_list_pluck( $clean['sidebar_widgets'], 'enabled' ) );
	}

	public function test_rest_and_cli_writer_stores_the_list(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$result = Settings::write(
			array(
				'sidebar_widgets' => array(
					array(
						'id'      => 'popular_tags',
						'enabled' => false,
					),
				),
			),
			'rest'
		);
		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['notices'] );
		$stored = get_option( 'jetonomy_settings' );
		$this->assertSame( array( 'popular_tags', 'trending', 'top_members' ), wp_list_pluck( $stored['sidebar_widgets'], 'id' ) );
		$this->assertFalse( $stored['sidebar_widgets'][0]['enabled'] );
	}
}
