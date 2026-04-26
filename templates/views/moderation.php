<?php
/**
 * Admin cross-space moderation dashboard.
 *
 * Gated to WP admins + jetonomy_moderate cap holders. Shows a per-space
 * summary of pending flags so a site owner can spot trouble at a glance
 * and click into the per-space queue where the actual resolve / dismiss
 * actions live. The dashboard itself intentionally does not embed the
 * action surface so we have exactly one place per concern — fire drill
 * here, action there.
 *
 * Space-level moderators hit this URL and get redirected into their
 * own context (their single moderated space, or a gentle empty state
 * if they moderate nothing).
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

use Jetonomy\Moderation\Moderation_Permissions;
use Jetonomy\Moderation\Moderation_Service;

$user_id = get_current_user_id();
$base    = \Jetonomy\base_url();

// Template_Loader::render has already redirected space-level mods into their
// per-space queue. Anyone reaching here without dashboard permission gets the
// standard 403 empty state.
if ( ! Moderation_Permissions::can_view_admin_dashboard( $user_id ) ) {
	status_header( 403 );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- jetonomy_icon() returns trusted SVG
	echo '<div class="jt-empty"><div class="jt-empty-icon">' . jetonomy_icon( 'lock', 48 ) . '</div><div class="jt-empty-text">' . esc_html__( 'You do not have permission to view this page.', 'jetonomy' ) . '</div></div>';
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
				/* translators: %d: total pending flag count across every space */
				echo esc_html( sprintf( _n( '%d pending flag across your community', '%d pending flags across your community', $total, 'jetonomy' ), $total ) );
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
		<?php \Jetonomy\Template_Loader::partial( 'moderation/queue-empty', [ 'message' => __( 'No pending flags anywhere. Your community is clean.', 'jetonomy' ) ] ); ?>
	<?php else : ?>
		<div class="jt-mod-dashboard-grid">
			<?php foreach ( $summary as $card ) : ?>
				<?php
				$space_url = $base . '/s/' . $card['slug'] . '/mod/';
				?>
				<a class="jt-card jt-mod-dashboard-card" href="<?php echo esc_url( $space_url ); ?>">
					<div class="jt-mod-dashboard-card-head">
						<h2 class="jt-mod-dashboard-card-title">
							<?php echo esc_html( $card['title'] ); ?>
						</h2>
						<span class="jt-badge-danger">
							<?php
							/* translators: %d: pending flag count in this space */
							echo esc_html( sprintf( _n( '%d pending', '%d pending', $card['pending'], 'jetonomy' ), $card['pending'] ) );
							?>
						</span>
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
