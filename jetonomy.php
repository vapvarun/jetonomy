<?php
/**
 * Plugin Name: Jetonomy
 * Plugin URI:  https://store.wbcomdesigns.com/jetonomy/
 * Description: Next-gen discussion platform for WordPress — forums, Q&A, and more.
 * Version:     1.3.6
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

define( 'JETONOMY_VERSION', '1.3.6' );
define( 'JETONOMY_DB_VERSION', '1.2.5' );
define( 'JETONOMY_FILE', __FILE__ );
define( 'JETONOMY_DIR', plugin_dir_path( __FILE__ ) );
define( 'JETONOMY_URL', plugin_dir_url( __FILE__ ) );

require_once JETONOMY_DIR . 'includes/class-autoloader.php';
Jetonomy\Autoloader::register();

// functions.php defines Jetonomy\table(), Jetonomy\now(), Jetonomy\base_url() — plain
// functions that the autoloader can't pick up. Must load BEFORE class-jetonomy so
// Migrator runs (fired on plugins_loaded -> init) can call these helpers.
require_once JETONOMY_DIR . 'includes/functions.php';

require_once JETONOMY_DIR . 'includes/class-jetonomy.php';

function jetonomy(): Jetonomy\Jetonomy {
	return Jetonomy\Jetonomy::instance();
}

jetonomy();

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

if ( file_exists( JETONOMY_DIR . 'vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php' ) ) {
	require_once JETONOMY_DIR . 'vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
}

// Auto-activate the preset license key on first load so downloads work.
add_action(
	'admin_init',
	function () {
		$preset_key = 'wbcomfreec7e2a9b45d8f1c3e6a0b9d2f7c4e8a11';
		$option     = 'jetonomy_license_key';
		$activated  = 'jetonomy_preset_activated';

		// Already activated for this domain — skip.
		if ( get_option( $activated ) ) {
			return;
		}

		// Store the key so the SDK can find it.
		update_option( $option, $preset_key, false );

		// Activate with the EDD store.
		$response = wp_remote_post(
			'https://wbcomdesigns.com',
			array(
				'timeout' => 15,
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
				// Auto-enable usage tracking checkbox.
				update_option(
					$option . '_allow_tracking',
					array(
						'allowed'   => true,
						'timestamp' => time(),
					),
					false
				);
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
