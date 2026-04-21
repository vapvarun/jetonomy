<?php
/**
 * Compose-topic embed partial.
 *
 * Renders a topic composer inline on any page. Reused by the shortcode
 * [jetonomy_compose_topic] and the jetonomy/compose-topic block.
 *
 * Expected variables (injected via Template_Loader::partial()):
 *   - string       $mode        'fixed'|'picker'.
 *   - int          $space_id    Used when mode='fixed'.
 *   - object|null  $space       Pre-resolved space row (fixed mode only).
 *   - object[]     $postable    Space rows user can post in (picker mode).
 *   - string[]     $types       Allowed post types.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

// Defensive normalisation — the partial can be invoked by anyone who calls
// Template_Loader::partial(), so tolerate missing/invalid args instead of
// fataling on the page.
/** @var mixed $mode */
/** @var mixed $space */
/** @var mixed $postable */
/** @var mixed $types */
$_mode         = ( isset( $mode ) && in_array( $mode, array( 'fixed', 'picker' ), true ) ) ? $mode : 'picker';
$_space        = isset( $space ) && is_object( $space ) ? $space : null;
$_space_id     = $_space ? (int) $_space->id : 0;
$_postable     = ( isset( $postable ) && is_array( $postable ) ) ? $postable : array();
$_types_raw    = ( isset( $types ) && is_array( $types ) ) ? $types : array();
$_types        = ! empty( $_types_raw ) ? $_types_raw : array( 'topic', 'question', 'idea' );
$_default_type = $_types[0];

if ( ! is_user_logged_in() ) {
	$_redirect = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
	$_login    = wp_login_url( $_redirect );
	?>
	<div class="jt-compose-topic-embed jt-compose-topic-login">
		<p><?php esc_html_e( 'Sign in to start a new topic.', 'jetonomy' ); ?></p>
		<a class="jt-btn jt-btn-fill" href="<?php echo esc_url( $_login ); ?>">
			<?php esc_html_e( 'Sign in', 'jetonomy' ); ?>
		</a>
	</div>
	<?php
	return;
}

// Invalid / missing space_id in fixed mode → degrade to picker so the block
// never silently breaks when a space is deleted or renumbered.
if ( 'fixed' === $_mode && ! $_space ) {
	$_mode = 'picker';
}

$_context = array(
	'mode'       => $_mode,
	'spaceId'    => $_space_id,
	'title'      => '',
	'body'       => '',
	'postType'   => $_default_type,
	'submitting' => false,
	'error'      => '',
);
?>
<div class="jt-compose-topic-embed"
	data-mode="<?php echo esc_attr( $_mode ); ?>"
	<?php
	if ( $_space_id ) :
		?>
		data-space-id="<?php echo (int) $_space_id; ?>"<?php endif; ?>
	data-wp-interactive="jetonomy"
	data-wp-context='<?php echo wp_json_encode( $_context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode already returns a safe JSON string for attribute context. ?>'>

	<?php if ( 'picker' === $_mode ) : ?>
		<label class="jt-compose-topic-field">
			<span class="jt-compose-topic-label"><?php esc_html_e( 'Post to space', 'jetonomy' ); ?></span>
			<select
				class="jt-compose-topic-space"
				data-wp-on--change="actions.composeTopicSelectSpace"
				data-wp-bind--disabled="context.submitting">
				<option value=""><?php esc_html_e( 'Choose a space…', 'jetonomy' ); ?></option>
				<?php foreach ( $_postable as $_s ) : ?>
					<option value="<?php echo (int) $_s->id; ?>"><?php echo esc_html( $_s->title ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( empty( $_postable ) ) : ?>
				<small class="jt-compose-topic-empty">
					<?php esc_html_e( 'You are not a member of any space yet. Join a space to start posting.', 'jetonomy' ); ?>
				</small>
			<?php endif; ?>
		</label>
	<?php else : ?>
		<p class="jt-compose-topic-posting-to">
			<?php
			printf(
				/* translators: %s: space title */
				esc_html__( 'Posting in %s', 'jetonomy' ),
				'<strong>' . esc_html( $_space->title ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped.
			);
			?>
		</p>
	<?php endif; ?>

	<label class="jt-compose-topic-field">
		<span class="jt-compose-topic-label"><?php esc_html_e( 'Title', 'jetonomy' ); ?></span>
		<input type="text"
			class="jt-compose-topic-title"
			maxlength="200"
			data-wp-on--input="actions.composeTopicTitleInput"
			data-wp-bind--disabled="context.submitting"
			placeholder="<?php esc_attr_e( 'What is your topic about?', 'jetonomy' ); ?>">
	</label>

	<label class="jt-compose-topic-field">
		<span class="jt-compose-topic-label"><?php esc_html_e( 'Details', 'jetonomy' ); ?></span>
		<textarea
			class="jt-compose-topic-body"
			rows="6"
			data-wp-on--input="actions.composeTopicBodyInput"
			data-wp-bind--disabled="context.submitting"
			placeholder="<?php esc_attr_e( 'Share the details… (Markdown supported)', 'jetonomy' ); ?>"></textarea>
	</label>

	<div class="jt-compose-topic-actions">
		<p class="jt-compose-topic-error"
			data-wp-text="context.error"
			data-wp-class--is-shown="context.error"></p>
		<button type="button"
			class="jt-btn jt-btn-fill jt-compose-topic-submit"
			data-wp-on--click="actions.composeTopicSubmit"
			data-wp-bind--disabled="context.submitting">
			<span data-wp-text="state.composeTopicSubmitLabel"><?php esc_html_e( 'Post topic', 'jetonomy' ); ?></span>
		</button>
	</div>
</div>
