<?php
/**
 * Search view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
if ( ! in_array( $filter, array( 'all', 'posts', 'spaces', 'tags' ), true ) ) {
	$filter = 'all';
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page     = max( 1, absint( wp_unslash( $_GET['pg'] ?? 1 ) ) );
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$jt_author_unresolved = false;
$author_id            = isset( $_GET['author_id'] ) ? absint( $_GET['author_id'] ) : 0;
// Author filter is by NAME for humans (a member can't know a numeric user ID).
// Resolve a typed name to an author_id: exact login first, then a display-name
// match. `author_id` (programmatic / REST) still works and takes precedence.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$author_name = isset( $_GET['author'] ) ? sanitize_text_field( wp_unslash( $_GET['author'] ) ) : '';
if ( ! $author_id && '' !== $author_name ) {
	$jt_author_user = get_user_by( 'login', $author_name );
	if ( ! $jt_author_user ) {
		$jt_author_matches = get_users(
			array(
				'search'         => '*' . $author_name . '*',
				'search_columns' => array( 'display_name', 'user_login', 'user_nicename' ),
				'number'         => 1,
				'fields'         => array( 'ID', 'display_name' ),
			)
		);
		$jt_author_user    = $jt_author_matches ? $jt_author_matches[0] : null;
	}
	if ( $jt_author_user ) {
		$author_id   = (int) $jt_author_user->ID;
		$author_name = \Jetonomy\user_display_name( $jt_author_user );
	} else {
		// A name nobody answers to. Without this the filter silently drops -
		// $author_id stays 0, so no author clause is added and the search
		// returns every visible topic, which reads as "here is everything this
		// person wrote". Say no results instead, which is the truth.
		$jt_author_unresolved = true;
	}
} elseif ( $author_id && '' === $author_name ) {
	// author_id came from the URL — show the name in the input.
	$jt_author_user = get_userdata( $author_id );
	$author_name    = $jt_author_user ? \Jetonomy\user_display_name( $jt_author_user ) : '';
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tag_slug = isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'relevance';
if ( ! in_array( $sort, array( 'relevance', 'newest', 'votes' ), true ) ) {
	$sort = 'relevance';
}

$posts  = array();
$spaces = array();
$tags   = array();

// Has the viewer asked for ANYTHING - a keyword, or a filter on its own?
//
// Filters are reachable before a search runs, and a filter-only URL
// (?author_name=aisha, the kind you bookmark or share) renders results rather than the
// empty state. The REST route answers an author-only search - it returns results for
// author_id with no q - so the web UI does the same (the three-entry-points rule).
$jt_has_filters = ( $date_from || $date_to || $author_id || '' !== $author_name || $tag_slug || 'relevance' !== $sort );
$jt_has_query   = ( '' !== $q && strlen( $q ) >= 2 );
$jt_searching   = ( $jt_has_query || $jt_has_filters );

$jt_posts_total  = 0;
$jt_spaces_total = 0;
$jt_tags_total   = 0;
if ( $jt_searching && ! $jt_author_unresolved ) {
	// Same search, same rules as REST /search and the app: one adapter call per
	// group instead of this view's own SQL. Post rows
	// already carry space_title / space_slug.
	$search_adapter = \Jetonomy\Adapters\Adapter_Registry::get_search_query();

	if ( in_array( $filter, array( 'all', 'posts' ), true ) ) {
		$jt_found       = $search_adapter->query(
			array(
				'type'      => 'post',
				'q'         => $jt_has_query ? $q : '',
				'date_from' => $date_from ?: null,
				'date_to'   => $date_to ?: null,
				'author_id' => $author_id ?: null,
				'tag_slug'  => $tag_slug ?: null,
				'sort'      => $sort,
				'limit'     => $per_page,
				'offset'    => $offset,
			)
		);
		$posts          = $jt_found['items'];
		$jt_posts_total = $jt_found['total'];
	}

	// Spaces and tags are keyword searches - the advanced filters (author, date,
	// tag) describe topics, not either of these. Without this guard a
	// filter-only request searched them for '', which matched every tag on the
	// site, so "this author's topics" came back decorated with arbitrary tags,
	// and a keyword plus a filter listed spaces and tags the filter never applied to.
	$jt_side_search = $jt_has_query && ! ( $date_from || $date_to || $author_id || $tag_slug );
	if ( $jt_side_search && in_array( $filter, array( 'all', 'spaces' ), true ) ) {
		$jt_found_spaces = $search_adapter->query(
			array(
				'type'  => 'space',
				'q'     => $q,
				'limit' => 10,
			)
		);
		$spaces          = $jt_found_spaces['items'];
		$jt_spaces_total = $jt_found_spaces['total'];
	}

	if ( $jt_side_search && in_array( $filter, array( 'all', 'tags' ), true ) ) {
		$jt_found_tags = $search_adapter->query(
			array(
				'type'  => 'tag',
				'q'     => $q,
				'limit' => 15,
			)
		);
		$tags          = $jt_found_tags['items'];
		$jt_tags_total = $jt_found_tags['total'];
	}
}

$total = count( $posts ) + count( $spaces ) + count( $tags );
// The header counts every match, not the rows on this page (posts are paged).
$jt_result_total = $jt_posts_total + $jt_spaces_total + $jt_tags_total;

// Words of the query, folded the way the table collation compares them
// (case- and accent-insensitive), for marking matches in the results.
$jt_hl_terms = array();
if ( $jt_has_query ) {
	foreach ( (array) preg_split( '/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY ) as $jt_term ) {
		$jt_hl_terms[] = mb_strtolower( remove_accents( (string) $jt_term ) );
	}
}

/**
 * Escape a result string and wrap each query word it contains in <mark>.
 *
 * Every segment is escaped, so <mark> is the only markup added. Matches start
 * at a word start (the search is a prefix match) and ignore case and accents,
 * so "cafe" marks "Café". The text is folded one character at a time with a
 * map back to the original, so the mark lands on the characters as written.
 *
 * @param string $text Plain text as stored.
 * @return string Safe HTML.
 */
