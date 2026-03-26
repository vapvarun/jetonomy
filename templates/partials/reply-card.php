<?php
defined( 'ABSPATH' ) || exit;
$author      = get_userdata( (int) $reply->author_id );
$profile     = \Jetonomy\Models\UserProfile::find_by_user( (int) $reply->author_id );
$author_id   = (int) $reply->author_id;
$trust       = $profile ? (int) $profile->trust_level : 0;
$time_ago    = human_time_diff( strtotime( $reply->created_at ), current_time( 'timestamp', true ) );
$is_op       = (int) $reply->author_id === (int) $post->author_id;
$is_accepted = (int) $reply->is_accepted;
?>
<div class="jt-reply <?php echo $is_accepted ? 'accepted' : ''; ?>" data-wp-interactive="jetonomy">
	<div class="jt-reply-head">
		<span class="jt-avatar-wrap <?php echo \Jetonomy\Models\UserProfile::is_online( (int) $reply->author_id ) ? 'is-online' : ''; ?>">
			<?php echo \Jetonomy\get_user_link( (int) $reply->author_id, 'jt-avatar-sm', 28, true ); ?>
		</span>
		<span class="jt-tl" data-jt-tl="<?php echo $trust; ?>" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo $trust; ?></span>
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
		<?php echo \Jetonomy\Embeds::process( jetonomy_format_content( wp_kses_post( $reply->content ) ) ); ?>
	</div>
	<div class="jt-reply-foot">
		<button class="jt-act"
			data-wp-on--click="actions.voteReplyUp"
			data-reply-id="<?php echo (int) $reply->id; ?>"
			aria-label="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>">
			<?php jetonomy_echo_icon( 'chevron-up', 14 ); ?> <span class="n"><?php echo (int) $reply->vote_score; ?></span>
		</button>
		<button class="jt-act"
			data-wp-on--click="actions.voteReplyDown"
			data-reply-id="<?php echo (int) $reply->id; ?>"
			aria-label="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'chevron-down', 14 ); ?></button>
		<?php if ( is_user_logged_in() ) : ?>
			<button class="jt-act jt-reply-to-btn"
				data-wp-on--click="actions.setReplyTo"
				data-reply-id="<?php echo (int) $reply->id; ?>"
				data-reply-author="<?php echo esc_attr( $author ? $author->display_name : '' ); ?>"><?php esc_html_e( 'Reply', 'jetonomy' ); ?></button>
		<?php endif; ?>
		<?php if ( is_user_logged_in() && ( (int) $reply->author_id === get_current_user_id() || current_user_can( 'jetonomy_moderate' ) ) ) : ?>
			<div class="jt-more-menu">
				<button class="jt-act jt-more-trigger" type="button" data-wp-on--click="actions.toggleMoreMenu" title="<?php esc_attr_e( 'More', 'jetonomy' ); ?>">&hellip;</button>
				<div class="jt-more-dropdown" hidden>
					<button class="jt-more-item"
						data-wp-on--click="actions.editReply"
						data-reply-id="<?php echo (int) $reply->id; ?>">
						<?php jetonomy_echo_icon( 'edit', 14 ); ?> <?php esc_html_e( 'Edit', 'jetonomy' ); ?>
					</button>
					<?php if ( current_user_can( 'jetonomy_moderate' ) ) : ?>
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
	// Accept Answer button — shown to post author on Q&A spaces, for non-accepted replies.
	if (
		is_user_logged_in()
		&& isset( $post, $space )
		&& 'qa' === ( $space->type ?? '' )
		&& (int) $post->author_id === get_current_user_id()
		&& ! $is_accepted
	) :
	?>
		<button class="jt-act"
			data-wp-on--click="actions.acceptReply"
			data-reply-id="<?php echo (int) $reply->id; ?>"
			data-post-id="<?php echo (int) $post->id; ?>"
			aria-label="<?php esc_attr_e( 'Accept as best answer', 'jetonomy' ); ?>">
			<?php jetonomy_echo_icon( 'check-circle', 14 ); ?> <?php esc_html_e( 'Accept', 'jetonomy' ); ?>
		</button>
	<?php endif; ?>
	<?php do_action( 'jetonomy_reply_actions', $reply ); ?>
	</div>
</div>
