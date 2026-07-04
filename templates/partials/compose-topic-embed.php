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
	$_redirect     = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
	$_login        = wp_login_url( $_redirect );
	$_can_register = (bool) get_option( 'users_can_register' );
	$_register_url = $_can_register ? wp_registration_url() : '';

	if ( 'fixed' === $_mode && $_space ) {
		/* translators: %s: space title. */
		$_lede = sprintf( __( 'Sign in to start a discussion in %s. Share what you\'re thinking, ask the community a question, or float an idea.', 'jetonomy' ), '<strong>' . esc_html( $_space->title ) . '</strong>' );
	} else {
		$_lede = __( 'Sign in to start a discussion. Ask a question, share an idea, or kick off a topic. Replies and reactions arrive in real time.', 'jetonomy' );
	}
	?>
	<div class="jt-compose-topic-embed jt-compose-topic-login" role="region" aria-label="<?php esc_attr_e( 'Start a new discussion', 'jetonomy' ); ?>">
		<div class="jt-compose-topic-login-icon" aria-hidden="true">
			<?php jetonomy_echo_icon( 'message-circle', 28 ); ?>
		</div>
		<div class="jt-compose-topic-login-body">
			<h3 class="jt-compose-topic-login-title"><?php esc_html_e( 'Join the conversation', 'jetonomy' ); ?></h3>
			<p class="jt-compose-topic-login-lede">
				<?php
				echo wp_kses(
					$_lede,
					array( 'strong' => array() )
				);
				?>
			</p>
			<div class="jt-compose-topic-login-actions">
				<a class="jt-btn jt-btn-fill jt-compose-topic-login-primary" href="<?php echo esc_url( $_login ); ?>">
					<?php esc_html_e( 'Sign in to post', 'jetonomy' ); ?>
				</a>
				<?php if ( $_register_url ) : ?>
					<a class="jt-compose-topic-login-secondary" href="<?php echo esc_url( $_register_url ); ?>">
						<?php esc_html_e( 'New here? Create an account', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
	return;
}

// Invalid / missing space_id in fixed mode → degrade to picker so the block
// never silently breaks when a space is deleted or renumbered.
if ( 'fixed' === $_mode && ! $_space ) {
	$_mode = 'picker';
}

// Title is collected at write time on every space type (1.4.3 decision: a
// real user-chosen title is worth keeping in the data even when the post
// view hides it visually for feed spaces). Both picker and fixed modes
// render the title input.
$_initial_space_type = $_space ? (string) ( $_space->type ?? '' ) : '';
$_show_title_initial = true;

// Build a JSON-shaped lookup the JS can use to map spaceId → spaceType,
// so composeTopicSelectSpace can flip `composeShowTitle` without parsing
// the DOM. Keeping this server-side keeps the picker behaviour consistent
// even when the surrounding markup is rebuilt by themes.
$_space_types_map = array();
foreach ( $_postable as $_s ) {
	$_space_types_map[ (int) $_s->id ] = (string) ( $_s->type ?? '' );
}

$_context = array(
	'mode'             => $_mode,
	'spaceId'          => $_space_id,
	'spaceType'        => $_initial_space_type,
	'composeShowTitle' => $_show_title_initial,
	'title'            => '',
	'body'             => '',
	'postType'         => $_default_type,
	'submitting'       => false,
	'error'            => '',
	'spaceTypes'       => (object) $_space_types_map,
);

// A dummy space object for the partial when no space is resolved yet
// (picker mode, nothing chosen). The partial only reads ->id and ->type
// so a tiny stdClass is enough; this prevents a fatal when the user
// renders the picker on a page with no member spaces.
$_partial_space = $_space ? $_space : (object) array(
	'id'   => $_space_id,
	'type' => $_initial_space_type,
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
			<span class="jt-compose-topic-label"><?php echo esc_html( sprintf( __( 'Post to %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?></span>
			<select
				class="jt-compose-topic-space"
				data-wp-on--change="actions.composeTopicSelectSpace"
				data-wp-bind--disabled="context.submitting">
				<option value="" data-type=""><?php esc_html_e( 'Choose a space…', 'jetonomy' ); ?></option>
				<?php foreach ( $_postable as $_s ) : ?>
					<option
						value="<?php echo (int) $_s->id; ?>"
						data-type="<?php echo esc_attr( (string) ( $_s->type ?? '' ) ); ?>"><?php echo esc_html( $_s->title ); ?></option>
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

	<form class="jt-compose-topic-form"
		data-wp-on--submit="actions.composeTopicSubmit">

		<?php
		// Single render of the shared field partial. Flags here describe
		// the embed surface: title only when space isn't a Feed (in picker
		// mode JS toggles the title group's hidden binding when selection
		// changes); body uses a plain textarea (the embed is meant to be
		// lightweight and host-page compatible); tags, prefix, scheduler,
		// and publish-menu are full-page-only. Private toggle stays so
		// regression #9886339472 (private leak) is fixed by giving the
		// embed the same privacy switch the full page has.
		\Jetonomy\Template_Loader::partial(
			'compose-fields',
			[
				'space'                    => $_partial_space,
				'show_title'               => $_show_title_initial,
				'show_tags'                => false,
				'show_prefix'              => false,
				'show_private'             => true,
				'show_scheduler'           => false,
				'show_publish_menu'        => false,
				// The posts endpoint verifies CAPTCHA for low-trust users,
				// so the embed needs the widget just like the full page.
				'show_captcha'             => true,
				'body_mode'                => 'textarea',
				'submit_label'             => '',
				// Picker mode flips title visibility in response to space
				// switches; fixed mode stays static (no binding emitted).
				'title_visibility_binding' => ( 'picker' === $_mode ) ? '!context.composeShowTitle' : '',
			]
		);
		?>

		<div class="jt-compose-topic-actions">
			<p class="jt-compose-topic-error"
				data-wp-text="context.error"
				data-wp-class--is-shown="context.error"></p>
			<button type="submit"
				class="jt-btn jt-btn-fill jt-compose-topic-submit"
				data-wp-bind--disabled="context.submitting"
				data-wp-class--is-submitting="context.submitting">
				<?php esc_html_e( 'Post topic', 'jetonomy' ); ?>
			</button>
		</div>
	</form>
</div>
