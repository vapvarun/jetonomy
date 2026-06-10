<?php
/**
 * Compose-fields partial.
 *
 * Single, flag-driven field set shared by the full new-post page
 * (templates/views/new-post.php) and the inline embed
 * (templates/partials/compose-topic-embed.php). It renders ONLY the
 * field nodes — not the surrounding <form>. Callers are responsible
 * for wrapping the partial in a form (full page) or a form-less
 * embed container (embed) and wiring their own submit action.
 *
 * Expected variables (injected via Template_Loader::partial()):
 *   - object       $space            Required. Resolved space row.
 *   - bool         $show_title       Show title input. Default: space is NOT feed.
 *   - bool         $show_tags        Show comma-separated tags input. Default: true.
 *   - bool         $show_prefix      Show prefix <select> when the space has prefixes. Default: true.
 *   - bool         $show_private     Show "private" checkbox for logged-in viewers. Default: true.
 *   - bool         $show_scheduler   Show date/time scheduler panel. Default: false.
 *   - bool         $show_publish_menu Show publish-mode dropdown (publish/draft/schedule). Default: false.
 *   - bool         $show_captcha     Print the active CAPTCHA provider's widget container before the submit row (1.5.0). Default: false.
 *   - string       $fields_hook      Action hook name fired after the body. Default: 'jetonomy_new_post_fields'.
 *   - string       $body_mode        'editor' (contenteditable) | 'textarea'. Default: 'editor'.
 *   - string       $submit_label     Submit button label (when this partial owns the submit row). Default: '' (caller renders submit).
 *   - string       $cancel_url       Cancel-link URL (only used when submit_label is set). Default: ''.
 *   - string       $title_visibility_binding Optional Interactivity binding to attach
 *                                            to the title-group wrapper so a parent
 *                                            context can hide it dynamically (e.g.
 *                                            embed picker mode flipping when the
 *                                            user selects a Feed space). Empty
 *                                            string = no binding (default).
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

/** @var object|null $space */
if ( ! isset( $space ) || ! is_object( $space ) ) {
	return;
}

$_space_type = (string) ( $space->type ?? '' );

// Flag defaults — only `show_title` is space-aware; everything else
// follows the documented defaults so the partial behaves identically
// whether called from the embed or the full page unless a caller
// explicitly opts out.
// 1.4.3: title is always collected. Feed spaces display the title as
// sr-only on the post view, but the value is real data used by
// breadcrumbs, notifications, search, and share previews.
$_show_title        = isset( $show_title ) ? (bool) $show_title : true;
$_show_tags         = isset( $show_tags ) ? (bool) $show_tags : true;
$_show_prefix       = isset( $show_prefix ) ? (bool) $show_prefix : true;
$_show_private      = isset( $show_private ) ? (bool) $show_private : true;
$_show_scheduler    = isset( $show_scheduler ) ? (bool) $show_scheduler : false;
$_show_publish_menu = isset( $show_publish_menu ) ? (bool) $show_publish_menu : false;
// When true, the active provider's widget container prints before the
// submit row (Turnstile needs an explicit container to render + produce
// its token; reCAPTCHA v3 returns '' so nothing prints). #9977126420.
$_show_captcha             = isset( $show_captcha ) ? (bool) $show_captcha : false;
$_fields_hook              = isset( $fields_hook ) && is_string( $fields_hook ) && '' !== $fields_hook
	? $fields_hook
	: 'jetonomy_new_post_fields';
$_body_mode                = ( isset( $body_mode ) && in_array( $body_mode, array( 'editor', 'textarea' ), true ) ) ? $body_mode : 'editor';
$_submit_label             = isset( $submit_label ) ? (string) $submit_label : '';
$_cancel_url               = isset( $cancel_url ) ? (string) $cancel_url : '';
$_title_visibility_binding = isset( $title_visibility_binding ) ? (string) $title_visibility_binding : '';

