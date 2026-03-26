<?php
namespace Jetonomy\Widgets;

defined( 'ABSPATH' ) || exit;

class User_Stats_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'jetonomy_user_stats',
			__( 'Jetonomy: User Stats', 'jetonomy' ),
			[ 'description' => __( 'Display the logged-in user\'s forum stats.', 'jetonomy' ) ]
		);
	}

	public function widget( $args, $instance ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo do_shortcode( '[jetonomy_user_profile user_id="' . $user_id . '"]' );
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ): void {
		$title = $instance['title'] ?? __( 'My Stats', 'jetonomy' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'jetonomy' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array {
		return [
			'title' => sanitize_text_field( $new_instance['title'] ?? '' ),
		];
	}
}
