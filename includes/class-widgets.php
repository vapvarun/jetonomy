<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Register all Jetonomy widgets.
 */
class Widgets {

	public static function register(): void {
		add_action( 'widgets_init', function () {
			register_widget( Widgets\Recent_Posts_Widget::class );
			register_widget( Widgets\Leaderboard_Widget::class );
			register_widget( Widgets\Active_Spaces_Widget::class );
			register_widget( Widgets\User_Stats_Widget::class );
		} );
	}
}