// Resolve post type from space type so the title-placeholder matches
// what the customer expects to see ("What is your question?" on Q&A
// spaces, etc.). Mirrors the mapping used by the REST layer's
// create_item when no `type` is sent — keeps UX and storage aligned.
$_space_type_to_post_type = array(
	'qa'    => 'question',
	'ideas' => 'idea',
	'feed'  => 'status',
);
$_post_type               = $_space_type_to_post_type[ $_space_type ] ?? 'topic';

?>

<?php if ( $_show_title ) : ?>
	<div class="jt-form-group" data-jt-field="title"
	<?php
	if ( '' !== $_title_visibility_binding ) :
		?>
		data-wp-bind--hidden="<?php echo esc_attr( $_title_visibility_binding ); ?>"<?php endif; ?>>
		<label for="jt-post-title" class="jt-label"><?php esc_html_e( 'Title', 'jetonomy' ); ?></label>
		<?php
		$_title_placeholders = array(
			'question' => __( 'What is your question?', 'jetonomy' ),
			'idea'     => __( 'Describe your idea', 'jetonomy' ),
		);
		$_title_placeholder  = $_title_placeholders[ $_post_type ] ?? __( 'Topic title', 'jetonomy' );
		?>
		<input type="text" id="jt-post-title" name="title" class="jt-input jt-compose-topic-title"
			placeholder="<?php echo esc_attr( $_title_placeholder ); ?>"
			data-space-id="<?php echo (int) $space->id; ?>"
			maxlength="255"<?php echo ( $_submit_label ) ? ' required autofocus' : ''; ?>>
		<?php if ( $_submit_label ) : ?>
			<div id="jt-similar-topics" class="jt-similar" hidden>
				<div class="jt-similar-head">
					<span class="jt-similar-label"><?php esc_html_e( 'Similar topics', 'jetonomy' ); ?></span>
					<label class="jt-similar-toggle">
						<input type="checkbox" id="jt-similar-all-spaces">
						<?php esc_html_e( 'Search all spaces', 'jetonomy' ); ?>
					</label>
				</div>
				<div id="jt-similar-results"></div>
			</div>
		<?php endif; ?>
	</div>
<?php endif; ?>

<?php if ( $_show_tags ) : ?>
	<div class="jt-form-group">
		<label for="jt-post-tags" class="jt-label"><?php esc_html_e( 'Tags', 'jetonomy' ); ?> <span class="jt-label-hint"><?php esc_html_e( '(optional, comma-separated)', 'jetonomy' ); ?></span></label>
		<input type="text" id="jt-post-tags" name="tags" class="jt-input" placeholder="<?php esc_attr_e( 'e.g. python, django, architecture', 'jetonomy' ); ?>">
	</div>
<?php endif; ?>

