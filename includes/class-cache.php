<?php
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

	public static function flush(): void {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}

	public static function is_persistent(): bool {
		return wp_using_ext_object_cache();
	}
}
