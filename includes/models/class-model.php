<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;

abstract class Model {

	abstract protected static function table_name(): string;

	protected static function table(): string {
		return table( static::table_name() );
	}

	protected static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	public static function find( int $id ): ?object {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE id = %d', $id
			)
		);
		return $row ?: null;
	}

	public static function insert( array $data ): int {
		static::db()->insert( static::table(), $data );
		return (int) static::db()->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		return false !== static::db()->update( static::table(), $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): bool {
		return false !== static::db()->delete( static::table(), [ 'id' => $id ] );
	}

	public static function count( array $where = [] ): int {
		$sql = 'SELECT COUNT(*) FROM ' . static::table();
		if ( ! empty( $where ) ) {
			$clauses = [];
			$values  = [];
			foreach ( $where as $col => $val ) {
				$clauses[] = "`{$col}` = %s";
				$values[]  = $val;
			}
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
			$sql  = static::db()->prepare( $sql, ...$values );
		}
		return (int) static::db()->get_var( $sql );
	}
}
