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
 *                            pre-resolved permalink, every pending report on
 *                            the item (one card per item, however many
 *                            members reported it) and whether it is already
 *                            removed. Callers rendering a LIST should always
 *                            pass it: without it this partial falls back to
 *                            resolving it itself, a few queries per card.
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
	'off-topic'      => __( 'Off-topic', 'jetonomy' ), // jt-terms-ok: report reason idiom, not the noun.
	'off_topic'      => __( 'Off-topic', 'jetonomy' ), // jt-terms-ok: report reason idiom, not the noun.
	'misinformation' => __( 'Misinformation', 'jetonomy' ),
	'other'          => __( 'Other', 'jetonomy' ),
);

$reporter = get_userdata( (int) $flag->reporter_id );
$time_ago = human_time_diff( strtotime( $flag->created_at ), time() );

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
	$context         = $jt_flag_context[ $flag->object_type . ':' . (int) $flag->object_id ] ?? null;
	$object_url      = $context->permalink ?? '';
}

$jt_fc_is_user = 'user' === $flag->object_type;
$jt_fc_removed = ! empty( $context->removed );
$jt_fc_count   = (int) ( $context->reports['count'] ?? 1 );
$jt_fc_reasons = array();
foreach ( (array) ( $context->reports['reasons'] ?? array( (string) $flag->reason => 1 ) ) as $jt_fc_key => $jt_fc_n ) {
	$jt_fc_label     = $reason_labels[ $jt_fc_key ] ?? (string) $jt_fc_key;
	$jt_fc_reasons[] = $jt_fc_n > 1 ? sprintf( '%1$s (%2$s)', $jt_fc_label, number_format_i18n( (int) $jt_fc_n ) ) : $jt_fc_label;
}
$jt_fc_reporter = $reporter ? \Jetonomy\user_display_name( $reporter ) : __( 'Unknown', 'jetonomy' );
if ( $jt_fc_count > 1 ) {
	/* translators: 1: newest reporter's display name; 2: number of other members who reported the same item. */
	$jt_fc_reporter = sprintf( _n( '%1$s and %2$s other', '%1$s and %2$s others', $jt_fc_count - 1, 'jetonomy' ), $jt_fc_reporter, number_format_i18n( $jt_fc_count - 1 ) );
}
$jt_fc_type = $jt_fc_is_user
	? \Jetonomy\jetonomy_label( 'member' )
	: \Jetonomy\jetonomy_label( 'reply' === $flag->object_type ? 'reply' : 'topic' );
