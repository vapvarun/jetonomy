<?php
/**
 * Active spaces widget.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Widgets;

defined( 'ABSPATH' ) || exit;

class Active_Spaces_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'jetonomy_active_spaces',
			/* translators: %s: plural space label. */
			sprintf( __( 'Jetonomy: Active %s', 'jetonomy' ), \Jetonomy\space_label( true ) ),
			/* translators: %s: plural space label. */
			array( 'description' => sprintf( __( 'Display the most active forum %s.', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) )
		);
	}

	public function widget( $args, $instance ): void {
		$count = absint( $instance['count'] ?? 5 ) ?: 5;
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo do_shortcode( '[jetonomy_spaces count="' . $count . '"]' );
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ): void {
		/* translators: %s: plural space label. */
		$title = $instance['title'] ?? sprintf( __( 'Active %s', 'jetonomy' ), \Jetonomy\space_label( true ) );
		$count = $instance['count'] ?? 5;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'jetonomy' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php /* translators: %s: plural space label. */ echo esc_html( sprintf( __( 'Number of %s:', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?></label>
			<input class="tiny-text" type="number" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>" value="<?php echo esc_attr( $count ); ?>" min="1" max="20" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array {
		return array(
			'title' => sanitize_text_field( $new_instance['title'] ?? '' ),
			'count' => absint( $new_instance['count'] ?? 5 ),
		);
	}
}
