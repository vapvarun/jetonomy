<?php
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Import Forums', 'jetonomy' ); ?></h1>

	<div class="notice notice-warning">
		<p><strong><?php esc_html_e( 'Important:', 'jetonomy' ); ?></strong> <?php esc_html_e( 'Back up your database before importing. This process creates new records and cannot be automatically reversed.', 'jetonomy' ); ?></p>
	</div>

	<?php if ( empty( $available ) ) : ?>
		<!-- No Sources -->
		<div class="jetonomy-empty-state">
			<span class="dashicons dashicons-database-import"></span>
			<h2><?php esc_html_e( 'No Forum Data Detected', 'jetonomy' ); ?></h2>
			<p><?php esc_html_e( 'Jetonomy can import from bbPress and wpForo. Install one of these plugins and create some content, then come back here to import.', 'jetonomy' ); ?></p>
			<div class="jetonomy-import-sources">
				<div class="jetonomy-import-source jetonomy-import-source--unavailable">
					<h3><?php esc_html_e( 'bbPress', 'jetonomy' ); ?></h3>
					<p><?php esc_html_e( 'Not detected', 'jetonomy' ); ?></p>
				</div>
				<div class="jetonomy-import-source jetonomy-import-source--unavailable">
					<h3><?php esc_html_e( 'wpForo', 'jetonomy' ); ?></h3>
					<p><?php esc_html_e( 'Not detected', 'jetonomy' ); ?></p>
				</div>
			</div>
		</div>
	<?php else : ?>
		<!-- Available Sources -->
		<div class="jetonomy-import-sources">
			<?php foreach ( $available as $id => $info ) : ?>
				<div class="jetonomy-import-source" id="import-source-<?php echo esc_attr( $id ); ?>">
					<div class="jetonomy-import-source__header">
						<h2><?php echo esc_html( $info['name'] ); ?></h2>
						<span class="jetonomy-badge jetonomy-badge--public"><?php esc_html_e( 'Available', 'jetonomy' ); ?></span>
					</div>

					<!-- Stats -->
					<div class="jetonomy-import-stats">
						<?php foreach ( $info['stats'] as $key => $val ) : ?>
							<div class="jetonomy-import-stat">
								<span class="jetonomy-import-stat__value"><?php echo esc_html( number_format_i18n( $val ) ); ?></span>
								<span class="jetonomy-import-stat__label"><?php echo esc_html( ucfirst( $key ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>

					<!-- Import Button -->
					<div class="jetonomy-import-action">
						<button type="button" class="button button-primary button-hero jetonomy-import-btn" data-source="<?php echo esc_attr( $id ); ?>">
							<?php
							/* translators: %s: source name */
							printf( esc_html__( 'Import from %s', 'jetonomy' ), esc_html( $info['name'] ) );
							?>
						</button>
					</div>

					<!-- Progress -->
					<div class="jetonomy-import-progress" data-source="<?php echo esc_attr( $id ); ?>" style="display:none;">
						<div class="jetonomy-progress-bar">
							<div class="jetonomy-progress-bar__fill"></div>
						</div>
						<p class="jetonomy-import-status-text"><?php esc_html_e( 'Starting import...', 'jetonomy' ); ?></p>
					</div>

					<!-- Results -->
					<div class="jetonomy-import-results" data-source="<?php echo esc_attr( $id ); ?>" style="display:none;">
						<div class="jetonomy-import-results__summary"></div>
						<div class="jetonomy-import-results__errors" style="display:none;">
							<h4><?php esc_html_e( 'Errors:', 'jetonomy' ); ?></h4>
							<ul></ul>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
