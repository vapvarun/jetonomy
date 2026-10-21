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
 *   $revisions     — object[]            (one page of snapshots plus the next-older one; only set when $mode === 'detail')
 *   $rev_total     — int                 (all snapshots of the object)
 *   $rev_page      — int                 (current page)
 *   $rev_per       — int                 (snapshots per page)
 *   $back_url      — string admin URL    (only set when $mode === 'detail')
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

/** @var string $mode */
/** @var int $rev_page */
/** @var int $rev_per */
/** @var int $rev_total */
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
			// The source is gone when no live title resolves: name it from the
			// newest stored snapshot instead of an id.
			echo esc_html(
				'' !== $object_title
					? $object_title
					: \Jetonomy\Admin\Revisions_List_Table::deleted_title( $object_type, $revisions ? \Jetonomy\Admin\Revisions_List_Table::stored_title( $revisions[0] ) : '' )
			);
			?>
		</h2>

		<p class="description">
			<?php
			printf(
				/* translators: %d: number of revisions */
				esc_html( _n( '%d revision recorded.', '%d revisions recorded.', $rev_total, 'jetonomy' ) ),
				(int) $rev_total
			);
			?>
			<?php esc_html_e( 'Each row compares against the previous snapshot. The current version is the top row of the first page while the item exists; the oldest entry has no diff.', 'jetonomy' ); ?>
		</p>

			<?php
			// Revisions arrive newest-first. Each row diffs against the snapshot
			// that came BEFORE it chronologically (i.e. the next index), so the
			// last entry has no predecessor and shows "Original" instead of a
			// toggle. Precomputed here so the cell and after_row callbacks agree
			// on which rows have a diff without recomputing the lookup twice.
			// A snapshot holds the content BEFORE an edit, so the latest edit only
			// shows as live content. Put the live version on top so it diffs
			// against the newest snapshot (a single edit gets its diff too).
			$jt_live = $revisions ? ( 'post' === $object_type ? \Jetonomy\Models\Post::find( (int) $object_id ) : \Jetonomy\Models\Reply::find( (int) $object_id ) ) : null;
			if ( $jt_live && 1 === $rev_page ) {
				array_unshift(
					$revisions,
					(object) array(
						'id'           => 0,
						'object_type'  => $object_type,
						'object_id'    => (int) $object_id,
						'author_id'    => (int) ( $jt_live->edited_by ?? 0 ),
						'created_at'   => (string) ( $jt_live->edited_at ?? '' ),
						'edit_summary' => __( 'Current version', 'jetonomy' ),
						'title'        => (string) ( $jt_live->title ?? '' ),
						'content'      => (string) ( $jt_live->content ?? '' ),
					)
				);
			}
			$jt_objects = \Jetonomy\Author::objects_of( $revisions );
			// The extra older snapshot is only a diff base, not a row.
			$jt_rows  = array_slice( $revisions, 0, $rev_per + ( $jt_live && 1 === $rev_page ? 1 : 0 ) );
			$jt_pages = (int) ceil( $rev_total / $rev_per );
			$jt_total = count( $revisions );
			$jt_prev  = static function ( $rev ) use ( $revisions, $jt_total ) {
				$idx = array_search( $rev, $revisions, true );
				return ( false !== $idx && $idx < ( $jt_total - 1 ) ) ? $revisions[ $idx + 1 ] : null;
			};

			jetonomy_admin_table(
				array(
					'rows'      => $jt_rows,
					'class'     => 'jt-revisions-detail',
					'columns'   => array(
						'date'    => array(
							'label'   => __( 'Date', 'jetonomy' ),
							'primary' => true,
							'width'   => 'm',
						),
						'author'  => array(
							'label' => __( 'Edited By', 'jetonomy' ),
							'width' => 'm',
						),
						'summary' => array( 'label' => __( 'Edit Summary', 'jetonomy' ) ),
						'diff'    => array(
							'label' => __( 'Diff', 'jetonomy' ),
							'width' => 's',
						),
					),
					'empty'     => array(
						'icon'  => 'backup',
						'title' => __( 'No revisions have been recorded here yet.', 'jetonomy' ),
					),
					'cell'      => function ( $rev, $col ) use ( $jt_prev, $jt_objects ) {
						switch ( $col ) {
							case 'date':
								$created = (string) ( $rev->created_at ?? '' );
								if ( '' === $created || '0000-00-00 00:00:00' === $created ) {
									echo '—';
									break;
								}
								$ts = strtotime( $created );
								if ( false === $ts ) {
									echo esc_html( $created );
									break;
								}
								printf(
									'<span title="%1$s">%2$s</span>',
									esc_attr( $created ),
									esc_html(
										sprintf(
											/* translators: %s: human-readable time difference. */
											__( '%s ago', 'jetonomy' ),
											human_time_diff( $ts, time() )
										)
									)
								);
								break;

							case 'author':
								$author_id = (int) ( $rev->author_id ?? 0 );
								$author    = $author_id > 0 ? get_userdata( $author_id ) : null;
								if ( \Jetonomy\Author::hides_actor( $author_id, $jt_objects[ $rev->object_type . ':' . (int) $rev->object_id ] ?? null ) ) {
									echo '<em>' . esc_html__( 'Anonymous', 'jetonomy' ) . '</em>';
								} elseif ( $author ) {
									echo esc_html( $author->display_name ?: $author->user_login );
								} elseif ( $author_id > 0 ) {
									printf(
										/* translators: %d: deleted user id */
										esc_html__( 'Deleted user (#%d)', 'jetonomy' ),
										$author_id
									);
								} else {
									echo \Jetonomy\Admin\Revisions_List_Table::editor_not_recorded(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
								}
								break;

							case 'summary':
								$summary = (string) ( $rev->edit_summary ?? '' );
								echo '' === $summary ? '—' : esc_html( wp_trim_words( $summary, 30, '…' ) );
								break;

							case 'diff':
								if ( ! $jt_prev( $rev ) ) {
									echo '<em class="description">' . esc_html__( 'Original', 'jetonomy' ) . '</em>';
									break;
								}
								$diff_id = 'jt-rev-diff-' . (int) ( $rev->id ?? 0 );
								?>
								<button
									type="button"
									class="button button-secondary jt-rev-diff-toggle"
									aria-expanded="false"
									aria-controls="<?php echo esc_attr( $diff_id ); ?>"
									data-target="<?php echo esc_attr( $diff_id ); ?>"
								>
									<?php esc_html_e( 'View diff', 'jetonomy' ); ?>
								</button>
								<?php
								break;
						}
					},
					'after_row' => function ( $rev, $colspan ) use ( $jt_prev ) {
						$prev_rev = $jt_prev( $rev );
						if ( ! $prev_rev ) {
							return;
						}
						$diff_id = 'jt-rev-diff-' . (int) ( $rev->id ?? 0 );

						// Compose left/right copies that include both the title
						// (if any) and the content — wp_text_diff processes them
						// as one block per side, which is the layout admins want.
						$left_text  = trim( (string) ( $prev_rev->title ?? '' ) . "\n\n" . (string) ( $prev_rev->content ?? '' ) );
						$right_text = trim( (string) ( $rev->title ?? '' ) . "\n\n" . (string) ( $rev->content ?? '' ) );
						?>
						<tr id="<?php echo esc_attr( $diff_id ); ?>" class="jt-rev-diff-row" hidden>
							<td colspan="<?php echo absint( $colspan ); ?>">
								<?php
								$diff_html = wp_text_diff(
									$left_text,
									$right_text,
									array(
										'show_split_view' => true,
										'title_left'      => __( 'Previous', 'jetonomy' ),
										'title_right'     => __( 'This revision', 'jetonomy' ),
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
									// known table template; echo it directly so the diff
									// styling renders. Esc would mangle the markup.
									echo $diff_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								?>
							</td>
						</tr>
						<?php
					},
				)
			);
	if ( $jt_pages > 1 ) :
		?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $rev_page,
							'total'     => $jt_pages,
							'prev_text' => '&lsaquo;',
							'next_text' => '&rsaquo;',
						)
					)
				);
				?>
					</div>
				</div>
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
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: topic label, lowercase (e.g. "topic"). 2: reply label, lowercase (e.g. "reply"). */
					__( 'Every edit to a %1$s or %2$s, newest first. Click a title to see each version and what changed.', 'jetonomy' ),
					\Jetonomy\jetonomy_label( 'topic', false, true ),
					\Jetonomy\jetonomy_label( 'reply', false, true )
				)
			);
			?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="jetonomy-revisions" />
			<div class="jt-content-table-wrap">
				<?php
				$list_table->render_filters();
				$list_table->display();
				?>
			</div>
		</form>
	</div>
	<?php
endif;
