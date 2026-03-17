<?php
defined( 'ABSPATH' ) || exit;
if ( empty( $crumbs ) ) {
	return;
}
?>
<nav class="jt-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'jetonomy' ); ?>">
	<a href="<?php echo esc_url( home_url( '/community/' ) ); ?>"><?php esc_html_e( 'Home', 'jetonomy' ); ?></a>
	<?php foreach ( $crumbs as $crumb ) : ?>
		<span>/</span>
		<?php if ( ! empty( $crumb['url'] ) ) : ?>
			<a href="<?php echo esc_url( $crumb['url'] ); ?>"><?php echo esc_html( $crumb['label'] ); ?></a>
		<?php else : ?>
			<span><?php echo esc_html( $crumb['label'] ); ?></span>
		<?php endif; ?>
	<?php endforeach; ?>
</nav>
