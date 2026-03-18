<?php
namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

class Import_Manager {

	private static array $importers = [];

	public static function init(): void {
		self::register( 'bbpress', new BBPress_Importer() );
		self::register( 'wpforo', new WPForo_Importer() );

		// Allow third-party importers
		self::$importers = apply_filters( 'jetonomy_importers', self::$importers );
	}

	public static function register( string $id, Importer $importer ): void {
		self::$importers[ $id ] = $importer;
	}

	public static function get_available(): array {
		$available = [];
		foreach ( self::$importers as $id => $importer ) {
			if ( $importer->is_source_available() ) {
				$available[ $id ] = [
					'name'  => $importer->get_source_name(),
					'stats' => $importer->get_source_stats(),
				];
			}
		}
		return $available;
	}

	public static function run( string $id, array $options = [] ): ?array {
		if ( ! isset( self::$importers[ $id ] ) ) return null;
		return self::$importers[ $id ]->run( $options );
	}
}
