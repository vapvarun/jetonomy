<?php
/**
 * Minimal WP function/class stubs shared by both free and Pro smoke tests.
 *
 * Scope: just enough to boot a plugin through `plugins_loaded` and `init`
 * without fatals. NOT a substitute for PHPUnit against the real WP test
 * suite — only catches "does the plugin crash on load" regressions.
 *
 * Constants must be defined by the caller BEFORE requiring this file
 * (ABSPATH, WPINC, DB_NAME, etc). See smoke-test.php for the full list.
 *
 * @package Jetonomy
 */

declare(strict_types=1);

// Global state used by the stubs. Callers may pre-seed $GLOBALS['__options']
// (e.g. with jetonomy_db_version => '0.0.0' to force the migrator path).
$GLOBALS['__hooks']     = $GLOBALS['__hooks'] ?? array();
$GLOBALS['__options']   = $GLOBALS['__options'] ?? array();
$GLOBALS['__transient'] = $GLOBALS['__transient'] ?? array();

// Prevent double-load — the Pro smoke test requires this file which the
// free smoke test also requires. Stubs are idempotent.
if ( defined( 'JETONOMY_SMOKE_STUBS_LOADED' ) ) {
	return;
}
define( 'JETONOMY_SMOKE_STUBS_LOADED', true );

// ---------- WP function stubs ----------

function add_action( string $tag, $cb, int $pri = 10, int $argc = 1 ): bool {
	$GLOBALS['__hooks'][ $tag ][ $pri ][] = array( 'cb' => $cb, 'argc' => $argc );
	ksort( $GLOBALS['__hooks'][ $tag ] );
	return true;
}
function add_filter( string $tag, $cb, int $pri = 10, int $argc = 1 ): bool {
	return add_action( $tag, $cb, $pri, $argc );
}
function remove_action( ...$a ): bool { return true; }
function remove_filter( ...$a ): bool { return true; }
function do_action( string $tag, ...$args ): void {
	if ( empty( $GLOBALS['__hooks'][ $tag ] ) ) {
		return;
	}
	foreach ( $GLOBALS['__hooks'][ $tag ] as $pri => $list ) {
		foreach ( $list as $entry ) {
			$n = (int) $entry['argc'];
			$a = $n > 0 ? array_slice( $args, 0, $n ) : array();
			call_user_func_array( $entry['cb'], $a );
		}
	}
}
function do_action_ref_array( string $tag, array $args ): void { do_action( $tag, ...$args ); }
function apply_filters( string $tag, $val, ...$args ) { return $val; }
function has_action( ...$a ): bool { return false; }
function has_filter( ...$a ): bool { return false; }
function did_action( ...$a ): int { return 0; }

function register_activation_hook( ...$a ): void {}
function register_deactivation_hook( ...$a ): void {}
function register_uninstall_hook( ...$a ): void {}

