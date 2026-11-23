<?php
/**
 * Sidebar widget: Top Members. Rendered by partials/sidebar.php when the owner
 * has it switched on (Settings > Appearance). Fetches its own data, so a
 * switched-off widget costs nothing. Override at
 * {theme}/jetonomy/partials/sidebar/top-members.php.
 *
 * @package Jetonomy
 * @since   2.1.0
 *
 * @var object|null $space     Current space, or null outside a space.
 * @var bool        $bn_active Whether BuddyNext card markup is used.
 */

defined( 'ABSPATH' ) || exit;

// Top members by reputation. Deliberately NOT block-filtered — this is a
// ranking/leaderboard, not a content feed; per-viewer filtering would
// re-rank the board and leak "you blocked someone" via rank gaps.
//
// Cached 600s under ONE global key — zero viewer variance,
// runs on 100% of page views, and the bare ORDER BY reputation filesorts.
// TTL-only by design: a 10-minute-stale Top Members widget is invisible,
// and busting on every reputation write would defeat the point.
//
// Reads the leaderboard model, not its own query, so the widget ranks exactly
// the population the Leaderboard page does (members with reputation > 0).
// The key is not the legacy 'sidebar:leaders', so an upgraded site does not serve a
// stale unfiltered list for the rest of the TTL.
$leaders = \Jetonomy\Cache::remember(
	'sidebar:top-members',
	static fn() => \Jetonomy\Models\UserProfile::list_for_leaderboard( 'all', 5, 0 ),
	600
);

// Warm the profile cache for the Top Members widget in one query so the avatar
// partial below does not fire a per-row SELECT on a cold cache.
if ( ! empty( $leaders ) ) {
	\Jetonomy\Models\UserProfile::prime( array_map( static fn( $r ) => (int) $r->user_id, $leaders ) );
}
?>
<?php if ( ! empty( $leaders ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h2 class="jt-card-title"><?php printf( /* translators: %s: plural member label. */ esc_html__( 'Top %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></h2>
			<?php else : ?>
				<?php printf( /* translators: %s: plural member label. */ esc_html__( 'Top %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true ) ) ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<?php foreach ( $leaders as $rank => $leader ) : ?>
				<?php
				$lu = get_userdata( (int) $leader->user_id );
				if ( ! $lu ) {
					continue;
				}
				?>
				<div class="jt-leader">
					<span class="jt-avatar-wrap <?php echo \Jetonomy\Models\UserProfile::is_online( (int) $leader->user_id ) ? esc_attr( 'is-online' ) : ''; ?>">
						<?php
						// Real avatar (image with initials fallback) via the shared
						// partial, consistent with every other member surface.
						\Jetonomy\Template_Loader::partial(
							'avatar',
							array(
								'user_id' => (int) $leader->user_id,
								'size'    => 30,
								'class'   => 'jt-avatar-sm jt-flex-shrink-0',
							)
						);
						?>
					</span>
					<span class="jt-leader-name">
						<a href="<?php echo esc_url( \Jetonomy\get_profile_url( (int) $leader->user_id ) ); ?>">
							<?php echo esc_html( \Jetonomy\user_display_name( $lu ) ); ?>
						</a>
					</span>
					<span class="jt-leader-pts"><?php echo (int) $leader->reputation; ?></span>
				</div>
			<?php endforeach; ?>
			<div class="jt-sidebar-link">
				<a href="<?php echo esc_url( \Jetonomy\route_url( 'leaderboard' ) ); ?>"><?php esc_html_e( 'View full leaderboard', 'jetonomy' ); ?></a>
			</div>
		</div>
	</div>
<?php endif; ?>
