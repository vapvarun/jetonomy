<?php
/**
 * Avatar partial.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
$size     = $size ?? 30;
$class    = $class ?? 'jt-avatar-sm';
$user     = is_numeric( $user_id ) ? get_userdata( $user_id ) : null;
$initials = $user ? strtoupper( mb_substr( $user->display_name, 0, 2 ) ) : '??';
// Resolve through Avatar::display_url() (same path as get_user_link()) so a
// member with no real avatar falls back to initials instead of Gravatar's
// generic mystery-person. Returns '' => the initials branch below fires.
$avatar_url = $user ? \Jetonomy\Avatar::display_url( (int) $user_id, $size * 2 ) : '';
$name       = $user ? $user->display_name : __( 'Anonymous', 'jetonomy' );
?>
<?php if ( $avatar_url ) : ?>
	<img src="<?php echo esc_url( $avatar_url ); ?>"
		alt="<?php echo esc_attr( $name ); ?>"
		class="jt-avatar <?php echo esc_attr( $class ); ?>"
		width="<?php echo (int) $size; ?>" height="<?php echo (int) $size; ?>"
		loading="lazy">
<?php else : ?>
	<span class="jt-avatar <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $initials ); ?></span>
<?php endif; ?>
