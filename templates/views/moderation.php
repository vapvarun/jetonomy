<?php
defined( 'ABSPATH' ) || exit;

// Require moderator / admin access.
if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'manage_options' ) ) {
	status_header( 403 );
	echo '<div class="jt-container"><div class="jt-empty"><div class="jt-empty-icon">&#128274;</div><div class="jt-empty-text">' . esc_html__( 'You do not have permission to view this page.', 'jetonomy' ) . '</div></div></div>';
	return;
}

$flags        = \Jetonomy\Models\Flag::list_pending();
$base         = home_url( '/community' );
$nonce_base   = wp_create_nonce( 'jetonomy_moderation' );

$reason_labels = [
	'spam'        => __( 'Spam', 'jetonomy' ),
	'abuse'       => __( 'Abuse / Harassment', 'jetonomy' ),
	'off-topic'   => __( 'Off-topic', 'jetonomy' ),
	'misinformation' => __( 'Misinformation', 'jetonomy' ),
	'other'       => __( 'Other', 'jetonomy' ),
];

$crumbs = [
	[ 'label' => __( 'Moderation Queue', 'jetonomy' ), 'url' => '' ],
];
?>
<div class="jt-container">

	<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

	<div style="max-width:800px;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
			<h1 style="font-family:var(--jt-font-heading);font-size:22px;font-weight:700;margin:0;">
				<?php esc_html_e( 'Moderation Queue', 'jetonomy' ); ?>
			</h1>
			<?php if ( ! empty( $flags ) ) : ?>
				<span style="background:var(--jt-danger);color:white;font-size:12px;font-weight:700;padding:3px 10px;border-radius:var(--jt-radius-full);">
					<?php
					/* translators: %d: number of pending flags */
					echo esc_html( sprintf( _n( '%d pending', '%d pending', count( $flags ), 'jetonomy' ), count( $flags ) ) );
					?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( empty( $flags ) ) : ?>
			<div class="jt-empty">
				<div class="jt-empty-icon">&#127881;</div>
				<div class="jt-empty-text"><?php esc_html_e( 'No pending flags. The community is clean!', 'jetonomy' ); ?></div>
			</div>
		<?php else : ?>
			<div class="jt-card" style="padding:0;overflow:hidden;">
				<?php foreach ( $flags as $flag ) : ?>
					<?php
					$reporter = get_userdata( (int) $flag->flagged_by );
					$time_ago = human_time_diff( strtotime( $flag->created_at ), current_time( 'timestamp', true ) );
					$reason   = $reason_labels[ $flag->reason ] ?? $flag->reason;

					// Build link to the flagged object.
					$object_url = '';
					if ( 'post' === $flag->object_type ) {
						global $wpdb;
						$posts_tbl  = \Jetonomy\table( 'posts' );
						$spaces_tbl = \Jetonomy\table( 'spaces' );
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$row = $wpdb->get_row(
							$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								"SELECT p.slug, sp.slug AS space_slug
								 FROM {$posts_tbl} p
								 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
								 WHERE p.id = %d",
								(int) $flag->object_id
							)
						);
						if ( $row ) {
							$object_url = $base . '/s/' . $row->space_slug . '/t/' . $row->slug . '/';
						}
					} elseif ( 'reply' === $flag->object_type ) {
						global $wpdb;
						$replies_tbl = \Jetonomy\table( 'replies' );
						$posts_tbl   = \Jetonomy\table( 'posts' );
						$spaces_tbl  = \Jetonomy\table( 'spaces' );
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$row = $wpdb->get_row(
							$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								"SELECT p.slug AS post_slug, sp.slug AS space_slug
								 FROM {$replies_tbl} r
								 LEFT JOIN {$posts_tbl} p ON p.id = r.post_id
								 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
								 WHERE r.id = %d",
								(int) $flag->object_id
							)
						);
						if ( $row ) {
							$object_url = $base . '/s/' . $row->space_slug . '/t/' . $row->post_slug . '/';
						}
					}
					?>
					<div style="padding:16px;border-bottom:1px solid var(--jt-border);"
						data-wp-interactive="jetonomy">
						<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
							<span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:2px 8px;border-radius:var(--jt-radius-full);background:var(--jt-danger-light);color:var(--jt-danger);">
								<?php echo esc_html( ucfirst( $flag->object_type ) ); ?>
							</span>
							<span style="font-size:12px;font-weight:500;color:var(--jt-text-secondary);">
								<?php echo esc_html( $reason ); ?>
							</span>
							<span style="margin-left:auto;font-size:12px;color:var(--jt-text-tertiary);">
								<?php echo esc_html( $reporter ? $reporter->display_name : __( 'Unknown', 'jetonomy' ) ); ?>
								&mdash;
								<?php
								/* translators: %s: human-readable time difference */
								echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
								?>
							</span>
						</div>

						<?php if ( ! empty( $flag->note ) ) : ?>
							<p style="font-size:13px;color:var(--jt-text-secondary);margin-bottom:8px;padding:8px;background:var(--jt-bg-subtle);border-radius:var(--jt-radius-sm);">
								<?php echo esc_html( $flag->note ); ?>
							</p>
						<?php endif; ?>

						<div style="display:flex;gap:8px;align-items:center;">
							<?php if ( $object_url ) : ?>
								<a href="<?php echo esc_url( $object_url ); ?>" class="jt-btn jt-btn-ghost" target="_blank">
									<?php esc_html_e( 'View', 'jetonomy' ); ?>
								</a>
							<?php endif; ?>
							<button type="button" class="jt-btn jt-btn-fill"
								style="background:var(--jt-danger);"
								data-wp-on--click="actions.dismissFlag"
								data-flag-id="<?php echo (int) $flag->id; ?>"
								data-nonce="<?php echo esc_attr( $nonce_base ); ?>"
								data-action="approved">
								<?php esc_html_e( 'Remove Content', 'jetonomy' ); ?>
							</button>
							<button type="button" class="jt-btn jt-btn-ghost"
								data-wp-on--click="actions.dismissFlag"
								data-flag-id="<?php echo (int) $flag->id; ?>"
								data-nonce="<?php echo esc_attr( $nonce_base ); ?>"
								data-action="dismissed">
								<?php esc_html_e( 'Dismiss', 'jetonomy' ); ?>
							</button>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

</div>
