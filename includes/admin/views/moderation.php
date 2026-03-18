<div class="wrap">
    <h1><?php esc_html_e( 'Moderation Queue', 'jetonomy' ); ?></h1>
    <h2><?php printf( esc_html__( 'Pending Posts (%d)', 'jetonomy' ), count( $pending_posts ) ); ?></h2>
    <?php if ( empty( $pending_posts ) ) : ?>
        <p><?php esc_html_e( 'No pending posts.', 'jetonomy' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e( 'Title', 'jetonomy' ); ?></th>
                <th><?php esc_html_e( 'Author', 'jetonomy' ); ?></th>
                <th><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $pending_posts as $p ) : $author = get_userdata( $p->author_id ); ?>
                <tr>
                    <td><?php echo esc_html( $p->title ); ?></td>
                    <td><?php echo esc_html( $author->display_name ?? 'Unknown' ); ?></td>
                    <td><?php echo esc_html( $p->created_at ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2><?php printf( esc_html__( 'Pending Flags (%d)', 'jetonomy' ), count( $pending_flags ) ); ?></h2>
    <?php if ( empty( $pending_flags ) ) : ?>
        <p><?php esc_html_e( 'No pending flags.', 'jetonomy' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e( 'Type', 'jetonomy' ); ?></th>
                <th><?php esc_html_e( 'Reason', 'jetonomy' ); ?></th>
                <th><?php esc_html_e( 'Reporter', 'jetonomy' ); ?></th>
                <th><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $pending_flags as $f ) : $reporter = get_userdata( $f->reporter_id ); ?>
                <tr>
                    <td><?php echo esc_html( $f->object_type . ' #' . $f->object_id ); ?></td>
                    <td><?php echo esc_html( $f->reason ); ?></td>
                    <td><?php echo esc_html( $reporter->display_name ?? 'Unknown' ); ?></td>
                    <td><?php echo esc_html( $f->created_at ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
