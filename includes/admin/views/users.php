<?php
/**
 * Admin users management view.
 *
 * Variables seeded by Admin::render_users() before include.
 *
 * @var int        $paged
 * @var int        $total
 * @var int        $total_pages
 * @var string     $search
 * @var int|string $filter_trust
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$trust_labels = array(
	0 => __( 'New', 'jetonomy' ),
	1 => __( 'Basic', 'jetonomy' ),
	2 => __( 'Member', 'jetonomy' ),
	3 => __( 'Regular', 'jetonomy' ),
	4 => __( 'Leader', 'jetonomy' ),
	5 => __( 'Elder', 'jetonomy' ),
);
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Users', 'jetonomy' ); ?></h1>

	<!-- Search & Filters -->
	<div class="tablenav top">
		<div class="alignleft actions">
			<form method="get" action="" class="jetonomy-user-filters">
				<input type="hidden" name="page" value="jetonomy-users">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search users...', 'jetonomy' ); ?>" class="regular-text">
				<select name="trust_level">
					<option value=""><?php esc_html_e( 'All Trust Levels', 'jetonomy' ); ?></option>
					<?php for ( $i = 0; $i <= 5; $i++ ) : ?>
						<option value="<?php echo absint( $i ); ?>" <?php selected( $filter_trust, (string) $i ); ?>><?php echo esc_html( $i . ' - ' . $trust_labels[ $i ] ); ?></option>
					<?php endfor; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'jetonomy' ); ?></button>
			</form>
		</div>
		<div class="alignright">
			<span class="displaying-num">
				<?php
				/* translators: %s: number of users */
				printf( esc_html( _n( '%s user', '%s users', $total, 'jetonomy' ) ), esc_html( number_format_i18n( $total ) ) );
				?>
			</span>
		</div>
	</div>

	<div class="jt-content-table-wrap">
		<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th class="column-username"><?php esc_html_e( 'Username', 'jetonomy' ); ?></th>
				<th class="column-display-name"><?php esc_html_e( 'Display Name', 'jetonomy' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Trust Level', 'jetonomy' ); ?></th>
				<th style="width:90px;"><?php esc_html_e( 'Reputation', 'jetonomy' ); ?></th>
				<th style="width:60px;"><?php esc_html_e( 'Posts', 'jetonomy' ); ?></th>
				<th style="width:70px;"><?php esc_html_e( 'Replies', 'jetonomy' ); ?></th>
				<th style="width:110px;"><?php esc_html_e( 'Joined', 'jetonomy' ); ?></th>
				<th style="width:110px;"><?php esc_html_e( 'Last Seen', 'jetonomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $users ) ) : ?>
				<?php
				jetonomy_admin_empty_state(
					array(
						'colspan' => 8,
						'icon'    => 'admin-users',
						'title'   => __( 'No users match these filters', 'jetonomy' ),
						'body'    => __( 'Try clearing a filter or broadening your search to see more members.', 'jetonomy' ),
					)
				);
				?>
			<?php else : ?>
				<?php foreach ( $users as $u ) : ?>
					<tr data-user-id="<?php echo absint( $u->user_id ); ?>">
						<td class="column-username">
							<?php echo get_avatar( $u->user_id, 24 ); ?>
							<strong><?php echo esc_html( $u->user_login ); ?></strong>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( get_edit_user_link( $u->user_id ) ); ?>"><?php esc_html_e( 'View Profile', 'jetonomy' ); ?></a> | </span>
								<span class="trust"><a href="#" class="jetonomy-change-trust-trigger" data-user-id="<?php echo absint( $u->user_id ); ?>" data-current="<?php echo absint( $u->trust_level ); ?>"><?php esc_html_e( 'Change Trust Level', 'jetonomy' ); ?></a> | </span>
								<span class="ban"><a href="#" class="jetonomy-ban-trigger" data-user-id="<?php echo absint( $u->user_id ); ?>" data-username="<?php echo esc_attr( $u->user_login ); ?>"><?php esc_html_e( 'Ban', 'jetonomy' ); ?></a> | </span>
								<span class="silence"><a href="#" class="jetonomy-silence-trigger" data-user-id="<?php echo absint( $u->user_id ); ?>"><?php esc_html_e( 'Silence', 'jetonomy' ); ?></a></span>
							</div>
						</td>
						<td class="column-display-name"><?php echo esc_html( $u->wp_display_name ?: $u->display_name ); ?></td>
						<td>
							<span class="jetonomy-trust-badge jetonomy-trust-badge--<?php echo absint( $u->trust_level ); ?>" data-user-id="<?php echo absint( $u->user_id ); ?>">
								<?php echo esc_html( ( $trust_labels[ (int) $u->trust_level ] ?? __( 'Unknown', 'jetonomy' ) ) . ' (' . $u->trust_level . ')' ); ?>
							</span>
						</td>
						<td><?php echo esc_html( number_format_i18n( $u->reputation ) ); ?></td>
						<td><?php echo absint( $u->post_count ); ?></td>
						<td><?php echo absint( $u->reply_count ); ?></td>
						<td>
							<?php echo esc_html( $u->user_registered ? human_time_diff( strtotime( $u->user_registered ), time() ) . ' ' . __( 'ago', 'jetonomy' ) : '&mdash;' ); ?>
						</td>
						<td>
							<?php echo esc_html( $u->last_seen_at ? human_time_diff( strtotime( $u->last_seen_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) : __( 'Never', 'jetonomy' ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</div><!-- /.jt-content-table-wrap -->

	<!-- Pagination -->
	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				$pagination = paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $total_pages,
						'type'    => 'array',
					)
				);
				if ( $pagination ) {
					echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $pagination ) ) . '</span>';
				}
				?>
			</div>
		</div>
	<?php endif; ?>

	<!-- Trust Level Change Inline Dropdown -->
	<div id="jetonomy-trust-dropdown" class="jetonomy-dropdown" style="display:none;">
		<select id="trust-level-select">
			<?php for ( $i = 0; $i <= 5; $i++ ) : ?>
				<option value="<?php echo absint( $i ); ?>"><?php echo esc_html( $i . ' - ' . $trust_labels[ $i ] ); ?></option>
			<?php endfor; ?>
		</select>
		<button type="button" class="button button-small button-primary" id="jetonomy-save-trust"><?php esc_html_e( 'Save', 'jetonomy' ); ?></button>
		<button type="button" class="button button-small jetonomy-dropdown-cancel"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></button>
		<input type="hidden" id="trust-user-id" value="">
	</div>

	<!-- Ban User Modal -->
	<div class="jetonomy-modal" id="jetonomy-ban-modal" style="display:none;">
		<div class="jetonomy-modal__overlay"></div>
		<div class="jetonomy-modal__content">
			<h2><?php esc_html_e( 'Ban User', 'jetonomy' ); ?></h2>
			<input type="hidden" id="ban-user-id" value="">
			<p id="ban-user-label"></p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="ban-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label></th>
					<td>
						<select id="ban-type">
							<option value="global_ban"><?php esc_html_e( 'Global Ban', 'jetonomy' ); ?></option>
							<option value="silence"><?php esc_html_e( 'Silence', 'jetonomy' ); ?></option>
							<option value="space_ban"><?php esc_html_e( 'Space Ban', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ban-reason"><?php esc_html_e( 'Reason', 'jetonomy' ); ?></label></th>
					<td><input type="text" id="ban-reason" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ban-duration"><?php esc_html_e( 'Duration', 'jetonomy' ); ?></label></th>
					<td>
						<select id="ban-duration">
							<option value="permanent"><?php esc_html_e( 'Permanent', 'jetonomy' ); ?></option>
							<option value="1d"><?php esc_html_e( '1 Day', 'jetonomy' ); ?></option>
							<option value="7d"><?php esc_html_e( '7 Days', 'jetonomy' ); ?></option>
							<option value="30d"><?php esc_html_e( '30 Days', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<p class="jetonomy-modal__actions">
				<button type="button" class="button button-primary" id="jetonomy-confirm-ban"><?php esc_html_e( 'Ban User', 'jetonomy' ); ?></button>
				<button type="button" class="button jetonomy-modal-close"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></button>
				<span class="spinner"></span>
			</p>
		</div>
	</div>
</div>
<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
<div class="jt-pro-upsell">
	<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
	<h4><?php esc_html_e( 'Custom Badges & Advanced Moderation', 'jetonomy' ); ?></h4>
	<p><?php esc_html_e( 'Auto-award badges based on activity, create custom fields for profiles, and set up keyword-based auto-moderation rules.', 'jetonomy' ); ?></p>
	<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
	&nbsp;
	<a href="https://store.wbcomdesigns.com/jetonomy/docs/" class="button button-link" target="_blank"><?php esc_html_e( 'View Docs', 'jetonomy' ); ?></a>
</div>
<?php endif; ?>
