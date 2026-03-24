<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Template_Loader {

    public static function render( array $data ): void {
        // ── /u/me/ redirect to actual user profile ──
        if ( 'profile' === $data['route'] && 'me' === $data['slug'] ) {
            if ( is_user_logged_in() ) {
                $settings = get_option( 'jetonomy_settings', [] );
                $base_slug = $settings['base_slug'] ?? 'community';
                wp_safe_redirect( home_url( '/' . $base_slug . '/u/' . wp_get_current_user()->user_login . '/' ) );
            } else {
                wp_safe_redirect( wp_login_url( home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) ) );
            }
            exit;
        }

        // ── Auth redirect for protected routes (BEFORE any output) ──
        $auth_required_routes = [ 'notifications', 'messages', 'conversation', 'edit-profile', 'new-post' ];
        if ( in_array( $data['route'], $auth_required_routes, true ) && ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( home_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) ) );
            exit;
        }

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
            'invite'        => 'views/invite.php',
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

        // RTL stylesheet for right-to-left languages.
        if ( is_rtl() ) {
            wp_enqueue_style( 'jetonomy-rtl', JETONOMY_URL . 'assets/css/jetonomy-rtl.css', [ 'jetonomy' ], JETONOMY_VERSION );
        }

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

        // Localize upload & API data for composer.js (image upload + instant search).
        wp_localize_script( 'jetonomy-composer', 'jetonomyUpload', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'jetonomy_upload' ),
            'apiBase' => rest_url( 'jetonomy/v1' ),
        ] );

        // Enqueue Prism.js for code syntax highlighting on post pages (only if files exist).
        $prism_dir = JETONOMY_DIR . 'assets/vendor/prismjs/';
        if ( in_array( $data['route'], [ 'post', 'new-post' ], true ) && file_exists( $prism_dir . 'prism.min.js' ) ) {
            wp_enqueue_style( 'prismjs', JETONOMY_URL . 'assets/vendor/prismjs/prism.min.css', [], '1.29.0' );
            wp_enqueue_script( 'prismjs', JETONOMY_URL . 'assets/vendor/prismjs/prism.min.js', [], '1.29.0', true );
            if ( file_exists( $prism_dir . 'prism-autoloader.min.js' ) ) {
                wp_enqueue_script( 'prismjs-autoloader', JETONOMY_URL . 'assets/vendor/prismjs/prism-autoloader.min.js', [ 'prismjs' ], '1.29.0', true );
            }
        }

        // Pre-flight 404 detection: check before get_header() sends HTTP headers.
        self::maybe_set_404( $data );

        // Track post view + set deduplication cookie before any output.
        self::maybe_track_post_view( $data );

        // Set up SEO
        self::set_seo_meta( $data );

        // Signal to the active theme that this is a Jetonomy community page.
        // Themes check for 'jt-page' in body_class to skip container/sidebar wrappers
        // and render Jetonomy's layout at full viewport width.
        add_filter(
            'body_class',
            static function ( array $classes ): array {
                $classes[] = 'jt-page';
                return $classes;
            }
        );

        // Use WP's get_header/get_footer for theme integration.
        // Block themes (FSE) have no header.php — use block_header_area() to avoid
        // the "Theme without header.php is deprecated" notice introduced in WP 3.0.
        if ( wp_is_block_theme() ) {
            ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); block_header_area(); ?>
<?php
        } else {
            get_header();
        }

        echo '<div id="jetonomy-app" class="jt-app" data-wp-interactive="jetonomy">';

        /**
         * Fires inside the Jetonomy app wrapper, before the header partial and
         * content container. Bridge plugins use this hook to inject a unified
         * community nav (e.g. BuddyNext subnav) in place of the default
         * Jetonomy community nav.
         *
         * @param array $data Route data array: ['route' => string, 'slug' => string].
         */
        do_action( 'jetonomy_before_content', $data );

        // Load the Jetonomy header partial (full-width, outside container)
        $header_path = file_exists( $theme_dir . 'partials/header.php' )
            ? $theme_dir . 'partials/header.php'
            : $plugin_dir . 'partials/header.php';
        if ( file_exists( $header_path ) ) {
            include $header_path;
        }

        // Open theme-compatible container for content
        echo '<div class="container">';

        // Load the main template
        include $template_path;

        echo '</div>'; // .container

        echo '</div>'; // #jetonomy-app

        if ( wp_is_block_theme() ) {
            block_footer_area();
            wp_footer();
            ?></body></html><?php
        } else {
            get_footer();
        }
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

            if ( $url ) {
                echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
            }
        }, 1 );
    }

    /**
     * Track a post view with 24-hour cookie deduplication.
     *
     * Must run before get_header() so that setcookie() fires before any output.
     */
    private static function maybe_track_post_view( array $data ): void {
        if ( 'post' !== $data['route'] || empty( $data['slug'] ) ) {
            return;
        }

        $post = \Jetonomy\Models\Post::find_by_slug( $data['slug'] );
        if ( ! $post ) {
            return;
        }

        $cookie = 'jt_viewed_' . (int) $post->id;
        // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE
        if ( empty( $_COOKIE[ $cookie ] ) ) {
            \Jetonomy\Models\Post::increment_view_count( (int) $post->id );
            setcookie( $cookie, '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        }
    }

    /**
     * Pre-flight 404 check — runs BEFORE get_header() sends HTTP headers.
     *
     * This ensures that non-existent spaces, posts, users, and tags return
     * a proper 404 HTTP status code rather than 200.
     */
    private static function maybe_set_404( array $data ): void {
        $slug = $data['slug'] ?? '';

        switch ( $data['route'] ) {
            case 'space':
                if ( $slug && ! \Jetonomy\Models\Space::find_by_slug( $slug ) ) {
                    status_header( 404 );
                }
                break;

            case 'post':
                if ( $slug ) {
                    $post = \Jetonomy\Models\Post::find_by_slug( $slug );
                    if ( ! $post ) {
                        status_header( 404 );
                    } elseif ( 'publish' !== $post->status ) {
                        // Allow moderators and the post author to view non-published posts.
                        $user_id   = get_current_user_id();
                        $is_author = $user_id && (int) $post->author_id === $user_id;
                        $can_mod   = current_user_can( 'jetonomy_moderate' );
                        if ( ! $is_author && ! $can_mod ) {
                            status_header( 404 );
                        }
                    }
                }
                break;

            case 'profile':
                if ( $slug && ! get_user_by( 'login', $slug ) ) {
                    status_header( 404 );
                }
                break;

            case 'tag':
                if ( $slug && ! \Jetonomy\Models\Tag::find_by_slug( $slug ) ) {
                    status_header( 404 );
                }
                break;

            case 'category':
                if ( $slug && ! \Jetonomy\Models\Category::find_by_slug( $slug ) ) {
                    status_header( 404 );
                }
                break;
        }
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
