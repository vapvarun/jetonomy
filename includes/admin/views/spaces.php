<div class="wrap">
    <h1><?php esc_html_e( 'Spaces', 'jetonomy' ); ?></h1>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th><?php esc_html_e( 'Title', 'jetonomy' ); ?></th>
            <th><?php esc_html_e( 'Type', 'jetonomy' ); ?></th>
            <th><?php esc_html_e( 'Posts', 'jetonomy' ); ?></th>
            <th><?php esc_html_e( 'Members', 'jetonomy' ); ?></th>
            <th><?php esc_html_e( 'Status', 'jetonomy' ); ?></th>
        </tr></thead>
        <tbody>
        <?php if ( empty( $spaces ) ) : ?>
            <tr><td colspan="5"><?php esc_html_e( 'No spaces yet.', 'jetonomy' ); ?></td></tr>
        <?php else : foreach ( $spaces as $space ) : ?>
            <tr>
                <td><strong><?php echo esc_html( $space->title ); ?></strong><br><code>/community/s/<?php echo esc_html( $space->slug ); ?>/</code></td>
                <td><?php echo esc_html( ucfirst( $space->type ) ); ?></td>
                <td><?php echo (int) $space->post_count; ?></td>
                <td><?php echo (int) $space->member_count; ?></td>
                <td><?php echo esc_html( ucfirst( $space->status ) ); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
