<?php
/**
 * Schema.org markup output.
 *
 * @package Jetonomy
 */

namespace Jetonomy\SEO;

defined( 'ABSPATH' ) || exit;

class Schema_Markup {

	public function __construct() {
		add_action( 'wp_head', [ $this, 'output_schema' ] );
		add_filter( 'document_title_parts', [ $this, 'filter_title' ] );
	}

	/**
	 * Apply SEO title patterns from settings.
	 *
	 * Supports placeholders: {post_title}, {space_name}, {site_name}
	 */
	public function filter_title( array $title_parts ): array {
		$route = get_query_var( 'jetonomy_route' );
		if ( empty( $route ) ) {
			return $title_parts;
		}

		$settings  = get_option( 'jetonomy_settings', [] );
		$site_name = get_bloginfo( 'name' );

		if ( 'post' === $route && ! empty( $settings['seo_post_title'] ) ) {
			$slug  = get_query_var( 'jetonomy_slug' );
			$post  = \Jetonomy\Models\Post::find_by_slug( $slug );
			$space = $post ? \Jetonomy\Models\Space::find( (int) $post->space_id ) : null;
			if ( $post ) {
				$pattern              = $settings['seo_post_title'];
				$title_parts['title'] = str_replace(
					[ '{post_title}', '{space_name}', '{site_name}' ],
					[ $post->title, $space->title ?? '', $site_name ],
					$pattern
				);
			}
		}

		if ( 'space' === $route && ! empty( $settings['seo_space_title'] ) ) {
			$slug  = get_query_var( 'jetonomy_slug' );
			$space = \Jetonomy\Models\Space::find_by_slug( $slug );
			if ( $space ) {
				$pattern              = $settings['seo_space_title'];
				$title_parts['title'] = str_replace(
					[ '{space_name}', '{site_name}' ],
					[ $space->title, $site_name ],
					$pattern
				);
			}
		}

		return $title_parts;
	}

	public function output_schema(): void {
		$route = get_query_var( 'jetonomy_route' );
		if ( empty( $route ) ) {
			return;
		}

		$settings = get_option( 'jetonomy_settings', [] );

		// 1.4.0 D.4: schema emission defaults to ON. Pre-1.4.0 the option was
		// opt-in, which meant a fresh install shipped with no JSON-LD on any
		// route. Customers who want it OFF can set seo_schema=>false explicitly.
		$seo_schema_enabled = ! array_key_exists( 'seo_schema', $settings ) || ! empty( $settings['seo_schema'] );
		if ( ! $seo_schema_enabled ) {
			return;
		}

		// Robots noindex for profiles + search is now driven by the
		// route-aware emitter in Template_Loader::set_seo_meta() (Phase D),
		// which covers more surfaces. Schema_Markup keeps these legacy
		// emissions for the case where an admin has the seo-pro extension
		// disabled and Template_Loader's emit was suppressed by a customer
		// filter — they're idempotent (browsers de-dupe identical metas).
		if ( 'profile' === $route && ! empty( $settings['seo_noindex_profiles'] ) ) {
			echo '<meta name="robots" content="noindex, follow">' . "\n";
		}
		if ( 'search' === $route && ! empty( $settings['seo_noindex_search'] ) ) {
			echo '<meta name="robots" content="noindex, follow">' . "\n";
		}

		$schema = null;

		switch ( $route ) {
			case 'post':
				$schema = $this->get_post_schema();
				break;
			case 'space':
				$schema = $this->get_space_schema();
				break;
			case 'home':
				$schema = $this->get_home_schema();
				break;
			case 'profile':
				$schema = $this->get_profile_schema();
				break;
			case 'tag':
				$schema = $this->get_tag_schema();
				break;
		}

		$breadcrumb = $this->get_breadcrumb_schema();
		if ( $breadcrumb ) {
			$this->print_jsonld( $breadcrumb );
		}

		if ( $schema ) {
			$this->print_jsonld( $schema );
		}
	}

