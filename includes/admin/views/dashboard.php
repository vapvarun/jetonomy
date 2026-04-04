<?php
/**
 * Admin dashboard view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

if ( ! get_option( 'jetonomy_setup_complete' ) ) : ?>
<div class="notice notice-info" style="padding:20px;border-left-color:var(--jt-accent,#3B82F6);">
	<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Welcome to Jetonomy!', 'jetonomy' ); ?></h3>
	<p><?php esc_html_e( 'Complete the setup wizard to create your first community space.', 'jetonomy' ); ?></p>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-setup' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Run Setup Wizard', 'jetonomy' ); ?></a>
</div>
	<?php
endif;

$stat_cards = [
	'total_posts'   => [
		'label' => __( 'Total Posts', 'jetonomy' ),
		'icon'  => 'dashicons-admin-post',
	],
	'total_replies' => [
		'label' => __( 'Total Replies', 'jetonomy' ),
		'icon'  => 'dashicons-format-chat',
	],
	'active_spaces' => [
		'label' => __( 'Active Spaces', 'jetonomy' ),
		'icon'  => 'dashicons-networking',
	],
	'users'         => [
		'label' => __( 'Registered Users', 'jetonomy' ),
		'icon'  => 'dashicons-admin-users',
	],
	'pending_flags' => [
		'label' => __( 'Pending Flags', 'jetonomy' ),
		'icon'  => 'dashicons-flag',
	],
	'posts_today'   => [
		'label' => __( 'Posts Today', 'jetonomy' ),
		'icon'  => 'dashicons-calendar-alt',
	],
];
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Jetonomy Dashboard', 'jetonomy' ); ?></h1>

	<!-- Stat Cards -->
	<div class="jetonomy-stat-cards">
		<?php foreach ( $stat_cards as $key => $card ) : ?>
			<div class="jetonomy-stat-card<?php echo esc_attr( 'pending_flags' === $key && $stats[ $key ] > 0 ? ' jetonomy-stat-card--warning' : '' ); ?>">
				<div class="jetonomy-stat-card__icon">
					<span class="dashicons <?php echo esc_attr( $card['icon'] ); ?>"></span>
				</div>
				<div class="jetonomy-stat-card__content">
					<div class="jetonomy-stat-card__value"><?php echo esc_html( number_format_i18n( $stats[ $key ] ) ); ?></div>
					<div class="jetonomy-stat-card__label"><?php echo esc_html( $card['label'] ); ?></div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<?php
	/**
	 * Fires after the dashboard stat cards.
	 *
	 * @param array $stats Dashboard statistics.
	 */
	do_action( 'jetonomy_admin_dashboard_after_stats', $stats );
	?>

	<div class="jetonomy-dashboard-grid">
		<!-- Recent Activity -->
		<div class="jetonomy-dashboard-card">
			<h2><?php esc_html_e( 'Recent Activity', 'jetonomy' ); ?></h2>
			<?php if ( empty( $recent_activity ) ) : ?>
				<p class="jetonomy-empty-state"><?php esc_html_e( 'No activity recorded yet.', 'jetonomy' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Action', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Object', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'When', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $recent_activity as $activity ) :
							$actor       = get_userdata( $activity->user_id );
							$action_code = $activity->action;
							$object_type = $activity->object_type;
							$object_id   = (int) $activity->object_id;

							// --- Action label mapping ---
							$action_labels = [
								'created_post'        => __( 'Created a post', 'jetonomy' ),
								'created_reply'       => __( 'Replied', 'jetonomy' ),
								'voted'               => __( 'Voted', 'jetonomy' ),
								'trust_level_changed' => __( 'Trust level changed', 'jetonomy' ),
								'moderated_approve'   => __( 'Approved content', 'jetonomy' ),
								'moderated_trash'     => __( 'Trashed content', 'jetonomy' ),
								'moderated_spam'      => __( 'Marked as spam', 'jetonomy' ),
								'reputation_changed'  => __( 'Reputation changed', 'jetonomy' ),
								'joined_space'        => __( 'Joined a space', 'jetonomy' ),
							];

							$action_label = isset( $action_labels[ $action_code ] )
								? $action_labels[ $action_code ]
								: ucwords( str_replace( '_', ' ', $action_code ) );

							// --- Dot color by action category ---
							$create_actions   = [ 'created_post', 'created_reply', 'joined_space' ];
							$vote_actions     = [ 'voted' ];
							$moderate_actions = [ 'moderated_approve', 'moderated_trash', 'moderated_spam' ];

							if ( in_array( $action_code, $create_actions, true ) ) {
								$dot_color = '#22c55e'; // green
							} elseif ( in_array( $action_code, $vote_actions, true ) ) {
								$dot_color = '#3b82f6'; // blue
							} elseif ( in_array( $action_code, $moderate_actions, true ) ) {
								$dot_color = '#f97316'; // orange
							} else {
								$dot_color = '#94a3b8'; // gray
							}

							// --- Object reference ---
							$object_html = '';

							if ( 'post' === $object_type ) {
								$post_row = \Jetonomy\Models\Post::find( $object_id );
								if ( $post_row ) {
									$post_url    = admin_url( 'admin.php?page=jetonomy-content&post_id=' . $object_id );
									$object_html = '<a href="' . esc_url( $post_url ) . '">' . esc_html( $post_row->title ) . '</a>';
								} else {
									/* translators: %d: post ID */
									$object_html = esc_html( sprintf( __( 'post #%d', 'jetonomy' ), $object_id ) );
								}
							} elseif ( 'reply' === $object_type ) {
								$reply_row = \Jetonomy\Models\Reply::find( $object_id );
								if ( $reply_row ) {
									$parent_post = \Jetonomy\Models\Post::find( (int) $reply_row->post_id );
									if ( $parent_post ) {
										$post_url = admin_url( 'admin.php?page=jetonomy-content&post_id=' . (int) $reply_row->post_id );
										/* translators: %s: post title */
										$object_html = sprintf(
											__( 'Reply on %s', 'jetonomy' ),
											'<a href="' . esc_url( $post_url ) . '">' . esc_html( $parent_post->title ) . '</a>'
										);
									} else {
										/* translators: %d: reply ID */
										$object_html = esc_html( sprintf( __( 'reply #%d', 'jetonomy' ), $object_id ) );
									}
								} else {
									/* translators: %d: reply ID */
									$object_html = esc_html( sprintf( __( 'reply #%d', 'jetonomy' ), $object_id ) );
								}
							} elseif ( 'space' === $object_type ) {
								$space_row   = \Jetonomy\Models\Space::find( $object_id );
								$object_html = $space_row
									? esc_html( $space_row->title )
									/* translators: %d: space ID */
									: esc_html( sprintf( __( 'space #%d', 'jetonomy' ), $object_id ) );
							} elseif ( 'user' === $object_type ) {
								$obj_user    = get_userdata( $object_id );
								$object_html = $obj_user
									? esc_html( $obj_user->display_name )
									/* translators: %d: user ID */
									: esc_html( sprintf( __( 'user #%d', 'jetonomy' ), $object_id ) );
							} else {
								$object_html = esc_html( $object_type . ' #' . $object_id );
							}
							?>
							<tr>
								<td><?php echo esc_html( $actor ? $actor->display_name : __( 'Unknown', 'jetonomy' ) ); ?></td>
								<td>
									<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background-color:<?php echo esc_attr( $dot_color ); ?>;margin-right:6px;vertical-align:middle;" aria-hidden="true"></span><?php echo esc_html( $action_label ); ?>
								</td>
								<td><?php echo wp_kses( $object_html, [ 'a' => [ 'href' => [] ] ] ); ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $activity->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Quick Actions & System Info -->
		<div class="jetonomy-dashboard-sidebar">
			<!-- Quick Actions -->
			<div class="jetonomy-dashboard-card">
				<h2><?php esc_html_e( 'Quick Actions', 'jetonomy' ); ?></h2>
				<div class="jetonomy-quick-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces&action=new' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-plus-alt2"></span>
						<?php esc_html_e( 'Create Space', 'jetonomy' ); ?>
					</a>
					<a href="<?php echo esc_url( home_url( '/' . $base_slug . '/' ) ); ?>" class="button" target="_blank">
						<span class="dashicons dashicons-external"></span>
						<?php esc_html_e( 'View Community', 'jetonomy' ); ?>
					</a>
					<button type="button" class="button" id="jetonomy-flush-rules">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Flush Rules', 'jetonomy' ); ?>
					</button>
				</div>
			</div>

			<?php if ( get_option( 'jetonomy_demo_data' ) ) : ?>
			<!-- Demo Data Cleanup -->
			<div class="jetonomy-dashboard-card jt-card--warning" id="jt-demo-card">
				<h2><?php esc_html_e( 'Demo Data Active', 'jetonomy' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Sample content from the setup wizard is still present. Remove it when you\'re ready.', 'jetonomy' ); ?>
				</p>
				<button type="button" class="button button--danger" id="jetonomy-cleanup-demo">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Remove All Demo Data', 'jetonomy' ); ?>
				</button>
				<script>
				document.getElementById('jetonomy-cleanup-demo').addEventListener('click', function() {
					if (!confirm(<?php echo wp_json_encode( __( 'Delete all sample categories, spaces, posts, and replies from the setup wizard? Your own content is not affected.', 'jetonomy' ) ); ?>)) return;
					var btn = this;
					btn.disabled = true;
					btn.textContent = <?php echo wp_json_encode( __( 'Removing...', 'jetonomy' ) ); ?>;
					fetch(ajaxurl, {
						method: 'POST',
						headers: {'Content-Type': 'application/x-www-form-urlencoded'},
						body: new URLSearchParams({action:'jetonomy_cleanup_sample_data', nonce: jetonomyAdmin.nonce}),
						credentials: 'same-origin'
					})
					.then(function(r){return r.json();})
					.then(function(res){
						if (res.success) {
							document.getElementById('jt-demo-card').remove();
						} else {
							alert(res.data || 'Failed');
							btn.disabled = false;
						}
					});
				});
				</script>
			</div>
			<?php endif; ?>

			<!-- System Info -->
			<div class="jetonomy-dashboard-card">
				<h2><?php esc_html_e( 'System Info', 'jetonomy' ); ?></h2>
				<table class="widefat">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Plugin Version', 'jetonomy' ); ?></strong></td>
							<td><?php echo esc_html( JETONOMY_VERSION ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'DB Version', 'jetonomy' ); ?></strong></td>
							<td><?php echo esc_html( get_option( 'jetonomy_db_version', JETONOMY_DB_VERSION ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'PHP Version', 'jetonomy' ); ?></strong></td>
							<td><?php echo esc_html( PHP_VERSION ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'WordPress Version', 'jetonomy' ); ?></strong></td>
							<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Base URL', 'jetonomy' ); ?></strong></td>
							<td><code>/<?php echo esc_html( $base_slug ); ?>/</code></td>
						</tr>
					</tbody>
				</table>
			</div>

			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<!-- Pro Upsell: Analytics -->
				<div class="jt-pro-upsell-card">
					<h3><?php esc_html_e( 'Analytics', 'jetonomy' ); ?> <span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span></h3>
					<p><?php esc_html_e( 'Engagement graphs, user growth, top spaces, and more.', 'jetonomy' ); ?></p>
					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * Fires to render additional dashboard widgets.
			 * Pro hooks analytics and other widgets here.
			 */
			do_action( 'jetonomy_admin_dashboard_widgets' );
			?>
		</div>
	</div>
</div>
