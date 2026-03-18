<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Router {

    private string $base_slug = 'community';

    public function __construct() {
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_request' ] );
    }

    public function add_rewrite_rules(): void {
        $base = $this->get_base_slug();

        // Community home
        add_rewrite_rule( "^{$base}/?$", 'index.php?jetonomy_route=home', 'top' );

        // Category view
        add_rewrite_rule( "^{$base}/category/([^/]+)/?$", 'index.php?jetonomy_route=category&jetonomy_slug=$matches[1]', 'top' );

        // Space view
        add_rewrite_rule( "^{$base}/s/([^/]+)/?$", 'index.php?jetonomy_route=space&jetonomy_slug=$matches[1]', 'top' );

        // Space members
        add_rewrite_rule( "^{$base}/s/([^/]+)/members/?$", 'index.php?jetonomy_route=space-members&jetonomy_slug=$matches[1]', 'top' );

        // Space roadmap (ideas)
        add_rewrite_rule( "^{$base}/s/([^/]+)/roadmap/?$", 'index.php?jetonomy_route=space-roadmap&jetonomy_slug=$matches[1]', 'top' );

        // New post in space
        add_rewrite_rule( "^{$base}/s/([^/]+)/new/?$", 'index.php?jetonomy_route=new-post&jetonomy_slug=$matches[1]', 'top' );

        // Single post
        add_rewrite_rule( "^{$base}/s/([^/]+)/t/([^/]+)/?$", 'index.php?jetonomy_route=post&jetonomy_space_slug=$matches[1]&jetonomy_slug=$matches[2]', 'top' );

        // User profile
        add_rewrite_rule( "^{$base}/u/([^/]+)/?$", 'index.php?jetonomy_route=profile&jetonomy_slug=$matches[1]', 'top' );

        // User profile edit
        add_rewrite_rule( "^{$base}/u/([^/]+)/edit/?$", 'index.php?jetonomy_route=edit-profile&jetonomy_slug=$matches[1]', 'top' );

        // User sub-pages
        add_rewrite_rule( "^{$base}/u/([^/]+)/(posts|badges|activity)/?$", 'index.php?jetonomy_route=profile&jetonomy_slug=$matches[1]&jetonomy_tab=$matches[2]', 'top' );

        // Notifications
        add_rewrite_rule( "^{$base}/notifications/?$", 'index.php?jetonomy_route=notifications', 'top' );

        // Search
        add_rewrite_rule( "^{$base}/search/?$", 'index.php?jetonomy_route=search', 'top' );

        // Leaderboard
        add_rewrite_rule( "^{$base}/leaderboard/?$", 'index.php?jetonomy_route=leaderboard', 'top' );

        // Moderation
        add_rewrite_rule( "^{$base}/mod/?$", 'index.php?jetonomy_route=moderation', 'top' );

        // Tags
        add_rewrite_rule( "^{$base}/tags/([^/]+)/?$", 'index.php?jetonomy_route=tag&jetonomy_slug=$matches[1]', 'top' );

        // Messages (Pro module registers its own rules, but the free plugin
        // reserves the URL pattern so that flushing works correctly when
        // Pro is activated/deactivated).
        if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
            add_rewrite_rule( "^{$base}/messages/?$", 'index.php?jetonomy_route=messages', 'top' );
            add_rewrite_rule( "^{$base}/messages/(\d+)/?$", 'index.php?jetonomy_route=conversation&jetonomy_slug=$matches[1]', 'top' );
        }
    }

    public function add_query_vars( array $vars ): array {
        $vars[] = 'jetonomy_route';
        $vars[] = 'jetonomy_slug';
        $vars[] = 'jetonomy_space_slug';
        $vars[] = 'jetonomy_tab';
        return $vars;
    }

    public function handle_request(): void {
        $route = get_query_var( 'jetonomy_route' );
        if ( empty( $route ) ) {
            return;
        }

        // Set up template data
        $data = [
            'route'      => $route,
            'slug'       => get_query_var( 'jetonomy_slug', '' ),
            'space_slug' => get_query_var( 'jetonomy_space_slug', '' ),
            'tab'        => get_query_var( 'jetonomy_tab', '' ),
        ];

        // Let WordPress know we're handling this
        status_header( 200 );

        // Load the template
        Template_Loader::render( $data );
        exit;
    }

    private function get_base_slug(): string {
        $settings = get_option( 'jetonomy_settings', [] );
        return $settings['base_slug'] ?? $this->base_slug;
    }
}
