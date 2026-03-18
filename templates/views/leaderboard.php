<?php
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

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$leaders = $wpdb->get_results(
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT * FROM {$profiles_tbl} ORDER BY reputation DESC LIMIT %d OFFSET %d",
		$per_page,
		$offset
	)
) ?: [];

$base   = home_url( '/community' );
$crumbs = [
	[ 'label' => __( 'Leaderboard', 'jetonomy' ), 'url' => '' ],
];
?>
<div class="jt-container">

	<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

	<div style="max-width:700px;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
			<h1 style="font-family:var(--jt-font-heading);font-size:22px;font-weight:700;margin:0;">
				<?php esc_html_e( 'Leaderboard', 'jetonomy' ); ?>
			</h1>
		</div>

		<?php if ( empty( $leaders ) ) : ?>
			<div class="jt-empty">
				<div class="jt-empty-icon">&#127942;</div>
				<div class="jt-empty-text"><?php esc_html_e( 'No members yet.', 'jetonomy' ); ?></div>
			</div>
		<?php else : ?>
			<div class="jt-card">
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
						$medal = '&#129945;'; // Gold.
					} elseif ( 1 === $rank ) {
						$medal = '&#129944;'; // Silver.
					} elseif ( 2 === $rank ) {
						$medal = '&#129943;'; // Bronze.
					}
					?>
					<div class="jt-leader" style="padding:10px 0;">
						<span class="jt-leader-rank">
							<?php
							if ( $medal ) {
								echo $medal; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- emoji literal.
							} else {
								echo $rank + 1;
							}
							?>
						</span>
						<span class="jt-avatar jt-avatar-md"><?php echo esc_html( $initials ); ?></span>
						<span class="jt-leader-name">
							<a href="<?php echo esc_url( $base . '/u/' . $lu->user_login . '/' ); ?>">
								<?php echo esc_html( $lu->display_name ); ?>
							</a>
							<span class="jt-tl" style="background:var(--jt-tl<?php echo $trust; ?>);margin-left:6px;" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo $trust; ?></span>
						</span>
						<div style="display:flex;gap:16px;margin-left:auto;text-align:right;">
							<div>
								<div class="jt-leader-pts"><?php echo (int) $leader->reputation; ?></div>
								<div style="font-size:10px;color:var(--jt-text-tertiary);text-transform:uppercase;letter-spacing:.05em;"><?php esc_html_e( 'rep', 'jetonomy' ); ?></div>
							</div>
							<div>
								<div style="font-family:var(--jt-font-mono);font-size:12px;font-weight:600;"><?php echo (int) $leader->post_count; ?></div>
								<div style="font-size:10px;color:var(--jt-text-tertiary);text-transform:uppercase;letter-spacing:.05em;"><?php esc_html_e( 'posts', 'jetonomy' ); ?></div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $leaders ) >= $per_page ] ); ?>
		<?php endif; ?>
	</div>

</div>
