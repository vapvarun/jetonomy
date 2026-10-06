<?php
/**
 * Breadcrumb partial.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
if ( empty( $crumbs ) ) {
	return;
}

ob_start();
?>
<nav class="jt-crumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'jetonomy' ); ?>">
	<a href="<?php echo esc_url( \Jetonomy\base_url() . '/' ); ?>"><?php esc_html_e( 'Home', 'jetonomy' ); ?></a>
	<?php foreach ( $crumbs as $crumb ) : ?>
		<span>/</span>
		<?php if ( ! empty( $crumb['url'] ) ) : ?>
			<a href="<?php echo esc_url( $crumb['url'] ); ?>"><?php echo esc_html( $crumb['label'] ); ?></a>
		<?php else : ?>
			<span><?php echo esc_html( $crumb['label'] ); ?></span>
		<?php endif; ?>
	<?php endforeach; ?>
</nav>
<?php
/**
 * Filter the rendered breadcrumb trail.
 *
 * Eighteen views render the trail through this one partial, so a site that
 * wants to restyle, replace or remove it has a single hook instead of
 * eighteen template overrides (Basecamp 10272509253). Return '' to suppress
 * it, or rebuild it from $crumbs.
 *
 * Placement is a separate filter, jetonomy_breadcrumb_placement: views print
 * the trail through Template_Loader::breadcrumb() before <main> and call
 * Template_Loader::breadcrumb_in_main() right after opening it, so a site can
 * move the trail inside the main landmark without any template override.
 *
 * Output is already escaped; a filter returning markup owns its own escaping.
 *
 * @param string               $html   Rendered breadcrumb markup.
 * @param array<int,array<string,mixed>> $crumbs Crumb list, each with label and optional url.
 */
$jt_crumb_html = apply_filters( 'jetonomy_breadcrumb_html', (string) ob_get_clean(), $crumbs );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped above; a filter returning markup owns its escaping.
echo $jt_crumb_html;
