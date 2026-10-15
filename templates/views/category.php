<?php
/**
 * Category view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$category_slug = $data['slug'] ?? '';
$category      = \Jetonomy\Models\Category::find_by_slug( $category_slug );

if ( ! $category ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		array(
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( '%s not found.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category' ) ),
			'tone'      => 'warn',
		)
	);
	return;
}


/*
 * Paginate: an unbounded render of every space in the category grows with the
 * community - fine at five spaces, unusable at two thousand. `spg` (space page) keeps
 * the key distinct from the `pg` topic pagination used elsewhere.
 */
/**
 * Filters the number of spaces per page on a category archive.
 *
 * @since 1.9.4
 *
 * @param int $per_page Spaces per page. Default 24.
 * @return int Spaces per page.
 */
$jt_per_page = (int) apply_filters( 'jetonomy_spaces_per_page', 24 );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page number.
$jt_page     = max( 1, (int) ( $_GET['spg'] ?? 1 ) );
$jt_total    = \Jetonomy\Models\Space::count_by_category( (int) $category->id );
$spaces      = \Jetonomy\Models\Space::list_by_category( (int) $category->id, null, $jt_per_page, ( $jt_page - 1 ) * $jt_per_page );
$jt_has_more = ( $jt_page * $jt_per_page ) < $jt_total;
$base        = \Jetonomy\base_url();

// Categories nest two levels deep, so the only ancestor is the parent. It is
// fetched visibility-filtered: a hidden parent is left out of the trail
// rather than named to a viewer who cannot open it.
$crumbs    = array();
$jt_parent = (int) $category->parent_id > 0 ? \Jetonomy\Models\Category::find_visible( (int) $category->parent_id ) : null;
if ( $jt_parent ) {
	$crumbs[] = array(
		'label' => $jt_parent->name,
		'url'   => \Jetonomy\route_url( 'category', $jt_parent->slug ),
	);
}
$crumbs[] = array(
	'label' => $category->name,
	'url'   => '',
);

$jt_children = \Jetonomy\Models\Category::list_children( (int) $category->id );
?>
<?php \Jetonomy\Template_Loader::breadcrumb( $crumbs ); ?>

<div class="jt-two-col">
		<main>
			<?php \Jetonomy\Template_Loader::breadcrumb_in_main(); ?>
			<div class="jt-cat-page-row">
				<?php if ( ! empty( $category->icon ) ) : ?>
					<?php jetonomy_render_space_icon( (string) $category->icon, 32, 'jt-cat-page-emoji' ); ?>
				<?php endif; ?>
				<div>
					<h1 class="jt-page-title"><?php echo esc_html( $category->name ); ?></h1>
					<?php if ( ! empty( $category->description ) ) : ?>
						<p class="jt-cat-page-desc"><?php echo esc_html( $category->description ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php
			\Jetonomy\Template_Loader::partial(
				'category-chips',
				array(
					'children'      => $jt_children,
					'spaces_by_cat' => $jt_children ? \Jetonomy\Models\Space::visible_by_category() : array(),
				)
			);
			?>

			<?php if ( empty( $spaces ) && empty( $jt_children ) ) : ?>
				<?php
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					array(
						'icon'      => 'empty-search',
						'icon_size' => 48,
						/* translators: 1: plural space label; 2: singular category label. */
						'message'   => sprintf( __( 'No %1$s in this %2$s yet.', 'jetonomy' ), \Jetonomy\space_label( true, true ), \Jetonomy\jetonomy_label( 'category', false, true ) ),
					)
				);
				?>
			<?php elseif ( ! empty( $spaces ) ) : ?>
				<?php
				// Shared with the home grid so both use one renderer: _n() for "posts"/"members"
				// and the label the site owner configured for a hidden space.
				jetonomy_render_space_grid( $spaces, $base );
				?>
				<?php
				\Jetonomy\Template_Loader::partial(
					'pagination',
					array(
						'has_more'  => $jt_has_more,
						'param_key' => 'spg',
						'target'    => '.jt-space-grid',
					)
				);
				?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
