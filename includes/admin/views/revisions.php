<?php
/**
 * Admin view: Revisions
 *
 * Read-only browser over jt_revisions. Two modes, branched on URL:
 *
 *   1. List mode (default, ?page=jetonomy-revisions)
 *      Aggregate WP_List_Table of (object_type, object_id) pairs that
 *      have ≥1 revision; the title links into detail mode.
 *
 *   2. Detail mode (?page=jetonomy-revisions&object_type=post&object_id=123)
 *      Per-object revision list (newest → oldest), with a per-row
 *      "View diff" pane comparing each snapshot against its predecessor
 *      using WP core wp_text_diff().
 *
 * Capability gate is performed by Admin::render_revisions_page() before
 * including this file — direct includes are blocked by the ABSPATH guard.
 *
 * Received variables:
 *   $mode          — 'list' | 'detail'
 *   $list_table    — Revisions_List_Table (only set when $mode === 'list')
 *   $object_type   — 'post' | 'reply'   (only set when $mode === 'detail')
 *   $object_id     — int                 (only set when $mode === 'detail')
 *   $object_title  — string              (only set when $mode === 'detail')
 *   $revisions     — object[]            (only set when $mode === 'detail')
 *   $back_url      — string admin URL    (only set when $mode === 'detail')
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

/** @var string $mode */
$mode = isset( $mode ) ? (string) $mode : 'list';

