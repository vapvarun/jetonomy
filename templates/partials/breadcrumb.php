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
 * Ceiling worth knowing: every view calls this partial immediately BEFORE it
 * opens <main>, and no view exposes a hook inside <main>. So suppressing the
 * trail here and re-emitting it inside the main region still needs a template
 * override for the specific view - this filter removes the need to override
 * all eighteen, not the need to override any. Moving placement itself under
 * filter control means moving the render out of the views and into
 * Template_Loader, which is a real refactor rather than a hook.
 *
 * Output is already escaped; a filter returning markup owns its own escaping.
 *
 * @param string               $html   Rendered breadcrumb markup.
 * @param array<int,array<string,mixed>> $crumbs Crumb list, each with label and optional url.
 */
$jt_crumb_html = apply_filters( 'jetonomy_breadcrumb_html', (string) ob_get_clean(), $crumbs );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped above; a filter returning markup owns its escaping.
echo $jt_crumb_html;
