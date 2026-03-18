<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Template_Loader {

    public static function render( array $data ): void {
        // Allow theme overrides: theme/jetonomy/views/home.php
        $theme_dir = get_stylesheet_directory() . '/jetonomy/';
        $plugin_dir = JETONOMY_DIR . 'templates/';

        // Map routes to template files
        $template_map = [
            'home'          => 'views/home.php',
            'category'      => 'views/category.php',
            'space'         => 'views/space.php',
            'space-members' => 'views/space-members.php',
            'space-roadmap' => 'views/space-roadmap.php',
            'post'          => 'views/single-post.php',
            'profile'       => 'views/user-profile.php',
            'notifications' => 'views/notifications.php',
            'search'        => 'views/search.php',
            'leaderboard'   => 'views/leaderboard.php',
            'moderation'    => 'views/moderation.php',
            'tag'           => 'views/tag.php',
            'new-post'      => 'views/new-post.php',
            'edit-profile'  => 'views/edit-profile.php',
        ];

        /**
         * Filter the template map so Pro (or other plugins) can register
         * additional routes or override existing template paths.
         *
         * Values may be relative (resolved against plugin_dir/theme_dir)
         * or absolute paths (starting with /).
         *
         * @param array $template_map Route => template file map.
         */
        $template_map = apply_filters( 'jetonomy_template_map', $template_map );

        $route = $data['route'];
        $template_file = $template_map[ $route ] ?? null;

        if ( ! $template_file ) {
            status_header( 404 );
            return;
        }

        // If the template path is absolute (from Pro), use it directly.
        // Otherwise, check theme override first, then plugin directory.
        if ( str_starts_with( $template_file, '/' ) || str_starts_with( $template_file, ABSPATH ) ) {
            $template_path = $template_file;
        } else {
            $template_path = file_exists( $theme_dir . $template_file )
                ? $theme_dir . $template_file
                : $plugin_dir . $template_file;
        }

        if ( ! file_exists( $template_path ) ) {
            status_header( 404 );
            return;
        }

        // Enqueue styles
        wp_enqueue_style(
            'jetonomy',
            JETONOMY_URL . 'assets/css/jetonomy.css',
            [],
            JETONOMY_VERSION
        );

        // Set up Interactivity API state
        $settings = get_option( 'jetonomy_settings', [] );
        wp_interactivity_state( 'jetonomy', [
            'apiBase'       => rest_url( 'jetonomy/v1' ),
            '_nonce'        => wp_create_nonce( 'wp_rest' ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'communityBase' => home_url( '/' . ( $settings['base_slug'] ?? 'community' ) ),
            'currentPostId' => 0,
            'postScores'    => new \stdClass(),
            'replyScores'   => new \stdClass(),
            'currentSort'   => sanitize_text_field( $_GET['sort'] ?? 'latest' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'unreadCount'   => 0,
            'isSubmitting'  => false,
            'submitLabel'   => __( 'Post Topic', 'jetonomy' ),
        ] );

        // Enqueue Interactivity API module
        wp_enqueue_script_module(
            'jetonomy-view',
            JETONOMY_URL . 'assets/js/view.js',
            [ '@wordpress/interactivity' ],
            JETONOMY_VERSION
        );

        // Enqueue composer enhancement script
        wp_enqueue_script(
            'jetonomy-composer',
            JETONOMY_URL . 'assets/js/composer.js',
            [],
            JETONOMY_VERSION,
            true
        );

        // Enqueue Prism.js for code syntax highlighting on post pages.
        if ( in_array( $data['route'], [ 'post', 'new-post' ], true ) ) {
            wp_enqueue_style( 'prismjs', 'https://cdn.jsdelivr.net/npm/prismjs@1.29.0/themes/prism.min.css', [], '1.29.0' );
            wp_enqueue_script( 'prismjs', 'https://cdn.jsdelivr.net/npm/prismjs@1.29.0/prism.min.js', [], '1.29.0', true );
            wp_enqueue_script( 'prismjs-autoloader', 'https://cdn.jsdelivr.net/npm/prismjs@1.29.0/plugins/autoloader/prism-autoloader.min.js', [ 'prismjs' ], '1.29.0', true );
        }

        // Set up SEO
        self::set_seo_meta( $data );

        // Use WP's get_header/get_footer for theme integration
        get_header();

        echo '<div id="jetonomy-app" class="jt-app">';

        // Load the Jetonomy header partial
        $header_path = file_exists( $theme_dir . 'partials/header.php' )
            ? $theme_dir . 'partials/header.php'
            : $plugin_dir . 'partials/header.php';
        if ( file_exists( $header_path ) ) {
            include $header_path;
        }

        // Load the main template
        include $template_path;

        echo '</div>';

        get_footer();
    }

    private static function set_seo_meta( array $data ): void {
        add_filter( 'document_title_parts', function ( $parts ) use ( $data ) {
            switch ( $data['route'] ) {
                case 'home':
                    $parts['title'] = __( 'Community', 'jetonomy' );
                    break;
                case 'space':
                    $parts['title'] = ucfirst( str_replace( '-', ' ', $data['slug'] ) ) . ' — ' . __( 'Community', 'jetonomy' );
                    break;
                case 'post':
                    $parts['title'] = ucfirst( str_replace( '-', ' ', $data['slug'] ) );
                    break;
            }
            return $parts;
        } );

        // Meta description + OG tags
        add_action( 'wp_head', function() use ( $data ) {
            $desc  = '';
            $title = '';
            $url   = '';
            $image = '';

            switch ( $data['route'] ) {
                case 'home':
                    $title = get_bloginfo( 'name' ) . ' Community';
                    $desc  = __( 'Join our community discussions, Q&A, and more.', 'jetonomy' );
                    $url   = home_url( '/community/' );
                    break;
                case 'space':
                    $space = \Jetonomy\Models\Space::find_by_slug( $data['slug'] );
                    if ( $space ) {
                        $title = $space->title;
                        $desc  = wp_strip_all_tags( $space->description ?? '' );
                        $url   = home_url( '/community/s/' . $space->slug . '/' );
                        $image = $space->cover_image ?? '';
                    }
                    break;
                case 'post':
                    $post = \Jetonomy\Models\Post::find_by_slug( $data['slug'] );
                    if ( $post ) {
                        $title = $post->title;
                        $desc  = mb_substr( wp_strip_all_tags( $post->content ), 0, 160 );
                        $space = \Jetonomy\Models\Space::find( (int) $post->space_id );
                        $url   = home_url( '/community/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/' );
                    }
                    break;
                case 'profile':
                    $user = get_user_by( 'login', $data['slug'] );
                    if ( $user ) {
                        $title = $user->display_name;
                        $desc  = __( 'Community member profile', 'jetonomy' );
                        $url   = home_url( '/community/u/' . $data['slug'] . '/' );
                    }
                    break;
            }

            if ( $desc ) {
                echo '<meta name="description" content="' . esc_attr( mb_substr( $desc, 0, 160 ) ) . '">' . "\n";
            }
            if ( $title ) {
                echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
                echo '<meta property="og:description" content="' . esc_attr( mb_substr( $desc, 0, 200 ) ) . '">' . "\n";
                echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
                echo '<meta property="og:type" content="website">' . "\n";
                echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
                if ( $image ) {
                    echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
                }
                echo '<meta name="twitter:card" content="summary">' . "\n";
                echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
                echo '<meta name="twitter:description" content="' . esc_attr( mb_substr( $desc, 0, 200 ) ) . '">' . "\n";
            }
        }, 1 );
    }

    /**
     * Helper to load a partial template.
     */
    public static function partial( string $name, array $args = [] ): void {
        $theme_path = get_stylesheet_directory() . '/jetonomy/partials/' . $name . '.php';
        $plugin_path = JETONOMY_DIR . 'templates/partials/' . $name . '.php';

        $path = file_exists( $theme_path ) ? $theme_path : $plugin_path;

        if ( file_exists( $path ) ) {
            extract( $args, EXTR_SKIP );
            include $path;
        }
    }
}
