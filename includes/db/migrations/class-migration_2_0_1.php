<?php
/**
 * Migration 2.0.1 - categories nest two levels deep.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;

/**
 * Brings existing category rows inside the two-level rule 2.0.1 enforces.
 *
 * Before 2.0.1 nothing validated parent_id, so a site can hold a third level
 * (REST, or a bbPress import of nested container forums), a category whose
 * parent was deleted, or a cycle. The new directory, pickers and breadcrumbs
 * read exactly two levels, so those rows would silently drop out of view.
 * Each one is re-parented to its top-level ancestor, or made top-level when
 * it has none. No category, space or topic is deleted.
 */
class Migration_2_0_1 {

	/**
	 * Re-parent out-of-rule categories. Idempotent: a second run finds nothing.
	 *
	 * @return void
	 */
	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'jt_categories';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-off migration over a small table.
		$rows = $wpdb->get_results( "SELECT id, parent_id FROM {$table} WHERE parent_id > 0" ) ?: [];

		$changed = false;
		foreach ( $rows as $row ) {
			$parent = (int) $row->parent_id;
			$root   = Category::top_level_ancestor( $parent );
			$target = $root === (int) $row->id ? 0 : $root;
			if ( $target !== $parent ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $table, [ 'parent_id' => $target ], [ 'id' => (int) $row->id ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			// The category tree cache holds row copies; retire it with the rows.
			Space::bump_tree_generation();
		}
	}
}
