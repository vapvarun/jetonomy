<?php
/**
 * Leaderboard view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$profiles_tbl = \Jetonomy\table( 'user_profiles' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : 'all';
if ( ! in_array( $period, [ 'all', 'month', 'week' ], true ) ) {
	$period = 'all';
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page     = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

$period_where = '';
if ( 'week' === $period ) {
	$period_where = ' WHERE last_seen_at > DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ( 'month' === $period ) {
	$period_where = ' WHERE last_seen_at > DATE_SUB(NOW(), INTERVAL 30 DAY)';
}

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$leaders = $wpdb->get_results(
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT * FROM {$profiles_tbl}{$period_where} ORDER BY reputation DESC LIMIT %d OFFSET %d",
		$per_page,
		$offset
	)
) ?: [];

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

		<?php if ( empty( $leaders ) ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'icon'      => 'award',
					'icon_size' => 48,
					'message'   => __( 'No members yet.', 'jetonomy' ),
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
				<?php foreach ( $leaders as $rank => $leader ) : ?>
					<?php
					$lu = get_userdata( (int) $leader->user_id );
					if ( ! $lu ) {
						continue;
					}
					$trust    = (int) $leader->trust_level;
					$initials = strtoupper( substr( $lu->display_name, 0, 2 ) );
					$medal    = '';
					if ( 0 === $rank ) {
						$medal = '<svg width="20" height="20" viewBox="0 0 24 24" fill="#FFD700" stroke="#B8860B" stroke-width="1.5"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>';
					} elseif ( 1 === $rank ) {
						$medal = '<svg width="20" height="20" viewBox="0 0 24 24" fill="#C0C0C0" stroke="#808080" stroke-width="1.5"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>';
					} elseif ( 2 === $rank ) {
						$medal = '<svg width="20" height="20" viewBox="0 0 24 24" fill="#CD7F32" stroke="#8B4513" stroke-width="1.5"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>';
					}
					?>
					<div class="jt-leader jt-leader-pad">
						<span class="jt-leader-rank">
							<?php
							if ( $medal ) {
								echo $medal; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG
							} else {
								echo (int) ( $rank + 1 );
							}
							?>
						</span>
						<span class="jt-avatar jt-avatar-md"><?php echo esc_html( $initials ); ?></span>
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
			</div>

			<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $leaders ) >= $per_page ] ); ?>
		<?php endif; ?>
</main>

<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => null ] ); ?>
</div>
