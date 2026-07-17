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
		register_activation_hook( JETONOMY_FILE, 'Jetonomy\activate_plugin' );
		register_deactivation_hook( JETONOMY_FILE, array( $this, 'deactivate' ) );
		add_action( 'wp_initialize_site', 'Jetonomy\install_on_new_site', 10, 2 );
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'plugins_loaded', array( $this, 'init' ) );

		// Register plugin-level theme.json for baseline typography, spacing, and colors.
		// Active theme's theme.json always wins — this provides sensible defaults for classic themes.
		add_filter( 'wp_theme_json_data_default', array( $this, 'register_plugin_theme_json' ) );

		// 1.4.0 C.3: a globally banned user must not be able to log in. Without
		// this filter, a banned user retained their session cookie across the
		// ban (and could re-authenticate after logout) — Permission_Engine
		// blocked their writes but not the login itself, so they kept showing
		// up online and reading restricted content via stale sessions.
		add_filter( 'authenticate', array( $this, 'reject_banned_login' ), 30, 1 );

		// 1.4.0: block login for accounts whose email isn't confirmed yet.
		// Surfaces a friendly message + a hint to use the resend link from
		// the Login block, so the visitor doesn't think their password was
		// wrong.
		add_filter( 'authenticate', array( $this, 'reject_pending_verification_login' ), 31, 1 );
	}

	/**
	 * Reject login for globally-banned users with a translated message.
	 *
	 * Runs at priority 30, after wp_authenticate_username_password (priority 20),
	 * so by the time we run we already have a WP_User instance for valid
	 * credentials. Wrong passwords + unknown logins flow through unchanged
	 * (we return them as-is) so the existing "incorrect password" message
	 * still wins and we don't leak which logins exist.
	 *
	 * @param mixed $user WP_User on success, WP_Error on auth failure, null if no auth attempted yet.
	 */
	public function reject_banned_login( $user ) {
		if ( $user instanceof \WP_User && Models\Restriction::is_banned( (int) $user->ID ) ) {
			return new \WP_Error(
				'jetonomy_user_banned',
				__( 'Your account has been banned from this community. Contact a moderator if you believe this is a mistake.', 'jetonomy' )
			);
		}
		return $user;
	}

	/**
	 * Reject login when the account is still pending email verification.
	 *
	 * Runs at priority 31 (after the banned-user filter at 30) so the
	 * messages don't collide. Only fires when the admin has turned on
	 * `require_email_verification` AND the user has the pending-verification
	 * meta — accounts created before the setting was switched on are NOT
	 * gated retroactively.
	 *
	 * @param mixed $user WP_User on success, WP_Error on auth failure, null if no auth attempted yet.
	 */
	public function reject_pending_verification_login( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}
		$pending = (bool) get_user_meta( (int) $user->ID, '_jetonomy_pending_verification', true );
		if ( ! $pending ) {
			return $user;
		}
		return new \WP_Error(
			'jetonomy_pending_verification',
			__( 'Confirm your email to finish signing up. Check your inbox for the link, or use "Resend confirmation" on the sign-in form.', 'jetonomy' )
		);
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
		// Read this BEFORE anything stamps it — see the guard on the bump below.
		$db_version_before = (string) get_option( 'jetonomy_db_version', '' );

		require_once JETONOMY_DIR . 'includes/db/class-schema.php';
		DB\Schema::create_tables();

		require_once JETONOMY_DIR . 'includes/permissions/class-capabilities.php';
		Permissions\Capabilities::register();

		Cron::schedule();

		// Register rewrite rules synchronously and flush so /community/* URLs resolve
		// on the very first request after activation — no reliance on a deferred init
		// callback firing before the user visits a community URL. The deferred
		// init:99 flush in load_dependencies() still covers version bumps afterwards.
		require_once JETONOMY_DIR . 'includes/class-router.php';
		require_once JETONOMY_DIR . 'includes/class-feed.php';
		( new Router() )->add_rewrite_rules();
		flush_rewrite_rules();
		update_option( 'jetonomy_permalinks_flushed_' . JETONOMY_VERSION, true );

		/*
		 * Only stamp the DB version on a FRESH install, where create_tables() above
		 * has just built every table at the current definition and there is nothing
		 * to migrate.
		 *
		 * On an EXISTING site this must not run. activate() does not run migrations,
		 * so stamping the version here would tell check_db_version() that the site is
		 * already up to date and the pending migrations would never run — not once,
		 * ever. That happens on the ordinary manual-zip upgrade (deactivate, upload,
		 * activate) and on any "deactivate and reactivate to be safe" support advice.
		 *
		 * It was survivable while migrations only added tables, because the
		 * create_tables() self-heal quietly covered for it. 1.7.1 is the first
		 * migration that MOVES DATA (jt_pro_attachments -> jt_attachments), and a
		 * migration that never runs there leaves every attachment on the site
		 * orphaned in a table nothing reads.
		 *
		 * Leaving the stored version alone lets check_db_version() do its job on the
		 * next request, which is the one code path that actually runs migrations.
		 */
		if ( '' === $db_version_before ) {
			update_option( 'jetonomy_db_version', JETONOMY_DB_VERSION );
		}

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
				'reply_to_post'       => array(
					'web'   => true,
					'email' => true,
				),
				'reply_to_reply'      => array(
					'web'   => true,
					'email' => false,
				),
				'mention'             => array(
					'web'   => true,
					'email' => true,
				),
				'accepted_answer'     => array(
					'web'   => true,
					'email' => true,
				),
				'idea_status_changed' => array(
					'web'   => true,
					'email' => true,
				),
				'new_post_in_sub'     => array(
					'web'   => true,
					'email' => false,
				),
				'badge_earned'        => array(
					'web'   => true,
					'email' => false,
				),
				'vote_on_post'        => array(
					'web'   => true,
					'email' => false,
				),
				'reaction'            => array(
					'web'   => true,
					'email' => false,
				),
				'moderation'          => array(
					'web'   => true,
					'email' => true,
				),
				'flag_resolved'       => array(
					'web'   => true,
					'email' => false,
				),
				'join_request'        => array(
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

		// Default the verification-reminder threshold to 24h on fresh
		// installs so the cron has a value to read on its very first tick.
		// Use array_key_exists() so an admin who explicitly set it to 0
		// (disable) keeps that choice on re-activation.
		if ( ! array_key_exists( 'verification_reminder_hours', $settings ) ) {
			$settings['verification_reminder_hours'] = 24;
			$changed                                 = true;
		}

		if ( $changed ) {
			update_option( 'jetonomy_settings', $settings );
		}

		// Seed the default `verification_reminder` email template so the
		// reminder cron has a subject + body to render before the A8 admin
		// editor ships. Only adds the key when it's missing — never
		// overwrites admin customizations. Defaults are sourced from
		// Notifier::get_default_template() (single source of truth — also
		// used by the A8 "Reset to default" button) so the seed and the
		// editor's reset path can never drift.
		$email_templates = get_option( 'jetonomy_email_templates', array() );
		if ( ! is_array( $email_templates ) ) {
			$email_templates = array();
		}
		if ( ! isset( $email_templates['verification_reminder'] ) ) {
			$email_templates['verification_reminder'] = \Jetonomy\Notifications\Notifier::get_default_template( 'verification_reminder' );
			update_option( 'jetonomy_email_templates', $email_templates );
		}

		// Only redirect to the setup wizard on the FIRST activation. Plugin
		// updates re-run activate(); without this guard every update would
		// bounce the admin into the wizard and potentially overwrite
		// already-customized settings (base_slug, default_space_type, etc.).
		if ( ! get_option( 'jetonomy_setup_complete' ) ) {
			set_transient( 'jetonomy_activation_redirect', true, 30 );
		}
	}

	public function deactivate(): void {
		// Delete the versioned flush-key so the next activation triggers a fresh flush.
		delete_option( 'jetonomy_permalinks_flushed_' . JETONOMY_VERSION );
		Cron::unschedule();
		// Background-Jobs Standard §3: clear the orphan sweep from BOTH
		// schedulers too. An unfinished sweep is NOT forgotten — see
		// Privacy_Backfill::unschedule(); it re-arms itself on reactivation.
		Privacy_Backfill::unschedule();
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
		$this->maybe_seed_verification_reminder_defaults();

		Admin_Bar::register();
	}

	/**
	 * One-time seed for the 1.4.1 verification-reminder defaults
	 * (`jetonomy_settings.verification_reminder_hours` + the default
	 * `verification_reminder` email template). activate() handles fresh
	 * installs but every existing 1.4.0 install upgrading in-place needs
	 * the same defaults the first time it loads on 1.4.1. Guarded by a
	 * single option so the work only happens once per site, identical
	 * pattern to maybe_backfill_activity() above.
	 */
	private function maybe_seed_verification_reminder_defaults(): void {
		if ( get_option( 'jetonomy_verification_reminder_seeded' ) ) {
			return;
		}

		$settings = get_option( 'jetonomy_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		if ( ! array_key_exists( 'verification_reminder_hours', $settings ) ) {
			$settings['verification_reminder_hours'] = 24;
			update_option( 'jetonomy_settings', $settings );
		}

		$email_templates = get_option( 'jetonomy_email_templates', array() );
		if ( ! is_array( $email_templates ) ) {
			$email_templates = array();
		}
		if ( ! isset( $email_templates['verification_reminder'] ) ) {
			// Single source of truth — see Notifier::get_default_template()
			// docblock for why both the activate() seed and the upgrade
			// path delegate here.
			$email_templates['verification_reminder'] = \Jetonomy\Notifications\Notifier::get_default_template( 'verification_reminder' );
			update_option( 'jetonomy_email_templates', $email_templates );
		}

		update_option( 'jetonomy_verification_reminder_seeded', true );
	}

	private function maybe_redirect_to_setup(): void {
		// init() runs on plugins_loaded, which fires for every request — admin
		// AND frontend. Without this guard a logged-out visitor, bot, or asset
		// request hitting the site first both gets wrongly bounced into wp-admin
		// and consumes the one-shot transient, so the admin never lands on the
		// wizard. Only act on admin requests; the transient survives until the
		// next admin pageview. (ajax/cron/REST are excluded below for safety.)
		if ( ! is_admin() ) {
			return;
		}
		if ( ! get_transient( 'jetonomy_activation_redirect' ) ) {
			return;
		}

		// IMPORTANT: do not consume (delete) the one-shot transient until we are
		// certain this request is a real browser admin pageview that will be
		// redirected. Deleting it up-front meant the first ajax/cron/network-admin
		// request after activation silently ate the transient and the admin never
		// reached the wizard (Basecamp F15). Each early-return below therefore
		// leaves the transient intact for the next qualifying pageview.

		// Safety net — never bounce into the wizard if setup already completed.
		// Belt-and-braces alongside the guard in activate(); protects against
		// any path that set the transient without consulting setup-complete.
		// Here we DO consume it: setup is done, so the one-shot has no more work.
		if ( get_option( 'jetonomy_setup_complete' ) ) {
			delete_transient( 'jetonomy_activation_redirect' );
			return;
		}

		// ajax/cron/network-admin: a logged-in admin is already browsing, so the
		// next normal admin pageview will redirect. Leave the transient intact —
		// the original 30s TTL from activate() covers this in-session window.
		if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() ) {
			return;
		}
		// Skip the redirect under WP-CLI / WP-Cron-as-CLI / REST contexts.
		// Browser activation still gets the wizard; `wp plugin install --activate`
		// stays clean instead of emitting wp-cli's "trying to do a URL redirect"
		// backtrace. No browser is present, so re-arm with a longer window to
		// survive until the admin's first real page load.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			set_transient( 'jetonomy_activation_redirect', 1, 5 * MINUTE_IN_SECONDS );
			return;
		}

		// Real browser admin request — consume the one-shot and redirect once.
		delete_transient( 'jetonomy_activation_redirect' );
		wp_safe_redirect( admin_url( 'admin.php?page=jetonomy-setup' ) );
		exit;
	}

	private function check_db_version(): void {
		$current = get_option( 'jetonomy_db_version', '0.0.0' );

		if ( version_compare( $current, JETONOMY_DB_VERSION, '<' ) ) {
			// 1) Run any registered data migrations from the stored version forward.
			require_once JETONOMY_DIR . 'includes/db/class-migrator.php';
			DB\Migrator::run( $current );

			// 2) Re-run Schema::create_tables() as a safety net. dbDelta is
			// idempotent — it adds any tables / columns / indexes that exist in
			// the current Schema definition but are missing from the database.
			require_once JETONOMY_DIR . 'includes/db/class-schema.php';
			DB\Schema::create_tables();

			update_option( 'jetonomy_db_version', JETONOMY_DB_VERSION );
			return;
		}

		// db_version is at (or past) target, so the migration loop above never runs.
		// A table the current schema defines can still be MISSING here — a migration
		// that half-completed, a db_version stamped by a dev/beta build before the
		// table existed, or a database restored from a mixed backup. Left alone, every
		// query against that table errors on every request, forever. The create_tables
		// self-heal above cannot reach this state because it is gated on the version
		// being behind — which is the exact gap this repair closes.
		$this->maybe_repair_schema();
	}

	/**
	 * Create any schema table that is missing even though db_version is at target.
	 *
	 * Runs at most once per plugin version (an autoloaded option flag short-circuits
	 * it), and the SHOW TABLES sentinel only triggers real work when a table is
	 * actually absent — so the steady-state cost is a single option read.
	 *
	 * The newest table (jt_attachments) is owned by a DATA migration — it is the
	 * renamed jt_pro_attachments — so a plain create_tables() would make an empty
	 * table and orphan the old rows. We run the migration itself, which is idempotent
	 * (renames/merges, then dbDelta-creates), then the create_tables net for the rest.
	 */
	private function maybe_repair_schema(): void {
		if ( get_option( 'jetonomy_schema_checked' ) === JETONOMY_VERSION ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$present = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'jt_attachments' ) );

		if ( ! $present ) {
			require_once JETONOMY_DIR . 'includes/db/migrations/class-migration_1_7_1.php';
			( new DB\Migrations\Migration_1_7_1() )->up();

			require_once JETONOMY_DIR . 'includes/db/class-schema.php';
			DB\Schema::create_tables();
		}

		update_option( 'jetonomy_schema_checked', JETONOMY_VERSION, true );
	}

	private function load_dependencies(): void {
		// functions.php is required at plugin bootstrap so helpers are available
		// before check_db_version() runs migrations. Do not re-require here.

		// Non-namespaced files still need explicit require
		require_once JETONOMY_DIR . 'includes/class-router.php';
		require_once JETONOMY_DIR . 'includes/class-feed.php';
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

		// Community media library hygiene: tag member uploads and hide them from
		// the admin Media Library by default. register() self-gates to wp-admin.
		( new Media_Library() )->register();
		( new Attachments() )->register();

		// MemberPress adapter (conditional)
		if ( defined( 'MEPR_VERSION' ) ) {
			$mepr = new Adapters\MemberPress_Adapter();
			Adapters\Adapter_Registry::register_membership( 'memberpress', $mepr );
			$mepr->register_hooks();
		}

		// Ollama AI adapter (conditional — self-hosted, free).
		//
		// AI_Adapter has zero in-tree consumers in the free plugin. That's
		// intentional: the registry slot is a Pro-only extension hook. Pro's
		// AI extension consumes Adapter_Registry::get_ai() / get_all_ai() and
		// brings its own provider adapters (OpenAI, Anthropic, custom) plus
		// the moderator / suggester / summarizer features that consume them.
		// Free ships Ollama for the self-hosted no-Pro use case via the
		// AI_Spam_Detector instance below.
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

		// BuddyPress integration: Groups ↔ Spaces sync, group activity
		// broadcast, and comment-to-reply bridge. Gated on BP Groups being
		// active so the file is not parsed on sites without BP. The
		// broadcast + bridge inside the class further gate themselves on
		// the Activity component at runtime so a BP-without-Activity
		// install stays fatal-free.
		if ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) ) {
			require_once JETONOMY_DIR . 'includes/integrations/class-buddypress.php';
			new Integrations\BuddyPress();
		}

		// FluentCommunity integration: navigational bridge plus member
		// sync, topic broadcast, and comment-to-reply bridge. Gated on
		// FC's bootstrap class so the file is not parsed on sites without
		// FluentCommunity. Writes to FC go through FC's own models
		// (Feed, Helper) with class_exists checks at the call site.
		if ( class_exists( '\\FluentCommunity\\App\\App' ) ) {
			require_once JETONOMY_DIR . 'includes/integrations/class-fluent-community.php';
			new Integrations\Fluent_Community();
		}

		// Theme integration — bridges BuddyX / BuddyX Pro / Reign Kirki colors
		// and dark-scheme toggle into Jetonomy's CSS tokens.
		new Integrations\Theme_Integration();
		new Integrations\Layout_CSS();

		// CAPTCHA protection (reCAPTCHA v3 / Cloudflare Turnstile).
		Captcha\Captcha_Manager::init();

		// Local avatars — resolves jt_user_profiles.avatar_url for every
		// get_avatar()/get_avatar_url() caller, Gravatar fallback.
		Avatar::init();

		new Notifications\Notifier();
		new Cron();
		new Privacy();

		new SEO\Sitemap();
		new SEO\Schema_Markup();

		// Social embed oEmbed hooks — Instagram/Facebook token injection + provider registration.
		Embeds::register();
		new Nav_Menus();
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- one-click email unsubscribe is authenticated by the signed token, not a nonce.
		$expires = isset( $_GET['jetonomy_unsub_exp'] ) ? absint( $_GET['jetonomy_unsub_exp'] ) : 0;

		if ( ! $user_id || ! $type ) {
			return;
		}

		// Verify the signed, time-limited token. Unsigned pre-1.5.0 links are no
		// longer honoured — see Notifier::verify_unsubscribe().
		if ( ! Notifications\Notifier::verify_unsubscribe( $user_id, $type, $token, $expires ) ) {
			wp_die( esc_html__( 'This unsubscribe link is invalid or has expired.', 'jetonomy' ), '', array( 'response' => 403 ) );
		}

		// Disable this notification type for the user.
		$profile = Models\UserProfile::find_by_user( $user_id );
		if ( $profile ) {
			$settings                                    = json_decode( $profile->settings ?? '{}', true ) ?: array();
			$settings['notifications'][ $type ]['email'] = false;
			// Must key on user_id: jt_user_profiles has no `id` column, so the base
			// Model::update() (WHERE id = ...) silently failed with "Unknown column
			// 'id'" and the unsubscribe never persisted — the link reported success
			// while the email flag stayed on.
			Models\UserProfile::update_profile( $user_id, array( 'settings' => wp_json_encode( $settings ) ) );
		}

		wp_die(
			esc_html__( 'You have been unsubscribed from these email notifications. You can re-enable them in your notification preferences.', 'jetonomy' ),
			esc_html__( 'Unsubscribed', 'jetonomy' ),
			array( 'response' => 200 )
		);
	}
}
