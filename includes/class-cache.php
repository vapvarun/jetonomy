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

	public static function flush(): void {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}

	public static function is_persistent(): bool {
		return wp_using_ext_object_cache();
	}
}