	private function get_post_schema(): ?array {
		$slug       = get_query_var( 'jetonomy_slug' );
		$space_slug = get_query_var( 'jetonomy_space_slug' );
		if ( ! $slug || ! $space_slug ) {
			return null;
		}

		$post = \Jetonomy\Models\Post::find_by_slug( $slug );
		if ( ! $post ) {
			return null;
		}

		$author      = get_userdata( (int) $post->author_id );
		$author_name = $author ? $author->display_name : 'Anonymous';
		$base        = \Jetonomy\base_url() . '/s/' . $space_slug . '/t/' . $slug . '/';

		if ( 'question' === $post->type && $post->accepted_reply_id ) {
			$accepted      = \Jetonomy\Models\Reply::find( (int) $post->accepted_reply_id );
			$answer_author = $accepted ? get_userdata( (int) $accepted->author_id ) : null;

			return [
				'@context'   => 'https://schema.org',
				'@type'      => 'QAPage',
				'mainEntity' => [
					'@type'          => 'Question',
					'name'           => $post->title,
					'text'           => wp_strip_all_tags( $post->content ),
					'dateCreated'    => $this->to_iso8601( $post->created_at ),
					'answerCount'    => (int) $post->reply_count,
					'upvoteCount'    => max( 0, (int) $post->vote_score ),
					'author'         => [
						'@type' => 'Person',
						'name'  => $author_name,
					],
					'acceptedAnswer' => $accepted ? [
						'@type'       => 'Answer',
						'text'        => wp_strip_all_tags( $accepted->content ),
						'dateCreated' => $this->to_iso8601( $accepted->created_at ),
						'upvoteCount' => max( 0, (int) $accepted->vote_score ),
						'author'      => [
							'@type' => 'Person',
							'name'  => $answer_author ? $answer_author->display_name : 'Anonymous',
						],
						'url'         => $base . '#reply-' . $accepted->id,
					] : null,
				],
			];
		}

		return [
			'@context'             => 'https://schema.org',
			'@type'                => 'DiscussionForumPosting',
			'headline'             => $post->title,
			'text'                 => wp_strip_all_tags( mb_substr( $post->content, 0, 500 ) ),
			'url'                  => $base,
			'datePublished'        => $this->to_iso8601( $post->created_at ),
			'dateModified'         => $this->to_iso8601( $post->updated_at ?: $post->created_at ),
			'author'               => [
				'@type' => 'Person',
				'name'  => $author_name,
			],
			'interactionStatistic' => [
				[
					'@type'                => 'InteractionCounter',
					'interactionType'      => 'https://schema.org/CommentAction',
					'userInteractionCount' => (int) $post->reply_count,
				],
				[
					'@type'                => 'InteractionCounter',
					'interactionType'      => 'https://schema.org/LikeAction',
					'userInteractionCount' => max( 0, (int) $post->vote_score ),
				],
			],
		];
	}

	/**
	 * Space → CollectionPage with mainEntity → ItemList of recent threads.
	 *
	 * 1.4.0 D.4: switched from DiscussionForumPosting (which is for a single
	 * thread, not a forum index) to CollectionPage so search engines model
	 * the page as a list of discussions, not as one giant post.
	 */
	private function get_space_schema(): ?array {
		$slug = get_query_var( 'jetonomy_slug' );
		if ( ! $slug ) {
			return null;
		}

		$space = \Jetonomy\Models\Space::find_by_slug( $slug );
		if ( ! $space ) {
			return null;
		}

		$base      = \Jetonomy\base_url();
		$space_url = $base . '/s/' . $space->slug . '/';

		// Top 10 recent posts in this space — gives the schema a real
		// mainEntity ItemList rather than an empty container. Stays well
		// inside the extreme-scale rule because of the LIMIT 10.
		$posts        = \Jetonomy\Models\Post::list_by_space( (int) $space->id, 'latest', 10 );
		$item_entries = array();
		foreach ( $posts as $i => $post ) {
			$item_entries[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'url'      => $base . '/s/' . $space->slug . '/t/' . $post->slug . '/',
				'name'     => $post->title,
			);
		}

		return array(
			'@context'    => 'https://schema.org',
			'@type'       => 'CollectionPage',
			'name'        => $space->title,
			'description' => wp_strip_all_tags( $space->description ?? '' ),
			'url'         => $space_url,
			'mainEntity'  => array(
				'@type'           => 'ItemList',
				'numberOfItems'   => (int) $space->post_count,
				'itemListElement' => $item_entries,
			),
		);
	}

