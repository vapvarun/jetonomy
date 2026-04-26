<?php
/**
 * Empty-state partial for a moderation queue.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$message = isset( $message ) ? (string) $message : __( 'No pending flags. The community is clean!', 'jetonomy' );
?>
<div class="jt-empty">
	<div class="jt-empty-icon"><?php jetonomy_echo_icon( 'check-circle', 48 ); ?></div>
	<div class="jt-empty-text"><?php echo esc_html( $message ); ?></div>
</div>
