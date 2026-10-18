<?php
/**
 * Schedule date + time fields, shared by the composer's "Schedule..." panel
 * and the "Change time" form on a scheduled topic.
 *
 * Two selects for the time instead of <input type="time">: Firefox Desktop has
 * no popup for a time input, which would block scheduling there. Mobile
 * browsers render the selects as native scroll wheels. view.js
 * (jtScheduleValue) joins the three values into a site-timezone wall-clock
 * time; the server converts it to UTC.
 *
 * @package Jetonomy
 *
 * @var string $utc_value Optional. Current schedule, UTC 'Y-m-d H:i:s', to prefill in the site timezone.
 * @var string $id_prefix Optional. Prefix for the field ids. Default 'jt-post-published'.
 */

defined( 'ABSPATH' ) || exit;

$id_prefix = isset( $id_prefix ) && '' !== (string) $id_prefix ? (string) $id_prefix : 'jt-post-published';
$jt_local  = ! empty( $utc_value ) ? get_date_from_gmt( (string) $utc_value, 'Y-m-d H:i' ) : '';
$jt_date   = '' !== $jt_local ? substr( $jt_local, 0, 10 ) : '';
$jt_hour   = '' !== $jt_local ? substr( $jt_local, 11, 2 ) : '';
$jt_minute = '' !== $jt_local ? substr( $jt_local, 14, 2 ) : '';
$jt_mins   = range( 0, 55, 5 );
if ( '' !== $jt_minute && ! in_array( (int) $jt_minute, $jt_mins, true ) ) {
	// A schedule set through the API can fall between the 5-minute steps; keep it.
	$jt_mins[] = (int) $jt_minute;
	sort( $jt_mins );
}
?>
<div class="jt-datetime-split">
	<input type="date" id="<?php echo esc_attr( $id_prefix . '-date' ); ?>" name="published_date" class="jt-input jt-input--date" value="<?php echo esc_attr( $jt_date ); ?>" aria-label="<?php esc_attr_e( 'Date', 'jetonomy' ); ?>">
	<div class="jt-time-split" aria-label="<?php esc_attr_e( 'Time', 'jetonomy' ); ?>">
		<select id="<?php echo esc_attr( $id_prefix . '-hour' ); ?>" name="published_hour" class="jt-input jt-input--time-part" aria-label="<?php esc_attr_e( 'Hour', 'jetonomy' ); ?>">
			<option value=""><?php esc_html_e( 'HH', 'jetonomy' ); ?></option>
			<?php for ( $_h = 0; $_h < 24; $_h++ ) : ?>
				<?php $jt_h = str_pad( (string) $_h, 2, '0', STR_PAD_LEFT ); ?>
				<option value="<?php echo esc_attr( $jt_h ); ?>"<?php selected( $jt_hour, $jt_h ); ?>><?php echo esc_html( $jt_h ); ?></option>
			<?php endfor; ?>
		</select>
		<span class="jt-time-split-sep" aria-hidden="true">:</span>
		<select id="<?php echo esc_attr( $id_prefix . '-minute' ); ?>" name="published_minute" class="jt-input jt-input--time-part" aria-label="<?php esc_attr_e( 'Minute', 'jetonomy' ); ?>">
			<option value=""><?php esc_html_e( 'MM', 'jetonomy' ); ?></option>
			<?php foreach ( $jt_mins as $_m ) : ?>
				<?php $jt_m = str_pad( (string) $_m, 2, '0', STR_PAD_LEFT ); ?>
				<option value="<?php echo esc_attr( $jt_m ); ?>"<?php selected( $jt_minute, $jt_m ); ?>><?php echo esc_html( $jt_m ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</div>
