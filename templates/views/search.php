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
if ( ! in_array( $filter, [ 'all', 'posts', 'spaces', 'tags' ], true ) ) {
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
if ( ! in_array( $sort, [ 'relevance', 'newest', 'votes' ], true ) ) {
	$sort = 'relevance';
}

$posts  = [];
$spaces = [];
$tags   = [];

// Has the viewer asked for ANYTHING - a keyword, or a filter on its own?
//
// Everything below used to be gated on a keyword alone, so the filters were
// unreachable until you had already searched, and a filter-only URL
// (?author_name=aisha, the kind you bookmark or share) rendered the empty
// state. The REST route has always answered an author-only search - it returns
// results for author_id with no q - so this was the web UI refusing to do what
// the API does, which is the three-entry-points rule failing on the surface
// members actually use.
$jt_has_filters = ( $date_from || $date_to || $author_id || '' !== $author_name || $tag_slug || 'relevance' !== $sort );
$jt_has_query   = ( '' !== $q && strlen( $q ) >= 2 );
$jt_searching   = ( $jt_has_query || $jt_has_filters );

$jt_posts_total = 0;
if ( $jt_searching && ! $jt_author_unresolved ) {
	// Same search, same rules as REST /search and the app: one adapter call per
	// group instead of this view's own SQL (Basecamp 10368526736). Post rows
	// already carry space_title / space_slug.
	$search_adapter = \Jetonomy\Adapters\Adapter_Registry::get_search_query();

	if ( in_array( $filter, [ 'all', 'posts' ], true ) ) {
		$jt_found       = $search_adapter->query(
			[
				'type'      => 'post',
				'q'         => $jt_has_query ? $q : '',
				'date_from' => $date_from ?: null,
				'date_to'   => $date_to ?: null,
				'author_id' => $author_id ?: null,
				'tag_slug'  => $tag_slug ?: null,
				'sort'      => $sort,
				'limit'     => $per_page,
				'offset'    => $offset,
			]
		);
		$posts          = $jt_found['items'];
		$jt_posts_total = $jt_found['total'];
	}

	// Spaces and tags are keyword searches - the advanced filters (author, date,
	// tag) describe topics, not either of these. Without this guard a
	// filter-only request searched them for '', which matched every tag on the
	// site, so "this author's topics" came back decorated with arbitrary tags.
	if ( $jt_has_query && in_array( $filter, [ 'all', 'spaces' ], true ) ) {
		$spaces = $search_adapter->query(
			[
				'type'       => 'space',
				'q'          => $q,
				'limit'      => 10,
				'with_total' => false,
			]
		)['items'];
	}

	if ( $jt_has_query && in_array( $filter, [ 'all', 'tags' ], true ) ) {
		$tags = $search_adapter->query(
			[
				'type'       => 'tag',
				'q'          => $q,
				'limit'      => 15,
				'with_total' => false,
			]
		)['items'];
	}
}

$total = count( $posts ) + count( $spaces ) + count( $tags );

$crumbs = [
	[
		'label' => __( 'Search', 'jetonomy' ),
		'url'   => '',
	],
];
?>
<?php \Jetonomy\Template_Loader::breadcrumb( $crumbs ); ?>

<div class="jt-two-col">
		<main>
			<?php \Jetonomy\Template_Loader::breadcrumb_in_main(); ?>
			<!-- Search form -->
			<form method="get" action="<?php echo esc_url( \Jetonomy\route_url( 'search' ) ); ?>" class="jt-search-page-form" autocomplete="off">
				<div class="jt-search-page-input">
					<span class="jt-search-page-icon" aria-hidden="true"><?php jetonomy_echo_icon( 'search', 20 ); ?></span>
					<input type="text" name="q"
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
			 * They used to live inside the results branch, so the only way to reach
			 * them was to search for a keyword first - and a filter-only search
			 * (every post by one author) was therefore impossible from the UI even
			 * though the REST route has always supported it. Exposing them on the
			 * landing page is what makes that capability reachable; the accordion
			 * stays collapsed until used, so the empty page is not busier.
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
						$filters = [
							'all'    => __( 'All', 'jetonomy' ),
							'posts'  => \Jetonomy\jetonomy_label( 'topic', true ),
							'spaces' => \Jetonomy\space_label( true ),
							'tags'   => __( 'Tags', 'jetonomy' ),
						];
						foreach ( $filters as $key => $label ) :
							$f_url = add_query_arg(
								[
									'q'      => $q,
									'filter' => $key,
								],
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
						echo esc_html( sprintf( _n( '%d result', '%d results', $total, 'jetonomy' ), $total ) );
						?>
					</span>
				</div>


				<?php do_action( 'jetonomy_search_filters', $q, $filter, compact( 'date_from', 'date_to', 'author_id', 'tag_slug', 'sort' ) ); ?>

				<?php if ( 0 === $total ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						[
							'icon'        => 'empty-search',
							/* translators: %s: search query */
							'message'     => sprintf( __( 'No results for "%s"', 'jetonomy' ), $q ),
							'description' => __( 'Try different or fewer keywords (search needs at least 2 characters), or browse the community.', 'jetonomy' ),
							/* translators: %s: the plural space label the site owner configured (e.g. spaces, groups). */
							'cta_label'   => sprintf( __( 'Browse all %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
							'cta_url'     => \Jetonomy\base_url() . '/',
							'tone'        => 'warn',
						]
					);
					?>
				<?php else : ?>

					<?php if ( ! empty( $posts ) ) : ?>
						<h3 class="jt-section-label">
							<?php echo esc_html( \Jetonomy\jetonomy_label( 'topic', true ) ); ?>
						</h3>
						<div class="jt-topics jt-mb-lg">
							<?php
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
										<div class="jt-row-title"><?php echo esc_html( jetonomy_post_title_or_excerpt( $post ) ); ?></div>
										<div class="jt-row-sub">
											<?php echo esc_html( $author_display['name'] ); ?>
											&middot;
											<?php echo esc_html( $post->space_title ); ?>
											&middot;
											<?php /* translators: %s: human-readable time difference. */ ?>
											<?php echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) ); ?>
										</div>
										<?php if ( $excerpt ) : ?>
											<div class="jt-row-excerpt"><?php echo esc_html( $excerpt ); ?></div>
										<?php endif; ?>
									</div>
									<div class="jt-row-stat">
										<div class="jt-row-stat-n"><?php echo (int) $post->reply_count; ?></div>
										<div class="jt-row-stat-l"><?php echo esc_html( \Jetonomy\count_noun( (int) $post->reply_count, 'reply' ) ); ?></div>
									</div>
								</a>
							<?php endforeach; ?>
						</div>

						<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => ( $offset + count( $posts ) ) < $jt_posts_total ] ); ?>
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
											<div class="jt-space-card-title"><?php echo esc_html( $space->title ); ?></div>
											<?php if ( ! empty( $space->description ) ) : ?>
												<div class="jt-space-card-excerpt jt-mt-sm"><?php echo esc_html( wp_trim_words( $space->description, 12 ) ); ?></div>
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
									<?php echo esc_html( $tag->name ); ?>
									<span class="jt-tag-count"><?php echo (int) $tag->post_count; ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

				<?php endif; ?>
			<?php else : ?>
				<?php
				// No query yet — invite the user to search.
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					[
						'icon'    => 'empty-search',
						/* translators: %s: the plural space label the site owner configured (e.g. spaces, groups). */
						'message' => sprintf( __( 'Enter a search term above to find discussions, %s, and tags.', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
					]
				);
				?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
