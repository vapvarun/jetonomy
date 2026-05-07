<?php
/**
 * Admin import view.
 *
 * Variables seeded by Admin::render_import() before include.
 *
 * @var array<string,bool> $available
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$import_history   = get_option( 'jetonomy_import_history', [] );
$resume_state     = get_option( 'jetonomy_import_resume', [] );
$current_progress = \Jetonomy\Import\Importer::get_progress();
$datetime_format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Import Forums', 'jetonomy' ); ?></h1>

	<div class="notice notice-warning">
		<p><strong><?php esc_html_e( 'Important:', 'jetonomy' ); ?></strong>
		<?php esc_html_e( 'Back up your database before importing. This process creates new records and cannot be automatically reversed.', 'jetonomy' ); ?></p>
	</div>

	<?php if ( empty( $available ) && empty( $resume_state ) ) : ?>
		<!-- No Sources -->
		<div class="jetonomy-empty-state">
			<span class="dashicons dashicons-database-import"></span>
			<h2><?php esc_html_e( 'No Forum Data Detected', 'jetonomy' ); ?></h2>
			<p><?php esc_html_e( 'Jetonomy can import from bbPress, wpForo, and Asgaros. Install one of these plugins and create some content, then come back here to import.', 'jetonomy' ); ?></p>
			<div class="jetonomy-import-sources">
				<div class="jetonomy-import-source jetonomy-import-source--unavailable" data-source="bbpress">
					<h3><?php esc_html_e( 'bbPress', 'jetonomy' ); ?></h3>
					<p><?php esc_html_e( 'Not detected', 'jetonomy' ); ?></p>
					<button type="button" class="button jetonomy-import-btn" data-source="bbpress" disabled><?php esc_html_e( 'Import from bbPress', 'jetonomy' ); ?></button>
				</div>
				<div class="jetonomy-import-source jetonomy-import-source--unavailable" data-source="wpforo">
					<h3><?php esc_html_e( 'wpForo', 'jetonomy' ); ?></h3>
					<p><?php esc_html_e( 'Not detected', 'jetonomy' ); ?></p>
					<button type="button" class="button jetonomy-import-btn" data-source="wpforo" disabled><?php esc_html_e( 'Import from wpForo', 'jetonomy' ); ?></button>
				</div>
				<div class="jetonomy-import-source jetonomy-import-source--unavailable" data-source="asgaros">
					<h3><?php esc_html_e( 'Asgaros', 'jetonomy' ); ?></h3>
					<p><?php esc_html_e( 'Not detected', 'jetonomy' ); ?></p>
					<button type="button" class="button jetonomy-import-btn" data-source="asgaros" disabled><?php esc_html_e( 'Import from Asgaros', 'jetonomy' ); ?></button>
				</div>
			</div>
		</div>
	<?php else : ?>
		<!-- Available Sources -->
		<div class="jetonomy-import-sources">
			<?php
			foreach ( $available as $id => $info ) :
				$was_imported = isset( $import_history[ $id ] );
				$has_resume   = ! empty( $resume_state ) && ( $resume_state['source'] ?? '' ) === $id;
				$is_running   = ( $current_progress['status'] ?? '' ) === 'running';
				?>
				<div class="jetonomy-import-source" id="import-source-<?php echo esc_attr( $id ); ?>"
					data-source="<?php echo esc_attr( $id ); ?>"
					data-was-imported="<?php echo esc_attr( $was_imported ? '1' : '0' ); ?>"
					data-has-resume="<?php echo esc_attr( $has_resume ? '1' : '0' ); ?>"
					data-resume-phase="<?php echo esc_attr( $resume_state['phase'] ?? '' ); ?>"
					data-resume-offset="<?php echo absint( $resume_state['offset'] ?? 0 ); ?>">

					<div class="jetonomy-import-source__header">
						<h2><?php echo esc_html( $info['name'] ); ?></h2>
						<?php if ( $was_imported ) : ?>
							<span class="jetonomy-badge jetonomy-badge--success">&#10003; <?php esc_html_e( 'Previously Imported', 'jetonomy' ); ?></span>
						<?php elseif ( $has_resume ) : ?>
							<span class="jetonomy-badge jetonomy-badge--warning">&#9888; <?php esc_html_e( 'Import Interrupted', 'jetonomy' ); ?></span>
						<?php else : ?>
							<span class="jetonomy-badge jetonomy-badge--info"><?php esc_html_e( 'Available', 'jetonomy' ); ?></span>
						<?php endif; ?>
					</div>

					<?php if ( $was_imported ) : ?>
						<div class="jetonomy-import-history">
							<p>
								<?php
								printf(
									/* translators: %s: date and time of last import */
									esc_html__( 'Last imported: %s', 'jetonomy' ),
									esc_html(
										date_i18n(
											$datetime_format,
											strtotime( $import_history[ $id ]['completed_at'] )
										)
									)
								);
								?>
								&mdash;
								<?php
								printf(
									/* translators: %s: number of records imported */
									esc_html__( '%s records imported', 'jetonomy' ),
									esc_html( number_format_i18n( $import_history[ $id ]['imported'] ) )
								);
								?>
							</p>
							<p class="description">
								<strong><?php esc_html_e( 'Warning:', 'jetonomy' ); ?></strong>
								<?php esc_html_e( 'Re-importing may create duplicate content. Only re-import if the previous import had issues.', 'jetonomy' ); ?>
							</p>
						</div>
					<?php endif; ?>

					<?php if ( $has_resume ) : ?>
						<div class="jetonomy-import-resume-info">
							<p>
								<?php
								printf(
									/* translators: 1: phase name, 2: offset number, 3: start date/time */
									esc_html__( 'Import was interrupted at phase: %1$s (offset: %2$s). Started: %3$s', 'jetonomy' ),
									esc_html( $resume_state['phase'] ?? '' ),
									esc_html( number_format_i18n( $resume_state['offset'] ?? 0 ) ),
									esc_html(
										date_i18n(
											$datetime_format,
											strtotime( $resume_state['started_at'] ?? '' )
										)
									)
								);
								?>
							</p>
						</div>
					<?php endif; ?>

					<!-- Stats -->
					<div class="jetonomy-import-stats">
						<?php foreach ( $info['stats'] as $key => $val ) : ?>
							<div class="jetonomy-import-stat">
								<span class="jetonomy-import-stat__value"><?php echo esc_html( number_format_i18n( $val ) ); ?></span>
								<span class="jetonomy-import-stat__label"><?php echo esc_html( ucfirst( $key ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>

					<!-- Action Buttons -->
					<div class="jetonomy-import-action">
						<?php if ( $has_resume ) : ?>
							<button type="button" class="button button-primary jetonomy-import-resume-btn"
								data-source="<?php echo esc_attr( $id ); ?>"
								data-phase="<?php echo esc_attr( $resume_state['phase'] ?? 'forums' ); ?>"
								data-offset="<?php echo absint( $resume_state['offset'] ?? 0 ); ?>">
								<?php esc_html_e( 'Resume Import', 'jetonomy' ); ?>
							</button>
							<button type="button" class="button jetonomy-import-restart-btn"
								data-source="<?php echo esc_attr( $id ); ?>">
								<?php esc_html_e( 'Start Over', 'jetonomy' ); ?>
							</button>
						<?php elseif ( $was_imported ) : ?>
							<button type="button" class="button jetonomy-import-btn jetonomy-import-btn--reimport"
								data-source="<?php echo esc_attr( $id ); ?>"
								data-jt-confirm="<?php esc_attr_e( 'Re-importing may create duplicates. Are you sure?', 'jetonomy' ); ?>"
								data-jt-confirm-tone="warning"
								data-jt-confirm-handler="dispatch-click">
								<?php esc_html_e( 'Re-Import', 'jetonomy' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="button button-primary button-hero jetonomy-import-btn"
								data-source="<?php echo esc_attr( $id ); ?>">
								<?php
								/* translators: %s: source name */
								printf( esc_html__( 'Import from %s', 'jetonomy' ), esc_html( $info['name'] ) );
								?>
							</button>
						<?php endif; ?>
					</div>

					<!-- Progress (hidden by default) -->
					<div class="jetonomy-import-progress" data-source="<?php echo esc_attr( $id ); ?>" style="display:none;">
						<div class="jetonomy-import-steps">
							<span class="jetonomy-step" data-step="forums"><?php esc_html_e( 'Forums', 'jetonomy' ); ?></span>
							<span class="jetonomy-step-arrow">&rarr;</span>
							<span class="jetonomy-step" data-step="topics"><?php esc_html_e( 'Topics', 'jetonomy' ); ?></span>
							<span class="jetonomy-step-arrow">&rarr;</span>
							<span class="jetonomy-step" data-step="replies"><?php esc_html_e( 'Replies', 'jetonomy' ); ?></span>
							<span class="jetonomy-step-arrow">&rarr;</span>
							<span class="jetonomy-step" data-step="profiles"><?php esc_html_e( 'Profiles', 'jetonomy' ); ?></span>
							<span class="jetonomy-step-arrow">&rarr;</span>
							<span class="jetonomy-step" data-step="recount"><?php esc_html_e( 'Finalize', 'jetonomy' ); ?></span>
						</div>
						<div class="jetonomy-progress-bar">
							<div class="jetonomy-progress-bar__fill"></div>
						</div>
						<div class="jetonomy-import-status">
							<span class="jetonomy-import-status-text"><?php esc_html_e( 'Starting...', 'jetonomy' ); ?></span>
							<span class="jetonomy-import-status-percent">0%</span>
						</div>
					</div>

					<!-- Results (hidden by default) -->
					<div class="jetonomy-import-results" data-source="<?php echo esc_attr( $id ); ?>" style="display:none;"></div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
<div class="jt-pro-upsell">
	<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
	<h4><?php esc_html_e( 'Private Messaging & Email Digests', 'jetonomy' ); ?></h4>
	<p><?php esc_html_e( 'Keep members engaged after migration with private messaging, daily/weekly email digests, and web push notifications.', 'jetonomy' ); ?></p>
	<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
	&nbsp;
	<a href="https://store.wbcomdesigns.com/jetonomy/docs/" class="button button-link" target="_blank"><?php esc_html_e( 'View Docs', 'jetonomy' ); ?></a>
</div>
<?php endif; ?>
