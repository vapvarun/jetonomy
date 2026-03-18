<div class="wrap">
    <h1><?php esc_html_e( 'Jetonomy Settings', 'jetonomy' ); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields( 'jetonomy_settings' ); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="base_slug"><?php esc_html_e( 'Community Base Slug', 'jetonomy' ); ?></label></th>
                <td>
                    <input type="text" id="base_slug" name="jetonomy_settings[base_slug]" value="<?php echo esc_attr( $settings['base_slug'] ?? 'community' ); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e( 'URL base for your community (e.g., "community" → /community/)', 'jetonomy' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="posts_per_page"><?php esc_html_e( 'Posts Per Page', 'jetonomy' ); ?></label></th>
                <td><input type="number" id="posts_per_page" name="jetonomy_settings[posts_per_page]" value="<?php echo absint( $settings['posts_per_page'] ?? 20 ); ?>" min="5" max="100"></td>
            </tr>
            <tr>
                <th scope="row"><label for="replies_per_page"><?php esc_html_e( 'Replies Per Page', 'jetonomy' ); ?></label></th>
                <td><input type="number" id="replies_per_page" name="jetonomy_settings[replies_per_page]" value="<?php echo absint( $settings['replies_per_page'] ?? 30 ); ?>" min="5" max="100"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Access', 'jetonomy' ); ?></th>
                <td>
                    <label><input type="checkbox" name="jetonomy_settings[guest_read]" value="1" <?php checked( ! empty( $settings['guest_read'] ) ); ?>> <?php esc_html_e( 'Allow guests to read public spaces', 'jetonomy' ); ?></label><br>
                    <label><input type="checkbox" name="jetonomy_settings[require_login]" value="1" <?php checked( ! empty( $settings['require_login'] ) ); ?>> <?php esc_html_e( 'Require login to participate', 'jetonomy' ); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="email_from_name"><?php esc_html_e( 'Email From Name', 'jetonomy' ); ?></label></th>
                <td><input type="text" id="email_from_name" name="jetonomy_settings[email_from_name]" value="<?php echo esc_attr( $settings['email_from_name'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="email_from_email"><?php esc_html_e( 'Email From Address', 'jetonomy' ); ?></label></th>
                <td><input type="email" id="email_from_email" name="jetonomy_settings[email_from_email]" value="<?php echo esc_attr( $settings['email_from_email'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
</div>
