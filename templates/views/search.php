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
$author_id = isset( $_GET['author_id'] ) ? absint( $_GET['author_id'] ) : 0;
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
		$author_name = $jt_author_user->display_name;
	}
} elseif ( $author_id && '' === $author_name ) {
	// author_id came from the URL — show the name in the input.
	$jt_author_user = get_userdata( $author_id );
	$author_name    = $jt_author_user ? $jt_author_user->display_name : '';
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tag_slug = isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'relevance';
if ( ! in_array( $sort, [ 'relevance', 'newest', 'votes' ], true ) ) {
	$sort = 'relevance';
}

$base   = \Jetonomy\base_url();
$posts  = [];
$spaces = [];
$tags   = [];

if ( '' !== $q && strlen( $q ) >= 2 ) {
	$search_adapter = \Jetonomy\Adapters\Adapter_Registry::get_search();
	if ( ! $search_adapter ) {
		$search_adapter = new \Jetonomy\Search\Fulltext_Search();
	}

	if ( in_array( $filter, [ 'all', 'posts' ], true ) ) {
		if ( $date_from || $date_to || $author_id || '' !== $author_name || $tag_slug || 'relevance' !== $sort ) {
			// Use direct filtered query when advanced filters are active.
			global $wpdb;
			$posts_tbl = \Jetonomy\table( 'posts' );
			$spaces_tbl = \Jetonomy\table( 'spaces' );
			$where     = [ 'MATCH(p.title, p.content_plain) AGAINST(%s IN BOOLEAN MODE)', "p.status = 'publish'" ];
			$params    = [ $q ];

			if ( $date_from ) {
				$where[]  = 'p.created_at >= %s';
				$params[] = $date_from . ' 00:00:00';
			}
			if ( $date_to ) {
				$where[]  = 'p.created_at <= %s';
				$params[] = $date_to . ' 23:59:59';
			}
			if ( $author_id ) {
				$where[]  = 'p.author_id = %d';
				$params[] = $author_id;
			}

			// Private-post visibility guard — same single source of truth the REST
			// controller and search adapter use, so this direct filtered query can't
			// leak private posts. Global search page (no space context) => pass null,
			// which applies the author-or-public rule with no privileged bypass.
			// Columns are unambiguous across the joined tag tables, so no alias needed.
			list( $vis_sql, $vis_params ) = \Jetonomy\Search\Fulltext_Search::visibility_clause( null, 'p' );
			if ( '' !== $vis_sql ) {
				$where[] = $vis_sql;
				$params  = array_merge( $params, $vis_params );
			}

			// Space-level content gate — exclude posts whose parent space the
			// viewer cannot read (private/hidden unless member). The non-filtered
			// search path already applies this; without it the advanced-filter
			// branch leaked private/hidden-space posts to any viewer.
			list( $space_vis_sql, $space_vis_params ) = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
			if ( '1=1' !== $space_vis_sql ) {
				$where[] = $space_vis_sql;
				$params  = array_merge( $params, $space_vis_params );
			}

			$order_by  = 'votes' === $sort ? 'p.vote_score DESC' : 'p.created_at DESC';
			$where_sql = implode( ' AND ', $where );

			if ( $tag_slug ) {
				$tags_tbl   = \Jetonomy\table( 'tags' );
				$pt_tbl     = \Jetonomy\table( 'post_tags' );
				$tag_params = array_merge( [ $tag_slug ], $params, [ $per_page, $offset ] );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql = $wpdb->prepare(
					"SELECT p.* FROM {$posts_tbl} p INNER JOIN {$spaces_tbl} s ON s.id = p.space_id INNER JOIN {$pt_tbl} pt ON pt.post_id = p.id INNER JOIN {$tags_tbl} t ON t.id = pt.tag_id AND t.slug = %s WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d",
					...$tag_params
				);
			} else {
				$paged_params = array_merge( $params, [ $per_page, $offset ] );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql = $wpdb->prepare(
					"SELECT p.* FROM {$posts_tbl} p INNER JOIN {$spaces_tbl} s ON s.id = p.space_id WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d",
					...$paged_params
				);
			}

			$posts = $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$posts = $search_adapter->search( $q, 'post', null, $per_page, $offset );
		}

		// Enrich post results with space slug/title for display.
		$space_cache = [];
		foreach ( $posts as $post ) {
			$sid = (int) $post->space_id;
			if ( ! isset( $space_cache[ $sid ] ) ) {
				$space_cache[ $sid ] = \Jetonomy\Models\Space::find( $sid );
			}
			$sp                = $space_cache[ $sid ];
			$post->space_slug  = $sp ? $sp->slug : '';
			$post->space_title = $sp ? $sp->title : '';
		}
	}

	if ( in_array( $filter, [ 'all', 'spaces' ], true ) ) {
		$spaces = $search_adapter->search( $q, 'space', null, 10, 0 );
	}

	if ( in_array( $filter, [ 'all', 'tags' ], true ) ) {
		global $wpdb;
		$tags_tbl = \Jetonomy\table( 'tags' );
		$like     = '%' . $wpdb->esc_like( $q ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tags = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$tags_tbl} WHERE name LIKE %s ORDER BY post_count DESC LIMIT 15",
				$like
			)
		) ?: [];
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
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<!-- Search form -->
			<form method="get" action="<?php echo esc_url( $base . '/search/' ); ?>" class="jt-search-page-form" autocomplete="off">
				<div class="jt-search-page-input">
					<span class="jt-search-page-icon" aria-hidden="true"><?php jetonomy_echo_icon( 'search', 20 ); ?></span>
					<input type="text" name="q"
						value="<?php echo esc_attr( $q ); ?>"
						placeholder="<?php esc_attr_e( 'Search discussions, spaces, tags…', 'jetonomy' ); ?>"
						autofocus>
				</div>
				<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
			</form>

			<?php if ( '' !== $q ) : ?>
				<!-- Filter pills -->
				<div class="jt-bar jt-mb-20">
					<div class="jt-pills">
						<?php
						$filters = [
							'all'    => __( 'All', 'jetonomy' ),
							'posts'  => __( 'Posts', 'jetonomy' ),
							'spaces' => \Jetonomy\space_label( true ),
							'tags'   => __( 'Tags', 'jetonomy' ),
						];
						foreach ( $filters as $key => $label ) :
							$f_url = add_query_arg(
								[
									'q'      => $q,
									'filter' => $key,
								],
								$base . '/search/'
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

				<!-- Advanced filters -->
				<details class="jt-search-filters jt-mb-20" 
				<?php
				if ( $date_from || $date_to || $author_id || '' !== $author_name || $tag_slug || 'relevance' !== $sort ) :
					?>
					open<?php endif; ?>>
					<summary class="jt-search-filters-toggle"><?php esc_html_e( 'Filters', 'jetonomy' ); ?> <?php jetonomy_echo_icon( 'chevron-down', 12 ); ?></summary>
					<form method="get" action="<?php echo esc_url( $base . '/search/' ); ?>" class="jt-search-filters-form">
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
							<a href="<?php echo esc_url( add_query_arg( 'q', $q, $base . '/search/' ) ); ?>" class="jt-btn jt-btn-ghost jt-btn-sm"><?php esc_html_e( 'Clear', 'jetonomy' ); ?></a>
						</div>
					</form>
				</details>

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
							'cta_label'   => sprintf( __( 'Browse all %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
							'cta_url'     => \Jetonomy\base_url() . '/',
							'tone'        => 'warn',
						]
					);
					?>
				<?php else : ?>

					<?php if ( ! empty( $posts ) ) : ?>
						<h3 class="jt-section-label">
							<?php esc_html_e( 'Posts', 'jetonomy' ); ?>
						</h3>
						<div class="jt-topics jt-mb-lg">
							<?php
							foreach ( $posts as $post ) :
								$time_ago = human_time_diff( strtotime( $post->created_at ), time() );
								$post_url = $base . '/s/' . $post->space_slug . '/t/' . $post->slug . '/';
								$excerpt  = wp_trim_words( wp_strip_all_tags( $post->content ), 25, '…' );
								$author   = get_userdata( (int) $post->author_id );
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
											<?php echo esc_html( $author ? $author->display_name : '' ); ?>
											&middot;
											<?php echo esc_html( $post->space_title ); ?>
											&middot;
											<?php echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) ); ?>
										</div>
										<?php if ( $excerpt ) : ?>
											<div class="jt-row-excerpt"><?php echo esc_html( $excerpt ); ?></div>
										<?php endif; ?>
									</div>
									<div class="jt-row-stat">
										<div class="jt-row-stat-n"><?php echo (int) $post->reply_count; ?></div>
										<div class="jt-row-stat-l"><?php esc_html_e( 'replies', 'jetonomy' ); ?></div>
									</div>
									<div class="jt-row-stat">
										<div class="jt-row-stat-n"><?php echo (int) $post->vote_score; ?></div>
										<div class="jt-row-stat-l"><?php esc_html_e( 'votes', 'jetonomy' ); ?></div>
									</div>
								</a>
							<?php endforeach; ?>
						</div>

						<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $posts ) >= $per_page ] ); ?>
					<?php endif; ?>

					<?php if ( ! empty( $spaces ) ) : ?>
						<h3 class="jt-section-label">
							<?php echo esc_html( \Jetonomy\space_label( true ) ); ?>
						</h3>
						<div class="jt-space-grid jt-mb-lg">
							<?php foreach ( $spaces as $space ) : ?>
								<a href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>"
									class="jt-card jt-space-card jt-no-underline jt-block">
									<div class="jt-space-card-inner">
										<?php jetonomy_render_space_icon( $space->icon ?? '', 24, 'jt-cat-emoji', $space->type ?? '' ); ?>
										<div>
											<div class="jt-space-card-title"><?php echo esc_html( $space->title ); ?></div>
											<?php if ( ! empty( $space->description ) ) : ?>
												<div class="jt-space-card-excerpt jt-mt-sm"><?php echo esc_html( wp_trim_words( $space->description, 12 ) ); ?></div>
											<?php endif; ?>
											<div class="jt-space-card-stat jt-mt-sm">
												<?php echo esc_html( (int) $space->post_count ); ?> <?php esc_html_e( 'posts', 'jetonomy' ); ?>
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
								<a href="<?php echo esc_url( $base . '/tag/' . $tag->slug . '/' ); ?>" class="jt-tag">
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
						'message' => __( 'Enter a search term above to find discussions, spaces, and tags.', 'jetonomy' ),
					]
				);
				?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
