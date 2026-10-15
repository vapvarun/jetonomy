<?php
/**
 * Leaderboard widget.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Widgets;

defined( 'ABSPATH' ) || exit;

class Leaderboard_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'jetonomy_leaderboard',
			__( 'Jetonomy: Leaderboard', 'jetonomy' ),
			/* translators: %s: plural member label. */
			array( 'description' => sprintf( __( 'Display top community %s by reputation.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) )
		);
	}

	public function widget( $args, $instance ): void {
		$count  = absint( $instance['count'] ?? 5 ) ?: 5;
		$period = in_array( $instance['period'] ?? 'all', array( 'all', 'month', 'week' ), true ) ? ( $instance['period'] ?? 'all' ) : 'all';
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo do_shortcode( '[jetonomy_leaderboard count="' . $count . '" period="' . $period . '"]' );
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ): void {
		/* translators: %s: plural member label. */
		$title  = $instance['title'] ?? sprintf( __( 'Top %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ) );
		$count  = $instance['count'] ?? 5;
		$period = $instance['period'] ?? 'all';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'jetonomy' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php /* translators: %s: plural member label. */ echo esc_html( sprintf( __( 'Number of %s:', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></label>
			<input class="tiny-text" type="number" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>" value="<?php echo esc_attr( $count ); ?>" min="1" max="20" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'period' ) ); ?>"><?php esc_html_e( 'Time period:', 'jetonomy' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'period' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'period' ) ); ?>">
				<option value="all" <?php selected( $period, 'all' ); ?>><?php esc_html_e( 'All time', 'jetonomy' ); ?></option>
				<option value="month" <?php selected( $period, 'month' ); ?>><?php esc_html_e( 'This month', 'jetonomy' ); ?></option>
				<option value="week" <?php selected( $period, 'week' ); ?>><?php esc_html_e( 'This week', 'jetonomy' ); ?></option>
			</select>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array {
		return array(
			'title'  => sanitize_text_field( $new_instance['title'] ?? '' ),
			'count'  => absint( $new_instance['count'] ?? 5 ),
			'period' => in_array( $new_instance['period'] ?? 'all', array( 'all', 'month', 'week' ), true ) ? $new_instance['period'] : 'all',
		);
	}
}
