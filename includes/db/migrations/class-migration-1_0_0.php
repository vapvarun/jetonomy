<?php
namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_0_0 {

	/**
	 * Run the migration: create all base tables.
	 */
	public function up(): void {
		require_once JETONOMY_DIR . 'includes/db/class-schema.php';
		\Jetonomy\DB\Schema::create_tables();
	}
}
