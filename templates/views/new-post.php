<?php
defined( 'ABSPATH' ) || exit;

// Auth check is handled by Template_Loader before output.
$space = \Jetonomy\Models\Space::find_by_slug( $data['slug'] );
if ( ! $space ) {
    echo '<div class="jt-empty"><div class="jt-empty-icon">404</div><p>' . esc_html__( 'Space not found.', 'jetonomy' ) . '</p></div>';
    return;
}

$base = home_url( '/community' );
$space_url = $base . '/s/' . esc_attr( $space->slug ) . '/';
$space_type_map = [
	'qa'    => [ 'post_type' => 'question', 'label' => __( 'Ask a Question', 'jetonomy' ) ],
	'ideas' => [ 'post_type' => 'idea',     'label' => __( 'Share an Idea',  'jetonomy' ) ],
	'feed'  => [ 'post_type' => 'status',   'label' => __( 'New Status',     'jetonomy' ) ],
];
$type_defaults = $space_type_map[ $space->type ] ?? [ 'post_type' => 'topic', 'label' => __( 'New Topic', 'jetonomy' ) ];
$post_type  = $type_defaults['post_type'];
$type_label = $type_defaults['label'];

\Jetonomy\Template_Loader::partial( 'breadcrumb', [
    'crumbs' => [
        [ 'label' => $space->title, 'url' => $space_url ],
        [ 'label' => $type_label, 'url' => '' ],
    ],
] );
?>

<div class="jt-narrow">
    <h1 class="jt-post-create-title"><?php echo esc_html( $type_label ); ?></h1>
    <p class="jt-post-create-subtitle">
        <?php printf( esc_html__( 'Posting in %s', 'jetonomy' ), '<strong>' . esc_html( $space->title ) . '</strong>' ); ?>
    </p>

    <form id="jt-new-post-form" class="jt-new-post-form"
          data-wp-interactive="jetonomy"
          data-wp-on--submit="actions.submitNewPost"
          data-wp-context='<?php echo wp_json_encode( [ "spaceId" => (int) $space->id, "spaceSlug" => $space->slug, "postType" => $post_type ] ); ?>'>
        <input type="hidden" name="space_id" value="<?php echo (int) $space->id; ?>">
        <input type="hidden" name="type" value="<?php echo esc_attr( $post_type ); ?>">

        <div class="jt-form-group">
            <label for="jt-post-title" class="jt-label"><?php esc_html_e( 'Title', 'jetonomy' ); ?></label>
            <input type="text" id="jt-post-title" name="title" class="jt-input"
                   placeholder="<?php
				$title_placeholders = [
					'question' => __( 'What is your question?', 'jetonomy' ),
					'idea'     => __( 'Describe your idea',     'jetonomy' ),
					'status'   => __( "What's on your mind?",  'jetonomy' ),
				];
				echo esc_attr( $title_placeholders[ $post_type ] ?? __( 'Topic title', 'jetonomy' ) );
				?>"
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
                    <button type="button" data-cmd="link" title="Link">&#128279;</button>
                    <button type="button" data-cmd="quote" title="Quote">&#10077;</button>
                    <button type="button" data-cmd="image" title="Upload Image">&#128247;</button>
                    <button type="button" data-cmd="emoji" title="Emoji">&#128522;</button>
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

        <div class="jt-form-actions">
            <a href="<?php echo esc_url( $space_url ); ?>" class="jt-btn jt-btn-ghost"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></a>
            <button type="submit" class="jt-btn jt-btn-fill" data-wp-bind--disabled="state.isSubmitting" data-wp-text="state.submitLabel">
                <?php
				$submit_labels = [
					'question' => __( 'Post Question', 'jetonomy' ),
					'idea'     => __( 'Submit Idea',   'jetonomy' ),
					'status'   => __( 'Post Status',   'jetonomy' ),
				];
				echo esc_html( $submit_labels[ $post_type ] ?? __( 'Post Topic', 'jetonomy' ) );
				?>
            </button>
        </div>
    </form>
</div>
