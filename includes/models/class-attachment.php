<?php
/**
 * Attachment link model — maps WP media items to posts/replies with ordering,
 * plus a batch primer so rendering N reply cards issues zero per-row queries.
 *
 * This lived in Pro until 1.7.1, and that was the wrong home for the DATA. A
 * customer who migrated a forum with Pro active and later dropped Pro (licence
 * lapsed, downgrade) watched their attachments disappear from the page: the
 * importer had taken the old forum's markup out of the post body, and the only
 * remaining record sat in a table free cannot read. The files were fine — the
 * site simply had no way to show them.
 *
 * So the LINK is free. "This post has files on it" is forum content, not a paid
 * feature. Pro keeps what is genuinely Pro: the upload composer, the size/type
 * limits, and the richer previews. Pro's Model now extends this one, so Pro's
 * existing call sites are unchanged and both plugins read the same table.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

class Attachment {

	/** @var array<string,array<int,object[]>> Primed cache keyed [type][object_id]. */
	protected static array $cache = array();

	protected static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'jt_attachments';
	}

	/**
	 * Attach a media item to a post or reply.
	 *
	 * Idempotent: the same file linked to the same object twice is a no-op, so a
	 * resumed or re-run import cannot double-attach a customer's file. Backed by a
	 * UNIQUE KEY on (object_type, object_id, attachment_id) as well as this guard.
	 */
	public static function link( string $object_type, int $object_id, int $attachment_id, int $sort = 0 ): int {
		global $wpdb;
		$object_type = 'reply' === $object_type ? 'reply' : 'post';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d AND attachment_id = %d LIMIT 1',
				$object_type,
				$object_id,
				$attachment_id
			)
		);
		if ( $existing ) {
			return $existing;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			static::table(),
			array(
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'attachment_id' => $attachment_id,
				'sort'          => $sort,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s' )
		);
		unset( static::$cache[ $object_type ][ $object_id ] );

		return (int) $wpdb->insert_id;
	}

	public static function find( int $link_id ): ?object {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . static::table() . ' WHERE id = %d', $link_id ) );

		return $row ?: null;
	}

	public static function unlink( int $link_id ): bool {
		global $wpdb;
		$row = static::find( $link_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = (bool) $wpdb->delete( static::table(), array( 'id' => $link_id ), array( '%d' ) );
		if ( $row ) {
			unset( static::$cache[ $row->object_type ][ (int) $row->object_id ] );
		}

		return $ok;
	}

	public static function unlink_all( string $object_type, int $object_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d', $object_type, $object_id ) );
		unset( static::$cache[ $object_type ][ $object_id ] );

		return $n;
	}

	public static function count_for( string $object_type, int $object_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d',
				$object_type,
				$object_id
			)
		);
	}

	public static function get_for( string $object_type, int $object_id ): array {
		if ( isset( static::$cache[ $object_type ][ $object_id ] ) ) {
			return static::$cache[ $object_type ][ $object_id ];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY sort ASC, id ASC',
				$object_type,
				$object_id
			)
		);

		static::$cache[ $object_type ][ $object_id ] = $rows ?: array();

		return static::$cache[ $object_type ][ $object_id ];
	}

	public static function owner_of( int $link_id ): int {
		$row = static::find( $link_id );

		return $row ? (int) get_post_field( 'post_author', (int) $row->attachment_id ) : 0;
	}

	/**
	 * Batch-load attachments for many objects of one type in ONE query, seeding the
	 * per-object cache so a later get_for() never re-queries. A page of N rows costs
	 * one query, not N.
	 *
	 * @param string $object_type 'post'|'reply'.
	 * @param int[]  $object_ids  Object ids.
	 * @return array<int,object[]> Keyed by object_id.
	 */
	public static function get_for_many( string $object_type, array $object_ids ): array {
		$object_type = 'reply' === $object_type ? 'reply' : 'post';
		$object_ids  = array_values( array_unique( array_filter( array_map( 'intval', $object_ids ) ) ) );
		if ( empty( $object_ids ) ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values bound via prepare().
		$sql = 'SELECT * FROM ' . static::table() . " WHERE object_type = %s AND object_id IN ({$placeholders}) ORDER BY sort ASC, id ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $object_type ), $object_ids ) ) );

		$grouped = array();
		foreach ( $object_ids as $oid ) {
			$grouped[ $oid ] = array();
		}
		foreach ( (array) $rows as $r ) {
			$grouped[ (int) $r->object_id ][] = $r;
		}
		foreach ( $grouped as $oid => $links ) {
			static::$cache[ $object_type ][ $oid ] = $links;
		}

		return $grouped;
	}

	/**
	 * Prime every reply on a post in ONE query, so rendering N reply cards issues
	 * zero per-row attachment queries.
	 */
	public static function prime_for_post( int $post_id ): void {
		global $wpdb;
		$replies_table = $wpdb->prefix . 'jt_replies';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.* FROM ' . static::table() . ' a
				 INNER JOIN ' . $replies_table . ' r ON r.id = a.object_id
				 WHERE a.object_type = %s AND r.post_id = %d
				 ORDER BY a.sort ASC, a.id ASC',
				'reply',
				$post_id
			)
		);

		$grouped = array();
		foreach ( (array) $rows as $r ) {
			$grouped[ (int) $r->object_id ][] = $r;
		}

		// Seed every reply on the post (empty where none) so get_for() never
		// re-queries a reply that simply has no attachments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $replies_table . ' WHERE post_id = %d', $post_id ) ) as $rid ) {
			static::$cache['reply'][ (int) $rid ] = $grouped[ (int) $rid ] ?? array();
		}
	}

	/**
	 * Hydrate a link row into what a renderer or REST payload needs.
	 *
	 * @return array{id:int,attachment_id:int,url:string,name:string,mime:string,ext:string,size:int,is_image:bool,thumb:string}|null
	 */
	public static function hydrate( object $row ): ?array {
		$attachment_id = (int) $row->attachment_id;
		$url           = (string) wp_get_attachment_url( $attachment_id );
		if ( '' === $url ) {
			return null; // Media item deleted from under us — render nothing, not a broken card.
		}

		$file     = (string) get_attached_file( $attachment_id );
		$mime     = (string) get_post_mime_type( $attachment_id );
		$is_image = (bool) wp_attachment_is_image( $attachment_id );

		return array(
			'id'            => (int) $row->id,
			'attachment_id' => $attachment_id,
			'url'           => $url,
			'name'          => $file ? basename( $file ) : (string) get_the_title( $attachment_id ),
			'mime'          => $mime,
			'ext'           => strtoupper( (string) pathinfo( $url, PATHINFO_EXTENSION ) ),
			'size'          => ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0,
			'is_image'      => $is_image,
			'thumb'         => $is_image ? (string) wp_get_attachment_image_url( $attachment_id, 'medium' ) : '',
		);
	}
}
