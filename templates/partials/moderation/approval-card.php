<?php
/**
 * Approval card partial — one item held by a space's require_approval setting.
 *
 * The sibling of flag-card.php, for the queue's OTHER source. A flag is a
 * member reporting content that is already published; an approval-hold is the
 * space refusing to publish in the first place, so there is no reporter, no
 * reason, and no flag row — just content waiting on a decision. That is why
 * this is a separate card rather than a variant of flag-card.
 *
 * Both actions post to the space-scoped route
 * `/spaces/{id}/moderation/{action}/{type}/{obj_id}`, deliberately NOT the
 * site-wide `/moderation/{action}/...` one: the site-wide route requires the
 * `jetonomy_moderate` capability, which a space moderator does not hold, and
 * rendering a button that can only ever 403 is the bug the Banned-members tab
 * already had to fix once.
 *
 * Expected args (extracted from the caller's $args):
 *   object      $item         Post or reply row.
 *   string      $kind         'post' or 'reply'.
 *   object      $space        Space the item belongs to (pre-resolved by the caller).
 *   object|null $parent_post  For replies: the parent post, for title + permalink.
 *   string      $base         Jetonomy base URL for content links.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $item ) || empty( $space ) || empty( $kind ) ) {
	return;
}

$jt_is_reply    = 'reply' === $kind;
$jt_author      = get_userdata( (int) ( $item->author_id ?? 0 ) );
$jt_author_name = $jt_author ? \Jetonomy\user_display_name( $jt_author ) : __( 'Unknown', 'jetonomy' );
$jt_age         = human_time_diff( strtotime( (string) $item->created_at ), time() );

// content_plain is maintained on both tables; fall back for rows written
// before it existed rather than rendering an empty card.
$jt_plain   = (string) ( $item->content_plain ?? '' );
$jt_plain   = '' !== $jt_plain ? $jt_plain : wp_strip_all_tags( (string) ( $item->content ?? '' ) );
$jt_excerpt = trim( mb_substr( $jt_plain, 0, 200 ) );
if ( mb_strlen( $jt_plain ) > 200 ) {
	$jt_excerpt .= '…';
}

// A reply has no title of its own — show the thread it belongs to so the
// moderator knows the context they are approving into.
$jt_title = $jt_is_reply
	? (string) ( $parent_post->title ?? '' )
	: (string) ( $item->title ?? '' );

$jt_slug = $jt_is_reply
	? (string) ( $parent_post->slug ?? '' )
	: (string) ( $item->slug ?? '' );

$jt_permalink = $jt_slug
	? $base . '/s/' . $space->slug . '/t/' . $jt_slug . '/'
	: '';

// The JS appends "{action}/{kind}/{id}" to this.
$jt_endpoint = esc_url_raw( rest_url( 'jetonomy/v1/spaces/' . (int) $space->id . '/moderation/' ) );
?>
<div class="jt-mod-flag jt-mod-item jt-mod-approval"
	data-object-kind="<?php echo esc_attr( $kind ); ?>"
	data-object-id="<?php echo absint( $item->id ); ?>"
	data-act-endpoint="<?php echo esc_attr( $jt_endpoint ); ?>">
	<div class="jt-mod-flag-head">
		<span class="jt-mod-flag-type">
			<?php echo $jt_is_reply ? esc_html__( 'Reply', 'jetonomy' ) : esc_html__( 'Post', 'jetonomy' ); ?>
		</span>
		<span class="jt-mod-flag-reason jt-mod-flag-reason--held">
			<?php esc_html_e( 'Awaiting approval', 'jetonomy' ); ?>
		</span>
		<a class="jt-mod-flag-space" href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>">
			<?php echo esc_html( (string) $space->title ); ?>
		</a>
		<span class="jt-mod-flag-reporter">
			<?php
			/* translators: 1: author display name, 2: human-readable time since submission */
			echo esc_html( sprintf( __( 'by %1$s · %2$s ago', 'jetonomy' ), $jt_author_name, $jt_age ) );
			?>
		</span>
	</div>

	<?php if ( '' !== $jt_title ) : ?>
		<div class="jt-mod-flag-title">
			<?php
			if ( $jt_is_reply ) {
				/* translators: %s: title of the thread the held reply belongs to */
				echo esc_html( sprintf( __( 'In: %s', 'jetonomy' ), $jt_title ) );
			} else {
				echo esc_html( $jt_title );
			}
			?>
		</div>
	<?php endif; ?>

	<div class="jt-mod-flag-excerpt">
		<?php echo esc_html( $jt_excerpt ); ?>
	</div>

	<div class="jt-mod-flag-actions">
		<?php if ( $jt_permalink ) : ?>
			<a href="<?php echo esc_url( $jt_permalink ); ?>" class="jt-btn jt-btn-ghost" target="_blank" rel="noreferrer">
				<?php esc_html_e( 'View', 'jetonomy' ); ?>
			</a>
		<?php endif; ?>
		<button type="button"
			class="jt-btn jt-btn-fill jt-mod-approve"
			data-wp-on--click="actions.moderateApproval"
			data-action-name="approve">
			<?php jetonomy_echo_icon( 'check-circle', 14 ); ?>
			<?php esc_html_e( 'Approve', 'jetonomy' ); ?>
		</button>
		<button type="button"
			class="jt-btn jt-btn-ghost jt-btn-danger jt-mod-approve"
			data-wp-on--click="actions.moderateApproval"
			data-action-name="trash"
			data-confirm="<?php esc_attr_e( 'Reject this submission? It moves to trash.', 'jetonomy' ); ?>">
			<?php jetonomy_echo_icon( 'trash', 14 ); ?>
			<?php esc_html_e( 'Reject', 'jetonomy' ); ?>
		</button>
	</div>
</div>
