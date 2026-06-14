<?php
/**
 * Wbcom stack companion cards (rendered inside the Integrations settings tab).
 *
 * One card per Companion_Registry entry: status badge + the matching action
 * (one-click free install, activate, or learn more). No data is created here —
 * the screen reflects registry status and triggers installs through
 * Companion_Installer via the admin-post handler.
 *
 * @package Jetonomy\Integrations
 */

defined( 'ABSPATH' ) || exit;

use Jetonomy\Integrations\Companion_Registry;

$jt_companions = Companion_Registry::all();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only redirect-back status flags, no state change.
$jt_install_state = isset( $_GET['jt_install'] ) ? sanitize_key( wp_unslash( $_GET['jt_install'] ) ) : '';
$jt_install_msg   = isset( $_GET['jt_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['jt_msg'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<div class="jt-settings-card">
	<div class="jt-settings-card__head">
		<p class="jt-settings-card__title"><?php esc_html_e( 'Wbcom stack', 'jetonomy' ); ?></p>
		<p class="jt-settings-card__desc"><?php esc_html_e( 'Extend your community with the Wbcom stack. Each plugin works on its own — installing one here does not tie it to Jetonomy.', 'jetonomy' ); ?></p>
	</div>

	<?php if ( 'ok' === $jt_install_state ) : ?>
		<div class="notice notice-success is-dismissible" style="margin:0 0 16px;"><p><?php esc_html_e( 'Integration installed and activated.', 'jetonomy' ); ?></p></div>
	<?php elseif ( 'error' === $jt_install_state && '' !== $jt_install_msg ) : ?>
		<div class="notice notice-error is-dismissible" style="margin:0 0 16px;"><p><?php echo esc_html( $jt_install_msg ); ?></p></div>
	<?php endif; ?>

	<table class="form-table">
		<?php
		foreach ( $jt_companions as $jt_slug => $jt_c ) :
			$jt_status  = Companion_Registry::status( $jt_slug );
			$jt_label   = (string) ( $jt_c['label'] ?? $jt_slug );
			$jt_why     = (string) ( $jt_c['why'] ?? '' );
			$jt_unlocks = (string) ( $jt_c['unlocks'] ?? '' );

			if ( 'active' === $jt_status ) {
				$jt_badge_variant = 'active';
				$jt_badge_label   = __( 'Active', 'jetonomy' );
			} elseif ( 'installed_inactive' === $jt_status ) {
				$jt_badge_variant = 'pending';
				$jt_badge_label   = __( 'Inactive', 'jetonomy' );
			} else {
				$jt_badge_variant = 'archived';
				$jt_badge_label   = __( 'Not installed', 'jetonomy' );
			}
			?>
			<tr>
				<th scope="row">
					<?php echo esc_html( $jt_label ); ?><br>
					<span class="jt-status-badge jt-status-badge--<?php echo esc_attr( $jt_badge_variant ); ?>" style="margin-top:6px;"><?php echo esc_html( $jt_badge_label ); ?></span>
				</th>
				<td>
					<?php if ( '' !== $jt_why ) : ?>
						<p style="margin:0 0 6px;"><?php echo esc_html( $jt_why ); ?></p>
					<?php endif; ?>
					<?php if ( 'active' === $jt_status && '' !== $jt_unlocks ) : ?>
						<p class="description" style="margin:0 0 8px;"><?php echo esc_html( $jt_unlocks ); ?></p>
					<?php endif; ?>

					<?php if ( 'active' === $jt_status ) : ?>
						<button type="button" class="button" disabled><?php esc_html_e( 'Connected', 'jetonomy' ); ?></button>
					<?php elseif ( current_user_can( 'install_plugins' ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<input type="hidden" name="action" value="jetonomy_install_companion">
							<input type="hidden" name="companion" value="<?php echo esc_attr( $jt_slug ); ?>">
							<input type="hidden" name="tier" value="free">
							<?php wp_nonce_field( 'jetonomy_install_companion_' . $jt_slug ); ?>
							<button type="submit" class="button button-primary">
								<?php
								echo 'installed_inactive' === $jt_status
									? esc_html__( 'Activate', 'jetonomy' )
									: esc_html__( 'Install free', 'jetonomy' );
								?>
							</button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</table>
</div>
