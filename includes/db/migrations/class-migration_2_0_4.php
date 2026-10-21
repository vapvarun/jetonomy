<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * 2.0.4: sub-spaces whose parent was deleted move to the top level.
 *
 * Until 2.0.4 deleting a space left its sub-spaces with a parent_id pointing at
 * a row that no longer exists; Space_Purge now moves them up to the deleted
 * space's own parent. The parent they had is gone, so existing orphans go to
 * the top level.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_0_4 {

	public function up(): void {
		global $wpdb;
		$spaces = $wpdb->prefix . 'jt_spaces';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$spaces} s LEFT JOIN {$spaces} p ON p.id = s.parent_id SET s.parent_id = 0 WHERE s.parent_id > 0 AND p.id IS NULL" );
		\Jetonomy\Cache::flush();
	}
}
