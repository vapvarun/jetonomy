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
		return [
			'1.1.0'   => '1_1_0',
			'1.2.0'   => '1_2_0',
			'1.2.1'   => '1_2_1',
			'1.2.2'   => '1_2_2',
			'1.2.3'   => '1_2_3',
			'1.2.4'   => '1_2_4',
			'1.2.5'   => '1_2_5',
			'1.2.6'   => '1_2_6',
			'1.4.1'   => '1_4_1',
			'1.4.2'   => '1_4_2',
			'1.4.2.1' => '1_4_2_1',
			'1.4.2.2' => '1_4_2_2',
			'1.4.4'   => '1_4_4',
			'1.5.0'   => '1_5_0',
			'1.5.0.1' => '1_5_0_1',
			'1.6.0'   => '1_6_0',
		];
	}
}
