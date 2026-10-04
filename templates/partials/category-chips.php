<?php
/**
 * Sub-category chips under a category.
 *
 * Args (extracted to locals by Template_Loader::partial()):
 * - $children      object[] Visible sub-categories, already ordered.
 * - $spaces_by_cat array    Space::visible_by_category() map, for the counts.
 *
 * A space filed in a sub-category used to be invisible on the directory
 * (Basecamp 10355160441). Each chip links to the sub-category page and counts
 * only the spaces this viewer can see, so a hidden space is never disclosed by
 * a number.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$jt_children = $children ?? [];
if ( empty( $jt_children ) ) {
	return;
}
$jt_spaces_by_cat = $spaces_by_cat ?? [];
?>
<?php /* translators: %s: plural category label the site owner configured (e.g. categories, channels). */ ?>
<ul class="jt-cat-chips" aria-label="<?php echo esc_attr( sprintf( __( 'Sub-%s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', true, true ) ) ); ?>">
	<?php foreach ( $jt_children as $jt_child ) : ?>
		<?php $jt_count = count( $jt_spaces_by_cat[ (int) $jt_child->id ] ?? [] ); ?>
		<li>
			<a class="jt-cat-chip" href="<?php echo esc_url( \Jetonomy\route_url( 'category', $jt_child->slug ) ); ?>">
				<?php if ( ! empty( $jt_child->icon ) ) : ?>
					<?php jetonomy_render_space_icon( (string) $jt_child->icon, 16, 'jt-cat-chip-icon' ); ?>
				<?php endif; ?>
				<span class="jt-cat-chip-name"><?php echo esc_html( $jt_child->name ); ?></span>
				<?php if ( $jt_count > 0 ) : ?>
					<?php /* translators: 1: number of spaces in the sub-category, 2: singular or plural space label. */ ?>
					<span class="jt-cat-chip-count" aria-label="<?php echo esc_attr( sprintf( __( '%1$s %2$s', 'jetonomy' ), number_format_i18n( $jt_count ), \Jetonomy\space_label( 1 !== $jt_count, true ) ) ); ?>"><?php echo esc_html( number_format_i18n( $jt_count ) ); ?></span>
				<?php endif; ?>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
