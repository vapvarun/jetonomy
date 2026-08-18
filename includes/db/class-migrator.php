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
		// Migrations run inline on plugins_loaded — whichever request arrives
		// first after an update, including anonymous frontend hits on shared
		// hosts with tight max_execution_time. An ALTER that outlives the PHP
		// limit completes server-side but the version never gets stamped, so
		// every subsequent request re-enters the whole loop. Lift the limits
		// for the migration pass (caching plan WP5.0).
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled_functions on some hosts.
		}

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
			'1.6.1'   => '1_6_1',
			'1.7.0'   => '1_7_0',
			'1.7.1'   => '1_7_1',
			// Deliberately keyed 1.8.1 even though no 1.8.1 was ever released -
			// the work shipped as 1.9.0. These keys are schema milestones, not
			// release numbers: run() compares them against the STORED
			// jetonomy_db_version, so this still fires for every site coming
			// from 1.8.0 or earlier, and the internal sites that ran the
			// 1.8.1 dev builds already have it recorded under this exact key.
			// Renumbering it would re-run the migration on those sites (it is
			// idempotent, so harmless) but buys nothing. Do not "fix" it.
			'1.8.1'   => '1_8_1',
			'1.9.1'   => '1_9_1',
			'1.9.2'   => '1_9_2',
			'1.9.3'   => '1_9_3',
		];
	}
}
