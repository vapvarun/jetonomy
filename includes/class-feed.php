<?php
/**
 * Space RSS feeds.
 *
 * Serves /{base}/s/{slug}/feed/ as an RSS 2.0 document of the space's
 * latest published posts. Feeds are consumed by readers that cannot
 * authenticate, so a feed renders only when a logged-out visitor could
 * read the space: the community must be in public mode AND the space
 * visibility must grant anonymous read. Private/hidden spaces 404 —
 * never leak titles through a feed URL.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Permissions\Permission_Engine;

class Feed {

	/**
	 * Number of items in a feed. Bounded — feeds never paginate.
	 */
	private const ITEMS = 20;

	/**
	 * Render the RSS document for a space and exit.
	 *
	 * @param string $space_slug Space slug from the rewrite rule.
	 */
	public static function render( string $space_slug ): void {
		$space = Space::find_by_slug( $space_slug );

		// Gate on what an ANONYMOUS visitor may read, regardless of who is
		// asking — feed URLs get shared and cached by readers/aggregators,
		// so a member's cookie must never widen what the URL exposes.
		if ( ! $space || 'public' !== Visibility::get_mode() || ! Permission_Engine::can( 0, 'read', (int) $space->id ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		// viewer_id = 0 (guest) always — RSS is logged-out/cacheable, so this
		// deliberately never applies a per-viewer block filter (Post::exclusion_sql()
		// already no-ops for guests); a per-viewer feed would poison page caches.
		$posts = Post::list_by_space_visible( (int) $space->id, 0, false, 'latest', self::ITEMS, 0 );

		/**
		 * Filters the posts included in a space RSS feed.
		 *
		 * @since 1.5.0
		 * @param array  $posts Post rows (newest first, max 20).
		 * @param object $space Space row.
		 */
		$posts = (array) apply_filters( 'jetonomy_space_feed_posts', $posts, $space );

		$space_url = trailingslashit( base_url() ) . 's/' . rawurlencode( (string) $space->slug ) . '/';
		$self_url  = $space_url . 'feed/';
		$newest    = ! empty( $posts ) ? (string) $posts[0]->created_at : (string) ( $space->created_at ?? now() );

		status_header( 200 );
		header( 'Content-Type: application/rss+xml; charset=' . esc_attr( get_option( 'blog_charset' ) ) );
		header( 'Last-Modified: ' . mysql2date( 'D, d M Y H:i:s', $newest, false ) . ' GMT' );

		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		?>
<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
	<title><?php echo esc_html( wp_specialchars_decode( (string) $space->title, ENT_QUOTES ) . ' – ' . get_bloginfo( 'name' ) ); ?></title>
	<link><?php echo esc_url( $space_url ); ?></link>
	<atom:link href="<?php echo esc_url( $self_url ); ?>" rel="self" type="application/rss+xml" />
	<description><?php echo esc_html( wp_strip_all_tags( (string) ( $space->description ?? '' ) ) ); ?></description>
	<language><?php echo esc_html( get_bloginfo( 'language' ) ); ?></language>
	<lastBuildDate><?php echo esc_html( mysql2date( 'r', $newest, false ) ); ?></lastBuildDate>
	<generator>Jetonomy <?php echo esc_html( JETONOMY_VERSION ); ?></generator>
		<?php foreach ( $posts as $post ) : ?>
			<?php
			$post_url = $space_url . 't/' . rawurlencode( (string) $post->slug ) . '/';
			$display  = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
			$excerpt  = wp_trim_words( (string) ( $post->content_plain ?? wp_strip_all_tags( (string) $post->content ) ), 55 );
			?>
	<item>
		<title><?php echo esc_html( wp_specialchars_decode( (string) $post->title, ENT_QUOTES ) ); ?></title>
		<link><?php echo esc_url( $post_url ); ?></link>
		<guid isPermaLink="true"><?php echo esc_url( $post_url ); ?></guid>
		<pubDate><?php echo esc_html( mysql2date( 'r', (string) $post->created_at, false ) ); ?></pubDate>
		<dc:creator><?php echo esc_html( '' !== $display['name'] ? $display['name'] : __( 'Member', 'jetonomy' ) ); ?></dc:creator>
		<description><?php echo esc_html( $excerpt ); ?></description>
	</item>
<?php endforeach; ?>
</channel>
</rss>
		<?php
		exit;
	}

	/**
	 * Print the feed discovery <link> for space pages.
	 *
	 * Hooked from Template_Loader head output when the current route is a
	 * public space — readers and browsers auto-detect the feed from it.
	 *
	 * @param object $space Space row.
	 */
	public static function discovery_link( object $space ): void {
		if ( 'public' !== Visibility::get_mode() || ! Permission_Engine::can( 0, 'read', (int) $space->id ) ) {
			return;
		}
		printf(
			'<link rel="alternate" type="application/rss+xml" title="%s" href="%s" />' . "\n",
			esc_attr( wp_specialchars_decode( (string) $space->title, ENT_QUOTES ) . ' – RSS' ),
			esc_url( trailingslashit( base_url() ) . 's/' . rawurlencode( (string) $space->slug ) . '/feed/' )
		);
	}
}
