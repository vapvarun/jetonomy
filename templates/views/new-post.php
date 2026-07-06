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
			'message'   => sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ),
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
					'spaceType'     => (string) ( $space->type ?? '' ),
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
		// Hand the full field set off to the shared partial. Flags here
		// describe the FULL page composer; the embed picks the same partial
		// with a different flag combo. Centralising the fields means a
		// future field (e.g. mood pill, anonymous toggle) only has to be
		// added in compose-fields.php — both surfaces inherit it for free.
		\Jetonomy\Template_Loader::partial(
			'compose-fields',
			[
				'space'             => $space,
				// Feed spaces are short-form: hide the title field (matching the
				// inline composer) so members post a status without a headline.
				// The server derives a title from the body for breadcrumbs,
				// notifications, search, and share previews. Every other space
				// type still collects a real title at write time. Rendering the
				// title input on feed spaces also marked it `required`, so the
				// browser blocked an empty-title submit before JS ever ran.
				'show_title'        => ( 'feed' !== ( $space->type ?? '' ) ),
				'show_tags'         => true,
				// Prefill the tags field when arriving from a tag page's
				// "Start a discussion tagged #x" CTA (?tag=slug). Tag pages are
				// otherwise a browse dead-end with no contribution path.
				'tags_value'        => isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill, no state change.
				'show_prefix'       => true,
				'show_private'      => true,
				'show_scheduler'    => true,
				'show_publish_menu' => true,
				'show_captcha'      => true,
				'fields_hook'       => 'jetonomy_new_post_fields',
				'body_mode'         => 'editor',
				'submit_label'      => $jt_submit_label,
				'cancel_url'        => $space_url,
			]
		);
		?>
	</form>
</div>
