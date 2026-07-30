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
				<?php
				jetonomy_admin_empty_state(
					array(
						'variant' => 'success',
						'icon'    => 'yes-alt',
						'title'   => __( 'Queue is clear', 'jetonomy' ),
						'body'    => __( 'No posts are waiting on moderation right now.', 'jetonomy' ),
					)
				);
				?>
			<?php else : ?>
				<?php
				// Shared responsive primitive (Basecamp 10146440826 / 10146443346):
				// the four moderation tabs hid records and actions on mobile.
				jetonomy_admin_table(
					array(
						'columns'   => array(
							'title'   => array(
								'label'   => __( 'Title', 'jetonomy' ),
								'primary' => true,
							),
							'author'  => array(
								'label' => __( 'Author', 'jetonomy' ),
								'width' => 'm',
							),
							'space'   => array(
								'label' => __( 'Space', 'jetonomy' ),
								'width' => 'm',
							),
							'date'    => array(
								'label' => __( 'Date', 'jetonomy' ),
								'width' => 'm',
							),
							'actions' => array(
								'label' => __( 'Actions', 'jetonomy' ),
								'width' => 'l',
							),
						),
						'rows'      => $pending_posts,
						'row_attrs' => static function ( $p ): array {
							return array(
								'data-type' => 'post',
								'data-id'   => (int) $p->id,
							);
						},
						'cell'      => static function ( $p, string $key ): void {
							switch ( $key ) {
								case 'title':
									echo '<strong>' . esc_html( $p->title ) . '</strong>';
									if ( $p->content ) {
										echo '<p class="description jetonomy-content-preview">' . esc_html( wp_trim_words( wp_strip_all_tags( $p->content ), 20 ) ) . '</p>';
									}
									break;
								case 'author':
									$author = get_userdata( $p->author_id );
									echo esc_html( $author ? $author->display_name : __( 'Unknown', 'jetonomy' ) );
									break;
								case 'space':
									echo esc_html( $p->space_title ?? '—' );
									break;
								case 'date':
									echo esc_html( human_time_diff( strtotime( $p->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) );
									break;
								case 'actions':
									?>
									<span class="jetonomy-mod-actions">
										<button type="button" class="button button-primary button-small jetonomy-moderate-btn" data-action="approve" data-type="post" data-id="<?php echo absint( $p->id ); ?>"><?php esc_html_e( 'Approve', 'jetonomy' ); ?></button>
										<button type="button" class="button button-small jetonomy-moderate-btn" data-action="spam" data-type="post" data-id="<?php echo absint( $p->id ); ?>"><?php esc_html_e( 'Spam', 'jetonomy' ); ?></button>
										<button type="button" class="button button-small button-link-delete jetonomy-moderate-btn" data-action="trash" data-type="post" data-id="<?php echo absint( $p->id ); ?>"><?php esc_html_e( 'Trash', 'jetonomy' ); ?></button>
									</span>
									<?php
									break;
							}
						},
					)
				);
				?>
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
							array(
								'base'    => add_query_arg(
									array(
										'tab'         => 'posts',
										'paged_posts' => '%#%',
									)
								),
								'format'  => '',
								'current' => $paged_posts,
								'total'   => (int) ceil( $total_posts / $per_page ),
								'type'    => 'array',
							)
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
				<?php
				jetonomy_admin_empty_state(
					array(
						'variant' => 'success',
						'icon'    => 'yes-alt',
						'title'   => __( 'Queue is clear', 'jetonomy' ),
						'body'    => __( 'No replies are waiting on moderation right now.', 'jetonomy' ),
					)
				);
				?>
			<?php else : ?>
				<?php
				jetonomy_admin_table(
					array(
						'columns'   => array(
							'content' => array(
								'label'   => __( 'Content', 'jetonomy' ),
								'primary' => true,
							),
							'author'  => array(
								'label' => __( 'Author', 'jetonomy' ),
								'width' => 'm',
							),
							'parent'  => array(
								'label' => __( 'Parent Post', 'jetonomy' ),
								'width' => 'l',
							),
							'date'    => array(
								'label' => __( 'Date', 'jetonomy' ),
								'width' => 'm',
							),
							'actions' => array(
								'label' => __( 'Actions', 'jetonomy' ),
								'width' => 'l',
							),
						),
						'rows'      => $pending_replies,
						'row_attrs' => static function ( $r ): array {
							return array(
								'data-type' => 'reply',
								'data-id'   => (int) $r->id,
							);
						},
						'cell'      => static function ( $r, string $key ): void {
							switch ( $key ) {
								case 'content':
									echo '<p class="description jetonomy-content-preview">' . esc_html( wp_trim_words( wp_strip_all_tags( $r->content ?? '' ), 30 ) ) . '</p>';
									break;
								case 'author':
									$author = get_userdata( $r->author_id );
									echo esc_html( $author ? $author->display_name : __( 'Unknown', 'jetonomy' ) );
									break;
								case 'parent':
									echo esc_html( $r->post_title ?? '#' . $r->post_id );
									break;
								case 'date':
									echo esc_html( human_time_diff( strtotime( $r->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) );
									break;
								case 'actions':
									?>
									<span class="jetonomy-mod-actions">
										<button type="button" class="button button-primary button-small jetonomy-moderate-btn" data-action="approve" data-type="reply" data-id="<?php echo absint( $r->id ); ?>"><?php esc_html_e( 'Approve', 'jetonomy' ); ?></button>
										<button type="button" class="button button-small jetonomy-moderate-btn" data-action="spam" data-type="reply" data-id="<?php echo absint( $r->id ); ?>"><?php esc_html_e( 'Spam', 'jetonomy' ); ?></button>
										<button type="button" class="button button-small button-link-delete jetonomy-moderate-btn" data-action="trash" data-type="reply" data-id="<?php echo absint( $r->id ); ?>"><?php esc_html_e( 'Trash', 'jetonomy' ); ?></button>
									</span>
									<?php
									break;
							}
						},
					)
				);
				?>
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
							array(
								'base'    => add_query_arg(
									array(
										'tab'           => 'replies',
										'paged_replies' => '%#%',
									)
								),
								'format'  => '',
								'current' => $paged_replies,
								'total'   => (int) ceil( $total_replies / $per_page ),
								'type'    => 'array',
							)
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
				<?php
				jetonomy_admin_empty_state(
					array(
						'variant' => 'success',
						'icon'    => 'yes-alt',
						'title'   => __( 'Nothing flagged', 'jetonomy' ),
						'body'    => __( 'No reports are open. The community is behaving.', 'jetonomy' ),
					)
				);
				?>
			<?php else : ?>
				<?php
				jetonomy_admin_table(
					array(
						'columns'   => array(
							'object'      => array(
								'label'   => __( 'Object', 'jetonomy' ),
								'primary' => true,
							),
							'reason'      => array(
								'label' => __( 'Reason', 'jetonomy' ),
								'width' => 'm',
							),
							'description' => array( 'label' => __( 'Description', 'jetonomy' ) ),
							'reporter'    => array(
								'label' => __( 'Reporter', 'jetonomy' ),
								'width' => 'm',
							),
							'date'        => array(
								'label' => __( 'Date', 'jetonomy' ),
								'width' => 'm',
							),
							'actions'     => array(
								'label' => __( 'Actions', 'jetonomy' ),
								'width' => 'l',
							),
						),
						'rows'      => $pending_flags,
						'row_attrs' => static function ( $f ): array {
							return array( 'data-flag-id' => (int) $f->id );
						},
						'cell'      => static function ( $f, string $key ): void {
							switch ( $key ) {
								case 'object':
									echo '<code>' . esc_html( $f->object_type . ' #' . $f->object_id ) . '</code>';
									break;
								case 'reason':
									echo '<span class="jetonomy-badge jetonomy-badge--flag-' . esc_attr( $f->reason ) . '">' . esc_html( ucfirst( str_replace( '_', ' ', $f->reason ) ) ) . '</span>';
									break;
								case 'description':
									echo esc_html( $f->description ? $f->description : '—' );
									break;
								case 'reporter':
									$reporter = get_userdata( $f->reporter_id );
									echo esc_html( $reporter ? $reporter->display_name : __( 'Unknown', 'jetonomy' ) );
									break;
								case 'date':
									echo esc_html( human_time_diff( strtotime( $f->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) );
									break;
								case 'actions':
									?>
									<span class="jetonomy-mod-actions">
										<button type="button" class="button button-small button-link-delete jetonomy-resolve-flag" data-flag-id="<?php echo absint( $f->id ); ?>" data-resolution="valid"><?php esc_html_e( 'Valid (Trash)', 'jetonomy' ); ?></button>
										<button type="button" class="button button-small jetonomy-resolve-flag" data-flag-id="<?php echo absint( $f->id ); ?>" data-resolution="dismissed"><?php esc_html_e( 'Dismiss', 'jetonomy' ); ?></button>
									</span>
									<?php
									break;
							}
						},
					)
				);
				?>
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
							array(
								'base'    => add_query_arg(
									array(
										'tab'         => 'flags',
										'paged_flags' => '%#%',
									)
								),
								'format'  => '',
								'current' => $paged_flags,
								'total'   => (int) ceil( $total_flags / $per_page ),
								'type'    => 'array',
							)
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
				<?php
				jetonomy_admin_empty_state(
					array(
						'variant' => 'success',
						'icon'    => 'yes-alt',
						'title'   => __( 'No active bans', 'jetonomy' ),
						'body'    => __( 'Every member is currently in good standing.', 'jetonomy' ),
					)
				);
				?>
			<?php else : ?>
				<?php
				jetonomy_admin_table(
					array(
						'columns'   => array(
							'user'    => array(
								'label'   => __( 'User', 'jetonomy' ),
								'primary' => true,
							),
							'type'    => array(
								'label' => __( 'Type', 'jetonomy' ),
								'width' => 'm',
							),
							'reason'  => array( 'label' => __( 'Reason', 'jetonomy' ) ),
							'expires' => array(
								'label' => __( 'Expires', 'jetonomy' ),
								'width' => 'm',
							),
							'issuer'  => array(
								'label' => __( 'Issued By', 'jetonomy' ),
								'width' => 'm',
							),
							'actions' => array(
								'label' => __( 'Actions', 'jetonomy' ),
								'width' => 's',
							),
						),
						'rows'      => $banned_users,
						'row_attrs' => static function ( $ban ): array {
							return array( 'data-restriction-id' => (int) $ban->id );
						},
						'cell'      => static function ( $ban, string $key ): void {
							switch ( $key ) {
								case 'user':
									echo '<strong>' . esc_html( $ban->display_name ?? $ban->user_login ?? __( 'Unknown', 'jetonomy' ) ) . '</strong>';
									break;
								case 'type':
									echo '<span class="jetonomy-badge jetonomy-badge--ban">' . esc_html( str_replace( '_', ' ', ucfirst( $ban->type ) ) ) . '</span>';
									break;
								case 'reason':
									echo esc_html( $ban->reason ? $ban->reason : '—' );
									break;
								case 'expires':
									if ( $ban->expires_at ) {
										echo esc_html( human_time_diff( time(), strtotime( $ban->expires_at ) ) );
									} else {
										echo '<strong>' . esc_html__( 'Permanent', 'jetonomy' ) . '</strong>';
									}
									break;
								case 'issuer':
									$issuer = get_userdata( $ban->issued_by );
									echo esc_html( $issuer ? $issuer->display_name : __( 'System', 'jetonomy' ) );
									break;
								case 'actions':
									?>
									<button type="button" class="jt-btn jt-btn-sm jetonomy-unban-user" data-restriction-id="<?php echo absint( $ban->id ); ?>"><?php esc_html_e( 'Unban', 'jetonomy' ); ?></button>
									<?php
									break;
							}
						},
					)
				);
				?>
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
							array(
								'base'    => add_query_arg(
									array(
										'tab'          => 'banned',
										'paged_banned' => '%#%',
									)
								),
								'format'  => '',
								'current' => $paged_banned,
								'total'   => (int) ceil( $total_banned / $per_page ),
								'type'    => 'array',
							)
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