// The action buttons say only "Remove" / "Dismiss"; describedby names what they act on.
$jt_fc_target    = ( $jt_fc_is_user && isset( $context->object ) ) || isset( $context->object, $context->topic );
$jt_fc_target_id = 'jt-flag-' . absint( $flag->id ) . '-target';
$jt_fc_describe  = $jt_fc_target ? ' aria-describedby="' . esc_attr( $jt_fc_target_id ) . '"' : '';
// Remove trashes the content, so it asks first like Reject and Lift restriction do, naming what goes.
$jt_fc_noun = \Jetonomy\jetonomy_label( 'reply' === $flag->object_type ? 'reply' : 'topic', false, true );
if ( ! isset( $context->topic ) ) {
	/* translators: %s: singular topic or reply label, lowercase. */
	$jt_fc_confirm = sprintf( __( 'Remove this %s? It moves to trash and its author is notified.', 'jetonomy' ), $jt_fc_noun );
} elseif ( 'reply' === $flag->object_type ) {
	/* translators: 1: singular reply label, lowercase; 2: title of the topic the reply is on. */
	$jt_fc_confirm = sprintf( __( 'Remove this %1$s on “%2$s”? It moves to trash and its author is notified.', 'jetonomy' ), $jt_fc_noun, jetonomy_post_title_or_excerpt( $context->topic ) );
} else {
	/* translators: 1: singular topic label, lowercase; 2: the topic's title. */
	$jt_fc_confirm = sprintf( __( 'Remove the %1$s “%2$s”? It moves to trash and its author is notified.', 'jetonomy' ), $jt_fc_noun, jetonomy_post_title_or_excerpt( $context->topic ) );
}
?>
<div class="jt-mod-flag jt-mod-item"
	data-flag-id="<?php echo absint( $flag->id ); ?>"
	data-resolve-endpoint="<?php echo esc_attr( $resolve_endpoint ); ?>">
	<div class="jt-mod-flag-head">
		<span class="jt-mod-flag-type">
			<?php echo esc_html( $jt_fc_type ); ?>
		</span>
		<span class="jt-mod-flag-reason">
			<?php echo esc_html( implode( ', ', $jt_fc_reasons ) ); ?>
		</span>
		<?php if ( $jt_fc_count > 1 ) : ?>
			<span class="jt-mod-flag-count">
				<?php
				/* translators: %s: number of members who reported the same item. */
				echo esc_html( sprintf( _n( '%s report', '%s reports', $jt_fc_count, 'jetonomy' ), number_format_i18n( $jt_fc_count ) ) );
				?>
			</span>
		<?php endif; ?>
		<span class="jt-mod-flag-reporter">
			<?php
			/* translators: %s: reporter's display name */
			echo esc_html( sprintf( __( 'Reported by %s', 'jetonomy' ), $jt_fc_reporter ) );
			/* translators: %s: human-readable time difference. */
			echo ' &middot; ' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
			?>
		</span>
	</div>

	<?php if ( $jt_fc_is_user && isset( $context->object ) ) : ?>
		<p class="jt-mod-flag-title" id="<?php echo esc_attr( $jt_fc_target_id ); ?>" dir="auto">
			<?php if ( $object_url ) : ?>
				<a href="<?php echo esc_url( $object_url ); ?>"><?php echo esc_html( \Jetonomy\user_display_name( $context->object ) ); ?></a>
			<?php else : ?>
				<?php echo esc_html( \Jetonomy\user_display_name( $context->object ) ); ?>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $jt_fc_removed ) : ?>
		<p class="jt-mod-flag-removed" role="status">
			<?php
			/* translators: %s: singular topic or reply label, lowercase. */
			echo esc_html( sprintf( __( 'This %s was already removed. Dismiss the report to clear it.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' === $flag->object_type ? 'reply' : 'topic', false, true ) ) );
			?>
		</p>
	<?php endif; ?>

	<?php
	// What was flagged: the topic title and the start of the flagged text, as
	// the site-wide queue shows, so a moderator need not open every item.
	if ( isset( $context->object, $context->topic ) ) :
		$jt_fc_plain   = (string) ( $context->object->content_plain ?? wp_strip_all_tags( (string) ( $context->object->content ?? '' ) ) );
		$jt_fc_excerpt = mb_strlen( $jt_fc_plain ) > 140 ? trim( mb_substr( $jt_fc_plain, 0, 140 ) ) . '…' : trim( $jt_fc_plain );
		?>
		<p class="jt-mod-flag-title" id="<?php echo esc_attr( $jt_fc_target_id ); ?>" dir="auto">
			<?php if ( $object_url ) : ?>
				<a href="<?php echo esc_url( $object_url ); ?>"><?php echo esc_html( jetonomy_post_title_or_excerpt( $context->topic ) ); ?></a>
			<?php else : ?>
				<?php echo esc_html( jetonomy_post_title_or_excerpt( $context->topic ) ); ?>
			<?php endif; ?>
		</p>
		<?php if ( '' !== $jt_fc_excerpt ) : ?>
			<p class="jt-mod-flag-excerpt" dir="auto"><?php echo esc_html( $jt_fc_excerpt ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! empty( $flag->description ) ) : ?>
		<p class="jt-mod-flag-note" dir="auto">
			<?php echo esc_html( (string) $flag->description ); ?>
		</p>
	<?php endif; ?>

	<div class="jt-mod-flag-actions">
		<?php if ( $object_url && ! $jt_fc_removed ) : ?>
			<a href="<?php echo esc_url( $object_url ); ?>" class="jt-btn jt-btn-ghost" target="_blank" rel="noreferrer">
				<?php $jt_fc_is_user ? esc_html_e( 'View profile', 'jetonomy' ) : esc_html_e( 'View', 'jetonomy' ); ?>
			</a>
		<?php endif; ?>
		<?php if ( $jt_fc_is_user ) : ?>
			<?php // Upholding a report on a member removes nothing; a ban, if needed, is its own action. ?>
			<button type="button"
				class="jt-btn jt-btn-fill jt-mod-resolve"
				data-wp-on--click="actions.resolveFlag"
				data-flag-id="<?php echo absint( $flag->id ); ?>"<?php echo $jt_fc_describe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
				data-resolution="valid">
				<?php jetonomy_echo_icon( 'check-circle', 14 ); ?>
				<?php esc_html_e( 'Uphold', 'jetonomy' ); ?>
			</button>
		<?php elseif ( ! $jt_fc_removed ) : ?>
		<button type="button"
			class="jt-btn jt-btn-fill jt-btn-danger jt-mod-resolve"
			data-wp-on--click="actions.resolveFlag"
			data-flag-id="<?php echo absint( $flag->id ); ?>"<?php echo $jt_fc_describe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
			data-resolution="valid"
			data-confirm="<?php echo esc_attr( $jt_fc_confirm ); ?>">
			<?php jetonomy_echo_icon( 'trash', 14 ); ?>
			<?php esc_html_e( 'Remove', 'jetonomy' ); ?>
		</button>
		<?php endif; ?>
		<button type="button"
			class="jt-btn jt-btn-ghost jt-mod-resolve"
			data-wp-on--click="actions.resolveFlag"
			data-flag-id="<?php echo absint( $flag->id ); ?>"<?php echo $jt_fc_describe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
			data-resolution="dismissed">
			<?php esc_html_e( 'Dismiss', 'jetonomy' ); ?>
		</button>
	</div>
</div>
