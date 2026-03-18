<?php
defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
    wp_redirect( wp_login_url( home_url( '/community/s/' . esc_attr( $data['slug'] ) . '/new/' ) ) );
    exit;
}

$space = \Jetonomy\Models\Space::find_by_slug( $data['slug'] );
if ( ! $space ) {
    echo '<div class="jt-container"><div class="jt-empty"><div class="jt-empty-icon">404</div><p>Space not found.</p></div></div>';
    return;
}

$base = home_url( '/community' );
$space_url = $base . '/s/' . esc_attr( $space->slug ) . '/';
$post_type = ( 'qa' === $space->type ) ? 'question' : 'topic';
$type_label = ( 'qa' === $space->type ) ? __( 'Ask a Question', 'jetonomy' ) : __( 'New Topic', 'jetonomy' );

\Jetonomy\Template_Loader::partial( 'breadcrumb', [
    'crumbs' => [
        [ 'label' => $space->title, 'url' => $space_url ],
        [ 'label' => $type_label, 'url' => '' ],
    ],
] );
?>

<div class="jt-container" style="max-width:800px;">
    <h1 class="jt-post-create-title"><?php echo esc_html( $type_label ); ?></h1>
    <p class="jt-post-create-subtitle">
        <?php printf( esc_html__( 'Posting in %s', 'jetonomy' ), '<strong>' . esc_html( $space->title ) . '</strong>' ); ?>
    </p>

    <form id="jt-new-post-form" class="jt-new-post-form" data-wp-interactive="jetonomy">
        <input type="hidden" name="space_id" value="<?php echo (int) $space->id; ?>">
        <input type="hidden" name="type" value="<?php echo esc_attr( $post_type ); ?>">

        <div class="jt-form-group">
            <label for="jt-post-title" class="jt-label"><?php esc_html_e( 'Title', 'jetonomy' ); ?></label>
            <input type="text" id="jt-post-title" name="title" class="jt-input"
                   placeholder="<?php echo ( 'question' === $post_type ) ? esc_attr__( 'What is your question?', 'jetonomy' ) : esc_attr__( 'Topic title', 'jetonomy' ); ?>"
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
                </div>
                <div class="jt-editor-body" contenteditable="true" data-placeholder="<?php esc_attr_e( 'Write your post...', 'jetonomy' ); ?>"></div>
            </div>
        </div>

        <div class="jt-form-actions">
            <a href="<?php echo esc_url( $space_url ); ?>" class="jt-btn jt-btn-ghost"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></a>
            <button type="submit" class="jt-btn jt-btn-fill" id="jt-submit-post">
                <?php echo ( 'question' === $post_type ) ? esc_html__( 'Post Question', 'jetonomy' ) : esc_html__( 'Post Topic', 'jetonomy' ); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.getElementById('jt-new-post-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const btn = document.getElementById('jt-submit-post');
    const editor = document.querySelector('#jt-post-editor .jt-editor-body');
    const title = document.getElementById('jt-post-title').value.trim();
    const content = editor.innerHTML.trim();
    const tags = document.getElementById('jt-post-tags').value.trim();
    const spaceId = form.querySelector('[name=space_id]').value;
    const type = form.querySelector('[name=type]').value;

    if (!title || !content) {
        alert('<?php echo esc_js( __( 'Title and content are required.', 'jetonomy' ) ); ?>');
        return;
    }

    btn.disabled = true;
    btn.textContent = '<?php echo esc_js( __( 'Posting...', 'jetonomy' ) ); ?>';

    fetch('<?php echo esc_url( rest_url( 'jetonomy/v1/spaces/' ) ); ?>' + spaceId + '/posts', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
        },
        body: JSON.stringify({ title, content, type, tags: tags ? tags.split(',').map(t => t.trim()) : [] })
    })
    .then(r => r.json())
    .then(res => {
        if (res.id || (res.data && res.data.id)) {
            const postSlug = res.slug || res.data?.slug || '';
            window.location.href = '<?php echo esc_url( $space_url ); ?>t/' + postSlug + '/';
        } else {
            alert(res.message || '<?php echo esc_js( __( 'Error creating post.', 'jetonomy' ) ); ?>');
            btn.disabled = false;
            btn.textContent = '<?php echo esc_js( $type_label ); ?>';
        }
    })
    .catch(() => {
        alert('<?php echo esc_js( __( 'Network error. Please try again.', 'jetonomy' ) ); ?>');
        btn.disabled = false;
        btn.textContent = '<?php echo esc_js( $type_label ); ?>';
    });
});
</script>
