<?php
/**
 * Database migrator.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB;

defined( 'ABSPATH' ) || exit;

class Migrator {

	/**
	 * Run any pending migrations.
	 *
	 * @param string $from_version The currently installed DB version.
	 */
	public static function run( string $from_version ): void {
		$migrations = self::get_migrations();

		foreach ( $migrations as $version => $class ) {
			if ( version_compare( $from_version, $version, '<' ) ) {
				require_once JETONOMY_DIR . "includes/db/migrations/class-migration_{$class}.php";
				$fqn = "Jetonomy\\DB\\Migrations\\Migration_{$class}";
				( new $fqn() )->up();
				update_option( 'jetonomy_db_version', $version );
			}
		}
	}

	/**
	 * Map of version string => migration class suffix.
	 *
	 * @return array<string,string>
	 */
	private static function get_migrations(): array {
		// Pre-release: all schema changes go in class-schema.php.
		// Post-release migrations will be added here as '1.1.0' => '1_1_0', etc.
		return [];
	}
}
