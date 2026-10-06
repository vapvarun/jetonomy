<?php
/**
 * Search adapter that can answer the full community search.
 *
 * Search_Adapter::search() only carries a keyword, a type and a space, so the
 * REST route, the search page and the app could never be served by it: they
 * also filter by tag, author and date, sort, and need a total for paging. An
 * adapter that implements this interface takes over all of them. One that
 * implements only Search_Adapter keeps serving Abilities search, and
 * everything else stays on the built-in MySQL search rather than returning
 * results that ignore the filters.
 *
 * The built-in implementation is Jetonomy\Search\Fulltext_Search.
 *
 * @package Jetonomy
 * @since   2.0.1
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

interface Search_Query_Adapter extends Search_Adapter {

	/**
	 * Run one search.
	 *
	 * Rows must already exclude anything the current viewer may not read
	 * (private topics, private or hidden spaces they are not a member of,
	 * authors they blocked): callers page through them and show `total`.
	 *
	 * Keys of $args: type ('post'|'reply'|'space'|'tag'), q, space_id,
	 * date_from and date_to (Y-m-d), author_id, tag_slug, sort
	 * ('relevance'|'newest'|'votes'), limit, offset, with_total. An empty q
	 * with a space, tag or author is a listing of that scope, newest first.
	 *
	 * @param array $args Query arguments, see above.
	 * @return array{items: object[], total: int} Post rows carry the jt_posts
	 *         columns plus space_title and space_slug; reply rows the jt_replies
	 *         columns; space and tag rows their table columns. `total` is 0
	 *         when with_total is false.
	 */
	public function query( array $args ): array;
}
