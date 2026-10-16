<?php
/**
 * Admin view: Activity Log
 *
 * Read-only browser over the jt_activity_log table. Filters and pagination
 * are handled inside the WP_List_Table; this view is just the page chrome
 * and the CSV export button.
 *
 * Received variables:
 *   $list_table — Activity_List_Table instance with prepare_items() already called.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

/** @var \Jetonomy\Admin\Activity_List_Table $list_table */
$page_url = admin_url( 'admin.php?page=jetonomy-activity' );

// Preserve the active filter set when the admin clicks Export CSV — the
// download endpoint reads the same $_GET keys via Activity_List_Table::read_filters().
$filters    = \Jetonomy\Admin\Activity_List_Table::read_filters();
$export_url = wp_nonce_url(
	add_query_arg(
		array_filter(
			array(
				'page'        => 'jetonomy-activity',
				'action'      => 'export_csv',
				'user_id'     => $filters['user_id'] ?: null,
				'action_type' => $filters['action'] ?: null,
				'date_from'   => $filters['date_from'] ?: null,
				'date_to'     => $filters['date_to'] ?: null,
			)
		),
		admin_url( 'admin.php' )
	),
	'jetonomy_activity_export'
);
?>
<div class="wrap jetonomy-admin">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Activity Log', 'jetonomy' ); ?></h1>
	<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">
		<?php esc_html_e( 'Export CSV', 'jetonomy' ); ?>
	</a>
	<hr class="wp-header-end" />

	<p class="description">
		<?php
		$jt_retention = (int) ( get_option( 'jetonomy_settings', array() )['activity_log_retention_days'] ?? 90 );
		esc_html_e( 'Everything that happens in your community, newest first.', 'jetonomy' );
		echo ' ';
		echo esc_html(
			$jt_retention > 0
				/* translators: %s: number of days. */
				? sprintf( _n( 'Entries older than %s day are deleted automatically.', 'Entries older than %s days are deleted automatically.', $jt_retention, 'jetonomy' ), number_format_i18n( $jt_retention ) )
				: __( 'Entries are kept until you delete them.', 'jetonomy' )
		);
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-settings&tab=general#jt-activity-log-settings' ) ); ?>"><?php esc_html_e( 'Change how long entries are kept', 'jetonomy' ); ?></a>
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="jetonomy-activity" />
		<div class="jt-content-table-wrap">
			<?php
			$list_table->render_filters();
			$list_table->display();
			?>
		</div>
	</form>
</div>
