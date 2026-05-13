<?php
/**
 * Cross-space moderation dashboard.
 *
 * Two audiences land here:
 *   1. WP admins + jetonomy_moderate cap holders — see every space with
 *      pending flags. Their query is unscoped.
 *   2. Space-level mods who moderate two or more spaces — see only the
 *      queues they actually own. (Single-space mods are redirected by
 *      Template_Loader::render straight to /s/:slug/mod/, so they
 *      never reach this template.)
 *
 * Page model: pending flags are listed directly so a moderator
 * sees content excerpt + reporter + age + reason WITHOUT having
 * to open a per-space queue first. Bulk actions and detailed
 * resolution still live at /s/:slug/mod/ — the row's space link
 * carries the moderator there pre-scoped to that space's queue.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

use Jetonomy\Moderation\Moderation_Permissions;
use Jetonomy\Moderation\Moderation_Service;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;

$user_id  = get_current_user_id();
$base     = \Jetonomy\base_url();
$is_admin = Moderation_Permissions::can_view_admin_dashboard( $user_id );

// Anyone without admin dashboard access OR any moderated space gets the
// standard 403 empty state. Template_Loader has already redirected
// single-space mods, so reaching here without view rights is a stale
// link / drive-by visit.
if ( ! $is_admin && ! Moderation_Permissions::can_view_any_queue( $user_id ) ) {
	status_header( 403 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'message' => __( 'You do not have permission to view this page.', 'jetonomy' ),
			'tone'    => 'forbidden',
		]
	);
	return;
}

$flags = Moderation_Service::list_pending_flags( $user_id );
$total = count( $flags );

// Reason → human label map. Source of truth for the badge text;
// matches the enum at class-moderation-controller.php:106.
$jt_reason_labels = [
	'spam'       => __( 'Spam', 'jetonomy' ),
	'offensive'  => __( 'Offensive', 'jetonomy' ),
	'off_topic'  => __( 'Off-topic', 'jetonomy' ),
	'harassment' => __( 'Harassment', 'jetonomy' ),
	'other'      => __( 'Other', 'jetonomy' ),
];

$crumbs = [
	[
		'label' => __( 'Moderation', 'jetonomy' ),
		'url'   => '',
	],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-mod-wrap jt-mod-dashboard">
	<div class="jt-mod-dashboard-head">
		<div>
			<h1 class="jt-page-title">
				<?php esc_html_e( 'Moderation Overview', 'jetonomy' ); ?>
			</h1>
			<p class="jt-member-sub">
				<?php
				if ( $is_admin ) {
					/* translators: %d: total pending flag count across every space */
					echo esc_html( sprintf( _n( '%d pending flag across your community', '%d pending flags across your community', $total, 'jetonomy' ), $total ) );
				} else {
					/* translators: %d: total pending flag count across the spaces this moderator owns */
					echo esc_html( sprintf( _n( '%d pending flag across the spaces you moderate', '%d pending flags across the spaces you moderate', $total, 'jetonomy' ), $total ) );
				}
				?>
			</p>
		</div>
		<?php if ( $total > 0 ) : ?>
			<span class="jt-badge-danger jt-flag-count" data-count="<?php echo esc_attr( (string) $total ); ?>">
				<?php
				/* translators: %d: total pending flag count */
				echo esc_html( sprintf( _n( '%d pending', '%d pending', $total, 'jetonomy' ), $total ) );
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php if ( empty( $flags ) ) : ?>
		<?php
		$jt_empty_message = $is_admin
			? __( 'No pending flags anywhere. Your community is clean.', 'jetonomy' )
			: __( 'No pending flags in the spaces you moderate.', 'jetonomy' );
		\Jetonomy\Template_Loader::partial( 'moderation/queue-empty', [ 'message' => $jt_empty_message ] );
		?>
	<?php else : ?>
		<ul class="jt-mod-flag-list">
			<?php foreach ( $flags as $flag ) :
				$is_reply       = 'reply' === $flag->object_type;
				$obj            = $is_reply ? Reply::find( (int) $flag->object_id ) : Post::find( (int) $flag->object_id );
				if ( ! $obj ) {
					continue;
				}
				$space_id       = $is_reply
					? (int) ( Post::find( (int) $obj->post_id )->space_id ?? 0 )
					: (int) ( $obj->space_id ?? 0 );
				$space          = $space_id ? Space::find( $space_id ) : null;
				if ( ! $space ) {
					continue;
				}
				$reporter       = get_userdata( (int) $flag->reporter_id );
				$reporter_name  = $reporter ? $reporter->display_name : __( 'Unknown', 'jetonomy' );
				$age            = human_time_diff( strtotime( $flag->created_at ), time() );
				$content_plain  = (string) ( $obj->content_plain ?? wp_strip_all_tags( (string) ( $obj->content ?? '' ) ) );
				$excerpt        = trim( mb_substr( $content_plain, 0, 140 ) );
				if ( mb_strlen( $content_plain ) > 140 ) {
					$excerpt .= '…';
				}
				$reason_key     = (string) ( $flag->reason ?? 'other' );
				$reason_label   = $jt_reason_labels[ $reason_key ] ?? $jt_reason_labels['other'];
				$queue_url      = $base . '/s/' . $space->slug . '/mod/';
				?>
				<li class="jt-mod-flag-row">
					<div class="jt-mod-flag-row-head">
						<span class="jt-mod-flag-reason jt-mod-flag-reason--<?php echo esc_attr( $reason_key ); ?>">
							<?php echo esc_html( $reason_label ); ?>
						</span>
						<span class="jt-mod-flag-type">
							<?php echo $is_reply ? esc_html__( 'Reply', 'jetonomy' ) : esc_html__( 'Post', 'jetonomy' ); ?>
						</span>
						<a class="jt-mod-flag-space" href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>">
							<?php echo esc_html( $space->title ); ?>
						</a>
						<span class="jt-mod-flag-age">
							<?php
							/* translators: %s: human-readable time since flag was filed */
							echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $age ) );
							?>
						</span>
					</div>
					<?php if ( ! $is_reply && ! empty( $obj->title ) ) : ?>
						<div class="jt-mod-flag-title"><?php echo esc_html( (string) $obj->title ); ?></div>
					<?php endif; ?>
					<div class="jt-mod-flag-excerpt">
						<?php echo esc_html( $excerpt ); ?>
					</div>
					<div class="jt-mod-flag-foot">
						<span class="jt-mod-flag-reporter">
							<?php
							/* translators: %s: reporter's display name */
							echo esc_html( sprintf( __( 'Reported by %s', 'jetonomy' ), $reporter_name ) );
							?>
						</span>
						<?php if ( ! empty( $flag->note ) ) : ?>
							<span class="jt-mod-flag-note" title="<?php echo esc_attr( (string) $flag->note ); ?>">
								<?php jetonomy_echo_icon( 'message-circle', 14 ); ?>
								<?php esc_html_e( 'Note', 'jetonomy' ); ?>
							</span>
						<?php endif; ?>
						<a class="jt-mod-flag-action" href="<?php echo esc_url( $queue_url ); ?>">
							<?php esc_html_e( 'Review in queue', 'jetonomy' ); ?>
							<?php jetonomy_echo_icon( 'arrow-right', 14 ); ?>
						</a>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
