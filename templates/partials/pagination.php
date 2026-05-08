<?php
/**
 * Pagination partial — "Load More" button.
 *
 * The button is a real anchor with a `?{param_key}={n}` URL, so visitors with
 * JS disabled get classic full-page pagination. With JS, pagination-frontend.js
 * intercepts the click, fetches the next page, and appends the new items into
 * the matching {target} container.
 *
 * @package Jetonomy
 *
 * @var bool   $has_more  Whether there are more items to load.
 * @var string $param_key Optional. Query-string key the server reads for the
 *                        page number (e.g. 'pg' for posts, 'rpg' for replies).
 *                        Defaults to 'pg'.
 * @var string $target    Optional. CSS selector for the list container that
 *                        new items should be appended to. Defaults to
 *                        '.jt-topics'.
 */

defined( 'ABSPATH' ) || exit;
if ( empty( $has_more ) ) {
	return;
}

$param_key = isset( $param_key ) && '' !== (string) $param_key ? (string) $param_key : 'pg';
$target    = isset( $target ) && '' !== (string) $target ? (string) $target : '.jt-topics';

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$current_page = max( 1, (int) ( $_GET[ $param_key ] ?? 1 ) );
$next_page    = $current_page + 1;
$base_url     = remove_query_arg( $param_key );
$next_url     = add_query_arg( $param_key, $next_page, $base_url );
?>
<div class="jt-pagination" data-jt-target="<?php echo esc_attr( $target ); ?>">
	<a href="<?php echo esc_url( $next_url ); ?>" class="jt-btn jt-btn-ghost jt-pagination-btn jt-load-more-trigger">
		<?php esc_html_e( 'Load More', 'jetonomy' ); ?>
	</a>
</div>
