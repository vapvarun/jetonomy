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
        ];

        $route = $data['route'];
        $template_file = $template_map[ $route ] ?? null;

        if ( ! $template_file ) {
            status_header( 404 );
            return;
        }

        // Check theme override first, then plugin
        $template_path = file_exists( $theme_dir . $template_file )
            ? $theme_dir . $template_file
            : $plugin_dir . $template_file;

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
        wp_interactivity_state( 'jetonomy', [
            'apiBase'       => rest_url( 'jetonomy/v1' ),
            '_nonce'        => wp_create_nonce( 'wp_rest' ),
            'currentPostId' => 0,
            'postScores'    => new \stdClass(),
            'replyScores'   => new \stdClass(),
            'currentSort'   => sanitize_text_field( $_GET['sort'] ?? 'latest' ),
            'unreadCount'   => 0,
        ] );

        // Enqueue Interactivity API module
        wp_enqueue_script_module(
            'jetonomy-view',
            JETONOMY_URL . 'assets/js/view.js',
            [ '@wordpress/interactivity' ],
            JETONOMY_VERSION
        );

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
