<?php
/**
 * Empty / not-found / forbidden state — single source of truth.
 *
 * Replaces the ad-hoc `<div class="jt-empty">` markup that lived inline
 * across 18+ templates. Every empty list, every 404 / 403 page, every
 * "no items yet" CTA renders through this one partial so spacing,
 * iconography, and copy treatment stay uniform across every surface.
 *
 * Args (pass via Template_Loader::partial):
 *
 *   icon        — Lucide icon name from assets/icons/. Default 'help-circle'.
 *                 Pass empty string to suppress the icon entirely (very rare).
 *   icon_size   — Icon pixel size (default 80 for full-page empty,
 *                 48 for small / 404 / 403, ignored for compact variant).
 *   message     — Primary one-line message.
 *   description — Optional secondary line below the message.
 *   cta_label   — Optional primary-button label.
 *   cta_url     — Optional primary-button URL. Both required to render.
 *   secondary_label / secondary_url — Optional second (ghost) button beside the CTA.
 *   slot        — Optional callable rendered inside the card, after the buttons
 *                 (e.g. a request form), so it shares the card's spacing.
 *   tone        — 'info' (default) | 'warn' | 'forbidden'.
 *                 Forbidden renders with a lock icon by default and
 *                 darker text. Warn is for "no results match your filter"
 *                 / "nothing returned" — same visuals as info but
 *                 secondary text colour.
 *   variant     — 'default' | 'compact'. Compact drops the icon and
 *                 description, suitable for inline tab content.
 *   crumb       — Passing it makes this state the page (a gate): the message
 *                 becomes the page's h1, the crumb leads back, and a "Browse
 *                 spaces" button is always offered. Every 404 is a page state
 *                 automatically (crumb "Not found"). Pass '' to show no crumb.
 *   hint        — Optional "what you can do next" line under the description.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$jt_es_icon        = isset( $icon ) ? (string) $icon : 'help-circle';
$jt_es_icon_size   = isset( $icon_size ) ? (int) $icon_size : 80;
$jt_es_message     = isset( $message ) ? (string) $message : '';
$jt_es_description = isset( $description ) ? (string) $description : '';
$jt_es_cta_label   = isset( $cta_label ) ? (string) $cta_label : '';
$jt_es_cta_url     = isset( $cta_url ) ? (string) $cta_url : '';
$jt_es_tone        = isset( $tone ) && in_array( $tone, array( 'info', 'warn', 'forbidden' ), true )
	? $tone
	: 'info';
$jt_es_variant     = isset( $variant ) && 'compact' === $variant ? 'compact' : 'default';
$jt_es_sec_label   = isset( $secondary_label ) ? (string) $secondary_label : '';
$jt_es_sec_url     = isset( $secondary_url ) ? (string) $secondary_url : '';

$jt_es_is_404     = 404 === http_response_code();
$jt_es_page       = isset( $crumb ) || $jt_es_is_404;
$jt_es_hint       = isset( $hint ) ? (string) $hint : '';
$jt_es_crumb      = isset( $crumb ) ? (string) $crumb : ( $jt_es_is_404 ? __( 'Not found', 'jetonomy' ) : '' );
$jt_es_browse_url = \Jetonomy\base_url() . '/';
/* translators: %s: plural space label the site owner configured (e.g. spaces). */
$jt_es_browse_label = sprintf( __( 'Browse %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) );

// A gate or not-found page is never a dead end: it always offers the space
// directory, as the main button when it has none of its own.
if ( $jt_es_page && '' === $jt_es_cta_url ) {
	$jt_es_cta_label = $jt_es_browse_label;
	$jt_es_cta_url   = $jt_es_browse_url;
}
$jt_es_browse_extra = $jt_es_page && untrailingslashit( $jt_es_cta_url ) !== untrailingslashit( $jt_es_browse_url );

if ( 'forbidden' === $jt_es_tone && 'help-circle' === $jt_es_icon ) {
	$jt_es_icon      = 'lock';
	$jt_es_icon_size = 48;
}

$jt_es_wrapper = 'compact' === $jt_es_variant ? 'jt-empty-compact' : 'jt-empty';
?>
<?php
if ( $jt_es_page && '' !== $jt_es_crumb ) {
	\Jetonomy\Template_Loader::breadcrumb( array( array( 'label' => $jt_es_crumb ) ) );
}
?>
<div class="<?php echo esc_attr( $jt_es_wrapper . ' jt-empty-tone-' . $jt_es_tone . ( $jt_es_page ? ' jt-empty--page' : '' ) ); ?>">
	<?php if ( 'default' === $jt_es_variant && '' !== $jt_es_icon ) : ?>
		<div class="jt-empty-icon"><?php jetonomy_echo_icon( $jt_es_icon, $jt_es_icon_size ); ?></div>
	<?php endif; ?>
	<?php if ( '' !== $jt_es_message ) : ?>
		<?php if ( $jt_es_page ) : ?>
			<h1 class="jt-empty-text"><?php echo esc_html( $jt_es_message ); ?></h1>
		<?php else : ?>
			<div class="jt-empty-text"><?php echo esc_html( $jt_es_message ); ?></div>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( 'default' === $jt_es_variant && '' !== $jt_es_description ) : ?>
		<p class="jt-empty-desc"><?php echo esc_html( $jt_es_description ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $jt_es_hint ) : ?>
		<p class="jt-empty-desc jt-empty-hint"><?php echo esc_html( $jt_es_hint ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $jt_es_cta_label && '' !== $jt_es_cta_url ) : ?>
		<p class="jt-empty-cta">
			<a href="<?php echo esc_url( $jt_es_cta_url ); ?>" class="jt-btn jt-btn-fill">
				<?php echo esc_html( $jt_es_cta_label ); ?>
			</a>
			<?php if ( '' !== $jt_es_sec_label && '' !== $jt_es_sec_url ) : ?>
				<a href="<?php echo esc_url( $jt_es_sec_url ); ?>" class="jt-btn jt-btn-ghost">
					<?php echo esc_html( $jt_es_sec_label ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $jt_es_browse_extra ) : ?>
				<a href="<?php echo esc_url( $jt_es_browse_url ); ?>" class="jt-btn jt-btn-ghost"><?php echo esc_html( $jt_es_browse_label ); ?></a>
			<?php endif; ?>
		</p>
	<?php elseif ( $jt_es_browse_extra ) : ?>
		<p class="jt-empty-cta"><a href="<?php echo esc_url( $jt_es_browse_url ); ?>" class="jt-btn jt-btn-ghost"><?php echo esc_html( $jt_es_browse_label ); ?></a></p>
	<?php endif; ?>
	<?php if ( $jt_es_is_404 ) : ?>
		<form class="jt-empty-search" role="search" method="get" action="<?php echo esc_url( \Jetonomy\route_url( 'search' ) ); ?>">
			<label class="screen-reader-text" for="jt-empty-search-q"><?php esc_html_e( 'Search the community', 'jetonomy' ); ?></label>
			<input type="search" id="jt-empty-search-q" name="q" class="jt-input" dir="auto" placeholder="<?php esc_attr_e( 'Search the community', 'jetonomy' ); ?>">
			<button type="submit" class="jt-btn jt-btn-fill"><?php esc_html_e( 'Search', 'jetonomy' ); ?></button>
		</form>
	<?php endif; ?>
	<?php
	if ( isset( $slot ) && is_callable( $slot ) ) {
		$slot();
	}
	?>
</div>
