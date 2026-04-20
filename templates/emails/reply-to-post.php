<?php
/**
 * Jetonomy notification email — reply_to_post.
 *
 * Per-type override example. Loaded by Notifier::render_email_template()
 * when the notification type is `reply_to_post`, in preference to base.php.
 *
 * Override in a theme: copy to `yourtheme/jetonomy/emails/reply-to-post.php`.
 *
 * Context matches base.php (see that file's docblock). Additionally, this
 * type guarantees the following keys are non-empty when the notification
 * source has them:
 *   • $ctx['post_title']         — the post the reply was posted to
 *   • $ctx['actor_display_name'] — who replied
 *   • $ctx['reply_excerpt']      — first ~30 words of the reply, stripped
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$header_html = '' !== $ctx['logo_url']
	? '<a href="' . $ctx['home_url'] . '" style="text-decoration:none;"><img src="' . $ctx['logo_url'] . '" alt="' . $ctx['site_name'] . '" style="max-height:40px;max-width:200px;height:auto;width:auto;" /></a>'
	: '<a href="' . $ctx['home_url'] . '" style="text-decoration:none;color:#111827;font-size:18px;font-weight:700;letter-spacing:-0.02em;">' . $ctx['site_name'] . '</a>';

$footer_links = '<a href="' . esc_url( $ctx['notif_url'] ) . '" style="color:#6B7280;text-decoration:underline;">' . esc_html__( 'Notification preferences', 'jetonomy' ) . '</a>';
if ( '' !== $ctx['unsub_url'] ) {
	$footer_links .= ' &nbsp;&middot;&nbsp; <a href="' . $ctx['unsub_url'] . '" style="color:#6B7280;text-decoration:underline;">' . esc_html__( 'Unsubscribe', 'jetonomy' ) . '</a>';
}

$footer_custom = '' !== $ctx['footer_text']
	? '<p style="margin:0 0 6px;font-size:11px;color:#9CA3AF;">' . esc_html( $ctx['footer_text'] ) . '</p>'
	: '';
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#F3F4F6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F3F4F6;">
<tr><td align="center" style="padding:32px 16px;">

<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">

<tr><td style="height:4px;background-color:<?php echo esc_attr( $ctx['accent'] ); ?>;border-radius:8px 8px 0 0;font-size:0;line-height:0;">&nbsp;</td></tr>

<tr><td style="background-color:#FFFFFF;padding:32px 32px 24px;border-left:1px solid #E5E7EB;border-right:1px solid #E5E7EB;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td style="padding-bottom:24px;"><?php echo $header_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — components escaped above. ?></td>
<td align="right" style="padding-bottom:24px;">
	<span style="display:inline-block;padding:3px 10px;background-color:<?php echo esc_attr( $ctx['accent'] ); ?>1A;color:<?php echo esc_attr( $ctx['accent'] ); ?>;font-size:11px;font-weight:600;border-radius:12px;letter-spacing:0.04em;text-transform:uppercase;"><?php echo esc_html( $ctx['type_label'] ); ?></span>
</td>
</tr>
</table>

<div style="border-top:1px solid #E5E7EB;margin-bottom:24px;"></div>

<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#374151;"><?php echo esc_html( $ctx['message'] ); ?></p>

<?php if ( '' !== $ctx['post_title'] ) : ?>
	<p style="margin:0 0 6px;font-size:12px;letter-spacing:0.04em;text-transform:uppercase;color:#9CA3AF;"><?php esc_html_e( 'On topic', 'jetonomy' ); ?></p>
	<p style="margin:0 0 16px;font-size:16px;font-weight:600;color:#111827;line-height:1.4;"><?php echo esc_html( $ctx['post_title'] ); ?></p>
<?php endif; ?>

<?php if ( '' !== $ctx['reply_excerpt'] ) : ?>
	<blockquote style="margin:0 0 24px;padding:12px 16px;background-color:#F9FAFB;border-left:3px solid <?php echo esc_attr( $ctx['accent'] ); ?>;border-radius:4px;font-size:14px;line-height:1.6;color:#4B5563;"><?php echo esc_html( $ctx['reply_excerpt'] ); ?></blockquote>
<?php endif; ?>

<table role="presentation" cellpadding="0" cellspacing="0">
<tr><td style="border-radius:6px;background-color:<?php echo esc_attr( $ctx['accent'] ); ?>;">
	<a href="<?php echo esc_url( $ctx['cta_url'] ); ?>" style="display:inline-block;padding:12px 28px;color:#FFFFFF;font-size:14px;font-weight:600;text-decoration:none;border-radius:6px;"><?php echo esc_html( $ctx['cta_text'] ); ?></a>
</td></tr>
</table>

</td></tr>

<tr><td style="background-color:#F9FAFB;padding:20px 32px;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 8px 8px;">
<p style="margin:0 0 8px;font-size:12px;line-height:1.5;color:#6B7280;"><?php echo $footer_links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — components escaped above. ?></p>
<?php echo $footer_custom; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped above. ?>
<p style="margin:0;font-size:11px;color:#9CA3AF;">
	<?php echo esc_html( $ctx['site_name_text'] ); ?> &middot; <a href="<?php echo esc_url( $ctx['home_url_text'] ); ?>" style="color:#9CA3AF;text-decoration:none;"><?php echo esc_html( $ctx['home_url_text'] ); ?></a>
</p>
</td></tr>

</table>

</td></tr>
</table>
</body>
</html>
