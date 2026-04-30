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
		<?php esc_html_e( 'Audit trail of community events. Read-only. Adjust retention via the daily prune cron.', 'jetonomy' ); ?>
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="jetonomy-activity" />
		<?php
		$list_table->render_filters();
		$list_table->display();
		?>
	</form>
</div>
