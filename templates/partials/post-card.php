<?php
/**
 * Post card partial.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
$display = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
// Anonymous-posting leak-audit fix: role pill must never be derived from the
// raw author_id when the display identity is masked, or "Anonymous [Admin]"
// de-anonymizes the real author.
$jt_is_masked = (int) $display['id'] !== (int) $post->author_id;
$profile = \Jetonomy\Models\UserProfile::find_by_user( (int) $post->author_id );
$space   = \Jetonomy\Models\Space::find( (int) $post->space_id );
// 1.4.0 C.5: caller passes `has_unread` from the bulk read-status map.
// Boolean signal — pill text reads "New" regardless of how many.
$has_unread = isset( $has_unread ) ? (bool) $has_unread : false;
// 1.5.0: the My Bookmarks page passes show_bookmark_toggle so each card
// offers a one-click "Remove bookmark" — the bookmarks list was otherwise a
// dead end (no way to manage the very bookmarks it exists to show).
$show_bookmark_toggle = isset( $show_bookmark_toggle ) ? (bool) $show_bookmark_toggle : false;
$initials             = '' !== $display['name'] ? strtoupper( mb_substr( $display['name'], 0, 2 ) ) : '??';
$trust                = $profile ? (int) $profile->trust_level : 0;
$base                 = \Jetonomy\base_url();
$post_url             = $base . '/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/';
$time_ago             = human_time_diff( strtotime( $post->created_at ), time() );
$tags                 = \Jetonomy\Models\Tag::list_for_post( (int) $post->id );
$viewer_id            = get_current_user_id();
$viewer_vote          = $viewer_id ? \Jetonomy\Models\Vote::get_user_vote( $viewer_id, 'post', (int) $post->id ) : null;

// Seed this card's score into the Interactivity store so the vote buttons'
// `data-wp-text` binding renders the right number and updates optimistically on
// click. wp_interactivity_state() merges recursively, so each card in a listing
// contributes its own key without clobbering the others.
if ( function_exists( 'wp_interactivity_state' ) ) {
	wp_interactivity_state( 'jetonomy', array( 'postScores' => array( (int) $post->id => (int) $post->vote_score ) ) );
}

// Resolve prefix color from space settings.
$prefix_name  = $post->prefix ?? null;
$prefix_color = null;
if ( $prefix_name && $space ) {
	$space_settings_pf = \Jetonomy\Models\Space::get_settings( (int) $space->id );
	$prefix_list       = $space_settings_pf['prefixes'] ?? array();
	foreach ( $prefix_list as $pfx ) {
		if ( ( $pfx['name'] ?? '' ) === $prefix_name ) {
			$prefix_color = $pfx['color'] ?? null;
			break;
		}
	}
}
?>
<div class="jt-row <?php echo $post->is_sticky ? esc_attr( 'pinned' ) : ''; ?>"
	data-wp-interactive="jetonomy">
	<?php if ( jetonomy_space_allows_voting( $space ) ) : ?>
		<div class="jt-votes" role="group" aria-label="<?php esc_attr_e( 'Vote on this post', 'jetonomy' ); ?>">
			<?php if ( $viewer_id ) : ?>
				<button type="button" class="jt-v-btn <?php echo 1 === $viewer_vote ? esc_attr( 'voted' ) : ''; ?>"
					data-wp-on--click="actions.voteUp"
					data-post-id="<?php echo absint( $post->id ); ?>"
					title="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>"
					aria-label="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'chevron-up', 14 ); ?></button>
				<span class="jt-v-num" data-wp-text="state.postScores.<?php echo absint( $post->id ); ?>"><?php echo (int) $post->vote_score; ?></span>
				<?php // Hide downvote on own content (self-downvote landed at -1). ?>
				<?php if ( (int) $post->author_id !== $viewer_id ) : ?>
					<button type="button" class="jt-v-btn <?php echo -1 === $viewer_vote ? esc_attr( 'voted' ) : ''; ?>"
						data-wp-on--click="actions.voteDown"
						data-post-id="<?php echo absint( $post->id ); ?>"
						title="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>"
						aria-label="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'chevron-down', 14 ); ?></button>
				<?php endif; ?>
			<?php else : ?>
				<span class="jt-v-btn" aria-hidden="true"><?php jetonomy_echo_icon( 'chevron-up', 14 ); ?></span>
				<span class="jt-v-num"><?php echo (int) $post->vote_score; ?></span>
				<span class="jt-v-btn" aria-hidden="true"><?php jetonomy_echo_icon( 'chevron-down', 14 ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<div class="jt-row-main">
		<?php
		// 1.4.0 C.4 fix: row is no longer one big <a>. The title link is the
		// stretched anchor (CSS positions ::before to cover the whole row),
		// so child <a> elements (tags, mentions) remain clickable without
		// nested-anchor invalid markup. The whole row stays clickable.
		?>
		<a class="jt-row-title jt-row-title-link" href="<?php echo esc_url( $post_url ); ?>">
			<?php if ( $post->is_sticky ) : ?>
				<span class="jt-badge-pinned"><?php jetonomy_echo_icon( 'pin', 12 ); ?> <?php esc_html_e( 'Pinned', 'jetonomy' ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $post->is_private ) ) : ?>
				<span class="jt-badge-private"><?php jetonomy_echo_icon( 'lock', 12 ); ?> <?php esc_html_e( 'Private', 'jetonomy' ); ?></span>
			<?php endif; ?>
			<?php
			/**
			 * Fires after the built-in post-card badges (sticky, private) so
			 * Pro extensions can append extra markers (super-sticky / pinned
			 * site-wide / "From announcements" etc.) without forking the
			 * post-card partial.
			 *
			 * @param object      $post  Post row.
			 * @param object|null $space Space row, if loaded.
			 */
			do_action( 'jetonomy_post_card_after_badges', $post, $space );
			?>
			<?php if ( $prefix_name ) : ?>
				<span class="jt-prefix"
				<?php
				if ( $prefix_color ) :
					?>
					style="--jt-pfx:<?php echo esc_attr( $prefix_color ); ?>"<?php endif; ?>><?php echo esc_html( $prefix_name ); ?></span>
			<?php endif; ?>
			<?php if ( $space && 'ideas' === ( $space->type ?? '' ) ) : ?>
				<?php jetonomy_render_idea_status_pill( (string) ( $post->idea_status ?? '' ) ); ?>
			<?php elseif ( $space && 'qa' === ( $space->type ?? '' ) ) : ?>
				<?php if ( ! empty( $post->accepted_reply_id ) ) : ?>
					<span class="jt-qa-pill jt-qa-pill-answered"><?php jetonomy_echo_icon( 'check-circle', 12 ); ?> <?php esc_html_e( 'Answered', 'jetonomy' ); ?></span>
				<?php else : ?>
					<span class="jt-qa-pill jt-qa-pill-needs-answer"><?php esc_html_e( 'Needs answer', 'jetonomy' ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
			<?php echo esc_html( jetonomy_post_title_or_excerpt( $post ) ); ?>
			<?php if ( $has_unread ) : ?>
				<span class="jt-unread-pill" aria-label="<?php esc_attr_e( 'You have unread replies', 'jetonomy' ); ?>">
					<?php esc_html_e( 'New', 'jetonomy' ); ?>
				</span>
			<?php endif; ?>
		</a>
		<div class="jt-row-sub">
			<?php echo esc_html( '' !== $display['name'] ? $display['name'] : __( 'Anonymous', 'jetonomy' ) ); ?>
			<?php
			// 1.4.0 G3: render role pill (Admin / Mod) when this user holds a
			// privileged role IN THIS POST'S SPACE. Reads the warmed cache
			// populated by the parent view — see space.php / single-post.php.
			$jt_role = \Jetonomy\get_space_role_label( (int) $post->author_id, (int) $post->space_id );
			if ( ! $jt_is_masked && null !== $jt_role ) :
				$jt_role_label = ( 'admin' === $jt_role )
					? __( 'Admin', 'jetonomy' )
					: __( 'Mod', 'jetonomy' );
				?>
				<span class="jt-role-pill jt-role-pill--<?php echo esc_attr( $jt_role ); ?>">
					<?php echo esc_html( $jt_role_label ); ?>
				</span>
			<?php endif; ?>
			<?php
			// 1.4.1 byline cleanup: trust-level number removed. Trust progress
			// stays on the user profile + hover-card surfaces.
			?>
			<?php foreach ( $tags as $post_tag ) : ?>
				<?php
				// 1.4.0 C.4 fix: tags are now anchors to /community/tag/:slug/
				// instead of inert <span> elements. CSS selectors stay
				// `.jt-tag` so existing styles still apply (the `<a>` carries
				// the same class).
				$jt_tag_url = \Jetonomy\base_url() . '/tag/' . rawurlencode( (string) $post_tag->slug ) . '/';
				?>
				<a class="jt-tag" href="<?php echo esc_url( $jt_tag_url ); ?>"><?php echo esc_html( $post_tag->name ); ?></a>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="jt-row-stat">
		<div class="jt-row-stat-n"><?php echo (int) $post->reply_count; ?></div>
		<div class="jt-row-stat-l"><?php esc_html_e( 'replies', 'jetonomy' ); ?></div>
	</div>
	<div class="jt-row-stat">
		<div class="jt-row-time">
			<?php
			/* translators: %s: human-readable time difference */
			echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
			?>
		</div>
	</div>
	<?php if ( $show_bookmark_toggle ) : ?>
		<button type="button"
			class="jt-act jt-bookmark-toggle bookmarked"
			data-wp-on--click="actions.toggleBookmark"
			data-post-id="<?php echo absint( $post->id ); ?>"
			data-bookmarked="1"
			data-bookmark-context="list"
			title="<?php esc_attr_e( 'Remove bookmark', 'jetonomy' ); ?>"
			aria-label="<?php esc_attr_e( 'Remove bookmark', 'jetonomy' ); ?>">
			<?php jetonomy_echo_icon( 'bookmark', 16 ); ?>
		</button>
	<?php endif; ?>
</div>
