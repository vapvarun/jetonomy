<?php
/**
 * Single post view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$post_slug = $data['slug'] ?? '';
$post      = \Jetonomy\Models\Post::find_by_slug( $post_slug );

if ( ! $post ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Post not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

// 1.4.0 C.1 fix: every "can moderate" check below uses Permission_Engine::can()
// so a space-role moderator (subscriber WP role with admin/moderator role on
// jt_space_members) gets the same UI affordances as a WP-cap moderator. Before
// this, a `current_user_can('jetonomy_moderate')` cap-only gate hid edit / pin /
// delete / move / merge from every space mod whose WP role lacked the cap —
// regression versus 1.3.8's promise that space mods are first-class.
$jt_viewer_id         = get_current_user_id();
$jt_can_moderate_here = $jt_viewer_id
	? \Jetonomy\Permissions\Permission_Engine::can( $jt_viewer_id, 'moderate', (int) $post->space_id )
	: false;

if ( 'publish' !== $post->status ) {
	$viewer_id = $jt_viewer_id;
	$is_author = $viewer_id && (int) $post->author_id === $viewer_id;
	$is_mod    = $jt_can_moderate_here || current_user_can( 'manage_options' );

	if ( ! $is_author && ! $is_mod ) {
		status_header( 404 );
		\Jetonomy\Template_Loader::partial(
			'empty-state',
			[
				'icon'      => 'empty-search',
				'icon_size' => 48,
				'message'   => __( 'Post not found.', 'jetonomy' ),
				'tone'      => 'warn',
			]
		);
		return;
	}
}

$space = \Jetonomy\Models\Space::find( (int) $post->space_id );

if ( $space && in_array( $space->visibility, [ 'private', 'hidden' ], true ) ) {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! \Jetonomy\Models\SpaceMember::is_member( (int) $space->id, $user_id ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'message' => __( 'This content is in a private space.', 'jetonomy' ),
					'tone'    => 'forbidden',
				]
			);
			return;
		}
	}
}

// Per-post privacy gate. Before 1.3.6 a subscriber with space access could
// reach a private topic via direct URL because neither the template nor the
// status/visibility checks above looked at is_private on the post itself
// (Basecamp 9803998504). Permission_Engine::can_read_post() is the single
// source of truth — author + manage_options + space mod/admin only.
if ( ! \Jetonomy\Permissions\Permission_Engine::can_read_post( get_current_user_id(), $post ) ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Post not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

$author   = get_userdata( (int) $post->author_id );
$profile  = \Jetonomy\Models\UserProfile::find_by_user( (int) $post->author_id );
$tags     = \Jetonomy\Models\Tag::list_for_post( (int) $post->id );
$category = ( $space && $space->category_id ) ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;

$author_id = (int) $post->author_id;
$trust     = $profile ? (int) $profile->trust_level : 0;
$time_ago  = human_time_diff( strtotime( $post->created_at ), time() );
$base      = \Jetonomy\base_url();
$post_url  = $base . '/s/' . ( $space ? $space->slug : '' ) . '/t/' . $post->slug . '/';

// Resolve prefix color from space settings.
$prefix_name  = $post->prefix ?? null;
$prefix_color = null;
if ( $prefix_name && $space ) {
	$sp_settings_pf = \Jetonomy\Models\Space::get_settings( (int) $space->id );
	$prefix_list    = $sp_settings_pf['prefixes'] ?? array();
	foreach ( $prefix_list as $pfx ) {
		if ( ( $pfx['name'] ?? '' ) === $prefix_name ) {
			$prefix_color = $pfx['color'] ?? null;
			break;
		}
	}
}

// Replies sort.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reply_sort = isset( $_GET['rsort'] ) ? sanitize_key( $_GET['rsort'] ) : 'oldest';
if ( ! in_array( $reply_sort, [ 'oldest', 'newest', 'best' ], true ) ) {
	$reply_sort = 'oldest';
}
// Top-level reply pagination, honouring the global `replies_per_page`
// setting. Server renders the requested page; pagination-frontend.js
// fetches the next page and appends in place. No-JS users get classic
// full-page pagination via the ?rpg=N anchor.
$total_replies    = (int) $post->reply_count;
$top_level_count  = \Jetonomy\Models\Reply::count_top_level( (int) $post->id );
$jt_settings      = get_option( 'jetonomy_settings', array() );
$replies_per_page = max( 1, (int) ( $jt_settings['replies_per_page'] ?? 30 ) );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reply_page        = max( 1, (int) ( $_GET['rpg'] ?? 1 ) );
$reply_offset      = ( $reply_page - 1 ) * $replies_per_page;
$reply_batch       = \Jetonomy\Models\Reply::get_threaded(
	(int) $post->id,
	$reply_sort,
	$replies_per_page,
	$reply_offset
);
$replies_have_more = ( $reply_page * $replies_per_page ) < $top_level_count;

// 1.4.0 G3: warm the role-label cache for the post author + every reply
// author (including nested children) so each role-pill lookup downstream
// is O(1). Without this a 200-reply thread would issue 200 SpaceMember
// queries during render. Single bulk query covers the whole tree.
$jt_role_warm_ids = [ (int) $post->author_id ];
$jt_role_walker   = function ( array $list ) use ( &$jt_role_warm_ids, &$jt_role_walker ): void {
	foreach ( $list as $reply ) {
		$jt_role_warm_ids[] = (int) ( $reply->author_id ?? 0 );
		if ( ! empty( $reply->children ) ) {
			$jt_role_walker( $reply->children );
		}
	}
};
$jt_role_walker( $reply_batch );
\Jetonomy\Models\SpaceMember::warm_role_cache( (int) $post->space_id, $jt_role_warm_ids );

// Current user vote on post.
$user_id        = get_current_user_id();
$user_post_vote = $user_id ? \Jetonomy\Models\Vote::get_user_vote( $user_id, 'post', (int) $post->id ) : null;

// Breadcrumb.
$crumbs = [];
if ( $category ) {
	$crumbs[] = [
		'label' => $category->name,
		'url'   => '',
	];
}
if ( $space ) {
	$crumbs[] = [
		'label' => $space->title,
		'url'   => $base . '/s/' . $space->slug . '/',
	];
}
$crumbs[] = [
	'label' => (string) ( $post->title ?? '' ),
	'url'   => '',
];

// Server-side state for IA.
$post_scores = [ (int) $post->id => (int) $post->vote_score ];
wp_interactivity_state(
	'jetonomy',
	[
		'currentPostId' => (int) $post->id,
		'postScores'    => $post_scores,
		'replyScores'   => [],
		'activeReply'   => 0,
		'submitting'    => false,
		'replyToId'     => null,
		'replyToAuthor' => '',
	]
);
?>
<?php
/**
 * Render a threaded reply recursively with depth-based nesting.
 *
 * @param object $reply Reply object with optional ->children and ->depth.
 * @param object $post  Parent post object.
 * @param int    $depth Current nesting depth (0 = top-level).
 */
