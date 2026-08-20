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
use Jetonomy\Models\Reply;
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
		// One-click install/activate of Wbcom stack companions from the
		// Integrations settings tab (self-contained — see includes/integrations/).
		add_action( 'admin_post_jetonomy_install_companion', array( $this, 'handle_install_companion' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'in_admin_header', array( $this, 'hide_third_party_notices' ) );
		add_filter( 'admin_footer_text', array( $this, 'filter_admin_footer_text' ) );
		// Email opt-out field on the WP user-profile screen — the admin
		// entry point for the jetonomy_email_opt_out meta (members set it on
		// the frontend Edit Profile page; owners see/toggle it here).
		add_action( 'show_user_profile', array( $this, 'render_email_optout_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_email_optout_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_email_optout_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_email_optout_field' ) );
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

	/**
	 * admin-post handler: install (or activate) a Wbcom stack companion from the
	 * Integrations settings tab, then redirect back with a result flag.
	 */
	public function handle_install_companion(): void {
		$slug = isset( $_POST['companion'] ) ? sanitize_key( wp_unslash( $_POST['companion'] ) ) : '';
		$tier = isset( $_POST['tier'] ) && 'pro' === $_POST['tier'] ? 'pro' : 'free';

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'jetonomy' ), 403 );
		}
		check_admin_referer( 'jetonomy_install_companion_' . $slug );

		$license = isset( $_POST['license'] ) ? sanitize_text_field( wp_unslash( $_POST['license'] ) ) : '';
		$result  = \Jetonomy\Integrations\Companion_Installer::install( $slug, $tier, $license );

		$args = array(
			'page' => 'jetonomy-settings',
			'tab'  => 'integrations',
		);
		if ( is_wp_error( $result ) ) {
			$args['jt_install'] = 'error';
			$args['jt_msg']     = rawurlencode( $result->get_error_message() );
		} else {
			$args['jt_install'] = 'ok';
			$args['jt_done']    = $slug;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Menu ──

	public function add_menu(): void {
		$menu_label = apply_filters( 'jetonomy_admin_menu_label', __( 'Jetonomy', 'jetonomy' ) );

		// Brand mark (mono members glyph) as a data-URI so WordPress recolors it
		// for default/hover/current menu states. Single solid color is required
		// for the admin-menu filter to tint cleanly.
		$menu_glyph = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><g fill="#a7aaad"><circle cx="324" cy="206" r="44"/><path d="M268 330a56 60 0 0 1 112 0v2a6 6 0 0 1-6 6H274a6 6 0 0 1-6-6z"/><circle cx="206" cy="220" r="60"/><path d="M124 366a82 86 0 0 1 164 0v4a10 10 0 0 1-10 10H134a10 10 0 0 1-10-10z"/></g></svg>';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding an inline SVG as a data-URI menu icon, not obfuscation.
		$menu_icon = apply_filters( 'jetonomy_admin_menu_icon', 'data:image/svg+xml;base64,' . base64_encode( $menu_glyph ) );

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

		// Settings -> General lets the owner rename Space/Spaces, and the page
		// heading already honours it. The menu label was hardcoded, so after a
		// rename the plugin's own nav contradicted its page content.
		$jt_spaces_label = \Jetonomy\space_label( true );

		add_submenu_page(
			'jetonomy',
			$jt_spaces_label,
			$jt_spaces_label,
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
		// The Settings page renders under jetonomy_manage_settings, but the form
		// posts to options.php which enforces manage_options by default. Align
		// them so delegating the granular cap to a non-admin role actually lets
		// that role SAVE (otherwise they hit a WP "not allowed" wp_die).
		add_filter( 'option_page_capability_jetonomy_settings', static fn() => 'jetonomy_manage_settings' );

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

		// BuddyPress integration toggles. Standalone options (read by
		// Integrations\BuddyPress) in their OWN settings group + form, so a
		// save on any other settings tab can never reset them. Only present
		// when BuddyPress Groups is active — the only context where the
		// broadcast / comment-bridge behaviour exists.
		if ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) ) {
			register_setting(
				'jetonomy_integrations',
				'jetonomy_bp_broadcast',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_bool_option' ),
					'default'           => '1',
				)
			);
			register_setting(
				'jetonomy_integrations',
				'jetonomy_bp_comment_bridge',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_bool_option' ),
					'default'           => '1',
				)
			);
		}
	}

	/**
	 * Normalise a checkbox option to the '1' / '0' string the BuddyPress
	 * integration reads. The Integrations form ships a hidden '0' input
	 * before each checkbox, so the option is always present in POST.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string '1' or '0'.
	 */
	public function sanitize_bool_option( $value ): string {
		return '1' === (string) $value ? '1' : '0';
	}

	/**
	 * Attach a display title and an admin URL to each pending flag row.
	 *
	 * The Flags tab used to print `post #217` in a <code> tag - not a link, and
	 * not the content. A moderator deciding Valid vs Dismiss had to go find the
	 * item by id somewhere else before they could judge it, which is most of the
	 * work the queue exists to save.
	 *
	 * Batched deliberately: this view is the worked example of an N+1 in this
	 * codebase, so titles are fetched with ONE query per object type for the
	 * whole page rather than a find() per row. Replies resolve to their PARENT
	 * post, so a flagged reply links somewhere useful instead of the generic
	 * content list the Activity Log has to settle for - its rows store only the
	 * reply id, whereas a flag row lets us join.
	 *
	 * Mutates the row objects in place, adding jt_object_title and
	 * jt_object_url. Both are '' when the target has since been deleted, which
	 * the view renders as the old id text rather than a dead link.
	 *
	 * @param object[] $flags Pending flag rows.
	 */
	private function prime_flag_objects( array $flags ): void {
		if ( empty( $flags ) ) {
			return;
		}

		global $wpdb;

		$post_ids  = array();
		$reply_ids = array();
		$user_ids  = array();
		foreach ( $flags as $f ) {
			$id = (int) $f->object_id;
			if ( $id <= 0 ) {
				continue;
			}
			switch ( $f->object_type ) {
				case 'post':
					$post_ids[ $id ] = true;
					break;
				case 'reply':
					$reply_ids[ $id ] = true;
					break;
				case 'user':
					$user_ids[ $id ] = true;
					break;
			}
		}

		$posts_t   = \Jetonomy\table( 'posts' );
		$replies_t = \Jetonomy\table( 'replies' );

		$post_titles = array();
		if ( $post_ids ) {
			$ids = implode( ',', array_map( 'intval', array_keys( $post_ids ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( "SELECT id, title FROM {$posts_t} WHERE id IN ({$ids})" ) as $row ) {
				$post_titles[ (int) $row->id ] = (string) $row->title;
			}
		}

		$reply_parents = array();
		if ( $reply_ids ) {
			$ids = implode( ',', array_map( 'intval', array_keys( $reply_ids ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results(
				"SELECT r.id, r.post_id, p.title FROM {$replies_t} r
				 LEFT JOIN {$posts_t} p ON p.id = r.post_id
				 WHERE r.id IN ({$ids})"
			) as $row ) {
				$reply_parents[ (int) $row->id ] = array(
					'post_id' => (int) $row->post_id,
					'title'   => (string) $row->title,
				);
			}
		}

		$users = array();
		if ( $user_ids ) {
			$found = get_users(
				array(
					'include' => array_keys( $user_ids ),
					'fields'  => array( 'ID', 'display_name' ),
				)
			);
			foreach ( $found as $u ) {
				$users[ (int) $u->ID ] = (string) $u->display_name;
			}
		}

		foreach ( $flags as $f ) {
			$id                 = (int) $f->object_id;
			$f->jt_object_title = '';
			$f->jt_object_url   = '';

			switch ( $f->object_type ) {
				case 'post':
					if ( isset( $post_titles[ $id ] ) ) {
						$f->jt_object_title = $post_titles[ $id ];
						$f->jt_object_url   = admin_url( 'admin.php?page=jetonomy-content&post_id=' . $id );
					}
					break;
				case 'reply':
					if ( isset( $reply_parents[ $id ] ) ) {
						$parent = $reply_parents[ $id ];
						/* translators: %s: title of the topic the flagged reply belongs to. */
						$f->jt_object_title = sprintf( __( 'Reply on "%s"', 'jetonomy' ), $parent['title'] );
						$f->jt_object_url   = $parent['post_id']
							? admin_url( 'admin.php?page=jetonomy-content&post_id=' . $parent['post_id'] )
							: '';
					}
					break;
				case 'user':
					if ( isset( $users[ $id ] ) ) {
						$f->jt_object_title = $users[ $id ];
						$f->jt_object_url   = admin_url( 'admin.php?page=jetonomy-users&user_id=' . $id );
					}
					break;
			}
		}
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
			// Settings -> Email renders a template row for this type
			// (views/settings.php), and Notifier looks up an override for
			// whatever type it is sending. It was missing from this allowlist,
			// so an owner could write a custom subject/body for "Your idea
			// roadmap status changed", save, and have it silently discarded
			// every time. Keep this list in step with $tmpl_types in the view.
			'idea_status_changed',
			'reaction',
			'moderation',
			'flag_resolved',
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

		// ── Permissions tab: role -> capability mapping (Basecamp 9725751235) ──
		// Lives in its own option (not jetonomy_settings) and resyncs the live
		// WP roles immediately, so unticking a box revokes on save. The hidden
		// role_caps_submitted marker distinguishes "tab posted with everything
		// unticked" from "another tab posted".
		if ( ! empty( $input['role_caps_submitted'] ) ) {
			// Editing WHO HOLDS WHICH CAPABILITY is role administration, not a
			// plugin setting: a delegated jetonomy_manage_settings holder could
			// otherwise grant their own role jetonomy_manage_users/moderate and
			// escalate. Same bar WP core sets for editing roles.
			if ( ! current_user_can( 'manage_options' ) ) {
				// Drop only the mapping - the rest of the Permissions tab
				// (trust thresholds, rate limits) is still theirs to save.
				unset( $input['role_caps'], $input['role_caps_submitted'] );
				add_settings_error(
					'jetonomy_settings',
					'jetonomy_role_caps_forbidden',
					__( 'Only administrators can change the role capability mapping.', 'jetonomy' )
				);
			}
		}
		if ( ! empty( $input['role_caps_submitted'] ) ) {
			$valid_caps = \Jetonomy\Permissions\Capabilities::all();
			$roles      = array_keys( get_editable_roles() );
			$overrides  = array();
			$posted     = isset( $input['role_caps'] ) && is_array( $input['role_caps'] ) ? $input['role_caps'] : array();
			foreach ( $roles as $role_slug ) {
				if ( 'administrator' === $role_slug ) {
					continue; // Admins always hold every cap - never stored.
				}
				$caps                    = isset( $posted[ $role_slug ] ) ? (array) $posted[ $role_slug ] : array();
				$overrides[ $role_slug ] = array_values( array_intersect( $valid_caps, array_map( 'sanitize_key', $caps ) ) );
			}
			update_option( \Jetonomy\Permissions\Capabilities::ROLE_CAPS_OPTION, $overrides, false );
			\Jetonomy\Permissions\Capabilities::register();
			unset( $input['role_caps'], $input['role_caps_submitted'] );
		}

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
			$clean['base_slug']       = $new_slug;
			$clean['community_title'] = sanitize_text_field( $input['community_title'] ?? __( 'Community', 'jetonomy' ) );
			// Mobile app EULA screen (Apple Guideline 1.2) reads these via /app/config.
			$clean['terms_url']   = esc_url_raw( $input['terms_url'] ?? '' );
			$clean['privacy_url'] = esc_url_raw( $input['privacy_url'] ?? '' );
			// Space label override (singular / plural). Empty = keep the default.
			$clean['space_label_singular'] = sanitize_text_field( $input['space_label_singular'] ?? '' );
			$clean['space_label_plural']   = sanitize_text_field( $input['space_label_plural'] ?? '' );
			// Clamp to the UI max (100) so a crafted POST can't store a huge
			// value that flows straight into a SQL LIMIT on a big-site query.
			$clean['posts_per_page'] = min( 100, max( 1, absint( $input['posts_per_page'] ?? 20 ) ) );
			// Activity-log retention: 1 day to ~10 years. Consumed by the daily
			// jetonomy_prune_activity cron (class-cron.php).
			$clean['activity_log_retention_days'] = min( 3650, max( 1, absint( $input['activity_log_retention_days'] ?? 90 ) ) );
			$clean['replies_per_page']            = min( 100, max( 1, absint( $input['replies_per_page'] ?? 30 ) ) );
			$raw_space_type                       = sanitize_key( (string) ( $input['default_space_type'] ?? 'forum' ) );
			$clean['default_space_type']          = in_array( $raw_space_type, array( 'forum', 'qa', 'ideas', 'feed' ), true ) ? $raw_space_type : 'forum';
			// Community access mode — radio stores "1" (public) or "0" (private).
			$clean['guest_read'] = isset( $input['guest_read'] ) ? (bool) (int) $input['guest_read'] : true;
			// Community as homepage — unchecked checkboxes don't submit, so
			// absence inside a General-tab save means OFF (default).
			$clean['front_page'] = ! empty( $input['front_page'] );

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

			// Both of these shipped in 1.9.3 as checkboxes on this tab and were
			// never added here, so sanitize_settings() dropped them on every
			// save. $clean starts as $existing, so an unwritten key silently
			// keeps its old value - the box appeared to tick, the page reloaded
			// showing it unticked, and the feature behind it stayed off. That is
			// what made "let space admins delete a space" impossible to turn on:
			// the AJAX guard reads allow_space_admin_purge, which could never
			// become true (Basecamp 10217204334).
			//
			// An unchecked checkbox submits nothing, so absence inside a
			// General-tab save means OFF - the same rule front_page above uses.
			$clean['allow_space_admin_purge'] = ! empty( $input['allow_space_admin_purge'] );
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

		// Reputation point overrides — only persist the keys we recognise so
		// a stale POST body can't smuggle in arbitrary action names. Values
		// are signed ints (negative penalties allowed).
		if ( isset( $input['reputation_points'] ) ) {
			$raw_rep   = is_array( $input['reputation_points'] ) ? $input['reputation_points'] : array();
			$defaults  = \Jetonomy\Trust\Reputation::action_points_defaults();
			$clean_rep = array();
			foreach ( $defaults as $action_key => $default_val ) {
				if ( array_key_exists( $action_key, $raw_rep ) ) {
					$clean_rep[ $action_key ] = (int) $raw_rep[ $action_key ];
				}
			}
			$clean['reputation_points'] = $clean_rep;
		}

		// ── Email tab ──
		// Only process if email_from_name is present (Email tab was submitted).
		if ( isset( $input['email_from_name'] ) ) {
			$clean['email_from_name']   = sanitize_text_field( $input['email_from_name'] ?? '' );
			$clean['email_from_email']  = sanitize_email( $input['email_from_email'] ?? '' );
			$clean['email_logo_url']    = esc_url_raw( $input['email_logo_url'] ?? '' );
			$clean['email_footer_text'] = sanitize_text_field( $input['email_footer_text'] ?? '' );

			// Verification-reminder cadence (hours). 0 = disabled; clamp to a
			// week so a typo can't schedule an absurd delay. Consumed by the
			// verification-reminder cron (Verification_Reminder).
			$clean['verification_reminder_hours'] = min( 168, max( 0, absint( $input['verification_reminder_hours'] ?? 24 ) ) );

			// Notification defaults — checkbox values absent when unchecked, so default false if not present.
			$notif_types = array(
				'reply_to_post',
				'reply_to_reply',
				'mention',
				'accepted_answer',
				'new_post_in_sub',
				'badge_earned',
				'vote_on_post',
				'reaction',
				'moderation',
				'flag_resolved',
				'join_request',
				// Was missing, so unchecking its admin default silently reverted
				// to the seeded true/true — the toggle looked dead.
				'idea_status_changed',
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
			$clean['accent_color'] = sanitize_hex_color( $input['accent_color'] ?? '#0073aa' );
			$clean['logo_url']     = esc_url_raw( $input['logo_url'] ?? '' );
			// Color palette — empty string means "no override, keep the default".
			foreach ( array( 'text_color', 'bg_color', 'bg_subtle_color', 'border_color' ) as $palette_key ) {
				$clean[ $palette_key ] = sanitize_hex_color( (string) ( $input[ $palette_key ] ?? '' ) ) ?: '';
			}
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

	/**
	 * Render the Jetonomy email opt-out field on the WP user-profile screen.
	 *
	 * The frontend Edit Profile page is the member's entry point; this is the
	 * owner/admin entry point for the same `jetonomy_email_opt_out` meta the
	 * verification reminder honours. Visible to the user on their own profile
	 * and to any user who can edit the target profile.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public function render_email_optout_field( $user ): void {
		if ( ! ( $user instanceof \WP_User ) ) {
			return;
		}
		$opted_out = (bool) get_user_meta( $user->ID, 'jetonomy_email_opt_out', true );
		?>
		<h2><?php esc_html_e( 'Jetonomy', 'jetonomy' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Community emails', 'jetonomy' ); ?></th>
				<td>
					<label for="jetonomy_email_opt_out">
						<input type="checkbox" name="jetonomy_email_opt_out" id="jetonomy_email_opt_out" value="1" <?php checked( $opted_out ); ?> />
						<?php esc_html_e( 'Pause all Jetonomy email notifications for this user.', 'jetonomy' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'When enabled, the email verification reminder and other Jetonomy emails are suppressed. Web notifications are unaffected.', 'jetonomy' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		wp_nonce_field( 'jetonomy_email_optout_' . $user->ID, 'jetonomy_email_optout_nonce' );
	}

	/**
	 * Persist the email opt-out field from the WP user-profile screen.
	 *
	 * @param int $user_id The user being saved.
	 */
	public function save_email_optout_field( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST['jetonomy_email_optout_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['jetonomy_email_optout_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'jetonomy_email_optout_' . $user_id ) ) {
			return;
		}
		if ( ! empty( $_POST['jetonomy_email_opt_out'] ) ) {
			update_user_meta( $user_id, 'jetonomy_email_opt_out', 1 );
		} else {
			delete_user_meta( $user_id, 'jetonomy_email_opt_out' );
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'jetonomy' ) ) {
			return;
		}

		// admin.css declares its own --jt-admin-* palette but still consumes a
		// handful of the shared --jt-* tokens, and jetonomy.css below consumes
		// them wholesale. Neither file declares a single one, so the token layer
		// is a hard dependency of both - not an optimisation.
		// {@see jetonomy_register_token_style()}
		$tokens = jetonomy_register_token_style();

		wp_enqueue_style(
			'jetonomy-admin',
			JETONOMY_URL . 'assets/css/admin.css',
			array( $tokens ),
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
			// Mirrors the front-end localize (Template_Loader::enqueue_assets) so
			// jetonomy-modals.js has the same translated button defaults whether
			// it loads on the community pages or in wp-admin.
			wp_localize_script(
				'jetonomy-modals',
				'jetonomyModalsI18n',
				array(
					'cancel'  => __( 'Cancel', 'jetonomy' ),
					'confirm' => __( 'Confirm', 'jetonomy' ),
					'submit'  => __( 'Submit', 'jetonomy' ),
					'ok'      => __( 'OK', 'jetonomy' ),
				)
			);
		}
		// Admin pages need the .jt-modal-* CSS classes the toolkit relies on,
		// which live in the front-end stylesheet. Enqueue it on Jetonomy admin
		// pages too — the rules are scoped + don't bleed into core wp-admin.
		wp_enqueue_style(
			'jetonomy',
			JETONOMY_URL . 'assets/css/jetonomy.css',
			array( $tokens ),
			JETONOMY_VERSION
		);

		wp_enqueue_script(
			'jetonomy-admin',
			JETONOMY_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker', 'jetonomy-modals' ),
			JETONOMY_VERSION,
			true
		);

		// Shared confirm-on-click delegate for [data-jt-confirm] markup.
		// Replaces legacy inline event-attribute confirms that fight CSP,
		// the Interactivity API, and event delegation.
		wp_enqueue_script(
			'jetonomy-admin-confirm',
			JETONOMY_URL . 'assets/js/admin-confirm.js',
			array( 'jetonomy-modals' ),
			JETONOMY_VERSION,
			true
		);

		// Lucide icon picker behaviour for any admin form that renders the
		// shared icon-picker partial (spaces, categories, badges).
		wp_enqueue_script(
			'jetonomy-icon-picker',
			JETONOMY_URL . 'assets/js/jetonomy-icon-picker.js',
			array(),
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
		// Names come from the registry so the picker and the saved-rules table
		// can never disagree about what an adapter is called.
		$adapter_labels = \Jetonomy\Adapters\Adapter_Registry::membership_labels();

		$membership_adapters = array();
		$all_adapters        = \Jetonomy\Adapters\Adapter_Registry::get_all_membership();
		foreach ( $all_adapters as $adapter_id => $adapter ) {
			if ( $adapter->is_active() && 'wp-roles' !== $adapter_id ) {
				$levels = array();
				foreach ( $adapter->get_all_levels() as $level ) {
					// kind + note are optional (see Membership_Adapter). Absent
					// keys stay absent rather than becoming '', so the picker can
					// tell "this adapter has not adopted grouping" apart from
					// "this row has an empty kind" and fall back to the flat list.
					$row = array(
						'id'    => $level['id'],
						'label' => $level['label'],
					);
					if ( ! empty( $level['kind'] ) ) {
						$row['kind'] = (string) $level['kind'];
					}
					if ( ! empty( $level['note'] ) ) {
						$row['note'] = (string) $level['note'];
					}
					$levels[] = $row;
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
					'confirmArchiveSpace'     => esc_html__( 'Archive this space and hand it to an administrator? Its topics and replies are kept and nothing is deleted. Members will no longer be able to post in it.', 'jetonomy' ),
					'confirmPurgeSpace'       => esc_html__( 'Permanently delete this space and EVERY topic, reply and attachment in it, including content written by other members? This cannot be undone.', 'jetonomy' ),
					/* translators: %s: the space name the operator must retype. */
					'purgeTypeToConfirm'      => esc_html__( 'This destroys every topic, reply and attachment in %s, including content written by other members. It cannot be undone. Type the space name to confirm.', 'jetonomy' ),
					'purgeConfirmLabel'       => esc_html__( 'Delete permanently', 'jetonomy' ),
					'purgeNameMismatch'       => esc_html__( 'That name did not match, so nothing was deleted.', 'jetonomy' ),
					'confirmBan'              => esc_html__( 'Are you sure you want to ban this user?', 'jetonomy' ),
					'confirmSpam'             => esc_html__( 'Mark this as spam? It will be hidden from the community.', 'jetonomy' ),
					'confirmTrash'            => esc_html__( 'Move this to trash? This removes it from the community.', 'jetonomy' ),
					'saving'                  => esc_html__( 'Saving...', 'jetonomy' ),
					'saved'                   => esc_html__( 'Saved!', 'jetonomy' ),
					'deleted'                 => esc_html__( 'Deleted.', 'jetonomy' ),
					'error'                   => esc_html__( 'Something went wrong.', 'jetonomy' ),
					'importing'               => esc_html__( 'Importing...', 'jetonomy' ),
					'importDone'              => esc_html__( 'Import complete!', 'jetonomy' ),
					/* translators: %s: server-supplied error detail. */
					'importErrorFormat'       => __( 'Error: %s', 'jetonomy' ),
					'importErrorUnknown'      => esc_html__( 'Unknown error', 'jetonomy' ),
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
					'reloadPage'              => esc_html__( 'Reload page', 'jetonomy' ),
					'importConnectionLost'    => esc_html__( 'Connection lost. You can resume this import later.', 'jetonomy' ),
					/* translators: %d: number of attachment files that could not be recovered. */
					'importSkippedFiles'      => esc_html__( '%d file(s) could not be recovered and were left linked in the original post text.', 'jetonomy' ),
					'inviteCopied'            => esc_html__( 'Invite link copied to clipboard.', 'jetonomy' ),
					'inviteRevokeConfirm'     => esc_html__( 'Revoke this invite link? Anyone holding it will no longer be able to join.', 'jetonomy' ),
					'inviteNoLinks'           => esc_html__( 'No invite links yet.', 'jetonomy' ),
					'inviteUnlimited'         => esc_html__( 'Unlimited', 'jetonomy' ),
					'inviteNever'             => esc_html__( 'Never', 'jetonomy' ),
					'inviteExpired'           => esc_html__( 'Expired', 'jetonomy' ),
					// Column labels for JS-injected invite rows. They must match
					// the headings jetonomy_admin_table() renders, because the
					// responsive layout shows them as each cell's label on mobile.
					'inviteLink'              => esc_html__( 'Invite Link', 'jetonomy' ),
					'inviteUses'              => esc_html__( 'Uses', 'jetonomy' ),
					'inviteExpires'           => esc_html__( 'Expires', 'jetonomy' ),
					'actions'                 => esc_html__( 'Actions', 'jetonomy' ),
					'showMoreDetails'         => esc_html__( 'Show more details', 'jetonomy' ),
					// Access-rule composer preview. Keyed so the sentence and its
					// mismatch warnings are translatable like everything else.
					'rulePreview'             => array(
						'whoFallback'      => __( 'People who match this rule', 'jetonomy' ),
						/* translators: 1: who the rule matches, 2: what they may do, 3: the space role they are recorded as. */
						'sentence'         => __( '%1$s can %2$s. They are recorded as %3$s.', 'jetonomy' ),
						'grants'           => array(
							// NOT "but not take part". A rule admits; it does not
							// restrict. On a public space anyone may join, a
							// signed-in member posts whether or not a Read rule
							// matches them, so the old wording contradicted the
							// help table one screen away and promised a cap the
							// rule cannot deliver.
							'read'        => __( 'read posts and replies', 'jetonomy' ),
							'participate' => __( 'read, post, reply, vote and report', 'jetonomy' ),
							'full'        => __( 'read, post, reply, vote, report, and - if their WordPress role already allows moderation - edit, close or pin other people\'s topics', 'jetonomy' ),
						),
						// The "who matches" half of the sentence. The grants map
						// above says what someone may do; without this the owner
						// got no help at all deciding WHO a rule catches, and the
						// value field was a bare box with one placeholder for five
						// different kinds of value. Write the consequence, not the
						// definition, and say when the match ends where it can.
						'typeNotes'        => array(
							'everyone'    => __( 'Matches every visitor, signed in or not. Nobody is asked to log in first.', 'jetonomy' ),
							'logged_in'   => __( 'Matches anyone with an account on this site, whoever they are. A new registration matches the moment it is created.', 'jetonomy' ),
							'role'        => __( 'Matches anyone holding this WordPress role. Most members hold Subscriber, the role WordPress gives new registrations, so a Subscriber rule usually means "everyone who signed up".', 'jetonomy' ),
							'capability'  => __( 'Matches anyone whose WordPress role carries this capability. Use it when several roles should match one rule, or when another plugin grants the capability on the fly.', 'jetonomy' ),
							'trust_level' => __( 'Matches members at or above this trust level, 0 to 5. Trust is earned by taking part, so this rule lets more people in over time without you touching it.', 'jetonomy' ),
						),
						// Per-type placeholder for the value box. One generic
						// example cannot serve a role slug, a capability and a
						// number at the same time.
						'typePlaceholders' => array(
							'role'        => __( 'subscriber', 'jetonomy' ),
							'capability'  => __( 'edit_posts', 'jetonomy' ),
							'trust_level' => __( '2', 'jetonomy' ),
						),
						// Roster-role labels for the derived value in the preview.
						'roles'            => array(
							'viewer'    => __( 'Viewer', 'jetonomy' ),
							'member'    => __( 'Member', 'jetonomy' ),
							'moderator' => __( 'Moderator', 'jetonomy' ),
							'admin'     => __( 'Admin', 'jetonomy' ),
						),
						'warnRoleHigher'   => __( 'Heads up: the Space Role is more powerful than the Grants. Anyone added to the roster by "Sync Members" gets the role\'s abilities too.', 'jetonomy' ),
						'warnGrantHigher'  => __( 'Heads up: the Grants are broader than the Space Role, so member lists will understate what these people can do.', 'jetonomy' ),
					),
					'copy'                    => esc_html__( 'Copy', 'jetonomy' ),
					'revoke'                  => esc_html__( 'Revoke', 'jetonomy' ),
					// Access-rule sync button + the import restart confirm. These
					// were written inline in admin.js with no key, so they stayed
					// English on every locale; the count was also concatenated,
					// which no translator could reorder.
					'sync'                    => esc_html__( 'Sync', 'jetonomy' ),
					'syncing'                 => esc_html__( 'Syncing...', 'jetonomy' ),
					/* translators: %d: number of memberships synced. */
					'syncedFormat'            => esc_html__( 'Synced (%d)', 'jetonomy' ),
					'importRestartConfirm'    => esc_html__( 'This will discard the interrupted import progress. Continue?', 'jetonomy' ),
					'importRestartTitle'      => esc_html__( 'Restart import', 'jetonomy' ),
				),
			)
		);

		// Per-page admin scripts. WP builds sub-page hooks as
		// '{sanitize_title(menu_label)}_page_{slug}', and White Label filters the
		// menu label — so the prefix becomes e.g. 'qa-brand_page_...'. Match by
		// the stable '_page_{slug}' suffix, not the label-derived prefix, or these
		// per-page scripts silently fail to load on white-labeled sites. (The
		// toplevel hook uses the menu SLUG, which White Label leaves alone.)
		if ( 'toplevel_page_jetonomy' === $hook ) {
			wp_enqueue_script(
				'jetonomy-admin-dashboard',
				JETONOMY_URL . 'assets/js/admin-dashboard.js',
				array( 'jetonomy-admin' ),
				JETONOMY_VERSION,
				true
			);
		} elseif ( str_ends_with( $hook, '_page_jetonomy-revisions' ) ) {
			wp_enqueue_script(
				'jetonomy-admin-revisions',
				JETONOMY_URL . 'assets/js/admin-revisions.js',
				array( 'jetonomy-admin' ),
				JETONOMY_VERSION,
				true
			);
		} elseif ( str_ends_with( $hook, '_page_jetonomy-tags' ) ) {
			wp_enqueue_script(
				'jetonomy-admin-tags',
				JETONOMY_URL . 'assets/js/admin-tags.js',
				array( 'jetonomy-admin' ),
				JETONOMY_VERSION,
				true
			);
		} elseif ( str_ends_with( $hook, '_page_jetonomy-settings' ) ) {
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

		// Live 7-day pulse for the analytics teaser (real numbers, not a
		// blurred screenshot — the widget demonstrates what Pro's analytics
		// does). Dismissible per user; cached 1h so it costs the dashboard
		// nothing on repeat views.
		if ( isset( $_GET['jetonomy_dismiss_pulse'] ) && check_admin_referer( 'jetonomy_dismiss_pulse' ) ) {
			update_user_meta( get_current_user_id(), 'jetonomy_pulse_dismissed', 1 );
		}
		$pulse = get_user_meta( get_current_user_id(), 'jetonomy_pulse_dismissed', true )
			? null
			: $this->weekly_pulse();

		include JETONOMY_DIR . 'includes/admin/views/dashboard.php';
	}

	/**
	 * 7-day community pulse for the dashboard analytics teaser.
	 *
	 * Four bounded queries (date-windowed COUNTs over indexed created_at
	 * columns), transient-cached for an hour. Big-site checklist: no
	 * unbounded scans, the DISTINCT runs over the 7-day activity window only.
	 *
	 * @return array{posts:int,replies:int,contributors:int,top_space:?string,top_space_posts:int}
	 */
	private function weekly_pulse(): array {
		$cached = get_transient( 'jetonomy_weekly_pulse' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$posts_t    = table( 'posts' );
		$replies_t  = table( 'replies' );
		$spaces_t   = table( 'spaces' );
		$activity_t = table( 'activity_log' );
		$since      = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		$top = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.title, COUNT(*) AS n FROM {$posts_t} p
				 INNER JOIN {$spaces_t} s ON s.id = p.space_id
				 WHERE p.status = 'publish' AND p.created_at >= %s
				 GROUP BY p.space_id, s.title ORDER BY n DESC LIMIT 1",
				$since
			)
		);

		$pulse = array(
			'posts'           => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_t} WHERE status = 'publish' AND created_at >= %s", $since ) ),
			'replies'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$replies_t} WHERE status = 'publish' AND created_at >= %s", $since ) ),
			'contributors'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$activity_t} WHERE created_at >= %s", $since ) ),
			'top_space'       => $top->title ?? null,
			'top_space_posts' => (int) ( $top->n ?? 0 ),
		);

		set_transient( 'jetonomy_weekly_pulse', $pulse, HOUR_IN_SECONDS );
		return $pulse;
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
				/* translators: %s: the singular space label. */
				wp_die( esc_html( sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ) ) );
			}
			$categories = $this->get_all_categories_flat();
			// Explicit cap (plan WP1.5): the unbounded default rendered every
			// member row on one screen. 1000 keeps this management surface
			// functional; when the space is larger the view shows a notice so
			// members past the cap are never SILENTLY hidden from the only
			// admin surface that can remove them (the frontend members page
			// is paginated and covers the tail).
			$members        = SpaceMember::list_by_space( $space_id, 1000 );
			$members_capped = ( (int) ( $space->member_count ?? 0 ) ) > count( $members ) && count( $members ) >= 1000;
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

		$where_sql = implode( ' AND ', $where );
		$spaces_t  = table( 'spaces' );
		$paged     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page  = absint( $_GET['per_page'] ?? 20 );
		if ( ! in_array( $per_page, array( 20, 50, 100 ), true ) ) {
			$per_page = 20;
		}
		$offset = ( $paged - 1 ) * $per_page;

		// Manual ordering is only meaningful inside one category - that is the
		// unit the front end renders (Space::list_by_category). With a single
		// category filtered we sort the way members see it, so the admin is a
		// preview and drag-reorder has somewhere coherent to write. Otherwise
		// the list stays alphabetical, which is what you want when browsing
		// every space across categories.
		$can_reorder = $filter_category > 0 && ! $filter_type && ! $filter_status;
		$order_sql   = $can_reorder ? 'sort_order ASC, title ASC' : 'title ASC';

		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$spaces_t} WHERE {$where_sql}" );
		$total_pages = (int) ceil( $total / $per_page );
		$spaces      = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order_sql is one of two literals above.
				"SELECT * FROM {$spaces_t} WHERE {$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		) ?: array();
		$categories = $this->get_all_categories_flat();

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

		// Real totals for tab badge counts. Posts/replies reuse the shared
		// count-by-status model methods (same COUNT(*) the REST queue uses);
		// the paginated list queries below keep their display JOINs (space/post
		// title) and stay here since the API path doesn't need those columns.
		$total_posts   = Post::count_by_status( array( 'pending' ) );
		$total_replies = Reply::count_by_status( array( 'pending' ) );
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

		$this->prime_flag_objects( $pending_flags );

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
		// Merge SEO defaults so the checkbox render matches what the frontend
		// consumers actually do (shared source of truth, prevents phantom
		// "Default: On" toggles that were really off).
		$settings = \Jetonomy\seo_settings();
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
