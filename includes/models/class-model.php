<?php
/**
 * Abstract base model.
 *
 * @package Jetonomy
 */

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
				'SELECT * FROM ' . static::table() . ' WHERE id = %d',
				$id
			)
		);
		return $row ?: null;
	}

	public static function insert( array $data ): int {
		static::db()->insert( static::table(), $data );
		return (int) static::db()->insert_id;
	}

	/**
	 * Enforce the editor-content pipeline on a write payload.
	 *
	 * Post/Reply create+update call this so EVERY writer - REST, wp-admin
	 * AJAX, CLI journeys, Abilities, the importers, Pro reply-by-email -
	 * shares one normalize+kses choke point instead of each remembering both
	 * halves (Basecamp 10138808747: several remembered only the kses half,
	 * so contenteditable div soup persisted through them). Also derives the
	 * FULLTEXT `content_plain` copy when the writer did not supply one, so a
	 * forgetful writer can no longer create content that search cannot find.
	 *
	 * @param array $data Column payload about to be written.
	 * @return array Payload with content sanitized (and content_plain filled).
	 */
	protected static function sanitize_content_fields( array $data ): array {
		if ( array_key_exists( 'content', $data ) && null !== $data['content'] ) {
			$data['content'] = \jetonomy_sanitize_editor_content( (string) $data['content'] );
			if ( ! array_key_exists( 'content_plain', $data ) ) {
				$data['content_plain'] = \jetonomy_content_to_plain( $data['content'] );
			}
		}
		return $data;
	}

	/**
	 * Update a row by primary key.
	 *
	 * CAUTION — this returns true when ZERO rows changed.
	 * $wpdb->update() returns the affected-row count, or false on error, so
	 * `false !== 0` is true and a write that matched nothing reports success.
	 * That is the right default here: re-saving a row with identical values is
	 * not a failure, and most callers only care that no DB error occurred.
	 *
	 * It is the WRONG contract whenever "did I win this race?" matters. If two
	 * actors can act on the same row and the loser must find out, do NOT rely
	 * on this method's return — issue a conditional write that includes the
	 * expected current state in the WHERE and inspect the row count yourself.
	 * Flag::resolve() is the worked example (moderation attribution, 1.9.4).
	 *
	 * @param int   $id   Primary key.
	 * @param array $data Column => value pairs.
	 * @return bool False only on a database error.
	 */
	public static function update( int $id, array $data ): bool {
		return false !== static::db()->update( static::table(), $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): bool|\WP_Error {
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
