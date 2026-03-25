<?php
defined( 'ABSPATH' ) || exit;

// Auth check is handled by Template_Loader before output.
$user_id = get_current_user_id();
$notifications = \Jetonomy\Models\Notification::list_for_user( $user_id, 30 );

// Mark all as read on page load.
\Jetonomy\Models\Notification::mark_all_read( $user_id );

$base = \Jetonomy\base_url();

$crumbs = [
	[ 'label' => __( 'Notifications', 'jetonomy' ), 'url' => '' ],
];

$type_labels = [
	'reply'            => __( 'replied to your post', 'jetonomy' ),
	'mention'          => __( 'mentioned you', 'jetonomy' ),
	'vote'             => __( 'voted on your post', 'jetonomy' ),
	'vote_up'          => __( 'upvoted your post', 'jetonomy' ),
	'accepted'         => __( 'accepted your reply', 'jetonomy' ),
	'new_post'         => __( 'created a new post', 'jetonomy' ),
	'subscription'     => __( 'new activity in a subscribed space', 'jetonomy' ),
	'trust_promotion'  => __( 'you have been promoted to a new trust level', 'jetonomy' ),
	'moderation'       => __( 'a moderator acted on your content', 'jetonomy' ),
	'badge_earned'     => __( 'earned a badge', 'jetonomy' ),
	'level_up'         => __( 'reached a new level', 'jetonomy' ),
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
<main>
		<h1 class="jt-page-title jt-mb-20">
			<?php esc_html_e( 'Notifications', 'jetonomy' ); ?>
		</h1>

		<?php if ( empty( $notifications ) ) : ?>
			<div class="jt-empty">
				<div class="jt-empty-icon"><?php jetonomy_echo_icon( 'empty-notifications', 80 ); ?></div>
				<div class="jt-empty-text"><?php esc_html_e( 'You are all caught up!', 'jetonomy' ); ?></div>
			</div>
		<?php else : ?>
			<div class="jt-card jt-card-flush">
				<?php foreach ( $notifications as $notif ) : ?>
					<?php
					$actor = $notif->actor_id ? get_userdata( (int) $notif->actor_id ) : null;
					$actor_name = $actor ? $actor->display_name : __( 'Someone', 'jetonomy' );
					$action_label = ! empty( $notif->message )
					? $notif->message
					: ( $type_labels[ $notif->type ] ?? $notif->type );
					$time_ago = human_time_diff( strtotime( $notif->created_at ), current_time( 'timestamp', true ) );

					// Build link to the relevant object.
					$notif_url = $base;
					if ( 'post' === $notif->object_type && $notif->object_id ) {
						global $wpdb;
						$posts_tbl  = \Jetonomy\table( 'posts' );
						$spaces_tbl = \Jetonomy\table( 'spaces' );
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$row = $wpdb->get_row(
							$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								"SELECT p.slug AS post_slug, sp.slug AS space_slug
								 FROM {$posts_tbl} p
								 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
								 WHERE p.id = %d",
								(int) $notif->object_id
							)
						);
						if ( $row ) {
							$notif_url = $base . '/s/' . $row->space_slug . '/t/' . $row->post_slug . '/';
						}
					}
					?>
					<a href="<?php echo esc_url( $notif_url ); ?>"
						class="jt-notif-item <?php echo ! $notif->is_read ? 'unread' : ''; ?>">
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

<aside class="jt-sidebar">
	<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => null ] ); ?>
</aside>
</div>
