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
			'message'   => sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ),
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
			? sprintf( __( 'This %s is hidden. Log in to check whether you have access.', 'jetonomy' ), \Jetonomy\space_label( false, true ) )
			: sprintf( __( 'This %s is private. Please log in to request access.', 'jetonomy' ), \Jetonomy\space_label( false, true ) );
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			[
				'icon'      => 'lock',
				'message'   => $gate_message,
				'cta_label' => __( 'Log In', 'jetonomy' ),
				'cta_url'   => wp_login_url( \Jetonomy\current_url() ),
				'tone'      => 'forbidden',
			]
		);
		return;
	} elseif ( 'invite' === $_jt_join_policy ) {
		// Invite-only — cannot self-join. Hidden spaces always land here
		// (forced above) so the message wording works for both.
		$invite_message = $_jt_is_hidden
			? sprintf( __( 'This %s is hidden and invite-only. You need an invitation to join.', 'jetonomy' ), \Jetonomy\space_label( false, true ) )
			: sprintf( __( 'This %s is invite-only. You need an invitation to join.', 'jetonomy' ), \Jetonomy\space_label( false, true ) );
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
					'message' => sprintf( __( 'Your request to join this %s is awaiting approval.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
				]
			);
			return;
		}
		$join_nonce = wp_create_nonce( 'wp_rest' );
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			[
				'icon'    => 'lock',
				'message' => sprintf( __( 'This %s requires approval to join. Submit a request below.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
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
				'message' => sprintf( __( 'This %s is private. Join to access posts and discussions.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
				'tone'    => 'forbidden',
			]
		);
		?>
		<div class="jt-space-gate-actions">
			<button class="jt-btn jt-btn-fill jt-join-btn" data-space-id="<?php echo absint( $space->id ); ?>" data-nonce="<?php echo esc_attr( $join_nonce ); ?>">
				<?php echo esc_html( sprintf( __( 'Join %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?>
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
// "Load More" must reflect whether more posts actually exist, not whether this
// page happened to fill up. count($posts) >= $limit showed the button on a space
// with EXACTLY $limit posts and then loaded an empty page (Basecamp). Compare the
// real total (same visibility population as the listing) against what's shown.
$_jt_total    = \Jetonomy\Models\Post::count_by_space_visible( (int) $space->id, (int) $_jt_user_id, (bool) $_jt_is_priv, $sort );
$_jt_has_more = ( $paged * $limit ) < $_jt_total;
$category     = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;
$base         = \Jetonomy\base_url();
$space_url    = $base . '/s/' . $space->slug . '/';

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
			<div class="jt-space-cover jt-space-cover--image" style="background-image:url('<?php echo esc_url( $space->cover_image ); ?>')">
		<?php else : ?>
			<div class="jt-space-cover jt-space-cover--tonal">
		<?php endif; ?>
			<div class="jt-space-cover__identity">
				<?php jetonomy_render_space_icon( $space->icon ?? '', 32, 'jt-space-emoji' ); ?>
				<h1 class="jt-space-cover__title"><?php echo esc_html( $space->title ); ?></h1>
			</div>
		</div>
		<?php
		$_jt_can_edit_space = is_user_logged_in()
			&& \Jetonomy\Permissions\Permission_Engine::is_space_admin( $_jt_user_id, (int) $space->id );
		?>
		<div class="jt-space-head">
				<div class="jt-space-head__meta">
					<?php if ( ! empty( $space->description ) ) : ?>
						<p class="jt-space-desc"><?php echo esc_html( $space->description ); ?></p>
					<?php endif; ?>
					<?php
					/**
					 * Fires in the space header to display custom field values
					 * (context = space). Pro custom-fields renders the saved
					 * values here. Mirrors jetonomy_profile_display_fields.
					 *
					 * @param object $space The space being viewed.
					 */
					do_action( 'jetonomy_space_display_fields', $space );
					?>
					<?php if ( $_jt_can_edit_space ) : ?>
						<?php
						/*
						 * Inline Edit Space CTA for users who can administer THIS space.
						 * The admin-bar entry alone is not enough - WordPress hides the
						 * admin bar at mobile widths under most themes, leaving phone-only
						 * space admins with no path to /edit/. This inline button always
						 * shows, gated by the same Permission_Engine::is_space_admin check
						 * as the admin-bar entry and the front-end edit template.
						 */
						?>
						<p class="jt-space-edit-cta">
							<a class="jt-btn jt-btn-sm jt-btn-ghost"
								href="<?php echo esc_url( \Jetonomy\base_url() . '/s/' . $space->slug . '/edit/' ); ?>">
								<?php jetonomy_echo_icon( 'pencil', 14 ); ?>
								<?php echo esc_html( sprintf( __( 'Edit %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
							</a>
						</p>
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
							<?php echo esc_html( sprintf( __( 'Join %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?>
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
					<?php echo esc_html( sprintf( __( 'This %s is archived. New posts and replies are no longer accepted.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
				<?php else : ?>
					<?php echo esc_html( sprintf( __( 'This %s is locked. New posts and replies are not allowed.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php
			// Space sub-navigation. Ideas spaces keep their Ideas + Roadmap tabs;
			// every space type also exposes a Members tab for logged-in users so
			// space moderators can reach the members page (and its pending join-
			// request approval panel) without knowing the direct URL. (#10013900410)
			// The tab list is filterable via `jetonomy_space_tabs`.
			$jt_is_ideas       = 'ideas' === ( $space->type ?? '' );
			$jt_show_members   = is_user_logged_in();
			$jt_primary_labels = [
				'forum' => __( 'Discussions', 'jetonomy' ),
				'qa'    => __( 'Questions', 'jetonomy' ),
				'ideas' => __( 'Ideas', 'jetonomy' ),
				'feed'  => __( 'Posts', 'jetonomy' ),
			];
			$jt_primary_label  = $jt_primary_labels[ $space->type ?? 'forum' ] ?? __( 'Discussions', 'jetonomy' );

			// Built-in tabs as an ordered, filterable map. Each entry:
			// slug => [ 'label' => string, 'url' => string, 'active' => bool ].
			// The primary tab represents this view (the space topic listing).
			$jt_space_tabs = array(
				'primary' => array(
					'label'  => $jt_primary_label,
					'url'    => $space_url,
					'active' => true,
				),
			);
			if ( $jt_is_ideas ) {
				$jt_space_tabs['roadmap'] = array(
					'label' => __( 'Roadmap', 'jetonomy' ),
					'url'   => $space_url . 'roadmap/',
				);
			}
			if ( $jt_show_members ) {
				$jt_space_tabs['members'] = array(
					'label' => __( 'Members', 'jetonomy' ),
					'url'   => $space_url . 'members/',
				);
			}

			/**
			 * Filters the space sub-navigation tabs.
			 *
			 * Add, remove, reorder, or relabel the tabs on a space page. Each tab
			 * is `slug => [ 'label' => string, 'url' => string, 'active' => bool ]`.
			 * Set 'active' on the tab representing the current view. A custom tab
			 * typically links to a route registered via `jetonomy_template_map`.
			 * The nav renders when there is more than one tab.
			 *
			 * @since 1.5.0
			 *
			 * @param array<string,array{label:string,url:string,active?:bool}> $jt_space_tabs Ordered tab map.
			 * @param object $space          The space being viewed.
			 * @param bool   $jt_show_members Whether the Members tab is shown (viewer logged in).
			 */
			$jt_space_tabs = apply_filters( 'jetonomy_space_tabs', $jt_space_tabs, $space, $jt_show_members );

			if ( is_array( $jt_space_tabs ) && count( $jt_space_tabs ) > 1 ) :
				?>
				<nav class="jt-space-tabs" aria-label="<?php echo esc_attr( sprintf( __( '%s sections', 'jetonomy' ), \Jetonomy\space_label() ) ); ?>">
					<?php
					foreach ( $jt_space_tabs as $jt_space_tab ) :
						if ( empty( $jt_space_tab['label'] ) || ! isset( $jt_space_tab['url'] ) ) {
							continue;
						}
						$jt_tab_on = ! empty( $jt_space_tab['active'] );
						?>
						<a href="<?php echo esc_url( $jt_space_tab['url'] ); ?>" class="jt-space-tab <?php echo $jt_tab_on ? 'on' : ''; ?>"<?php echo $jt_tab_on ? ' aria-current="page"' : ''; ?>>
							<?php echo esc_html( $jt_space_tab['label'] ); ?>
						</a>
					<?php endforeach; ?>
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
					<?php
					// Logged-out members still come here to contribute — the
					// primary action shouldn't read as a faint secondary control.
					// Use the filled button so "Log in to post" has the same
					// visual weight as the New Post action it stands in for.
					?>
					<a href="<?php echo esc_url( wp_login_url( $space_url ) ); ?>" class="jt-btn jt-btn-fill">
						<?php esc_html_e( 'Log in to post', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( empty( $posts ) ) : ?>
				<?php
				// Empty copy + CTA speak the space's own language: a Q&A space
				// invites a question, a feed invites an update, an ideas space
				// invites an idea — "No posts yet" reads as generic and dead.
				$_jt_space_type = (string) ( $space->type ?? 'forum' );
				if ( 'unanswered' === $sort ) {
					$_jt_no_posts_msg = ( 'qa' === $_jt_space_type )
						? __( 'Every question has an accepted answer.', 'jetonomy' )
						: __( 'No posts without replies yet.', 'jetonomy' );
				} else {
					switch ( $_jt_space_type ) {
						case 'qa':
							$_jt_no_posts_msg = __( 'No questions yet. Ask the first one and get answers from the community.', 'jetonomy' );
							break;
						case 'feed':
							$_jt_no_posts_msg = __( 'Nothing posted yet. Share the first update.', 'jetonomy' );
							break;
						case 'ideas':
							$_jt_no_posts_msg = __( 'No ideas yet. Suggest the first one and let the community vote.', 'jetonomy' );
							break;
						default:
							$_jt_no_posts_msg = __( 'No posts yet. Be the first to start a discussion!', 'jetonomy' );
					}
				}
				$_jt_cta_by_type = [
					'qa'    => __( 'Ask a question', 'jetonomy' ),
					'feed'  => __( 'Share an update', 'jetonomy' ),
					'ideas' => __( 'Suggest an idea', 'jetonomy' ),
				];
				$_jt_post_cta    = $_jt_cta_by_type[ $_jt_space_type ] ?? __( 'New Post', 'jetonomy' );
				$_jt_can_post    = is_user_logged_in() && ( $_jt_is_member || $_jt_is_admin || 'open' === $_jt_join_policy );
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					[
						'icon'      => 'empty-posts',
						'message'   => $_jt_no_posts_msg,
						'cta_label' => $_jt_can_post ? $_jt_post_cta : '',
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
				<?php
				// Feed spaces render the post body inline as a social-feed
				// card; every other space type uses the topic-card list with
				// title + excerpt + click-through.
				$jt_card_partial = ( 'feed' === ( $space->type ?? '' ) ) ? 'feed-card' : 'post-card';
				$jt_topics_class = ( 'feed' === ( $space->type ?? '' ) ) ? 'jt-topics jt-feed' : 'jt-topics';
				?>
				<div class="<?php echo esc_attr( $jt_topics_class ); ?>">
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
							$jt_card_partial,
							array(
								'post'       => $post,
								'has_unread' => $jt_has_unread,
							)
						);
						?>
					<?php endforeach; ?>
				</div>

				<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => $_jt_has_more ] ); ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
	</div>
