<?php
/**
 * Numbered prev / next pagination nav.
 *
 * Distinct from partials/pagination.php, which renders a "Load More" button
 * for infinite-append lists. This one is for panels a moderator or admin
 * PAGES through, where knowing "page 3 of 12" and being able to go back
 * matters more than appending.
 *
 * Extracted in 1.9.4: the same twenty lines of markup were already inlined
 * twice in views/moderation.php, and the approvals panels needed two more
 * copies. Four hand-maintained copies of one nav is how the accessible name
 * and the rel=prev/next hints drift apart.
 *
 * @package Jetonomy
 *
 * @var int    $paged Current page, 1-indexed and already clamped by the caller.
 * @var int    $pages Total pages. Renders nothing when 1 or less.
 * @var string $base  Base URL; 'paged' is added to it.
 * @var string $label Accessible name for the nav landmark.
 */

defined( 'ABSPATH' ) || exit;

$jt_pages = isset( $pages ) ? (int) $pages : 1;
$jt_paged = isset( $paged ) ? (int) $paged : 1;
$jt_base  = isset( $base ) ? (string) $base : '';

if ( $jt_pages <= 1 || '' === $jt_base ) {
	return;
}

$jt_label = isset( $label ) && '' !== (string) $label
	? (string) $label
	: __( 'Pagination', 'jetonomy' );
?>
<nav class="jt-pagination" aria-label="<?php echo esc_attr( $jt_label ); ?>">
	<?php if ( $jt_paged > 1 ) : ?>
		<a class="jt-pagination-link" href="<?php echo esc_url( add_query_arg( 'paged', $jt_paged - 1, $jt_base ) ); ?>" rel="prev">
			<?php jetonomy_echo_icon( 'chevron-left', 14 ); ?>
			<?php esc_html_e( 'Previous', 'jetonomy' ); ?>
		</a>
	<?php endif; ?>
	<span class="jt-pagination-status">
		<?php
		/* translators: 1: current page, 2: total pages */
		echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'jetonomy' ), $jt_paged, $jt_pages ) );
		?>
	</span>
	<?php if ( $jt_paged < $jt_pages ) : ?>
		<a class="jt-pagination-link" href="<?php echo esc_url( add_query_arg( 'paged', $jt_paged + 1, $jt_base ) ); ?>" rel="next">
			<?php esc_html_e( 'Next', 'jetonomy' ); ?>
			<?php jetonomy_echo_icon( 'chevron-right', 14 ); ?>
		</a>
	<?php endif; ?>
</nav>