<?php
if ( $_show_prefix ) :
	$_space_settings_np = \Jetonomy\Models\Space::get_settings( (int) $space->id );
	$_available_pfx     = ( ! empty( $_space_settings_np['enable_prefixes'] ) && ! empty( $_space_settings_np['prefixes'] ) ) ? $_space_settings_np['prefixes'] : array();
	if ( ! empty( $_available_pfx ) ) :
		?>
		<div class="jt-form-group">
			<label for="jt-post-prefix" class="jt-label"><?php esc_html_e( 'Prefix', 'jetonomy' ); ?> <span class="jt-label-hint"><?php esc_html_e( '(optional)', 'jetonomy' ); ?></span></label>
			<select id="jt-post-prefix" name="prefix" class="jt-select">
				<option value=""><?php esc_html_e( 'None', 'jetonomy' ); ?></option>
				<?php foreach ( $_available_pfx as $_avpfx ) : ?>
					<option value="<?php echo esc_attr( $_avpfx['name'] ); ?>"><?php echo esc_html( $_avpfx['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	endif;
endif;
?>

<div class="jt-form-group">
	<label class="jt-label"><?php esc_html_e( 'Content', 'jetonomy' ); ?></label>
	<?php if ( 'textarea' === $_body_mode ) : ?>
		<textarea
			class="jt-input jt-compose-topic-body"
			name="content"
			id="jt-post-body"
			rows="6"
			data-wp-on--input="actions.composeTopicBodyInput"
			data-wp-bind--disabled="context.submitting"
			placeholder="<?php esc_attr_e( 'Share the details… (Markdown supported)', 'jetonomy' ); ?>"></textarea>
	<?php else : ?>
		<div class="jt-editor" id="jt-post-editor">
			<div class="jt-editor-bar">
				<button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button>
				<button type="button" data-cmd="italic" title="Italic"><em>I</em></button>
				<button type="button" data-cmd="code" title="Code">&lt;/&gt;</button>
				<button type="button" data-cmd="link" title="Link"><?php jetonomy_echo_icon( 'link', 16 ); ?></button>
				<button type="button" data-cmd="quote" title="<?php esc_attr_e( 'Blockquote', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'quote', 16 ); ?></button>
				<button type="button" data-cmd="image" title="<?php esc_attr_e( 'Upload image', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'image', 16 ); ?></button>
			</div>
			<div class="jt-editor-body" contenteditable="true" data-placeholder="<?php esc_attr_e( 'Write your post...', 'jetonomy' ); ?>"></div>
		</div>
	<?php endif; ?>
</div>

<?php
/**
 * Fires after the standard new-post form fields, before the submit
 * button. Pro extensions hook custom fields here (e.g. the poll
 * builder). Caller controls the hook name so the embed can pass a
 * different one if it ever wants a slimmer extension set.
 *
 * @param object|null $space The space object.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- caller-controlled hook (defaults to jetonomy_compose_extras) so embeds can pass a different surface-specific hook.
do_action( $_fields_hook, $space );
?>

<?php if ( $_show_private && is_user_logged_in() ) : ?>
	<div class="jt-form-group jt-private-toggle">
		<label class="jt-checkbox-label">
			<input type="checkbox" name="is_private" value="1" id="jt-post-private">
			<?php jetonomy_echo_icon( 'lock', 14 ); ?>
			<?php esc_html_e( 'Private: only you and moderators can see this topic', 'jetonomy' ); ?>
		</label>
	</div>
<?php endif; ?>

<?php if ( $_show_scheduler ) : ?>
	<!-- Scheduler panel — shown when "Schedule" is selected in the publish menu.
		Initial `hidden` attribute prevents a flash-of-visible-content before
		the Interactivity API hydrates the data-wp-bind--hidden binding. -->
	<div class="jt-schedule-panel" hidden data-wp-bind--hidden="!context.showScheduler">
		<div class="jt-form-group">
			<label class="jt-label">
				<?php esc_html_e( 'Publish on', 'jetonomy' ); ?>
			</label>
			<div class="jt-datetime-split">
				<input type="date" id="jt-post-published-date" name="published_date" class="jt-input jt-input--date" aria-label="<?php esc_attr_e( 'Date', 'jetonomy' ); ?>">
				<?php
				// Two select dropdowns for the time — works consistently on every
				// desktop browser (Firefox Desktop has no popup for <input type="time">,
				// which previously blocked scheduling on Firefox). Mobile browsers
				// render these as native scroll wheels. view.js combines them into
				// the hidden published_at value before submit.
				?>
				<div class="jt-time-split" aria-label="<?php esc_attr_e( 'Time', 'jetonomy' ); ?>">
					<select id="jt-post-published-hour" name="published_hour" class="jt-input jt-input--time-part" aria-label="<?php esc_attr_e( 'Hour', 'jetonomy' ); ?>">
						<option value=""><?php esc_html_e( 'HH', 'jetonomy' ); ?></option>
						<?php for ( $_h = 0; $_h < 24; $_h++ ) : ?>
							<option value="<?php echo esc_attr( str_pad( (string) $_h, 2, '0', STR_PAD_LEFT ) ); ?>"><?php echo esc_html( str_pad( (string) $_h, 2, '0', STR_PAD_LEFT ) ); ?></option>
						<?php endfor; ?>
					</select>
					<span class="jt-time-split-sep" aria-hidden="true">:</span>
					<select id="jt-post-published-minute" name="published_minute" class="jt-input jt-input--time-part" aria-label="<?php esc_attr_e( 'Minute', 'jetonomy' ); ?>">
						<option value=""><?php esc_html_e( 'MM', 'jetonomy' ); ?></option>
						<?php for ( $_m = 0; $_m < 60; $_m += 5 ) : ?>
							<option value="<?php echo esc_attr( str_pad( (string) $_m, 2, '0', STR_PAD_LEFT ) ); ?>"><?php echo esc_html( str_pad( (string) $_m, 2, '0', STR_PAD_LEFT ) ); ?></option>
						<?php endfor; ?>
					</select>
				</div>
			</div>
			<input type="hidden" name="published_at" value="">
			<p class="jt-label-hint">
				<?php esc_html_e( 'Your post will be published automatically at this date and time.', 'jetonomy' ); ?>
			</p>
		</div>
	</div>
<?php endif; ?>

<?php if ( $_show_captcha ) : ?>
	<?php echo \Jetonomy\Captcha\Captcha_Manager::render_widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- adapter builds the HTML with esc_attr. ?>
<?php endif; ?>

<?php if ( '' !== $_submit_label ) : ?>
	<div class="jt-form-actions">
		<?php if ( '' !== $_cancel_url ) : ?>
			<a href="<?php echo esc_url( $_cancel_url ); ?>" class="jt-btn jt-btn-ghost"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></a>
		<?php endif; ?>

		<?php if ( $_show_publish_menu ) : ?>
			<div class="jt-publish-mode">
				<button type="submit" class="jt-btn jt-btn-fill jt-publish-mode__submit"
						data-wp-bind--disabled="state.isSubmitting"
						data-wp-text="state.submitLabel">
					<?php echo esc_html( $_submit_label ); ?>
				</button>
				<button type="button" class="jt-btn jt-btn-fill jt-publish-mode__toggle"
						aria-label="<?php esc_attr_e( 'More publishing options', 'jetonomy' ); ?>"
						aria-haspopup="true"
						data-wp-on--click="actions.togglePublishMenu">
					<?php jetonomy_echo_icon( 'chevron-down', 16 ); ?>
				</button>
				<?php
				// [1.4.3 WS3-B] Visibility now driven by jetonomySmartDropdown
				// (togglePublishMenu); removed `data-wp-bind--hidden` so JS owns
				// display + position. Initial `hidden` attribute prevents flash
				// before the primitive attaches.
				?>
				<div class="jt-publish-mode__menu" hidden>
					<button type="button" class="jt-publish-mode__option"
							data-wp-on--click="actions.selectPublishNow">
						<?php esc_html_e( 'Publish now', 'jetonomy' ); ?>
					</button>
					<button type="button" class="jt-publish-mode__option"
							data-wp-on--click="actions.selectSaveDraft">
						<?php esc_html_e( 'Save as draft', 'jetonomy' ); ?>
					</button>
					<button type="button" class="jt-publish-mode__option"
							data-wp-on--click="actions.selectSchedule">
						<?php esc_html_e( 'Schedule...', 'jetonomy' ); ?>
					</button>
				</div>
			</div>
		<?php else : ?>
			<button type="submit" class="jt-btn jt-btn-fill"
					data-wp-bind--disabled="state.isSubmitting || context.submitting">
				<?php echo esc_html( $_submit_label ); ?>
			</button>
		<?php endif; ?>
	</div>
<?php endif; ?>
