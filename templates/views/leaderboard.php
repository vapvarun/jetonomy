<?php
/**
 * Leaderboard view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : 'all';
if ( ! in_array( $period, [ 'all', 'month', 'week' ], true ) ) {
	$period = 'all';
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page = max( 1, absint( wp_unslash( $_GET['pg'] ?? 1 ) ) );

// Per-page is intentionally aligned with the REST controller default
// (Leaderboards_Controller::register_routes() `limit` arg). Keep these
// in sync — both surfaces render the same page size so server-side and
// client-side leaderboards stay consistent.
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

// Page slice + real total come from the model so the server-rendered board
// and the REST endpoint share one query and one pagination rule. has_more
// compares the real total against what is shown — never count( $leaders ),
// which rendered a phantom "Load More" when the total was an exact multiple
// of $per_page (Basecamp).
$leaders      = \Jetonomy\Models\UserProfile::list_for_leaderboard( $period, $per_page, $offset );
$_jt_total    = \Jetonomy\Models\UserProfile::count_for_leaderboard( $period );
$_jt_has_more = ( $page * $per_page ) < $_jt_total;

// Batch-fetch leader users in one query to avoid N get_userdata() calls
// inside the render loop below. Mirrors the REST controller's batching
// strategy so big-site leaderboard renders stay O(1) on user lookups.
$leader_ids        = array_map(
	static fn( $r ) => (int) $r->user_id,
	$leaders
);
$leader_users      = ! empty( $leader_ids )
	? get_users(
		[
			'include' => $leader_ids,
			'orderby' => 'include',
		]
	)
	: [];
$leader_user_by_id = [];
foreach ( $leader_users as $lu_obj ) {
	$leader_user_by_id[ (int) $lu_obj->ID ] = $lu_obj;
}

// Warm the profile cache for every leader in one WHERE user_id IN (...) query so
// the avatar partial below (Avatar::display_url() -> UserProfile::find_by_user())
// does not fire a per-row SELECT on a cold cache. Keeps big-site boards O(1).
if ( ! empty( $leader_ids ) ) {
	\Jetonomy\Models\UserProfile::prime( $leader_ids );
}

$base   = \Jetonomy\base_url();
$crumbs = [
	[
		'label' => __( 'Leaderboard', 'jetonomy' ),
		'url'   => '',
	],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
<main>
		<div class="jt-flex jt-items-center jt-justify-between jt-mb-20">
			<h1 class="jt-page-title">
				<?php esc_html_e( 'Leaderboard', 'jetonomy' ); ?>
			</h1>
		</div>

		<!-- Period filter — switches the board between all-time and recent activity. -->
		<div class="jt-bar jt-mb-20">
			<div class="jt-pills">
				<?php
				$jt_lb_periods = array(
					'all'   => __( 'All time', 'jetonomy' ),
					'month' => __( 'This month', 'jetonomy' ),
					'week'  => __( 'This week', 'jetonomy' ),
				);
				foreach ( $jt_lb_periods as $jt_lb_key => $jt_lb_label ) :
					$jt_lb_url = 'all' === $jt_lb_key
						? $base . '/leaderboard/'
						: add_query_arg( 'period', $jt_lb_key, $base . '/leaderboard/' );
					?>
					<a href="<?php echo esc_url( $jt_lb_url ); ?>"
						class="jt-pill <?php echo $period === $jt_lb_key ? esc_attr( 'on' ) : ''; ?>"
						<?php echo $period === $jt_lb_key ? 'aria-current="true"' : ''; ?>>
						<?php echo esc_html( $jt_lb_label ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>

		<?php
		// "You are ranked #N" — orients the viewer on a big board where they
		// aren't on the first page. One O(1) COUNT query; hidden for guests and
		// members not active in the selected period (rank 0).
		$jt_current_uid = get_current_user_id();
		$jt_my_rank     = $jt_current_uid ? \Jetonomy\Models\UserProfile::rank_for_user( $jt_current_uid, $period ) : 0;
		if ( $jt_my_rank > 0 ) :
			?>
			<div class="jt-card jt-leader-you">
				<span class="jt-leader-you-rank">#<?php echo (int) $jt_my_rank; ?></span>
				<span class="jt-leader-you-label">
					<?php esc_html_e( 'Your rank', 'jetonomy' ); ?>
					<small><?php
						echo esc_html(
							sprintf(
								/* translators: %d: total members on the leaderboard. */
								__( 'of %d members', 'jetonomy' ),
								$_jt_total
							)
						);
					?></small>
				</span>
			</div>
		<?php endif; ?>

		<?php if ( empty( $leaders ) ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'icon'        => 'award',
					'icon_size'   => 48,
					'message'     => __( 'No members yet.', 'jetonomy' ),
					'description' => __( 'Reputation is earned by posting, getting replies, having answers accepted, and receiving votes. Be the first to start.', 'jetonomy' ),
					'cta_label'   => __( 'Browse the community', 'jetonomy' ),
					'cta_url'     => \Jetonomy\base_url() . '/',
				]
			);
			?>
		<?php else : ?>
			<div class="jt-card">
				<?php /* Mobile-only column header — hidden on desktop via .jt-leader-head CSS. */ ?>
				<div class="jt-leader jt-leader-head" aria-hidden="true">
					<span class="jt-leader-rank"></span>
					<span class="jt-leader-head-spacer"></span>
					<span class="jt-leader-name"><?php esc_html_e( 'Member', 'jetonomy' ); ?></span>
					<div class="jt-leader-stats">
						<div class="jt-leader-stat-lbl"><?php esc_html_e( 'rep', 'jetonomy' ); ?></div>
						<div class="jt-leader-stat-lbl"><?php esc_html_e( 'posts', 'jetonomy' ); ?></div>
					</div>
				</div>
				<?php /* Appendable list — pagination-frontend.js targets .jt-leaderboard-list to inject page 2+ rows. */ ?>
				<div class="jt-leaderboard-list">
				<?php foreach ( $leaders as $rank => $leader ) : ?>
					<?php
					$lu = $leader_user_by_id[ (int) $leader->user_id ] ?? null;
					if ( ! $lu ) {
						continue;
					}
					// Absolute rank across pages so medals + numbering stay correct
					// when "Load More" appends page 2+ into this same list.
					$abs_rank    = $offset + (int) $rank;
					$trust       = (int) $leader->trust_level;
					$medal_class = '';
					if ( 0 === $abs_rank ) {
						$medal_class = 'jt-medal jt-medal-gold';
					} elseif ( 1 === $abs_rank ) {
						$medal_class = 'jt-medal jt-medal-silver';
					} elseif ( 2 === $abs_rank ) {
						$medal_class = 'jt-medal jt-medal-bronze';
					}
					?>
					<div class="jt-leader jt-leader-pad">
						<span class="jt-leader-rank">
							<?php
							if ( $medal_class ) {
								// 1.4.1 icon-source sweep: medal renders via the
								// `award` Lucide icon (gold/silver/bronze tinted
								// by `.jt-medal-*` color classes) instead of the
								// previous inline-SVG with hex fills. One source
								// of truth for icon shape across the plugin.
								echo '<span class="' . esc_attr( $medal_class ) . '" aria-hidden="true">';
								jetonomy_echo_icon( 'award', 20 );
								echo '</span>';
							} else {
								echo (int) ( $abs_rank + 1 );
							}
							?>
						</span>
						<?php
						// Render the real avatar (image with initials fallback) via the
						// shared partial, consistent with every other member surface. The
						// profile cache was primed above so this stays O(1) per row.
						\Jetonomy\Template_Loader::partial(
							'avatar',
							[
								'user_id' => (int) $leader->user_id,
								'size'    => 36,
								'class'   => 'jt-avatar-md',
							]
						);
						?>
						<span class="jt-leader-name">
							<a href="<?php echo esc_url( \Jetonomy\get_profile_url( (int) $leader->user_id ) ); ?>">
								<?php echo esc_html( $lu->display_name ); ?>
							</a>
							<?php
							// 1.4.1 byline cleanup: trust-level number removed.
							// Trust progress lives on the user profile + hover-card
							// surfaces.
							?>
						</span>
						<div class="jt-leader-stats">
							<div>
								<div class="jt-leader-pts"><?php echo (int) $leader->reputation; ?></div>
								<div class="jt-leader-stat-lbl"><?php esc_html_e( 'rep', 'jetonomy' ); ?></div>
							</div>
							<div>
								<div class="jt-leader-stat-val"><?php echo (int) $leader->post_count; ?></div>
								<div class="jt-leader-stat-lbl"><?php esc_html_e( 'posts', 'jetonomy' ); ?></div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
				</div><!-- .jt-leaderboard-list -->
			</div>

			<?php
			\Jetonomy\Template_Loader::partial(
				'pagination',
				[
					'has_more' => $_jt_has_more,
					'target'   => '.jt-leaderboard-list',
				]
			);
			?>
		<?php endif; ?>
</main>

<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => null ] ); ?>
</div>
