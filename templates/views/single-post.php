<?php
/**
 * Single post view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$post_slug = $data['slug'] ?? '';
$post      = \Jetonomy\Models\Post::find_by_slug( $post_slug );

// One not-found state for a missing topic and one the viewer may not read (a
// 404 either way, so a private topic's existence is not disclosed). It was a
// dead end; it now says what happened and leads back to the community.
$jt_not_found = static function (): void {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		array(
			'icon'        => 'empty-search',
			'icon_size'   => 48,
			'message'     => sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( '%s not found.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic' ) ),
			'description' => sprintf( /* translators: %s: the singular topic label, lowercase. */ __( 'This %s may have been removed, or the link is wrong.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ),
			'cta_label'   => __( 'Back to the community', 'jetonomy' ),
			'cta_url'     => \Jetonomy\base_url(),
		)
	);
};

if ( ! $post ) {
	$jt_not_found();
	return;
}

// Every "can moderate" check below uses Permission_Engine::can() so a space-role
// moderator (subscriber WP role with admin/moderator role on jt_space_members) gets the
// same UI affordances as a WP-cap moderator; a `current_user_can('jetonomy_moderate')`
// cap-only gate would hide edit / pin / delete / move / merge from every space mod whose
// WP role lacks the cap.
$jt_viewer_id         = get_current_user_id();
$jt_can_moderate_here = $jt_viewer_id
	? \Jetonomy\Permissions\Permission_Engine::can( $jt_viewer_id, 'moderate', (int) $post->space_id )
	: false;

// Only a published topic takes member writes: Content_Gate refuses replies,
// votes, reactions, bookmarks, follows and pins on anything else with a 409
// Every write control below keys off this one flag
// so the page never offers what the server refuses. The status notice (with
// Restore / Delete permanently for moderators on trash) still renders.
$jt_is_live = 'publish' === $post->status;

// The non-published (pending / trash / spam) gate lives in
// Permission_Engine::can_read_post() below - the author-or-moderator rule, same 404 +
// "Post not found" outcome - so the REST route, oEmbed, JSON-LD and the Pro extensions
// enforce it too, all of which read posts through that method.

$space = \Jetonomy\Models\Space::find( (int) $post->space_id );

