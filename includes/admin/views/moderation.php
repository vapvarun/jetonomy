<?php
/**
 * Admin moderation view.
 *
 * Variables seeded by Admin::render_moderation() before include — declared here
 * for static analysis (PHPStan does not follow include-from-method scope).
 *
 * @var int      $per_page
 * @var int      $paged_posts
 * @var int      $paged_replies
 * @var int      $paged_flags
 * @var int      $paged_banned
 * @var int      $total_posts
 * @var int      $total_replies
 * @var int      $total_flags
 * @var int      $total_banned
 * @var object[] $pending_posts
 * @var object[] $pending_replies
 * @var object[] $pending_flags
 * @var object[] $banned_users
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$active_tab = sanitize_text_field( $_GET['tab'] ?? 'posts' );
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Moderation', 'jetonomy' ); ?></h1>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-moderation&tab=posts' ) ); ?>" class="nav-tab <?php echo esc_attr( 'posts' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php printf( esc_html__( 'Pending Posts (%d)', 'jetonomy' ), absint( $total_posts ) ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-moderation&tab=replies' ) ); ?>" class="nav-tab <?php echo esc_attr( 'replies' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php printf( esc_html__( 'Pending Replies (%d)', 'jetonomy' ), absint( $total_replies ) ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-moderation&tab=flags' ) ); ?>" class="nav-tab <?php echo esc_attr( 'flags' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php printf( esc_html__( 'Flags (%d)', 'jetonomy' ), absint( $total_flags ) ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-moderation&tab=banned' ) ); ?>" class="nav-tab <?php echo esc_attr( 'banned' === $active_tab ? 'nav-tab-active' : '' ); ?>">
			<?php printf( esc_html__( 'Banned Users (%d)', 'jetonomy' ), absint( $total_banned ) ); ?>
		</a>
		<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
			<a class="nav-tab disabled" title="<?php esc_attr_e( 'Pro required', 'jetonomy' ); ?>"><?php esc_html_e( 'Auto-Rules', 'jetonomy' ); ?> <span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span></a>
		<?php endif; ?>
		<?php
		/**
		 * Fires to render additional moderation tabs.
		 * Pro hooks "Auto-Rules" tab here.
		 *
		 * @param string $active_tab Current active tab slug.
		 */
		do_action( 'jetonomy_admin_moderation_tabs', $active_tab );
		?>
	</nav>

	<?php if ( 'posts' === $active_tab ) : ?>
		<!-- Pending Posts -->
		<div class="jetonomy-tab-content">
			<?php if ( empty( $pending_posts ) ) : ?>
				<div class="jetonomy-empty-state">
					<span class="dashicons dashicons-yes-alt"></span>
					<p><?php esc_html_e( 'No pending posts. The queue is clear.', 'jetonomy' ); ?></p>
				</div>
			<?php else : ?>
				<div class="jt-content-table-wrap">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th class="column-title"><?php esc_html_e( 'Title', 'jetonomy' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Author', 'jetonomy' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Space', 'jetonomy' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
							<th style="width:200px;"><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $pending_posts as $p ) :
							$author = get_userdata( $p->author_id );
							?>
							<tr data-type="post" data-id="<?php echo absint( $p->id ); ?>">
								<td>
									<strong><?php echo esc_html( $p->title ); ?></strong>
									<?php if ( $p->content ) : ?>
										<p class="description jetonomy-content-preview"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $p->content ), 20 ) ); ?></p>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $author ? $author->display_name : __( 'Unknown', 'jetonomy' ) ); ?></td>
								<td><?php echo esc_html( $p->space_title ?? '&mdash;' ); ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $p->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) ); ?></td>
								<td class="jetonomy-mod-actions">
									<button type="button" class="button button-primary button-small jetonomy-moderate-btn" data-action="approve" data-type="post" data-id="<?php echo absint( $p->id ); ?>"><?php esc_html_e( 'Approve', 'jetonomy' ); ?></button>
									<button type="button" class="button button-small jetonomy-moderate-btn" data-action="spam" data-type="post" data-id="<?php echo absint( $p->id ); ?>"><?php esc_html_e( 'Spam', 'jetonomy' ); ?></button>
									<button type="button" class="button button-small button-link-delete jetonomy-moderate-btn" data-action="trash" data-type="post" data-id="<?php echo absint( $p->id ); ?>"><?php esc_html_e( 'Trash', 'jetonomy' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div><!-- /.jt-content-table-wrap -->
				<?php if ( (int) ceil( $total_posts / $per_page ) > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							$_first = ( $paged_posts - 1 ) * $per_page + 1;
							$_last  = min( $paged_posts * $per_page, $total_posts );
							printf( esc_html__( '%1$s&#8211;%2$s of %3$s', 'jetonomy' ), esc_html( number_format_i18n( $_first ) ), esc_html( number_format_i18n( $_last ) ), esc_html( number_format_i18n( $total_posts ) ) );
							?>
						</span>
						<?php
						$plinks = paginate_links(
							[
								'base'    => add_query_arg(
									[
										'tab'         => 'posts',
										'paged_posts' => '%#%',
									]
								),
								'format'  => '',
								'current' => $paged_posts,
								'total'   => (int) ceil( $total_posts / $per_page ),
								'type'    => 'array',
							]
						);
						if ( $plinks ) {
							echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $plinks ) ) . '</span>'; }
						?>
					</div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

	<?php elseif ( 'replies' === $active_tab ) : ?>
		<!-- Pending Replies -->
		<div class="jetonomy-tab-content">
			<?php if ( empty( $pending_replies ) ) : ?>
				<div class="jetonomy-empty-state">
					<span class="dashicons dashicons-yes-alt"></span>
					<p><?php esc_html_e( 'No pending replies. The queue is clear.', 'jetonomy' ); ?></p>
				</div>
			<?php else : ?>
				<div class="jt-content-table-wrap">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th class="column-content"><?php esc_html_e( 'Content', 'jetonomy' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Author', 'jetonomy' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Parent Post', 'jetonomy' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
							<th style="width:200px;"><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $pending_replies as $r ) :
							$author = get_userdata( $r->author_id );
							?>
							<tr data-type="reply" data-id="<?php echo absint( $r->id ); ?>">
								<td>
									<p class="description jetonomy-content-preview"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $r->content ?? '' ), 30 ) ); ?></p>
								</td>
								<td><?php echo esc_html( $author ? $author->display_name : __( 'Unknown', 'jetonomy' ) ); ?></td>
								<td><?php echo esc_html( $r->post_title ?? '#' . $r->post_id ); ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $r->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) ); ?></td>
								<td class="jetonomy-mod-actions">
									<button type="button" class="button button-primary button-small jetonomy-moderate-btn" data-action="approve" data-type="reply" data-id="<?php echo absint( $r->id ); ?>"><?php esc_html_e( 'Approve', 'jetonomy' ); ?></button>
									<button type="button" class="button button-small jetonomy-moderate-btn" data-action="spam" data-type="reply" data-id="<?php echo absint( $r->id ); ?>"><?php esc_html_e( 'Spam', 'jetonomy' ); ?></button>
									<button type="button" class="button button-small button-link-delete jetonomy-moderate-btn" data-action="trash" data-type="reply" data-id="<?php echo absint( $r->id ); ?>"><?php esc_html_e( 'Trash', 'jetonomy' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div><!-- /.jt-content-table-wrap -->
				<?php if ( (int) ceil( $total_replies / $per_page ) > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							$_first = ( $paged_replies - 1 ) * $per_page + 1;
							$_last  = min( $paged_replies * $per_page, $total_replies );
							printf( esc_html__( '%1$s&#8211;%2$s of %3$s', 'jetonomy' ), esc_html( number_format_i18n( $_first ) ), esc_html( number_format_i18n( $_last ) ), esc_html( number_format_i18n( $total_replies ) ) );
							?>
						</span>
						<?php
						$plinks = paginate_links(
							[
								'base'    => add_query_arg(
									[
										'tab'           => 'replies',
										'paged_replies' => '%#%',
									]
								),
								'format'  => '',
								'current' => $paged_replies,
								'total'   => (int) ceil( $total_replies / $per_page ),
								'type'    => 'array',
							]
						);
						if ( $plinks ) {
							echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $plinks ) ) . '</span>'; }
						?>
					</div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

	<?php elseif ( 'flags' === $active_tab ) : ?>
		<!-- Flags -->
		<div class="jetonomy-tab-content">
			<?php if ( empty( $pending_flags ) ) : ?>
				<div class="jetonomy-empty-state">
					<span class="dashicons dashicons-yes-alt"></span>
					<p><?php esc_html_e( 'No pending flags. Everything looks good.', 'jetonomy' ); ?></p>
				</div>
			<?php else : ?>
				<div class="jt-content-table-wrap">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Object', 'jetonomy' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Reason', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Description', 'jetonomy' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Reporter', 'jetonomy' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
							<th style="width:180px;"><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $pending_flags as $f ) :
							$reporter = get_userdata( $f->reporter_id );
							?>
							<tr data-flag-id="<?php echo absint( $f->id ); ?>">
								<td>
									<code><?php echo esc_html( $f->object_type . ' #' . $f->object_id ); ?></code>
								</td>
								<td>
									<span class="jetonomy-badge jetonomy-badge--flag-<?php echo esc_attr( $f->reason ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $f->reason ) ) ); ?></span>
								</td>
								<td><?php echo esc_html( $f->description ?: '&mdash;' ); ?></td>
								<td><?php echo esc_html( $reporter ? $reporter->display_name : __( 'Unknown', 'jetonomy' ) ); ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $f->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) ); ?></td>
								<td class="jetonomy-mod-actions">
									<button type="button" class="button button-small button-link-delete jetonomy-resolve-flag" data-flag-id="<?php echo absint( $f->id ); ?>" data-resolution="valid"><?php esc_html_e( 'Valid (Trash)', 'jetonomy' ); ?></button>
									<button type="button" class="button button-small jetonomy-resolve-flag" data-flag-id="<?php echo absint( $f->id ); ?>" data-resolution="dismissed"><?php esc_html_e( 'Dismiss', 'jetonomy' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div><!-- /.jt-content-table-wrap -->
				<?php if ( (int) ceil( $total_flags / $per_page ) > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							$_first = ( $paged_flags - 1 ) * $per_page + 1;
							$_last  = min( $paged_flags * $per_page, $total_flags );
							printf( esc_html__( '%1$s&#8211;%2$s of %3$s', 'jetonomy' ), esc_html( number_format_i18n( $_first ) ), esc_html( number_format_i18n( $_last ) ), esc_html( number_format_i18n( $total_flags ) ) );
							?>
						</span>
						<?php
						$plinks = paginate_links(
							[
								'base'    => add_query_arg(
									[
										'tab'         => 'flags',
										'paged_flags' => '%#%',
									]
								),
								'format'  => '',
								'current' => $paged_flags,
								'total'   => (int) ceil( $total_flags / $per_page ),
								'type'    => 'array',
							]
						);
						if ( $plinks ) {
							echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $plinks ) ) . '</span>'; }
						?>
					</div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

	<?php elseif ( 'banned' === $active_tab ) : ?>
		<!-- Banned Users -->
		<div class="jetonomy-tab-content">
			<?php if ( empty( $banned_users ) ) : ?>
				<div class="jetonomy-empty-state">
					<span class="dashicons dashicons-yes-alt"></span>
					<p><?php esc_html_e( 'No active bans.', 'jetonomy' ); ?></p>
				</div>
			<?php else : ?>
				<div class="jt-content-table-wrap">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'jetonomy' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Type', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Reason', 'jetonomy' ); ?></th>
							<th style="width:140px;"><?php esc_html_e( 'Expires', 'jetonomy' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Issued By', 'jetonomy' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $banned_users as $ban ) :
							$issuer = get_userdata( $ban->issued_by );
							?>
							<tr data-restriction-id="<?php echo absint( $ban->id ); ?>">
								<td>
									<strong><?php echo esc_html( $ban->display_name ?? $ban->user_login ?? __( 'Unknown', 'jetonomy' ) ); ?></strong>
								</td>
								<td>
									<span class="jetonomy-badge jetonomy-badge--ban"><?php echo esc_html( str_replace( '_', ' ', ucfirst( $ban->type ) ) ); ?></span>
								</td>
								<td><?php echo esc_html( $ban->reason ?: '&mdash;' ); ?></td>
								<td>
									<?php if ( $ban->expires_at ) : ?>
										<?php echo esc_html( human_time_diff( time(), strtotime( $ban->expires_at ) ) ); ?>
									<?php else : ?>
										<strong><?php esc_html_e( 'Permanent', 'jetonomy' ); ?></strong>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $issuer ? $issuer->display_name : __( 'System', 'jetonomy' ) ); ?></td>
								<td>
									<button type="button" class="jt-btn jt-btn-sm jetonomy-unban-user" data-restriction-id="<?php echo absint( $ban->id ); ?>"><?php esc_html_e( 'Unban', 'jetonomy' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div><!-- /.jt-content-table-wrap -->
				<?php if ( (int) ceil( $total_banned / $per_page ) > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							$_first = ( $paged_banned - 1 ) * $per_page + 1;
							$_last  = min( $paged_banned * $per_page, $total_banned );
							printf( esc_html__( '%1$s&#8211;%2$s of %3$s', 'jetonomy' ), esc_html( number_format_i18n( $_first ) ), esc_html( number_format_i18n( $_last ) ), esc_html( number_format_i18n( $total_banned ) ) );
							?>
						</span>
						<?php
						$plinks = paginate_links(
							[
								'base'    => add_query_arg(
									[
										'tab'          => 'banned',
										'paged_banned' => '%#%',
									]
								),
								'format'  => '',
								'current' => $paged_banned,
								'total'   => (int) ceil( $total_banned / $per_page ),
								'type'    => 'array',
							]
						);
						if ( $plinks ) {
							echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $plinks ) ) . '</span>'; }
						?>
					</div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	/**
	 * Fires to render additional moderation tab content.
	 * Pro hooks its Auto-Rules tab content here.
	 *
	 * @param string $active_tab Current active tab slug.
	 */
	do_action( 'jetonomy_admin_moderation_tab_content', $active_tab );
	?>
</div>
