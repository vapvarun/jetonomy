<?php
/**
 * Admin dashboard view.
 *
 * Variables seeded by Admin::render_dashboard() before include.
 *
 * @var array<string,mixed> $stats
 * @var string              $base_slug
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

/*
 * Show the first-run banner only when the site really has nothing yet.
 *
 * The wizard flag alone is not enough: a site whose content arrived by import,
 * migration, CLI or REST never ran the wizard, and the flag records whether the
 * WIZARD ran, not whether the community exists.
 */
$jt_has_content = ( (int) ( $stats['active_spaces'] ?? 0 ) > 0 )
	|| ( (int) ( $stats['total_posts'] ?? 0 ) > 0 );

// Printed after the Dashboard reloads from a demo-data import/removal, so the
// owner sees the outcome instead of a silent page refresh.
$jt_demo_done = sanitize_key( wp_unslash( $_GET['jt_demo'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
if ( in_array( $jt_demo_done, array( 'imported', 'removed' ), true ) ) :
	?>
<div class="notice notice-success is-dismissible"><p><?php echo esc_html( 'imported' === $jt_demo_done ? __( 'Demo data imported.', 'jetonomy' ) : __( 'All demo data has been removed.', 'jetonomy' ) ); ?></p></div>
	<?php
endif;

if ( ! get_option( 'jetonomy_setup_complete' ) && ! $jt_has_content ) :
	?>
<div class="notice notice-info" style="padding:20px;border-left-color:var(--jt-accent,#3B82F6);">
	<h3 style="margin:0 0 8px;"><?php esc_html_e( 'Welcome to Jetonomy!', 'jetonomy' ); ?></h3>
	<p><?php /* translators: %s: space label, lowercase (e.g. "space"). */ echo esc_html( sprintf( __( 'Complete the setup wizard to create your first community %s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', false, true ) ) ); ?></p>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-setup' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Run Setup Wizard', 'jetonomy' ); ?></a>
</div>
	<?php
endif;

$stat_cards = array(
	'total_posts'   => array(
		'label' => __( 'Total Posts', 'jetonomy' ),
		'icon'  => 'dashicons-admin-post',
	),
	'total_replies' => array(
		/* translators: %s: reply label, plural (e.g. "Replies"). */
		'label' => sprintf( __( 'Total %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true ) ),
		'icon'  => 'dashicons-format-chat',
	),
	'active_spaces' => array(
		/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
		'label' => sprintf( __( 'Active %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', true ) ),
		'icon'  => 'dashicons-networking',
	),
	'users'         => array(
		'label' => __( 'Registered Users', 'jetonomy' ),
		'icon'  => 'dashicons-admin-users',
	),
	'pending_flags' => array(
		'label' => __( 'Pending Flags', 'jetonomy' ),
		'icon'  => 'dashicons-flag',
	),
	'posts_today'   => array(
		'label' => __( 'Posts Today', 'jetonomy' ),
		'icon'  => 'dashicons-calendar-alt',
	),
);
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
					<?php
					// A warning-styled "Pending Flags" count is an alert with no
					// action — give the owner a one-click path to the queue.
					if ( 'pending_flags' === $key && $stats[ $key ] > 0 ) :
						?>
						<a class="jetonomy-stat-card__cta" href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-moderation' ) ); ?>"><?php esc_html_e( 'Review flags →', 'jetonomy' ); ?></a>
					<?php endif; ?>
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
				<?php
				jetonomy_admin_empty_state(
					array(
						'variant' => 'compact',
						'icon'    => 'chart-line',
						'title'   => __( 'No activity yet', 'jetonomy' ),
						/* translators: %s: member label (e.g. "Member"). */
						'body'    => sprintf( __( '%s actions will appear here as your community grows.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member' ) ),
					)
				);
				?>
			<?php else : ?>
				<table class="wp-list-table widefat striped"><!-- jetonomy-audit-table-ok: 2-col info table; card CSS enforces fixed layout + wrap-anywhere -->
					<thead>
						<tr>
							<th scope="col" class="column-primary"><?php esc_html_e( 'User', 'jetonomy' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'jetonomy' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Object', 'jetonomy' ); ?></th>
							<th scope="col"><?php esc_html_e( 'When', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$jt_objects = \Jetonomy\Author::objects_of( $recent_activity );
						foreach ( $recent_activity as $activity ) :
							$actor       = get_userdata( $activity->user_id );
							$jt_masked   = \Jetonomy\Author::hides_actor( (int) $activity->user_id, $jt_objects[ $activity->object_type . ':' . (int) $activity->object_id ] ?? null );
							$action_code = $activity->action;
							$object_type = $activity->object_type;
							$object_id   = (int) $activity->object_id;

							$action_label = \Jetonomy\Admin\Admin::action_label( (string) $action_code );

							// --- Dot color by action category ---
							$create_actions   = array( 'created_post', 'created_reply', 'joined_space' );
							$vote_actions     = array( 'voted' );
							$moderate_actions = array( 'moderated_approve', 'moderated_trash', 'moderated_spam' );

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
										$post_url    = admin_url( 'admin.php?page=jetonomy-content&post_id=' . (int) $reply_row->post_id );
										$object_html = sprintf(
											/* translators: 1: reply label (e.g. "Reply"). 2: linked post title. */
											__( '%1$s on %2$s', 'jetonomy' ),
											esc_html( \Jetonomy\jetonomy_label( 'reply' ) ),
											'<a href="' . esc_url( $post_url ) . '">' . esc_html( $parent_post->title ) . '</a>'
										);
									} else {
										/* translators: 1: reply label, lowercase (e.g. "reply"). 2: reply ID. */
										$object_html = esc_html( sprintf( __( '%1$s #%2$d', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', false, true ), $object_id ) );
									}
								} else {
									/* translators: 1: reply label, lowercase (e.g. "reply"). 2: reply ID. */
									$object_html = esc_html( sprintf( __( '%1$s #%2$d', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', false, true ), $object_id ) );
								}
							} elseif ( 'space' === $object_type ) {
								$space_row   = \Jetonomy\Models\Space::find( $object_id );
								$object_html = $space_row
									? esc_html( $space_row->title )
									/* translators: 1: reply label, lowercase (e.g. "reply"). 2: reply ID. */
									: esc_html( sprintf( __( '%1$s #%2$d', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', false, true ), $object_id ) );
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
							<?php
							// column-primary + data-colname put this table on the same
							// mobile collapse contract as every other admin list, and
							// collapse it around WHO acted rather than leaving the
							// phone with four unlabelled cells.
							?>
							<tr>
								<td class="column-primary" data-colname="<?php esc_attr_e( 'User', 'jetonomy' ); ?>">
									<strong><?php echo esc_html( $jt_masked ? __( 'Anonymous', 'jetonomy' ) : ( $actor ? $actor->display_name : __( 'Unknown', 'jetonomy' ) ) ); ?></strong>
									<button type="button" class="toggle-row" aria-expanded="false"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'jetonomy' ); ?></span></button>
								</td>
								<td data-colname="<?php esc_attr_e( 'Action', 'jetonomy' ); ?>">
									<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background-color:<?php echo esc_attr( $dot_color ); ?>;margin-right:6px;vertical-align:middle;" aria-hidden="true"></span><?php echo esc_html( $action_label ); ?>
								</td>
								<td data-colname="<?php esc_attr_e( 'Object', 'jetonomy' ); ?>"><?php echo wp_kses( $object_html, array( 'a' => array( 'href' => array() ) ) ); ?></td>
								<td data-colname="<?php esc_attr_e( 'When', 'jetonomy' ); ?>"><?php echo esc_html( human_time_diff( strtotime( $activity->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) ); ?></td>
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
						<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( 'Create %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space' ) ) ); ?>
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

			<?php
			// Both directions live on one card: an owner who skipped sample data in
			// setup, or removed it, can bring it back here.
			$jt_demo = \Jetonomy\Admin\Ajax\Demo_Seeder::summary();
			?>
			<div class="jetonomy-dashboard-card<?php echo $jt_demo['active'] ? ' jt-card--warning' : ''; ?>" id="jt-demo-card" data-active="<?php echo $jt_demo['active'] ? '1' : '0'; ?>">
				<?php if ( $jt_demo['active'] ) : ?>
					<h2><?php esc_html_e( 'Demo Data Active', 'jetonomy' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Sample content is still present. Remove it when you\'re ready; your own content is not affected.', 'jetonomy' ); ?>
					</p>
					<button type="button" class="button button--danger" id="jetonomy-cleanup-demo">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php esc_html_e( 'Remove All Demo Data', 'jetonomy' ); ?>
					</button>
				<?php else : ?>
					<h2><?php esc_html_e( 'Demo Data', 'jetonomy' ); ?></h2>
					<p class="description">
						<?php /* translators: 1: category label, plural, lowercase (e.g. "categories"). 2: space label, plural, lowercase (e.g. "spaces"). 3: member label, plural, lowercase (e.g. "members"). 4: topic label, plural, lowercase (e.g. "topics"). */ echo esc_html( sprintf( __( 'Fill the community with sample %1$s, %2$s, %3$s and %4$s to try things out. You can remove it again in one click.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', true, true ), \Jetonomy\jetonomy_label( 'space', true, true ), \Jetonomy\jetonomy_label( 'member', true, true ), \Jetonomy\jetonomy_label( 'topic', true, true ) ) ); ?>
					</p>
					<button type="button" class="button" id="jetonomy-import-demo">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Import Demo Data', 'jetonomy' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<!-- System Info -->
			<div class="jetonomy-dashboard-card">
				<h2><?php esc_html_e( 'System Info', 'jetonomy' ); ?></h2>
				<table class="widefat"><!-- jetonomy-audit-table-ok: 2-col info table; card CSS enforces fixed layout + wrap-anywhere -->
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

			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) && null !== ( $pulse ?? null ) ) : ?>
				<!-- Analytics teaser: REAL 7-day numbers (cached 1h) + Pro upsell.
					Replaces the old static card so the widget itself demonstrates
					what Pro's full analytics does. -->
				<div class="jt-pro-upsell-card jt-pulse-card">
					<h3><?php esc_html_e( 'This week in your community', 'jetonomy' ); ?> <span class="jt-pro-badge"><?php esc_html_e( 'PREVIEW', 'jetonomy' ); ?></span></h3>
					<div class="jt-pulse-grid">
						<div class="jt-pulse-stat">
							<span class="jt-pulse-n"><?php echo esc_html( number_format_i18n( $pulse['posts'] ) ); ?></span>
							<span class="jt-pulse-l"><?php /* translators: %s: topic label, singular or plural to match the number (e.g. "topic" / "topics"). */ echo esc_html( sprintf( _n( 'new %s', 'new %s', (int) $pulse['posts'], 'jetonomy' ), \Jetonomy\count_noun( (int) $pulse['posts'], 'topic' ) ) ); ?></span>
						</div>
						<div class="jt-pulse-stat">
							<span class="jt-pulse-n"><?php echo esc_html( number_format_i18n( $pulse['replies'] ) ); ?></span>
							<span class="jt-pulse-l"><?php echo esc_html( \Jetonomy\count_noun( (int) $pulse['replies'], 'reply' ) ); ?></span>
						</div>
						<div class="jt-pulse-stat">
							<span class="jt-pulse-n"><?php echo esc_html( number_format_i18n( $pulse['contributors'] ) ); ?></span>
							<span class="jt-pulse-l"><?php /* translators: %s: member label, singular or plural to match the number (e.g. "member" / "members"). */ echo esc_html( sprintf( _n( 'active %s', 'active %s', (int) $pulse['contributors'], 'jetonomy' ), \Jetonomy\count_noun( (int) $pulse['contributors'], 'member' ) ) ); ?></span>
						</div>
					</div>
					<?php if ( ! empty( $pulse['top_space'] ) ) : ?>
						<p class="jt-pulse-top">
							<?php
							printf(
								/* translators: 1: space title, 2: number of new topics, 3: topic label, singular or plural to match the number. */
								esc_html( _n( 'Most active: %1$s (%2$s new %3$s)', 'Most active: %1$s (%2$s new %3$s)', (int) $pulse['top_space_posts'], 'jetonomy' ) ),
								'<strong>' . esc_html( $pulse['top_space'] ) . '</strong>',
								esc_html( number_format_i18n( $pulse['top_space_posts'] ) ),
								esc_html( \Jetonomy\count_noun( (int) $pulse['top_space_posts'], 'topic' ) )
							);
							?>
						</p>
					<?php endif; ?>
					<p><?php esc_html_e( 'Pro adds full history, engagement and moderation analytics, top contributors, and CSV export.', 'jetonomy' ); ?></p>
					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button button-primary" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
					<a class="jt-pulse-dismiss" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'jetonomy_dismiss_pulse', '1' ), 'jetonomy_dismiss_pulse' ) ); ?>"><?php esc_html_e( 'Dismiss', 'jetonomy' ); ?></a>
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
