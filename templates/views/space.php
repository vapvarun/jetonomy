<?php
/**
 * Space view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$space_slug = $data['slug'] ?? '';
$space      = \Jetonomy\Models\Space::find_by_slug( $space_slug );

if ( ! $space ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Space not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

$_jt_join_policy = $space->join_policy ?? 'open';
$_jt_user_id     = get_current_user_id();
$_jt_is_member   = $_jt_user_id && \Jetonomy\Models\SpaceMember::is_member( (int) $space->id, $_jt_user_id );
$_jt_is_admin    = current_user_can( 'manage_options' );
$_jt_is_hidden   = 'hidden' === ( $space->visibility ?? '' );

// Read-side defence against legacy data: hidden spaces are invite-only
// regardless of what stored join_policy says. The write-side validator
// rejects the bad combo on every entry point now, but a site that already
// had hidden + open rows in the database before upgrading must still gate
// correctly until the data migration runs. Forcing the local view of
// join_policy here keeps the gate, sidebar, and join button consistent.
if ( $_jt_is_hidden ) {
	$_jt_join_policy = 'invite';
}

// Gate: block access for private/hidden spaces (full block) or public spaces with
// restricted join policies (buttons handled below; content stays readable).
//
// The four gate variants below render their unique content (login link, join
// form, pending-approval state) inside the shared empty-state partial so the
// spacing, icon, and tone treatment are uniform with every other empty path.
// Only the join-form variant adds raw HTML below the partial because the form
// itself is too bespoke to fit the generic CTA argument.
if ( in_array( $space->visibility, [ 'private', 'hidden' ], true ) && ! $_jt_is_member && ! $_jt_is_admin ) {
	if ( ! $_jt_user_id ) {
		// Guest — prompt to log in. Hidden and private spaces use distinct
		// copy so the message does not lie about the space's actual setting:
		// the sidebar already shows "Hidden" via ucfirst($space->visibility)
		// and the home/category listings show a Hidden badge, so the gate
		// message must agree.
		$gate_message = $_jt_is_hidden
			? __( 'This space is hidden. Log in to check whether you have access.', 'jetonomy' )
			: __( 'This space is private. Please log in to request access.', 'jetonomy' );
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			[
				'icon'      => 'lock',
				'message'   => $gate_message,
				'cta_label' => __( 'Log In', 'jetonomy' ),
				'cta_url'   => wp_login_url( get_permalink() ),
				'tone'      => 'forbidden',
			]
		);
		return;
	} elseif ( 'invite' === $_jt_join_policy ) {
		// Invite-only — cannot self-join. Hidden spaces always land here
		// (forced above) so the message wording works for both.
		$invite_message = $_jt_is_hidden
			? __( 'This space is hidden and invite-only. You need an invitation to join.', 'jetonomy' )
			: __( 'This space is invite-only. You need an invitation to join.', 'jetonomy' );
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			[
				'icon'    => 'lock',
				'message' => $invite_message,
				'tone'    => 'forbidden',
			]
		);
		return;
	} elseif ( 'approval' === $_jt_join_policy ) {
		// Approval required — check for existing pending request first.
		$_jt_gate_pending = \Jetonomy\Models\JoinRequest::find_pending( (int) $space->id, $_jt_user_id );
		if ( $_jt_gate_pending ) {
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'icon'    => 'check-circle',
					'message' => __( 'Your request to join this space is awaiting approval.', 'jetonomy' ),
				]
			);
			return;
		}
		$join_nonce = wp_create_nonce( 'wp_rest' );
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			[
				'icon'    => 'lock',
				'message' => __( 'This space requires approval to join. Submit a request below.', 'jetonomy' ),
				'tone'    => 'forbidden',
			]
		);
		?>
		<form class="jt-join-request-form jt-space-gate-form" data-space-id="<?php echo absint( $space->id ); ?>" data-nonce="<?php echo esc_attr( $join_nonce ); ?>">
			<textarea class="jt-input" name="message" rows="3" placeholder="<?php esc_attr_e( 'Optional: why do you want to join?', 'jetonomy' ); ?>"></textarea>
			<button type="submit" class="jt-btn jt-btn-fill"><?php esc_html_e( 'Join', 'jetonomy' ); ?></button>
		</form>
		<?php
		return;
	} else {
		// Open join policy but private visibility — allow direct join.
		$join_nonce = wp_create_nonce( 'wp_rest' );
		?>
		<?php
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			[
				'icon'    => 'lock',
				'message' => __( 'This space is private. Join to access posts and discussions.', 'jetonomy' ),
				'tone'    => 'forbidden',
			]
		);
		?>
		<div class="jt-space-gate-actions">
			<button class="jt-btn jt-btn-fill jt-join-btn" data-space-id="<?php echo absint( $space->id ); ?>" data-nonce="<?php echo esc_attr( $join_nonce ); ?>">
				<?php esc_html_e( 'Join Space', 'jetonomy' ); ?>
			</button>
		</div>
		<?php
		return;
	}
}

$space_status  = $space->status ?? 'active';
$is_restricted = in_array( $space_status, [ 'archived', 'locked' ], true );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'latest';
if ( ! in_array( $sort, [ 'latest', 'popular', 'unanswered' ], true ) ) {
	$sort = 'latest';
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged           = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$_jt_settings    = get_option( 'jetonomy_settings', [] );
$_space_settings = \Jetonomy\Models\Space::get_settings( (int) $space->id );
$limit           = max( 1, (int) ( $_space_settings['posts_per_page'] ?? $_jt_settings['posts_per_page'] ?? 20 ) );
$offset          = ( $paged - 1 ) * $limit;

// Use the visibility-aware listing so private topics (is_private = 1) are
// filtered out for non-author, non-privileged viewers — before 1.3.6 this
// called list_by_space() which returned them to everyone with space access,
// including subscribers who were never supposed to see them (Basecamp 9803998504).
$_jt_is_priv = $_jt_user_id
	&& ( $_jt_is_admin || \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $_jt_user_id, (int) $space->id ) );
$posts       = \Jetonomy\Models\Post::list_by_space_visible(
	(int) $space->id,
	(int) $_jt_user_id,
	(bool) $_jt_is_priv,
	$sort,
	$limit,
	$offset
);
$category    = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;
$base        = \Jetonomy\base_url();
$space_url   = $base . '/s/' . $space->slug . '/';

$crumbs = [];
if ( $category ) {
	$crumbs[] = [
		'label' => $category->name,
		'url'   => '',
	];
}
$crumbs[] = [
	'label' => $space->title,
	'url'   => '',
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<?php if ( ! empty( $space->cover_image ) ) : ?>
			<div class="jt-space-cover" style="background-image:url('<?php echo esc_url( $space->cover_image ); ?>')"></div>
		<?php endif; ?>
		<div class="jt-space-head">
				<?php jetonomy_render_space_icon( $space->icon ?? '', 32, 'jt-space-emoji' ); ?>
				<div>
						<h1><?php echo esc_html( $space->title ); ?></h1>
					<?php if ( ! empty( $space->description ) ) : ?>
						<p class="jt-space-desc"><?php echo esc_html( $space->description ); ?></p>
					<?php endif; ?>
				</div>
				<div class="jt-space-nums">
					<div class="jt-num">
						<div class="jt-num-val"><?php echo esc_html( (int) $space->post_count ); ?></div>
						<div class="jt-num-lbl"><?php esc_html_e( 'Posts', 'jetonomy' ); ?></div>
					</div>
					<div class="jt-num">
						<div class="jt-num-val"><?php echo esc_html( (int) $space->member_count ); ?></div>
						<div class="jt-num-lbl"><?php esc_html_e( 'Members', 'jetonomy' ); ?></div>
					</div>
				</div>
				<?php
				if ( is_user_logged_in() ) :
					if ( 'invite' === $_jt_join_policy && ! $_jt_is_member && ! $_jt_is_admin ) :
						// Invite-only: show a disabled badge instead of Follow/Join.
						?>
						<span class="jt-btn jt-btn-sm jt-btn-ghost" style="cursor:default;opacity:.7;">
							<?php esc_html_e( 'Invite Only', 'jetonomy' ); ?>
						</span>
						<?php
					elseif ( 'approval' === $_jt_join_policy && ! $_jt_is_member && ! $_jt_is_admin ) :
						// Approval required: check for existing pending request.
						$_jt_pending = \Jetonomy\Models\JoinRequest::find_pending( (int) $space->id, $_jt_user_id );
						if ( $_jt_pending ) :
							?>
							<span class="jt-btn jt-btn-sm jt-btn-ghost" style="cursor:default;opacity:.7;text-align:center;">
								<?php esc_html_e( 'Awaiting Approval', 'jetonomy' ); ?>
							</span>
							<?php
						else :
							$_jt_join_nonce = wp_create_nonce( 'wp_rest' );
							?>
							<button class="jt-btn jt-btn-sm jt-btn-fill jt-join-request-btn"
								data-space-id="<?php echo absint( $space->id ); ?>"
								data-nonce="<?php echo esc_attr( $_jt_join_nonce ); ?>">
								<?php esc_html_e( 'Join', 'jetonomy' ); ?>
							</button>
						<?php endif; ?>
						<?php
					elseif ( 'open' === $_jt_join_policy && ! $_jt_is_member && ! $_jt_is_admin ) :
						// Open space, non-member: show Join Space button (instant membership).
						$_jt_join_nonce = wp_create_nonce( 'wp_rest' );
						?>
						<button class="jt-btn jt-btn-sm jt-btn-fill jt-join-btn"
							data-space-id="<?php echo absint( $space->id ); ?>"
							data-nonce="<?php echo esc_attr( $_jt_join_nonce ); ?>">
							<?php esc_html_e( 'Join Space', 'jetonomy' ); ?>
						</button>
						<?php
					else :
						// Member or open space: show Follow/Following toggle.
						$is_following_space = \Jetonomy\Models\Subscription::is_subscribed( get_current_user_id(), 'space', (int) $space->id );
						?>
						<button class="jt-btn jt-btn-sm <?php echo esc_attr( $is_following_space ? 'jt-btn-fill jt-following' : 'jt-btn-ghost' ); ?>"
							data-wp-interactive="jetonomy"
							data-wp-on--click="actions.followSpace"
							data-space-id="<?php echo absint( $space->id ); ?>"
							data-following="<?php echo esc_attr( $is_following_space ? '1' : '0' ); ?>">
							<?php echo $is_following_space ? esc_html__( 'Following', 'jetonomy' ) : esc_html__( 'Follow', 'jetonomy' ); ?>
						</button>
					<?php endif; ?>
				<?php endif; ?>
			</div>

		<?php if ( $is_restricted ) : ?>
			<div class="jt-status-banner jt-status-banner--<?php echo esc_attr( $space_status ); ?>">
				<?php if ( 'archived' === $space_status ) : ?>
					<?php esc_html_e( 'This space is archived. New posts and replies are no longer accepted.', 'jetonomy' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'This space is locked. New posts and replies are not allowed.', 'jetonomy' ); ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( 'ideas' === ( $space->type ?? '' ) ) : ?>
				<nav class="jt-space-tabs" aria-label="<?php esc_attr_e( 'Space sections', 'jetonomy' ); ?>">
					<a href="<?php echo esc_url( $space_url ); ?>" class="jt-space-tab on" aria-current="page">
						<?php esc_html_e( 'Ideas', 'jetonomy' ); ?>
					</a>
					<a href="<?php echo esc_url( $space_url . 'roadmap/' ); ?>" class="jt-space-tab">
						<?php esc_html_e( 'Roadmap', 'jetonomy' ); ?>
					</a>
				</nav>
			<?php endif; ?>

			<div class="jt-bar">
				<div class="jt-pills">
					<?php
					$sort_options = [
						'latest'     => __( 'Latest', 'jetonomy' ),
						'popular'    => __( 'Popular', 'jetonomy' ),
						'unanswered' => __( 'Unanswered', 'jetonomy' ),
					];
					foreach ( $sort_options as $key => $label ) :
						$pill_url = add_query_arg( 'sort', $key, $space_url );
						?>
						<a href="<?php echo esc_url( $pill_url ); ?>"
							class="jt-pill <?php echo $sort === $key ? esc_attr( 'on' ) : ''; ?>"
							<?php echo $sort === $key ? 'aria-current="true"' : ''; ?>>
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
				<?php if ( $is_restricted ) : ?>
					<?php /* No new-post button for archived/locked spaces. */ ?>
				<?php elseif ( is_user_logged_in() && ( $_jt_is_member || $_jt_is_admin || 'open' === $_jt_join_policy ) ) : ?>
					<a href="<?php echo esc_url( $space_url . 'new/' ); ?>" class="jt-btn jt-btn-fill">
						<?php
						$new_post_labels = [
							'qa'    => __( '+ Ask a Question', 'jetonomy' ),
							'ideas' => __( '+ Share an Idea', 'jetonomy' ),
							'feed'  => __( '+ New Status', 'jetonomy' ),
						];
						echo esc_html( $new_post_labels[ $space->type ] ?? __( '+ New Post', 'jetonomy' ) );
						?>
					</a>
				<?php elseif ( ! is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( wp_login_url( $space_url ) ); ?>" class="jt-btn jt-btn-ghost">
						<?php esc_html_e( 'Log in to post', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( empty( $posts ) ) : ?>
				<?php
				$_jt_no_posts_msg = ( 'unanswered' === $sort )
					? __( 'All questions have been answered!', 'jetonomy' )
					: __( 'No posts yet. Be the first to start a discussion!', 'jetonomy' );
				$_jt_can_post     = is_user_logged_in() && ( $_jt_is_member || $_jt_is_admin || 'open' === $_jt_join_policy );
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					[
						'icon'      => 'empty-posts',
						'message'   => $_jt_no_posts_msg,
						'cta_label' => $_jt_can_post ? __( 'New Post', 'jetonomy' ) : '',
						'cta_url'   => $_jt_can_post ? ( $space_url . 'new/' ) : '',
					]
				);
				?>
			<?php else : ?>
				<?php
				// 1.4.0 G3: warm the per-request role-label cache so each
				// post-card partial below is O(1) instead of issuing one
				// SpaceMember query per author. Single bulk query for the
				// whole list. Author IDs are unique-deduped inside the
				// model helper.
				\Jetonomy\Models\SpaceMember::warm_role_cache(
					(int) $space->id,
					array_map( static fn( $p ) => (int) $p->author_id, $posts )
				);

				// 1.4.0 C.5: bulk-load the viewer's last-read reply id per
				// post so each card can render a "new replies" pill in O(1).
				$jt_read_map = array();
				$jt_viewer   = get_current_user_id();
				if ( $jt_viewer > 0 ) {
					$jt_read_map = \Jetonomy\Models\ReadStatus::last_read_for_posts(
						$jt_viewer,
						array_map( static fn( $p ) => (int) $p->id, $posts )
					);
				}
				?>
				<div class="jt-topics">
					<?php foreach ( $posts as $post ) : ?>
						<?php
						// Boolean signal: does the viewer have unread replies on
						// this thread? An ID-arithmetic count would be misleading
						// because reply IDs aren't contiguous per post — only the
						// "newer than last_read" comparison is reliable.
						$jt_has_unread = false;
						if ( $jt_viewer > 0 ) {
							$jt_last_reply = (int) ( $post->last_reply_id ?? 0 );
							$jt_last_read  = $jt_read_map[ (int) $post->id ] ?? 0;
							$jt_has_unread = $jt_last_reply > $jt_last_read && (int) $post->author_id !== $jt_viewer;
						}
						?>
						<?php
						\Jetonomy\Template_Loader::partial(
							'post-card',
							array(
								'post'       => $post,
								'has_unread' => $jt_has_unread,
							)
						);
						?>
					<?php endforeach; ?>
				</div>

				<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $posts ) >= $limit ] ); ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
	</div>
