<?php
/**
 * Plugin Name: Jetonomy
 * Plugin URI:  https://store.wbcomdesigns.com/jetonomy/
 * Description: Next-gen discussion platform for WordPress - forums, Q&A, and more.
 * Version:     1.6.1
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author:      Wbcom Designs
 * Author URI:  https://wbcomdesigns.com/
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jetonomy
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'JETONOMY_VERSION', '1.6.1' );
define( 'JETONOMY_DB_VERSION', '1.6.1' );
define( 'JETONOMY_FILE', __FILE__ );
define( 'JETONOMY_DIR', plugin_dir_path( __FILE__ ) );
define( 'JETONOMY_URL', plugin_dir_url( __FILE__ ) );

// Action Scheduler — bundled in free so Pro extensions (email digest, badges,
// AI, reply-by-email, web-push, webhooks) and any future cron-heavy work get
// reliable background processing instead of WP-Cron's "next page view" model.
// AS self-resolves to the highest registered version when multiple plugins ship it.
// JETONOMY_SMOKE_TEST guard: tools/smoke-test.php boots with a minimal WP stub
// that doesn't carry AS's required functions/constants (EP_NONE, register_post_type
// with full args, etc.). Real WordPress always has these, so this is smoke-only.
if ( ! defined( 'JETONOMY_SMOKE_TEST' ) ) {
	require_once JETONOMY_DIR . 'libs/action-scheduler/action-scheduler.php';
}

require_once JETONOMY_DIR . 'includes/class-autoloader.php';
Jetonomy\Autoloader::register();

// functions.php defines Jetonomy\table(), Jetonomy\now(), Jetonomy\base_url() — plain
// functions that the autoloader can't pick up. Must load BEFORE class-jetonomy so
// Migrator runs (fired on plugins_loaded -> init) can call these helpers.
require_once JETONOMY_DIR . 'includes/functions.php';

// Public global helpers for templates (jetonomy_post_title_or_excerpt etc.).
// Kept separate from functions.php because functions.php is namespaced
// (Jetonomy\*) while helpers.php exposes global functions that templates and
// child themes can call without namespace imports.
require_once JETONOMY_DIR . 'includes/helpers.php';

require_once JETONOMY_DIR . 'includes/class-jetonomy.php';

function jetonomy(): Jetonomy\Jetonomy {
	return Jetonomy\Jetonomy::instance();
}

jetonomy();

// Multisite-aware activation helpers -- declared in the Jetonomy namespace.
require_once JETONOMY_DIR . 'includes/functions-multisite.php';

// EDD Software Licensing SDK — free plugin auto-updates with preset key.
add_action(
	'edd_sl_sdk_registry',
	function ( $registry ) {
		$registry->register(
			array(
				'id'      => 'jetonomy',
				'url'     => 'https://wbcomdesigns.com',
				'item_id' => 1660320,
				'version' => JETONOMY_VERSION,
				'file'    => JETONOMY_FILE,
				'license' => 'wbcomfreec7e2a9b45d8f1c3e6a0b9d2f7c4e8a11',
			)
		);
	}
);

// SDK lives at libs/ (committed, ships in zip). Pro reads it via the same path.
if ( file_exists( JETONOMY_DIR . 'libs/edd-sl-sdk/edd-sl-sdk.php' ) ) {
	require_once JETONOMY_DIR . 'libs/edd-sl-sdk/edd-sl-sdk.php';
}

// Auto-activate the preset license key on first load so downloads work.
add_action(
	'admin_init',
	function () {
		// Owner opt-out: define JETONOMY_NO_LICENSE_PHONE_HOME to disable the
		// store call entirely (air-gapped / privacy-strict installs).
		if ( defined( 'JETONOMY_NO_LICENSE_PHONE_HOME' ) && JETONOMY_NO_LICENSE_PHONE_HOME ) {
			return;
		}

		$preset_key = 'wbcomfreec7e2a9b45d8f1c3e6a0b9d2f7c4e8a11';
		$option     = 'jetonomy_license_key';
		$activated  = 'jetonomy_preset_activated';

		// Already activated for this domain — skip.
		if ( get_option( $activated ) ) {
			return;
		}

		// Back off after any attempt so a server that can't reach the store does
		// NOT repeat a blocking request on every admin pageview (the previous
		// behaviour: 15s hang per page whenever egress failed). At most one
		// attempt per 12h until activation succeeds.
		if ( get_transient( 'jetonomy_preset_activation_backoff' ) ) {
			return;
		}
		set_transient( 'jetonomy_preset_activation_backoff', 1, 12 * HOUR_IN_SECONDS );

		// Store the key so the SDK can find it.
		update_option( $option, $preset_key, false );

		// Activate with the EDD store. Short timeout so a slow/unreachable store
		// can't stall the admin for 15 seconds.
		$response = wp_remote_post(
			'https://wbcomdesigns.com',
			array(
				'timeout' => 5,
				'body'    => array(
					'edd_action' => 'activate_license',
					'license'    => $preset_key,
					'item_id'    => 1660320,
					'url'        => home_url(),
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 'valid' === ( $body['license'] ?? '' ) ) {
				update_option( $activated, 1, false );
				// NOTE: usage tracking is NOT auto-enabled here. Enrolling a site
				// in tracking without explicit consent is a privacy overreach;
				// the owner opts in via the settings toggle instead.
			}
		}
	}
);

/**
 * Render an SVG icon from assets/icons/.
 *
 * @param string $name Icon slug (filename without .svg).
 * @param int    $size Width/height in px (default 24).
 * @return string Sanitized SVG markup.
 */
