<?php
/**
 * Flag card partial — one pending flag row.
 *
 * Shared by the admin dashboard view and per-space queue so the resolve /
 * dismiss UI stays identical across surfaces.
 *
 * Expected args (extracted from caller's $args):
 *   object $flag             Flag row.
 *   string $resolve_endpoint REST URL for POSTing resolve (without the trailing flag id).
 *                            Example: "/wp-json/jetonomy/v1/spaces/2/moderation/flags/"
 *                            The JS appends "{id}/resolve".
 *   string $base             Jetonomy base URL for content links.
 *   object $context          Optional. The row's entry from
 *                            Moderation_Service::flag_context(), carrying a
 *                            pre-resolved permalink. Callers rendering a LIST
 *                            should always pass it: without it this partial
 *                            falls back to resolving the permalink itself,
 *                            which is one query per card.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $flag ) || empty( $resolve_endpoint ) ) {
	return;
}

$reason_labels = array(
	'spam'           => __( 'Spam', 'jetonomy' ),
	'offensive'      => __( 'Offensive', 'jetonomy' ),
	'abuse'          => __( 'Abuse / Harassment', 'jetonomy' ),
	'harassment'     => __( 'Harassment', 'jetonomy' ),
	'off-topic'      => __( 'Off-topic', 'jetonomy' ),
	'off_topic'      => __( 'Off-topic', 'jetonomy' ),
	'misinformation' => __( 'Misinformation', 'jetonomy' ),
	'other'          => __( 'Other', 'jetonomy' ),
);

$reporter    = get_userdata( (int) $flag->reporter_id );
$time_ago    = human_time_diff( strtotime( $flag->created_at ), time() );
$reason_text = $reason_labels[ $flag->reason ] ?? $flag->reason;

// Deliberately NOT block-filtered anywhere on this card or the moderation
// queue it belongs to: a blocked user's flagged content MUST still reach
// moderators. Hard rule — never filter moderation.
//
// Deep link to the flagged object. A page of cards passes $context from
// Moderation_Service::flag_context() so the queue costs a fixed number of
// queries; a card rendered on its own resolves itself the same way.
$object_url = isset( $context->permalink ) ? (string) $context->permalink : '';

if ( '' === $object_url ) {
	$jt_flag_context = \Jetonomy\Moderation\Moderation_Service::flag_context( array( $flag ), \Jetonomy\base_url() );
	$object_url      = $jt_flag_context[ $flag->object_type . ':' . (int) $flag->object_id ]->permalink ?? '';
}
?>
<div class="jt-mod-flag jt-mod-item"
	data-flag-id="<?php echo absint( $flag->id ); ?>"
	data-resolve-endpoint="<?php echo esc_attr( $resolve_endpoint ); ?>">
	<div class="jt-mod-flag-head">
		<span class="jt-mod-flag-type">
			<?php echo esc_html( ucfirst( (string) $flag->object_type ) ); ?>
		</span>
		<span class="jt-mod-flag-reason">
			<?php echo esc_html( $reason_text ); ?>
		</span>
		<span class="jt-mod-flag-reporter">
			<?php echo esc_html( $reporter ? \Jetonomy\user_display_name( $reporter ) : __( 'Unknown', 'jetonomy' ) ); ?>
			<?php
			/* translators: %s: human-readable time difference. */
			echo ' &middot; ' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
			?>
		</span>
	</div>

	<?php if ( ! empty( $flag->description ) ) : ?>
		<p class="jt-mod-flag-note">
			<?php echo esc_html( (string) $flag->description ); ?>
		</p>
	<?php endif; ?>

	<div class="jt-mod-flag-actions">
		<?php if ( $object_url ) : ?>
			<a href="<?php echo esc_url( $object_url ); ?>" class="jt-btn jt-btn-ghost" target="_blank" rel="noreferrer">
				<?php esc_html_e( 'View', 'jetonomy' ); ?>
			</a>
		<?php endif; ?>
		<button type="button"
			class="jt-btn jt-btn-fill jt-btn-danger jt-mod-resolve"
			data-wp-on--click="actions.resolveFlag"
			data-flag-id="<?php echo absint( $flag->id ); ?>"
			data-resolution="valid">
			<?php jetonomy_echo_icon( 'trash', 14 ); ?>
			<?php esc_html_e( 'Remove', 'jetonomy' ); ?>
		</button>
		<button type="button"
			class="jt-btn jt-btn-ghost jt-mod-resolve"
			data-wp-on--click="actions.resolveFlag"
			data-flag-id="<?php echo absint( $flag->id ); ?>"
			data-resolution="dismissed">
			<?php esc_html_e( 'Dismiss', 'jetonomy' ); ?>
		</button>
	</div>
</div>
