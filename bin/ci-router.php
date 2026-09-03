<?php
/**
 * Router for PHP's built-in server, used by CI to serve WordPress.
 *
 * WHY THIS EXISTS
 * ---------------
 * `php -S host:port -t /tmp/wp` with no router cannot serve a WordPress pretty
 * permalink that carries a file extension. The built-in server treats
 * `/community-sitemap.xml` as a request for a static file, does not find one,
 * and returns its OWN 404 page - WordPress never runs.
 *
 * Extensionless routes like `/community/s/x/feed/` happen to fall through to
 * index.php, which is why the space-feed check passed in CI for months while
 * the whole class of extension-bearing routes was silently untestable:
 *
 *     /community/s/x/feed/     -> 200, reaches WordPress
 *     /community-sitemap.xml   -> 404 text/html, PHP's own error page
 *
 * That is not a product bug, it is the test environment being unable to
 * represent a real request. Every sitemap, .xml feed, robots.txt or file-shaped
 * route was unverifiable in CI until this existed.
 *
 * Behaviour: serve a real file when one exists (so wp-admin assets, CSS and JS
 * still work), otherwise hand the request to WordPress the way Apache or nginx
 * would.
 *
 * @package Jetonomy
 */

$jt_root = rtrim( (string) ( $_SERVER['DOCUMENT_ROOT'] ?? getcwd() ), '/' );
$jt_path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
$jt_file = $jt_root . $jt_path;

// A real file on disk is served as-is: assets, uploads, wp-admin PHP.
if ( '' !== $jt_path && '/' !== substr( $jt_path, -1 ) && file_exists( $jt_file ) && is_file( $jt_file ) ) {
	return false;
}

// A directory with an index.php (wp-admin/, and the site root) behaves normally.
if ( is_dir( $jt_file ) && is_file( rtrim( $jt_file, '/' ) . '/index.php' ) ) {
	require rtrim( $jt_file, '/' ) . '/index.php';
	return true;
}

// Everything else is a WordPress route, extension or not.
require $jt_root . '/index.php';
return true;
