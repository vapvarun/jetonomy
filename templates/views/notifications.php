<?php
/**
 * Notifications view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

// Auth check is handled by Template_Loader before output.
$user_id       = get_current_user_id();
$notifications = \Jetonomy\Models\Notification::list_for_user( $user_id, 30 );
// 1.4.0 C.6 fix: do NOT auto mark-all-read on render — the user never got
// to see what was unread because the page wiped their state on view.
// The "Mark all as read" button (rendered below) calls the existing REST
// endpoint POST /jetonomy/v1/notifications/mark-all-read on click.
$has_unread = false;
foreach ( $notifications as $notif ) {
	if ( empty( $notif->is_read ) ) {
		$has_unread = true;
		break;
	}
}

$base = \Jetonomy\base_url();

$crumbs = [
	[
		'label' => __( 'Notifications', 'jetonomy' ),
		'url'   => '',
	],
];

$type_labels = [
	'reply_to_post'   => __( 'replied to your post', 'jetonomy' ),
	'reply_to_reply'  => __( 'replied to your comment', 'jetonomy' ),
	'mention'         => __( 'mentioned you', 'jetonomy' ),
	'vote_on_post'    => __( 'voted on your post', 'jetonomy' ),
	'accepted_answer' => __( 'accepted your reply', 'jetonomy' ),
	'new_post_in_sub' => __( 'new activity in a subscribed space', 'jetonomy' ),
	'moderation'      => __( 'a moderator acted on your content', 'jetonomy' ),
	'badge_earned'    => __( 'earned a badge', 'jetonomy' ),
	'flag'            => __( 'new content flag requires review', 'jetonomy' ),
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
<main>
		<div class="jt-notifications-head">
			<h1 class="jt-page-title">
				<?php esc_html_e( 'Notifications', 'jetonomy' ); ?>
			</h1>
			<?php if ( $has_unread ) : ?>
				<button type="button" class="jt-btn jt-btn-ghost jt-mark-all-read"
					data-jt-mark-all-read
					aria-label="<?php esc_attr_e( 'Mark all notifications as read', 'jetonomy' ); ?>">
					<?php esc_html_e( 'Mark all as read', 'jetonomy' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<?php if ( empty( $notifications ) ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'icon'    => 'empty-notifications',
					'message' => __( 'You are all caught up!', 'jetonomy' ),
				]
			);
			?>
		<?php else : ?>
			<div class="jt-card jt-card-flush">
				<?php foreach ( $notifications as $notif ) : ?>
					<?php
					$actor        = $notif->actor_id ? get_userdata( (int) $notif->actor_id ) : null;
					$actor_name   = $actor ? $actor->display_name : __( 'Someone', 'jetonomy' );
					$action_label = ! empty( $notif->message )
					? $notif->message
					: ( $type_labels[ $notif->type ] ?? $notif->type );
					$time_ago     = human_time_diff( strtotime( $notif->created_at ), time() );

					// Build link to the relevant object.
					$notif_url = $base;
					if ( $notif->object_id ) {
						global $wpdb;
						$posts_tbl   = \Jetonomy\table( 'posts' );
						$spaces_tbl  = \Jetonomy\table( 'spaces' );
						$replies_tbl = \Jetonomy\table( 'replies' );

						if ( 'post' === $notif->object_type ) {
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$row = $wpdb->get_row(
								$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
									"SELECT p.slug AS post_slug, sp.slug AS space_slug FROM {$posts_tbl} p LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id WHERE p.id = %d",
									(int) $notif->object_id
								)
							);
							if ( $row ) {
								$notif_url = $base . '/s/' . $row->space_slug . '/t/' . $row->post_slug . '/';
							}
						} elseif ( 'reply' === $notif->object_type ) {
							// Reply notification — look up parent post for URL + anchor to reply.
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$row = $wpdb->get_row(
								$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
									"SELECT p.slug AS post_slug, sp.slug AS space_slug, r.id AS reply_id FROM {$replies_tbl} r LEFT JOIN {$posts_tbl} p ON p.id = r.post_id LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id WHERE r.id = %d",
									(int) $notif->object_id
								)
							);
							if ( $row ) {
								$notif_url = $base . '/s/' . $row->space_slug . '/t/' . $row->post_slug . '/#reply-' . $row->reply_id;
							}
						} elseif ( 'badge' === $notif->object_type ) {
							$badge_user = get_userdata( (int) $notif->user_id );
							if ( $badge_user ) {
								$notif_url = $base . '/u/' . rawurlencode( $badge_user->user_login ) . '/';
							}
						} elseif ( 'space' === $notif->object_type && 'join_request' === $notif->type ) {
							// Mirrors Notifier::build_join_request_url_for() so the
							// link the customer sees on this page matches the link
							// in their email — and routes to the right surface for
							// who they are. Recipient with `jetonomy_manage_spaces`
							// (or admin) lands in wp-admin → Spaces → join requests;
							// space-level admins go to the front-end mod queue.
							$jr_space = \Jetonomy\Models\Space::find( (int) $notif->object_id );
							if ( $jr_space ) {
								if ( current_user_can( 'jetonomy_manage_spaces' ) || current_user_can( 'manage_options' ) ) {
									$notif_url = admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . (int) $jr_space->id . '&tab=join_requests' );
								} else {
									$notif_url = $base . '/s/' . $jr_space->slug . '/mod/';
								}
							}
						}
					}
					?>
					<a href="<?php echo esc_url( $notif_url ); ?>"
						class="jt-notif-item <?php echo ! $notif->is_read ? esc_attr( 'unread' ) : ''; ?>">
						<span class="jt-avatar jt-avatar-sm jt-flex-shrink-0">
							<?php echo esc_html( $actor ? strtoupper( substr( $actor->display_name, 0, 2 ) ) : '?' ); ?>
						</span>
						<div class="jt-notif-body">
							<div class="jt-notif-text">
								<?php if ( ! empty( $notif->message ) ) : ?>
									<?php echo esc_html( $notif->message ); ?>
								<?php else : ?>
									<strong><?php echo esc_html( $actor_name ); ?></strong>
									<?php echo esc_html( $action_label ); ?>
								<?php endif; ?>
							</div>
							<div class="jt-notif-time">
								<?php
								/* translators: %s: human-readable time difference */
								echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
								?>
							</div>
						</div>
						<?php if ( ! $notif->is_read ) : ?>
							<span class="jt-notif-dot"></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
</main>

<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => null ] ); ?>
</div>

<?php if ( $has_unread ) : ?>
<script>
( function () {
	'use strict';
	var btn = document.querySelector( '[data-jt-mark-all-read]' );
	if ( ! btn || ! window.jetonomyData ) {
		return;
	}
	btn.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		btn.disabled = true;
		fetch( window.jetonomyData.restBase + '/notifications/mark-all-read', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': window.jetonomyData.restNonce,
				'Content-Type': 'application/json'
			}
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'mark_all_read_failed' );
			}
			// Drop unread dots + hide the button — no full refresh needed.
			document.querySelectorAll( '.jt-notif-dot' ).forEach( function ( d ) { d.remove(); } );
			btn.remove();
		} ).catch( function () {
			btn.disabled = false;
		} );
	} );
} )();
</script>
<?php endif; ?>
