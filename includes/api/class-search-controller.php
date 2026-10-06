<?php
/**
 * Search REST API controller.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use function Jetonomy\table;

class Search_Controller extends Base_Controller {

	protected $rest_base = 'search';

	/**
	 * Register REST routes for search.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/search',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search' ],
				'permission_callback' => [ \Jetonomy\Visibility::class, 'rest_check' ],
				'args'                => [
					'q'         => [
						// Optional: a tag/space/author scope is a valid listing on its
						// own (e.g. a tag page). The 2-char minimum is enforced in the
						// handler only when there is no scope to narrow by.
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'type'      => [
						'type'    => 'string',
						'default' => 'post',
						'enum'    => [ 'post', 'reply', 'space', 'tag', 'all' ],
					],
					'space_id'  => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'date_from' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_to'   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'author_id' => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'author'    => [
						'type'              => 'string',
						'description'       => 'Author name or username; resolved to author_id server-side (headless parity with the search UI).',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'tag'       => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'sort'      => [
						'type'    => 'string',
						'default' => 'relevance',
						'enum'    => [ 'relevance', 'newest', 'votes' ],
					],
					'limit'     => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'description' => 'Page size (1-50, default 20). No default is declared here so an unsent value can fall back to the per_page alias.',
					],
					'offset'    => [
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => 'Row offset for pagination.',
					],
					'per_page'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'description' => 'Alias for limit — kept for existing web callers (assets/js/header.js) that already send per_page.',
					],
				],
			]
		);
	}

	/**
	 * GET /jetonomy/v1/search — Full-text search across posts, replies, or spaces.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$q         = trim( (string) $request->get_param( 'q' ) );
		$type      = $request->get_param( 'type' ) ?? 'post';
		$space_id  = $request->get_param( 'space_id' ) ? absint( $request->get_param( 'space_id' ) ) : null;
		$date_from = $request->get_param( 'date_from' ) ? sanitize_text_field( $request->get_param( 'date_from' ) ) : null;
		$date_to   = $request->get_param( 'date_to' ) ? sanitize_text_field( $request->get_param( 'date_to' ) ) : null;
		$author_id = $request->get_param( 'author_id' ) ? absint( $request->get_param( 'author_id' ) ) : null;
		// Headless parity with the search UI: accept an author name/username and
		// resolve it to an id (exact login, then display-name/nicename match).
		// `author_id` wins if both are sent.
		$author_name = $request->get_param( 'author' ) ? sanitize_text_field( $request->get_param( 'author' ) ) : '';
		if ( ! $author_id && '' !== $author_name ) {
			$author_user = get_user_by( 'login', $author_name );
			if ( ! $author_user ) {
				$author_matches = get_users(
					array(
						'search'         => '*' . $author_name . '*',
						'search_columns' => array( 'display_name', 'user_login', 'user_nicename' ),
						'number'         => 1,
						'fields'         => array( 'ID' ),
					)
				);
				$author_user    = $author_matches ? $author_matches[0] : null;
			}
			if ( $author_user ) {
				$author_id = (int) $author_user->ID;
			}
		}
		$tag_slug = $request->get_param( 'tag' ) ? sanitize_text_field( $request->get_param( 'tag' ) ) : null;
		$sort     = $request->get_param( 'sort' ) ?? 'relevance';

		// A tag / space / author scope is a valid listing on its own (tag pages,
		// "posts in this space", "posts by this author"). Only require the 2-char
		// free-text minimum when there is no scope to narrow by; a too-short query
		// alongside a scope is treated as "no query" (scope-only listing) rather
		// than an error.
		$has_scope = $tag_slug || $space_id || $author_id;
		if ( strlen( $q ) < 2 ) {
			if ( ! $has_scope ) {
				return $this->validation_error( __( 'Search query must be at least 2 characters.', 'jetonomy' ) );
			}
			$q = '';
		}

		// Pagination — mirrors Feed_Controller::list_items() (get_pagination() +
		// clamp to 1..50). `per_page` is accepted as an alias for `limit`: the
		// header search overlay (assets/js/header.js) already sends per_page,
		// and the composer typeahead (assets/js/composer.js) already sends
		// limit — both were previously silently dropped by the route schema.
		//
		// Big-site note: limit is capped at 50; a deep OFFSET on a FULLTEXT+JOIN
		// query is O(offset), which is fine at ~2000 rows. A keyset cursor would
		// only work for sort=newest (relevance has no monotonic key to page on),
		// so we deliberately do not half-build one here.
		$pagination = $this->get_pagination( $request );
		$limit_raw  = $request->get_param( 'limit' );
		if ( null === $limit_raw || '' === $limit_raw ) {
			$per_page = $request->get_param( 'per_page' );
			if ( null !== $per_page && '' !== $per_page ) {
				$limit_raw = $per_page;
			}
		}
		$limit  = max( 1, min( 50, (int) ( $limit_raw ?? $pagination['limit'] ) ) );
		$offset = max( 0, (int) $pagination['offset'] );

		// One search behind every entry point: the built-in MySQL search, or an
		// adapter a plugin registered (Basecamp 10368526736).
		$adapter = \Jetonomy\Adapters\Adapter_Registry::get_search_query();
		$scope   = compact( 'q', 'space_id', 'date_from', 'date_to', 'author_id', 'tag_slug', 'sort' );

		// Combined "all" mode returns posts, spaces, and tags grouped. Limit/offset
		// apply to the posts group only — spaces/tags are a header slice (a fixed
		// preview list), not a paginated collection in this mode.
		if ( 'all' === $type || empty( $type ) ) {
			$found_posts  = $adapter->query(
				array_merge(
					$scope,
					[
						'type'   => 'post',
						'limit'  => $limit,
						'offset' => $offset,
					]
				)
			);
			$found_spaces = $adapter->query(
				[
					'type'  => 'space',
					'q'     => $q,
					'limit' => 20,
				]
			);
			$found_tags   = $adapter->query(
				[
					'type'  => 'tag',
					'q'     => $q,
					'limit' => 10,
				]
			);
			$posts        = $found_posts['items'];
			$posts_total  = $found_posts['total'];
			$spaces       = $found_spaces['items'];
			$spaces_total = $found_spaces['total'];
			$tags         = $found_tags['items'];
			$tags_total   = $found_tags['total'];

			$response = new WP_REST_Response(
				[
					'data' => [
						'posts'  => array_map(
							function ( $row ) {
								$item         = $this->prepare_post( $row );
								$item['type'] = 'post';
								return $item; },
							$this->enrich_viewer_state( $this->enrich_with_author( $posts ) )
						),
						// Shared serializer, not `(array) $row` — see
						// Base_Controller::prepare_space(). Casting the raw row here
						// shipped string-typed numerics and internal columns to
						// clients (Basecamp 10161324553).
						'spaces' => array_map(
							function ( $row ) {
								$item         = $this->prepare_space( $row );
								$item['type'] = 'space';
								return $item; },
							$spaces
						),
						'tags'   => array_map(
							function ( $row ) {
								return (array) $row; },
							$tags
						),
					],
					'meta' => [
						// Back-compat: `total` previously meant "rows returned across
						// all three groups combined." It now means the posts group's
						// real total (matching every other search mode + the new
						// X-WP-Total/X-WP-TotalPages headers). `totals` carries the
						// real per-group counts so callers than need the old combined
						// number can add them up themselves.
						'total'    => $posts_total,
						'totals'   => [
							'posts'  => $posts_total,
							'spaces' => $spaces_total,
							'tags'   => $tags_total,
						],
						'offset'   => $offset,
						'has_more' => ( $offset + count( $posts ) ) < $posts_total,
					],
				],
				200
			);

			$response->header( 'X-WP-Total', (string) $posts_total );
			$response->header( 'X-WP-TotalPages', (string) (int) ceil( $posts_total / max( 1, $limit ) ) );

			return $response;
		}

		$results = [];
		$total   = 0;

		if ( in_array( $type, [ 'post', 'reply', 'space', 'tag' ], true ) ) {
			$found   = $adapter->query(
				array_merge(
					$scope,
					[
						'type'   => $type,
						'limit'  => $limit,
						'offset' => $offset,
					]
				)
			);
			$results = $found['items'];
			$total   = $found['total'];
		}

		// Batch-load author data before serializing so prepare_post()/prepare_reply()
		// read pre-enriched fields instead of falling back to a per-row lookup.
		if ( 'post' === $type || 'reply' === $type ) {
			$results = $this->enrich_with_author( $results );
		}
		// Viewer state (bookmark/vote) batched too — without this prepare_post()
		// fell through to 2 point queries PER ROW (plan WP3.4). Post rows only;
		// the base method's type-gate additionally refuses reply/space rows.
		if ( 'post' === $type ) {
			$results = $this->enrich_viewer_state( $results );
		}

		$items = array_map(
			function ( $row ) use ( $type ) {
				// Every row type goes through the SAME serializer the dedicated
				// endpoints use, so an object cannot have one shape from /search and
				// a different one from /feed or /spaces/{id}/posts. Casting the raw
				// DB row here shipped string-typed numerics, no _gmt twins and no
				// author enrichment (Basecamp 10161324553).
				switch ( $type ) {
					case 'space':
						$item = $this->prepare_space( $row );
						break;
					case 'post':
						$item = $this->prepare_post( $row );
						break;
					default:
						// Replies and tags: replies carry joined post/space columns
						// the reply serializer does not model, and tags are a flat
						// {id,name,slug,post_count} row with no serializer of their
						// own. Cast, but normalize the numerics so a typed client
						// never receives "12" where it expects 12.
						$item = $this->normalize_row_numerics( (array) $row );
						break;
				}
				$item['type'] = $type;
				return $item;
			},
			$results
		);

		// paginated_response() computes has_more from offset + count(items) vs
		// total (base-controller.php), which is correct on every page.
		$response = $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $offset,
			]
		);

		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $limit ) ) );

		return $response;
	}
}