if ( $space && in_array( $space->visibility, array( 'private', 'hidden' ), true ) ) {
	$user_id = get_current_user_id();
	// Admission is membership OR an access rule, never membership alone -
	// Space::admitted() is the one answer (its own docblock says so). This
	// gate asked SpaceMember::is_member() directly, so a viewer admitted by
	// a rule (a Learnomy course or learning-space level, a membership plan)
	// could browse the space and post through the Permission Engine, then
	// hit "private space" opening the very post they wrote (2026-08-06).
	if ( ! $user_id || ! \Jetonomy\Models\Space::admitted( (int) $space->id, $user_id ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			// A hidden space is not discoverable, so its topics answer exactly
			// like a missing one: a different message confirmed a guessed URL.
			if ( 'hidden' === $space->visibility ) {
				$jt_not_found();
				return;
			}
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				array(
					/* translators: %s: singular space label. */
					'message' => sprintf( __( 'This content is in a private %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					'tone'    => 'forbidden',
				)
			);
			return;
		}
	}
}

// Per-post privacy gate. Before 1.3.6 a subscriber with space access could
// reach a private topic via direct URL because neither the template nor the
// status/visibility checks above looked at is_private on the post itself
// Permission_Engine::can_read_post() is the single
// source of truth — author + manage_options + space mod/admin only.
if ( ! \Jetonomy\Permissions\Permission_Engine::can_read_post( get_current_user_id(), $post ) ) {
	$jt_not_found();
	return;
}

// Blocked-author tombstone. A deep link (notification, share, search result
// that predates a block) can land here even though every list surface
// already filters this author out. We do NOT 404 — the post genuinely
// exists and moderators/deep-linkers need a real state, not "not found" —
// we just refuse to ship the content to a viewer who blocked its author.
// Routed through the shared seam (rather than an inline in_array()) so this screen and
// the REST payload decide "is blocked" the same way; it also empties the row's
// title/body, so the early return below is not the only thing standing between a
// blocked author's words and the page.
\Jetonomy\Models\Post::apply_block_tombstone(
	$post,
	\Jetonomy\Models\BlockedUser::blocked_ids( get_current_user_id() )
);
if ( ! empty( $post->is_blocked_author ) ) {
	?>
	<div class="jt-post-blocked-tombstone" data-wp-interactive="jetonomy">
		<?php jetonomy_echo_icon( 'shield', 32 ); ?>
		<p><?php esc_html_e( 'Content hidden because you blocked this user.', 'jetonomy' ); ?></p>
		<button class="jt-btn jt-btn-ghost" type="button"
			data-wp-on--click="actions.unblockUser"
			data-user-id="<?php echo (int) $post->author_id; ?>">
			<?php esc_html_e( 'Unblock this user', 'jetonomy' ); ?>
		</button>
	</div>
	<?php
	return;
}

// Anonymous-posting leak-audit fix: the single-post header rendered the raw
// author via get_userdata() directly, bypassing Author::for_display() — the
// same mask every listing/reply/feed card already routes through. That left
// the one page most likely to be viewed showing the real name on an
// is_anonymous=1 topic. `$jt_author_display['id']` is 0 (name "Anonymous",
// no avatar/url) whenever the viewer isn't granted a reveal.
$jt_author_display = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
$jt_author_masked  = (int) $jt_author_display['id'] !== (int) $post->author_id;
$profile           = \Jetonomy\Models\UserProfile::find_by_user( (int) $post->author_id );
$tags              = \Jetonomy\Models\Tag::list_for_post( (int) $post->id );
$category          = ( $space && $space->category_id ) ? \Jetonomy\Models\Category::find_visible( (int) $space->category_id ) : null;

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

// Replies sort. The DEFAULT is a contract, not a preference: reply deep links
// (\Jetonomy\reply_permalink()) carry no ?rsort so that the page they computed
// under Reply::DEFAULT_SORT is the page this view renders.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reply_sort = isset( $_GET['rsort'] ) ? sanitize_key( $_GET['rsort'] ) : \Jetonomy\Models\Reply::DEFAULT_SORT;
if ( ! in_array( $reply_sort, array( 'oldest', 'newest', 'best' ), true ) ) {
	$reply_sort = \Jetonomy\Models\Reply::DEFAULT_SORT;
}
// Top-level reply pagination, honouring the global `replies_per_page`
// setting. Server renders the requested page; pagination-frontend.js
// fetches the next page and appends in place. No-JS users get classic
// full-page pagination via the ?rpg=N anchor.
$total_replies   = (int) $post->reply_count;
$top_level_count = \Jetonomy\Models\Reply::count_top_level( (int) $post->id );
// Via the shared helper, NOT a raw get_option(): \Jetonomy\reply_permalink()
// computes which page a deep-linked reply lands on using this same value. If
// the view and the link-builder ever read the setting differently, every
// notification link silently lands on the wrong page.
$replies_per_page = \Jetonomy\replies_per_page();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reply_page        = max( 1, absint( wp_unslash( $_GET['rpg'] ?? 1 ) ) );
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
$jt_role_warm_ids = array( (int) $post->author_id );
$jt_reply_ids     = array();
$jt_role_walker   = function ( array $list ) use ( &$jt_role_warm_ids, &$jt_reply_ids, &$jt_role_walker ): void {
	foreach ( $list as $reply ) {
		$jt_role_warm_ids[] = (int) ( $reply->author_id ?? 0 );
		$jt_reply_ids[]     = (int) ( $reply->id ?? 0 );
		if ( ! empty( $reply->children ) ) {
			$jt_role_walker( $reply->children );
		}
	}
};
$jt_role_walker( $reply_batch );
\Jetonomy\Models\SpaceMember::warm_role_cache( (int) $post->space_id, $jt_role_warm_ids );

// Q&A accepted-answer placement. The accepted reply already renders inline in
// the chronological thread with its own "Accepted" styling (reply-card.php).
// The pinned callout above the thread exists only to surface that answer when
// it is buried on a LATER page — so we suppress the callout whenever the
// accepted reply is already visible on the current page. Skipping it in the
// render loop instead would desync pagination (the offset slice would render
// one short) and orphan the accepted reply's child replies, which breaks on
// the 200-400 reply threads this is built for.
$jt_accepted_reply_id = (int) ( $post->accepted_reply_id ?? 0 );
$jt_accepted_on_page  = $jt_accepted_reply_id
	&& \Jetonomy\Models\Reply::tree_contains( $reply_batch, $jt_accepted_reply_id );

// Prime attachments for exactly what this page renders: the paged tree
// (walked above) plus the off-page accepted-answer card, which is by
// construction NOT in $reply_batch when the callout shows.
// The hook-driven whole-thread primes then no-op for this post.
$jt_prime_ids = $jt_reply_ids;
if ( $jt_accepted_reply_id && ! $jt_accepted_on_page ) {
	$jt_prime_ids[] = $jt_accepted_reply_id;
}
\Jetonomy\Models\Attachment::prime_rendered_replies( (int) $post->id, $jt_prime_ids );
// Publish the rendered set for hook-driven primes (Pro reactions reads it
// on jetonomy_before_replies).
\Jetonomy\Models\Reply::set_rendered_ids( (int) $post->id, $jt_prime_ids );

// Batch the per-reply-card lookups over the SAME rendered set —
// author profiles and the viewer's reply votes. The walker covers nested
// children, so reply-card.php's per-row Vote::get_user_vote (30+ point
// queries on a full page) reads the memo instead.
\Jetonomy\Models\UserProfile::prime( $jt_role_warm_ids );
$jt_vote_viewer = get_current_user_id();
if ( $jt_vote_viewer > 0 && ! empty( $jt_prime_ids ) ) {
	\Jetonomy\Models\Vote::user_votes_map( $jt_vote_viewer, 'reply', $jt_prime_ids );
}

// Current user vote on post.
$user_id        = get_current_user_id();
$user_post_vote = $user_id ? \Jetonomy\Models\Vote::get_user_vote( $user_id, 'post', (int) $post->id ) : null;

// Breadcrumb.
$crumbs = array();
if ( $category ) {
	$crumbs[] = array(
		'label' => $category->name,
		'url'   => '',
	);
}
if ( $space ) {
	$crumbs[] = array(
		'label' => $space->title,
		'url'   => $base . '/s/' . $space->slug . '/',
	);
}
$crumbs[] = array(
	'label' => (string) ( $post->title ?? '' ),
	'url'   => '',
);

// Server-side state for IA.
$post_scores = array( (int) $post->id => (int) $post->vote_score );
wp_interactivity_state(
	'jetonomy',
	array(
		'currentPostId' => (int) $post->id,
		'postScores'    => $post_scores,
		'replyScores'   => array(),
		'activeReply'   => 0,
		'submitting'    => false,
		'replyToId'     => null,
		'replyToAuthor' => '',
	)
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
function jetonomy_render_threaded_reply( $reply, $post, $depth = 0, $space = null, $page = 1 ) {
	$depth         = isset( $reply->depth ) ? (int) $reply->depth : $depth;
	$wrapper_class = $depth > 0 ? 'jt-nested jt-nested-' . min( $depth, 3 ) : '';
	?>
	<div class="<?php echo esc_attr( $wrapper_class ); ?>">
		<?php
		\Jetonomy\Template_Loader::partial(
			'reply-card',
			array(
				'reply'          => $reply,
				'post'           => $post,
				'space'          => $space,
				// This page was just rendered, so every card on it — including
				// nested children, which page with their top-level ancestor —
				// permalinks to it. Passing it keeps reply_permalink() from
				// paying a COUNT per card.
				'permalink_page' => (int) $page,
			)
		);
		?>
		<?php
		// Every child says whom it answers (the masked name for an anonymous parent), at any depth.
		if ( ! empty( $reply->children ) ) {
			$jt_parent_name = \Jetonomy\Author::for_display( (int) $reply->author_id, $reply )['name'];
			foreach ( $reply->children as $child ) {
				$child->jt_parent_name = '' !== $jt_parent_name ? $jt_parent_name : __( 'Anonymous', 'jetonomy' );
			}
		}
		?>
		<?php if ( $depth === 0 && ! empty( $reply->children ) ) : ?>
			<div class="jt-thread-toggle" data-wp-interactive="jetonomy"
				data-wp-context='{"collapsed": false, "childCount": <?php echo (int) count( $reply->children ); ?>}'>
				<button class="jt-thread-toggle-btn" data-wp-on--click="actions.toggleThread"
					data-wp-text="state.threadToggleLabel">
					&minus; <?php printf( /* translators: %s: the plural label of the item (the configured noun). */ esc_html__( 'Hide %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?>
				</button>
				<div class="jt-thread-children" data-wp-class--collapsed="context.collapsed">
					<?php foreach ( $reply->children as $child ) : ?>
						<?php jetonomy_render_threaded_reply( $child, $post, $depth + 1, $space, $page ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php elseif ( ! empty( $reply->children ) ) : ?>
			<?php foreach ( $reply->children as $child ) : ?>
				<?php jetonomy_render_threaded_reply( $child, $post, $depth + 1, $space, $page ); ?>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}
?>
<?php \Jetonomy\Template_Loader::breadcrumb( $crumbs ); ?>

<div class="jt-two-col">
		<main>
			<?php \Jetonomy\Template_Loader::breadcrumb_in_main(); ?>
			<?php if ( 'publish' !== $post->status ) : ?>
				<div class="jt-notice jt-notice-warning">
					<?php
					if ( 'pending' === $post->status ) {
						echo esc_html( sprintf( /* translators: %s: singular topic label. */ __( 'This %s is pending review and not yet publicly visible.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ) );
					} elseif ( 'spam' === $post->status ) {
						echo esc_html( sprintf( /* translators: %s: singular topic label. */ __( 'This %s has been marked as spam.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ) );
					} elseif ( 'trash' === $post->status ) {
						echo esc_html( sprintf( /* translators: %s: singular topic label. */ __( 'This %s is in the trash and hidden from the community.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ) );
					} elseif ( 'draft' === $post->status && ! empty( $post->published_at ) ) {
						// published_at is UTC; show it in the site timezone.
						echo esc_html(
							sprintf(
								/* translators: %s: scheduled publish date and time. */
								__( 'Scheduled to publish on %s. It is hidden from the community until then.', 'jetonomy' ),
								get_date_from_gmt( $post->published_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
							)
						);
					} elseif ( 'draft' === $post->status ) {
						esc_html_e( 'This is a draft and is hidden from the community until it is published.', 'jetonomy' );
					} else {
						/* translators: 1: singular topic label; 2: the raw status slug. */
						echo esc_html( sprintf( __( 'This %1$s has status: %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ), $post->status ) );
					}
					?>
					<?php
					// A trashed topic reaches this template only for its author
					// and for moderators (can_read_post). Moderators get the way
					// back - or out - right here, the one frontend surface a
					// trashed topic has. The author does not: trashing is theirs,
					// restoring and destroying are a moderator's call.
					if ( 'trash' === $post->status && $jt_can_moderate_here ) :
						?>
						<div class="jt-notice-actions">
							<button type="button" class="jt-btn jt-btn-fill jt-btn-sm"
								data-wp-interactive="jetonomy"
								data-wp-on--click="actions.trashedPostAction"
								data-rest-method="POST"
								data-rest-path="<?php echo esc_attr( '/spaces/' . (int) $post->space_id . '/moderation/approve/post/' . (int) $post->id ); ?>">
								<?php esc_html_e( 'Restore', 'jetonomy' ); ?>
							</button>
							<button type="button" class="jt-btn jt-btn-ghost jt-btn-danger jt-btn-sm"
								data-wp-interactive="jetonomy"
								data-wp-on--click="actions.trashedPostAction"
								data-rest-method="DELETE"
								data-rest-path="<?php echo esc_attr( '/posts/' . (int) $post->id . '?force=true' ); ?>"
								data-confirm="<?php echo esc_attr( sprintf( /* translators: 1: singular topic label; 2: plural reply label. */ __( 'Delete this %1$s and all its %2$s permanently? This cannot be undone.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?>"
								data-redirect="<?php echo esc_url( $space ? \Jetonomy\base_url() . '/s/' . $space->slug . '/mod/?view=trash' : \Jetonomy\base_url() . '/' ); ?>">
								<?php esc_html_e( 'Delete permanently', 'jetonomy' ); ?>
							</button>
						</div>
					<?php endif; ?>
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
					$jt_show_follow = is_user_logged_in() && $jt_is_live;
					if ( $jt_show_follow ) {
						$is_following = \Jetonomy\Models\Subscription::is_subscribed( get_current_user_id(), 'post', (int) $post->id );
					}
					if ( $jt_h1_is_sr_only ) :
						?>
						<h1 class="screen-reader-text"><?php echo esc_html( $jt_h1_label ); ?></h1>
					<?php else : ?>
						<div class="jt-post-title-row">
							<?php // dir="auto" on the prefix keeps its words out of the title's direction guess. ?>
							<h1 dir="auto">
								<?php if ( $prefix_name ) : ?>
									<span class="jt-prefix" dir="auto"
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
							<span class="jt-idea-status-setter-label"><?php esc_html_e( 'Roadmap status', 'jetonomy' ); ?></span>
							<div class="jt-idea-status-options">
								<?php if ( '' === $jt_current_status ) : ?>
									<?php // The current state, not a choice: it hides once a stage is picked. ?>
									<span class="jt-idea-pill jt-idea-status-btn jt-idea-status-unset is-active"><?php esc_html_e( 'Not set', 'jetonomy' ); ?></span>
								<?php endif; ?>
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
						// Anonymous-posting mask: same split-render pattern as
						// reply-card.php — get_user_link() renders the avatar
						// (icon-only silhouette + no link when $jt_author_display['id']
						// is 0), name rendered separately so "Anonymous" always shows
						// even though get_user_link()'s show_name path requires a
						// resolvable WP user. Trusted, fully-escaped plugin markup
						// (incl. Lucide SVG avatar). Echo direct.
						echo \Jetonomy\get_user_link( (int) $jt_author_display['id'], 'jt-avatar-md', 36, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						if ( '' !== $jt_author_display['url'] ) :
							?>
							<a class="jt-user-link" href="<?php echo esc_url( $jt_author_display['url'] ); ?>">
								<span class="jt-user-name"><?php echo esc_html( $jt_author_display['name'] ); ?></span>
							</a>
							<?php
						else :
							?>
							<span class="jt-user-name"><?php echo esc_html( '' !== $jt_author_display['name'] ? $jt_author_display['name'] : __( 'Anonymous', 'jetonomy' ) ); ?></span>
							<?php
						endif;
						?>
						<span>
							<?php
							/* translators: %s: human-readable time difference. */
							echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
							?>
						</span>
						<?php \Jetonomy\Template_Loader::partial( 'edited-mark', array( 'item' => $post ) ); ?>
						<?php if ( ! empty( $post->is_private ) ) : ?>
							<span class="jt-badge-private">
								<?php jetonomy_echo_icon( 'lock', 14 ); ?> <?php esc_html_e( 'Private', 'jetonomy' ); ?>
							</span>
						<?php endif; ?>
						<?php if ( ! empty( $post->is_sticky ) ) : ?>
							<span class="jt-badge-pinned">
								<?php jetonomy_echo_icon( 'pin', 14 ); ?> <?php esc_html_e( 'Pinned', 'jetonomy' ); ?>
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
						<?php
						/**
						 * Mirror the listing-card badge hook on the single-post
						 * header so Pro markers (notably the site-wide
						 * "Announcement" badge) show here too, not just in listings.
						 */
						do_action( 'jetonomy_post_card_after_badges', $post, $space );
						?>
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

				<div class="jt-post-body" dir="auto">
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
				// Shared allow-list (helpers.php) — permits poll inputs AND
				// attachment-card markup. Single source of truth with
				// reply-card.php so the two after-content slots never drift.
				$jt_post_content_after_tags = jetonomy_after_content_allowed_html();

				/**
				 * Filters markup printed after the post body. The result is passed through wp_kses() with the shared after-content allowlist.
				 *
				 * @since 1.0.0
				 *
				 * @param string $html Markup to print. Default empty.
				 * @param object $post Post row.
				 * @return string Markup to print.
				 */
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
						<div class="jt-vote-cluster" role="group" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: the singular label of the item (the configured noun). */ __( 'Vote on this %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ) ); ?>">
							<?php // "may actually vote here", not just "logged in": a Read-grant rule admits without granting the vote, and the server 403s the vote. ?>
							<?php if ( $jt_is_live && jetonomy_viewer_can_vote( $space ) ) : ?>
							<button class="jt-act <?php echo 1 === $user_post_vote ? 'voted' : ''; ?>"
								aria-pressed="<?php echo 1 === $user_post_vote ? 'true' : 'false'; ?>"
								data-wp-on--click="actions.voteUp"
								data-post-id="<?php echo absint( $post->id ); ?>"
								<?php echo jetonomy_vote_attrs( 'up', 1 === $user_post_vote ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>>
								<?php jetonomy_echo_icon( 'chevron-up', 16 ); ?>
								<span class="n" data-wp-text="state.postScores.<?php echo absint( $post->id ); ?>"><?php echo esc_html( (int) $post->vote_score ); ?></span>
							</button>
								<?php
								// Hide downvote on own content — self-downvote was
								// landing at -1.
								if ( (int) $post->author_id !== get_current_user_id() ) :
									?>
							<button class="jt-act <?php echo -1 === $user_post_vote ? 'voted' : ''; ?>"
								aria-pressed="<?php echo -1 === $user_post_vote ? 'true' : 'false'; ?>"
								data-wp-on--click="actions.voteDown"
								data-post-id="<?php echo absint( $post->id ); ?>"
									<?php echo jetonomy_vote_attrs( 'down', -1 === $user_post_vote ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>>
									<?php jetonomy_echo_icon( 'chevron-down', 16 ); ?>
							</button>
								<?php endif; ?>
							<?php elseif ( ! is_user_logged_in() ) : ?>
								<?php
								// Logged-out: the vote control was an inert read-only
								// span — clicking it did nothing, leaving a visitor who
								// wanted to vote stuck. Make it a link to log in (and
								// return here), so the intent has somewhere to go.
								?>
							<a class="jt-act jt-act-login" href="<?php echo esc_url( wp_login_url( \Jetonomy\current_url() ) ); ?>"
								title="<?php esc_attr_e( 'Log in to vote', 'jetonomy' ); ?>"
								aria-label="<?php esc_attr_e( 'Log in to vote', 'jetonomy' ); ?>">
								<?php jetonomy_echo_icon( 'chevron-up', 16 ); ?>
								<span class="n"><?php echo esc_html( (int) $post->vote_score ); ?></span>
							</a>
							<?php else : ?>
								<?php jetonomy_vote_denied_control( (int) $post->vote_score, 16 ); ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<?php
					/* translators: %d: number of views */
					$jt_view_count_label = sprintf( _n( '%d view', '%d views', (int) $post->view_count, 'jetonomy' ), (int) $post->view_count );
					?>
					<?php // Only a published topic counts a view; the endpoint refuses anything else, so a pending/draft/trashed page would just log a 404. ?>
					<span class="jt-view-count"<?php echo 'publish' === ( $post->status ?? '' ) ? ' data-jt-view-post="' . absint( $post->id ) . '"' : ''; ?> title="<?php echo esc_attr( $jt_view_count_label ); ?>" aria-label="<?php echo esc_attr( $jt_view_count_label ); ?>">
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
				if ( is_user_logged_in() && $jt_is_live ) :
					$is_bookmarked = \Jetonomy\Models\Bookmark::is_bookmarked( get_current_user_id(), (int) $post->id );
					?>
					<button class="jt-act jt-bookmark-btn <?php echo $is_bookmarked ? esc_attr( 'bookmarked' ) : ''; ?>"
						data-wp-on--click="actions.toggleBookmark"
						data-post-id="<?php echo absint( $post->id ); ?>"
						data-bookmarked="<?php echo esc_attr( $is_bookmarked ? '1' : '0' ); ?>"
						aria-pressed="<?php echo esc_attr( $is_bookmarked ? 'true' : 'false' ); ?>"
						title="<?php echo $is_bookmarked ? esc_attr__( 'Remove bookmark', 'jetonomy' ) : esc_attr__( 'Bookmark', 'jetonomy' ); ?>"
						aria-label="<?php echo $is_bookmarked ? esc_attr__( 'Remove bookmark', 'jetonomy' ) : esc_attr__( 'Bookmark', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'bookmark', 16 ); ?></button>
					<?php if ( (int) $post->author_id !== get_current_user_id() ) : ?>
						<?php
						// Mark the report button if the current user already filed
						// an open flag on this post. Without this, the user can
						// click, fill the reason form, hit submit and only THEN
						// learn the server rejected it as a duplicate — wasted UX.
						// Mirrors the bookmark "is_bookmarked" pattern above.
						$jt_already_flagged = (bool) \Jetonomy\Models\Flag::find_by_reporter_and_object(
							get_current_user_id(),
							'post',
							(int) $post->id
						);
						?>
						<button class="jt-act <?php echo $jt_already_flagged ? 'is-flagged' : ''; ?>"
							data-wp-on--click="actions.flagPost"
							data-post-id="<?php echo absint( $post->id ); ?>"
							data-flagged="<?php echo esc_attr( $jt_already_flagged ? '1' : '0' ); ?>"
							title="<?php echo $jt_already_flagged ? esc_attr__( 'You have reported this', 'jetonomy' ) : esc_attr__( 'Report', 'jetonomy' ); ?>"
							aria-label="<?php echo $jt_already_flagged ? esc_attr__( 'You have reported this', 'jetonomy' ) : esc_attr__( 'Report', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'flag', 16 ); ?></button>
						<?php
						// Moderator-only: if this topic has open reports, surface a count
						// linking to the space moderation queue so a mod can act on it
						// while reading, not just from the queue.
						if ( $jt_can_moderate_here ) :
							// Denormalised counter (maintained on flag create/resolve) — a
							// column read, no per-post query, so this also scales to listings.
							$jt_open_flags = (int) ( $post->flag_count ?? 0 );
							if ( $jt_open_flags > 0 ) :
								?>
								<a class="jt-act jt-flagged-indicator" href="<?php echo esc_url( $base . '/s/' . ( $space->slug ?? '' ) . '/mod/' ); ?>"
									title="
									<?php
									/* translators: %d: number of open reports on this topic. */
									echo esc_attr( sprintf( _n( '%d open report - review in the moderation queue', '%d open reports - review in the moderation queue', $jt_open_flags, 'jetonomy' ), $jt_open_flags ) );
									?>
									">
									<?php jetonomy_echo_icon( 'flag', 16 ); ?>
									<span class="jt-flagged-count"><?php echo (int) $jt_open_flags; ?></span>
								</a>
								<?php
							endif;
						endif;
						?>
					<?php endif; ?>
				<?php endif; ?>
				<?php
				// A trashed topic is restored or deleted from the notice above,
				// by a moderator. Edit / Make Private / Delete on it did nothing
				// useful (DELETE on a trashed post is a 409), so its author has
				// no menu at all and moderators keep only the moderation tools.
				$jt_is_trashed = 'trash' === $post->status;
				$jt_is_author  = (int) $post->author_id === $jt_viewer_id;
				// One flag per action, asked exactly as the REST endpoint asks it,
				// so the menu offers what the owner's Permissions screen grants and
				// nothing the server would refuse.
				$jt_pe        = \Jetonomy\Permissions\Permission_Engine::class;
				$jt_sid       = (int) $post->space_id;
				$jt_can_edit  = $jt_viewer_id && ! $jt_is_trashed && ( ( $jt_is_author && $jt_pe::can( $jt_viewer_id, 'edit_own_posts', $jt_sid ) ) || $jt_pe::can( $jt_viewer_id, 'edit_others_posts', $jt_sid ) );
				$jt_can_del   = $jt_viewer_id && ! $jt_is_trashed && ( ( $jt_is_author && $jt_pe::can( $jt_viewer_id, 'delete_own_posts', $jt_sid ) ) || $jt_pe::can( $jt_viewer_id, 'delete_others_posts', $jt_sid ) );
				$jt_can_pin   = $jt_viewer_id && $jt_is_live && $jt_pe::can( $jt_viewer_id, 'pin_posts', $jt_sid );
				$jt_can_close = $jt_viewer_id && $jt_is_live && $jt_pe::can( $jt_viewer_id, 'close_posts', $jt_sid );
				$jt_can_move  = $jt_viewer_id && $jt_pe::can( $jt_viewer_id, 'move_posts', $jt_sid );
				?>
				<?php if ( $jt_can_edit || $jt_can_del || $jt_can_pin || $jt_can_close || $jt_can_move || current_user_can( 'jetonomy_manage_settings' ) ) : ?>
					<div class="jt-more-menu">
						<button class="jt-act jt-more-trigger" type="button"
							title="<?php esc_attr_e( 'More options', 'jetonomy' ); ?>"
							aria-label="<?php esc_attr_e( 'More options', 'jetonomy' ); ?>"
							data-wp-on--click="actions.toggleMoreMenu"><?php jetonomy_echo_icon( 'more-horizontal', 16 ); ?></button>
						<div class="jt-more-dropdown" hidden>
							<?php if ( $jt_can_edit ) : ?>
								<button class="jt-more-item"
									data-wp-on--click="actions.editPost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-edit-token="<?php echo esc_attr( \Jetonomy\Models\Post::edit_token( $post, \Jetonomy\Models\Post::EDIT_FIELDS ) ); ?>"><?php jetonomy_echo_icon( 'edit', 14 ); ?> <?php esc_html_e( 'Edit', 'jetonomy' ); ?></button>
								<button class="jt-more-item"
									data-wp-on--click="actions.togglePrivate"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-private="<?php echo esc_attr( ! empty( $post->is_private ) ? '1' : '0' ); ?>"><?php jetonomy_echo_icon( 'lock', 14 ); ?> <?php echo ! empty( $post->is_private ) ? esc_html__( 'Make Public', 'jetonomy' ) : esc_html__( 'Make Private', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( $jt_can_pin ) : ?>
								<?php
								/*
								 * "Pin to space", not bare "Pin". Pro's
								 * site-announcements extension adds a "Pin to
								 * community" button to this same post, which pins
								 * to the top of EVERY space. Against that, an
								 * unqualified "Pin" does not say where it pins, and
								 * a moderator has to already know two pin scopes
								 * exist to read it correctly. Naming both by their
								 * scope makes them a matched pair, not a guess.
								 */
								$jt_pin_label = $post->is_sticky
									/* translators: %s: singular space label. */
									? sprintf( __( 'Unpin from %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) )
									/* translators: %s: singular space label. */
									: sprintf( __( 'Pin to %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) );
								?>
								<button class="jt-more-item"
									data-wp-on--click="actions.pinPost"
									data-pin="<?php echo $post->is_sticky ? '0' : '1'; ?>"
									data-post-id="<?php echo absint( $post->id ); ?>"><?php jetonomy_echo_icon( 'pin', 16 ); ?> <?php echo esc_html( $jt_pin_label ); ?></button>
							<?php endif; ?>
							<?php if ( $jt_can_close ) : ?>
									<button class="jt-more-item"
										data-wp-on--click="actions.toggleClose"
										data-close="<?php echo empty( $post->is_closed ) ? '1' : '0'; ?>"
										data-post-id="<?php echo absint( $post->id ); ?>"><?php jetonomy_echo_icon( 'lock', 14 ); ?> <?php echo ! empty( $post->is_closed ) ? esc_html( sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Reopen %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ) ) : esc_html( sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'Close %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ) ); ?></button>
							<?php endif; ?>
							<?php if ( $jt_can_move ) : ?>
								<button class="jt-more-item"
									data-wp-on--click="actions.movePost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-space-id="<?php echo absint( $post->space_id ); ?>"><?php jetonomy_echo_icon( 'move', 14 ); ?> <?php esc_html_e( 'Move', 'jetonomy' ); ?></button>
								<?php // Merge moves replies across topics, so REST gates it on move_posts too. ?>
								<button class="jt-more-item"
									data-wp-on--click="actions.mergePost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-space-id="<?php echo absint( $post->space_id ); ?>"><?php jetonomy_echo_icon( 'merge', 14 ); ?> <?php esc_html_e( 'Merge', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( $jt_can_del ) : ?>
								<button class="jt-more-item jt-more-item--danger"
									data-wp-on--click="actions.deletePost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-space-slug="<?php echo esc_attr( $space->slug ?? '' ); ?>"><?php jetonomy_echo_icon( 'trash', 16 ); ?> <?php esc_html_e( 'Delete', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php // Gate matches the TARGET: the jetonomy-spaces admin page requires jetonomy_manage_settings, so a space-level moderator (subscriber WP role) must not see a link that lands on Access Denied. The moderation actions above correctly stay on the space-role gate. ?>
							<?php if ( current_user_can( 'jetonomy_manage_settings' ) ) : ?>
								<a class="jt-more-item" href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . (int) $post->space_id ) ); ?>"><?php jetonomy_echo_icon( 'settings', 14 ); ?> <?php esc_html_e( 'Admin', 'jetonomy' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
				<?php
				// Pro hangs React, Pin to community and Add Poll here - all writes.
				if ( $jt_is_live ) {
					/**
					 * Fires in the post action bar for live threads, so extensions can add write actions such as reactions or polls.
					 *
					 * @since 1.0.0
					 *
					 * @param object $post Post row.
					 */
					do_action( 'jetonomy_post_actions', $post );
				}
				?>
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
					array(
						'postId'        => (int) $post->id,
						'totalReplies'  => $total_replies,
						'topLevelCount' => $top_level_count,
						'sort'          => $reply_sort,
						'hasMore'       => false,
						'loadingMore'   => false,
					)
				);
				?>
				'>

				<div class="jt-replies-head">
					<h2>
						<?php echo esc_html( \Jetonomy\jetonomy_label( 'reply', true ) ); ?>
						<span class="jt-count-pill"><?php echo esc_html( (int) $total_replies ); ?></span>
					</h2>
					<div class="jt-replies-controls">
						<div class="jt-pills">
							<?php
							$reply_sorts = array(
								'oldest' => __( 'Oldest', 'jetonomy' ),
								'newest' => __( 'Newest', 'jetonomy' ),
								'best'   => __( 'Best', 'jetonomy' ),
							);
							foreach ( $reply_sorts as $key => $label ) :
								$rsort_url = add_query_arg( array( 'rsort' => $key ), $post_url );
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
				// thread so members read it first regardless of sort — but only
				// when it is NOT already visible on the current page. When the
				// accepted reply falls on this page it renders inline with its
				// own "Accepted" styling, so a pinned echo here would just be a
				// duplicate card ($jt_accepted_on_page computed above).
				$jt_accepted_reply = null;
				if (
					$space
					&& 'qa' === ( $space->type ?? '' )
					&& $jt_accepted_reply_id
					&& ! $jt_accepted_on_page
				) {
					$jt_accepted_reply = \Jetonomy\Models\Reply::find( $jt_accepted_reply_id );
					if ( $jt_accepted_reply ) {
						// Off-page fetch bypasses get_threaded()'s tree builder —
						// apply the same block and private tombstones here so the
						// callout never shows text the inline card would withhold.
						\Jetonomy\Models\Reply::apply_block_tombstone(
							$jt_accepted_reply,
							\Jetonomy\Models\BlockedUser::blocked_ids( get_current_user_id() )
						);
						\Jetonomy\Models\Reply::apply_private_tombstone( $jt_accepted_reply, $post, get_current_user_id() );
					}
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

				<?php
				// "may actually reply here", not merely "is logged in": a Read-grant admits
				// a member to the space without granting create_replies, and POST /replies
				// then 403s. Gate the composer on the same permission the server enforces so
				// a read-only member never sees a Post Reply box they cannot submit - the
				// Vote / New-Topic seam, applied to the reply surface. The empty state
				// below uses it too, so it never invites a reply the viewer cannot post.
				$jt_can_reply_here = $jt_is_live
					&& $jt_viewer_id
					&& \Jetonomy\Permissions\Permission_Engine::can( $jt_viewer_id, 'create_replies', (int) $post->space_id );
				?>
				<?php if ( empty( $reply_batch ) ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						array(
							'icon'    => 'empty-replies',
							'message' => ( $jt_can_reply_here && ( ! $post->is_closed || $jt_can_moderate_here ) )
								// jt-terms-ok: 'reply' at the end is the verb; the noun comes from the label.
								? sprintf( /* translators: %s: plural reply label. */ __( 'No %s yet. Be the first to reply!', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true, true ) )
								: sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( 'No %s yet.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true, true ) ),
						)
					);
					?>
				<?php else : ?>

					<div class="jt-replies-list" id="jt-replies-container" data-per-page="<?php echo (int) $replies_per_page; ?>" data-top-level="<?php echo (int) $top_level_count; ?>" data-sort="<?php echo esc_attr( $reply_sort ); ?>">
						<?php foreach ( $reply_batch as $index => $reply ) : ?>
							<?php jetonomy_render_threaded_reply( $reply, $post, 0, $space, $reply_page ); ?>
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
			<?php
			$jt_restriction = ( $jt_is_live && ! $jt_can_reply_here )
				? \Jetonomy\Permissions\Permission_Engine::restriction_error( (int) $jt_viewer_id, 'create_replies', (int) $post->space_id )
				: null;
			?>
			<?php if ( $post->is_closed && ! $jt_can_moderate_here ) : ?>
				<div class="jt-closed-notice">
					<?php echo esc_html( \Jetonomy\Permissions\Content_Gate::closed_error()->get_error_message() ); ?>
				</div>
			<?php elseif ( $jt_can_reply_here ) : ?>
				<?php if ( $post->is_closed ) : ?>
					<div class="jt-closed-notice jt-closed-notice--staff">
						<?php printf( /* translators: %s: singular topic label. */ esc_html__( 'This %s is closed. As a moderator, you can still take part.', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'topic', false, true ) ) ); ?>
					</div>
				<?php endif; ?>
				<div class="jt-reply-composer" id="jt-composer">
					<h3>
						<?php printf( /* translators: %s: singular reply label. */ esc_html__( 'Your %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'reply' ) ) ); ?>
					</h3>
					<?php
					\Jetonomy\Template_Loader::partial(
						'composer',
						array(
							'post_id'  => (int) $post->id,
							'post_url' => $post_url,
						)
					);
					?>
				</div>
			<?php elseif ( $jt_restriction ) : ?>
				<?php // A silenced or banned member is told why there is no reply box. ?>
				<p class="jt-notice jt-notice-warning"><?php echo esc_html( $jt_restriction->get_error_message() ); ?></p>
			<?php elseif ( ! is_user_logged_in() ) : ?>
				<?php
				// Guests get the composer partial's own logged-out state (the
				// styled Log in to reply button), so there is one copy of it.
				\Jetonomy\Template_Loader::partial( 'composer', array( 'post_url' => $post_url ) );
				?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => $space ) ); ?>
	</div>
