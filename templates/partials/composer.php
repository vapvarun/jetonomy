<?php
/**
 * Reply composer partial.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
// $post_id   — the post this reply belongs to (required).
// $reply_to  — optional parent reply ID for nested replies.
// $placeholder — optional custom placeholder string.
if ( ! is_user_logged_in() ) {
	$login_url = wp_login_url( isset( $post_url ) ? $post_url : get_permalink() );
	?>
	<div class="jt-editor jt-editor-login">
		<a href="<?php echo esc_url( $login_url ); ?>" class="jt-btn jt-btn-fill">
			<?php esc_html_e( 'Log in to reply', 'jetonomy' ); ?>
		</a>
	</div>
	<?php
	return;
}

$_post_id     = isset( $post_id ) ? (int) $post_id : 0;
$_reply_to    = isset( $reply_to ) ? (int) $reply_to : 0;
$_placeholder = isset( $placeholder ) ? $placeholder : __( 'Write your reply… (Markdown supported)', 'jetonomy' );
?>
<div class="jt-editor"
	data-wp-interactive="jetonomy"
	data-wp-context='{"postId":<?php echo (int) $_post_id; ?>,"replyTo":<?php echo (int) $_reply_to; ?>,"submitting":false}'>
	<div class="jt-editor-bar">
		<button type="button" class="jt-editor-bar-btn" data-cmd="bold" title="<?php esc_attr_e( 'Bold', 'jetonomy' ); ?>"><strong>B</strong></button>
		<button type="button" class="jt-editor-bar-btn" data-cmd="italic" title="<?php esc_attr_e( 'Italic', 'jetonomy' ); ?>"><em>I</em></button>
		<button type="button" class="jt-editor-bar-btn" data-cmd="code" title="<?php esc_attr_e( 'Code', 'jetonomy' ); ?>"><code>&lt;/&gt;</code></button>
		<button type="button" class="jt-editor-bar-btn" data-cmd="link" title="<?php esc_attr_e( 'Link', 'jetonomy' ); ?>">
			<?php jetonomy_echo_icon( 'link', 14 ); ?>
		</button>
		<button type="button" class="jt-editor-bar-btn" data-cmd="quote" title="<?php esc_attr_e( 'Blockquote', 'jetonomy' ); ?>">&ldquo;&rdquo;</button>
		<button type="button" class="jt-editor-bar-btn" data-cmd="image" title="<?php esc_attr_e( 'Upload Image', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'image', 16 ); ?></button>
		<?php
		/**
		 * Fires inside the composer toolbar, after the built-in formatting
		 * buttons. Extensions can add tools here (e.g. the Pro AI "Suggest a
		 * reply" button). Receives the target post ID + parent reply ID.
		 *
		 * @param int $post_id  Post the reply belongs to.
		 * @param int $reply_to Parent reply ID (0 for a top-level reply).
		 */
		do_action( 'jetonomy_composer_toolbar', $_post_id, $_reply_to );
		?>
	</div>
	<div class="jt-editor-body"
		contenteditable="true"
		data-placeholder="<?php echo esc_attr( $_placeholder ); ?>"
		data-wp-on--input="actions.onEditorInput"
		id="jt-composer-<?php echo (int) $_post_id; ?>"
		aria-label="<?php esc_attr_e( 'Reply editor', 'jetonomy' ); ?>"></div>
	<div class="jt-editor-foot">
		<?php
		// 1.4.1 platform-aware hint: phones / tablets do not have a Ctrl key,
		// so the desktop submit-shortcut copy reads as nonsense there. Detect
		// touch via wp_is_mobile() (matches WP's own mobile signal) and swap.
		$_jt_composer_hint = wp_is_mobile()
			? __( 'Markdown supported.', 'jetonomy' )
			: __( 'Markdown · Ctrl+Enter to submit', 'jetonomy' );
		?>
		<span class="jt-editor-hint"><?php echo esc_html( $_jt_composer_hint ); ?></span>
		<div class="jt-flex jt-items-center jt-gap-sm">
			<?php if ( $_reply_to ) : ?>
				<button type="button" class="jt-btn jt-btn-ghost"
					data-wp-on--click="actions.cancelReplyComposer"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></button>
			<?php endif; ?>
			<button type="button" class="jt-btn jt-btn-fill"
				data-wp-on--click="actions.submitReply"
				data-post-id="<?php echo (int) $_post_id; ?>"
				data-reply-to="<?php echo (int) $_reply_to; ?>"
				data-wp-bind--disabled="context.submitting">
				<?php esc_html_e( 'Post Reply', 'jetonomy' ); ?>
			</button>
		</div>
	</div>
</div>
