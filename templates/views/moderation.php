<?php
/**
 * Cross-space moderation dashboard.
 *
 * Two audiences land here:
 *   1. WP admins + jetonomy_moderate cap holders — see every space with
 *      pending flags. Their query is unscoped.
 *   2. Space-level mods who moderate two or more spaces — see only the
 *      queues they actually own. (Single-space mods are redirected by
 *      Template_Loader::render straight to /s/:slug/mod/, so they
 *      never reach this template.)
 *
 * The data layer (Moderation_Service::dashboard_summary →
 * list_pending_flags) already scopes flags by `moderated_space_ids()`
 * for non-admin callers, so the heading and copy are the only thing
 * that needs an audience switch here.
 *
 * The dashboard itself intentionally does not embed the action
 * surface so we have exactly one place per concern — overview here,
 * resolve / dismiss in /s/:slug/mod/.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

use Jetonomy\Moderation\Moderation_Permissions;
use Jetonomy\Moderation\Moderation_Service;

$user_id  = get_current_user_id();
$base     = \Jetonomy\base_url();
$is_admin = Moderation_Permissions::can_view_admin_dashboard( $user_id );

// Anyone without admin dashboard access OR any moderated space gets the
// standard 403 empty state. Template_Loader has already redirected
// single-space mods, so reaching here without view rights is a stale
// link / drive-by visit.
if ( ! $is_admin && ! Moderation_Permissions::can_view_any_queue( $user_id ) ) {
	status_header( 403 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'message' => __( 'You do not have permission to view this page.', 'jetonomy' ),
			'tone'    => 'forbidden',
		]
	);
	return;
}

$summary = Moderation_Service::dashboard_summary( $user_id );
$total   = array_sum( array_column( $summary, 'pending' ) );

$crumbs = [
	[
		'label' => __( 'Moderation', 'jetonomy' ),
		'url'   => '',
	],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-mod-wrap jt-mod-dashboard">
	<div class="jt-flex jt-items-center jt-justify-between jt-mb-20">
		<div>
			<h1 class="jt-page-title">
				<?php esc_html_e( 'Moderation Overview', 'jetonomy' ); ?>
			</h1>
			<p class="jt-member-sub">
				<?php
				if ( $is_admin ) {
					/* translators: %d: total pending flag count across every space */
					echo esc_html( sprintf( _n( '%d pending flag across your community', '%d pending flags across your community', $total, 'jetonomy' ), $total ) );
				} else {
					/* translators: %d: total pending flag count across the spaces this moderator owns */
					echo esc_html( sprintf( _n( '%d pending flag across the spaces you moderate', '%d pending flags across the spaces you moderate', $total, 'jetonomy' ), $total ) );
				}
				?>
			</p>
		</div>
		<?php if ( $total > 0 ) : ?>
			<span class="jt-badge-danger jt-flag-count" data-count="<?php echo esc_attr( (string) $total ); ?>">
				<?php
				/* translators: %d: total pending flag count */
				echo esc_html( sprintf( _n( '%d pending', '%d pending', $total, 'jetonomy' ), $total ) );
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php if ( empty( $summary ) ) : ?>
		<?php
		$jt_empty_message = $is_admin
			? __( 'No pending flags anywhere. Your community is clean.', 'jetonomy' )
			: __( 'No pending flags in the spaces you moderate.', 'jetonomy' );
		\Jetonomy\Template_Loader::partial( 'moderation/queue-empty', [ 'message' => $jt_empty_message ] );
		?>
	<?php else : ?>
		<div class="jt-mod-dashboard-grid">
			<?php foreach ( $summary as $card ) : ?>
				<?php
				$space_url = $base . '/s/' . $card['slug'] . '/mod/';
				?>
				<a class="jt-card jt-mod-dashboard-card" href="<?php echo esc_url( $space_url ); ?>">
					<div class="jt-mod-dashboard-card-head">
						<span class="jt-badge-danger">
							<?php
							/* translators: %d: pending flag count in this space */
							echo esc_html( sprintf( _n( '%d pending', '%d pending', $card['pending'], 'jetonomy' ), $card['pending'] ) );
							?>
						</span>
						<h2 class="jt-mod-dashboard-card-title">
							<?php echo esc_html( $card['title'] ); ?>
						</h2>
					</div>
					<div class="jt-mod-dashboard-card-cta">
						<?php esc_html_e( 'Open queue', 'jetonomy' ); ?>
						<?php jetonomy_echo_icon( 'arrow-right', 14 ); ?>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
