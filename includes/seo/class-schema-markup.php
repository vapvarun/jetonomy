<?php
namespace Jetonomy\SEO;

defined( 'ABSPATH' ) || exit;

class Schema_Markup {

	public function __construct() {
		add_action( 'wp_head', [ $this, 'output_schema' ] );
	}

	public function output_schema(): void {
		$route = get_query_var( 'jetonomy_route' );
		if ( empty( $route ) ) {
			return;
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
		}

		// Always add breadcrumb schema.
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

		// Q&A type gets QAPage schema (rich results in Google).
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
					'author'         => [ '@type' => 'Person', 'name' => $author_name ],
					'acceptedAnswer' => $accepted ? [
						'@type'       => 'Answer',
						'text'        => wp_strip_all_tags( $accepted->content ),
						'dateCreated' => $this->to_iso8601( $accepted->created_at ),
						'upvoteCount' => max( 0, (int) $accepted->vote_score ),
						'author'      => [ '@type' => 'Person', 'name' => $answer_author ? $answer_author->display_name : 'Anonymous' ],
						'url'         => $base . '#reply-' . $accepted->id,
					] : null,
				],
			];
		}

		// Forum post gets DiscussionForumPosting.
		return [
			'@context'             => 'https://schema.org',
			'@type'                => 'DiscussionForumPosting',
			'headline'             => $post->title,
			'text'                 => wp_strip_all_tags( mb_substr( $post->content, 0, 500 ) ),
			'url'                  => $base,
			'datePublished'        => $this->to_iso8601( $post->created_at ),
			'dateModified'         => $this->to_iso8601( $post->updated_at ?: $post->created_at ),
			'author'               => [ '@type' => 'Person', 'name' => $author_name ],
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

	private function get_space_schema(): ?array {
		$slug = get_query_var( 'jetonomy_slug' );
		if ( ! $slug ) {
			return null;
		}

		$space = \Jetonomy\Models\Space::find_by_slug( $slug );
		if ( ! $space ) {
			return null;
		}

		return [
			'@context'    => 'https://schema.org',
			'@type'       => 'DiscussionForumPosting',
			'name'        => $space->title,
			'description' => wp_strip_all_tags( $space->description ?? '' ),
			'url'         => \Jetonomy\base_url() . '/s/' . $space->slug . '/',
		];
	}

	private function get_home_schema(): array {
		return [
			'@context'    => 'https://schema.org',
			'@type'       => 'WebPage',
			'name'        => get_bloginfo( 'name' ) . ' Community',
			'description' => __( 'Community discussion forum', 'jetonomy' ),
			'url'         => \Jetonomy\base_url() . '/',
		];
	}

	private function get_breadcrumb_schema(): ?array {
		$route      = get_query_var( 'jetonomy_route' );
		$slug       = get_query_var( 'jetonomy_slug' );
		$space_slug = get_query_var( 'jetonomy_space_slug' );
		$base       = \Jetonomy\base_url() . '/';

		$items = [
			[ 'name' => __( 'Community', 'jetonomy' ), 'url' => $base ],
		];

		if ( 'space' === $route && $slug ) {
			$space = \Jetonomy\Models\Space::find_by_slug( $slug );
			if ( $space ) {
				$items[] = [ 'name' => $space->title, 'url' => $base . 's/' . $slug . '/' ];
			}
		}

		if ( 'post' === $route && $space_slug && $slug ) {
			$space = \Jetonomy\Models\Space::find_by_slug( $space_slug );
			$post  = \Jetonomy\Models\Post::find_by_slug( $slug );
			if ( $space ) {
				$items[] = [ 'name' => $space->title, 'url' => $base . 's/' . $space_slug . '/' ];
			}
			if ( $post ) {
				$items[] = [ 'name' => $post->title, 'url' => $base . 's/' . $space_slug . '/t/' . $slug . '/' ];
			}
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
		// Remove null values recursively.
		$data = $this->array_filter_recursive( $data );
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
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
