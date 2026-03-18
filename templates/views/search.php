<?php
defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
if ( ! in_array( $filter, [ 'all', 'posts', 'spaces', 'tags' ], true ) ) {
	$filter = 'all';
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page     = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

$base   = home_url( '/community' );
$posts  = [];
$spaces = [];
$tags   = [];

if ( '' !== $q && strlen( $q ) >= 2 ) {
	$search_adapter = \Jetonomy\Adapters\Adapter_Registry::get_search();
	if ( ! $search_adapter ) {
		$search_adapter = new \Jetonomy\Search\Fulltext_Search();
	}

	if ( in_array( $filter, [ 'all', 'posts' ], true ) ) {
		$posts = $search_adapter->search( $q, 'post', null, $per_page, $offset );

		// Enrich post results with space slug/title for display.
		$space_cache = [];
		foreach ( $posts as $post ) {
			$sid = (int) $post->space_id;
			if ( ! isset( $space_cache[ $sid ] ) ) {
				$space_cache[ $sid ] = \Jetonomy\Models\Space::find( $sid );
			}
			$sp = $space_cache[ $sid ];
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
	[ 'label' => __( 'Search', 'jetonomy' ), 'url' => '' ],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<!-- Search form -->
			<div class="jt-search-form-wrap">
				<form method="get" action="<?php echo esc_url( $base . '/search/' ); ?>">
					<div class="jt-search jt-search-full">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
						<input type="text" name="q"
							value="<?php echo esc_attr( $q ); ?>"
							placeholder="<?php esc_attr_e( 'Search discussions, spaces, tags…', 'jetonomy' ); ?>"
							autofocus>
					</div>
					<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
				</form>
			</div>

			<?php if ( '' !== $q ) : ?>
				<!-- Filter pills -->
				<div class="jt-bar jt-mb-20">
					<div class="jt-pills">
						<?php
						$filters = [
							'all'    => __( 'All', 'jetonomy' ),
							'posts'  => __( 'Posts', 'jetonomy' ),
							'spaces' => __( 'Spaces', 'jetonomy' ),
							'tags'   => __( 'Tags', 'jetonomy' ),
						];
						foreach ( $filters as $key => $label ) :
							$f_url = add_query_arg( [ 'q' => $q, 'filter' => $key ], $base . '/search/' );
						?>
							<a href="<?php echo esc_url( $f_url ); ?>"
								class="jt-pill <?php echo $filter === $key ? 'on' : ''; ?>">
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

				<?php if ( 0 === $total ) : ?>
					<div class="jt-empty">
						<div class="jt-empty-icon">&#128270;</div>
						<div class="jt-empty-text">
							<?php
							/* translators: %s: search query */
							echo esc_html( sprintf( __( 'No results for "%s"', 'jetonomy' ), $q ) );
							?>
						</div>
					</div>
				<?php else : ?>

					<?php if ( ! empty( $posts ) ) : ?>
						<h3 class="jt-section-label">
							<?php esc_html_e( 'Posts', 'jetonomy' ); ?>
						</h3>
						<div class="jt-topics jt-mb-lg">
							<?php foreach ( $posts as $post ) : ?>
								<?php
								$time_ago = human_time_diff( strtotime( $post->created_at ), current_time( 'timestamp', true ) );
								$post_url = $base . '/s/' . $post->space_slug . '/t/' . $post->slug . '/';
								?>
								<div class="jt-row" onclick="window.location='<?php echo esc_url( $post_url ); ?>'">
									<div class="jt-votes">
										<span class="jt-v-num"><?php echo (int) $post->vote_score; ?></span>
									</div>
									<div class="jt-row-main">
										<div class="jt-row-title"><?php echo esc_html( $post->title ); ?></div>
										<div class="jt-row-sub">
											<a href="<?php echo esc_url( $base . '/s/' . $post->space_slug . '/' ); ?>"
												onclick="event.stopPropagation();">
												<?php echo esc_html( $post->space_title ); ?>
											</a>
										</div>
									</div>
									<div class="jt-row-stat">
										<div class="jt-row-stat-n"><?php echo (int) $post->reply_count; ?></div>
										<div class="jt-row-stat-l"><?php esc_html_e( 'replies', 'jetonomy' ); ?></div>
									</div>
									<div class="jt-row-stat">
										<div class="jt-row-time">
											<?php
											/* translators: %s: human-readable time difference */
											echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
											?>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $posts ) >= $per_page ] ); ?>
					<?php endif; ?>

					<?php if ( ! empty( $spaces ) ) : ?>
						<h3 class="jt-section-label">
							<?php esc_html_e( 'Spaces', 'jetonomy' ); ?>
						</h3>
						<div class="jt-space-grid jt-mb-lg">
							<?php foreach ( $spaces as $space ) : ?>
								<a href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>"
									class="jt-card jt-space-card jt-no-underline jt-block">
									<div class="jt-space-card-inner">
										<?php if ( ! empty( $space->emoji ) ) : ?>
											<span class="jt-cat-emoji"><?php echo esc_html( $space->emoji ); ?></span>
										<?php endif; ?>
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
				<!-- No query yet -->
				<div class="jt-empty">
					<div class="jt-empty-icon">&#128270;</div>
					<div class="jt-empty-text"><?php esc_html_e( 'Enter a search term above to find discussions, spaces, and tags.', 'jetonomy' ); ?></div>
				</div>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
