<?php
/**
 * Reply card partial.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
$display     = \Jetonomy\Author::for_display( (int) $reply->author_id, $reply );
$profile     = \Jetonomy\Models\UserProfile::find_by_user( (int) $reply->author_id );
$author_id   = (int) $display['id'];
$trust       = $profile ? (int) $profile->trust_level : 0;
$time_ago    = human_time_diff( strtotime( $reply->created_at ), time() );
$is_op       = (int) $reply->author_id === (int) $post->author_id;
$is_accepted = (int) $reply->is_accepted;

// 1.4.0 C.1 fix: space-role moderators (subscriber WP role + jt_space_members
// admin/mod) get the same edit / split / delete affordances as WP-cap mods.
// Permission_Engine::can() checks WP cap → space role → trust level in order.
$jt_reply_viewer       = get_current_user_id();
$jt_can_moderate_reply = $jt_reply_viewer
	? \Jetonomy\Permissions\Permission_Engine::can( $jt_reply_viewer, 'moderate', (int) ( $post->space_id ?? 0 ) )
	: false;
?>
<div class="jt-reply <?php echo $is_accepted ? esc_attr( 'accepted' ) : ''; ?>" data-wp-interactive="jetonomy">
	<div class="jt-reply-head">
		<span class="jt-avatar-wrap <?php echo \Jetonomy\Models\UserProfile::is_online( (int) $reply->author_id ) ? esc_attr( 'is-online' ) : ''; ?>">
			<?php
			// get_user_link() returns trusted, fully-escaped plugin markup (incl. the
			// Lucide SVG fallback avatar, which wp_kses_post would strip). Echo direct.
			echo \Jetonomy\get_user_link( (int) $display['id'], 'jt-avatar-sm', 28, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</span>
		<?php if ( '' !== $display['url'] ) : ?>
			<a class="jt-reply-author" href="<?php echo esc_url( $display['url'] ); ?>">
				<?php echo esc_html( $display['name'] ); ?>
			</a>
		<?php else : ?>
			<span class="jt-reply-author"><?php echo esc_html( '' !== $display['name'] ? $display['name'] : __( 'Anonymous', 'jetonomy' ) ); ?></span>
		<?php endif; ?>
		<?php
		// 1.4.0 G3: role pill — same as post-card.php, scoped to the
		// PARENT POST's space (an admin of space A replying in space B
		// gets no pill on the space-B reply, which is the right
		// behaviour). Reads the cache warmed at the top of single-post.php.
		$jt_role = isset( $post ) && isset( $post->space_id )
			? \Jetonomy\get_space_role_label( (int) $reply->author_id, (int) $post->space_id )
			: null;
		if ( null !== $jt_role ) :
			$jt_role_label = ( 'admin' === $jt_role )
				? __( 'Admin', 'jetonomy' )
				: __( 'Mod', 'jetonomy' );
			?>
			<span class="jt-role-pill jt-role-pill--<?php echo esc_attr( $jt_role ); ?>">
				<?php echo esc_html( $jt_role_label ); ?>
			</span>
		<?php endif; ?>
		<?php
		// 1.4.1 byline cleanup: trust-level number removed from inline bylines.
		// "TL2" / "TL3" reads as jargon to first-time visitors. Trust progress
		// stays accessible on the user profile + hover-card surfaces.
		?>
		<?php if ( $is_op ) : ?>
			<span class="jt-reply-op"><?php esc_html_e( 'OP', 'jetonomy' ); ?></span>
		<?php endif; ?>
		<span class="jt-reply-time">
			<?php
			/* translators: %s: human-readable time difference */
			echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
			?>
		</span>
		<?php if ( $is_accepted ) : ?>
			<span class="jt-accepted-tag"><?php jetonomy_echo_icon( 'check-circle', 14 ); ?> <?php esc_html_e( 'Accepted', 'jetonomy' ); ?></span>
		<?php endif; ?>
	</div>
	<div class="jt-reply-body">
		<?php
		// jetonomy_kses_embedded_content() is a wp_kses() wrapper with an extended iframe allowlist — safe to echo.
		// Embeds first so standalone URLs (including `tiktok.com/@user/video/...`)
		// are captured as whole tokens BEFORE jetonomy_format_content runs its
		// @mention / #hashtag matchers — otherwise a URL path's `/@name` gets
		// eaten by the mention regex and the URL never embeds.
		$jt_reply_rendered = jetonomy_kses_embedded_content( jetonomy_format_content( \Jetonomy\Embeds::process( wp_kses_post( $reply->content ) ) ) );
		jetonomy_maybe_enqueue_embed_scripts( $jt_reply_rendered );
		echo $jt_reply_rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
	<?php
	$reply_viewer_id   = get_current_user_id();
	$reply_viewer_vote = $reply_viewer_id ? \Jetonomy\Models\Vote::get_user_vote( $reply_viewer_id, 'reply', (int) $reply->id ) : null;
	?>
	<div class="jt-reply-foot">
		<?php
		// 1.4.1 voting cleanup: up + down buttons stay as separate <button>
		// elements (voting JS in view.js binds to the buttons individually
		// and reads the score via `el.ref.querySelector('.n')`, so the
		// inner shape MUST not change). The wrapper `.jt-vote-cluster`
		// only adds visual grouping + alignment so up and down read as
		// equal-weight peers — no friction asymmetry, no nudging toward
		// one side. JS bindings are untouched and verified live.
		?>
		<?php if ( jetonomy_space_allows_voting( $space ?? null ) ) : ?>
		<div class="jt-vote-cluster" role="group" aria-label="<?php esc_attr_e( 'Vote on this reply', 'jetonomy' ); ?>">
			<?php if ( is_user_logged_in() ) : ?>
			<button class="jt-act <?php echo 1 === $reply_viewer_vote ? 'voted' : ''; ?>"
				data-wp-on--click="actions.voteReplyUp"
				data-reply-id="<?php echo (int) $reply->id; ?>"
				title="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>"
				aria-label="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>">
				<?php jetonomy_echo_icon( 'chevron-up', 14 ); ?> <span class="n"><?php echo (int) $reply->vote_score; ?></span>
			</button>
				<?php
				// Hide downvote on own replies — self-downvote was landing at
				// -1 (Basecamp 9803889865).
				if ( (int) $reply->author_id !== get_current_user_id() ) :
					?>
			<button class="jt-act <?php echo -1 === $reply_viewer_vote ? 'voted' : ''; ?>"
				data-wp-on--click="actions.voteReplyDown"
				data-reply-id="<?php echo (int) $reply->id; ?>"
				title="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>"
				aria-label="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'chevron-down', 14 ); ?></button>
				<?php endif; ?>
			<?php else : ?>
				<?php
				// Logged-out: reply votes were clickable buttons that silently
				// failed on click (no auth). Match the post-vote control — a
				// "Log in to vote" link that takes the intent somewhere.
				?>
			<a class="jt-act jt-act-login"
				href="<?php echo esc_url( wp_login_url( \Jetonomy\current_url() ) ); ?>"
				title="<?php esc_attr_e( 'Log in to vote', 'jetonomy' ); ?>"
				aria-label="<?php esc_attr_e( 'Log in to vote', 'jetonomy' ); ?>">
				<?php jetonomy_echo_icon( 'chevron-up', 14 ); ?> <span class="n"><?php echo (int) $reply->vote_score; ?></span>
			</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if ( is_user_logged_in() ) : ?>
			<button class="jt-act jt-reply-to-btn"
				data-wp-on--click="actions.setReplyTo"
				data-reply-id="<?php echo (int) $reply->id; ?>"
				data-reply-author="<?php echo esc_attr( '' !== $display['name'] ? $display['name'] : __( 'Anonymous', 'jetonomy' ) ); ?>"
				title="<?php esc_attr_e( 'Reply', 'jetonomy' ); ?>"
				aria-label="<?php esc_attr_e( 'Reply', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'message-circle', 14 ); ?> <span class="jt-btn-label"><?php esc_html_e( 'Reply', 'jetonomy' ); ?></span></button>
			<button class="jt-act"
				data-wp-on--click="actions.quoteReply"
				data-reply-id="<?php echo (int) $reply->id; ?>"
				data-reply-author="<?php echo esc_attr( '' !== $display['name'] ? $display['name'] : __( 'Anonymous', 'jetonomy' ) ); ?>"
				title="<?php esc_attr_e( 'Quote', 'jetonomy' ); ?>"
				aria-label="<?php esc_attr_e( 'Quote', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'quote', 14 ); ?></button>
		<?php endif; ?>
		<?php if ( is_user_logged_in() && get_current_user_id() !== (int) $reply->author_id ) : ?>
			<button class="jt-act"
				data-wp-on--click="actions.flagReply"
				data-reply-id="<?php echo (int) $reply->id; ?>"
				title="<?php esc_attr_e( 'Report', 'jetonomy' ); ?>"
				aria-label="<?php esc_attr_e( 'Report', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'flag', 14 ); ?></button>
		<?php endif; ?>
		<?php if ( is_user_logged_in() && ( get_current_user_id() === (int) $reply->author_id || $jt_can_moderate_reply ) ) : ?>
			<div class="jt-more-menu">
				<button class="jt-act jt-more-trigger" type="button"
					data-wp-on--click="actions.toggleMoreMenu"
					title="<?php esc_attr_e( 'More options', 'jetonomy' ); ?>"
					aria-label="<?php esc_attr_e( 'More options', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'more-horizontal', 14 ); ?></button>
				<div class="jt-more-dropdown" hidden>
					<button class="jt-more-item"
						data-wp-on--click="actions.editReply"
						data-reply-id="<?php echo (int) $reply->id; ?>">
						<?php jetonomy_echo_icon( 'edit', 14 ); ?> <?php esc_html_e( 'Edit', 'jetonomy' ); ?>
					</button>
					<?php if ( $jt_can_moderate_reply ) : ?>
					<button class="jt-more-item"
						data-wp-on--click="actions.splitReply"
						data-reply-id="<?php echo (int) $reply->id; ?>"
						data-post-id="<?php echo (int) $post->id; ?>"
						data-space-id="<?php echo (int) ( $post->space_id ?? 0 ); ?>">
						<?php jetonomy_echo_icon( 'split', 14 ); ?> <?php esc_html_e( 'Split to Topic', 'jetonomy' ); ?>
					</button>
					<?php endif; ?>
					<button class="jt-more-item jt-more-item--danger"
						data-wp-on--click="actions.deleteReply"
						data-reply-id="<?php echo (int) $reply->id; ?>">
						<?php jetonomy_echo_icon( 'trash', 14 ); ?> <?php esc_html_e( 'Delete', 'jetonomy' ); ?>
					</button>
				</div>
			</div>
		<?php endif; ?>
	<?php
	// Accept Answer button — Q&A spaces, for non-accepted replies. Shown to the
	// post author OR anyone who can close posts in the space (space moderators
	// and admins), mirroring Replies_Controller::accept_reply()'s permission gate
	// so the button appears exactly when the action would succeed. Space-role
	// moderators hold `close_posts` but not `moderate`, so this uses close_posts.
	if (
		is_user_logged_in()
		&& isset( $post, $space )
		&& 'qa' === ( $space->type ?? '' )
		&& (
			get_current_user_id() === (int) $post->author_id
			|| \Jetonomy\Permissions\Permission_Engine::can( get_current_user_id(), 'close_posts', (int) ( $post->space_id ?? 0 ) )
		)
		&& ! $is_accepted
	) :
		?>
		<button class="jt-act"
			data-wp-on--click="actions.acceptReply"
			data-reply-id="<?php echo (int) $reply->id; ?>"
			data-post-id="<?php echo (int) $post->id; ?>"
			title="<?php esc_attr_e( 'Accept as best answer', 'jetonomy' ); ?>"
			aria-label="<?php esc_attr_e( 'Accept as best answer', 'jetonomy' ); ?>">
			<?php jetonomy_echo_icon( 'check-circle', 14 ); ?> <span class="jt-btn-label"><?php esc_html_e( 'Accept', 'jetonomy' ); ?></span>
		</button>
	<?php endif; ?>
	<?php
		// Un-accept — shown on the accepted reply to the post author or a moderator
		// (close_posts), so a wrongly-accepted answer can be reverted.
	if (
			is_user_logged_in()
			&& isset( $post, $space )
			&& 'qa' === ( $space->type ?? '' )
			&& $is_accepted
			&& (
				get_current_user_id() === (int) $post->author_id
				|| \Jetonomy\Permissions\Permission_Engine::can( get_current_user_id(), 'close_posts', (int) ( $post->space_id ?? 0 ) )
			)
		) :
		?>
			<button class="jt-act"
				data-wp-on--click="actions.unacceptReply"
				data-reply-id="<?php echo (int) $reply->id; ?>"
				data-post-id="<?php echo (int) $post->id; ?>"
				title="<?php esc_attr_e( 'Remove accepted answer', 'jetonomy' ); ?>"
				aria-label="<?php esc_attr_e( 'Remove accepted answer', 'jetonomy' ); ?>">
			<?php jetonomy_echo_icon( 'x-circle', 14 ); ?> <span class="jt-btn-label"><?php esc_html_e( 'Unaccept', 'jetonomy' ); ?></span>
			</button>
		<?php endif; ?>
		<?php do_action( 'jetonomy_reply_actions', $reply ); ?>
	</div>
</div>
