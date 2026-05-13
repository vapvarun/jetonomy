<?php
/**
 * PHPStan bootstrap — declares constants the static analyser would otherwise
 * see as undefined.
 *
 * Loaded via `bootstrapFiles:` in phpstan.neon.dist BEFORE PHPStan walks the
 * tree, so every file that references these constants type-checks cleanly
 * without needing per-file phpcs:ignore annotations.
 *
 * Runtime defines for these live in jetonomy.php (loaded by WordPress at
 * plugins_loaded). PHPStan does not boot WordPress, so it can't see those
 * defines. Mirroring them here closes the gap.
 *
 * @package Jetonomy
 */

if ( ! defined( 'JETONOMY_VERSION' ) ) {
	define( 'JETONOMY_VERSION', '1.4.2' );
}
if ( ! defined( 'JETONOMY_DB_VERSION' ) ) {
	define( 'JETONOMY_DB_VERSION', '1.4.3.0' );
}
if ( ! defined( 'JETONOMY_FILE' ) ) {
	define( 'JETONOMY_FILE', __FILE__ );
}
if ( ! defined( 'JETONOMY_DIR' ) ) {
	define( 'JETONOMY_DIR', __DIR__ . '/' );
}
if ( ! defined( 'JETONOMY_URL' ) ) {
	define( 'JETONOMY_URL', '' );
}
if ( ! defined( 'JETONOMY_BASENAME' ) ) {
	define( 'JETONOMY_BASENAME', 'jetonomy/jetonomy.php' );
}

if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
	define( 'JETONOMY_PRO_VERSION', '1.4.2' );
}
if ( ! defined( 'JETONOMY_PRO_DIR' ) ) {
	define( 'JETONOMY_PRO_DIR', '' );
}
if ( ! defined( 'JETONOMY_PRO_URL' ) ) {
	define( 'JETONOMY_PRO_URL', '' );
}

// WordPress core DB constants — wp-config.php defines them at runtime. PHPStan
// doesn't load wp-config, so the install-tests script + a few CLI/migration
// paths look unresolved without these. They're WP-namespaced, not ours, so the
// PrefixAllGlobals rule doesn't apply.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', '' );
}
if ( ! defined( 'DB_USER' ) ) {
	define( 'DB_USER', '' );
}
if ( ! defined( 'DB_PASSWORD' ) ) {
	define( 'DB_PASSWORD', '' );
}
if ( ! defined( 'DB_HOST' ) ) {
	define( 'DB_HOST', '' );
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
