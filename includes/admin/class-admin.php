<?php
/**
 * Admin UI and settings.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Import\Import_Manager;
use function Jetonomy\table;
use function Jetonomy\now;

class Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_render_setup_wizard' ) );
		// A6: intercepts the CSV export request before any output is sent so
		// the download streams cleanly without admin-header HTML interleaving.
		add_action( 'admin_init', array( $this, 'maybe_export_activity_csv' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'in_admin_header', array( $this, 'hide_third_party_notices' ) );
		add_filter( 'admin_footer_text', array( $this, 'filter_admin_footer_text' ) );
		// A6: persist the per-page screen option for the Activity Log table.
		add_filter( 'set-screen-option', array( $this, 'save_activity_screen_option' ), 10, 3 );

		new Ajax\Categories_Handler();
		new Ajax\Tags_Handler();
		new Ajax\Spaces_Handler();
		new Ajax\Moderation_Handler();
		new Ajax\Users_Handler();
		new Ajax\Import_Handler();
		new Ajax\Settings_Handler();
		new Ajax\Content_Handler();
		new Ajax\Setup_Handler();
	}

	// ── Menu ──

	public function add_menu(): void {
		$menu_label = apply_filters( 'jetonomy_admin_menu_label', __( 'Jetonomy', 'jetonomy' ) );
		$menu_icon  = apply_filters( 'jetonomy_admin_menu_icon', 'dashicons-groups' );

		add_menu_page(
			$menu_label,
			$menu_label,
			'jetonomy_manage_settings',
			'jetonomy',
			array( $this, 'render_dashboard' ),
			$menu_icon,
			30
		);

		add_submenu_page(
			'jetonomy',
			__( 'Dashboard', 'jetonomy' ),
			__( 'Dashboard', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'jetonomy',
			__( 'Categories', 'jetonomy' ),
			__( 'Categories', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-categories',
			array( $this, 'render_categories' )
		);

		add_submenu_page(
			'jetonomy',
			__( 'Tags', 'jetonomy' ),
			__( 'Tags', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-tags',
			array( $this, 'render_tags' )
		);

		add_submenu_page(
			'jetonomy',
			__( 'Spaces', 'jetonomy' ),
			__( 'Spaces', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-spaces',
			array( $this, 'render_spaces' )
		);

		add_submenu_page(
			'jetonomy',
			__( 'Content', 'jetonomy' ),
			__( 'Content', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-content',
			array( $this, 'render_content' )
		);

		add_submenu_page(
			'jetonomy',
			__( 'Moderation', 'jetonomy' ),
			__( 'Moderation', 'jetonomy' ),
			'jetonomy_moderate',
			'jetonomy-moderation',
			array( $this, 'render_moderation' )
		);

		// ── A6: Activity Log ──
		// Sits between Content and Users in the sidebar — read-only audit
		// browser over jt_activity_log. Capability matches every other
		// non-mod admin page so existing settings admins can use it without
		// any cap migration.
		$activity_hook = add_submenu_page(
			'jetonomy',
			__( 'Activity Log', 'jetonomy' ),
			__( 'Activity Log', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-activity',
			array( $this, 'render_activity' )
		);
		if ( $activity_hook ) {
			add_action( "load-{$activity_hook}", array( $this, 'on_activity_load' ) );
		}

		// ── A7: Revisions ──
		// Slots between Activity Log and Users. Read-only browser over
		// jt_revisions (per-object diff viewer). Same capability as the
		// other non-mod admin pages, so no cap migration is needed.
		// Order constraint: Content · Moderation · Activity Log ·
		// Revisions · Users — A6 must remain immediately before; Users
		// must remain immediately after.
		add_submenu_page(
			'jetonomy',
			__( 'Revisions', 'jetonomy' ),
			__( 'Revisions', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-revisions',
			array( $this, 'render_revisions_page' )
		);

		add_submenu_page(
			'jetonomy',
			__( 'Users', 'jetonomy' ),
			__( 'Users', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-users',
			array( $this, 'render_users' )
		);

		add_submenu_page(
			'jetonomy',
			__( 'Import', 'jetonomy' ),
			__( 'Import', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-import',
			array( $this, 'render_import' )
		);

		add_submenu_page(
			'jetonomy',
			__( 'Settings', 'jetonomy' ),
			__( 'Settings', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-settings',
			array( $this, 'render_settings' )
		);

		// Pro-only subpages — only show when Pro is active.
		if ( defined( 'JETONOMY_PRO_VERSION' ) ) {
			add_submenu_page(
				'jetonomy',
				__( 'Extensions', 'jetonomy' ),
				__( 'Extensions', 'jetonomy' ),
				'jetonomy_manage_settings',
				'jetonomy-extensions',
				array( $this, 'render_extensions' )
			);
			// License is now a tab inside Settings — no separate submenu.
		}

		// Hidden setup wizard page (no menu item).
		add_submenu_page( '', __( 'Jetonomy Setup', 'jetonomy' ), '', 'manage_options', 'jetonomy-setup', array( $this, 'render_setup' ) );
	}

	/**
	 * Render the setup wizard as a standalone page.
	 *
	 * Intercepts at admin_init and exits before admin-header.php runs,
	 * preventing strip_tags(null) deprecation on the hidden submenu page.
	 */
	public function maybe_render_setup_wizard(): void {
		if ( ! isset( $_GET['page'] ) || 'jetonomy-setup' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'jetonomy' ) );
		}
		include JETONOMY_DIR . 'includes/admin/views/setup-wizard.php';
		exit;
	}

	// ── Settings API ──

	public function register_settings(): void {
		register_setting(
			'jetonomy_settings',
			'jetonomy_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// Email template overrides live in their own option so they're not
		// nuked when a different tab saves. Sanitized per-row.
		register_setting(
			'jetonomy_settings',
			'jetonomy_email_templates',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_email_templates' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize the email template overrides option.
	 * Each row: { subject: string, body: string }. Both fields are plain
	 * text with supported placeholders — no HTML allowed here.
	 *
	 * @param mixed $input
	 * @return array<string, array{subject: string, body: string}>
	 */
	public function sanitize_email_templates( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$allowed_types = array(
			'user_welcome',
			'reply_to_post',
			'reply_to_reply',
			'mention',
			'accepted_answer',
			'new_post_in_sub',
			'badge_earned',
			'vote_on_post',
			'moderation',
			'join_request',
			// A8: editor row for the A10 reminder cron's email. Without this
			// the form silently strips any verification_reminder override
			// because the loop below only persists allowlisted keys.
			'verification_reminder',
		);

		$clean = array();
		foreach ( $allowed_types as $type ) {
			if ( empty( $input[ $type ] ) || ! is_array( $input[ $type ] ) ) {
				continue;
			}
			$subject = isset( $input[ $type ]['subject'] ) ? sanitize_text_field( (string) $input[ $type ]['subject'] ) : '';
			$body    = isset( $input[ $type ]['body'] ) ? wp_kses_post( (string) $input[ $type ]['body'] ) : '';
			// Only persist rows that actually have an override — keeps the
			// option small and makes "fall back to default" the natural state.
			if ( '' === $subject && '' === $body ) {
				continue;
			}
			$clean[ $type ] = array(
				'subject' => $subject,
				'body'    => $body,
			);
		}
		return $clean;
	}

	public function sanitize_settings( $input ): array {
		// Merge with existing settings so saving one tab doesn't wipe another.
		$existing = get_option( 'jetonomy_settings', array() );
		$clean    = is_array( $existing ) ? $existing : array();

		// ── General tab ──
		// Only process if base_slug is present (General tab was submitted).
		if ( isset( $input['base_slug'] ) ) {
			$new_slug = sanitize_title( $input['base_slug'] ?? 'community' );
			if ( $new_slug !== ( $existing['base_slug'] ?? '' ) ) {
				// Delete the versioned flush key so Router re-registers rules on next load.
				delete_option( 'jetonomy_permalinks_flushed_' . JETONOMY_VERSION );

				// Store the old slug so Router can 301-redirect old URLs.
				$old_base = $existing['base_slug'] ?? '';
				if ( ! empty( $old_base ) ) {
					update_option( 'jetonomy_old_base_slug', $old_base, false );
				}
			}
			$clean['base_slug']          = $new_slug;
			$clean['community_title']    = sanitize_text_field( $input['community_title'] ?? __( 'Community', 'jetonomy' ) );
			$clean['posts_per_page']     = max( 1, absint( $input['posts_per_page'] ?? 20 ) );
			$clean['replies_per_page']   = max( 1, absint( $input['replies_per_page'] ?? 30 ) );
			$raw_space_type              = sanitize_key( (string) ( $input['default_space_type'] ?? 'forum' ) );
			$clean['default_space_type'] = in_array( $raw_space_type, array( 'forum', 'qa', 'ideas', 'feed' ), true ) ? $raw_space_type : 'forum';
			// Community access mode — radio stores "1" (public) or "0" (private).
			$clean['guest_read'] = isset( $input['guest_read'] ) ? (bool) (int) $input['guest_read'] : true;

			// Front-end space creation role allowlist (G6). Validate each
			// posted role against the live wp_roles() registry so a stale or
			// malformed input can't smuggle in an arbitrary string. Empty
			// array (no checkboxes ticked) keeps the gate admin-only.
			$raw_roles                              = is_array( $input['frontend_space_creation_roles'] ?? null ) ? $input['frontend_space_creation_roles'] : array();
			$known_keys                             = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : array();
			$clean['frontend_space_creation_roles'] = array_values(
				array_intersect( array_map( 'sanitize_key', $raw_roles ), $known_keys )
			);

			// Email verification gate for new signups. When ON, the Login
			// block's register flow holds the new account in pending state
			// until the visitor clicks the confirmation link in the email.
			// The reject_pending_verification_login authenticate filter only
			// gates accounts that ALREADY have the pending meta — flipping
			// this setting on does NOT retroactively lock existing users.
			$clean['require_email_verification'] = ! empty( $input['require_email_verification'] );
		}

		// ── Permissions tab ──
		// Only process if trust_thresholds is present (Permissions tab was submitted).
		if ( isset( $input['trust_thresholds'] ) ) {
			$raw_thresholds = is_array( $input['trust_thresholds'] ) ? $input['trust_thresholds'] : array();
			$tl_defaults    = \Jetonomy\Trust\Trust_Levels::defaults();
			foreach ( array( 1, 2, 3 ) as $level ) {
				$td                                  = $tl_defaults[ $level ];
				$lv                                  = is_array( $raw_thresholds[ $level ] ?? null ) ? $raw_thresholds[ $level ] : array();
				$clean['trust_thresholds'][ $level ] = array(
					'posts'            => absint( $lv['posts'] ?? $td['posts'] ),
					'days_active'      => absint( $lv['days_active'] ?? $td['days_active'] ),
					'reputation'       => absint( $lv['reputation'] ?? $td['reputation'] ),
					'replies_received' => absint( $lv['replies_received'] ?? $td['replies_received'] ),
				);
			}
		}

		// Only process if rate_limits is present (Permissions tab was submitted).
		if ( isset( $input['rate_limits'] ) ) {
			$raw_limits           = is_array( $input['rate_limits'] ) ? $input['rate_limits'] : array();
			$rl_defaults          = \Jetonomy\Permissions\Rate_Limiter::defaults();
			$clean['rate_limits'] = array(
				'posts'   => absint( $raw_limits['posts'] ?? $rl_defaults['posts'] ),
				'replies' => absint( $raw_limits['replies'] ?? $rl_defaults['replies'] ),
				'votes'   => absint( $raw_limits['votes'] ?? $rl_defaults['votes'] ),
			);
		}

		// ── Email tab ──
		// Only process if email_from_name is present (Email tab was submitted).
		if ( isset( $input['email_from_name'] ) ) {
			$clean['email_from_name']   = sanitize_text_field( $input['email_from_name'] ?? '' );
			$clean['email_from_email']  = sanitize_email( $input['email_from_email'] ?? '' );
			$clean['email_logo_url']    = esc_url_raw( $input['email_logo_url'] ?? '' );
			$clean['email_footer_text'] = sanitize_text_field( $input['email_footer_text'] ?? '' );

			// Notification defaults — checkbox values absent when unchecked, so default false if not present.
			$notif_types = array(
				'reply_to_post',
				'reply_to_reply',
				'mention',
				'accepted_answer',
				'new_post_in_sub',
				'badge_earned',
				'vote_on_post',
				'moderation',
				'join_request',
			);
			$raw_notif   = is_array( $input['notification_defaults'] ?? null ) ? $input['notification_defaults'] : array();
			foreach ( $notif_types as $nt ) {
				$nt_data                               = is_array( $raw_notif[ $nt ] ?? null ) ? $raw_notif[ $nt ] : array();
				$clean['notification_defaults'][ $nt ] = array(
					'web'   => ! empty( $nt_data['web'] ),
					'email' => ! empty( $nt_data['email'] ),
				);
			}
		}

		// ── Appearance tab ──
		// Only process if accent_color is present (Appearance tab was submitted).
		if ( isset( $input['accent_color'] ) ) {
			$clean['inherit_fonts']  = ! empty( $input['inherit_fonts'] );
			$clean['inherit_colors'] = ! empty( $input['inherit_colors'] );
			$clean['accent_color']   = sanitize_hex_color( $input['accent_color'] ?? '#0073aa' );
			$clean['layout_density'] = sanitize_text_field( $input['layout_density'] ?? 'comfortable' );
			$clean['custom_css']     = wp_strip_all_tags( $input['custom_css'] ?? '' );

			$raw_width                       = sanitize_key( (string) ( $input['container_width'] ?? 'theme' ) );
			$clean['container_width']        = in_array( $raw_width, array( 'theme', 'full', 'custom' ), true ) ? $raw_width : 'theme';
			$clean['container_width_custom'] = max( 600, min( 2400, absint( $input['container_width_custom'] ?? 1280 ) ) );

			$raw_sidebar                 = sanitize_key( (string) ( $input['sidebar_visibility'] ?? 'theme' ) );
			$clean['sidebar_visibility'] = in_array( $raw_sidebar, array( 'theme', 'hide' ), true ) ? $raw_sidebar : 'theme';

			$raw_padding             = sanitize_key( (string) ( $input['padding_preset'] ?? 'theme' ) );
			$clean['padding_preset'] = in_array( $raw_padding, array( 'theme', 'none', 'comfortable' ), true ) ? $raw_padding : 'theme';
		}

		// ── Anti-Spam tab ──
		// Only process if captcha_provider is present (Anti-Spam tab was submitted).
		if ( isset( $input['captcha_provider'] ) ) {
			$allowed_providers                = array( 'none', 'recaptcha_v3', 'turnstile' );
			$raw_provider                     = sanitize_text_field( $input['captcha_provider'] ?? 'none' );
			$clean['captcha_provider']        = in_array( $raw_provider, $allowed_providers, true ) ? $raw_provider : 'none';
			$clean['captcha_site_key']        = sanitize_text_field( $input['captcha_site_key'] ?? '' );
			$clean['captcha_secret_key']      = sanitize_text_field( $input['captcha_secret_key'] ?? '' );
			$raw_threshold                    = (float) ( $input['captcha_score_threshold'] ?? 0.5 );
			$clean['captcha_score_threshold'] = max( 0.1, min( 0.9, $raw_threshold ) );
		}

		// ── SEO tab ──
		// Only process if seo_post_title is present (SEO tab was submitted).
		if ( isset( $input['seo_post_title'] ) ) {
			$clean['seo_post_title']       = sanitize_text_field( $input['seo_post_title'] ?? '{post_title} - {space_name} | {site_name}' );
			$clean['seo_space_title']      = sanitize_text_field( $input['seo_space_title'] ?? '{space_name} | {site_name}' );
			$clean['seo_schema']           = ! empty( $input['seo_schema'] );
			$clean['seo_sitemap']          = ! empty( $input['seo_sitemap'] );
			$clean['seo_noindex_profiles'] = ! empty( $input['seo_noindex_profiles'] );
			$clean['seo_noindex_search']   = ! empty( $input['seo_noindex_search'] );

			// Twitter / X site handle (D.6) — emitted as `twitter:site` on
			// every public route. Strip leading @ if the admin types it; the
			// emitter prepends it back so the value stored is a plain handle.
			$raw_twitter                 = trim( (string) ( $input['seo_twitter_handle'] ?? '' ) );
			$clean['seo_twitter_handle'] = preg_replace( '/[^A-Za-z0-9_]/', '', ltrim( $raw_twitter, '@' ) );

			// Default share image URL (D.6) — falls back into the og:image
			// chain (route image → admin default → custom logo → site icon).
			$clean['seo_default_og_image'] = esc_url_raw( $input['seo_default_og_image'] ?? '' );

			// Social embeds — Meta developer app credentials for Instagram/Facebook oEmbed.
			// App IDs are numeric; secrets are 32-char hex strings. Strip whitespace only.
			$clean['fb_app_id']     = preg_replace( '/\D/', '', (string) ( $input['fb_app_id'] ?? '' ) );
			$clean['fb_app_secret'] = trim( sanitize_text_field( $input['fb_app_secret'] ?? '' ) );
		}

		return $clean;
	}

	// ── Assets ──

	/**
	 * Hide third-party admin notices on Jetonomy pages.
	 */
	/**
	 * Apply the `jetonomy_admin_footer_text` filter on Jetonomy admin pages.
	 * Lets extensions (e.g. Pro white-label) replace the WordPress default
	 * "Thank you for creating with WordPress" line on plugin screens.
	 *
	 * @since 1.4.1
	 *
	 * @param string $text Default WordPress footer text.
	 * @return string Filtered text.
	 */
	public function filter_admin_footer_text( $text ) {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'jetonomy' ) ) {
			return $text;
		}
		/**
		 * Filter the admin footer text shown on Jetonomy admin pages.
		 *
		 * @since 1.4.1
		 * @param string $text Current footer text.
		 */
		return (string) apply_filters( 'jetonomy_admin_footer_text', (string) $text );
	}

	public function hide_third_party_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'jetonomy' ) ) {
			return;
		}

		// Remove all notice hooks except our own.
		global $wp_filter;
		foreach ( array( 'admin_notices', 'all_admin_notices' ) as $hook_name ) {
			if ( empty( $wp_filter[ $hook_name ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $key => $callback ) {
					if ( $this->is_own_notice_callback( $callback['function'] ?? null ) ) {
						continue;
					}
					unset( $wp_filter[ $hook_name ]->callbacks[ $priority ][ $key ] );
				}
			}
		}
	}

	/**
	 * Decide whether a notice callback belongs to Jetonomy or Wbcom code.
	 *
	 * Without the closure branch, every Pro extension that registers a
	 * save-confirmation notice via add_action( 'admin_notices', function () { ... } )
	 * has its notice silently stripped on every Jetonomy admin screen, so the
	 * customer saves the form, sees nothing, and concludes the save did not work.
	 *
	 * @param mixed $fn Callback function part of a $wp_filter callback entry.
	 */
	private function is_own_notice_callback( $fn ): bool {
		// Built-in WordPress settings errors output.
		if ( is_string( $fn ) && 'settings_errors' === $fn ) {
			return true;
		}

		// Named functions whose symbol contains our slug.
		if ( is_string( $fn ) && ( str_contains( $fn, 'jetonomy' ) || str_contains( $fn, 'wbcom' ) ) ) {
			return true;
		}

		// [ $object, 'method' ] callbacks where the class belongs to us.
		if ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) ) {
			$class = get_class( $fn[0] );
			if ( str_contains( $class, 'Jetonomy' ) || str_contains( $class, 'Wbcom' ) ) {
				return true;
			}
		}

		// Anonymous closures defined inside our own plugin files. Reflection
		// is the only reliable way to attribute a closure to source code; a
		// class check fails because every closure reports class "Closure".
		if ( $fn instanceof \Closure ) {
			try {
				$file = (string) ( new \ReflectionFunction( $fn ) )->getFileName();
			} catch ( \Throwable $e ) {
				// Fail open: a third-party closure leaking through is less
				// harmful than swallowing one of our own save confirmations.
				return true;
			}
			if ( '' === $file ) {
				return true;
			}
			$file = wp_normalize_path( $file );
			if ( str_contains( $file, '/jetonomy/' ) || str_contains( $file, '/jetonomy-pro/' ) || str_contains( $file, '/wbcom' ) ) {
				return true;
			}
		}

		return false;
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'jetonomy' ) ) {
			return;
		}

		wp_enqueue_style(
			'jetonomy-admin',
			JETONOMY_URL . 'assets/css/admin.css',
			array(),
			JETONOMY_VERSION
		);

		// Shared modal toolkit (1.4.0) — registers window.jetonomyConfirm /
		// jetonomyAlert / jetonomyPrompt globally for wp-admin too. Same
		// implementation as the front-end so all confirms / prompts share
		// the same UX.
		if ( ! wp_script_is( 'jetonomy-modals', 'registered' ) ) {
			wp_register_script(
				'jetonomy-modals',
				JETONOMY_URL . 'assets/js/jetonomy-modals.js',
				array(),
				JETONOMY_VERSION,
				true
			);
		}
		// Admin pages need the .jt-modal-* CSS classes the toolkit relies on,
		// which live in the front-end stylesheet. Enqueue it on Jetonomy admin
		// pages too — the rules are scoped + don't bleed into core wp-admin.
		wp_enqueue_style(
			'jetonomy',
			JETONOMY_URL . 'assets/css/jetonomy.css',
			array(),
			JETONOMY_VERSION
		);

		wp_enqueue_script(
			'jetonomy-admin',
			JETONOMY_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker', 'jetonomy-modals' ),
			JETONOMY_VERSION,
			true
		);

		wp_enqueue_style( 'wp-color-picker' );

		// Code editor for custom CSS
		$page = sanitize_text_field( $_GET['page'] ?? '' );
		if ( 'jetonomy-settings' === $page ) {
			$cm_settings = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
			if ( false !== $cm_settings ) {
				wp_localize_script( 'jetonomy-admin', 'jetonomyCmSettings', $cm_settings );
			}
		}

		// Media uploader
		wp_enqueue_media();

		// Gather membership adapters with their levels for the access rules UI.
		$adapter_labels = array(
			'wp-roles'    => __( 'WP Role', 'jetonomy' ),
			'memberpress' => __( 'MemberPress Plan', 'jetonomy' ),
			'pmpro'       => __( 'PMPro Level', 'jetonomy' ),
			'woocommerce' => __( 'WooCommerce Membership', 'jetonomy' ),
			'rcp'         => __( 'RCP Membership', 'jetonomy' ),
			'learndash'   => __( 'LearnDash Course', 'jetonomy' ),
			'tutor'       => __( 'Tutor Course', 'jetonomy' ),
			'lifterlms'   => __( 'LifterLMS Course', 'jetonomy' ),
			'sensei'      => __( 'Sensei Course', 'jetonomy' ),
			'masterstudy' => __( 'MasterStudy Course', 'jetonomy' ),
		);

		$membership_adapters = array();
		$all_adapters        = \Jetonomy\Adapters\Adapter_Registry::get_all_membership();
		foreach ( $all_adapters as $adapter_id => $adapter ) {
			if ( $adapter->is_active() && 'wp-roles' !== $adapter_id ) {
				$levels = array();
				foreach ( $adapter->get_all_levels() as $level ) {
					$levels[] = array(
						'id'    => $level['id'],
						'label' => $level['label'],
					);
				}
				$membership_adapters[] = array(
					'id'     => $adapter_id,
					'label'  => $adapter_labels[ $adapter_id ] ?? ucfirst( $adapter_id ),
					'levels' => $levels,
				);
			}
		}

		wp_localize_script(
			'jetonomy-admin',
			'jetonomyAdmin',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( 'jetonomy_admin' ),
				'membershipAdapters' => $membership_adapters,
				'i18n'               => array(
					'confirmDelete'           => esc_html__( 'Are you sure? This cannot be undone.', 'jetonomy' ),
					'confirmBan'              => esc_html__( 'Are you sure you want to ban this user?', 'jetonomy' ),
					'saving'                  => esc_html__( 'Saving...', 'jetonomy' ),
					'saved'                   => esc_html__( 'Saved!', 'jetonomy' ),
					'deleted'                 => esc_html__( 'Deleted.', 'jetonomy' ),
					'error'                   => esc_html__( 'Something went wrong.', 'jetonomy' ),
					'importing'               => esc_html__( 'Importing...', 'jetonomy' ),
					'importDone'              => esc_html__( 'Import complete!', 'jetonomy' ),
					'selectImage'             => esc_html__( 'Select Image', 'jetonomy' ),
					'useImage'                => esc_html__( 'Use this image', 'jetonomy' ),
					'testEmailSent'           => esc_html__( 'Test email sent!', 'jetonomy' ),
					'rewritesFlushed'         => esc_html__( 'Rewrite rules flushed.', 'jetonomy' ),
					'unban'                   => esc_html__( 'Unban', 'jetonomy' ),
					'ban'                     => esc_html__( 'Ban', 'jetonomy' ),
					'demoCleanupConfirm'      => esc_html__( 'Delete all sample categories, spaces, posts, and replies from the setup wizard? Your own content is not affected.', 'jetonomy' ),
					'demoCleanupRemoving'     => esc_html__( 'Removing...', 'jetonomy' ),
					'revisionViewDiff'        => esc_html__( 'View diff', 'jetonomy' ),
					'revisionHideDiff'        => esc_html__( 'Hide diff', 'jetonomy' ),
					'tagNameRequired'         => esc_html__( 'Name is required.', 'jetonomy' ),
					'tagDeleteConfirm'        => esc_html__( 'Delete this tag?', 'jetonomy' ),
					'tagDeleteAttachedPrefix' => esc_html__( 'This tag is attached to', 'jetonomy' ),
					'tagDeleteAttachedSuffix' => esc_html__( 'posts. Delete it and detach from all posts?', 'jetonomy' ),
					'tagBulkSelectAtLeastOne' => esc_html__( 'Select at least one tag.', 'jetonomy' ),
					'tagBulkDeleteConfirm'    => esc_html__( 'Delete the selected tags?', 'jetonomy' ),
					'emailPreviewFailed'      => esc_html__( 'Preview failed.', 'jetonomy' ),
					'emailPreviewTitle'       => esc_html__( 'Email Preview', 'jetonomy' ),
					'emailSending'            => esc_html__( 'Sending...', 'jetonomy' ),
					'emailSent'               => esc_html__( 'Sent.', 'jetonomy' ),
					'emailSendFailed'         => esc_html__( 'Failed to send.', 'jetonomy' ),
					/* translators: %s: email template label */
					'emailResetConfirm'       => esc_html__( 'Reset %s to default? Your custom copy will be lost.', 'jetonomy' ),
					'emailResetFailed'        => esc_html__( 'Reset failed.', 'jetonomy' ),
					'hiddenForcesInvite'      => esc_html__( 'Hidden spaces must use Invite Only. Join policy switched.', 'jetonomy' ),
					'hiddenRequiresInvite'    => esc_html__( 'Switched visibility to Private because Hidden requires Invite Only.', 'jetonomy' ),
				),
			)
		);

		// Per-page admin scripts. Hook suffix matches WP's auto-generated
		// menu_page_url hook ('toplevel_page_jetonomy' for the dashboard,
		// 'jetonomy_page_jetonomy-{slug}' for sub-pages).
		if ( 'toplevel_page_jetonomy' === $hook ) {
			wp_enqueue_script(
				'jetonomy-admin-dashboard',
				JETONOMY_URL . 'assets/js/admin-dashboard.js',
				array( 'jetonomy-admin' ),
				JETONOMY_VERSION,
				true
			);
		} elseif ( 'jetonomy_page_jetonomy-revisions' === $hook ) {
			wp_enqueue_script(
				'jetonomy-admin-revisions',
				JETONOMY_URL . 'assets/js/admin-revisions.js',
				array( 'jetonomy-admin' ),
				JETONOMY_VERSION,
				true
			);
		} elseif ( 'jetonomy_page_jetonomy-tags' === $hook ) {
			wp_enqueue_script(
				'jetonomy-admin-tags',
				JETONOMY_URL . 'assets/js/admin-tags.js',
				array( 'jetonomy-admin' ),
				JETONOMY_VERSION,
				true
			);
		} elseif ( 'jetonomy_page_jetonomy-settings' === $hook ) {
			wp_enqueue_script(
				'jetonomy-admin-settings',
				JETONOMY_URL . 'assets/js/admin-settings.js',
				array( 'jetonomy-admin' ),
				JETONOMY_VERSION,
				true
			);
		}
	}

	// ── Page Renderers ──

	public function render_dashboard(): void {
		global $wpdb;

		$posts_t    = table( 'posts' );
		$replies_t  = table( 'replies' );
		$spaces_t   = table( 'spaces' );
		$users_t    = table( 'user_profiles' );
		$flags_t    = table( 'flags' );
		$activity_t = table( 'activity_log' );

		$today = current_time( 'Y-m-d' );

		$stats = array(
			'total_posts'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$posts_t} WHERE status = 'publish'" ),
			'total_replies' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$replies_t} WHERE status = 'publish'" ),
			'active_spaces' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$spaces_t} WHERE status = 'active'" ),
			'users'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$users_t}" ),
			'pending_flags' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flags_t} WHERE status = 'pending'" ),
			'posts_today'   => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$posts_t} WHERE status = 'publish' AND created_at >= %s",
					$today . ' 00:00:00'
				)
			),
		);

		$recent_activity = $wpdb->get_results(
			"SELECT * FROM {$activity_t} ORDER BY created_at DESC LIMIT 10"
		) ?: array();

		$settings  = get_option( 'jetonomy_settings', array() );
		$base_slug = $settings['base_slug'] ?? 'community';

		include JETONOMY_DIR . 'includes/admin/views/dashboard.php';
	}

	public function render_categories(): void {
		// Flat list of every category (for the parent-select dropdowns) —
		// dropdown needs all values regardless of pagination.
		$all_categories = $this->get_all_categories_nested();

		// Paginated top-level categories for the main table.
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = absint( $_GET['per_page'] ?? 20 );
		if ( ! in_array( $per_page, array( 20, 50, 100 ), true ) ) {
			$per_page = 20;
		}
		$search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$orderby = sanitize_key( wp_unslash( $_GET['orderby'] ?? 'sort_order' ) );
		$order   = 'DESC' === strtoupper( sanitize_key( wp_unslash( $_GET['order'] ?? 'ASC' ) ) ) ? 'DESC' : 'ASC';
		$offset  = ( $paged - 1 ) * $per_page;

		$result           = Category::list_paginated( $search, $orderby, $order, $per_page, $offset );
		$categories       = $result['rows'];
		$categories_total = (int) $result['total'];
		$categories_pages = (int) ceil( $categories_total / $per_page );

		include JETONOMY_DIR . 'includes/admin/views/categories.php';
	}

	/**
	 * Tags admin page — paginated list with search, sort, add, edit, bulk delete.
	 *
	 * Pagination is server-side so the page scales to 10k+ tags without
	 * loading everything into the DOM at once.
	 */
	public function render_tags(): void {
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = absint( $_GET['per_page'] ?? 20 );
		if ( ! in_array( $per_page, array( 20, 50, 100 ), true ) ) {
			$per_page = 20;
		}
		$search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$orderby = sanitize_key( wp_unslash( $_GET['orderby'] ?? 'name' ) );
		$order   = 'DESC' === strtoupper( sanitize_key( wp_unslash( $_GET['order'] ?? 'ASC' ) ) ) ? 'DESC' : 'ASC';

		$offset = ( $paged - 1 ) * $per_page;
		$result = \Jetonomy\Models\Tag::list_paginated( $search, $orderby, $order, $per_page, $offset );

		$tags        = $result['rows'];
		$tags_total  = (int) $result['total'];
		$total_pages = (int) ceil( $tags_total / $per_page );

		include JETONOMY_DIR . 'includes/admin/views/tags.php';
	}

	public function render_spaces(): void {
		global $wpdb;

		$action   = sanitize_text_field( $_GET['action'] ?? 'list' );
		$space_id = absint( $_GET['space_id'] ?? 0 );

		if ( 'edit' === $action && $space_id > 0 ) {
			$space = Space::find( $space_id );
			if ( ! $space ) {
				wp_die( esc_html__( 'Space not found.', 'jetonomy' ) );
			}
			$categories     = $this->get_all_categories_flat();
			$members        = SpaceMember::list_by_space( $space_id );
			$access_rules   = AccessRule::list_for_space( $space_id );
			$space_settings = Space::get_settings( $space_id );
			$join_requests  = JoinRequest::list_pending_for_space( $space_id );
			include JETONOMY_DIR . 'includes/admin/views/space-edit.php';
			return;
		}

		// List view
		$filter_category = absint( $_GET['category_id'] ?? 0 );
		$filter_type     = sanitize_text_field( $_GET['type'] ?? '' );
		$filter_status   = sanitize_text_field( $_GET['status'] ?? '' );

		$where = array( '1=1' );
		if ( $filter_category ) {
			$where[] = $wpdb->prepare( 'category_id = %d', $filter_category );
		}
		if ( $filter_type && in_array( $filter_type, array( 'forum', 'qa', 'ideas', 'feed' ), true ) ) {
			$where[] = $wpdb->prepare( 'type = %s', $filter_type );
		}
		if ( $filter_status && in_array( $filter_status, array( 'active', 'archived', 'locked' ), true ) ) {
			$where[] = $wpdb->prepare( 'status = %s', $filter_status );
		}

		$where_sql   = implode( ' AND ', $where );
		$spaces_t    = table( 'spaces' );
		$paged       = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page    = 20;
		$offset      = ( $paged - 1 ) * $per_page;
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$spaces_t} WHERE {$where_sql}" );
		$total_pages = (int) ceil( $total / $per_page );
		$spaces      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$spaces_t} WHERE {$where_sql} ORDER BY title ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		) ?: array();
		$categories  = $this->get_all_categories_flat();

		include JETONOMY_DIR . 'includes/admin/views/spaces.php';
	}

	public function render_moderation(): void {
		global $wpdb;

		$posts_t        = table( 'posts' );
		$replies_t      = table( 'replies' );
		$flags_t        = table( 'flags' );
		$restrictions_t = table( 'restrictions' );
		$per_page       = 20;

		// Per-tab paged params.
		$paged_posts   = max( 1, absint( $_GET['paged_posts'] ?? 1 ) );
		$paged_replies = max( 1, absint( $_GET['paged_replies'] ?? 1 ) );
		$paged_flags   = max( 1, absint( $_GET['paged_flags'] ?? 1 ) );
		$paged_banned  = max( 1, absint( $_GET['paged_banned'] ?? 1 ) );

		// Real totals for tab badge counts.
		$total_posts   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$posts_t} WHERE status = 'pending'" );
		$total_replies = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$replies_t} WHERE status = 'pending'" );
		$total_flags   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$flags_t} WHERE status = 'pending'" );
		$total_banned  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$restrictions_t}
				 WHERE type IN ('global_ban','space_ban','silence')
				 AND (expires_at IS NULL OR expires_at > %s)",
				now()
			)
		);

		$pending_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, s.title as space_title
				 FROM {$posts_t} p
				 LEFT JOIN " . table( 'spaces' ) . " s ON s.id = p.space_id
				 WHERE p.status = 'pending'
				 ORDER BY p.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				( $paged_posts - 1 ) * $per_page
			)
		) ?: array();

		$pending_replies = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, p.title as post_title
				 FROM {$replies_t} r
				 LEFT JOIN {$posts_t} p ON p.id = r.post_id
				 WHERE r.status = 'pending'
				 ORDER BY r.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				( $paged_replies - 1 ) * $per_page
			)
		) ?: array();

		$pending_flags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$flags_t}
				 WHERE status = 'pending'
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				( $paged_flags - 1 ) * $per_page
			)
		) ?: array();

		$banned_users = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, u.display_name, u.user_login
				 FROM {$restrictions_t} r
				 LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
				 WHERE r.type IN ('global_ban','space_ban','silence')
				 AND (r.expires_at IS NULL OR r.expires_at > %s)
				 ORDER BY r.created_at DESC
				 LIMIT %d OFFSET %d",
				now(),
				$per_page,
				( $paged_banned - 1 ) * $per_page
			)
		) ?: array();

		include JETONOMY_DIR . 'includes/admin/views/moderation.php';
	}

	// ── A6: Activity Log ──

	/**
	 * load-* hook for the Activity Log screen — registers the per-page
	 * screen option BEFORE prepare_items() runs so admins can pick a
	 * non-default page size without their preference being ignored.
	 */
	public function on_activity_load(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Entries per page', 'jetonomy' ),
				'default' => 20,
				'option'  => 'jetonomy_activity_per_page',
			)
		);
	}

	/**
	 * Persist the screen-option value when the user saves it. WP only
	 * applies returned non-false values, so a defensive cast keeps the
	 * stored value within sane bounds.
	 *
	 * @param mixed  $status Current return value from earlier filters.
	 * @param string $option Option key being saved.
	 * @param mixed  $value  Submitted value.
	 */
	public function save_activity_screen_option( $status, $option, $value ) {
		if ( 'jetonomy_activity_per_page' === $option ) {
			$value = absint( $value );
			if ( $value < 1 || $value > 200 ) {
				$value = 20;
			}
			return $value;
		}
		return $status;
	}

	/**
	 * Render the Activity Log admin page.
	 */
	public function render_activity(): void {
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'jetonomy' ) );
		}

		$list_table = new Activity_List_Table();
		$list_table->prepare_items();

		include JETONOMY_DIR . 'includes/admin/views/activity.php';
	}

	/**
	 * Stream the filtered Activity Log as CSV.
	 *
	 * Triggered when admin.php?page=jetonomy-activity&action=export_csv is
	 * loaded with a valid nonce. Runs on admin_init so headers can be sent
	 * before any wp-admin chrome renders. Filters mirror the list table —
	 * the same read_filters() helper feeds both code paths.
	 */
	public function maybe_export_activity_csv(): void {
		if ( ! isset( $_GET['page'], $_GET['action'] ) ) {
			return;
		}
		if ( 'jetonomy-activity' !== $_GET['page'] || 'export_csv' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to export activity.', 'jetonomy' ) );
		}
		check_admin_referer( 'jetonomy_activity_export' );

		global $wpdb;
		$activity_t = table( 'activity_log' );
		$filters    = Activity_List_Table::read_filters();

		$clauses = array( '1=1' );
		$args    = array();
		if ( $filters['user_id'] > 0 ) {
			$clauses[] = 'user_id = %d';
			$args[]    = $filters['user_id'];
		}
		if ( '' !== $filters['action'] ) {
			$clauses[] = 'action = %s';
			$args[]    = $filters['action'];
		}
		if ( '' !== $filters['date_from'] ) {
			$clauses[] = 'created_at >= %s';
			$args[]    = $filters['date_from'] . ' 00:00:00';
		}
		if ( '' !== $filters['date_to'] ) {
			$clauses[] = 'created_at <= %s';
			$args[]    = $filters['date_to'] . ' 23:59:59';
		}
		$where = implode( ' AND ', $clauses );

		// Hard cap at 50k rows so a sloppy filter set can't generate a
		// gigabyte download. Admins who need bigger exports should
		// narrow the date range or use WP-CLI directly against the table.
		$sql       = "SELECT id, user_id, action, object_type, object_id, metadata, created_at FROM {$activity_t} WHERE {$where} ORDER BY created_at DESC LIMIT 50000";
		$full_args = $args;
		$rows      = $full_args
			? $wpdb->get_results( $wpdb->prepare( $sql, ...$full_args ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );
		$rows      = is_array( $rows ) ? $rows : array();

		$filename = sprintf( 'jetonomy-activity-%s.csv', gmdate( 'Y-m-d-His' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		// php://output is the stdout stream — WP_Filesystem doesn't model it.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}
		fputcsv( $out, array( 'id', 'user_id', 'user_login', 'action', 'object_type', 'object_id', 'metadata', 'created_at' ) );
		foreach ( $rows as $row ) {
			$user       = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
			$user_login = $user ? $user->user_login : '';
			fputcsv(
				$out,
				array(
					(string) $row['id'],
					(string) $row['user_id'],
					$user_login,
					(string) $row['action'],
					(string) $row['object_type'],
					(string) $row['object_id'],
					(string) ( $row['metadata'] ?? '' ),
					(string) $row['created_at'],
				)
			);
		}
		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		exit;
	}

	// ── A7: Revisions ──

	/**
	 * Render the Revisions admin page.
	 *
	 * Two modes branch on the URL:
	 *   - List mode (no object_id): aggregate WP_List_Table.
	 *   - Detail mode (object_type + object_id): per-object diff viewer
	 *     using wp_text_diff() against the previous snapshot.
	 *
	 * Capability gate is duplicated here on top of the menu cap so a
	 * direct URL hit fails closed with wp_die() rather than rendering a
	 * blank page.
	 */
	public function render_revisions_page(): void {
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'jetonomy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation params.
		$raw_type  = isset( $_GET['object_type'] ) ? sanitize_key( wp_unslash( $_GET['object_type'] ) ) : '';
		$object_id = isset( $_GET['object_id'] ) ? absint( wp_unslash( $_GET['object_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$is_detail = ( in_array( $raw_type, array( 'post', 'reply' ), true ) && $object_id > 0 );

		if ( $is_detail ) {
			$mode        = 'detail';
			$object_type = $raw_type;
			$revisions   = \Jetonomy\Models\Revision::list_for_object( $object_type, $object_id );

			// Resolve a human-readable title for the page heading. Posts
			// have a title column; replies fall back to the parent post
			// title (prefixed) so the row stays scannable even though
			// replies are titleless.
			$object_title = $this->resolve_revision_object_title( $object_type, $object_id );

			$back_url = admin_url( 'admin.php?page=jetonomy-revisions' );

			include JETONOMY_DIR . 'includes/admin/views/revisions.php';
			return;
		}

		$mode       = 'list';
		$list_table = new Revisions_List_Table();
		$list_table->prepare_items();

		include JETONOMY_DIR . 'includes/admin/views/revisions.php';
	}

	/**
	 * Resolve a human-readable title for a (type, id) pair. Single-row
	 * lookup since the detail view only renders one object at a time —
	 * no batching needed here.
	 */
	private function resolve_revision_object_title( string $type, int $id ): string {
		global $wpdb;

		if ( 'post' === $type ) {
			$posts_t = table( 'posts' );
			$title   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT title FROM {$posts_t} WHERE id = %d",
					$id
				)
			);
			return is_string( $title ) ? $title : '';
		}

		if ( 'reply' === $type ) {
			$replies_t = table( 'replies' );
			$posts_t   = table( 'posts' );
			$parent    = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT p.title FROM {$replies_t} r LEFT JOIN {$posts_t} p ON p.id = r.post_id WHERE r.id = %d",
					$id
				)
			);
			if ( is_string( $parent ) && '' !== $parent ) {
				return sprintf(
					/* translators: %s: parent post title */
					__( 'Reply to: %s', 'jetonomy' ),
					$parent
				);
			}
			return '';
		}

		return '';
	}

	public function render_users(): void {
		global $wpdb;

		$search       = sanitize_text_field( $_GET['s'] ?? '' );
		$filter_trust = sanitize_text_field( $_GET['trust_level'] ?? '' );
		$paged        = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page     = 20;
		$offset       = ( $paged - 1 ) * $per_page;

		$profiles_t = table( 'user_profiles' );

		$where      = array( '1=1' );
		$join_where = '';

		if ( '' !== $filter_trust && is_numeric( $filter_trust ) ) {
			$where[] = $wpdb->prepare( 'p.trust_level = %d', absint( $filter_trust ) );
		}
		if ( $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(u.user_login LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)', $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$profiles_t} p
			 INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
			 WHERE {$where_sql}"
		);

		$users = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*, u.user_login, u.display_name as wp_display_name, u.user_email, u.user_registered
				 FROM {$profiles_t} p
				 INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
				 WHERE {$where_sql}
				 ORDER BY p.reputation DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		) ?: array();

		$total_pages = ceil( $total / $per_page );

		include JETONOMY_DIR . 'includes/admin/views/users.php';
	}

	public function render_import(): void {
		Import_Manager::init();
		$available = Import_Manager::get_available();
		include JETONOMY_DIR . 'includes/admin/views/import.php';
	}

	public function render_settings(): void {
		$settings = get_option( 'jetonomy_settings', array() );
		include JETONOMY_DIR . 'includes/admin/views/settings.php';
	}

	/**
	 * Render the Extensions page — content provided by Pro via hook.
	 */
	public function render_extensions(): void {
		/**
		 * Fires to render the Extensions page content.
		 * Hooked by Jetonomy Pro to display the extensions manager.
		 */
		do_action( 'jetonomy_admin_render_extensions' );
	}

	/**
	 * Render the License page — content provided by Pro via hook.
	 */
	public function render_license(): void {
		/**
		 * Fires to render the License page content.
		 * Hooked by Jetonomy Pro to display the license form.
		 */
		do_action( 'jetonomy_admin_render_license' );
	}

	// ── Helpers ──

	private function get_all_categories_nested(): array {
		$top    = Category::list_top_level();
		$result = array();
		foreach ( $top as $cat ) {
			$cat->children = Category::list_children( (int) $cat->id );
			$result[]      = $cat;
		}
		return $result;
	}

	private function get_all_categories_flat(): array {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT * FROM ' . table( 'categories' ) . ' ORDER BY sort_order ASC, name ASC'
		) ?: array();
	}

	// ═══════════════════════════════════════════════════════════════
	// AJAX: Spaces
	// ═══════════════════════════════════════════════════════════════



	// ═══════════════════════════════════════════════════════════════
	// AJAX: Space Members (moved to Spaces_Handler)
	// ═══════════════════════════════════════════════════════════════



	// ═══════════════════════════════════════════════════════════════
	// AJAX: Access Rules (moved to Spaces_Handler)
	// ═══════════════════════════════════════════════════════════════


	// ═══════════════════════════════════════════════════════════════
	// AJAX: Users
	// ═══════════════════════════════════════════════════════════════





	// ═══════════════════════════════════════════════════════════════
	// AJAX: Misc
	// ═══════════════════════════════════════════════════════════════


	// ═══════════════════════════════════════════════════════════════
	// Content Management
	// ═══════════════════════════════════════════════════════════════

	public function render_content(): void {
		// Branch: if a post_id is given, show that post's replies page.
		$post_id = absint( $_GET['post_id'] ?? 0 );
		if ( $post_id ) {
			$this->render_post_replies( $post_id );
			return;
		}

		global $wpdb;
		$posts_t  = table( 'posts' );
		$spaces_t = table( 'spaces' );

		$current_space  = absint( $_GET['space_id'] ?? 0 );
		$current_status = sanitize_text_field( $_GET['status'] ?? 'all' );
		$search_query   = sanitize_text_field( $_GET['s'] ?? '' );

		$spaces = $wpdb->get_results( "SELECT id, title FROM {$spaces_t} ORDER BY title ASC" ) ?: array();

		$where = '1=1';
		$args  = array();
		if ( $current_space ) {
			$where .= ' AND p.space_id = %d';
			$args[] = $current_space;
		}
		if ( 'all' !== $current_status ) {
			$where .= ' AND p.status = %s';
			$args[] = $current_status;
		}
		if ( $search_query ) {
			$where .= ' AND p.title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search_query ) . '%';
		}

		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		// Total count with same filters (no LIMIT).
		$count_sql   = "SELECT COUNT(*) FROM {$posts_t} p WHERE {$where}";
		$total       = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) );
		$total_pages = (int) ceil( $total / $per_page );

		$sql = "SELECT p.*, s.title AS space_title, s.slug AS space_slug
		        FROM {$posts_t} p
		        LEFT JOIN {$spaces_t} s ON s.id = p.space_id
		        WHERE {$where}
		        ORDER BY p.created_at DESC
		        LIMIT %d OFFSET %d";

		$full_args = array_merge( $args, array( $per_page, $offset ) );
		$posts     = $wpdb->get_results( $wpdb->prepare( $sql, ...$full_args ) ) ?: array();

		include JETONOMY_DIR . 'includes/admin/views/content.php';
	}

	/**
	 * Renders the replies page for a specific post.
	 * Handles pagination for posts with hundreds/thousands of replies.
	 */
	private function render_post_replies( int $post_id ): void {
		global $wpdb;

		$post = Post::find( $post_id );
		if ( ! $post ) {
			wp_die( esc_html__( 'Post not found.', 'jetonomy' ) );
		}

		$replies_t = table( 'replies' );

		// Status filter.
		$current_status = sanitize_text_field( $_GET['status'] ?? 'all' );
		$valid_statuses = array( 'all', 'publish', 'pending', 'spam', 'trash' );
		if ( ! in_array( $current_status, $valid_statuses, true ) ) {
			$current_status = 'all';
		}

		// Search.
		$search_query = sanitize_text_field( $_GET['s'] ?? '' );

		// Build WHERE clause.
		$where = 'r.post_id = %d';
		$args  = array( $post_id );
		if ( 'all' !== $current_status ) {
			$where .= ' AND r.status = %s';
			$args[] = $current_status;
		}
		if ( $search_query ) {
			$where .= ' AND r.content_plain LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search_query ) . '%';
		}

		// Pagination — 50 per page for large reply sets.
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 50;
		$offset   = ( $paged - 1 ) * $per_page;

		$count_sql   = "SELECT COUNT(*) FROM {$replies_t} r WHERE {$where}";
		$total       = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );
		$total_pages = (int) ceil( $total / $per_page );

		$sql     = "SELECT r.* FROM {$replies_t} r WHERE {$where} ORDER BY r.created_at ASC LIMIT %d OFFSET %d";
		$replies = $wpdb->get_results( $wpdb->prepare( $sql, ...array_merge( $args, array( $per_page, $offset ) ) ) ) ?: array();

		$nonce_value = wp_create_nonce( 'jetonomy_admin' );

		include JETONOMY_DIR . 'includes/admin/views/replies.php';
	}

	// ═══════════════════════════════════════════════════════════════
	// Setup Wizard
	// ═══════════════════════════════════════════════════════════════

	public function render_setup(): void {
		include JETONOMY_DIR . 'includes/admin/views/setup-wizard.php';
	}
}
