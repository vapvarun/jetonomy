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
 *   tone        — 'info' (default) | 'warn' | 'forbidden'.
 *                 Forbidden renders with a lock icon by default and
 *                 darker text. Warn is for "no results match your filter"
 *                 / "nothing returned" — same visuals as info but
 *                 secondary text colour.
 *   variant     — 'default' | 'compact'. Compact drops the icon and
 *                 description, suitable for inline tab content.
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
$jt_es_tone        = isset( $tone ) && in_array( $tone, [ 'info', 'warn', 'forbidden' ], true )
	? $tone
	: 'info';
$jt_es_variant     = isset( $variant ) && 'compact' === $variant ? 'compact' : 'default';

if ( 'forbidden' === $jt_es_tone && 'help-circle' === $jt_es_icon ) {
	$jt_es_icon      = 'lock';
	$jt_es_icon_size = 48;
}

$jt_es_wrapper = 'compact' === $jt_es_variant ? 'jt-empty-compact' : 'jt-empty';
?>
<div class="<?php echo esc_attr( $jt_es_wrapper . ' jt-empty-tone-' . $jt_es_tone ); ?>">
	<?php if ( 'default' === $jt_es_variant && '' !== $jt_es_icon ) : ?>
		<div class="jt-empty-icon"><?php jetonomy_echo_icon( $jt_es_icon, $jt_es_icon_size ); ?></div>
	<?php endif; ?>
	<?php if ( '' !== $jt_es_message ) : ?>
		<div class="jt-empty-text"><?php echo esc_html( $jt_es_message ); ?></div>
	<?php endif; ?>
	<?php if ( 'default' === $jt_es_variant && '' !== $jt_es_description ) : ?>
		<p class="jt-empty-desc"><?php echo esc_html( $jt_es_description ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $jt_es_cta_label && '' !== $jt_es_cta_url ) : ?>
		<p class="jt-empty-cta">
			<a href="<?php echo esc_url( $jt_es_cta_url ); ?>" class="jt-btn jt-btn-primary">
				<?php echo esc_html( $jt_es_cta_label ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
