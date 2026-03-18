<div class="wrap">
    <h1><?php esc_html_e( 'Import Forums', 'jetonomy' ); ?></h1>
    <?php if ( empty( $available ) ) : ?>
        <div class="notice notice-info"><p><?php esc_html_e( 'No forum data detected. Install bbPress or wpForo first, then come back here to import.', 'jetonomy' ); ?></p></div>
    <?php else : ?>
        <?php foreach ( $available as $id => $info ) : ?>
            <div style="background:white;padding:20px;border:1px solid #ddd;border-radius:8px;margin:16px 0;">
                <h3><?php echo esc_html( $info['name'] ); ?></h3>
                <p>
                    <?php foreach ( $info['stats'] as $key => $val ) : ?>
                        <strong><?php echo esc_html( number_format_i18n( $val ) ); ?></strong> <?php echo esc_html( $key ); ?> &nbsp;
                    <?php endforeach; ?>
                </p>
                <button class="button button-primary jetonomy-import-btn" data-source="<?php echo esc_attr( $id ); ?>"><?php printf( esc_html__( 'Import from %s', 'jetonomy' ), esc_html( $info['name'] ) ); ?></button>
                <span class="jetonomy-import-status" data-source="<?php echo esc_attr( $id ); ?>"></span>
            </div>
        <?php endforeach; ?>
        <script>
        document.querySelectorAll('.jetonomy-import-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var source = this.dataset.source;
                var status = document.querySelector('.jetonomy-import-status[data-source="' + source + '"]');
                this.disabled = true;
                status.textContent = '<?php echo esc_js( __( 'Importing...', 'jetonomy' ) ); ?>';

                var data = new FormData();
                data.append('action', 'jetonomy_run_import');
                data.append('source', source);
                data.append('nonce', '<?php echo wp_create_nonce( 'jetonomy_import' ); ?>');

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            status.textContent = 'Done! Imported: ' + res.data.imported + ', Skipped: ' + res.data.skipped + ', Errors: ' + res.data.errors.length;
                        } else {
                            status.textContent = 'Error: ' + res.data;
                        }
                        btn.disabled = false;
                    });
            });
        });
        </script>
    <?php endif; ?>
</div>
