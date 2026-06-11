<?php
/**
 * URL router.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Router {

	private string $base_slug = 'community';

	public function __construct() {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_filter( 'request', [ $this, 'maybe_serve_front_page' ] );
		add_action( 'template_redirect', [ $this, 'redirect_old_base_slug' ], 5 );
		add_action( 'template_redirect', [ $this, 'handle_request' ] );
	}

	/**
	 * Serve the community home on the site front page when the
	 * "Community as homepage" setting is enabled.
	 *
	 * Purely additive by design: it fires ONLY for the bare front-page
	 * request, where WP's parsed query vars are completely empty. Every
	 * other request — feeds (feed=...), pagination (paged=...), posts,
	 * pages, attachments, and all /{base}/* community routes — carries
	 * query vars and passes through untouched, so no existing route,
	 * rewrite rule, or permalink behaviour changes. With the injected
	 * route var, template_redirect renders the home view through the
	 * exact same Template_Loader path as /{base}/ itself.
	 *
	 * @param array $query_vars Parsed request vars from WP::parse_request().
	 * @return array Unchanged vars, or vars + jetonomy_route=home on the front page.
	 */
	public function maybe_serve_front_page( array $query_vars ): array {
		if ( ! empty( $query_vars ) ) {
			return $query_vars;
		}

		$settings = get_option( 'jetonomy_settings', [] );
		if ( empty( $settings['front_page'] ) ) {
			return $query_vars;
		}

		$query_vars['jetonomy_route'] = 'home';
		return $query_vars;
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

		// Space moderation queue (per-space, for space moderators and admins)
		add_rewrite_rule( "^{$base}/s/([^/]+)/mod/?$", 'index.php?jetonomy_route=space-moderation&jetonomy_slug=$matches[1]', 'top' );

		// New post in space
		add_rewrite_rule( "^{$base}/s/([^/]+)/new/?$", 'index.php?jetonomy_route=new-post&jetonomy_slug=$matches[1]', 'top' );

		// Single post
		add_rewrite_rule( "^{$base}/s/([^/]+)/t/([^/]+)/?$", 'index.php?jetonomy_route=post&jetonomy_space_slug=$matches[1]&jetonomy_slug=$matches[2]', 'top' );

		// User profile
		add_rewrite_rule( "^{$base}/u/([^/]+)/?$", 'index.php?jetonomy_route=profile&jetonomy_slug=$matches[1]', 'top' );

		// User profile edit
		add_rewrite_rule( "^{$base}/u/([^/]+)/edit/?$", 'index.php?jetonomy_route=edit-profile&jetonomy_slug=$matches[1]', 'top' );

		// User sub-pages
		add_rewrite_rule( "^{$base}/u/([^/]+)/(posts|badges|activity|bookmarks|replies|votes|drafts)/?$", 'index.php?jetonomy_route=profile&jetonomy_slug=$matches[1]&jetonomy_tab=$matches[2]', 'top' );

		// Notifications
		add_rewrite_rule( "^{$base}/notifications/?$", 'index.php?jetonomy_route=notifications', 'top' );

		// Search
		add_rewrite_rule( "^{$base}/search/?$", 'index.php?jetonomy_route=search', 'top' );

		// Leaderboard
		add_rewrite_rule( "^{$base}/leaderboard/?$", 'index.php?jetonomy_route=leaderboard', 'top' );

		// Moderation
		add_rewrite_rule( "^{$base}/mod/?$", 'index.php?jetonomy_route=moderation', 'top' );

		// My spaces (G7) — landing for "Spaces I run" + "Spaces I'm in"
		add_rewrite_rule( "^{$base}/my-spaces/?$", 'index.php?jetonomy_route=my-spaces', 'top' );

		// My drafts (1.4.1 A9) — top-level standalone view, current user's drafts.
		// Distinct from /u/:slug/drafts/ on the profile page; this URL is the
		// canonical "go to my drafts" entry point that can be linked from header
		// menus, emails, etc., without needing to know the user's login slug.
		add_rewrite_rule( "^{$base}/drafts/?$", 'index.php?jetonomy_route=drafts', 'top' );

		// My bookmarks (1.4.1 A9) — top-level standalone view, current user's
		// bookmarks. Same rationale as /drafts/ above — canonical login-agnostic
		// entry point for the current user's bookmark list.
		add_rewrite_rule( "^{$base}/bookmarks/?$", 'index.php?jetonomy_route=bookmarks', 'top' );

		// Front-end create space (G6) — gated by admin toggle + trust level
		add_rewrite_rule( "^{$base}/new-space/?$", 'index.php?jetonomy_route=new-space', 'top' );

		// Front-end edit space (G5) — /community/s/:slug/edit/
		add_rewrite_rule( "^{$base}/s/([^/]+)/edit/?$", 'index.php?jetonomy_route=edit-space&jetonomy_slug=$matches[1]', 'top' );

		// Space RSS feed (1.5.0) — /community/s/:slug/feed/
		add_rewrite_rule( "^{$base}/s/([^/]+)/feed/?$", 'index.php?jetonomy_route=space-feed&jetonomy_slug=$matches[1]', 'top' );

		// Tags
		add_rewrite_rule( "^{$base}/tag/([^/]+)/?$", 'index.php?jetonomy_route=tag&jetonomy_slug=$matches[1]', 'top' );

		// Invite link
		add_rewrite_rule( "^{$base}/invite/([a-zA-Z0-9]+)/?$", 'index.php?jetonomy_route=invite&jetonomy_slug=$matches[1]', 'top' );

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

	/**
	 * 301-redirect requests from the old base slug to the current one.
	 *
	 * When an admin changes the base slug (e.g. "community" → "forum"),
	 * the old URL is stored in the `jetonomy_old_base_slug` option.
	 * This handler performs a permanent redirect so search engines and
	 * bookmarks update.
	 */
	public function redirect_old_base_slug(): void {
		$old_slug = get_option( 'jetonomy_old_base_slug', '' );
		if ( empty( $old_slug ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] ?? '' );
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH ) ?? '';

		// Match /old-slug or /old-slug/anything.
		if ( strpos( $path, '/' . $old_slug . '/' ) === 0 || $path === '/' . $old_slug ) {
			$new_slug = $this->get_base_slug();
			$new_uri  = str_replace( '/' . $old_slug, '/' . $new_slug, $request_uri );
			wp_safe_redirect( home_url( $new_uri ), 301 );
			exit;
		}
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

		// Space RSS feed renders XML and exits before any template work.
		if ( 'space-feed' === $route ) {
			Feed::render( (string) $data['slug'] );
		}

		// Load the template (template may call status_header(404) inside)
		Template_Loader::render( $data );
		exit;
	}

	private function get_base_slug(): string {
		$settings = get_option( 'jetonomy_settings', [] );
		return $settings['base_slug'] ?? $this->base_slug;
	}
}