function plugin_dir_path( string $f ): string { return dirname( $f ) . '/'; }
function plugin_dir_url( string $f ): string { return 'https://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }
function plugin_basename( string $f ): string { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function plugins_url( string $p = '', string $f = '' ): string { return 'https://example.test/wp-content/plugins/' . $p; }
function trailingslashit( string $p ): string { return rtrim( $p, '/\\' ) . '/'; }
function untrailingslashit( string $p ): string { return rtrim( $p, '/\\' ); }
function register_post_type( ...$a ) { return new \stdClass(); }
function register_taxonomy( ...$a ) { return new \stdClass(); }
function register_post_status( ...$a ): void {}
function wp_using_ext_object_cache( $u = null ): bool { return false; }
function _doing_it_wrong( ...$a ): void {}
function _deprecated_function( ...$a ): void {}
function _deprecated_argument( ...$a ): void {}
function _deprecated_hook( ...$a ): void {}

function get_option( string $k, $d = false ) { return $GLOBALS['__options'][ $k ] ?? $d; }
function update_option( string $k, $v, $autoload = null ): bool { $GLOBALS['__options'][ $k ] = $v; return true; }
function add_option( string $k, $v, $p = '', $a = 'yes' ): bool { $GLOBALS['__options'][ $k ] = $v; return true; }
function delete_option( string $k ): bool { unset( $GLOBALS['__options'][ $k ] ); return true; }

function get_transient( string $k ) { return $GLOBALS['__transient'][ $k ] ?? false; }
function set_transient( string $k, $v, int $t = 0 ): bool { $GLOBALS['__transient'][ $k ] = $v; return true; }
function delete_transient( string $k ): bool { unset( $GLOBALS['__transient'][ $k ] ); return true; }

function __( string $s, string $d = '' ): string { return $s; }
function _e( string $s, string $d = '' ): void { echo $s; }
function _x( string $s, string $c, string $d = '' ): string { return $s; }
function _n( string $s, string $p, int $n, string $d = '' ): string { return 1 === $n ? $s : $p; }
function esc_html( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( string $s ): string { return $s; }
function esc_js( string $s ): string { return $s; }
function esc_html__( string $s, string $d = '' ): string { return $s; }
function esc_attr__( string $s, string $d = '' ): string { return $s; }
function wp_kses_post( string $s ): string { return $s; }
function sanitize_text_field( string $s ): string { return $s; }
function sanitize_key( string $s ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $s ) ); }

function wp_json_encode( $d, int $opt = 0, int $depth = 512 ) { return json_encode( $d, $opt, $depth ); }
function wp_unslash( $v ) { return $v; }
function wp_parse_args( $a, $d = '' ): array { return array_merge( (array) $d, (array) $a ); }
function absint( $v ): int { return (int) abs( (int) $v ); }

function current_time( string $t = 'mysql', $gmt = 0 ): string {
	return 'mysql' === $t ? gmdate( 'Y-m-d H:i:s' ) : (string) time();
}
function home_url( string $p = '', ?string $s = null ): string { return 'https://example.test' . $p; }
function site_url( string $p = '' ): string { return 'https://example.test' . $p; }
function admin_url( string $p = '' ): string { return 'https://example.test/wp-admin/' . $p; }
function rest_url( string $p = '' ): string { return 'https://example.test/wp-json/' . $p; }
function get_bloginfo( string $s = '' ): string { return 'Smoke Test Site'; }

function is_admin(): bool { return false; }
function is_user_logged_in(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function wp_doing_cron(): bool { return false; }
function is_network_admin(): bool { return false; }
function wp_safe_redirect( $u, int $s = 302 ): bool { return true; }

function current_user_can( string $c ): bool { return false; }
function wp_get_current_user() { return (object) array( 'ID' => 0, 'user_email' => '', 'display_name' => '' ); }
function get_current_user_id(): int { return 0; }

function wp_next_scheduled( string $h, array $args = array() ) { return false; }
function wp_schedule_event( ...$a ): bool { return true; }
function wp_clear_scheduled_hook( ...$a ): bool { return true; }
function load_plugin_textdomain( ...$a ): bool { return true; }

function add_shortcode( ...$a ): void {}
function remove_shortcode( ...$a ): void {}
function shortcode_exists( string $s ): bool { return false; }
function do_shortcode( string $c, bool $i = false ): string { return $c; }
function wp_register_sidebar_widget( ...$a ): void {}
function register_widget( ...$a ): void {}
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir(), 'baseurl' => 'https://example.test/wp-content/uploads', 'path' => sys_get_temp_dir(), 'url' => 'https://example.test/wp-content/uploads' ); }
function wp_timezone_string(): string { return 'UTC'; }
function get_user_by( string $f, $v ) { return false; }
function get_userdata( int $id ) { return false; }
function get_users( array $a = array() ): array { return array(); }
function count_users() { return array( 'total_users' => 0 ); }
function get_taxonomies( array $a = array(), string $o = 'names' ): array { return array(); }
function get_terms( ...$a ): array { return array(); }
function get_post_types( array $a = array(), string $o = 'names' ): array { return array(); }
function get_posts( array $a = array() ): array { return array(); }
function wp_get_environment_type(): string { return 'production'; }
function wp_cache_get( $k, string $g = '' ) { return false; }
function wp_cache_set( $k, $d, string $g = '', int $t = 0 ): bool { return true; }
function wp_cache_delete( $k, string $g = '' ): bool { return true; }
function wp_cache_flush(): bool { return true; }
function get_file_data( string $f, array $d ): array { return array_fill_keys( array_keys( $d ), '' ); }
function sanitize_title( string $t ): string { return strtolower( preg_replace( '/[^a-z0-9\-]+/i', '-', $t ) ); }
function sanitize_user( string $u ): string { return preg_replace( '/[^a-z0-9_\-]/i', '', $u ); }
function wp_generate_password( int $l = 12 ): string { return str_repeat( 'a', $l ); }
function zeroise( $n, int $t ): string { return str_pad( (string) $n, $t, '0', STR_PAD_LEFT ); }
function human_time_diff( int $f, int $t = 0 ): string { return '1 hour'; }
function mysql2date( string $f, string $d ): string { return $d; }
function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); }
function wp_trim_words( string $t, int $n = 55 ): string { return $t; }
function wp_encode_emoji( string $s ): string { return $s; }
function wp_slash( $v ) { return $v; }
function wp_normalize_path( string $p ): string { return $p; }
function wp_parse_url( string $u, int $c = -1 ) { return \parse_url( $u, $c ); }
function wp_remote_get( string $u, array $a = array() ): array { return array( 'body' => '', 'response' => array( 'code' => 200 ) ); }
function wp_remote_post( string $u, array $a = array() ): array { return array( 'body' => '', 'response' => array( 'code' => 200 ) ); }
function wp_remote_retrieve_body( $r ): string { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_response_code( $r ): int { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_mail( ...$a ): bool { return true; }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000000'; }
function wp_hash( string $d ): string { return md5( $d ); }
function wp_salt( string $s = 'auth' ): string { return 'smoke'; }
function wp_parse_str( string $s, &$a ): void { parse_str( $s, $a ); }
// dbDelta is in wp-admin/includes/upgrade.php in real installs. Plugins
// that call it on activation often require that file explicitly — we
// pre-define it here AND create a shim upgrade.php below so the require
// is a no-op.
function dbDelta( $queries = '', bool $execute = true ): array { return array(); }
function maybe_create_table( string $t, string $c ): bool { return true; }
function maybe_add_column( string $t, string $c, string $d ): bool { return true; }
function wp_oembed_add_provider( ...$a ): void {}
function wp_oembed_remove_provider( string $p ): bool { return true; }
function wp_oembed_get( string $u, $a = '' ): string { return ''; }
function wp_sitemaps_get_server() { return (object) array( 'registry' => new class { public function add_provider( ...$a ): bool { return true; } } ); }

// Rewrite + query stubs (called during init).
function add_rewrite_rule( ...$a ): void {}
function add_rewrite_tag( ...$a ): void {}
function add_rewrite_endpoint( ...$a ): void {}
function flush_rewrite_rules( bool $h = true ): void {}
function get_query_var( string $n, $d = '' ): string { return ''; }
function set_query_var( string $n, $v ): void {}

// REST + enqueue stubs.
function register_rest_route( ...$a ): bool { return true; }
function wp_enqueue_script( ...$a ): void {}
function wp_enqueue_style( ...$a ): void {}
function wp_register_script( ...$a ): bool { return true; }
function wp_register_style( ...$a ): bool { return true; }
function wp_add_inline_style( ...$a ): bool { return true; }
function wp_add_inline_script( ...$a ): bool { return true; }
function wp_localize_script( ...$a ): bool { return true; }
function wp_create_nonce( string $a = '' ): string { return 'smoke_nonce'; }
function register_block_type( ...$a ) { return true; }
function register_setting( ...$a ): void {}
function add_menu_page( ...$a ): string { return ''; }
function add_submenu_page( ...$a ): string { return ''; }
function add_options_page( ...$a ): string { return ''; }

// Role stubs.
function get_role( string $n ) { return new WP_Role( $n ); }
function get_roles(): array { return array( 'administrator' => array(), 'editor' => array(), 'author' => array(), 'contributor' => array(), 'subscriber' => array() ); }
function add_role( string $n, string $d, array $c = array() ): WP_Role { return new WP_Role( $n ); }
function is_wp_error( $v ): bool { return $v instanceof WP_Error; }

// ---------- WP class stubs ----------

if ( ! class_exists( 'WP_Role' ) ) {
	class WP_Role {
		public string $name;
		public array $capabilities = array();
		public function __construct( string $n ) { $this->name = $n; }
		public function add_cap( string $c, bool $g = true ): void { $this->capabilities[ $c ] = $g; }
		public function remove_cap( string $c ): void { unset( $this->capabilities[ $c ] ); }
		public function has_cap( string $c ): bool { return ! empty( $this->capabilities[ $c ] ); }
	}
}

if ( ! class_exists( 'WP_Rewrite' ) ) {
	class WP_Rewrite {
		public function add_rule( ...$a ): void {}
		public function flush_rules( bool $h = true ): void {}
	}
}
$GLOBALS['wp_rewrite'] = new WP_Rewrite();

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	class WP_REST_Controller {
		protected $namespace = '';
		protected $rest_base = '';
		public function register_routes() {}
		public function get_items( $req ) { return array(); }
		public function get_item( $req ) { return array(); }
		public function create_item( $req ) { return array(); }
		public function update_item( $req ) { return array(); }
		public function delete_item( $req ) { return array(); }
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function get_param( string $k ) { return null; }
		public function get_params(): array { return array(); }
		public function get_json_params() { return array(); }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( $d = null, int $s = 200 ) {}
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public array $errors = array();
		public array $error_data = array();
		public function __construct( $c = '', string $m = '', $d = '' ) {
			if ( '' !== $c ) { $this->errors[ $c ][] = $m; $this->error_data[ $c ] = $d; }
		}
		public function get_error_code() { return array_key_first( $this->errors ) ?: ''; }
		public function get_error_message( $c = '' ): string {
			$c = '' !== $c ? $c : $this->get_error_code();
			return $this->errors[ $c ][0] ?? '';
		}
		public function get_error_data( $c = '' ) { return $this->error_data[ $c ] ?? ''; }
		public function add( $c, string $m = '', $d = '' ): void { $this->errors[ $c ][] = $m; $this->error_data[ $c ] = $d; }
		public function has_errors(): bool { return ! empty( $this->errors ); }
	}
}
if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
	class WP_Sitemaps_Provider {
		public $name = '';
		public $object_type = '';
		public function get_url_list( int $p, string $s = '' ): array { return array(); }
		public function get_max_num_pages( string $s = '' ): int { return 1; }
	}
}
if ( ! class_exists( 'WP_Widget' ) ) {
	class WP_Widget {
		public $id_base;
		public $name;
		public function __construct( $b = '', $n = '', $o = array(), $co = array() ) {}
		public function widget( $a, $i ) {}
		public function form( $i ) { return ''; }
		public function update( $n, $o ) { return $n; }
	}
}