function jetonomy_icon( string $name, int $size = 24 ): string {
	static $cache = array();
	if ( isset( $cache[ $name ] ) ) {
		$svg = $cache[ $name ];
	} else {
		$file = JETONOMY_DIR . 'assets/icons/' . sanitize_file_name( $name ) . '.svg';
		if ( ! file_exists( $file ) ) {
			return '';
		}
		$svg            = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cache[ $name ] = $svg;
	}
	$svg = str_replace( '<svg ', '<svg width="' . $size . '" height="' . $size . '" ', $svg );
	return $svg;
}

/**
 * Echo an SVG icon.
 *
 * @param string $name Icon slug.
 * @param int    $size Width/height in px.
 */
function jetonomy_echo_icon( string $name, int $size = 24 ): void {
	echo jetonomy_icon( $name, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from trusted local file.
}

/**
 * Render a space icon (Lucide-only contract, 1.4.0).
 *
 * The plugin contract is "Lucide icons only — no emoji in shipped UI."
 * Existing Space rows store whatever the admin (or the demo seeder)
 * pasted in the icon field, including unicode emoji. This helper:
 *
 *   - empty / null  → renders the default `users` icon
 *   - matches one of the SVGs in `assets/icons/`  → renders that Lucide icon
 *   - starts with `dashicons-`  → renders the dashicon (legacy compat)
 *   - anything else (emoji, free-text, unknown name)  → renders the
 *     default `users` icon, NEVER the raw value
 *
 * Output is always wrapped in a span carrying the supplied class so
 * existing CSS selectors keep working without changes.
 *
 * @param mixed  $icon       The space's `icon` column value.
 * @param int    $size       Pixel size.
 * @param string $class_name Wrapper class name.
 */
function jetonomy_render_space_icon( $icon, int $size = 24, string $class_name = 'jt-space-card-icon', string $type_fallback = '' ): void {
	$icon = is_string( $icon ) ? trim( $icon ) : '';

	if ( '' !== $icon && 0 === strpos( $icon, 'dashicons-' ) ) {
		echo '<span class="' . esc_attr( $class_name ) . ' dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		return;
	}

	$lucide = '';
	if ( '' !== $icon && preg_match( '/^[a-z0-9][a-z0-9-]{0,40}$/', $icon ) ) {
		$svg_path = JETONOMY_DIR . 'assets/icons/' . $icon . '.svg';
		if ( file_exists( $svg_path ) ) {
			$lucide = $icon;
		}
	}

	// When the stored icon is missing / emoji / unknown, pick a default
	// based on space type so customers don't see the same icon on every
	// card. The defaults are picked for breadth — a cluster of icon-less
	// spaces reads visually varied without admin work.
	//
	// 1.4.1: qa default flipped from `help-circle` to `book-open`.
	// help-circle reads as a literal question-mark glyph and looked
	// repetitive on customer sites that ran several support-style Q&A
	// spaces side by side. `book-open` carries the same "answers /
	// knowledge" intent without the on-the-nose question-mark visual.
	if ( '' === $lucide ) {
		$type_defaults = array(
			'qa'    => 'book-open',
			'ideas' => 'lightbulb',
			'feed'  => 'hash',
			'forum' => 'message-circle',
		);
		$lucide        = $type_defaults[ $type_fallback ] ?? 'users';
	}

	echo '<span class="' . esc_attr( $class_name ) . '" aria-hidden="true">';
	echo jetonomy_icon( $lucide, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted local SVG.
	echo '</span>';
}

/**
 * Map legacy emoji-byte strings stored in `wp_jt_pro_badges.icon` to the
 * Lucide slug they should render as today.
 *
 * Used by the badge icon helper (fallback resolution) AND by the one-shot
 * upgrade migration that normalises saved rows. Returning null means the
 * input wasn't a known legacy emoji — caller decides the default.
 *
 * @param string $icon Raw stored value (may be UTF-8 emoji, a Lucide slug, or empty).
 * @return string|null Lucide slug to render, or null when the input isn't a recognised legacy emoji.
 */
function jetonomy_map_legacy_badge_emoji( string $icon ): ?string {
	$map = array(
		"\xE2\x9C\x8D\xEF\xB8\x8F"     => 'edit',
		"\xF0\x9F\x93\x9D"             => 'edit',
		"\xF0\x9F\x92\xAC"             => 'message-circle',
		"\xF0\x9F\x8F\x86"             => 'award',
		"\xE2\x9C\x85"                 => 'check-circle',
		"\xE2\xAD\x90"                 => 'star',
		"\xF0\x9F\x8C\x9F"             => 'star',
		"\xF0\x9F\x8E\x96\xEF\xB8\x8F" => 'award',
		"\xF0\x9F\x96\x90\xEF\xB8\x8F" => 'hand',
		"\xF0\x9F\x9A\xA9"             => 'flag',
	);
	return $map[ $icon ] ?? null;
}

/**
 * Render a badge icon — Lucide-only, with graceful fallback.
 *
 * Mirrors {@see jetonomy_render_space_icon()} but tuned for the small badge
 * display sizes used in the admin table and the my-badges profile card.
 * Resolution order: stored Lucide slug → emoji-byte mapping → 'award' default.
 *
 * @param mixed  $icon       The badge's `icon` column value.
 * @param int    $size       Pixel size for the SVG.
 * @param string $class_name Wrapper class name.
 */
function jetonomy_render_badge_icon( $icon, int $size = 24, string $class_name = 'jt-badge-icon' ): void {
	$icon = is_string( $icon ) ? trim( $icon ) : '';

	$lucide = '';
	if ( '' !== $icon && preg_match( '/^[a-z0-9][a-z0-9-]{0,40}$/', $icon ) ) {
		$svg_path = JETONOMY_DIR . 'assets/icons/' . $icon . '.svg';
		if ( file_exists( $svg_path ) ) {
			$lucide = $icon;
		}
	}

	if ( '' === $lucide && '' !== $icon ) {
		$mapped = jetonomy_map_legacy_badge_emoji( $icon );
		if ( null !== $mapped ) {
			$svg_path = JETONOMY_DIR . 'assets/icons/' . $mapped . '.svg';
			if ( file_exists( $svg_path ) ) {
				$lucide = $mapped;
			}
		}
	}

	if ( '' === $lucide ) {
		$lucide = 'award';
	}

	echo '<span class="' . esc_attr( $class_name ) . '" aria-hidden="true">';
	echo jetonomy_icon( $lucide, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted local SVG.
	echo '</span>';
}

/**
 * Format post/reply content with @mention and #hashtag auto-linking.
 *
 * Expects already-sanitized HTML (via wp_kses_post). Applies regex only
 * to text segments outside HTML tags to avoid mangling existing markup.
 * Individual replacement values are escaped with esc_html/esc_url.
 *
 * @param string $content Sanitized HTML content string.
 * @return string Processed content with mention and tag links.
 */
/**
 * Resolve whether voting is enabled on a given space.
 *
 * Mirrors the server-side gate in `Permission_Engine::can()` (the
 * 'vote' branch). The admin checkbox stores `'1'` for enabled; an
 * unset key defaults to allowed so spaces seeded before this feature
 * existed don't suddenly lose voting. Used at every frontend render
 * site that draws vote controls so the UI never shows an affordance
 * the server will refuse.
 *
 * @param object|null $space Space object (or null when no space context).
 * @return bool True when voting is allowed on the space.
 */
function jetonomy_space_allows_voting( $space ): bool {
	if ( ! $space || empty( $space->id ) ) {
		return true;
	}
	$settings = \Jetonomy\Models\Space::get_settings( (int) $space->id );
	if ( ! isset( $settings['allow_voting'] ) ) {
		return true;
	}
	return '1' === (string) $settings['allow_voting'];
}

/**
 * Resolve a customer-facing label for an idea roadmap status enum value.
 *
 * Mirrors the column labels in `templates/views/space-roadmap.php`. Used
 * on idea cards in space listings and on the single-post page when the
 * post belongs to a `type=ideas` space. Unknown values fall back to
 * "Submitted" so a stale enum never renders an empty pill.
 *
 * @param string $status One of Post::valid_idea_statuses(), or empty.
 * @return string Translated label.
 */
function jetonomy_idea_status_label( string $status ): string {
	$labels = array(
		'planned'     => __( 'Planned', 'jetonomy' ),
		'in_progress' => __( 'In Progress', 'jetonomy' ),
		'shipped'     => __( 'Shipped', 'jetonomy' ),
		'declined'    => __( 'Declined', 'jetonomy' ),
	);
	return $labels[ $status ] ?? '';
}

/**
 * Render an idea-status pill for a post on a `type=ideas` space.
 *
 * Echoes a small <span class="jt-idea-pill jt-idea-pill-{status}"> so
 * the listing card and the post header both look the same. Status colors
 * come from CSS so themes can override them without inline-style fights.
 *
 * @param string $status One of Post::valid_idea_statuses(), or empty.
 */
function jetonomy_render_idea_status_pill( string $status ): void {
	if ( '' === $status || ! in_array( $status, \Jetonomy\Models\Post::valid_idea_statuses(), true ) ) {
		return;
	}
	echo '<span class="jt-idea-pill jt-idea-pill-' . esc_attr( $status ) . '">'
		. esc_html( jetonomy_idea_status_label( $status ) )
		. '</span>';
}

/**
 * Customer-facing label for a space `type` enum value.
 *
 * Mirrors the schema enum in `class-schema.php` (forum | qa | ideas | feed).
 * Unknown values fall back to "Forum" so the badge never renders empty —
 * matches the schema DEFAULT.
 *
 * @param string $type Space type enum value.
 * @return string Translated label.
 */
function jetonomy_space_type_label( string $type ): string {
	switch ( $type ) {
		case 'qa':
			return _x( 'Q&A', 'space type label', 'jetonomy' );
		case 'ideas':
			return _x( 'Ideas', 'space type label', 'jetonomy' );
		case 'feed':
			return _x( 'Feed', 'space type label', 'jetonomy' );
		case 'forum':
		default:
			return _x( 'Forum', 'space type label', 'jetonomy' );
	}
}

/**
 * Lucide icon name to pair with a space `type` badge.
 *
 * Kept in one place so the directory cards, the space header, and any
 * future admin chip all show the same glyph for a given type.
 */
function jetonomy_space_type_icon( string $type ): string {
	switch ( $type ) {
		case 'qa':
			return 'book-open';
		case 'ideas':
			return 'lightbulb';
		case 'feed':
			return 'rss';
		case 'forum':
		default:
			return 'message-circle';
	}
}

/**
 * Customer-facing label for a space `join_policy` enum value.
 *
 * Mirrors the schema enum in `class-schema.php` (open | approval | invite).
 * "Open" returns an empty string by design — the default policy is the
 * implicit zero state and we don't want every card to carry a noisy
 * "Open" pill.
 */
function jetonomy_space_join_policy_label( string $join_policy ): string {
	switch ( $join_policy ) {
		case 'approval':
			return __( 'Approval required', 'jetonomy' );
		case 'invite':
			return __( 'Invite only', 'jetonomy' );
		case 'open':
		default:
			return '';
	}
}

/**
 * Render the type + join-policy badges on a space directory card.
 *
 * Pairs with the existing "Hidden" badge in `home.php` and `category.php`
 * so all three pieces of space metadata sit in the same horizontal strip.
 *
 *   - Type badge always renders. Default `forum` is shown explicitly so
 *     mixed-type listings stay visually consistent (every card carries
 *     exactly one type chip; readers don't have to infer the absence as
 *     "this one is the default").
 *   - Join-policy badge only renders for `approval` / `invite` (the
 *     default `open` is left implicit per the label helper above).
 *
 * Echoes nothing when `$space` is missing the relevant fields, so the
 * helper is safe to drop into any template that already received a
 * Space model row.
 *
 * @param object|null $space Space model row (or null/missing fields).
 */
function jetonomy_render_space_meta_badges( $space ): void {
	if ( ! is_object( $space ) ) {
		return;
	}

	$type        = isset( $space->type ) ? (string) $space->type : '';
	$join_policy = isset( $space->join_policy ) ? (string) $space->join_policy : '';

	if ( '' !== $type ) {
		$icon  = jetonomy_space_type_icon( $type );
		$label = jetonomy_space_type_label( $type );
		echo '<span class="jt-space-card-badge jt-space-card-badge-type jt-space-card-badge-type-' . esc_attr( $type ) . '">';
		jetonomy_echo_icon( $icon, 12 );
		echo esc_html( $label );
		echo '</span>';
	}

	$policy_label = jetonomy_space_join_policy_label( $join_policy );
	if ( '' !== $policy_label ) {
		$icon = 'invite' === $join_policy ? 'key' : 'user-check';
		echo '<span class="jt-space-card-badge jt-space-card-badge-policy jt-space-card-badge-policy-' . esc_attr( $join_policy ) . '" aria-label="' . esc_attr( $policy_label ) . '">';
		jetonomy_echo_icon( $icon, 12 );
		echo esc_html( $policy_label );
		echo '</span>';
	}
}

/**
 * wp_kses variant for rendered post/reply content.
 *
 * Extends the default "post" allowed-tags list with the attributes that
 * oEmbed HTML needs to survive the outer sanitization pass in single-post.php
 * and reply-card.php:
 *
 * - `<iframe>` for YouTube/Vimeo/Spotify/SoundCloud embeds.
 * - `<blockquote class=... data-*>` + inner `<section>` / `<p>` / `<a target>`
 *   for TikTok, Instagram, and Twitter embeds. These providers ship a
 *   blockquote fallback plus a hydration script (see
 *   jetonomy_maybe_enqueue_embed_scripts()) that swaps the blockquote for
 *   the real player at runtime. If we strip the blockquote's `class`
 *   (`tiktok-embed` / `instagram-media` / `twitter-tweet`) or its
 *   `data-video-id` / `data-instgrm-permalink` attributes, the script has no
 *   hook to attach to and the visitor only sees the plain caption fallback.
 *
 * @param string $content HTML content to sanitize.
 * @return string
 */
function jetonomy_kses_embedded_content( string $content ): string {
	$allowed = wp_kses_allowed_html( 'post' );

	$allowed['iframe'] = array(
		'src'             => true,
		'width'           => true,
		'height'          => true,
		'frameborder'     => true,
		'allow'           => true,
		'allowfullscreen' => true,
		'referrerpolicy'  => true,
		'loading'         => true,
		'title'           => true,
		'name'            => true,
		'class'           => true,
		'id'              => true,
		'style'           => true,
	);

	// Provider embed blockquote — TikTok, Instagram, Twitter. Keep the
	// class hook and the data-* attributes the hydration script reads.
	$allowed['blockquote'] = array_merge(
		$allowed['blockquote'] ?? array(),
		array(
			'class'                  => true,
			'cite'                   => true,
			'style'                  => true,
			'data-video-id'          => true,
			'data-embed-from'        => true,
			'data-instgrm-permalink' => true,
			'data-instgrm-version'   => true,
			'data-instgrm-captioned' => true,
			'data-lang'              => true,
			'data-theme'             => true,
		)
	);

	// TikTok wraps the fallback caption in <section>; also allow the
	// attributes Instagram/Twitter put on inner links.
	$allowed['section'] = array(
		'class' => true,
		'style' => true,
	);

	foreach ( array( 'a', 'p' ) as $tag ) {
		if ( isset( $allowed[ $tag ] ) && is_array( $allowed[ $tag ] ) ) {
			$allowed[ $tag ]['target'] = true;
			$allowed[ $tag ]['rel']    = true;
		}
	}

	return wp_kses( $content, $allowed );
}

/**
 * Enqueue provider hydration scripts when content contains a TikTok,
 * Instagram, or Twitter embed blockquote.
 *
 * These providers return oEmbed HTML as `<blockquote class="..." …>` plus a
 * `<script async src="…">` tag. `wp_filter_oembed_result()` strips the
 * script (only iframes survive core's oEmbed sanitizer), so the blockquote
 * renders as a plain caption without the script loading — visitors see the
 * fallback text but no video player.
 *
 * We detect the blockquote marker in the sanitized, ready-to-echo content
 * and enqueue the corresponding provider script server-side. The script
 * runs once per page and rewrites every matching blockquote to an iframe
 * player.
 *
 * Safe to call multiple times per request — wp_enqueue_script() dedupes by
 * handle. Does nothing on feed / REST / admin contexts.
 *
 * @param string $content Rendered content about to be echoed.
 */
function jetonomy_maybe_enqueue_embed_scripts( string $content ): void {
	if ( is_admin() || is_feed() || wp_doing_ajax() ) {
		return;
	}

	if ( false !== strpos( $content, 'class="tiktok-embed"' ) || false !== strpos( $content, "class='tiktok-embed'" ) ) {
		wp_enqueue_script( 'jetonomy-tiktok-embed', 'https://www.tiktok.com/embed.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	if ( false !== strpos( $content, 'class="instagram-media"' ) || false !== strpos( $content, "class='instagram-media'" ) ) {
		wp_enqueue_script( 'jetonomy-instagram-embed', 'https://www.instagram.com/embed.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	if ( false !== strpos( $content, 'class="twitter-tweet"' ) || false !== strpos( $content, "class='twitter-tweet'" ) ) {
		wp_enqueue_script( 'jetonomy-twitter-embed', 'https://platform.twitter.com/widgets.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}
}

function jetonomy_format_content( string $content ): string {
	$base = \Jetonomy\base_url();

	// Normalize paragraphs: wpautop converts \n\n to <p>…</p> for plain-text
	// storage, and is a no-op when content is already block-wrapped. This is
	// the single display-side paragraph layer (mirrors core's `the_content`).
	$content = wpautop( $content );

	// Split content into HTML tags and text segments, process only text segments.
	$parts = preg_split( '/(<[^>]*>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $parts ) {
		return $content;
	}

	$inside_a = 0;
	foreach ( $parts as $i => $part ) {
		// Track whether we're inside an <a> tag to avoid nesting links.
		if ( preg_match( '/<a[\s>]/i', $part ) ) {
			++$inside_a;
			continue;
		}
		if ( preg_match( '/<\/a>/i', $part ) ) {
			--$inside_a;
			continue;
		}
		// Skip HTML tags and text inside anchor tags.
		if ( isset( $part[0] ) && '<' === $part[0] ) {
			continue;
		}
		if ( $inside_a > 0 ) {
			continue;
		}

		// @mentions → profile links. Negative lookbehind prevents matching
		// inside URL paths like `tiktok.com/@username/video/...` — `/` or
		// word/email characters immediately before `@` block the match.
		$part = preg_replace_callback(
			'/(?<![\w\/.:-])@([a-zA-Z0-9_-]+)/u',
			function ( $matches ) use ( $base ) {
				$username = $matches[1];
				$url      = $base . '/u/' . rawurlencode( $username ) . '/';
				return '<a href="' . esc_url( $url ) . '" class="jt-mention">@' . esc_html( $username ) . '</a>';
			},
			$part
		);

		// #hashtags → tag page links. Same lookbehind so URL fragments
		// (`foo.com#section`) don't get linkified as tags.
		$part = preg_replace_callback(
			'/(?<![\w\/.:-])#([a-zA-Z0-9_-]+)/u',
			function ( $matches ) use ( $base ) {
				$tag  = $matches[1];
				$slug = sanitize_title( $tag );
				$url  = $base . '/tag/' . $slug . '/';
				return '<a href="' . esc_url( $url ) . '" class="jt-tag-link">#' . esc_html( $tag ) . '</a>';
			},
			$part
		);

		$parts[ $i ] = $part;
	}

	return implode( '', $parts );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once JETONOMY_DIR . 'includes/class-cli.php';
	\WP_CLI::add_command( 'jetonomy', 'Jetonomy\\CLI' );
	\Jetonomy\CLI\CLI_Dispatcher::register();
}
