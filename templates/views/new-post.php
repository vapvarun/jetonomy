<?php
/**
 * New post form view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

// Auth check is handled by Template_Loader before output.
$space = \Jetonomy\Models\Space::find_by_slug( $data['slug'] );
if ( ! $space ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Space not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

$base           = \Jetonomy\base_url();
$space_url      = $base . '/s/' . esc_attr( $space->slug ) . '/';
$space_type_map = [
	'qa'    => [
		'post_type' => 'question',
		'label'     => __( 'Ask a Question', 'jetonomy' ),
	],
	'ideas' => [
		'post_type' => 'idea',
		'label'     => __( 'Share an Idea', 'jetonomy' ),
	],
	'feed'  => [
		'post_type' => 'status',
		'label'     => __( 'New Status', 'jetonomy' ),
	],
];
$type_defaults  = $space_type_map[ $space->type ] ?? [
	'post_type' => 'topic',
	'label'     => __( 'New Topic', 'jetonomy' ),
];
$post_type      = $type_defaults['post_type'];
$type_label     = $type_defaults['label'];

// Type-aware submit label. Set as Interactivity state so the button text
// stays correct after hydration (the SSR'd label gets replaced by
// state.submitLabel via data-wp-text).
$jt_submit_labels = array(
	'question' => __( 'Post Question', 'jetonomy' ),
	'idea'     => __( 'Submit Idea', 'jetonomy' ),
	'status'   => __( 'Post Status', 'jetonomy' ),
);
$jt_submit_label  = $jt_submit_labels[ $post_type ] ?? __( 'Post Topic', 'jetonomy' );
if ( function_exists( 'wp_interactivity_state' ) ) {
	wp_interactivity_state( 'jetonomy', array( 'submitLabel' => $jt_submit_label ) );
}

\Jetonomy\Template_Loader::partial(
	'breadcrumb',
	[
		'crumbs' => [
			[
				'label' => $space->title,
				'url'   => $space_url,
			],
			[
				'label' => $type_label,
				'url'   => '',
			],
		],
	]
);
?>

<div class="jt-narrow">
	<h1 class="jt-post-create-title"><?php echo esc_html( $type_label ); ?></h1>
	<p class="jt-post-create-subtitle">
		<?php printf( esc_html__( 'Posting in %s', 'jetonomy' ), '<strong>' . esc_html( $space->title ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %s contains esc_html() output wrapped in static tag. ?>
	</p>

	<div class="jt-form-error"
		role="alert"
		data-wp-interactive="jetonomy"
		data-wp-bind--hidden="!state.submitError"
		data-wp-text="state.submitError"
		hidden></div>

	<form id="jt-new-post-form" class="jt-new-post-form"
			data-wp-interactive="jetonomy"
			data-wp-on--submit="<?php echo esc_attr( apply_filters( 'jetonomy_new_post_submit_action', 'actions.submitNewPost' ) ); ?>"
			data-wp-context='
			<?php
			echo wp_json_encode(
				[
					'spaceId'       => (int) $space->id,
					'spaceSlug'     => $space->slug,
					'postType'      => $post_type,
					'postStatus'    => 'publish',
					'showScheduler' => false,
				]
			);
			?>
			'>
		<input type="hidden" name="space_id" value="<?php echo (int) $space->id; ?>">
		<input type="hidden" name="type" value="<?php echo esc_attr( $post_type ); ?>">

		<?php
		// Feed spaces hide the title field — a status post is its content.
		// The REST layer derives a title from the body so search and
		// breadcrumbs still have something to work with.
		$jt_hide_title = ( 'feed' === ( $space->type ?? '' ) );
		if ( ! $jt_hide_title ) :
			?>
			<div class="jt-form-group">
				<label for="jt-post-title" class="jt-label"><?php esc_html_e( 'Title', 'jetonomy' ); ?></label>
				<?php
				$title_placeholders = [
					'question' => __( 'What is your question?', 'jetonomy' ),
					'idea'     => __( 'Describe your idea', 'jetonomy' ),
				];
				$title_placeholder  = esc_attr( $title_placeholders[ $post_type ] ?? __( 'Topic title', 'jetonomy' ) );
				?>
				<input type="text" id="jt-post-title" name="title" class="jt-input"
						placeholder="<?php echo $title_placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>"
						data-space-id="<?php echo (int) $space->id; ?>"
						required maxlength="255" autofocus>
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
			</div>
		<?php endif; ?>

		<div class="jt-form-group">
			<label for="jt-post-tags" class="jt-label"><?php esc_html_e( 'Tags', 'jetonomy' ); ?> <span class="jt-label-hint"><?php esc_html_e( '(optional, comma-separated)', 'jetonomy' ); ?></span></label>
			<input type="text" id="jt-post-tags" name="tags" class="jt-input" placeholder="<?php esc_attr_e( 'e.g. python, django, architecture', 'jetonomy' ); ?>">
		</div>

		<?php
		$space_settings_np = \Jetonomy\Models\Space::get_settings( (int) $space->id );
		$available_pfx     = ( ! empty( $space_settings_np['enable_prefixes'] ) && ! empty( $space_settings_np['prefixes'] ) ) ? $space_settings_np['prefixes'] : array();
		if ( ! empty( $available_pfx ) ) :
			?>
		<div class="jt-form-group">
			<label for="jt-post-prefix" class="jt-label"><?php esc_html_e( 'Prefix', 'jetonomy' ); ?> <span class="jt-label-hint"><?php esc_html_e( '(optional)', 'jetonomy' ); ?></span></label>
			<select id="jt-post-prefix" name="prefix" class="jt-select">
				<option value=""><?php esc_html_e( 'None', 'jetonomy' ); ?></option>
				<?php foreach ( $available_pfx as $avpfx ) : ?>
					<option value="<?php echo esc_attr( $avpfx['name'] ); ?>"><?php echo esc_html( $avpfx['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php endif; ?>

		<div class="jt-form-group">
			<label class="jt-label"><?php esc_html_e( 'Content', 'jetonomy' ); ?></label>
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
		</div>

		<?php
		/**
		 * Fires after the standard new-post form fields, before the submit button.
		 * Pro hooks custom fields here.
		 *
		 * @param object|null $space The space object.
		 */
		do_action( 'jetonomy_new_post_fields', $space );
		?>

		<?php if ( is_user_logged_in() ) : ?>
		<div class="jt-form-group jt-private-toggle">
			<label class="jt-checkbox-label">
				<input type="checkbox" name="is_private" value="1" id="jt-post-private">
				<?php jetonomy_echo_icon( 'lock', 14 ); ?>
				<?php esc_html_e( 'Private: only you and moderators can see this topic', 'jetonomy' ); ?>
			</label>
		</div>
		<?php endif; ?>

		<!-- Scheduler panel — shown when "Schedule" is selected.
			Initial `hidden` attribute prevents a flash-of-visible-content
			before the Interactivity API hydrates the data-wp-bind--hidden binding. -->
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
					// the hidden published_time value before submit.
					?>
					<div class="jt-time-split" aria-label="<?php esc_attr_e( 'Time', 'jetonomy' ); ?>">
						<select id="jt-post-published-hour" name="published_hour" class="jt-input jt-input--time-part" aria-label="<?php esc_attr_e( 'Hour', 'jetonomy' ); ?>">
							<option value=""><?php esc_html_e( 'HH', 'jetonomy' ); ?></option>
							<?php for ( $h = 0; $h < 24; $h++ ) : ?>
								<option value="<?php echo esc_attr( str_pad( (string) $h, 2, '0', STR_PAD_LEFT ) ); ?>"><?php echo esc_html( str_pad( (string) $h, 2, '0', STR_PAD_LEFT ) ); ?></option>
							<?php endfor; ?>
						</select>
						<span class="jt-time-split-sep" aria-hidden="true">:</span>
						<select id="jt-post-published-minute" name="published_minute" class="jt-input jt-input--time-part" aria-label="<?php esc_attr_e( 'Minute', 'jetonomy' ); ?>">
							<option value=""><?php esc_html_e( 'MM', 'jetonomy' ); ?></option>
							<?php for ( $m = 0; $m < 60; $m += 5 ) : ?>
								<option value="<?php echo esc_attr( str_pad( (string) $m, 2, '0', STR_PAD_LEFT ) ); ?>"><?php echo esc_html( str_pad( (string) $m, 2, '0', STR_PAD_LEFT ) ); ?></option>
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

		<div class="jt-form-actions">
			<a href="<?php echo esc_url( $space_url ); ?>" class="jt-btn jt-btn-ghost"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></a>

			<!-- Publish mode selector -->
			<div class="jt-publish-mode">
				<button type="submit" class="jt-btn jt-btn-fill jt-publish-mode__submit"
						data-wp-bind--disabled="state.isSubmitting"
						data-wp-text="state.submitLabel">
					<?php
					$submit_labels = [
						'question' => __( 'Post Question', 'jetonomy' ),
						'idea'     => __( 'Submit Idea', 'jetonomy' ),
						'status'   => __( 'Post Status', 'jetonomy' ),
					];
					echo esc_html( $submit_labels[ $post_type ] ?? __( 'Post Topic', 'jetonomy' ) );
					?>
				</button>
				<button type="button" class="jt-btn jt-btn-fill jt-publish-mode__toggle"
						aria-label="<?php esc_attr_e( 'More publishing options', 'jetonomy' ); ?>"
						aria-haspopup="true"
						data-wp-on--click="actions.togglePublishMenu">
					<?php jetonomy_echo_icon( 'chevron-down', 16 ); ?>
				</button>
				<div class="jt-publish-mode__menu" hidden data-wp-bind--hidden="!state.publishMenuOpen">
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
		</div>
	</form>
</div>
