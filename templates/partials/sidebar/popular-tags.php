<?php
/**
 * Sidebar widget: Popular Tags. Rendered by partials/sidebar.php when the owner
 * has it switched on (Settings > Appearance). Fetches its own data, so a
 * switched-off widget costs nothing. Override at
 * {theme}/jetonomy/partials/sidebar/popular-tags.php.
 *
 * @package Jetonomy
 * @since   2.1.0
 *
 * @var object|null $space     Current space, or null outside a space.
 * @var bool        $bn_active Whether BuddyNext card markup is used.
 */

defined( 'ABSPATH' ) || exit;

// Popular tags. Cached 900s under one global key — fully
// shared, ordered by post_count (indexed since 1.9.2), moves only on
// post publish. TTL-only: staleness is invisible on a tag cloud.
$popular_tags = \Jetonomy\Cache::remember(
	'tags:popular:15',
	static function () {
		return \Jetonomy\Models\Tag::list_popular( 15 );
	},
	900
);
// The shared list is ordered by the stored count, which includes topics this
// viewer cannot read. Show each tag's count as the tag page will (one grouped
// query), and drop tags with nothing visible so a name never hints at a
// private topic.
// Guests share one cached answer (the bulk of traffic); members vary by membership.
$jt_tag_ids    = wp_list_pluck( (array) $popular_tags, 'id' );
$jt_tag_counts = is_user_logged_in()
	? \Jetonomy\Models\Post::count_by_tags_visible( $jt_tag_ids )
	: \Jetonomy\Cache::remember( 'tags:popular:15:guest-counts', static fn() => \Jetonomy\Models\Post::count_by_tags_visible( $jt_tag_ids ), 900 );
$popular_tags  = array_values( array_filter( (array) $popular_tags, static fn( $t ) => ! empty( $jt_tag_counts[ (int) $t->id ] ) ) );
?>
<?php if ( ! empty( $popular_tags ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h2 class="jt-card-title"><?php esc_html_e( 'Popular Tags', 'jetonomy' ); ?></h2>
			<?php else : ?>
				<?php esc_html_e( 'Popular Tags', 'jetonomy' ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<div class="jt-tags">
				<?php foreach ( $popular_tags as $tag ) : ?>
					<a href="<?php echo esc_url( \Jetonomy\route_url( 'tag', $tag->slug ) ); ?>" class="jt-tag">
						<?php echo esc_html( $tag->name ); ?>
						<span class="jt-tag-count"><?php echo (int) $jt_tag_counts[ (int) $tag->id ]; ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
<?php endif; ?>
