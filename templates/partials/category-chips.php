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
<?php
// Each chip keeps its link to the sub-category page; the chevron beside it
// shows that sub-category's spaces right here (Basecamp 10375135686). Panels
// are rendered from the same visible-space map as the counts, so no extra
// query and no space the viewer cannot see. One panel open at a time keeps
// the chip row from reflowing under the pointer.
?>
<div class="jt-cat-subs" data-wp-interactive="jetonomy" data-wp-context='{"openSub":0}'>
	<?php /* translators: %s: a plural label the site owner configured (e.g. spaces, categories). */ ?>
	<ul class="jt-cat-chips" aria-label="<?php echo esc_attr( sprintf( __( 'Sub-%s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', true, true ) ) ); ?>">
		<?php foreach ( $jt_children as $jt_child ) : ?>
			<?php $jt_count = count( $jt_spaces_by_cat[ (int) $jt_child->id ] ?? [] ); ?>
			<li class="jt-cat-chip-group" data-wp-context='<?php echo wp_json_encode( array( 'subId' => (int) $jt_child->id ) ); ?>'>
				<a class="jt-cat-chip" href="<?php echo esc_url( \Jetonomy\route_url( 'category', $jt_child->slug ) ); ?>">
					<?php if ( ! empty( $jt_child->icon ) ) : ?>
						<?php jetonomy_render_space_icon( (string) $jt_child->icon, 16, 'jt-cat-chip-icon' ); ?>
					<?php endif; ?>
					<span class="jt-cat-chip-name"><?php echo esc_html( $jt_child->name ); ?></span>
					<?php if ( $jt_count > 0 ) : ?>
						<span class="jt-cat-chip-count" aria-hidden="true"><?php echo esc_html( number_format_i18n( $jt_count ) ); ?></span><span class="jt-sr-only"><?php echo esc_html( \Jetonomy\count_label( $jt_count, 'space' ) ); ?></span>
					<?php endif; ?>
				</a>
				<button type="button" class="jt-cat-chip-toggle" aria-expanded="false" aria-controls="jt-cat-sub-<?php echo (int) $jt_child->id; ?>"
					data-wp-bind--aria-expanded="state.isSubOpen"
					data-wp-on--click="actions.toggleSub">
					<?php jetonomy_echo_icon( 'chevron-down', 16 ); ?>
					<?php /* translators: 1: plural space label (e.g. spaces), 2: sub-category name. */ ?>
					<span class="jt-sr-only"><?php echo esc_html( sprintf( __( 'Show %1$s in %2$s', 'jetonomy' ), \Jetonomy\space_label( true, true ), $jt_child->name ) ); ?></span>
				</button>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php foreach ( $jt_children as $jt_child ) : ?>
		<div class="jt-cat-sub-panel" id="jt-cat-sub-<?php echo (int) $jt_child->id; ?>" hidden
			data-wp-context='<?php echo wp_json_encode( array( 'subId' => (int) $jt_child->id ) ); ?>'
			data-wp-bind--hidden="!state.isSubOpen">
			<h3 class="jt-cat-sub-title"><?php echo esc_html( $jt_child->name ); ?></h3>
			<?php jetonomy_render_space_grid( $jt_spaces_by_cat[ (int) $jt_child->id ] ?? [], \Jetonomy\base_url() ); ?>
		</div>
	<?php endforeach; ?>
</div>
