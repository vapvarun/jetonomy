<?php
/**
 * Registers Jetonomy pages in the WordPress nav menu editor.
 *
 * Adds a "Community" meta box to Appearance → Menus so admins
 * can add Community, Spaces, Leaderboard etc. to any menu.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Nav_Menus {

	public function __construct() {
		add_action( 'admin_head-nav-menus.php', [ $this, 'add_meta_box' ] );
	}

	/**
	 * Register the Community meta box on the Menus admin page.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'jetonomy-nav-menu',
			__( 'Community', 'jetonomy' ),
			[ $this, 'render_meta_box' ],
			'nav-menus',
			'side',
			'default'
		);
	}

	/**
	 * Render the Community menu items meta box.
	 */
	public function render_meta_box(): void {
		$base = home_url( '/' . $this->get_base_slug() . '/' );

		$items = [
			[
				'title' => __( 'Community Home', 'jetonomy' ),
				'url'   => $base,
				'class' => 'jetonomy-community',
			],
			[
				'title' => __( 'Search', 'jetonomy' ),
				'url'   => $base . 'search/',
				'class' => 'jetonomy-search',
			],
			[
				'title' => __( 'Leaderboard', 'jetonomy' ),
				'url'   => $base . 'leaderboard/',
				'class' => 'jetonomy-leaderboard',
			],
			[
				'title' => __( 'Notifications', 'jetonomy' ),
				'url'   => $base . 'notifications/',
				'class' => 'jetonomy-notifications',
			],
		];

		// Add dynamic spaces
		$spaces = $this->get_spaces();
		foreach ( $spaces as $space ) {
			$items[] = [
				'title' => $space->title,
				'url'   => $base . 's/' . $space->slug . '/',
				'class' => 'jetonomy-space',
			];
		}

		?>
		<div id="jetonomy-menu-items" class="posttypediv">
			<div class="tabs-panel tabs-panel-active" style="max-height:300px;overflow:auto;">
				<ul class="categorychecklist form-no-clear">
					<?php
					$i = -1;
					foreach ( $items as $item ) :
						?>
						<li>
							<label class="menu-item-title">
								<input type="checkbox"
									class="menu-item-checkbox"
									name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-object-id]"
									value="<?php echo esc_attr( $i ); ?>">
								<?php echo esc_html( $item['title'] ); ?>
							</label>
							<input type="hidden"
								class="menu-item-type"
								name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-type]"
								value="custom">
							<input type="hidden"
								class="menu-item-title"
								name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-title]"
								value="<?php echo esc_attr( $item['title'] ); ?>">
							<input type="hidden"
								class="menu-item-url"
								name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-url]"
								value="<?php echo esc_url( $item['url'] ); ?>">
							<input type="hidden"
								class="menu-item-classes"
								name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-classes]"
								value="<?php echo esc_attr( $item['class'] ); ?>">
						</li>
						<?php
						$i--;
					endforeach;
					?>
				</ul>
			</div>

			<p class="button-controls wp-clearfix">
				<span class="list-controls">
					<label>
						<input type="checkbox" class="select-all">
						<?php esc_html_e( 'Select All', 'jetonomy' ); ?>
					</label>
				</span>
				<span class="add-to-menu">
					<input type="submit"
						class="button submit-add-to-menu right"
						value="<?php esc_attr_e( 'Add to Menu', 'jetonomy' ); ?>"
						name="add-jetonomy-menu-item"
						id="submit-jetonomy-menu-items">
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Get all public spaces for the menu.
	 */
	private function get_spaces(): array {
		global $wpdb;
		$table = table( 'spaces' );
		return $wpdb->get_results(
			"SELECT title, slug FROM {$table} WHERE visibility = 'public' AND status = 'active' ORDER BY title ASC LIMIT 50"
		);
	}

	/**
	 * Get the community base slug.
	 */
	private function get_base_slug(): string {
		$settings = get_option( 'jetonomy_settings', [] );
		return $settings['base_slug'] ?? 'community';
	}
}
