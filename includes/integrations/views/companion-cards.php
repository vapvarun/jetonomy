<?php
/**
 * Wbcom family header + companion cards (Integrations settings tab).
 *
 * A branded family showcase header followed by one logo card per
 * Companion_Registry entry: status badge (Connected / Installed activate /
 * Not installed) and the matching action (one-click free install, activate,
 * or store link). No data is created here - the screen reflects registry
 * status and triggers installs through Companion_Installer via admin-post.
 *
 * @package Jetonomy\Integrations
 * @since   1.5.0
 */

defined( 'ABSPATH' ) || exit;

use Jetonomy\Integrations\Companion_Registry;

$jt_companions  = Companion_Registry::all();
$jt_logo_base   = JETONOMY_URL . 'assets/img/companions/';

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only redirect-back status flags, no state change.
$jt_install_state = isset( $_GET['jt_install'] ) ? sanitize_key( wp_unslash( $_GET['jt_install'] ) ) : '';
$jt_install_msg   = isset( $_GET['jt_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['jt_msg'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>

<?php if ( 'ok' === $jt_install_state ) : ?>
	<div class="notice notice-success jt-companion-notice is-dismissible">
		<p><?php esc_html_e( 'Integration installed and activated.', 'jetonomy' ); ?></p>
	</div>
<?php elseif ( 'error' === $jt_install_state && '' !== $jt_install_msg ) : ?>
	<div class="notice notice-error jt-companion-notice is-dismissible">
		<p><?php echo esc_html( $jt_install_msg ); ?></p>
	</div>
<?php endif; ?>

<div class="jt-fam-header">
	<img class="jt-fam-header__mark"
		src="<?php echo esc_url( $jt_logo_base . 'wbcom.svg' ); ?>"
		alt="<?php esc_attr_e( 'Wbcom', 'jetonomy' ); ?>"
		width="52"
		height="52"
	/>
	<div class="jt-fam-header__body">
		<h2 class="jt-fam-header__title"><?php esc_html_e( 'Part of the Wbcom family', 'jetonomy' ); ?></h2>
		<p class="jt-fam-header__desc">
			<?php esc_html_e( 'Jetonomy is part of the Wbcom community stack: gamification, courses, messaging, listings, jobs, and more. Every plugin works on its own, and you can add any of them below in one click. The family keeps growing, so check back for new releases.', 'jetonomy' ); ?>
		</p>
		<a class="jt-fam-header__link"
			href="https://wbcomdesigns.com/downloads/"
			target="_blank"
			rel="noopener noreferrer">
			<?php esc_html_e( 'Explore the full Wbcom family', 'jetonomy' ); ?>
		</a>
	</div>
</div>

<div class="jt-companions-grid">
	<?php
	foreach ( $jt_companions as $jt_slug => $jt_c ) :
		$jt_status  = Companion_Registry::status( $jt_slug );
		$jt_label   = (string) ( $jt_c['label'] ?? $jt_slug );
		$jt_why     = (string) ( $jt_c['why'] ?? '' );
		$jt_unlocks = (string) ( $jt_c['unlocks'] ?? '' );
		$jt_store   = (string) ( $jt_c['store_url'] ?? '' );
		$jt_logo    = $jt_logo_base . sanitize_file_name( $jt_slug ) . '.svg';

		// Status badge variant + label.
		if ( 'active' === $jt_status ) {
			$jt_badge_class = 'jt-companion-badge jt-companion-badge--success';
			$jt_badge_label = __( 'Connected', 'jetonomy' );
		} elseif ( 'installed_inactive' === $jt_status ) {
			$jt_badge_class = 'jt-companion-badge jt-companion-badge--warning';
			$jt_badge_label = __( 'Installed, activate', 'jetonomy' );
		} else {
			$jt_badge_class = 'jt-companion-badge jt-companion-badge--muted';
			$jt_badge_label = __( 'Not installed', 'jetonomy' );
		}
		?>
		<div class="jt-companion-card">
			<div class="jt-companion-card__head">
				<img class="jt-companion-card__logo"
					src="<?php echo esc_url( $jt_logo ); ?>"
					alt="<?php echo esc_attr( $jt_label ); ?>"
					loading="lazy"
				/>
				<h3 class="jt-companion-card__title"><?php echo esc_html( $jt_label ); ?></h3>
				<span class="<?php echo esc_attr( $jt_badge_class ); ?>"><?php echo esc_html( $jt_badge_label ); ?></span>
			</div>

			<?php if ( '' !== $jt_why ) : ?>
				<p class="jt-companion-card__why"><?php echo esc_html( $jt_why ); ?></p>
			<?php endif; ?>

			<?php if ( 'active' === $jt_status && '' !== $jt_unlocks ) : ?>
				<p class="jt-companion-card__unlocks">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<?php echo esc_html( $jt_unlocks ); ?>
				</p>
			<?php endif; ?>

			<div class="jt-companion-card__actions">
				<?php if ( 'active' === $jt_status ) : ?>
					<span class="button jt-companion-btn--connected" aria-disabled="true">
						<span class="dashicons dashicons-yes" aria-hidden="true"></span>
						<?php esc_html_e( 'Connected', 'jetonomy' ); ?>
					</span>
				<?php elseif ( current_user_can( 'install_plugins' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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

				<?php if ( '' !== $jt_store ) : ?>
					<a href="<?php echo esc_url( $jt_store ); ?>"
						target="_blank"
						rel="noopener noreferrer"
						class="button jt-companion-btn--learn-more">
						<?php esc_html_e( 'Learn more', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>

<p class="description jt-companions-foot">
	<?php esc_html_e( 'Every product above is a standalone Wbcom plugin. Jetonomy detects each one and lights up the matching features automatically when it is active.', 'jetonomy' ); ?>
</p>
