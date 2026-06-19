<?php
/**
 * oEmbed REST controller.
 *
 * Provides oEmbed 1.0 JSON responses for forum thread URLs so that Slack,
 * Twitter/X, Discord, Facebook and other WordPress sites can unfurl links
 * to Jetonomy discussions. Because threads live in the custom `wp_jt_posts`
 * table (not a WordPress CPT), core WP oEmbed cannot resolve them —
 * `url_to_postid()` returns 0 for routed URLs. This controller fills that gap.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\UserProfile;

class OEmbed_Controller extends Base_Controller {

	protected $rest_base = 'oembed';

	/**
	 * Register the forum URL pattern as a known oEmbed provider.
	 *
	 * This is what lets other WordPress sites (and the block editor) auto-embed
	 * pasted Jetonomy thread URLs. Slack/Twitter/Discord don't use this — they
	 * read the `<link rel="alternate" type="application/json+oembed">` discovery
	 * tag emitted by Template_Loader::set_seo_meta on the thread page itself.
	 *
	 * Called from Api::register_routes on `rest_api_init`, but the provider
	 * registration is global so we also hook `init` directly.
	 */
	public static function register_provider(): void {
		$settings  = get_option( 'jetonomy_settings', array() );
		$base_slug = trim( (string) ( $settings['base_slug'] ?? 'community' ), '/' );
		$pattern   = home_url( '/' . $base_slug . '/s/*' );

		wp_oembed_add_provider( $pattern, rest_url( 'jetonomy/v1/oembed' ), false );
	}

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/oembed',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( \Jetonomy\Visibility::class, 'rest_check' ),
				'args'                => array(
					'url'       => array(
						'required'          => true,
						'type'              => 'string',
						'format'            => 'uri',
						'sanitize_callback' => 'esc_url_raw',
					),
					'format'    => array(
						'type'    => 'string',
						'default' => 'json',
						'enum'    => array( 'json' ),
					),
					'type'      => array(
						'type'    => 'string',
						'default' => 'rich',
						'enum'    => array( 'rich', 'link' ),
					),
					'maxwidth'  => array(
						'type'    => 'integer',
						'default' => 550,
					),
					'maxheight' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Build an oEmbed response for a Jetonomy thread URL.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$url    = (string) $request->get_param( 'url' );
		$parsed = self::parse_thread_url( $url );

		if ( ! $parsed ) {
			return new WP_Error(
				'jetonomy_oembed_not_found',
				__( 'Not a Jetonomy thread URL.', 'jetonomy' ),
				array( 'status' => 404 )
			);
		}

		$post = Post::find_by_slug( $parsed['post_slug'] );
		if ( ! $post || 'publish' !== $post->status ) {
			return new WP_Error(
				'jetonomy_oembed_not_found',
				__( 'Thread not found.', 'jetonomy' ),
				array( 'status' => 404 )
			);
		}

		$space = Space::find( (int) $post->space_id );
		if ( ! $space || $space->slug !== $parsed['space_slug'] ) {
			// Cross-space slug collision guard — the URL says one space, the post lives in another.
			return new WP_Error(
				'jetonomy_oembed_not_found',
				__( 'Thread not found.', 'jetonomy' ),
				array( 'status' => 404 )
			);
		}

		// Content gate — an oEmbed unfurl must not leak a thread whose parent
		// space the viewer cannot read (private/hidden unless member), nor an
		// is_private post. Canonical single-row check covers both axes; a
		// public-space thread still unfurls for anonymous consumers (Slack/X)
		// because can_read_post( 0, … ) passes for public spaces.
		if ( ! \Jetonomy\Permissions\Permission_Engine::can_read_post( get_current_user_id(), $post ) ) {
			return new WP_Error(
				'jetonomy_oembed_not_found',
				__( 'Thread not found.', 'jetonomy' ),
				array( 'status' => 404 )
			);
		}

		$author_id     = (int) $post->author_id;
		$author        = $author_id ? get_userdata( $author_id ) : null;
		$profile       = $author_id ? UserProfile::find_by_user( $author_id ) : null;
		$author_name   = ( $profile && ! empty( $profile->display_name ) )
			? $profile->display_name
			: ( $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ) );
		$author_url    = $author_id ? \Jetonomy\get_profile_url( $author_id ) : '';
		$author_avatar = $author_id ? get_avatar_url( $author_id, array( 'size' => 80 ) ) : '';

		$title   = wp_strip_all_tags( (string) $post->title );
		$excerpt = self::build_excerpt( $post );
		$thumb   = self::extract_first_image( (string) $post->content );

		$canonical = \Jetonomy\base_url() . '/s/' . $space->slug . '/t/' . $post->slug . '/';

		// Resolve response type: callers can pass `?type=link` to force the
		// simple link variant; default is `rich` so consumers that render HTML
		// get the polished card. Invalid values fall back to `rich`.
		$requested_type = strtolower( (string) $request->get_param( 'type' ) );
		$response_type  = in_array( $requested_type, array( 'link', 'rich' ), true )
			? $requested_type
			: 'rich';

		// Clamp width to maxwidth (spec); default to 550 which renders well
		// in most consumers (block editor, Slack unfurl card width).
		$maxwidth = (int) $request->get_param( 'maxwidth' );
		$width    = $maxwidth > 0 ? min( 550, $maxwidth ) : 550;
		$height   = 220;

		$data = array(
			'version'       => '1.0',
			'type'          => $response_type,
			'title'         => $title,
			'author_name'   => $author_name,
			'author_url'    => $author_url,
			'provider_name' => get_bloginfo( 'name' ),
			'provider_url'  => home_url( '/' ),
			'cache_age'     => 3600,
		);

		if ( $excerpt ) {
			$data['description'] = $excerpt;
		}

		if ( $thumb ) {
			$data['thumbnail_url']    = $thumb;
			$data['thumbnail_width']  = 600;
			$data['thumbnail_height'] = 315;
		}

		if ( 'rich' === $response_type ) {
			$data['width']  = $width;
			$data['height'] = $height;
			$data['html']   = self::build_rich_html(
				array(
					'url'           => $canonical,
					'title'         => $title,
					'excerpt'       => $excerpt,
					'author_name'   => $author_name,
					'author_url'    => $author_url,
					'author_avatar' => $author_avatar,
					'space_title'   => (string) ( $space->title ?? '' ),
					'space_url'     => \Jetonomy\base_url() . '/s/' . $space->slug . '/',
					'reply_count'   => (int) ( $post->reply_count ?? 0 ),
					'vote_score'    => (int) ( $post->vote_score ?? 0 ),
					'thumb'         => $thumb,
					'width'         => $width,
				)
			);
		}

		/**
		 * Filter the oEmbed response payload for a Jetonomy thread.
		 *
		 * @param array  $data  oEmbed response fields.
		 * @param object $post  Post row from wp_jt_posts.
		 * @param object $space Parent space row.
		 */
		$data = apply_filters( 'jetonomy_oembed_response', $data, $post, $space );

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}

	/**
	 * Build the HTML card returned in `type: rich` oEmbed responses.
	 *
	 * Self-contained (inline styles only — consumers iframe this). Safe to
	 * render inside the block editor's Embed block and in Slack unfurls that
	 * honour rich oEmbed HTML.
	 *
	 * @param array $parts Post presentation fields.
	 * @return string
	 */
	private static function build_rich_html( array $parts ): string {
		$url           = esc_url( $parts['url'] );
		$title         = esc_html( $parts['title'] );
		$excerpt       = esc_html( $parts['excerpt'] );
		$author_name   = esc_html( $parts['author_name'] );
		$author_url    = esc_url( $parts['author_url'] );
		$author_avatar = esc_url( $parts['author_avatar'] );
		$space_title   = esc_html( $parts['space_title'] );
		$space_url     = esc_url( $parts['space_url'] );
		$reply_count   = (int) $parts['reply_count'];
		$vote_score    = (int) $parts['vote_score'];
		$thumb         = $parts['thumb'] ? esc_url( $parts['thumb'] ) : '';
		$width         = (int) $parts['width'];

		// translators: %d: number of replies.
		$replies_label = sprintf( _n( '%d reply', '%d replies', $reply_count, 'jetonomy' ), $reply_count );
		// translators: %d: vote score.
		$votes_label = sprintf( _n( '%d vote', '%d votes', max( 0, $vote_score ), 'jetonomy' ), max( 0, $vote_score ) );

		$thumb_html = '';
		if ( $thumb ) {
			$thumb_html = '<img src="' . $thumb . '" alt="" style="width:100%;height:auto;max-height:180px;object-fit:cover;border-radius:8px 8px 0 0;display:block;">';
		}

		$avatar_html = '';
		if ( $author_avatar ) {
			$avatar_html = '<img src="' . $author_avatar . '" alt="" width="24" height="24" style="width:24px;height:24px;border-radius:50%;vertical-align:middle;margin-right:6px;">';
		}

		$html  = '<div class="jetonomy-oembed" style="max-width:' . $width . 'px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;background:#fff;">';
		$html .= $thumb_html;
		$html .= '<div style="padding:16px 18px;">';
		$html .= '<div style="font-size:12px;color:#6b7280;margin-bottom:6px;"><a href="' . $space_url . '" style="color:#6b7280;text-decoration:none;">' . $space_title . '</a></div>';
		$html .= '<a href="' . $url . '" style="color:#111827;text-decoration:none;"><div style="font-size:16px;font-weight:600;line-height:1.35;margin-bottom:8px;">' . $title . '</div></a>';
		if ( '' !== $excerpt ) {
			$html .= '<div style="font-size:14px;color:#4b5563;line-height:1.5;margin-bottom:12px;">' . $excerpt . '</div>';
		}
		$html .= '<div style="display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#6b7280;">';
		$html .= '<a href="' . $author_url . '" style="color:#6b7280;text-decoration:none;display:inline-flex;align-items:center;">' . $avatar_html . $author_name . '</a>';
		$html .= '<span>' . esc_html( $replies_label ) . ' · ' . esc_html( $votes_label ) . '</span>';
		$html .= '</div>';
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Parse a forum thread URL into space/post slugs.
	 *
	 * Matches the `/{base_slug}/s/{space}/t/{post}/` rewrite registered in
	 * includes/class-router.php. Rejects any URL that isn't on this site.
	 *
	 * @param string $url Absolute URL.
	 * @return array{space_slug:string,post_slug:string}|null
	 */
	public static function parse_thread_url( string $url ): ?array {
		$host     = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$url_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || ! $url_host || strtolower( $host ) !== strtolower( $url_host ) ) {
			return null;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return null;
		}

		$settings  = get_option( 'jetonomy_settings', array() );
		$base_slug = trim( (string) ( $settings['base_slug'] ?? 'community' ), '/' );
		$regex     = '#^/' . preg_quote( $base_slug, '#' ) . '/s/([^/]+)/t/([^/]+)/?$#';

		if ( ! preg_match( $regex, $path, $m ) ) {
			return null;
		}

		return array(
			'space_slug' => $m[1],
			'post_slug'  => $m[2],
		);
	}

	/**
	 * Build a 200-character plain-text excerpt from the post content.
	 *
	 * Prefers the denormalised `content_plain` column (FULLTEXT-indexed),
	 * falling back to stripping tags from the HTML `content` field.
	 *
	 * @param object $post Post row.
	 * @return string
	 */
	private static function build_excerpt( object $post ): string {
		$source = '';
		if ( ! empty( $post->content_plain ) ) {
			$source = (string) $post->content_plain;
		} elseif ( ! empty( $post->content ) ) {
			$source = wp_strip_all_tags( (string) $post->content );
		}

		$source = trim( preg_replace( '/\s+/', ' ', $source ) );
		if ( '' === $source ) {
			return '';
		}

		if ( mb_strlen( $source ) > 200 ) {
			$source = mb_substr( $source, 0, 197 ) . '…';
		}
		return $source;
	}

	/**
	 * Extract the first <img src="..."> from post content.
	 *
	 * @param string $content Raw HTML content.
	 * @return string|null Absolute URL, or null when no image is found.
	 */
	private static function extract_first_image( string $content ): ?string {
		if ( '' === $content ) {
			return null;
		}
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			$src = esc_url_raw( $m[1] );
			return $src ? $src : null;
		}
		return null;
	}
}