function jetonomy_render_threaded_reply( $reply, $post, $depth = 0, $space = null ) {
	$depth         = isset( $reply->depth ) ? (int) $reply->depth : $depth;
	$wrapper_class = $depth > 0 ? 'jt-nested jt-nested-' . min( $depth, 3 ) : '';
	?>
	<div class="<?php echo esc_attr( $wrapper_class ); ?>">
		<?php
		\Jetonomy\Template_Loader::partial(
			'reply-card',
			[
				'reply' => $reply,
				'post'  => $post,
				'space' => $space,
			]
		);
		?>
		<?php if ( $depth === 0 && ! empty( $reply->children ) ) : ?>
			<div class="jt-thread-toggle" data-wp-interactive="jetonomy"
				data-wp-context='{"collapsed": false, "childCount": <?php echo (int) count( $reply->children ); ?>}'>
				<button class="jt-thread-toggle-btn" data-wp-on--click="actions.toggleThread"
					data-wp-text="context.collapsed ? '+ Show ' + context.childCount + ' replies' : '&minus; Hide replies'">
					&minus; Hide replies
				</button>
				<div class="jt-thread-children" data-wp-class--collapsed="context.collapsed">
					<?php foreach ( $reply->children as $child ) : ?>
						<?php jetonomy_render_threaded_reply( $child, $post, $depth + 1, $space ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php elseif ( ! empty( $reply->children ) ) : ?>
			<?php foreach ( $reply->children as $child ) : ?>
				<?php jetonomy_render_threaded_reply( $child, $post, $depth + 1, $space ); ?>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<?php if ( 'publish' !== $post->status ) : ?>
				<div class="jt-notice jt-notice-warning">
					<?php
					if ( 'pending' === $post->status ) {
						esc_html_e( 'This post is pending review and not yet publicly visible.', 'jetonomy' );
					} elseif ( 'spam' === $post->status ) {
						esc_html_e( 'This post has been marked as spam.', 'jetonomy' );
					} else {
						/* translators: %s: post status */
						echo esc_html( sprintf( __( 'This post has status: %s', 'jetonomy' ), $post->status ) );
					}
					?>
				</div>
			<?php endif; ?>
			<!-- Post -->
			<article class="jt-post" data-wp-interactive="jetonomy">
				<div class="jt-post-head">
					<?php
					// 1.4.3: feed-space posts hide the h1 visually but the
					// title is a real user-entered string used by breadcrumbs,
					// notifications, search, and share previews. Visible h1
					// for every other space type.
					$jt_h1_is_sr_only = ( $space && 'feed' === ( $space->type ?? '' ) );
					$jt_h1_label      = (string) ( $post->title ?? '' );
					?>
					<?php
					// Feed-space untitled posts: emit the sr-only h1 alone so
					// the visual flow is meta-row → body. Defer the Follow
					// button to the meta row (it lands at the trailing edge
					// via margin-inline-start: auto). Titled posts get the
					// usual title-row with Follow on the right.
					$jt_show_follow = is_user_logged_in();
					if ( $jt_show_follow ) {
						$is_following = \Jetonomy\Models\Subscription::is_subscribed( get_current_user_id(), 'post', (int) $post->id );
					}
					if ( $jt_h1_is_sr_only ) :
						?>
						<h1 class="screen-reader-text"><?php echo esc_html( $jt_h1_label ); ?></h1>
					<?php else : ?>
						<div class="jt-post-title-row">
							<h1>
								<?php if ( $prefix_name ) : ?>
									<span class="jt-prefix"
									<?php
									if ( $prefix_color ) :
										?>
										style="--jt-pfx:<?php echo esc_attr( $prefix_color ); ?>"<?php endif; ?>><?php echo esc_html( $prefix_name ); ?></span>
								<?php endif; ?>
								<?php if ( $space && 'ideas' === ( $space->type ?? '' ) ) : ?>
									<?php jetonomy_render_idea_status_pill( (string) ( $post->idea_status ?? '' ) ); ?>
								<?php endif; ?>
								<?php echo esc_html( (string) ( $post->title ?? '' ) ); ?>
							</h1>
							<?php if ( $jt_show_follow ) : ?>
								<button class="jt-btn jt-btn-sm <?php echo esc_attr( $is_following ? 'jt-btn-fill jt-following' : 'jt-btn-ghost' ); ?>"
									data-wp-on--click="actions.followPost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-following="<?php echo esc_attr( $is_following ? '1' : '0' ); ?>">
									<?php echo $is_following ? esc_html__( 'Following', 'jetonomy' ) : esc_html__( 'Follow', 'jetonomy' ); ?>
								</button>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<?php
					// On Ideas spaces, space moderators see a status picker so
					// the roadmap workflow is reachable from the post page.
					// Members see the read-only pill above; non-Ideas spaces
					// see neither. The picker is a row of pill buttons rather
					// than a native <select> — same visual language as the
					// status pill itself, single click to apply.
					if ( $space && 'ideas' === ( $space->type ?? '' ) && $jt_can_moderate_here ) :
						$jt_current_status = (string) ( $post->idea_status ?? '' );
						?>
						<div class="jt-idea-status-setter"
							data-wp-interactive="jetonomy"
							data-wp-context='<?php echo wp_json_encode( array( 'postId' => (int) $post->id ) ); ?>'
							role="group"
							aria-label="<?php esc_attr_e( 'Set roadmap status', 'jetonomy' ); ?>">
							<span class="jt-idea-status-setter-label"><?php esc_html_e( 'Status:', 'jetonomy' ); ?></span>
							<div class="jt-idea-status-options">
								<?php foreach ( \Jetonomy\Models\Post::valid_idea_statuses() as $jt_opt ) : ?>
									<button type="button"
										class="jt-idea-pill jt-idea-pill-<?php echo esc_attr( $jt_opt ); ?> jt-idea-status-btn<?php echo $jt_current_status === $jt_opt ? ' is-active' : ''; ?>"
										data-status="<?php echo esc_attr( $jt_opt ); ?>"
										data-wp-on--click="actions.setIdeaStatus"
										aria-pressed="<?php echo $jt_current_status === $jt_opt ? 'true' : 'false'; ?>">
										<?php echo esc_html( jetonomy_idea_status_label( $jt_opt ) ); ?>
									</button>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
					<div class="jt-meta">
						<?php
						// 1.4.1 byline cleanup: trust-level number removed from inline
						// bylines (it lives on the user profile + hover-card surfaces).
						// Tags moved out of the byline into their own row below the
						// post body so the meta line reads cleanly as
						// "User · time · status" without descriptor noise.
						echo wp_kses_post( \Jetonomy\get_user_link( (int) $post->author_id, 'jt-avatar-md', 36, true ) );
						?>
						<span>
							<?php
							/* translators: %s: human-readable time difference */
							echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
							?>
						</span>
						<?php if ( ! empty( $post->is_private ) ) : ?>
							<span class="jt-badge-private">
								<?php jetonomy_echo_icon( 'lock', 14 ); ?> <?php esc_html_e( 'Private', 'jetonomy' ); ?>
							</span>
						<?php endif; ?>
						<?php if ( $post->is_resolved ) : ?>
							<span class="jt-badge-resolved">
								<?php jetonomy_echo_icon( 'check-circle', 14 ); ?> <?php esc_html_e( 'Resolved', 'jetonomy' ); ?>
							</span>
						<?php endif; ?>
						<?php if ( $post->is_closed ) : ?>
							<span class="jt-badge-closed">
								<?php esc_html_e( 'Closed', 'jetonomy' ); ?>
							</span>
						<?php endif; ?>
						<?php if ( $jt_h1_is_sr_only && $jt_show_follow ) : ?>
							<button class="jt-btn jt-btn-sm jt-meta-follow <?php echo esc_attr( $is_following ? 'jt-btn-fill jt-following' : 'jt-btn-ghost' ); ?>"
								data-wp-on--click="actions.followPost"
								data-post-id="<?php echo absint( $post->id ); ?>"
								data-following="<?php echo esc_attr( $is_following ? '1' : '0' ); ?>">
								<?php echo $is_following ? esc_html__( 'Following', 'jetonomy' ) : esc_html__( 'Follow', 'jetonomy' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>

				<div class="jt-post-body">
					<?php
					// jetonomy_kses_embedded_content() is a wp_kses() wrapper with an extended iframe allowlist — safe to echo.
					// Embeds first so URL paths containing @username don't get mangled
					// by jetonomy_format_content's mention matcher. See reply-card.php.
					$jt_post_rendered = jetonomy_kses_embedded_content( jetonomy_format_content( \Jetonomy\Embeds::process( wp_kses_post( $post->content ) ) ) );
					jetonomy_maybe_enqueue_embed_scripts( $jt_post_rendered );
					echo $jt_post_rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>

				<?php if ( ! empty( $tags ) ) : ?>
					<div class="jt-post-tags" aria-label="<?php esc_attr_e( 'Tags', 'jetonomy' ); ?>">
						<?php foreach ( $tags as $tag ) : ?>
							<a href="<?php echo esc_url( $base . '/tag/' . $tag->slug . '/' ); ?>" class="jt-tag-link">
								#<?php echo esc_html( $tag->name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php
				/**
				 * Fires after the post body to display custom field values.
				 *
				 * @param object $post The Jetonomy post object.
				 */
				do_action( 'jetonomy_post_meta_fields', $post );
				?>

				<?php
				// Poll widget (Pro) renders radio/checkbox inputs via this
				// filter. wp_kses_post() strips form inputs, which silently
				// breaks voting UI even though the REST endpoint works.
				// Extend the post-content allowlist with the input element
				// + the IA event directive consumers used inside the slot.
				$jt_post_content_after_tags          = wp_kses_allowed_html( 'post' );
				$jt_post_content_after_tags['input'] = array(
					'type'                   => true,
					'name'                   => true,
					'value'                  => true,
					'checked'                => true,
					'disabled'               => true,
					'class'                  => true,
					'id'                     => true,
					'data-wp-on--click'      => true,
					'data-wp-on--change'     => true,
					'data-wp-bind--checked'  => true,
					'data-wp-bind--disabled' => true,
					'aria-label'             => true,
					'aria-checked'           => true,
				);
				// IA directives on existing elements (div/span/button/label).
				foreach ( array( 'div', 'span', 'button', 'label', 'a', 'form', 'select', 'option', 'textarea' ) as $jt_tag ) {
					if ( isset( $jt_post_content_after_tags[ $jt_tag ] ) ) {
						$jt_post_content_after_tags[ $jt_tag ]['data-wp-interactive']    = true;
						$jt_post_content_after_tags[ $jt_tag ]['data-wp-context']        = true;
						$jt_post_content_after_tags[ $jt_tag ]['data-wp-on--click']      = true;
						$jt_post_content_after_tags[ $jt_tag ]['data-wp-on--change']     = true;
						$jt_post_content_after_tags[ $jt_tag ]['data-wp-on--submit']     = true;
						$jt_post_content_after_tags[ $jt_tag ]['data-wp-bind--hidden']   = true;
						$jt_post_content_after_tags[ $jt_tag ]['data-wp-bind--disabled'] = true;
						$jt_post_content_after_tags[ $jt_tag ]['data-wp-class--active']  = true;
					}
				}
				$jt_post_content_after_tags['textarea'] = isset( $jt_post_content_after_tags['textarea'] )
					? $jt_post_content_after_tags['textarea']
					: array(
						'name'        => true,
						'class'       => true,
						'rows'        => true,
						'cols'        => true,
						'placeholder' => true,
					);
				$jt_post_content_after_tags['select']   = isset( $jt_post_content_after_tags['select'] )
					? $jt_post_content_after_tags['select']
					: array(
						'name'  => true,
						'class' => true,
					);
				$jt_post_content_after_tags['option']   = isset( $jt_post_content_after_tags['option'] )
					? $jt_post_content_after_tags['option']
					: array(
						'value'    => true,
						'selected' => true,
					);

				echo wp_kses(
					apply_filters( 'jetonomy_after_post_content', '', $post ),
					$jt_post_content_after_tags
				);
				?>

				<div class="jt-post-foot">
					<?php
					// 1.4.1 voting cleanup: up + down render inside one
					// `.jt-vote-cluster` wrapper for visual grouping + equal-
					// weight presentation. The inner button shape stays
					// identical so view.js's bindings (which read score
					// via `el.ref.querySelector('.n')` and find the sibling
					// down button via `el.ref.parentElement?.querySelector`)
					// keep working — both buttons remain direct siblings
					// inside the cluster.
					?>
					<?php if ( jetonomy_space_allows_voting( $space ) ) : ?>
						<div class="jt-vote-cluster" role="group" aria-label="<?php esc_attr_e( 'Vote on this post', 'jetonomy' ); ?>">
							<?php if ( is_user_logged_in() ) : ?>
							<button class="jt-act <?php echo 1 === $user_post_vote ? 'voted' : ''; ?>"
								data-wp-on--click="actions.voteUp"
								data-post-id="<?php echo absint( $post->id ); ?>"
								title="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>"
								aria-label="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>">
								<?php jetonomy_echo_icon( 'chevron-up', 16 ); ?>
								<span class="n" data-wp-text="state.postScores.<?php echo absint( $post->id ); ?>"><?php echo esc_html( (int) $post->vote_score ); ?></span>
							</button>
								<?php
								// Hide downvote on own content — self-downvote was
								// landing at -1 (Basecamp 9803889865).
								if ( (int) $post->author_id !== get_current_user_id() ) :
									?>
							<button class="jt-act <?php echo -1 === $user_post_vote ? 'voted' : ''; ?>"
								data-wp-on--click="actions.voteDown"
								data-post-id="<?php echo absint( $post->id ); ?>"
								title="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>"
								aria-label="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>">
									<?php jetonomy_echo_icon( 'chevron-down', 16 ); ?>
							</button>
								<?php endif; ?>
							<?php else : ?>
							<span class="jt-act">
								<?php jetonomy_echo_icon( 'chevron-up', 16 ); ?>
								<span class="n"><?php echo esc_html( (int) $post->vote_score ); ?></span>
							</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<?php
					/* translators: %d: number of views */
					$jt_view_count_label = sprintf( _n( '%d view', '%d views', (int) $post->view_count, 'jetonomy' ), (int) $post->view_count );
					?>
					<span class="jt-view-count" title="<?php echo esc_attr( $jt_view_count_label ); ?>" aria-label="<?php echo esc_attr( $jt_view_count_label ); ?>">
						<?php jetonomy_echo_icon( 'eye', 14 ); ?>
						<span class="n"><?php echo esc_html( (int) $post->view_count ); ?></span>
					</span>
				<button class="jt-act jt-share-btn"
					data-wp-on--click="actions.sharePost"
					data-post-url="<?php echo esc_url( \Jetonomy\base_url() . '/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/' ); ?>"
					data-post-title="<?php echo esc_attr( jetonomy_post_title_or_excerpt( $post ) ); ?>"
					title="<?php esc_attr_e( 'Share', 'jetonomy' ); ?>"
					aria-label="<?php esc_attr_e( 'Share', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'link', 16 ); ?></button>
				<?php
				if ( is_user_logged_in() ) :
					$is_bookmarked = \Jetonomy\Models\Bookmark::is_bookmarked( get_current_user_id(), (int) $post->id );
					?>
					<button class="jt-act jt-bookmark-btn <?php echo $is_bookmarked ? esc_attr( 'bookmarked' ) : ''; ?>"
						data-wp-on--click="actions.toggleBookmark"
						data-post-id="<?php echo absint( $post->id ); ?>"
						data-bookmarked="<?php echo esc_attr( $is_bookmarked ? '1' : '0' ); ?>"
						title="<?php echo $is_bookmarked ? esc_attr__( 'Remove bookmark', 'jetonomy' ) : esc_attr__( 'Bookmark', 'jetonomy' ); ?>"
						aria-label="<?php echo $is_bookmarked ? esc_attr__( 'Remove bookmark', 'jetonomy' ) : esc_attr__( 'Bookmark', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'bookmark', 16 ); ?></button>
					<?php if ( (int) $post->author_id !== get_current_user_id() ) : ?>
						<button class="jt-act"
							data-wp-on--click="actions.flagPost"
							data-post-id="<?php echo absint( $post->id ); ?>"
							title="<?php esc_attr_e( 'Report', 'jetonomy' ); ?>"
							aria-label="<?php esc_attr_e( 'Report', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'flag', 16 ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $jt_can_moderate_here || (int) $post->author_id === get_current_user_id() ) : ?>
					<div class="jt-more-menu">
						<button class="jt-act jt-more-trigger" type="button"
							title="<?php esc_attr_e( 'More options', 'jetonomy' ); ?>"
							aria-label="<?php esc_attr_e( 'More options', 'jetonomy' ); ?>"
							data-wp-on--click="actions.toggleMoreMenu"><?php jetonomy_echo_icon( 'more-horizontal', 16 ); ?></button>
						<div class="jt-more-dropdown" hidden>
							<?php if ( (int) $post->author_id === get_current_user_id() || $jt_can_moderate_here ) : ?>
								<button class="jt-more-item"
									data-wp-on--click="actions.editPost"
									data-post-id="<?php echo absint( $post->id ); ?>"><?php jetonomy_echo_icon( 'edit', 14 ); ?> <?php esc_html_e( 'Edit', 'jetonomy' ); ?></button>
								<button class="jt-more-item"
									data-wp-on--click="actions.togglePrivate"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-private="<?php echo esc_attr( ! empty( $post->is_private ) ? '1' : '0' ); ?>"><?php jetonomy_echo_icon( 'lock', 14 ); ?> <?php echo ! empty( $post->is_private ) ? esc_html__( 'Make Public', 'jetonomy' ) : esc_html__( 'Make Private', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( $jt_can_moderate_here ) : ?>
								<button class="jt-more-item"
									data-wp-on--click="actions.pinPost"
									data-post-id="<?php echo absint( $post->id ); ?>"><?php jetonomy_echo_icon( 'pin', 16 ); ?> <?php echo $post->is_sticky ? esc_html__( 'Unpin', 'jetonomy' ) : esc_html__( 'Pin', 'jetonomy' ); ?></button>
								<button class="jt-more-item"
									data-wp-on--click="actions.toggleClose"
									data-post-id="<?php echo absint( $post->id ); ?>"><?php jetonomy_echo_icon( 'lock', 14 ); ?> <?php echo ! empty( $post->is_closed ) ? esc_html__( 'Reopen topic', 'jetonomy' ) : esc_html__( 'Close topic', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( $jt_can_moderate_here ) : ?>
								<button class="jt-more-item"
									data-wp-on--click="actions.movePost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-space-id="<?php echo absint( $post->space_id ); ?>"><?php jetonomy_echo_icon( 'move', 14 ); ?> <?php esc_html_e( 'Move', 'jetonomy' ); ?></button>
								<button class="jt-more-item"
									data-wp-on--click="actions.mergePost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-space-id="<?php echo absint( $post->space_id ); ?>"><?php jetonomy_echo_icon( 'merge', 14 ); ?> <?php esc_html_e( 'Merge', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( (int) $post->author_id === get_current_user_id() || $jt_can_moderate_here ) : ?>
								<button class="jt-more-item jt-more-item--danger"
									data-wp-on--click="actions.deletePost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-space-slug="<?php echo esc_attr( $space->slug ?? '' ); ?>"><?php jetonomy_echo_icon( 'trash', 16 ); ?> <?php esc_html_e( 'Delete', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( $jt_can_moderate_here ) : ?>
								<a class="jt-more-item" href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . (int) $post->space_id ) ); ?>"><?php jetonomy_echo_icon( 'settings', 14 ); ?> <?php esc_html_e( 'Admin', 'jetonomy' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
				<?php do_action( 'jetonomy_post_actions', $post ); ?>
				</div>
			</article>

			<?php
			/**
			 * Fires after the main post article element, before the replies section.
			 * Prime slot for ads, related posts, or CTAs between topic and replies.
			 *
			 * Note: Named `_article` (not `_content`) to avoid collision with the
			 * existing `jetonomy_after_post_content` FILTER that injects HTML inside
			 * the post body (see single-post.php:269).
			 *
			 * @param object $post Current post object.
			 */
			do_action( 'jetonomy_after_post_article', $post );
			?>

			<!-- Replies -->
			<div class="jt-replies-section" id="replies"
				data-wp-interactive="jetonomy"
				data-wp-init--polling="callbacks.initReplyPolling"
				data-wp-context='
				<?php
				echo wp_json_encode(
					[
						'postId'        => (int) $post->id,
						'totalReplies'  => $total_replies,
						'topLevelCount' => $top_level_count,
						'sort'          => $reply_sort,
						'hasMore'       => false,
						'loadingMore'   => false,
					]
				);
				?>
				'>

				<div class="jt-replies-head">
					<h2>
						<?php esc_html_e( 'Replies', 'jetonomy' ); ?>
						<span class="jt-count-pill"><?php echo esc_html( (int) $total_replies ); ?></span>
					</h2>
					<div class="jt-replies-controls">
						<div class="jt-pills">
							<?php
							$reply_sorts = [
								'oldest' => __( 'Oldest', 'jetonomy' ),
								'newest' => __( 'Newest', 'jetonomy' ),
								'best'   => __( 'Best', 'jetonomy' ),
							];
							foreach ( $reply_sorts as $key => $label ) :
								$rsort_url = add_query_arg( [ 'rsort' => $key ], $post_url );
								?>
								<a href="<?php echo esc_url( $rsort_url ); ?>#replies"
									class="jt-pill <?php echo $reply_sort === $key ? esc_attr( 'on' ) : ''; ?>"
									<?php echo $reply_sort === $key ? 'aria-current="true"' : ''; ?>>
									<?php echo esc_html( $label ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<?php
				/**
				 * Fires before the replies list renders (above both empty state and populated list).
				 * Ad slot / announcement injection point above replies.
				 *
				 * @param object $post         Current post object.
				 * @param int    $total_replies Total reply count.
				 */
				do_action( 'jetonomy_before_replies', $post, $total_replies );
				?>

				<?php
				// Q&A: surface the accepted answer above the chronological
				// thread so members read it first regardless of sort. The
				// reply still renders in its original position below — this
				// is a pinned echo, not a relocation, so the conversation
				// flow stays intact for the curious.
				$jt_accepted_reply = null;
				if (
					$space
					&& 'qa' === ( $space->type ?? '' )
					&& ! empty( $post->accepted_reply_id )
				) {
					$jt_accepted_reply = \Jetonomy\Models\Reply::find( (int) $post->accepted_reply_id );
				}
				if ( $jt_accepted_reply ) :
					?>
					<aside class="jt-accepted-callout" aria-label="<?php esc_attr_e( 'Accepted answer', 'jetonomy' ); ?>">
						<header class="jt-accepted-callout-head">
							<?php jetonomy_echo_icon( 'check-circle', 16 ); ?>
							<span><?php esc_html_e( 'Accepted answer', 'jetonomy' ); ?></span>
						</header>
						<div class="jt-accepted-callout-body">
							<?php
							\Jetonomy\Template_Loader::partial(
								'reply-card',
								array(
									'reply' => $jt_accepted_reply,
									'post'  => $post,
									'space' => $space,
								)
							);
							?>
						</div>
					</aside>
				<?php endif; ?>

				<?php if ( empty( $reply_batch ) ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						[
							'icon'    => 'empty-replies',
							'message' => __( 'No replies yet. Be the first to reply!', 'jetonomy' ),
						]
					);
					?>
				<?php else : ?>

					<div class="jt-replies-list" id="jt-replies-container">
						<?php foreach ( $reply_batch as $index => $reply ) : ?>
							<?php jetonomy_render_threaded_reply( $reply, $post, 0, $space ); ?>
							<?php
							/**
							 * Fires after each top-level reply in the replies list.
							 * Ad slot / injection between replies (e.g. every Nth reply).
							 *
							 * @param object $reply Reply object just rendered.
							 * @param int    $index Zero-based index within the batch.
							 * @param object $post  Current post object.
							 */
							do_action( 'jetonomy_between_replies', $reply, $index, $post );
							?>
						<?php endforeach; ?>
					</div>

					<?php
					\Jetonomy\Template_Loader::partial(
						'pagination',
						array(
							'has_more'  => $replies_have_more,
							'param_key' => 'rpg',
							'target'    => '#jt-replies-container',
						)
					);
					?>

				<?php endif; ?>

				<?php
				/**
				 * Fires after the replies list renders.
				 * Ad slot / CTA injection below replies, above the composer.
				 *
				 * @param object $post          Current post object.
				 * @param int    $total_replies Total reply count.
				 */
				do_action( 'jetonomy_after_replies', $post, $total_replies );
				?>
			</div>

			<!-- Composer -->
			<?php if ( $post->is_closed ) : ?>
				<div class="jt-closed-notice">
					<?php esc_html_e( 'This post is closed and no longer accepts replies.', 'jetonomy' ); ?>
				</div>
			<?php elseif ( is_user_logged_in() ) : ?>
				<div class="jt-reply-composer" id="jt-composer">
					<h3>
						<?php esc_html_e( 'Your Reply', 'jetonomy' ); ?>
					</h3>
					<?php
					\Jetonomy\Template_Loader::partial(
						'composer',
						[
							'post_id'  => (int) $post->id,
							'post_url' => $post_url,
						]
					);
					?>
				</div>
			<?php else : ?>
				<div class="jt-login-prompt">
					<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log in to reply', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
	</div>
