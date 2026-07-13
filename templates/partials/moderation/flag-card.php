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
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $flag ) || empty( $resolve_endpoint ) ) {
	return;
}

$reason_labels = [
	'spam'           => __( 'Spam', 'jetonomy' ),
	'offensive'      => __( 'Offensive', 'jetonomy' ),
	'abuse'          => __( 'Abuse / Harassment', 'jetonomy' ),
	'harassment'     => __( 'Harassment', 'jetonomy' ),
	'off-topic'      => __( 'Off-topic', 'jetonomy' ),
	'off_topic'      => __( 'Off-topic', 'jetonomy' ),
	'misinformation' => __( 'Misinformation', 'jetonomy' ),
	'other'          => __( 'Other', 'jetonomy' ),
];

$reporter    = get_userdata( (int) $flag->reporter_id );
$time_ago    = human_time_diff( strtotime( $flag->created_at ), time() );
$reason_text = $reason_labels[ $flag->reason ] ?? $flag->reason;

// Deliberately NOT block-filtered anywhere on this card or the moderation
// queue it belongs to: a blocked user's flagged content MUST still reach
// moderators. Hard rule — never filter moderation.
// Resolve deep link to the flagged object.
$object_url = '';
if ( 'post' === $flag->object_type ) {
	global $wpdb;
	$posts_tbl  = \Jetonomy\table( 'posts' );
	$spaces_tbl = \Jetonomy\table( 'spaces' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT p.slug, sp.slug AS space_slug
			 FROM {$posts_tbl} p
			 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
			 WHERE p.id = %d",
			(int) $flag->object_id
		)
	);
	if ( $row ) {
		$object_url = $base . '/s/' . $row->space_slug . '/t/' . $row->slug . '/';
	}
} elseif ( 'reply' === $flag->object_type ) {
	global $wpdb;
	$replies_tbl = \Jetonomy\table( 'replies' );
	$posts_tbl   = \Jetonomy\table( 'posts' );
	$spaces_tbl  = \Jetonomy\table( 'spaces' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT p.slug AS post_slug, sp.slug AS space_slug
			 FROM {$replies_tbl} r
			 LEFT JOIN {$posts_tbl} p ON p.id = r.post_id
			 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
			 WHERE r.id = %d",
			(int) $flag->object_id
		)
	);
	if ( $row ) {
		$object_url = $base . '/s/' . $row->space_slug . '/t/' . $row->post_slug . '/';
	}
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
			<?php echo esc_html( $reporter ? $reporter->display_name : __( 'Unknown', 'jetonomy' ) ); ?>
			<?php
			/* translators: %s: human-readable time difference */
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
