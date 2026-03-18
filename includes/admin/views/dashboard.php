<div class="wrap">
    <h1><?php esc_html_e( 'Jetonomy Dashboard', 'jetonomy' ); ?></h1>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:16px;margin:20px 0;">
        <?php foreach ( $stats as $key => $val ) : ?>
            <div style="background:white;padding:20px;border:1px solid #ddd;border-radius:8px;text-align:center;">
                <div style="font-size:28px;font-weight:700;"><?php echo esc_html( number_format_i18n( $val ) ); ?></div>
                <div style="color:#666;font-size:13px;text-transform:uppercase;letter-spacing:0.05em;"><?php echo esc_html( str_replace( '_', ' ', $key ) ); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <p><a href="<?php echo esc_url( home_url( '/community/' ) ); ?>" class="button button-primary" target="_blank"><?php esc_html_e( 'Visit Community', 'jetonomy' ); ?></a></p>
</div>
