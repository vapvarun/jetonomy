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
$unique_id    = 'jt-pagination-' . wp_unique_id();
?>
<div class="jt-pagination" id="<?php echo esc_attr( $unique_id ); ?>">
	<a href="<?php echo esc_url( $next_url ); ?>" class="jt-btn jt-btn-ghost jt-pagination-btn jt-load-more-trigger">
		<?php esc_html_e( 'Load More', 'jetonomy' ); ?>
	</a>
</div>
<script>
(function() {
	var container = document.getElementById(<?php echo wp_json_encode( $unique_id ); ?>);
	if (!container || !('IntersectionObserver' in window)) return;
	var btn = container.querySelector('.jt-load-more-trigger');
	if (!btn) return;
	var loading = false;
	var observer = new IntersectionObserver(function(entries) {
		if (!entries[0].isIntersecting || loading) return;
		loading = true;
		btn.textContent = <?php echo wp_json_encode( __( 'Loading...', 'jetonomy' ) ); ?>;
		btn.style.pointerEvents = 'none';
		fetch(btn.href).then(function(r) { return r.text(); }).then(function(html) {
			var parser = new DOMParser();
			var doc = parser.parseFromString(html, 'text/html');
			/* Find the topic list in the fetched page and append its rows */
			var newTopics = doc.querySelector('.jt-topics');
			var currentTopics = container.parentElement.querySelector('.jt-topics');
			if (newTopics && currentTopics) {
				var children = Array.from(newTopics.children);
				children.forEach(function(child) { currentTopics.appendChild(child); });
			}
			/* Replace pagination with new one (or remove if no more) */
			var newPag = doc.querySelector('.jt-pagination');
			if (newPag) {
				container.replaceWith(newPag);
			} else {
				container.remove();
			}
			observer.disconnect();
		}).catch(function() {
			loading = false;
			btn.textContent = <?php echo wp_json_encode( __( 'Load More', 'jetonomy' ) ); ?>;
			btn.style.pointerEvents = '';
		});
	}, { rootMargin: '200px' });
	observer.observe(container);
})();
</script>