// wpdb stub.
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public int $insert_id = 0;
		public int $num_queries = 0;
		public string $queries = '';
		public string $charset_collate = 'utf8mb4_unicode_ci';

		public function prepare( $q, ...$a ): string { return is_string( $q ) ? $q : ''; }
		public function query( $q ): int { return 0; }
		public function get_results( $q = null, $o = 'OBJECT' ): array { return array(); }
		public function get_row( $q = null, $o = 'OBJECT', int $i = 0 ) { return null; }
		public function get_var( $q = null, int $c = 0, int $r = 0 ) { return null; }
		public function get_col( $q = null, int $c = 0 ): array { return array(); }
		public function insert( string $t, array $d, $f = null ): int { return 0; }
		public function update( string $t, array $d, array $w, $df = null, $wf = null ): int { return 0; }
		public function delete( string $t, array $w, $wf = null ): int { return 0; }
		public function get_charset_collate(): string { return $this->charset_collate; }
		public function has_cap( $c ): bool { return true; }
		public function db_version(): string { return '8.0'; }
		public function esc_like( string $t ): string { return $t; }
		public function _real_escape( string $s ): string { return $s; }
	}
}
$GLOBALS['wpdb'] = new wpdb();
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'ARRAY_N', 'ARRAY_N' );
}

// Time constants defined by WordPress core — Pro extensions reference them.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) )   { define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )    { define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS ); }
if ( ! defined( 'WEEK_IN_SECONDS' ) )   { define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS ); }
if ( ! defined( 'MONTH_IN_SECONDS' ) )  { define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS ); }
if ( ! defined( 'YEAR_IN_SECONDS' ) )   { define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS ); }
