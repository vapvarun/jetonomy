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
	echo '<div class="jt-empty"><div class="jt-empty-icon">404</div><p>' . esc_html__( 'Space not found.', 'jetonomy' ) . '</p></div>';
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

	<form id="jt-new-post-form" class="jt-new-post-form"
			data-wp-interactive="jetonomy"
			data-wp-on--submit="actions.submitNewPost"
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

		<div class="jt-form-group">
			<label for="jt-post-title" class="jt-label"><?php esc_html_e( 'Title', 'jetonomy' ); ?></label>
			<?php
			$title_placeholders = [
				'question' => __( 'What is your question?', 'jetonomy' ),
				'idea'     => __( 'Describe your idea', 'jetonomy' ),
				'status'   => __( "What's on your mind?", 'jetonomy' ),
			];
			$title_placeholder = esc_attr( $title_placeholders[ $post_type ] ?? __( 'Topic title', 'jetonomy' ) );
			?>
			<input type="text" id="jt-post-title" name="title" class="jt-input"
					placeholder="<?php echo $title_placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above ?>"
					required maxlength="255" autofocus>
		</div>

		<div class="jt-form-group">
			<label for="jt-post-tags" class="jt-label"><?php esc_html_e( 'Tags', 'jetonomy' ); ?> <span class="jt-label-hint"><?php esc_html_e( '(optional, comma-separated)', 'jetonomy' ); ?></span></label>
			<input type="text" id="jt-post-tags" name="tags" class="jt-input" placeholder="<?php esc_attr_e( 'e.g. python, django, architecture', 'jetonomy' ); ?>">
		</div>

		<div class="jt-form-group">
			<label class="jt-label"><?php esc_html_e( 'Content', 'jetonomy' ); ?></label>
			<div class="jt-editor" id="jt-post-editor">
				<div class="jt-editor-bar">
					<button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button>
					<button type="button" data-cmd="italic" title="Italic"><em>I</em></button>
					<button type="button" data-cmd="code" title="Code">&lt;/&gt;</button>
					<button type="button" data-cmd="link" title="Link"><?php jetonomy_echo_icon( 'link', 16 ); ?></button>
					<button type="button" data-cmd="quote" title="Quote"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V20c0 1 0 1 1 1z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3c0 1 0 1 1 1z"/></svg></button>
					<button type="button" data-cmd="image" title="Upload Image"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></button>
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

		<!-- Scheduler panel — shown when "Schedule" is selected -->
		<div class="jt-schedule-panel" data-wp-bind--hidden="!context.showScheduler">
			<div class="jt-form-group">
				<label for="jt-post-published-at" class="jt-label">
					<?php esc_html_e( 'Publish on', 'jetonomy' ); ?>
				</label>
				<input type="datetime-local" id="jt-post-published-at" name="published_at" class="jt-input jt-input--datetime">
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
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
				</button>
				<div class="jt-publish-mode__menu" data-wp-bind--hidden="!state.publishMenuOpen">
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