$jt_highlight = static function ( string $text ) use ( $jt_hl_terms ): string {
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$esc  = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	if ( ! $jt_hl_terms || '' === $text ) {
		return $esc( $text );
	}

	$chars  = mb_str_split( $text );
	$folded = array();
	$owner  = array();
	foreach ( $chars as $i => $char ) {
		$fold = strlen( $char ) > 1 ? mb_strtolower( remove_accents( $char ) ) : strtolower( $char );
		foreach ( mb_str_split( $fold ) as $fc ) {
			$folded[] = $fc;
			$owner[]  = $i;
		}
	}

	$hay  = implode( '', $folded );
	$mark = array();
	foreach ( $jt_hl_terms as $term ) {
		$len = mb_strlen( $term );
		$at  = 0;
		while ( false !== ( $pos = mb_strpos( $hay, $term, $at ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( 0 === $pos || ! preg_match( '/[\p{L}\p{N}]/u', $folded[ $pos - 1 ] ) ) {
				for ( $k = $owner[ $pos ]; $k <= $owner[ $pos + $len - 1 ]; $k++ ) {
					$mark[ $k ] = true;
				}
			}
			$at = $pos + 1;
		}
	}

	$out = '';
	$run = '';
	$in  = false;
	foreach ( $chars as $i => $char ) {
		if ( isset( $mark[ $i ] ) !== $in ) {
			$out .= $in ? '<mark class="jt-hl">' . $esc( $run ) . '</mark>' : $esc( $run );
			$run  = '';
			$in   = ! $in;
		}
		$run .= $char;
	}
	return $out . ( $in ? '<mark class="jt-hl">' . $esc( $run ) . '</mark>' : $esc( $run ) );
};

$crumbs = array(
	array(
		'label' => __( 'Search', 'jetonomy' ),
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::breadcrumb( $crumbs ); ?>

<div class="jt-two-col">
		<main>
			<?php \Jetonomy\Template_Loader::breadcrumb_in_main(); ?>
			<!-- Search form -->
			<form method="get" action="<?php echo esc_url( \Jetonomy\route_url( 'search' ) ); ?>" class="jt-search-page-form" autocomplete="off">
				<div class="jt-search-page-input">
					<span class="jt-search-page-icon" aria-hidden="true"><?php jetonomy_echo_icon( 'search', 20 ); ?></span>
					<input type="text" name="q" dir="auto"
						value="<?php echo esc_attr( $q ); ?>"
						<?php /* translators: %s: the plural space label the site owner configured (e.g. spaces, groups). */ ?>
						placeholder="<?php echo esc_attr( sprintf( __( 'Search discussions, %s, tags…', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?>"
						autofocus>
				</div>
				<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
			</form>

			<?php
			/*
			 * Filters render whether or not a search has run.
			 *
			 * They live outside the results branch so a filter-only search (every post by one
			 * author) is reachable from the UI, as it is from the REST route. The accordion stays
			 * collapsed until used, so the empty page is not busier.
			 */
			?>
				<!-- Advanced filters -->
				<details class="jt-search-filters jt-mb-20" 
				<?php
				if ( $date_from || $date_to || $author_id || '' !== $author_name || $tag_slug || 'relevance' !== $sort ) :
					?>
					open<?php endif; ?>>
					<summary class="jt-search-filters-toggle"><?php esc_html_e( 'Filters', 'jetonomy' ); ?> <?php jetonomy_echo_icon( 'chevron-down', 12 ); ?></summary>
					<form method="get" action="<?php echo esc_url( \Jetonomy\route_url( 'search' ) ); ?>" class="jt-search-filters-form">
						<input type="hidden" name="q" value="<?php echo esc_attr( $q ); ?>">
						<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
						<div class="jt-filter-row">
							<label><?php esc_html_e( 'Date from', 'jetonomy' ); ?>
								<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" class="jt-input jt-input-sm">
							</label>
							<label><?php esc_html_e( 'Date to', 'jetonomy' ); ?>
								<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" class="jt-input jt-input-sm">
							</label>
							<label><?php esc_html_e( 'Tag', 'jetonomy' ); ?>
								<input type="text" name="tag" value="<?php echo esc_attr( $tag_slug ); ?>" placeholder="<?php esc_attr_e( 'e.g. javascript', 'jetonomy' ); ?>" class="jt-input jt-input-sm">
							</label>
							<label><?php esc_html_e( 'Author', 'jetonomy' ); ?>
								<input type="text" name="author" value="<?php echo esc_attr( $author_name ); ?>" placeholder="<?php esc_attr_e( 'Name or username', 'jetonomy' ); ?>" class="jt-input jt-input-sm" autocomplete="off">
							</label>
							<label><?php esc_html_e( 'Sort', 'jetonomy' ); ?>
								<select name="sort" class="jt-input jt-input-sm">
									<option value="relevance" <?php selected( $sort, 'relevance' ); ?>><?php esc_html_e( 'Relevance', 'jetonomy' ); ?></option>
									<option value="newest" <?php selected( $sort, 'newest' ); ?>><?php esc_html_e( 'Newest', 'jetonomy' ); ?></option>
									<option value="votes" <?php selected( $sort, 'votes' ); ?>><?php esc_html_e( 'Most voted', 'jetonomy' ); ?></option>
								</select>
							</label>
						</div>
						<div class="jt-filter-actions">
							<button type="submit" class="jt-btn jt-btn-fill jt-btn-sm"><?php esc_html_e( 'Apply', 'jetonomy' ); ?></button>
							<a href="<?php echo esc_url( add_query_arg( 'q', $q, \Jetonomy\route_url( 'search' ) ) ); ?>" class="jt-btn jt-btn-ghost jt-btn-sm"><?php esc_html_e( 'Clear', 'jetonomy' ); ?></a>
						</div>
					</form>
				</details>
			<?php if ( $jt_searching ) : ?>
				<!-- Filter pills -->
				<div class="jt-bar jt-mb-20">
					<div class="jt-pills">
						<?php
						$filters = array(
							'all'    => __( 'All', 'jetonomy' ),
							'posts'  => \Jetonomy\jetonomy_label( 'topic', true ),
							'spaces' => \Jetonomy\space_label( true ),
							'tags'   => __( 'Tags', 'jetonomy' ),
						);
						foreach ( $filters as $key => $label ) :
							$f_url = add_query_arg(
								array(
									'q'      => $q,
									'filter' => $key,
								),
								\Jetonomy\route_url( 'search' )
							);
							?>
							<a href="<?php echo esc_url( $f_url ); ?>"
								class="jt-pill <?php echo $filter === $key ? esc_attr( 'on' ) : ''; ?>"
								<?php echo $filter === $key ? 'aria-current="true"' : ''; ?>>
								<?php echo esc_html( $label ); ?>
							</a>
						<?php endforeach; ?>
					</div>
					<span class="jt-search-result-count">
						<?php
						/* translators: %d: number of results */
						echo esc_html( sprintf( _n( '%d result', '%d results', $jt_result_total, 'jetonomy' ), $jt_result_total ) );
						?>
					</span>
				</div>


				<?php
				/**
				 * Fires below the search filter bar, so extensions can add their own filters.
				 *
				 * @since 1.0.0
				 *
				 * @param string $q      Search query.
				 * @param string $filter Active result-type filter.
				 * @param array  $args   Active date, author, tag and sort filters.
				 */
				?>
				<?php do_action( 'jetonomy_search_filters', $q, $filter, compact( 'date_from', 'date_to', 'author_id', 'tag_slug', 'sort' ) ); ?>

				<?php if ( 0 === $total ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						array(
							'icon'        => 'empty-search',
							/* translators: %s: search query */
							'message'     => sprintf( __( 'No results for "%s"', 'jetonomy' ), $q ),
							'description' => __( 'Try different or fewer keywords, or browse the community.', 'jetonomy' ),
							/* translators: %s: the plural space label the site owner configured (e.g. spaces, groups). */
							'cta_label'   => sprintf( __( 'Browse all %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
							'cta_url'     => \Jetonomy\base_url() . '/',
							'tone'        => 'warn',
						)
					);
					?>
				<?php else : ?>

					<?php if ( ! empty( $posts ) ) : ?>
						<h3 class="jt-section-label">
							<?php echo esc_html( \Jetonomy\jetonomy_label( 'topic', true ) ); ?>
						</h3>
						<div class="jt-topics jt-mb-lg">
							<?php
							// Authors, profiles and spaces in one query each, not per row.
							\Jetonomy\prime_post_cards( $posts );
							foreach ( $posts as $post ) :
								$time_ago       = human_time_diff( strtotime( $post->created_at ), time() );
								$post_url       = \Jetonomy\route_url( 'post', $post->space_slug, $post->slug );
								$excerpt        = wp_trim_words( wp_strip_all_tags( $post->content ), 25, '…' );
								$author_display = \Jetonomy\Author::for_display( (int) $post->author_id, $post );
								?>
								<?php $_jt_search_space = isset( $post->space_id ) ? \Jetonomy\Models\Space::find( (int) $post->space_id ) : null; ?>
								<a href="<?php echo esc_url( $post_url ); ?>" class="jt-row">
									<?php if ( jetonomy_space_allows_voting( $_jt_search_space ) ) : ?>
										<div class="jt-votes">
											<span class="jt-v-num"><?php echo (int) $post->vote_score; ?></span>
										</div>
									<?php endif; ?>
									<div class="jt-row-main">
										<div class="jt-row-title" dir="auto"><?php echo $jt_highlight( jetonomy_post_title_or_excerpt( $post ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $jt_highlight escapes every segment. ?></div>
										<div class="jt-row-sub">
											<?php echo esc_html( $author_display['name'] ); ?>
											&middot;
											<?php echo esc_html( $post->space_title ); ?>
											&middot;
											<?php /* translators: %s: human-readable time difference. */ ?>
											<?php echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) ); ?>
										</div>
										<?php if ( $excerpt ) : ?>
											<div class="jt-row-excerpt" dir="auto"><?php echo $jt_highlight( $excerpt ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $jt_highlight escapes every segment. ?></div>
										<?php endif; ?>
									</div>
									<div class="jt-row-stat">
										<div class="jt-row-stat-n"><?php echo (int) $post->reply_count; ?></div>
										<div class="jt-row-stat-l"><?php echo esc_html( \Jetonomy\count_noun( (int) $post->reply_count, 'reply' ) ); ?></div>
									</div>
								</a>
							<?php endforeach; ?>
						</div>

						<?php \Jetonomy\Template_Loader::partial( 'pagination', array( 'has_more' => ( $offset + count( $posts ) ) < $jt_posts_total ) ); ?>
					<?php endif; ?>

					<?php if ( ! empty( $spaces ) ) : ?>
						<h3 class="jt-section-label">
							<?php echo esc_html( \Jetonomy\space_label( true ) ); ?>
						</h3>
						<div class="jt-space-grid jt-mb-lg">
							<?php foreach ( $spaces as $space ) : ?>
								<a href="<?php echo esc_url( \Jetonomy\route_url( 'space', $space->slug ) ); ?>"
									class="jt-card jt-space-card jt-no-underline jt-block">
									<div class="jt-space-card-inner">
										<?php jetonomy_render_space_icon( $space->icon ?? '', 24, 'jt-space-card-icon', $space->type ?? '' ); ?>
										<div>
											<div class="jt-space-card-title" dir="auto"><?php echo $jt_highlight( (string) $space->title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $jt_highlight escapes every segment. ?></div>
											<?php if ( ! empty( $space->description ) ) : ?>
												<div class="jt-space-card-excerpt jt-mt-sm" dir="auto"><?php echo $jt_highlight( wp_trim_words( $space->description, 12 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $jt_highlight escapes every segment. ?></div>
											<?php endif; ?>
											<div class="jt-space-card-stat jt-mt-sm">
												<?php echo esc_html( \Jetonomy\count_label( (int) $space->post_count, 'topic' ) ); ?>
											</div>
										</div>
									</div>
								</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $tags ) ) : ?>
						<h3 class="jt-section-label">
							<?php esc_html_e( 'Tags', 'jetonomy' ); ?>
						</h3>
						<div class="jt-tags">
							<?php foreach ( $tags as $tag ) : ?>
								<a href="<?php echo esc_url( \Jetonomy\route_url( 'tag', $tag->slug ) ); ?>" class="jt-tag">
									<bdi><?php echo $jt_highlight( (string) $tag->name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $jt_highlight escapes every segment. ?></bdi>
									<span class="jt-tag-count"><?php echo (int) $tag->post_count; ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

				<?php endif; ?>
			<?php else : ?>
				<?php
				// No query yet, or one too short to search: invite the user to
				// search, and say why a 1-character term returned nothing.
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					array(
						'icon'        => 'empty-search',
						/* translators: %s: the plural space label the site owner configured (e.g. spaces, groups). */
						'message'     => sprintf( __( 'Enter a search term above to find discussions, %s, and tags.', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
						'description' => '' !== $q ? __( 'Search query must be at least 2 characters.', 'jetonomy' ) : '',
					)
				);
				?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