if ( 'detail' === $mode ) :
	/** @var string $object_type */
	/** @var int $object_id */
	/** @var string $object_title */
	/** @var array<int, object> $revisions */
	/** @var string $back_url */
	?>
	<div class="wrap jetonomy-admin">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Revisions', 'jetonomy' ); ?></h1>
		<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
			<?php esc_html_e( '&larr; Back to all revisions', 'jetonomy' ); ?>
		</a>
		<hr class="wp-header-end" />

		<h2 class="title">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: object type label, 2: object id, 3: title */
					__( '%1$s #%2$d: %3$s', 'jetonomy' ),
					ucfirst( $object_type ),
					$object_id,
					$object_title !== '' ? $object_title : __( '(no title)', 'jetonomy' )
				)
			);
			?>
		</h2>

		<p class="description">
			<?php
			printf(
				/* translators: %d: number of revisions */
				esc_html( _n( '%d revision recorded.', '%d revisions recorded.', count( $revisions ), 'jetonomy' ) ),
				(int) count( $revisions )
			);
			?>
			<?php esc_html_e( 'Each row compares against the previous snapshot. The oldest entry has no diff.', 'jetonomy' ); ?>
		</p>

		<?php if ( empty( $revisions ) ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'No revisions have been recorded for this object yet.', 'jetonomy' ); ?></p>
			</div>
		<?php else : ?>
			<table class="widefat striped jt-revisions-detail">
				<thead>
					<tr>
						<th scope="col" style="width: 22%;"><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
						<th scope="col" style="width: 22%;"><?php esc_html_e( 'Edited By', 'jetonomy' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Edit Summary', 'jetonomy' ); ?></th>
						<th scope="col" style="width: 12%;"><?php esc_html_e( 'Diff', 'jetonomy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					// Revisions arrive newest-first. Each row diffs against the
					// snapshot that came BEFORE it chronologically (i.e. the
					// next index in the array). The last entry has no
					// predecessor — its diff cell shows the original copy.
					$total = count( $revisions );
					foreach ( $revisions as $idx => $rev ) :
						$rev_id    = (int) ( $rev->id ?? 0 );
						$author_id = (int) ( $rev->author_id ?? 0 );
						$author    = $author_id > 0 ? get_userdata( $author_id ) : null;
						$created   = (string) ( $rev->created_at ?? '' );
						$summary   = (string) ( $rev->edit_summary ?? '' );
						$has_prev  = $idx < ( $total - 1 );
						$prev_rev  = $has_prev ? $revisions[ $idx + 1 ] : null;
						$diff_id   = 'jt-rev-diff-' . $rev_id;

						$prev_title   = $has_prev ? (string) ( $prev_rev->title ?? '' ) : '';
						$prev_content = $has_prev ? (string) ( $prev_rev->content ?? '' ) : '';
						$curr_title   = (string) ( $rev->title ?? '' );
						$curr_content = (string) ( $rev->content ?? '' );

						// Compose left/right copies that include both the
						// title (if any) and the content — wp_text_diff
						// processes them as one block per side, which is
						// exactly the layout admins want.
						$left_text  = trim( $prev_title . "\n\n" . $prev_content );
						$right_text = trim( $curr_title . "\n\n" . $curr_content );
						?>
						<tr>
							<td>
								<?php
								if ( '' === $created || '0000-00-00 00:00:00' === $created ) {
									echo '&mdash;';
								} else {
									$ts = strtotime( $created );
									if ( false === $ts ) {
										echo esc_html( $created );
									} else {
										printf(
											'<span title="%1$s">%2$s</span>',
											esc_attr( $created ),
											esc_html(
												sprintf(
													/* translators: %s: human-readable time difference */
													__( '%s ago', 'jetonomy' ),
													human_time_diff( $ts, time() )
												)
											)
										);
									}
								}
								?>
							</td>
							<td>
								<?php
								if ( $author ) {
									echo esc_html( $author->display_name ?: $author->user_login );
								} elseif ( $author_id > 0 ) {
									printf(
										/* translators: %d: deleted user id */
										esc_html__( 'Deleted user (#%d)', 'jetonomy' ),
										$author_id
									);
								} else {
									echo '<em>' . esc_html__( 'Unknown', 'jetonomy' ) . '</em>';
								}
								?>
							</td>
							<td>
								<?php
								if ( '' === $summary ) {
									echo '&mdash;';
								} else {
									echo esc_html( wp_trim_words( $summary, 30, '…' ) );
								}
								?>
							</td>
							<td>
								<?php if ( $has_prev ) : ?>
									<button
										type="button"
										class="button button-secondary jt-rev-diff-toggle"
										aria-expanded="false"
										aria-controls="<?php echo esc_attr( $diff_id ); ?>"
										data-target="<?php echo esc_attr( $diff_id ); ?>"
									>
										<?php esc_html_e( 'View diff', 'jetonomy' ); ?>
									</button>
								<?php else : ?>
									<em class="description"><?php esc_html_e( 'Original', 'jetonomy' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( $has_prev ) : ?>
							<tr id="<?php echo esc_attr( $diff_id ); ?>" class="jt-rev-diff-row" hidden>
								<td colspan="4">
									<?php
									$diff_html = wp_text_diff(
										$left_text,
										$right_text,
										array(
											'show_split_view' => true,
											'title_left'  => __( 'Previous', 'jetonomy' ),
											'title_right' => __( 'This revision', 'jetonomy' ),
										)
									);
									if ( ! $diff_html ) {
										?>
										<p class="description">
											<?php esc_html_e( 'No textual difference between this revision and the previous one.', 'jetonomy' ); ?>
										</p>
										<?php
									} else {
										// wp_text_diff returns admin-safe HTML built from a
										// known table template; echo it directly so the
										// diff styling renders. Esc would mangle the markup.
										echo $diff_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									}
									?>
								</td>
							</tr>
						<?php endif; ?>
					<?php endforeach; ?>
				</tbody>
			</table>

		<?php endif; ?>
	</div>
	<?php
else :
	/** @var \Jetonomy\Admin\Revisions_List_Table $list_table */
	?>
	<div class="wrap jetonomy-admin">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Revisions', 'jetonomy' ); ?></h1>
		<hr class="wp-header-end" />

		<p class="description">
			<?php esc_html_e( 'Audit trail of post and reply edits. Click a title to see the full revision history with diffs.', 'jetonomy' ); ?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="jetonomy-revisions" />
			<?php
			$list_table->render_filters();
			$list_table->display();
			?>
		</form>
	</div>
	<?php
endif;
