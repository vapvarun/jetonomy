<?php
/**
 * Feed card partial — renders a Feed-space post inline as a social-feed
 * card with the full body visible, no click-through required to read.
 * The title is omitted from the visible header on purpose (it's auto-
 * derived from the body for short-form posts and would just duplicate
 * the first line); the full post page still uses the title for SEO and
 * deep links via the timestamp anchor below.
 *
 * @package Jetonomy
 *
 * @var object $post       Post row from \Jetonomy\Models\Post::list_*().
 * @var bool   $has_unread Whether the viewer has unread replies.
 */

defined( 'ABSPATH' ) || exit;

$display     = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
$profile     = \Jetonomy\Models\UserProfile::find_by_user( (int) $post->author_id );
$space       = \Jetonomy\Models\Space::find( (int) $post->space_id );
$has_unread  = isset( $has_unread ) ? (bool) $has_unread : false;
$base        = \Jetonomy\base_url();
$post_url    = $base . '/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/';
$time_ago    = human_time_diff( strtotime( $post->created_at ), time() );
$viewer_id   = get_current_user_id();
$viewer_vote = $viewer_id ? \Jetonomy\Models\Vote::get_user_vote( $viewer_id, 'post', (int) $post->id ) : null;

$author_name = '' !== $display['name'] ? $display['name'] : __( 'Anonymous', 'jetonomy' );
?>
<article class="jt-feed-card" data-wp-interactive="jetonomy">
	<header class="jt-feed-card-head">
		<?php
		// Trusted, fully-escaped plugin markup (incl. Lucide SVG avatar). Echo direct.
		echo \Jetonomy\get_user_link( (int) $display['id'], 'jt-avatar-md', 36, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="jt-feed-card-meta">
			<?php if ( '' !== $display['url'] ) : ?>
				<a class="jt-feed-card-author" href="<?php echo esc_url( $display['url'] ); ?>"><?php echo esc_html( $author_name ); ?></a>
			<?php else : ?>
				<?php // Deleted/anonymous author: plain text, not an empty-href link (which would reload the page on click). ?>
				<span class="jt-feed-card-author"><?php echo esc_html( $author_name ); ?></span>
			<?php endif; ?>
			<?php
			$jt_role = \Jetonomy\get_space_role_label( (int) $post->author_id, (int) $post->space_id );
			if ( null !== $jt_role ) :
				$jt_role_label = ( 'admin' === $jt_role )
					? __( 'Admin', 'jetonomy' )
					: __( 'Mod', 'jetonomy' );
				?>
				<span class="jt-role-pill jt-role-pill--<?php echo esc_attr( $jt_role ); ?>"><?php echo esc_html( $jt_role_label ); ?></span>
			<?php endif; ?>
			<a class="jt-feed-card-time" href="<?php echo esc_url( $post_url ); ?>">
				<?php
				/* translators: %s: human-readable time difference */
				echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
				?>
			</a>
		</div>
	</header>

	<div class="jt-feed-card-body">
		<?php echo wp_kses_post( jetonomy_format_content( (string) $post->content ) ); ?>
	</div>

	<footer class="jt-feed-card-foot">
		<?php if ( jetonomy_space_allows_voting( $space ) ) : ?>
		<button type="button"
			class="jt-feed-act <?php echo 1 === $viewer_vote ? esc_attr( 'voted' ) : ''; ?>"
			data-wp-on--click="actions.voteUp"
			data-post-id="<?php echo absint( $post->id ); ?>"
			aria-label="<?php esc_attr_e( 'Upvote', 'jetonomy' ); ?>">
			<?php jetonomy_echo_icon( 'chevron-up', 16 ); ?>
			<span class="n jt-feed-act-n"><?php echo esc_html( (int) $post->vote_score ); ?></span>
		</button>
			<?php
			// Downvote — keep both directions available (respect negative voices),
			// hidden only on the member's own post to block self-downvote.
			if ( (int) $post->author_id !== $viewer_id ) :
				?>
		<button type="button"
			class="jt-feed-act <?php echo -1 === $viewer_vote ? esc_attr( 'voted' ) : ''; ?>"
			data-wp-on--click="actions.voteDown"
			data-post-id="<?php echo absint( $post->id ); ?>"
			aria-label="<?php esc_attr_e( 'Downvote', 'jetonomy' ); ?>">
				<?php jetonomy_echo_icon( 'chevron-down', 16 ); ?>
		</button>
			<?php endif; ?>
		<?php endif; ?>

		<a class="jt-feed-act" href="<?php echo esc_url( $post_url . '#replies' ); ?>"
			aria-label="<?php esc_attr_e( 'View replies', 'jetonomy' ); ?>">
			<?php jetonomy_echo_icon( 'message-circle', 16 ); ?>
			<span class="jt-feed-act-n"><?php echo esc_html( (int) $post->reply_count ); ?></span>
			<?php if ( $has_unread ) : ?>
				<span class="jt-unread-pill" aria-label="<?php esc_attr_e( 'You have unread replies', 'jetonomy' ); ?>"><?php esc_html_e( 'New', 'jetonomy' ); ?></span>
			<?php endif; ?>
		</a>

		<a class="jt-feed-act jt-feed-act-permalink" href="<?php echo esc_url( $post_url ); ?>">
			<?php esc_html_e( 'Open', 'jetonomy' ); ?>
		</a>
	</footer>
</article>
