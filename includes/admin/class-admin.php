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
use Jetonomy\Models\UserProfile;
use Jetonomy\Models\Flag;
use Jetonomy\Models\ActivityLog;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\JoinRequest;
use Jetonomy\Import\Import_Manager;
use function Jetonomy\table;
use function Jetonomy\now;

class Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_page_access_denied', array( $this, 'explain_access_rules_refusal' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_permalink_notice' ) );
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
		/**
		 * Filters the top-level admin menu label.
		 *
		 * @since 1.0.0
		 *
		 * @param string $label Menu label. Default "Jetonomy".
		 * @return string Menu label.
		 */
		$menu_label = apply_filters( 'jetonomy_admin_menu_label', __( 'Jetonomy', 'jetonomy' ) );

		// Brand mark (mono members glyph) as a data-URI so WordPress recolors it
		// for default/hover/current menu states. Single solid color is required
		// for the admin-menu filter to tint cleanly.
		$menu_glyph = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><g fill="#a7aaad"><circle cx="324" cy="206" r="44"/><path d="M268 330a56 60 0 0 1 112 0v2a6 6 0 0 1-6 6H274a6 6 0 0 1-6-6z"/><circle cx="206" cy="220" r="60"/><path d="M124 366a82 86 0 0 1 164 0v4a10 10 0 0 1-10 10H134a10 10 0 0 1-10-10z"/></g></svg>';
		/**
		 * Filters the top-level admin menu icon.
		 *
		 * @since 1.0.0
		 *
		 * @param string $icon Dashicon class or base64 SVG data URI.
		 * @return string Menu icon.
		 */
		$menu_icon = apply_filters( 'jetonomy_admin_menu_icon', 'data:image/svg+xml;base64,' . base64_encode( $menu_glyph ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inline SVG as a data-URI menu icon.

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

		// Owner can rename Category/Categories under Settings -> General; the
		// menu label and the page honour it, the same way Spaces already does.
		$jt_categories_label = \Jetonomy\jetonomy_label( 'category', true );

		add_submenu_page(
			'jetonomy',
			$jt_categories_label,
			$jt_categories_label,
			'jetonomy_manage_categories',
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
			'jetonomy_manage_spaces', // The capability every Spaces action checks.
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
			'jetonomy_manage_users',
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
				'jetonomy_manage_extensions',
				'jetonomy-extensions',
				array( $this, 'render_extensions' )
			);
			// License is now a tab inside Settings — no separate submenu.
		}

		// Hidden setup wizard page (no menu item).
		add_submenu_page( '', sprintf( /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ __( '%s Setup', 'jetonomy' ), self::brand_name() ), '', 'manage_options', 'jetonomy-setup', array( $this, 'render_setup' ) );
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

	/**
	 * The product name wp-admin shows in headings, banners and help text.
	 *
	 * One source for every "Jetonomy ..." heading, so Pro's White Label (which
	 * fills `jetonomy_brand_name` from its Brand Name setting) renames them all.
	 * The admin menu label has its own filter, `jetonomy_admin_menu_label`.
	 *
	 * @return string Unescaped.
	 */
	public static function brand_name(): string {
		/**
		 * Filters the product name shown in wp-admin headings and help text.
		 *
		 * @since 2.0.2
		 *
		 * @param string $name Product name. Default "Jetonomy".
		 */
		return (string) apply_filters( 'jetonomy_brand_name', __( 'Jetonomy', 'jetonomy' ) );
	}

	/**
	 * May the current user save Jetonomy settings?
	 *
	 * THE answer, for free and for every Pro settings tab.
	 *
	 * The Settings page renders under `jetonomy_manage_settings` (see add_menu),
	 * and free honours that capability on save via the
	 * option_page_capability_jetonomy_settings filter below. Every Pro tab saves through
	 * this same check, so a manager the owner delegated can save what they can open.
	 *
	 * Administrators hold `jetonomy_manage_settings` too (Capabilities grants
	 * every cap to administrator), so this is never narrower than `manage_options`.
	 * It is NOT the right gate for role administration - editing which role holds
	 * which capability stays on `manage_options`, or a delegated manager could
	 * grant their own role anything.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage_settings(): bool {
		return current_user_can( 'jetonomy_manage_settings' );
	}

	/**
	 * Display name for a stored object type (space, reply, category, ...).
	 *
	 * Terminology nouns use the owner's label (a stored "post" is the topic
	 * noun); other types (user) are humanized from the key.
	 *
	 * @param string $type Object type key.
	 * @return string Unescaped.
	 */
	public static function object_type_label( string $type ): string {
		$type = 'post' === $type ? 'topic' : $type; // Stored as "post"; the owner calls it a topic.
		return isset( \Jetonomy\label_defaults()[ $type ] ) ? \Jetonomy\jetonomy_label( $type ) : ucfirst( str_replace( '_', ' ', $type ) );
	}

	/**
	 * Human label for an activity-log action code (e.g. joined_space).
	 *
	 * Shared by the Dashboard feed and the Activity screen so both read the
	 * same and follow the owner's terminology. Unknown codes are humanized.
	 *
	 * @param string $code Action code.
	 * @return string Unescaped.
	 */
	public static function action_label( string $code ): string {
		switch ( $code ) {
			case 'created_post':
				/* translators: %s: topic label, lowercase (e.g. "topic"). */
				return sprintf( __( 'Started a %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) );
			case 'created_reply':
				return __( 'Replied', 'jetonomy' );
			case 'voted':
				return __( 'Voted', 'jetonomy' );
			case 'trust_level_changed':
				return __( 'Trust level changed', 'jetonomy' );
			case 'moderated_approve':
				return __( 'Approved content', 'jetonomy' );
			case 'moderated_trash':
				return __( 'Trashed content', 'jetonomy' );
			case 'moderated_spam':
				return __( 'Marked as spam', 'jetonomy' );
			case 'reputation_changed':
				return __( 'Reputation changed', 'jetonomy' );
			case 'joined_space':
				/* translators: %s: space label, lowercase (e.g. "space"). */
				return sprintf( __( 'Joined the %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', false, true ) );
			case 'idea_status_changed':
				return __( 'Updated idea roadmap status', 'jetonomy' );
		}
		return ucwords( str_replace( '_', ' ', $code ) );
	}

	/**
	 * Every notification type Settings -> Email lists, with its admin label.
	 *
	 * The one list behind the Notification Defaults table, the Email Templates
	 * editor and both sanitizer allowlists, so a new type added here shows up
	 * (and saves) everywhere at once. `toggle` is false for emails a member
	 * cannot switch off (welcome, verification reminder): they get a template
	 * row but no on/off default. Template defaults live in
	 * Notifier::get_default_template(). `note` is an optional line shown under
	 * the template row.
	 *
	 * @return array<string, array{label: string, toggle: bool, note: string}>
	 */
	public static function email_types(): array {
		$topic = \Jetonomy\jetonomy_label( 'topic', false, true );
		$types = array(
			/* translators: %s: member label, lowercase (e.g. "member"). */
			'user_welcome'           => array( sprintf( __( 'Welcome: new %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', false, true ) ), false ),
			/* translators: 1: reply label (e.g. "Reply"). 2: the item replied to, lowercase (e.g. "topic" or "reply"). */
			'reply_to_post'          => array( sprintf( __( '%1$s to your %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ), $topic ), true ),
			/* translators: 1: reply label (e.g. "Reply"). 2: singular topic label, lowercase. */
			'reply_in_followed'      => array( sprintf( __( '%1$s in a %2$s you follow', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ), $topic ), false, sprintf( /* translators: 1: singular topic label, lowercase. 2: singular reply label, lowercase. */ __( 'Sent to followers who did not start the %1$s. On or off follows the %2$s notification above.', 'jetonomy' ), $topic, \Jetonomy\jetonomy_label( 'reply', false, true ) ) ),
			/* translators: 1: reply label (e.g. "Reply"). 2: the item replied to, lowercase (e.g. "topic" or "reply"). */
			'reply_to_reply'         => array( sprintf( __( '%1$s to your %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ), \Jetonomy\jetonomy_label( 'reply', false, true ) ), true ),
			'mention'                => array( __( 'Mention (@username)', 'jetonomy' ), true ),
			'accepted_answer'        => array( __( 'Your answer accepted', 'jetonomy' ), true ),
			'idea_status_changed'    => array( __( 'Your idea roadmap status changed', 'jetonomy' ), true ),
			/* translators: 1: singular topic label, lowercase. 2: singular space label, lowercase. */
			'new_post_in_sub'        => array( sprintf( __( 'New %1$s in subscribed %2$s', 'jetonomy' ), $topic, \Jetonomy\space_label( false, true ) ), true ),
			'badge_earned'           => array( __( 'Badge earned', 'jetonomy' ), true ),
			/* translators: %s: singular topic label, lowercase. */
			'vote_on_post'           => array( sprintf( __( 'Vote on your %s', 'jetonomy' ), $topic ), true ),
			/* translators: %s: singular topic label, lowercase. */
			'reaction'               => array( sprintf( __( 'Reaction on your %s', 'jetonomy' ), $topic ), true ),
			'moderation'             => array( __( 'Moderator action on your content', 'jetonomy' ), true ),
			'flag_created'           => array( __( 'New report for moderators', 'jetonomy' ), false, __( 'Sent to moderators when content is reported. On or off follows Moderator action on your content.', 'jetonomy' ) ),
			'flag_resolved'          => array( __( 'Your report was reviewed', 'jetonomy' ), true ),
			/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
			'join_request'           => array( sprintf( __( '%s join request', 'jetonomy' ), \Jetonomy\space_label() ), true ),
			'join_request_result'    => array( __( 'Your join request was answered', 'jetonomy' ), true ),
			'verification'           => array( __( 'Confirm your email (new accounts)', 'jetonomy' ), false, __( 'The confirmation link is always added below your text. Placeholders here: {site} and {user}.', 'jetonomy' ) ),
			'verification_reminder'  => array( __( 'Verification reminder', 'jetonomy' ), false ),
			/* translators: %s: reply label, lowercase (e.g. "reply"). */
			'reply_by_email_refused' => array( sprintf( __( 'Emailed %s not posted', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', false, true ) ), false ),
		);
		// Pro private messages go through Notifier too, but only while that extension is live.
		if ( \Jetonomy\messaging_active() ) {
			$types['message'] = array( __( 'Private message', 'jetonomy' ), true );
		}
		return array_map(
			static fn( array $t ): array => array(
				'label'  => $t[0],
				'toggle' => $t[1],
				'note'   => $t[2] ?? '',
			),
			$types
		);
	}

	// ── Settings API ──

	/**
	 * Tell the owner when Plain permalinks make the community unreachable.
	 *
	 * Every route is a rewrite rule, and Plain permalinks have none, so
	 * /community/ silently served the homepage.
	 */
	public function render_permalink_notice(): void {
		if ( '' !== (string) get_option( 'permalink_structure' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'options-permalink' === $screen->id ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html( sprintf( /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ __( '%s:', 'jetonomy' ), self::brand_name() ) ),
			esc_html__( 'your community cannot be reached while permalinks are set to Plain. Visitors see the homepage instead.', 'jetonomy' ),
			esc_url( admin_url( 'options-permalink.php' ) ),
			esc_html__( 'Choose a permalink structure', 'jetonomy' )
		);
	}

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
	 * Each flag shows the item's title as a link, so a moderator can judge Valid vs
	 * Dismiss without finding the item by id elsewhere.
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
	 * the view renders as plain id text rather than a dead link.
	 *
	 * @param object[] $flags Pending flag rows.
	 */
	private function prime_flag_objects( array $flags ): void {
		if ( empty( $flags ) ) {
			return;
		}

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

		$post_titles   = Post::titles_by_ids( array_keys( $post_ids ) );
		$reply_parents = Reply::parents_by_ids( array_keys( $reply_ids ) );

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
						/* translators: 1: reply label (e.g. "Reply"). 2: title of the topic the flagged reply belongs to. */
						$f->jt_object_title = sprintf( __( '%1$s on "%2$s"', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ), $parent['title'] );
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
	 * Did THIS request post the Email tab's template fields?
	 *
	 * See sanitize_email_templates() for why a per-call check is not enough.
	 *
	 * @var bool
	 */
	private static bool $email_templates_submitted = false;

	/**
	 * Sanitize the email template overrides option.
	 * Each row: { subject: string, body: string }. Both fields are plain
	 * text with supported placeholders — no HTML allowed here.
	 *
	 * @param mixed $input
	 * @return array<string, array{subject: string, body: string}>
	 */
	public function sanitize_email_templates( $input ): array {
		$stored = get_option( 'jetonomy_email_templates', array() );
		$stored = is_array( $stored ) ? $stored : array();

		// Core can call this sanitizer TWICE for one save. When the stored value
		// equals the registered default - which is array(), i.e. every site that
		// has never saved a template - update_option() routes to add_option(),
		// and add_option() runs sanitize_option() again on the array this method
		// just returned. That second pass has no _submitted marker (it is not a
		// stored key), so the guard below read it as "a different tab posted"
		// and handed back the stored empty array: the owner's FIRST template was
		// silently dropped, for ever, on exactly the sites that had none.
		//
		// The marker is therefore remembered for the request, not just for the
		// call. Static, because the two passes are the same request by
		// definition.
		if ( is_array( $input ) && ! empty( $input['_submitted'] ) ) {
			self::$email_templates_submitted = true;
		}

		if ( self::$email_templates_submitted && is_array( $input ) && empty( $input['_submitted'] ) ) {
			// Core's re-sanitise of our own output. It is already clean; handing
			// back $stored here is what lost the first save.
			return $input;
		}

		// This option is registered in the `jetonomy_settings` group, and
		// options.php writes EVERY option in a group on submit - including the
		// ones whose fields the current tab never rendered. So a save on
		// Appearance, General, SEO or any other tab arrived here with $input
		// null and wiped every override the owner had written. The header
		// comment on register_setting() promised a separate OPTION protected
		// this; what matters is the separate GROUP, which it does not have.
		//
		// The Email tab posts jetonomy_email_templates[_submitted]=1, so its
		// absence means this save is not about templates. Present with no rows
		// still means "the owner cleared them", which is honoured below.
		if ( ! is_array( $input ) || empty( $input['_submitted'] ) ) {
			return $stored;
		}

		// One list for the editor rows and this allowlist (Admin::email_types()),
		// so a type with a row can never have its override discarded on save.
		$allowed_types = array_keys( self::email_types() );

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

	/**
	 * Whether a role currently holds any Jetonomy capability.
	 *
	 * @param string $role_slug Role slug.
	 */
	private static function role_holds_jetonomy_cap( string $role_slug ): bool {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return false;
		}
		foreach ( \Jetonomy\Permissions\Capabilities::all() as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	public function sanitize_settings( $input ): array {
		// Merge with existing settings so saving one tab doesn't wipe another.
		$existing = get_option( 'jetonomy_settings', array() );
		$clean    = is_array( $existing ) ? $existing : array();

		// ── Permissions tab: role -> capability mapping ──
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
				unset( $input['role_caps'], $input['role_caps_submitted'], $input['role_caps_reset'] );
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
			$stored     = get_option( \Jetonomy\Permissions\Capabilities::ROLE_CAPS_OPTION, array() );
			$stored     = is_array( $stored ) ? $stored : array();
			$overrides  = array();
			$posted     = isset( $input['role_caps'] ) && is_array( $input['role_caps'] ) ? $input['role_caps'] : array();
			foreach ( $roles as $role_slug ) {
				if ( 'administrator' === $role_slug ) {
					continue; // Admins always hold every cap - never stored.
				}
				$caps = isset( $posted[ $role_slug ] ) ? (array) $posted[ $role_slug ] : array();
				$caps = array_values( array_intersect( $valid_caps, array_map( 'sanitize_key', $caps ) ) );
				// A row the owner never touched (never stored, holds no Jetonomy
				// cap, still empty) stays unmapped so the role keeps the space
				// membership fallback. Storing it cut Customer/Student members off
				// on the first save of this tab. A row that
				// was stored or held caps and is now empty is a deliberate "none".
				if ( ! $caps && ! array_key_exists( $role_slug, $stored ) && ! self::role_holds_jetonomy_cap( $role_slug ) ) {
					continue;
				}
				$overrides[ $role_slug ] = $caps;
			}
			// "Give members access" on a locked third-party role: unmap it so the
			// space-membership fallback applies again.
			$reset = sanitize_key( $input['role_caps_reset'] ?? '' );
			if ( $reset && ! isset( \Jetonomy\Permissions\Capabilities::default_map()[ $reset ] ) ) {
				unset( $overrides[ $reset ] );
			}
			update_option( \Jetonomy\Permissions\Capabilities::ROLE_CAPS_OPTION, $overrides, false );
			\Jetonomy\Permissions\Capabilities::register();
			unset( $input['role_caps'], $input['role_caps_submitted'], $input['role_caps_reset'] );
		}

		// ── General tab ──
		// Only process if base_slug is present (General tab was submitted).
		if ( isset( $input['base_slug'] ) ) {
			// The base the site is running on now; old links point at it.
			$old_base = \Jetonomy\base_slug();
			// A cleared field keeps that base: an empty one would unmount the community.
			$new_slug = sanitize_title( $input['base_slug'] ) ?: $old_base;
			if ( $new_slug !== $old_base ) {
				// Delete the versioned flush key so Router re-registers rules on next load.
				delete_option( 'jetonomy_permalinks_flushed_' . JETONOMY_VERSION );

				// Store the old slug so Router can 301-redirect old URLs.
				update_option( 'jetonomy_old_base_slug', $old_base, false );
			}
			// Moving back onto the old slug retires the redirect.
			if ( get_option( 'jetonomy_old_base_slug', '' ) === $new_slug ) {
				delete_option( 'jetonomy_old_base_slug' );
			}
			$clean['base_slug']       = $new_slug;
			$clean['community_title'] = sanitize_text_field( $input['community_title'] ?? __( 'Community', 'jetonomy' ) );
			// Mobile app EULA screen (Apple Guideline 1.2) reads these via /app/config.
			$clean['terms_url']   = esc_url_raw( $input['terms_url'] ?? '' );
			$clean['privacy_url'] = esc_url_raw( $input['privacy_url'] ?? '' );
			$clean['support_url'] = esc_url_raw( $input['support_url'] ?? '' );
			// Product-noun label overrides (singular / plural). Empty = keep the
			// default. One loop over every renamable noun so the save side cannot
			// drift from the Terminology settings block that renders them.
			foreach ( array_keys( \Jetonomy\label_defaults() ) as $jt_noun ) {
				$clean[ $jt_noun . '_label_singular' ] = sanitize_text_field( $input[ $jt_noun . '_label_singular' ] ?? '' );
				$clean[ $jt_noun . '_label_plural' ]   = sanitize_text_field( $input[ $jt_noun . '_label_plural' ] ?? '' );
			}
			// Clamp to the UI max (100) so a crafted POST can't store a huge
			// value that flows straight into a SQL LIMIT on a big-site query.
			$clean['posts_per_page'] = min( 100, max( 1, absint( $input['posts_per_page'] ?? 20 ) ) );
			// Activity-log retention: 1 day to ~10 years. Consumed by the daily
			// jetonomy_prune_activity cron (class-cron.php).
			$clean['activity_log_retention_days'] = min( 3650, max( 1, absint( $input['activity_log_retention_days'] ?? 90 ) ) );
			$clean['replies_per_page']            = min( 100, max( 1, absint( $input['replies_per_page'] ?? 30 ) ) );
			$raw_space_type                       = sanitize_key( (string) ( $input['default_space_type'] ?? 'forum' ) );
			$clean['default_space_type']          = in_array( $raw_space_type, Space::valid_types(), true ) ? $raw_space_type : 'forum';
			// Community access mode — radio stores "1" (public) or "0" (private).
			$clean['guest_read'] = isset( $input['guest_read'] ) ? (bool) (int) $input['guest_read'] : true;
			// Community as homepage — unchecked checkboxes don't submit, so
			// absence inside a General-tab save means OFF (default).
			$clean['front_page'] = ! empty( $input['front_page'] );
			// Private spaces as locked teasers for logged-out visitors (default off).
			$clean['guest_private_teasers'] = ! empty( $input['guest_private_teasers'] );
			if ( $clean['guest_private_teasers'] !== ! empty( $existing['guest_private_teasers'] ) ) {
				Space::bump_tree_generation(); // Cached guest listings must re-read.
			}

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
			// become true.
			//
			// An unchecked checkbox submits nothing, so absence inside a
			// General-tab save means OFF - the same rule front_page above uses.
			$clean['allow_space_admin_purge'] = ! empty( $input['allow_space_admin_purge'] );
			// Arming data removal on delete is for whoever can delete plugins; a
			// delegated settings manager keeps the stored value.
			if ( current_user_can( 'manage_options' ) ) {
				$clean['remove_data_on_uninstall'] = ! empty( $input['remove_data_on_uninstall'] );
			}
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
			// Display names per level; blank keeps the built-in name.
			$raw_names                  = is_array( $input['trust_level_names'] ?? null ) ? $input['trust_level_names'] : array();
			$clean['trust_level_names'] = array();
			// No wp_unslash(): options.php has already unslashed the posted value.
			foreach ( range( 0, 5 ) as $level ) {
				$clean['trust_level_names'][ $level ] = sanitize_text_field( (string) ( $raw_names[ $level ] ?? '' ) );
			}
		}

		// How members are identified on bylines, member lists and REST.
		// Allowlisted rather than trusted: this string is read on every name
		// render, so an unrecognised value must fall back rather than reach
		// user_display_name().
		if ( isset( $input['member_name_display'] ) ) {
			$mode                         = sanitize_key( wp_unslash( $input['member_name_display'] ) );
			$clean['member_name_display'] = in_array( $mode, array( 'display_name', 'handle', 'both' ), true )
				? $mode
				: 'display_name';
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
				if ( ! array_key_exists( $action_key, $raw_rep ) ) {
					continue;
				}

				// An empty field means "use the default", not zero. Storing 0 for a
				// cleared box silently turned off the points for that action.
				if ( '' === trim( (string) $raw_rep[ $action_key ] ) ) {
					continue;
				}

				// Store only what DIFFERS from the default. The form posts every
				// action, so persisting them all made the first save a full set of
				// overrides - after which the jetonomy_reputation_points_map filter
				// could never apply again, because Reputation::points_for() reads a
				// stored override ahead of the filtered map. An owner who never
				// touched this tab had their integration's ladder silently frozen
				// by visiting Permissions once.
				if ( (int) $raw_rep[ $action_key ] === (int) $default_val ) {
					continue;
				}

				$clean_rep[ $action_key ] = (int) $raw_rep[ $action_key ];
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
			// Same single list as the Notification Defaults table. Pro's `message`
			// is only in it (rendered, and so submitted) while messaging is live.
			$notif_types = array_keys( array_filter( self::email_types(), static fn( array $t ): bool => $t['toggle'] ) );
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
		// Our screens name the product, so White Label's brand substitution
		// has something to replace.
		/* translators: %s: plugin name. */
		$text = sprintf( __( 'Thank you for building your community with %s.', 'jetonomy' ), 'Jetonomy' );
		/**
		 * Filter the admin footer text shown on Jetonomy admin pages.
		 *
		 * @since 1.4.1
		 * @param string $text Current footer text.
		 */
		return (string) apply_filters( 'jetonomy_admin_footer_text', $text );
	}

	/**
	 * Hide third-party admin notices on Jetonomy pages.
	 */
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
		<h2><?php echo esc_html( self::brand_name() ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Community emails', 'jetonomy' ); ?></th>
				<td>
					<label for="jetonomy_email_opt_out">
						<input type="checkbox" name="jetonomy_email_opt_out" id="jetonomy_email_opt_out" value="1" <?php checked( $opted_out ); ?> />
						<?php /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'Pause all %s email notifications for this user.', 'jetonomy' ), self::brand_name() ) ); ?>
					</label>
					<p class="description"><?php /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'When enabled, the email verification reminder and other %s emails are suppressed. Web notifications are unaffected.', 'jetonomy' ), self::brand_name() ) ); ?></p>
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
				array( 'wp-i18n' ),
				JETONOMY_VERSION,
				true
			);
			\Jetonomy\script_translations( 'jetonomy-modals' );
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

		// Not "admin.js": `wp i18n make-json` strips /.min.js$/ unescaped, which
		// turns admin.js into a.js, so its translation JSON would never load.
		wp_enqueue_script(
			'jetonomy-admin',
			JETONOMY_URL . 'assets/js/admin-common.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker', 'jetonomy-modals', 'wp-i18n' ),
			JETONOMY_VERSION,
			true
		);
		\Jetonomy\script_translations( 'jetonomy-admin' );

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
			array( 'wp-i18n' ),
			JETONOMY_VERSION,
			true
		);
		\Jetonomy\script_translations( 'jetonomy-icon-picker' );

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
			)
		);
		wp_localize_script( 'jetonomy-admin', 'jetonomyLabels', \Jetonomy\label_map() );

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
				array( 'jetonomy-admin', 'wp-i18n', 'wp-api-fetch' ),
				JETONOMY_VERSION,
				true
			);
			\Jetonomy\script_translations( 'jetonomy-admin-dashboard' );
		} elseif ( str_ends_with( $hook, '_page_jetonomy-revisions' ) ) {
			wp_enqueue_script(
				'jetonomy-admin-revisions',
				JETONOMY_URL . 'assets/js/admin-revisions.js',
				array( 'jetonomy-admin', 'wp-i18n' ),
				JETONOMY_VERSION,
				true
			);
			\Jetonomy\script_translations( 'jetonomy-admin-revisions' );
		} elseif ( str_ends_with( $hook, '_page_jetonomy-tags' ) ) {
			wp_enqueue_script(
				'jetonomy-admin-tags',
				JETONOMY_URL . 'assets/js/admin-tags.js',
				array( 'jetonomy-admin', 'wp-i18n' ),
				JETONOMY_VERSION,
				true
			);
			\Jetonomy\script_translations( 'jetonomy-admin-tags' );
		} elseif ( str_ends_with( $hook, '_page_jetonomy-settings' ) ) {
			wp_enqueue_script(
				'jetonomy-admin-settings',
				JETONOMY_URL . 'assets/js/admin-settings.js',
				array( 'jetonomy-admin', 'wp-i18n' ),
				JETONOMY_VERSION,
				true
			);
			\Jetonomy\script_translations( 'jetonomy-admin-settings' );
		}
	}

	// ── Page Renderers ──

	public function render_dashboard(): void {
		$today = current_time( 'Y-m-d' );

		$stats = array(
			'total_posts'   => Post::count( array( 'status' => 'publish' ) ),
			'total_replies' => Reply::count( array( 'status' => 'publish' ) ),
			'active_spaces' => Space::count( array( 'status' => 'active' ) ),
			'users'         => UserProfile::count(),
			'pending_flags' => Flag::count_pending( \Jetonomy\Moderation\Moderation_Permissions::unreadable_space_ids( get_current_user_id() ) ),
			'posts_today'   => Post::count_published_since( $today . ' 00:00:00' ),
		);

		$recent_activity = ActivityLog::list_recent( 10 ) ?: array();

		$base_slug = \Jetonomy\base_slug();

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

		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$top   = Post::busiest_space_since( $since );

		$pulse = array(
			'posts'           => Post::count_published_since( $since ),
			'replies'         => Reply::count_published_since( $since ),
			'contributors'    => ActivityLog::count_contributors_since( $since ),
			'top_space'       => $top->title ?? null,
			'top_space_posts' => (int) ( $top->n ?? 0 ),
		);

		set_transient( 'jetonomy_weekly_pulse', $pulse, HOUR_IN_SECONDS );
		return $pulse;
	}

	public function render_categories(): void {
		// Flat list of every category (for the parent-select dropdowns) —
		// dropdown needs all values regardless of pagination.
		// Parents only: categories nest two levels deep, so a sub-category
		// can never be offered as a parent.
		$all_categories = Category::list_top_level();

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

	/**
	 * Tell a space admin why the Access Rules screen is closed to them.
	 *
	 * Rules are managed by site administrators only (jetonomy_manage_spaces).
	 * WordPress refuses the page before Jetonomy runs, with a sentence that
	 * reads like a broken link; this runs on WordPress's own refusal hook and
	 * answers the space admin of that space in plain words instead.
	 */
	public function explain_access_rules_refusal(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing of a refusal page.
		if ( 'jetonomy-spaces' !== ( $_GET['page'] ?? '' ) || 'access' !== ( $_GET['tab'] ?? '' ) ) {
			return;
		}
		$space = Space::find( absint( $_GET['space_id'] ?? 0 ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $space || ! \Jetonomy\Permissions\Permission_Engine::is_space_admin( get_current_user_id(), (int) $space->id ) ) {
			return;
		}
		wp_die(
			/* translators: %s: the singular space label, lowercase (e.g. space, group). */
			esc_html( sprintf( __( 'Only site administrators can manage access rules for this %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ),
			esc_html__( 'Access Rules', 'jetonomy' ),
			array(
				'response'  => 403,
				'link_url'  => esc_url( \Jetonomy\route_url( 'space', $space->slug ) ),
				/* translators: %s: where the link returns to - the configured space label, or a specific space title. */
				'link_text' => esc_html( sprintf( __( 'Back to %s', 'jetonomy' ), $space->title ) ),
			)
		);
	}

	public function render_spaces(): void {
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'jetonomy' ), 403 );
		}
		$action   = sanitize_text_field( $_GET['action'] ?? 'list' );
		$space_id = absint( $_GET['space_id'] ?? 0 );

		if ( 'edit' === $action && $space_id > 0 ) {
			$space = Space::find( $space_id );
			if ( ! $space ) {
				/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
				wp_die( esc_html( sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ) ) );
			}
			$categories = Category::list_tree( null, (int) ( $space->category_id ?? 0 ) );
			// Explicit cap: the unbounded default rendered every
			// member row on one screen. 1000 keeps this management surface
			// functional; when the space is larger the view shows a notice so
			// members past the cap are never SILENTLY hidden from the only
			// admin surface that can remove them (the frontend members page
			// is paginated and covers the tail).
			$members        = SpaceMember::list_by_space( $space_id, 1000 );
			$members_capped = ( (int) ( $space->member_count ?? 0 ) ) > count( $members ) && count( $members ) >= 1000;
			$access_rules   = AccessRule::list_for_space( $space_id );
			$space_settings = Space::get_settings( $space_id );
			$join_total     = JoinRequest::count_pending_for_space( $space_id );
			$join_per_page  = 20;
			$join_pages     = max( 1, (int) ceil( $join_total / $join_per_page ) );
			$join_paged     = min( $join_pages, max( 1, absint( $_GET['jrpg'] ?? 1 ) ) );
			$join_requests  = JoinRequest::list_pending_for_space( $space_id, $join_per_page, ( $join_paged - 1 ) * $join_per_page );
			include JETONOMY_DIR . 'includes/admin/views/space-edit.php';
			return;
		}

		// List view
		$filter_category = absint( $_GET['category_id'] ?? 0 );
		$filter_type     = sanitize_text_field( $_GET['type'] ?? '' );
		$filter_status   = sanitize_text_field( $_GET['status'] ?? '' );

		$search_query = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$orderby      = sanitize_key( wp_unslash( $_GET['orderby'] ?? '' ) );
		$order        = 'DESC' === strtoupper( sanitize_key( wp_unslash( $_GET['order'] ?? 'ASC' ) ) ) ? 'DESC' : 'ASC';

		$filters  = array(
			'category_id' => $filter_category,
			'type'        => $filter_type,
			'status'      => $filter_status,
			'search'      => $search_query,
			'orderby'     => $orderby,
			'order'       => $order,
		);
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = absint( $_GET['per_page'] ?? 20 );
		if ( ! in_array( $per_page, array( 20, 50, 100 ), true ) ) {
			$per_page = 20;
		}
		$offset = ( $paged - 1 ) * $per_page;

		// Inside one category (and nothing else filtered) the list follows the
		// manual order members see, so drag-reorder has a coherent target.
		$can_reorder = $filter_category > 0 && ! $filter_type && ! $filter_status && '' === $search_query && '' === $orderby;

		$total       = Space::count_for_admin( $filters );
		$total_pages = (int) ceil( $total / $per_page );
		$spaces      = Space::list_for_admin( $filters, $per_page, $offset );
		$categories  = Category::list_tree();

		include JETONOMY_DIR . 'includes/admin/views/spaces.php';
	}

	public function render_moderation(): void {
		$per_page = 20;

		// Per-tab paged params.
		$paged_posts   = max( 1, absint( $_GET['paged_posts'] ?? 1 ) );
		$paged_replies = max( 1, absint( $_GET['paged_replies'] ?? 1 ) );
		$paged_flags   = max( 1, absint( $_GET['paged_flags'] ?? 1 ) );
		$paged_banned  = max( 1, absint( $_GET['paged_banned'] ?? 1 ) );

		// Site moderators (not administrators) never see the queue of a private
		// or hidden space they cannot read - rows and totals alike.
		$skip          = \Jetonomy\Moderation\Moderation_Permissions::unreadable_space_ids( get_current_user_id() );
		$scope         = $skip ? array( 'skip_spaces' => $skip ) : null;
		$total_posts   = Post::count_by_status( array( 'pending' ), $scope );
		$total_replies = Reply::count_by_status( array( 'pending' ), $scope );
		$total_flags   = Flag::count_pending( $skip );
		$total_banned  = Restriction::count_active();

		$pending_posts   = Post::list_pending_with_space( $per_page, ( $paged_posts - 1 ) * $per_page, $skip );
		$pending_replies = Reply::list_pending_with_post( $per_page, ( $paged_replies - 1 ) * $per_page, $skip );
		$pending_flags   = Flag::list_pending( $per_page, ( $paged_flags - 1 ) * $per_page, $skip );

		$this->prime_flag_objects( $pending_flags );

		// Bans, silences and space bans, with the account name the list shows.
		$banned_users = Restriction::list_active(
			array(
				'limit'  => $per_page,
				'offset' => ( $paged_banned - 1 ) * $per_page,
			)
		);
		if ( $banned_users ) {
			cache_users( array_map( static fn( $r ) => (int) $r->user_id, $banned_users ) );
			foreach ( $banned_users as $ban ) {
				$account           = get_userdata( (int) $ban->user_id );
				$ban->display_name = $account ? $account->display_name : null;
				$ban->user_login   = $account ? $account->user_login : null;
			}
		}

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

		$rows = ActivityLog::export_rows( Activity_List_Table::read_filters() );

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
		fputcsv( $out, array( 'id', 'user_id', 'user_login', 'action', 'object_type', 'object_id', 'metadata', 'created_at' ), escape: '' );
		$objects = \Jetonomy\Author::objects_of( array_map( static fn( $r ) => (object) $r, $rows ) );
		foreach ( $rows as $row ) {
			// Anonymous authors stay anonymous in the export too; only a logged Reveal names them.
			$hidden = \Jetonomy\Author::hides_actor( (int) $row['user_id'], $objects[ $row['object_type'] . ':' . (int) $row['object_id'] ] ?? null );
			if ( $hidden ) {
				$row['user_id'] = 0;
			}
			$user       = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
			$user_login = $hidden ? __( 'Anonymous', 'jetonomy' ) : ( $user ? $user->user_login : '' );
			// Spreadsheets run a cell that starts with = + - @ (or tab/CR) as a formula.
			$safe = static fn( string $cell ): string => preg_match( '/^[=+\-@\t\r]/', $cell ) ? "'" . $cell : $cell;
			fputcsv(
				$out,
				array(
					(string) $row['id'],
					(string) $row['user_id'],
					$safe( (string) $user_login ),
					$safe( (string) $row['action'] ),
					$safe( (string) $row['object_type'] ),
					(string) $row['object_id'],
					$safe( (string) ( $row['metadata'] ?? '' ) ),
					(string) $row['created_at'],
				),
				escape: ''
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
			// One page of snapshots plus the next-older one, so the last row on the page still has something to diff against.
			$rev_per   = 20;
			$rev_page  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation param.
			$rev_total = \Jetonomy\Models\Revision::count(
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
				)
			);
			$revisions = \Jetonomy\Models\Revision::list_for_object( $object_type, $object_id, $rev_per + 1, ( $rev_page - 1 ) * $rev_per );

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
		if ( 'post' === $type ) {
			return Post::titles_by_ids( array( $id ) )[ $id ] ?? '';
		}

		if ( 'reply' === $type ) {
			$parent = Reply::parents_by_ids( array( $id ) )[ $id ]['title'] ?? '';
			if ( '' !== $parent ) {
				return sprintf(
					/* translators: 1: reply label (e.g. "Reply"). 2: parent post title. */
					__( '%1$s to: %2$s', 'jetonomy' ),
					\Jetonomy\jetonomy_label( 'reply' ),
					$parent
				);
			}
		}

		return '';
	}

	public function render_users(): void {
		$search       = sanitize_text_field( $_GET['s'] ?? '' );
		$filter_trust = sanitize_text_field( $_GET['trust_level'] ?? '' );
		$paged        = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page     = 20;
		$offset       = ( $paged - 1 ) * $per_page;

		$total       = UserProfile::count_for_admin( $filter_trust, $search );
		$users       = UserProfile::list_for_admin( $filter_trust, $search, $per_page, $offset );
		$total_pages = ceil( $total / $per_page );
		// One query for the whole page, so each row can name its active restriction.
		$restrictions = Restriction::active_rows_for_users( array_map( static fn( $u ) => (int) $u->user_id, $users ) );
		// A space ban names its space; one query for every space on the page.
		$space_titles = array_column( \Jetonomy\Models\Space::list_by_ids( array_column( $restrictions, 'space_id' ) ), 'title', 'id' );
		// Each row asks whether the viewer may lift it (their own ban in that space): one query for the page.
		Restriction::prime_space_bans( get_current_user_id(), array_column( $restrictions, 'space_id' ) );
		// Each row asks whether the viewer may restrict that member (user_can on them): load the page's users once.
		cache_users( array_map( static fn( $u ) => (int) $u->user_id, $users ) );

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

		$current_space  = absint( $_GET['space_id'] ?? 0 );
		$current_status = sanitize_text_field( $_GET['status'] ?? 'all' );
		$search_query   = sanitize_text_field( $_GET['s'] ?? '' );

		$spaces = Space::list_titles();

		// "Scheduled" is a pseudo-status (a draft with a published_at) and "All"
		// leaves out the trash; Post::list_for_admin() owns both rules.
		$orderby = sanitize_key( wp_unslash( $_GET['orderby'] ?? 'created_at' ) );
		$order   = 'ASC' === strtoupper( sanitize_key( wp_unslash( $_GET['order'] ?? 'DESC' ) ) ) ? 'ASC' : 'DESC';

		$filters = array(
			'space_id' => $current_space,
			'status'   => $current_status,
			'search'   => $search_query,
			'orderby'  => $orderby,
			'order'    => $order,
		);

		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		$total       = Post::count_for_admin( $filters );
		$total_pages = (int) ceil( $total / $per_page );
		$posts       = Post::list_for_admin( $filters, $per_page, $offset );

		// Counts for the Scheduled and Trash filter labels, the only place an
		// owner learns there is anything in either.
		$scheduled_count = Post::count_scheduled();
		$trash_count     = Post::count( array( 'status' => 'trash' ) );

		// Site-timezone "now", for the view's Overdue split. Computed once here
		// rather than per row.
		$scheduled_now = current_time( 'mysql', true );

		include JETONOMY_DIR . 'includes/admin/views/content.php';
	}

	/**
	 * Renders the replies page for a specific post.
	 * Handles pagination for posts with hundreds/thousands of replies.
	 */
	private function render_post_replies( int $post_id ): void {
		$post = Post::find( $post_id );
		if ( ! $post ) {
			wp_die( esc_html( sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( '%s not found.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic' ) ) ) );
		}

		// Status filter.
		$current_status = sanitize_text_field( $_GET['status'] ?? 'all' );
		$valid_statuses = array( 'all', 'publish', 'pending', 'spam', 'trash' );
		if ( ! in_array( $current_status, $valid_statuses, true ) ) {
			$current_status = 'all';
		}

		// Search.
		$search_query = sanitize_text_field( $_GET['s'] ?? '' );

		// Pagination — 50 per page for large reply sets.
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = 50;
		$offset   = ( $paged - 1 ) * $per_page;

		$total       = Reply::count_for_admin_post( $post_id, $current_status, $search_query );
		$total_pages = (int) ceil( $total / $per_page );
		$replies     = Reply::list_for_admin_post( $post_id, $current_status, $search_query, $per_page, $offset );
		$trash_count = Reply::count(
			array(
				'post_id' => $post_id,
				'status'  => 'trash',
			)
		);

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
