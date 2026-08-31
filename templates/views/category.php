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
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => sprintf( /* translators: %s: the singular label of the item (the configured noun). */ __( '%s not found.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category' ) ),
			'tone'      => 'warn',
		]
	);
	return;
}


/*
 * Paginate. This page used to render every space in the category with no
 * LIMIT at all, so its cost grew with the community - fine at five spaces,
 * unusable at two thousand. `spg` (space page) keeps the key distinct from
 * the `pg` topic pagination used elsewhere.
 */
$jt_per_page = (int) apply_filters( 'jetonomy_spaces_per_page', 24 );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page number.
$jt_page     = max( 1, (int) ( $_GET['spg'] ?? 1 ) );
$jt_total    = \Jetonomy\Models\Space::count_by_category( (int) $category->id );
$spaces      = \Jetonomy\Models\Space::list_by_category( (int) $category->id, null, $jt_per_page, ( $jt_page - 1 ) * $jt_per_page );
$jt_has_more = ( $jt_page * $jt_per_page ) < $jt_total;
$base        = \Jetonomy\base_url();

$crumbs = [
	[
		'label' => $category->name,
		'url'   => '',
	],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
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

			<?php if ( empty( $spaces ) ) : ?>
				<?php
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					[
						'icon'      => 'empty-search',
						'icon_size' => 48,
						/* translators: 1: plural space label (e.g. spaces, groups); 2: singular category label. */
						'message'   => sprintf( __( 'No %1$s in this %2$s yet.', 'jetonomy' ), \Jetonomy\space_label( true, true ), \Jetonomy\jetonomy_label( 'category', false, true ) ),
					]
				);
				?>
			<?php else : ?>
				<?php
				// Shared with the home grid. This view used to carry its own copy,
				// which had drifted: hardcoded "posts"/"members" instead of _n(), and
				// a hardcoded "Hidden space" instead of the label the site owner
				// configured. Both are fixed by using one renderer.
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