	/**
	 * Home → WebSite + SearchAction (Sitelinks Searchbox eligibility).
	 *
	 * 1.4.0 D.4: added the `potentialAction` block pointing at
	 * /community/search/?q={search_term_string} so Google's Sitelinks
	 * Searchbox can surface our search directly in the SERP.
	 */
	private function get_home_schema(): array {
		$base      = \Jetonomy\base_url();
		$site_name = get_bloginfo( 'name' );

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'WebSite',
			'name'            => $site_name . ' Community',
			'description'     => __( 'Community discussion forum', 'jetonomy' ),
			'url'             => $base . '/',
			'inLanguage'      => str_replace( '_', '-', get_locale() ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => $base . '/search/?q={search_term_string}',
				),
				'query-input' => 'required name=search_term_string',
			),
		);
	}

	/**
	 * Profile → Person schema with avatar, bio, profile URL, social links.
	 *
	 * 1.4.0 D.4: lets search engines pull the right person card / image /
	 * description for the profile, and links out via `sameAs` so social
	 * presences resolve back to the community profile.
	 */
	private function get_profile_schema(): ?array {
		$login = get_query_var( 'jetonomy_slug' );
		if ( ! $login ) {
			return null;
		}

		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			return null;
		}

		$profile     = \Jetonomy\Models\UserProfile::find_by_user( (int) $user->ID );
		$bio         = $profile && ! empty( $profile->bio ) ? wp_strip_all_tags( (string) $profile->bio ) : '';
		$profile_url = \Jetonomy\base_url() . '/u/' . rawurlencode( $user->user_login ) . '/';
		$avatar_url  = (string) get_avatar_url( $user->ID, array( 'size' => 256 ) );

		$same_as = array();
		if ( $profile && ! empty( $profile->social_links ) ) {
			$social = is_array( $profile->social_links )
				? $profile->social_links
				: ( json_decode( (string) $profile->social_links, true ) ?: array() );
			foreach ( (array) $social as $link ) {
				$url = is_array( $link ) ? ( $link['url'] ?? '' ) : (string) $link;
				if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
					$same_as[] = $url;
				}
			}
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Person',
			'name'     => $user->display_name,
			'url'      => $profile_url,
			'image'    => $avatar_url,
		);
		if ( '' !== $bio ) {
			$schema['description'] = $bio;
		}
		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = array_values( array_unique( $same_as ) );
		}
		return $schema;
	}

	/**
	 * Tag → CollectionPage (mainEntity → ItemList of tagged threads).
	 *
	 * 1.4.0 D.4: same pattern as space schema — search engines see the tag
	 * page as a curated index, not a single document.
	 */
	private function get_tag_schema(): ?array {
		$slug = get_query_var( 'jetonomy_slug' );
		if ( ! $slug ) {
			return null;
		}

		$tag = \Jetonomy\Models\Tag::find_by_slug( $slug );
		if ( ! $tag ) {
			return null;
		}

		$base    = \Jetonomy\base_url();
		$tag_url = $base . '/tag/' . rawurlencode( $tag->slug ) . '/';

		return array(
			'@context'    => 'https://schema.org',
			'@type'       => 'CollectionPage',
			'name'        => '#' . $tag->name,
			'description' => sprintf(
				/* translators: 1: tag name, 2: site name */
				__( 'Discussions tagged %1$s on %2$s.', 'jetonomy' ),
				$tag->name,
				get_bloginfo( 'name' )
			),
			'url'         => $tag_url,
			'mainEntity'  => array(
				'@type'         => 'ItemList',
				'numberOfItems' => (int) $tag->post_count,
			),
		);
	}

	private function get_breadcrumb_schema(): ?array {
		$route      = get_query_var( 'jetonomy_route' );
		$slug       = get_query_var( 'jetonomy_slug' );
		$space_slug = get_query_var( 'jetonomy_space_slug' );
		$base       = \Jetonomy\base_url() . '/';

		$items = [
			[
				'name' => __( 'Community', 'jetonomy' ),
				'url'  => $base,
			],
		];

		if ( 'space' === $route && $slug ) {
			$space = \Jetonomy\Models\Space::find_by_slug( $slug );
			if ( $space ) {
				$items[] = [
					'name' => $space->title,
					'url'  => $base . 's/' . $slug . '/',
				];
			}
		}

		if ( 'post' === $route && $space_slug && $slug ) {
			$space = \Jetonomy\Models\Space::find_by_slug( $space_slug );
			$post  = \Jetonomy\Models\Post::find_by_slug( $slug );
			if ( $space ) {
				$items[] = [
					'name' => $space->title,
					'url'  => $base . 's/' . $space_slug . '/',
				];
			}
			if ( $post ) {
				$items[] = [
					'name' => $post->title,
					'url'  => $base . 's/' . $space_slug . '/t/' . $slug . '/',
				];
			}
		}

		// 1.4.0 D.4: extended breadcrumb coverage to profile / tag /
		// leaderboard / search / moderation. Each route produces a 2-item
		// chain (Community → page-name) so crawlers see proper hierarchy.
		if ( 'profile' === $route && $slug ) {
			$user = get_user_by( 'login', $slug );
			if ( $user ) {
				$items[] = array(
					'name' => '@' . $user->user_login,
					'url'  => $base . 'u/' . rawurlencode( $user->user_login ) . '/',
				);
			}
		}
		if ( 'tag' === $route && $slug ) {
			$tag = \Jetonomy\Models\Tag::find_by_slug( $slug );
			if ( $tag ) {
				$items[] = array(
					'name' => '#' . $tag->name,
					'url'  => $base . 'tag/' . rawurlencode( $tag->slug ) . '/',
				);
			}
		}
		if ( 'leaderboard' === $route ) {
			$items[] = array(
				'name' => __( 'Leaderboard', 'jetonomy' ),
				'url'  => $base . 'leaderboard/',
			);
		}
		if ( 'search' === $route ) {
			$items[] = array(
				'name' => __( 'Search', 'jetonomy' ),
				'url'  => $base . 'search/',
			);
		}
		if ( 'moderation' === $route ) {
			$items[] = array(
				'name' => __( 'Moderation', 'jetonomy' ),
				'url'  => $base . 'mod/',
			);
		}

		if ( count( $items ) <= 1 ) {
			return null;
		}

		$list = [];
		foreach ( $items as $i => $item ) {
			$list[] = [
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['name'],
				'item'     => $item['url'],
			];
		}

		return [
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list,
		];
	}

	private function print_jsonld( array $data ): void {
		$data = $this->array_filter_recursive( $data );
		wp_print_inline_script_tag(
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			[ 'type' => 'application/ld+json' ]
		);
	}

	private function to_iso8601( ?string $mysql_date ): string {
		if ( ! $mysql_date ) {
			return '';
		}
		return gmdate( 'c', strtotime( $mysql_date ) );
	}

	private function array_filter_recursive( array $array ): array {
		foreach ( $array as $key => &$value ) {
			if ( is_array( $value ) ) {
				$value = $this->array_filter_recursive( $value );
			}
			if ( null === $value ) {
				unset( $array[ $key ] );
			}
		}
		return $array;
	}
}
