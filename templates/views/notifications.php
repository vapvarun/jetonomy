<?php
/**
 * Notifications view.
 *
 * Renders the notifications inbox with filter tabs (All / Unread / Replies /
 * Mentions / Votes / Badges), per-row mark-read + delete actions, bulk
 * selection, and "Load More" pagination at 20 rows / page. Counts displayed
 * next to each tab come from a single SUM(CASE …) query so tab labels stay
 * cheap even at 10k+ rows per user. Deep-link URLs are pre-joined into the
 * row set so the template never queries inside the foreach.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Notification;

// Auth check is handled by Template_Loader before output.
$user_id = get_current_user_id();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
$filter = in_array( $filter, array( 'all', 'unread', 'mentions', 'replies', 'votes', 'badges' ), true ) ? $filter : 'all';

// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$current_page = isset( $_GET['paged'] ) ? max( 1, (int) wp_unslash( $_GET['paged'] ) ) : 1;
$page_size    = 20;
$offset       = ( $current_page - 1 ) * $page_size;

$notifications = Notification::list_for_user_with_targets( $user_id, $page_size, $offset, $filter );
$counts        = Notification::counts_by_filter( $user_id );
$has_more      = count( $notifications ) === $page_size;

$base = \Jetonomy\base_url();

$crumbs = array(
	array(
		'label' => __( 'Notifications', 'jetonomy' ),
		'url'   => '',
	),
);

$type_labels = array(
	'reply_to_post'       => __( 'replied to your post', 'jetonomy' ),
	'reply_to_reply'      => __( 'replied to your comment', 'jetonomy' ),
	'mention'             => __( 'mentioned you', 'jetonomy' ),
	'vote_on_post'        => __( 'voted on your post', 'jetonomy' ),
	'accepted_answer'     => __( 'accepted your reply', 'jetonomy' ),
	'idea_status_changed' => __( 'updated your idea on the roadmap', 'jetonomy' ),
	'new_post_in_sub'     => __( 'new activity in a subscribed space', 'jetonomy' ),
	'moderation'          => __( 'a moderator acted on your content', 'jetonomy' ),
	'badge_earned'        => __( 'earned a badge', 'jetonomy' ),
	'flag'                => __( 'new content flag requires review', 'jetonomy' ),
);

$filter_tabs = array(
	'all'      => __( 'All', 'jetonomy' ),
	'unread'   => __( 'Unread', 'jetonomy' ),
	'replies'  => __( 'Replies', 'jetonomy' ),
	'mentions' => __( 'Mentions', 'jetonomy' ),
	'votes'    => __( 'Votes', 'jetonomy' ),
);
// Badges notifications are only ever produced by the Pro custom-badges extension,
// so the tab would always be empty for a free-only site — only show it with Pro active.
if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
	$filter_tabs['badges'] = __( 'Badges', 'jetonomy' );
}

$empty_copy = array(
	'all'      => array( __( 'No notifications yet', 'jetonomy' ), __( 'When members reply to your posts or mention you, those updates land here.', 'jetonomy' ) ),
	'unread'   => array( __( "You're all caught up!", 'jetonomy' ), __( 'Nothing new since you last checked. Switch to All to see your history.', 'jetonomy' ) ),
	'replies'  => array( __( 'No replies yet', 'jetonomy' ), __( 'Replies to your posts and comments will show up here.', 'jetonomy' ) ),
	'mentions' => array( __( 'No mentions yet', 'jetonomy' ), __( "When someone @-mentions you in a post or reply, you'll see it here.", 'jetonomy' ) ),
	'votes'    => array( __( 'No votes yet', 'jetonomy' ), __( 'Upvotes on your posts will appear here.', 'jetonomy' ) ),
	'badges'   => array( __( 'No badges yet', 'jetonomy' ), __( 'Earn badges by contributing to the community.', 'jetonomy' ) ),
);

// Settings deep-link points at the existing notification-preferences block on
// the Edit Profile page so we don't ship a parallel settings surface.
$settings_url = $base . '/u/' . rawurlencode( wp_get_current_user()->user_login ) . '/edit/#notification-preferences';
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
<main>
	<div class="jt-notifications-head">
		<h1 class="jt-page-title"><?php esc_html_e( 'Notifications', 'jetonomy' ); ?></h1>
		<div class="jt-notifications-head__actions">
			<?php if ( $counts['unread'] > 0 ) : ?>
				<button type="button" class="jt-btn jt-btn-ghost jt-btn-sm jt-mark-all-read"
					data-jt-mark-all-read
					data-wp-on--click="actions.markAllNotifsRead"
					aria-label="<?php esc_attr_e( 'Mark all notifications as read', 'jetonomy' ); ?>">
					<?php esc_html_e( 'Mark all as read', 'jetonomy' ); ?>
				</button>
			<?php endif; ?>
			<a href="<?php echo esc_url( $settings_url ); ?>" class="jt-btn jt-btn-ghost jt-btn-sm" aria-label="<?php esc_attr_e( 'Notification settings', 'jetonomy' ); ?>">
				<?php esc_html_e( 'Settings', 'jetonomy' ); ?>
			</a>
		</div>
	</div>

	<nav class="jt-notif-tabs" aria-label="<?php esc_attr_e( 'Filter notifications', 'jetonomy' ); ?>">
		<?php
		foreach ( $filter_tabs as $tab_slug => $tab_label ) :
			$tab_url   = 'all' === $tab_slug
				? remove_query_arg( array( 'filter', 'paged' ) )
				: add_query_arg( array( 'filter' => $tab_slug ), remove_query_arg( 'paged' ) );
			$tab_count = $counts[ $tab_slug ] ?? 0;
			$is_active = $filter === $tab_slug;
			$tab_class = 'jt-notif-tab';
			if ( $is_active ) {
				$tab_class .= ' is-active';
			}
			?>
			<a href="<?php echo esc_url( $tab_url ); ?>"
				class="<?php echo esc_attr( $tab_class ); ?>"
				<?php echo $is_active ? 'aria-current="page"' : ''; ?>>
				<span class="jt-notif-tab__label"><?php echo esc_html( $tab_label ); ?></span>
				<?php if ( $tab_count > 0 ) : ?>
					<span class="jt-notif-tab__count"><?php echo esc_html( number_format_i18n( $tab_count ) ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php if ( empty( $notifications ) ) : ?>
		<?php
		[ $empty_title, $empty_desc ] = $empty_copy[ $filter ] ?? $empty_copy['all'];
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			array(
				'icon'        => 'empty-notifications',
				'message'     => $empty_title,
				'description' => $empty_desc,
			)
		);
		?>
	<?php else : ?>

		<div class="jt-notif-bulkbar" data-jt-notif-bulkbar hidden>
			<label class="jt-notif-bulkbar__selectall">
				<input type="checkbox" data-jt-notif-selectall data-wp-on--change="actions.toggleNotifSelectAll">
				<span><?php esc_html_e( 'Select all on page', 'jetonomy' ); ?></span>
			</label>
			<span class="jt-notif-bulkbar__count" data-jt-notif-selected-count>0</span>
			<div class="jt-notif-bulkbar__actions">
				<button type="button" class="jt-btn jt-btn-ghost jt-btn-sm" data-jt-notif-bulk="mark_read" data-wp-on--click="actions.bulkNotifs">
					<?php esc_html_e( 'Mark read', 'jetonomy' ); ?>
				</button>
				<button type="button" class="jt-btn jt-btn-ghost jt-btn-sm jt-btn-danger" data-jt-notif-bulk="delete" data-wp-on--click="actions.bulkNotifs">
					<?php esc_html_e( 'Delete', 'jetonomy' ); ?>
				</button>
			</div>
		</div>

		<div class="jt-card jt-card-flush jt-notif-list" data-jt-notif-list>
			<?php foreach ( $notifications as $notif ) : ?>
				<?php
				// actor_anonymous is the source of truth for masking — set at
				// notification-creation time from the reply/post's is_anonymous
				// flag (Notifier::create_and_maybe_email / Mentions::notify).
				// Gating get_userdata() on it (rather than deriving from the
				// message text) stops the real author's avatar/name/name-link
				// leaking beside an "Anonymous replied…" message.
				$jt_notif_anon = ! empty( $notif->actor_anonymous );
				$actor         = ( $notif->actor_id && ! $jt_notif_anon ) ? get_userdata( (int) $notif->actor_id ) : null;
				$actor_name    = $jt_notif_anon ? __( 'Anonymous', 'jetonomy' ) : ( $actor ? $actor->display_name : __( 'Someone', 'jetonomy' ) );
				$action_label = ! empty( $notif->message )
					? $notif->message
					: ( $type_labels[ $notif->type ] ?? $notif->type );
				$time_ago     = human_time_diff( strtotime( $notif->created_at ), time() );

				// Build link to the relevant object using pre-joined slug columns.
				$notif_url = $base;
				if ( 'post' === $notif->object_type && ! empty( $notif->post_slug ) && ! empty( $notif->space_slug ) ) {
					$notif_url = $base . '/s/' . $notif->space_slug . '/t/' . $notif->post_slug . '/';
				} elseif ( 'reply' === $notif->object_type && ! empty( $notif->reply_post_slug ) && ! empty( $notif->reply_space_slug ) ) {
					$notif_url = $base . '/s/' . $notif->reply_space_slug . '/t/' . $notif->reply_post_slug . '/#reply-' . (int) $notif->object_id;
				} elseif ( 'badge' === $notif->object_type ) {
					$badge_user = get_userdata( (int) $notif->user_id );
					if ( $badge_user ) {
						// Deep-link to the profile's badges section (#jt-badges anchor,
						// rendered by Pro's custom-badges extension) so an "earned a
						// badge" notification lands on the achievement, not the profile
						// top. Degrades to the profile root when no badges section is
						// present (e.g. free-only site).
						$notif_url = $base . '/u/' . rawurlencode( $badge_user->user_login ) . '/#jt-badges';
					}
				} elseif ( 'space' === $notif->object_type && 'join_request' === $notif->type ) {
					// Mirrors Notifier::build_join_request_url_for() so the link
					// the customer sees on this page matches the link in their
					// email — and routes to the right surface for who they are.
					$jr_space = \Jetonomy\Models\Space::find( (int) $notif->object_id );
					if ( $jr_space ) {
						if ( current_user_can( 'jetonomy_manage_spaces' ) || current_user_can( 'manage_options' ) ) {
							$notif_url = admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . (int) $jr_space->id . '&tab=join_requests' );
						} else {
							$notif_url = $base . '/s/' . $jr_space->slug . '/mod/';
						}
					}
				} else {
					// Anything else (e.g. Pro DM 'message'/'conversation') routes
					// through the shared resolver + its jetonomy_notification_deep_link
					// filter, so Pro object types link correctly instead of home.
					$resolved = \Jetonomy\notification_deep_link( (string) $notif->object_type, (int) $notif->object_id );
					if ( '' !== $resolved ) {
						$notif_url = $resolved;
					}
				}

				$row_class = 'jt-notif-item';
				if ( ! $notif->is_read ) {
					$row_class .= ' unread';
				}
				?>
				<div class="<?php echo esc_attr( $row_class ); ?>"
					data-jt-notif-id="<?php echo esc_attr( (int) $notif->id ); ?>"
					data-jt-notif-read="<?php echo esc_attr( $notif->is_read ? '1' : '0' ); ?>">
					<label class="jt-notif-item__select" aria-label="<?php esc_attr_e( 'Select notification', 'jetonomy' ); ?>">
						<input type="checkbox" class="jt-notif-cb" data-jt-notif-cb data-wp-on--change="actions.updateNotifSelection" value="<?php echo esc_attr( (int) $notif->id ); ?>">
					</label>
					<a href="<?php echo esc_url( $notif_url ); ?>" class="jt-notif-item__link">
						<span class="jt-avatar jt-avatar-sm jt-flex-shrink-0" aria-hidden="true">
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
							<span class="jt-notif-dot" aria-label="<?php esc_attr_e( 'Unread', 'jetonomy' ); ?>"></span>
						<?php endif; ?>
					</a>
					<div class="jt-notif-item__menu" data-jt-notif-menu>
						<button type="button" class="jt-notif-item__menu-trigger" data-jt-notif-menu-trigger data-wp-on--click="actions.toggleNotifMenu"
							aria-haspopup="true" aria-expanded="false"
							aria-label="<?php esc_attr_e( 'Notification actions', 'jetonomy' ); ?>">
							<?php jetonomy_echo_icon( 'more-horizontal', 18 ); ?>
						</button>
						<ul class="jt-notif-item__menu-list" role="menu" hidden>
							<?php if ( ! $notif->is_read ) : ?>
								<li role="none">
									<button type="button" role="menuitem" data-jt-notif-action="mark_read" data-wp-on--click="actions.markNotifRead">
										<?php esc_html_e( 'Mark as read', 'jetonomy' ); ?>
									</button>
								</li>
							<?php endif; ?>
							<li role="none">
								<button type="button" role="menuitem" data-jt-notif-action="delete" data-wp-on--click="actions.deleteNotif" class="jt-notif-menu-danger">
									<?php esc_html_e( 'Delete', 'jetonomy' ); ?>
								</button>
							</li>
						</ul>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		\Jetonomy\Template_Loader::partial(
			'pagination',
			array(
				'has_more'  => $has_more,
				'param_key' => 'paged',
				'target'    => '.jt-notif-list',
			)
		);
		?>
	<?php endif; ?>
</main>

<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => null ) ); ?>
</div>
