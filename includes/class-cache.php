<?php
/**
 * Cache layer.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Cache {
	private const GROUP       = 'jetonomy';
	private const DEFAULT_TTL = 300; // 5 minutes

	public static function get( string $key ) {
		return wp_cache_get( $key, self::GROUP );
	}

	public static function set( string $key, $value, int $ttl = 0 ): void {
		wp_cache_set( $key, $value, self::GROUP, $ttl ?: self::DEFAULT_TTL );
	}

	public static function delete( string $key ): void {
		wp_cache_delete( $key, self::GROUP );
	}

	/**
	 * Delete several keys in one call.
	 *
	 * A convenience for writers that must bust more than one key whose value
	 * they changed (e.g. a space row is served under both space:{id} and
	 * space:slug:{slug}, so a single write invalidates both). Keeps the
	 * "bust every key you changed" discipline in one place.
	 *
	 * @param string[] $keys Cache keys to delete.
	 */
	public static function delete_many( array $keys ): void {
		foreach ( $keys as $key ) {
			wp_cache_delete( (string) $key, self::GROUP );
		}
	}

	public static function remember( string $key, callable $callback, int $ttl = 0 ) {
		$cached = self::get( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$value = $callback();
		self::set( $key, $value, $ttl );
		return $value;
	}

	/**
	 * remember() for callers that contract an object|null result.
	 *
	 * A DB miss caches null. Some persistent object-cache backends
	 * (Redis/Memcached) materialise a stored null as '' (empty string) on the
	 * next read, and wp_cache_get() returns that '' rather than false — so
	 * remember() treats it as a hit and hands back the string. Any caller with
	 * an `?object` return type then fatals with a TypeError. Coerce every
	 * non-object hit back to null so the object|null contract always holds.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Value producer on a miss.
	 * @param int      $ttl      TTL in seconds.
	 * @return object|null
	 */
	public static function remember_object( string $key, callable $callback, int $ttl = 0 ): ?object {
		$value = self::remember( $key, $callback, $ttl );
		return is_object( $value ) ? $value : null;
	}

	/**
	 * Flush the plugin's cache group.
	 *
	 * Used by one-shot admin/CLI/import recomputes whose set-based writes cannot
	 * name the ids they touched (Caching Standard §4d), and by the admin
	 * "Clear cache" action. NOT a per-write path — group flush is a sledgehammer.
	 *
	 * Guarded by wp_cache_supports('flush_group') per §4b: flush_group is only
	 * implemented by core's default cache and some persistent drop-ins. When a
	 * persistent drop-in lacks it we fall back to a full flush (still correct for
	 * these rare one-shot callers); with no persistent cache there is nothing to
	 * flush (wp_cache_* is request-local).
	 */
	public static function flush(): void {
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		} elseif ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}

	public static function is_persistent(): bool {
		return wp_using_ext_object_cache();
	}
}
