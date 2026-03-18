<?php
defined( 'ABSPATH' ) || exit;
if ( empty( $has_more ) ) {
	return;
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$current_page = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$next_page    = $current_page + 1;
$base_url     = remove_query_arg( 'pg' );
$next_url     = add_query_arg( 'pg', $next_page, $base_url );
?>
<div class="jt-pagination">
	<a href="<?php echo esc_url( $next_url ); ?>" class="jt-btn jt-btn-ghost jt-pagination-btn">
		<?php esc_html_e( 'Load More', 'jetonomy' ); ?>
	</a>
</div>
