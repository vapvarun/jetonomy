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
		<?php echo \Jetonomy\get_user_link( (int) $reply->author_id, 'jt-avatar-sm', 28, true ); ?>
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
			<span class="jt-accepted-tag">&#10003; <?php esc_html_e( 'Accepted', 'jetonomy' ); ?></span>
		<?php endif; ?>
	</div>
	<div class="jt-reply-body">
		<?php echo \Jetonomy\Embeds::process( wp_kses_post( $reply->content ) ); ?>
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
			<button class="jt-act jt-reply-edit"
				data-wp-on--click="actions.editReply"
				data-reply-id="<?php echo (int) $reply->id; ?>"
				title="<?php esc_attr_e( 'Edit', 'jetonomy' ); ?>">&#9998;</button>
			<button class="jt-act jt-reply-delete"
				data-wp-on--click="actions.deleteReply"
				data-reply-id="<?php echo (int) $reply->id; ?>"
				title="<?php esc_attr_e( 'Delete', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'trash', 16 ); ?></button>
		<?php endif; ?>
	<?php do_action( 'jetonomy_reply_actions', $reply ); ?>
	</div>
</div>
