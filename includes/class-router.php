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
		add_filter( 'request', [ $this, 'suppress_default_query' ] );
		add_action( 'parse_query', [ $this, 'correct_query_state' ], 1 );
		add_action( 'template_redirect', [ $this, 'redirect_old_base_slug' ], 5 );
		add_action( 'template_redirect', [ $this, 'handle_request' ] );
		// WP's canonical redirect would append a trailing slash to the extension-
		// less-looking /…-sitemap.xml URL (301 -> …/) before handle_request emits.
		// Skip it for the sitemap route so the XML is served directly.
		add_filter( 'redirect_canonical', [ $this, 'skip_canonical_for_sitemap' ] );
	}

	/**
	 * Disable WordPress canonical redirects on the custom sitemap route.
	 *
	 * @param string|false $redirect_url The URL WP wants to redirect to.
	 * @return string|false
	 */
	public function skip_canonical_for_sitemap( $redirect_url ) {
		return 'sitemap' === get_query_var( 'jetonomy_route' ) ? false : $redirect_url;
	}

	/**
	 * Is the community rendered on top of the site's real front page?
	 *
	 * True only for the "Community as homepage" setting on the front-page
	 * request itself. In that case a real WP page object backs the URL, so
	 * WordPress — and any SEO plugin — already owns its title/canonical/OG,
	 * and Jetonomy defers (see Template_Loader::set_seo_meta()).
	 */
	public function is_mapped_front_page(): bool {
		$settings = get_option( 'jetonomy_settings', [] );
		return ! empty( $settings['front_page'] ) && is_front_page();
	}

	/**
	 * Stop WP_Query resolving a Jetonomy route to somebody else's content.
	 *
	 * Every Jetonomy URL is virtual: the rewrite sets `jetonomy_route`, which
	 * WP_Query does not recognise as a "which content" var. With nothing
	 * recognised, WP_Query falls back to `is_home = true`, and on a site with a
	 * static front page that resolves to whatever is set as Settings → Reading →
	 * "Posts page". Jetonomy then renders the right content at template_redirect
	 * but never corrects the query, so core's query state stays wrong for the
	 * whole request — and everything downstream reads that lie: core's
	 * wp_get_document_title(), themes, breadcrumbs, and SEO plugins.
	 *
	 * That is the root cause of Basecamp 10101954870: Yoast read `is_home` and
	 * published the Posts page's title AND a canonical pointing at it, on every
	 * Space and topic. Rank Math escaped only because it omits tags it cannot
	 * confidently build. The fix is not to argue with either plugin — it is to
	 * stop lying about the query. Doing so is vendor-neutral: it fixes Yoast,
	 * Rank Math, AIOSEO, SEOPress and core in one move, with no SEO-plugin code.
	 *
	 * Mirrors BuddyNext's PageRouter::suppress_default_query().
	 *
	 * @param array $query_vars Parsed request vars from WP::parse_request().
	 * @return array
	 */
	public function suppress_default_query( array $query_vars ): array {
		if ( empty( $query_vars['jetonomy_route'] ) ) {
			return $query_vars;
		}

		// Strip slug-based lookups: no backing post exists, so leaving these
		// lets WP_Query try (and fail) to resolve one.
		unset( $query_vars['pagename'], $query_vars['name'], $query_vars['page'] );

		// Return an empty result set — handle_request() renders the output.
		$query_vars['post__in'] = [ 0 ];

		return $query_vars;
	}

	/**
	 * Tell the main query what a Jetonomy route actually is: none of the things
	 * WordPress would otherwise guess.
	 *
	 * Deliberately clears rather than impersonates. Presenting a virtual route as
	 * singular (BuddyNext does this for its hubs, to make themes render a
	 * full-width layout) would need a stubbed WP_Post to keep body_class() and the
	 * theme's singular path from reading a null $post. Jetonomy renders its own
	 * full template and exits, so it needs no such stub — and claiming to be a
	 * page we are not is how this bug started.
	 *
	 * The front page is intentionally NOT handled here: when the community is
	 * mapped onto it, a real page backs the URL and WP's own resolution is
	 * correct. See is_mapped_front_page().
	 */
	public function correct_query_state( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->get( 'jetonomy_route' ) ) {
			return;
		}

		// is_front_page is NOT cleared here: WP_Query has no such property (it is a
		// method that derives from is_home + show_on_front), so is_home = false
		// above already makes is_front_page() return false. Assigning it would only
		// create a dynamic property nothing reads.
		$query->is_home           = false;
		$query->is_archive        = false;
		$query->is_singular       = false;
		$query->is_page           = false;
		$query->is_single         = false;
		$query->is_404            = false;
		$query->queried_object    = null;
		$query->queried_object_id = 0;
	}

	public function add_rewrite_rules(): void {
		$base = $this->get_base_slug();

		// Community home
		add_rewrite_rule( "^{$base}/?$", 'index.php?jetonomy_route=home', 'top' );

		// Custom XML sitemap (index + paginated children) — replaces the WP-core
		// providers so we can emit <priority>/<changefreq>. Handled by
		// Sitemap_Emitter, which echoes XML and exits (see handle_request).
		add_rewrite_rule( "^{$base}-sitemap\\.xml$", 'index.php?jetonomy_route=sitemap', 'top' );
		add_rewrite_rule( "^{$base}-sitemap-(spaces|posts)-([0-9]+)\\.xml$", 'index.php?jetonomy_route=sitemap&jetonomy_tab=$matches[1]&jetonomy_slug=$matches[2]', 'top' );

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
		add_rewrite_rule( "^{$base}/subscriptions/?$", 'index.php?jetonomy_route=subscriptions', 'top' );

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
		$route = (string) get_query_var( 'jetonomy_route' );

		// "Community as homepage": derive the route here rather than injecting a
		// fake query var during `request`. The old approach put jetonomy_route=home
		// into an otherwise-empty front-page query, which made the vars non-empty
		// and broke WP's own front-page resolution — is_front_page() went false and
		// the real page never became the queried object, so the owner's per-page SEO
		// (their Yoast title on that page) was unreachable. Deriving at
		// template_redirect leaves WP's resolution intact and still renders the
		// community through the exact same Template_Loader path as /{base}/.
		$mapped = false;
		if ( '' === $route && $this->is_mapped_front_page() ) {
			$route  = 'home';
			$mapped = true;
		}

		if ( empty( $route ) ) {
			return;
		}

		// Set up template data
		$data = [
			'route'      => $route,
			'slug'       => get_query_var( 'jetonomy_slug', '' ),
			'space_slug' => get_query_var( 'jetonomy_space_slug', '' ),
			'tab'        => get_query_var( 'jetonomy_tab', '' ),
			// A real page backs this URL — it, not Jetonomy, owns the SEO.
			'mapped'     => $mapped,
		];

		// A resolved Jetonomy route is a real page. WordPress may have flagged
		// the main query as 404 — e.g. `?paged=2` on a route whose underlying
		// main object is a single page — which makes the notifications / listing
		// "Load More" fetches 404 from page 2 on. Clear the inherited 404 and
		// assert a 200 before rendering; templates still call status_header( 404 )
		// themselves for genuinely missing content (unknown space / post).
		global $wp_query;
		if ( $wp_query instanceof \WP_Query && $wp_query->is_404() ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );

		// Space RSS feed renders XML and exits before any template work.
		if ( 'space-feed' === $route ) {
			Feed::render( (string) $data['slug'] );
		}

		// Custom XML sitemap renders XML and exits before any template work.
		// Empty tab = the sitemap index; tab+slug = a child page.
		if ( 'sitemap' === $route ) {
			SEO\Sitemap_Emitter::render( (string) $data['tab'], (int) $data['slug'] );
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
