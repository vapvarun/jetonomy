<?php
/**
 * Main plugin class.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

final class Jetonomy {
	private static ?self $instance = null;

	public ?Moderation\AI_Spam_Detector $ai_spam_detector = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_hooks();
	}

	private function register_hooks(): void {
		register_activation_hook( JETONOMY_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( JETONOMY_FILE, array( $this, 'deactivate' ) );
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'plugins_loaded', array( $this, 'init' ) );

		// Register plugin-level theme.json for baseline typography, spacing, and colors.
		// Active theme's theme.json always wins — this provides sensible defaults for classic themes.
		add_filter( 'wp_theme_json_data_default', array( $this, 'register_plugin_theme_json' ) );
	}

	/**
	 * Merge plugin theme.json into the default layer so it provides a baseline
	 * but is overridden by the active theme's theme.json.
	 */
	public function register_plugin_theme_json( $theme_json ) {
		$plugin_json_path = JETONOMY_DIR . 'theme.json';
		if ( ! file_exists( $plugin_json_path ) ) {
			return $theme_json;
		}
		$plugin_data = json_decode( file_get_contents( $plugin_json_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! $plugin_data ) {
			return $theme_json;
		}
		$theme_json->update_with( $plugin_data );
		return $theme_json;
	}

	public function activate(): void {
		require_once JETONOMY_DIR . 'includes/db/class-schema.php';
		DB\Schema::create_tables();

		require_once JETONOMY_DIR . 'includes/permissions/class-capabilities.php';
		Permissions\Capabilities::register();

		Cron::schedule();

		update_option( 'jetonomy_db_version', JETONOMY_DB_VERSION );

		// Preset EDD license key for free plugin auto-updates.
		if ( ! get_option( 'jetonomy_license_key' ) ) {
			update_option( 'jetonomy_license_key', 'wbcomfreec7e2a9b45d8f1c3e6a0b9d2f7c4e8a11' );
		}

		// Set sensible defaults on fresh install. Each block is individually
		// guarded so re-activation never overwrites admin-customized values.
		$settings = get_option( 'jetonomy_settings', array() );
		$changed  = false;

		if ( empty( $settings['notification_defaults'] ) ) {
			$settings['notification_defaults'] = array(
				'reply_to_post'   => array(
					'web'   => true,
					'email' => true,
				),
				'reply_to_reply'  => array(
					'web'   => true,
					'email' => false,
				),
				'mention'         => array(
					'web'   => true,
					'email' => true,
				),
				'accepted_answer' => array(
					'web'   => true,
					'email' => true,
				),
				'new_post_in_sub' => array(
					'web'   => true,
					'email' => false,
				),
				'badge_earned'    => array(
					'web'   => true,
					'email' => false,
				),
				'vote_on_post'    => array(
					'web'   => true,
					'email' => false,
				),
				'moderation'      => array(
					'web'   => true,
					'email' => true,
				),
				'join_request'    => array(
					'web'   => true,
					'email' => true,
				),
			);
			$changed                           = true;
		}

		if ( empty( $settings['trust_thresholds'] ) ) {
			$settings['trust_thresholds'] = \Jetonomy\Trust\Trust_Levels::defaults();
			$changed                      = true;
		}

		if ( empty( $settings['rate_limits'] ) ) {
			$settings['rate_limits'] = \Jetonomy\Permissions\Rate_Limiter::defaults();
			$changed                 = true;
		}

		if ( $changed ) {
			update_option( 'jetonomy_settings', $settings );
		}

		// Flag a rewrite flush for the next init — rules are not registered yet
		// during activation, so flushing here would be a no-op.
		delete_option( 'jetonomy_permalinks_flushed_' . JETONOMY_VERSION );
		set_transient( 'jetonomy_activation_redirect', true, 30 );
	}

	public function deactivate(): void {
		// Delete the versioned flush-key so the next activation triggers a fresh flush.
		delete_option( 'jetonomy_permalinks_flushed_' . JETONOMY_VERSION );
		Cron::unschedule();
		flush_rewrite_rules();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'jetonomy', false, dirname( plugin_basename( JETONOMY_FILE ) ) . '/languages' );
	}

	public function init(): void {
		// Ensure preset license key exists for existing installs.
		if ( ! get_option( 'jetonomy_license_key' ) ) {
			update_option( 'jetonomy_license_key', 'wbcomfreec7e2a9b45d8f1c3e6a0b9d2f7c4e8a11' );
		}

		$this->maybe_redirect_to_setup();
		$this->check_db_version();
		$this->load_dependencies();
		$this->maybe_backfill_activity();
	}

	private function maybe_redirect_to_setup(): void {
		if ( ! get_transient( 'jetonomy_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'jetonomy_activation_redirect' );
		if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=jetonomy-setup' ) );
		exit;
	}

	private function check_db_version(): void {
		$current = get_option( 'jetonomy_db_version', '0.0.0' );
		if ( version_compare( $current, JETONOMY_DB_VERSION, '<' ) ) {
			require_once JETONOMY_DIR . 'includes/db/class-migrator.php';
			DB\Migrator::run( $current );
		}
	}

	private function load_dependencies(): void {
		require_once JETONOMY_DIR . 'includes/functions.php';

		// Non-namespaced files still need explicit require
		require_once JETONOMY_DIR . 'includes/class-router.php';
		require_once JETONOMY_DIR . 'includes/class-template-loader.php';
		new Router();

		// Ensure rewrite rules are flushed at least once after activation.
		// Version-keyed so a URL structure change triggers a re-flush.
		// Deferred to init:99 so Router::add_rewrite_rules (init:10) has run first.
		$flush_key = 'jetonomy_permalinks_flushed_' . JETONOMY_VERSION;
		if ( ! get_option( $flush_key ) ) {
			add_action(
				'init',
				static function () use ( $flush_key ) {
					flush_rewrite_rules();
					update_option( $flush_key, true );
				},
				99
			);
		}

		// Re-register capabilities on version change so ROLE_MAP additions
		// (e.g. jetonomy_manage_spaces) propagate without requiring re-activation.
		$caps_key = 'jetonomy_caps_registered_' . JETONOMY_VERSION;
		if ( ! get_option( $caps_key ) ) {
			require_once JETONOMY_DIR . 'includes/permissions/class-capabilities.php';
			Permissions\Capabilities::register();
			update_option( $caps_key, true );
		}

		new API\Api();

		// Register Jetonomy thread URLs as a known oEmbed provider so other
		// WordPress sites (and the block editor) auto-embed pasted links.
		// Loaded here explicitly — Api::register_routes runs on rest_api_init,
		// which only fires on REST requests, so we'd miss provider registration
		// on regular page loads that paste an embed block.
		add_action(
			'init',
			static function () {
				if ( ! class_exists( '\\Jetonomy\\API\\OEmbed_Controller' ) ) {
					$file = JETONOMY_DIR . 'includes/api/class-base-controller.php';
					if ( file_exists( $file ) ) {
						require_once $file;
					}
					$file = JETONOMY_DIR . 'includes/api/class-oembed-controller.php';
					if ( file_exists( $file ) ) {
						require_once $file;
					}
				}
				if ( class_exists( '\\Jetonomy\\API\\OEmbed_Controller' ) ) {
					\Jetonomy\API\OEmbed_Controller::register_provider();
				}
			}
		);

		// Handle email unsubscribe links.
		add_action( 'init', array( $this, 'handle_email_unsubscribe' ) );

		// Adapters — autoloader resolves all classes
		Adapters\Adapter_Registry::init_defaults();
		Adapters\Adapter_Registry::register_email( 'wp-mail', new Adapters\WP_Mail_Adapter() );
		Adapters\Adapter_Registry::register_search( 'fulltext', new Search\Fulltext_Search() );

		// MemberPress adapter (conditional)
		if ( defined( 'MEPR_VERSION' ) ) {
			$mepr = new Adapters\MemberPress_Adapter();
			Adapters\Adapter_Registry::register_membership( 'memberpress', $mepr );
			$mepr->register_hooks();
		}

		// Ollama AI adapter (conditional — self-hosted, free).
		$ai_settings = $settings['ai']['providers']['ollama'] ?? [];
		if ( ! empty( $ai_settings['enabled'] ) ) {
			Adapters\Adapter_Registry::register_ai( 'ollama', new Adapters\Ollama_AI_Adapter() );
		}

		// AI spam detection (free version — Ollama only).
		// Instance stored so Pro can reliably remove the filter via jetonomy()->ai_spam_detector.
		$this->ai_spam_detector = new Moderation\AI_Spam_Detector();

		// PMPro adapter (conditional)
		if ( defined( 'PMPRO_VERSION' ) ) {
			$pmpro = new Adapters\PMPro_Adapter();
			Adapters\Adapter_Registry::register_membership( 'pmpro', $pmpro );
			$pmpro->register_hooks();
		}

		// BuddyPress integration — Groups ↔ Spaces sync.
		if ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) ) {
			require_once JETONOMY_DIR . 'includes/integrations/class-buddypress.php';
			new Integrations\BuddyPress();
		}

		// Theme integration — bridges BuddyX / BuddyX Pro / Reign Kirki colors
		// and dark-scheme toggle into Jetonomy's CSS tokens.
		new Integrations\Theme_Integration();

		// CAPTCHA protection (reCAPTCHA v3 / Cloudflare Turnstile).
		Captcha\Captcha_Manager::init();

		new Notifications\Notifier();
		new Cron();
		new Privacy();

		new SEO\Sitemap();
		new SEO\Schema_Markup();

		// Social embed oEmbed hooks — Instagram/Facebook token injection + provider registration.
		Embeds::register();
		new Nav_Menus();
		new Media();
		new Activity_Tracker();
		new Abilities();

		Import\Import_Manager::init();

		// Shortcodes, Widgets, Blocks — embed forum content anywhere.
		Shortcodes::register();
		Widgets::register();
		Blocks::register();

		if ( is_admin() ) {
			new Admin\Admin();
		}
	}

	/**
	 * One-time activity backfill for existing installs that pre-date the Activity_Tracker.
	 * Runs AFTER load_dependencies() so the table() helper is available.
	 */
	private function maybe_backfill_activity(): void {
		if ( get_option( 'jetonomy_activity_backfilled' ) || ! get_option( 'jetonomy_setup_complete' ) ) {
			return;
		}

		global $wpdb;
		$activity_t = table( 'activity_log' );

		// Only backfill if activity_log is empty (first run).
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$activity_t}" );
		if ( $count > 0 ) {
			update_option( 'jetonomy_activity_backfilled', true );
			return;
		}

		$posts_t   = table( 'posts' );
		$replies_t = table( 'replies' );
		$members_t = table( 'space_members' );

		// Posts.
		$wpdb->query(
			"INSERT INTO {$activity_t} (user_id, action, object_type, object_id, metadata, created_at)
             SELECT author_id, 'created_post', 'post', id, JSON_OBJECT('space_id', space_id), created_at
             FROM {$posts_t} WHERE status = 'publish'"
		);

		// Replies.
		$wpdb->query(
			"INSERT INTO {$activity_t} (user_id, action, object_type, object_id, metadata, created_at)
             SELECT author_id, 'created_reply', 'reply', id, JSON_OBJECT('post_id', post_id), created_at
             FROM {$replies_t} WHERE status = 'publish'"
		);

		// Space memberships.
		$wpdb->query(
			"INSERT INTO {$activity_t} (user_id, action, object_type, object_id, metadata, created_at)
             SELECT user_id, 'joined_space', 'space', space_id, JSON_OBJECT('role', role), joined_at
             FROM {$members_t}"
		);

		update_option( 'jetonomy_activity_backfilled', true );
	}

	/**
	 * Handle one-click email unsubscribe via URL parameter.
	 */
	public function handle_email_unsubscribe(): void {
		if ( empty( $_GET['jetonomy_unsubscribe'] ) || empty( $_GET['uid'] ) ) {
			return;
		}

		$token   = sanitize_text_field( wp_unslash( $_GET['jetonomy_unsubscribe'] ) );
		$user_id = absint( $_GET['uid'] );
		$type    = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );

		if ( ! $user_id || ! $type ) {
			return;
		}

		// Verify token.
		$expected = wp_hash( $user_id . ':' . $type . ':unsubscribe' );
		if ( ! hash_equals( $expected, $token ) ) {
			wp_die( esc_html__( 'Invalid unsubscribe link.', 'jetonomy' ), '', array( 'response' => 403 ) );
		}

		// Disable this notification type for the user.
		$profile = Models\UserProfile::find_by_user( $user_id );
		if ( $profile ) {
			$settings                                    = json_decode( $profile->settings ?? '{}', true ) ?: array();
			$settings['notifications'][ $type ]['email'] = false;
			Models\UserProfile::update( (int) $profile->id, array( 'settings' => wp_json_encode( $settings ) ) );
		}

		wp_die(
			esc_html__( 'You have been unsubscribed from these email notifications. You can re-enable them in your notification preferences.', 'jetonomy' ),
			esc_html__( 'Unsubscribed', 'jetonomy' ),
			array( 'response' => 200 )
		);
	}
}
