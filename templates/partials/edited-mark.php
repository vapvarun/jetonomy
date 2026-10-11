<?php
/**
 * "edited 2 hours ago" marker for a topic or reply.
 *
 * Shown to everyone when the content changed more than a minute after it was
 * posted, so readers know a post may differ from what earlier replies saw.
 * The edit history itself stays with staff in wp-admin.
 *
 * @package Jetonomy
 *
 * @var object $item Post or reply row with created_at and edited_at (UTC).
 */

defined( 'ABSPATH' ) || exit;

$jt_edited = strtotime( (string) ( $item->edited_at ?? '' ) . ' UTC' );
$jt_posted = strtotime( (string) ( $item->created_at ?? '' ) . ' UTC' );
if ( ! $jt_edited || ! $jt_posted || $jt_edited - $jt_posted <= MINUTE_IN_SECONDS ) {
	return;
}
?>
<span class="jt-edited">
	<time datetime="<?php echo esc_attr( get_date_from_gmt( (string) $item->edited_at, 'c' ) ); ?>"
		title="<?php echo esc_attr( get_date_from_gmt( (string) $item->edited_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>">
		<?php
		/* translators: %s: human-readable time since the edit, e.g. "2 hours". */
		echo esc_html( sprintf( __( 'edited %s ago', 'jetonomy' ), human_time_diff( $jt_edited, time() ) ) );
		?>
	</time>
</span>
