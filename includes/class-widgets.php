<?php
/**
 * Widgets registration.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Register all Jetonomy widgets.
 */
class Widgets {

	public static function register(): void {
		add_action(
			'widgets_init',
			function () {
				register_widget( Widgets\Recent_Posts_Widget::class );
				register_widget( Widgets\Leaderboard_Widget::class );
				register_widget( Widgets\Active_Spaces_Widget::class );
				register_widget( Widgets\User_Stats_Widget::class );

				// Our own widget area, so a site can add widgets and blocks to the community
				// sidebar on any theme; the theme's sidebar never shows on community pages.
				register_sidebar(
					array(
						'id'            => 'jetonomy-sidebar',
						'name'          => __( 'Community Sidebar', 'jetonomy' ),
						'description'   => __( 'Shown at the bottom of the sidebar on every community page.', 'jetonomy' ),
						'before_widget' => '<section id="%1$s" class="%2$s jt-card jt-mb-md">',
						'after_widget'  => '</section>',
						'before_title'  => '<h2 class="jt-card-title">',
						'after_title'   => '</h2>',
					)
				);
			}
		);
	}
}
