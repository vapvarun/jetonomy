<?php
/**
 * Minimal stand-in for WPMediaVerse's service container, for tests of the
 * Pro messaging hand-off. Loading it makes Private_Messaging\Extension::is_dormant()
 * true for the whole process, so only require it from a separate-process test.
 */
namespace WPMediaVerse\Core;

class Plugin {
	/** @var array<string,object> */
	public static array $services = array();

	public static function container(): object {
		return new class() {
			public function has( string $k ): bool {
				return isset( Plugin::$services[ $k ] );
			}
			public function get( string $k ): object {
				return Plugin::$services[ $k ];
			}
		};
	}
}
