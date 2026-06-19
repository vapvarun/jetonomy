<?php
/**
 * BuddyPress integration.
 *
 * Links BP Groups to Jetonomy Spaces with member sync,
 * a Forum tab inside groups, and a summary on member profiles.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Integrations;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;

/**
 * BuddyPress integration — Groups ↔ Spaces.
 */
class BuddyPress {

	/**
	 * Group meta key for the linked Jetonomy space ID.
	 */
	const META_KEY = 'jetonomy_space_id';

	/**
	 * Option key controlling whether new Jetonomy topics broadcast to the
	 * paired BuddyPress group's activity stream. Defaults to '1' (on).
	 */
	const OPT_BROADCAST = 'jetonomy_bp_broadcast';

	/**
	 * Option key controlling whether BuddyPress activity comments on a
	 * broadcast item round-trip back to the Jetonomy topic as replies.
	 * Defaults to '1' (on).
	 */
	const OPT_COMMENT_BRIDGE = 'jetonomy_bp_comment_bridge';

	/**
	 * BP activity meta key used to tag broadcast activities with their
	 * originating Jetonomy post ID. The comment bridge reads this to
	 * decide which activity comments should round-trip as JT replies.
	 */
	const ACTIVITY_META_POST = 'jetonomy_post_id';

	/**
	 * BP activity type for broadcast items. Custom so existing BP themes
	 * and integrations can filter or style these rows distinctly.
	 */
	const ACTIVITY_TYPE = 'jetonomy_topic';

	/**
	 * Loop guard shared between the JT->BP broadcast and the BP->JT reply
	 * bridge so a write on one side cannot trigger a boomerang write back.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Get the URL for a BP group, compatible with all BP versions.
	 *
	 * @param \BP_Groups_Group $group Group object.
	 * @return string Group URL or empty string.
	 */
	public static function get_group_url( $group ): string {
		if ( function_exists( 'bp_get_group_url' ) ) {
			return (string) bp_get_group_url( $group );
		}
		if ( function_exists( 'bp_get_group_permalink' ) ) {
			return (string) bp_get_group_permalink( $group );
		}
		return '';
	}

	/**
	 * Get the profile URL for a BP member, compatible with all BP versions.
	 *
	 * @param int $user_id User ID.
	 * @return string Member profile URL or empty string.
	 */
	public static function get_member_url( int $user_id ): string {
		if ( function_exists( 'bp_members_get_user_url' ) ) {
			return (string) bp_members_get_user_url( $user_id );
		}
		if ( function_exists( 'bp_core_get_user_domain' ) ) {
			return (string) bp_core_get_user_domain( $user_id );
		}
		return '';
	}

	/**
	 * Boot the integration.
	 */
	public function __construct() {
		// Group lifecycle.
		add_action( 'groups_created_group', array( $this, 'on_group_created' ), 10, 2 );
		add_action( 'groups_delete_group', array( $this, 'on_group_deleted' ), 10, 1 );
		add_action( 'groups_details_updated', array( $this, 'on_group_updated' ), 10, 1 );

		// Member sync.
		add_action( 'groups_join_group', array( $this, 'on_member_join' ), 10, 2 );
		add_action( 'groups_leave_group', array( $this, 'on_member_leave' ), 10, 2 );
		add_action( 'groups_remove_member', array( $this, 'on_member_leave' ), 10, 2 );
		add_action( 'groups_ban_member', array( $this, 'on_member_leave' ), 10, 2 );
		add_action( 'groups_unban_member', array( $this, 'on_member_join' ), 10, 2 );
		add_action( 'groups_promote_member', array( $this, 'on_member_promote' ), 10, 3 );
		add_action( 'groups_demote_member', array( $this, 'on_member_demote' ), 10, 2 );

		// Enqueue BP-specific styles on frontend.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		// "Back to Group" link on Jetonomy pages when space is linked to a BP group.
		add_action( 'jetonomy_before_content', array( $this, 'render_back_to_group_banner' ) );

		// Show linked group in sidebar About section.
		add_action( 'jetonomy_sidebar_about_after_meta', array( $this, 'render_sidebar_group_link' ) );

		// BP Group nav: Forum tab.
		add_action( 'bp_setup_nav', array( $this, 'register_group_forum_tab' ), 20 );

		// BP Member profile: Forum summary tab.
		add_action( 'bp_setup_nav', array( $this, 'register_profile_forum_tab' ), 20 );

		// Forum settings in group manage screen (Manage > Details).
		add_action( 'groups_custom_group_fields_editable', array( $this, 'render_group_forum_settings' ) );
		add_action( 'groups_group_details_edited', array( $this, 'save_group_forum_settings' ), 10, 1 );

		// Forum settings in group creation wizard (Details step).
		add_action( 'bp_after_group_details_creation_step', array( $this, 'render_group_forum_settings' ) );
		add_action( 'groups_created_group', array( $this, 'save_group_forum_settings_on_create' ), 20, 1 );

		// Register our custom activity type so BP renders it in group streams.
		add_action( 'bp_register_activity_actions', array( $this, 'register_activity_type' ) );

		// Broadcast: new JT topic -> activity item in the paired BP group.
		if ( $this->broadcast_enabled() ) {
			add_action( 'jetonomy_after_create_post', array( $this, 'on_jt_post_created_for_bp' ), 20, 3 );

			// BP strips <br>/<p> from activity content via kses both on save
			// AND on display, so paragraph breaks in our broadcast would
			// collapse when rendered. Whitelist these two tags (no XSS
			// surface) so broadcast rows keep their paragraph shape in the
			// group activity stream.
			add_filter( 'bp_activity_allowed_tags', array( $this, 'filter_broadcast_allowed_tags' ) );
		}

		// Comment bridge: BP activity comment on a broadcast item -> JT reply.
		if ( $this->comment_bridge_enabled() ) {
			add_action( 'bp_activity_comment_posted', array( $this, 'on_bp_activity_comment_posted' ), 20, 3 );
		}
	}

	/*
	 * ══════════════════════════════════════════════
	 *  Feature Toggles
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Whether JT topics broadcast to the paired BP group activity stream.
	 * Defaults on.
	 */
	public function broadcast_enabled(): bool {
		return '0' !== (string) get_option( self::OPT_BROADCAST, '1' );
	}

	/**
	 * Whether BP activity comments on broadcast items round-trip as JT
	 * replies. Defaults on.
	 */
	public function comment_bridge_enabled(): bool {
		return '0' !== (string) get_option( self::OPT_COMMENT_BRIDGE, '1' );
	}

	/*
	 * ══════════════════════════════════════════════
	 *  Activity Broadcast  (JT topic → BP group stream)
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Register the `jetonomy_topic` activity type with BuddyPress so the
	 * activity stream renders our broadcast rows with a meaningful label
	 * and filters them alongside native BP activity types.
	 */
	public function register_activity_type(): void {
		if ( ! function_exists( 'bp_activity_set_action' ) ) {
			return;
		}
		bp_activity_set_action(
			'groups',
			self::ACTIVITY_TYPE,
			__( 'New forum topic', 'jetonomy' ),
			array( $this, 'format_activity_action' ),
			__( 'Forum Topics', 'jetonomy' ),
			array( 'activity', 'group', 'member', 'member_groups' )
		);
	}

	/**
	 * Render the human-readable action string for a broadcast activity
	 * row. Called by BuddyPress when listing the activity stream.
	 *
	 * @param string $action   Default action string.
	 * @param object $activity BP activity item.
	 * @return string
	 */
	public function format_activity_action( $action, $activity ): string {
		unset( $action );
		$user_link  = bp_core_get_userlink( (int) $activity->user_id );
		$group      = groups_get_group( (int) $activity->item_id );
		$group_name = is_object( $group ) && ! empty( $group->name ) ? (string) $group->name : '';
		$group_link = '' !== $group_name
			? '<a href="' . esc_url( self::get_group_url( $group ) ) . '">' . esc_html( $group_name ) . '</a>'
			: '';

		if ( '' === $group_link ) {
			/* translators: %s: user link. */
			return sprintf( esc_html__( '%s started a new forum topic', 'jetonomy' ), $user_link );
		}
		/* translators: 1: user link, 2: group link. */
		return sprintf( esc_html__( '%1$s started a new forum topic in %2$s', 'jetonomy' ), $user_link, $group_link );
	}

	/**
	 * Broadcast a new Jetonomy topic into the paired BP group's activity
	 * stream. Skips private topics and unpaired spaces. Tags the activity
	 * row with `jetonomy_post_id` meta so the comment bridge can detect
	 * ours and round-trip replies back.
	 *
	 * Signature: do_action('jetonomy_after_create_post', $post_id, $space_id, $request).
	 *
	 * @param int|mixed $post_id  Jetonomy post ID.
	 * @param int|mixed $space_id Jetonomy space ID.
	 * @param mixed     $request  REST request (unused).
	 */
	public function on_jt_post_created_for_bp( $post_id, $space_id, $request ): void {
		unset( $request );
		if ( self::$syncing ) {
			return;
		}
		if ( ! function_exists( 'bp_activity_add' ) || ! bp_is_active( 'activity' ) ) {
			return;
		}

		$post_id  = (int) $post_id;
		$space_id = (int) $space_id;
		if ( $post_id <= 0 || $space_id <= 0 ) {
			return;
		}

		$group_id = self::find_group_by_space( $space_id );
		if ( ! $group_id ) {
			return;
		}

		$post = Post::find( $post_id );
		if ( ! $post || 'publish' !== ( $post->status ?? '' ) ) {
			return;
		}

		// Privacy guard: private topics never broadcast. Group audience
		// can be broader than the private-topic scope.
		if ( ! empty( $post->is_private ) ) {
			return;
		}

		$space = Space::find( $space_id );
		$group = groups_get_group( $group_id );
		if ( ! $group ) {
			return;
		}

		$base      = \Jetonomy\base_url();
		$topic_url = $space ? $base . '/s/' . $space->slug . '/t/' . $post->slug . '/' : '';

		// Jetonomy stores `content_plain` with block-level breaks already
		// stripped, so paragraphs run together. Re-derive a paragraph-aware
		// excerpt from the raw HTML (same approach as the FC broadcast) by
		// converting block-level tag boundaries to double newlines BEFORE
		// stripping tags.
		$excerpt = '';
		if ( ! empty( $post->content ) ) {
			$html    = (string) $post->content;
			$html    = (string) preg_replace( '#</(p|div|blockquote|li|h[1-6])\s*>\s*<\1[^>]*>#i', "\n\n", $html );
			$html    = (string) preg_replace( '#</(p|div|blockquote|h[1-6])\s*>#i', "\n\n", $html );
			$html    = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
			$clean   = wp_strip_all_tags( $html );
			$clean   = (string) preg_replace( '/[ \t]+/', ' ', $clean );
			$clean   = (string) preg_replace( "/\n[ \t]+/", "\n", $clean );
			$clean   = (string) preg_replace( "/\n{3,}/", "\n\n", $clean );
			$excerpt = trim( $clean );
		}

		// Build the rendered body with real <p> tags per paragraph plus a
		// discreet attribution line at the end (reads as a byline, not a
		// shouty CTA). The `bp_activity_allowed_tags` filter registered in
		// the constructor keeps <p> + <br> on BP's kses whitelist during
		// both save and display so paragraph shape survives.
		$rendered = '';
		if ( '' !== $excerpt ) {
			$paras = (array) preg_split( '/\n{2,}/', $excerpt );
			foreach ( $paras as $para ) {
				$para = trim( (string) $para );
				if ( '' !== $para ) {
					$rendered .= '<p>' . esc_html( $para ) . '</p>';
				}
			}
		}
		if ( '' !== $topic_url ) {
			// Attribution line: reads as a discreet byline rather than a
			// hard CTA. BP's kses strips `class` / `id` from activity
			// tags, so no custom styling class here. Theme paragraph
			// margins are enough to separate the attribution line from
			// the preceding excerpt.
			$rendered .= '<p>';
			$rendered .= esc_html__( 'Shared from the forum', 'jetonomy' ) . ' &middot; ';
			$rendered .= '<a href="' . esc_url( $topic_url ) . '" rel="noopener">';
			$rendered .= esc_html__( 'View discussion', 'jetonomy' );
			$rendered .= '</a></p>';
		}

		self::$syncing = true;
		$activity_id   = bp_activity_add(
			array(
				'user_id'           => (int) $post->author_id,
				'component'         => 'groups',
				'type'              => self::ACTIVITY_TYPE,
				'item_id'           => $group_id,
				'secondary_item_id' => $post_id,
				'content'           => $rendered,
				'primary_link'      => $topic_url,
				'hide_sitewide'     => ( 'public' !== (string) ( $group->status ?? 'public' ) ),
			)
		);
		if ( $activity_id && function_exists( 'bp_activity_update_meta' ) ) {
			bp_activity_update_meta( (int) $activity_id, self::ACTIVITY_META_POST, $post_id );
		}
		self::$syncing = false;
	}

	/**
	 * Add `<br>` and `<p>` to BuddyPress's activity content allowedtags
	 * map so our broadcast row can keep its paragraph breaks. Only added
	 * for the duration of a single `bp_activity_add` call; removed again
	 * right after so other activities stay on BP's strict default.
	 *
	 * @param array $tags Allowed tags map passed by `bp_activity_allowed_tags`.
	 * @return array
	 */
	public function filter_broadcast_allowed_tags( $tags ): array {
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}
		if ( ! isset( $tags['br'] ) ) {
			$tags['br'] = array();
		}
		if ( ! isset( $tags['p'] ) ) {
			$tags['p'] = array();
		}
		return $tags;
	}

	/*
	 * ══════════════════════════════════════════════
	 *  Comment-to-Reply Bridge  (BP comment → JT reply)
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Mirror a BP activity comment back to the originating Jetonomy topic
	 * as a reply, but only when the top-level activity was one of our
	 * broadcast rows (identified by `jetonomy_post_id` activity meta).
	 * Native BP activity comments are untouched.
	 *
	 * Signature: do_action('bp_activity_comment_posted', $comment_id, $r, $activity).
	 *
	 * @param int   $comment_id Newly created BP activity comment ID (unused).
	 * @param array $r          Comment args: content, user_id, activity_id, parent_id, skip_notification.
	 * @param mixed $activity   Top-level BP_Activity_Activity being commented on.
	 */
	public function on_bp_activity_comment_posted( $comment_id, $r, $activity ): void {
		unset( $comment_id );
		if ( self::$syncing ) {
			return;
		}
		if ( ! function_exists( 'bp_activity_get_meta' ) ) {
			return;
		}

		$top_activity_id = is_object( $activity ) && isset( $activity->id ) ? (int) $activity->id : 0;
		if ( $top_activity_id <= 0 ) {
			return;
		}

		$jt_post_id = (int) bp_activity_get_meta( $top_activity_id, self::ACTIVITY_META_POST, true );
		if ( $jt_post_id <= 0 ) {
			return;
		}

		$jt_post = Post::find( $jt_post_id );
		if ( ! $jt_post || 'publish' !== ( $jt_post->status ?? '' ) ) {
			return;
		}

		$content = isset( $r['content'] ) ? (string) $r['content'] : '';
		$user_id = isset( $r['user_id'] ) ? (int) $r['user_id'] : 0;
		if ( '' === trim( $content ) || $user_id <= 0 ) {
			return;
		}

		$html  = wp_kses_post( $content );
		$plain = trim( wp_strip_all_tags( $content ) );
		if ( '' === $plain ) {
			return;
		}

		self::$syncing = true;
		Reply::create(
			array(
				'post_id'       => $jt_post_id,
				'author_id'     => $user_id,
				'content'       => $html,
				'content_plain' => $plain,
				'status'        => 'publish',
			)
		);
		self::$syncing = false;
	}

	/*
	 * ══════════════════════════════════════════════
	 *  Assets
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Enqueue BuddyPress integration stylesheet on BP pages.
	 */
	public function enqueue_styles(): void {
		if ( ! is_buddypress() ) {
			return;
		}
		wp_enqueue_style(
			'jetonomy-buddypress',
			JETONOMY_URL . 'assets/css/buddypress.css',
			array(),
			JETONOMY_VERSION
		);
	}

	/*
	 * ══════════════════════════════════════════════
	 *  Group Lifecycle
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Auto-create a Jetonomy space when a BP group is created.
	 *
	 * @param int    $group_id Group ID.
	 * @param object $group    BP_Groups_Group object.
	 */
	public function on_group_created( int $group_id, object $group ): void {
		// Only create a forum space when explicitly requested via the form.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['jt_bp_forum_action'] )
			? sanitize_text_field( wp_unslash( $_POST['jt_bp_forum_action'] ) )
			: 'none';

		if ( 'create' !== $action ) {
			// 'none', 'link_*', or no form data — don't auto-create.
			// The save_group_forum_settings_on_create handler at priority 20 handles linking.
			return;
		}

		// Explicitly requested: create a linked space for the new group.
		$visibility_map = array(
			'public'  => 'public',
			'private' => 'private',
			'hidden'  => 'hidden',
		);

		$bp_status  = $group->status ?? 'public';
		$visibility = $visibility_map[ $bp_status ] ?? 'public';

		$space_id = Space::create(
			array(
				'title'       => $group->name ?? '',
				'slug'        => sanitize_title( $group->name ?? 'bp-group-' . $group_id ),
				'description' => $group->description,
				'visibility'  => $visibility,
				'author_id'   => (int) $group->creator_id ?: get_current_user_id(),
			)
		);

		if ( $space_id ) {
			groups_update_groupmeta( $group_id, self::META_KEY, $space_id );

			// Add group creator as space admin.
			$creator_id = (int) ( (int) $group->creator_id ?: get_current_user_id() );
			if ( $creator_id ) {
				$result = SpaceMember::add( $space_id, $creator_id, 'admin' );
				if ( is_wp_error( $result ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[Jetonomy] BP group sync: failed to add creator to space — ' . $result->get_error_message() );
				}
			}
		}
	}

	/**
	 * Unlink space when group is deleted. Space is preserved, just unlinked.
	 *
	 * @param int $group_id Group ID.
	 */
	public function on_group_deleted( int $group_id ): void {
		groups_delete_groupmeta( $group_id, self::META_KEY );
	}

	/**
	 * Sync group name/description changes to the linked space.
	 *
	 * @param int $group_id Group ID.
	 */
	public function on_group_updated( int $group_id ): void {
		$space_id = $this->get_linked_space( $group_id );
		if ( ! $space_id ) {
			return;
		}

		$group = groups_get_group( $group_id );
		if ( ! $group ) {
			return;
		}

		$visibility_map = array(
			'public'  => 'public',
			'private' => 'private',
			'hidden'  => 'hidden',
		);

		$data = array();
		if ( ! empty( $group->name ) ) {
			$data['title'] = $group->name;
		}
		if ( isset( $group->description ) ) {
			$data['description'] = $group->description;
		}
		if ( ! empty( $group->status ) && isset( $visibility_map[ $group->status ] ) ) {
			$data['visibility'] = $visibility_map[ $group->status ];
		}

		if ( ! empty( $data ) ) {
			Space::update( $space_id, $data );
		}
	}

	/*
	 * ══════════════════════════════════════════════
	 *  Member Sync
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Add user to linked space when they join the BP group.
	 *
	 * @param int $group_id Group ID.
	 * @param int $user_id  User ID.
	 */
	public function on_member_join( int $group_id, int $user_id ): void {
		$space_id = $this->get_linked_space( $group_id );
		if ( ! $space_id ) {
			return;
		}

		if ( ! SpaceMember::is_member( $space_id, $user_id ) ) {
			$result = SpaceMember::add( $space_id, $user_id, 'member' );
			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[Jetonomy] BP member join sync: ' . $result->get_error_message() );
			}
		}
	}

	/**
	 * Remove user from linked space when they leave/are removed/banned.
	 *
	 * @param int $group_id Group ID.
	 * @param int $user_id  User ID.
	 */
	public function on_member_leave( int $group_id, int $user_id ): void {
		$space_id = $this->get_linked_space( $group_id );
		if ( ! $space_id ) {
			return;
		}

		SpaceMember::remove( $space_id, $user_id );
	}

	/**
	 * Promote user role in linked space when promoted in BP group.
	 *
	 * @param int    $group_id Group ID.
	 * @param int    $user_id  User ID.
	 * @param string $status   New BP status: 'admin' or 'mod'.
	 */
	public function on_member_promote( int $group_id, int $user_id, string $status ): void {
		$space_id = $this->get_linked_space( $group_id );
		if ( ! $space_id ) {
			return;
		}

		$role_map = array(
			'admin' => 'admin',
			'mod'   => 'moderator',
		);
		$role     = $role_map[ $status ] ?? 'member';

		// SpaceMember::add with REPLACE semantics updates the role.
		$result = SpaceMember::add( $space_id, $user_id, $role );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Jetonomy] BP member promote sync: ' . $result->get_error_message() );
		}
	}

	/**
	 * Demote user role in linked space when demoted in BP group.
	 *
	 * @param int $group_id Group ID.
	 * @param int $user_id  User ID.
	 */
	public function on_member_demote( int $group_id, int $user_id ): void {
		$space_id = $this->get_linked_space( $group_id );
		if ( ! $space_id ) {
			return;
		}

		$result = SpaceMember::add( $space_id, $user_id, 'member' );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Jetonomy] BP member demote sync: ' . $result->get_error_message() );
		}
	}

	/*
	 * ══════════════════════════════════════════════
	 *  BP Group: Forum Tab
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Register a "Forum" sub-nav inside BP Group pages.
	 */
	public function register_group_forum_tab(): void {
		if ( ! bp_is_active( 'groups' ) || ! bp_is_group() ) {
			return;
		}

		$group_id = bp_get_current_group_id();
		$space_id = $this->get_linked_space( $group_id );
		if ( ! $space_id ) {
			return;
		}

		bp_core_new_subnav_item(
			array(
				'name'            => __( 'Forum', 'jetonomy' ),
				'slug'            => 'forum',
				'parent_slug'     => bp_get_current_group_slug(),
				'parent_url'      => self::get_group_url( groups_get_current_group() ),
				'position'        => 30,
				'screen_function' => array( $this, 'group_forum_screen' ),
				'user_has_access' => true,
			),
			'groups'
		);
	}

	/**
	 * Screen callback for the group Forum tab.
	 */
	public function group_forum_screen(): void {
		add_action( 'bp_template_content', array( $this, 'group_forum_content' ) );
		bp_core_load_template( array( 'groups/single/plugins' ) );
	}

	/**
	 * Render the forum topics list inside the BP group.
	 */
	public function group_forum_content(): void {
		$group_id = bp_get_current_group_id();
		$space_id = $this->get_linked_space( $group_id );
		if ( ! $space_id ) {
			return;
		}

		$space = Space::find( $space_id );
		if ( ! $space ) {
			return;
		}

		// Visibility-aware listing so BuddyPress activity-stream widgets don't
		// surface private topics to non-author, non-privileged viewers
		// (Basecamp 9803998504). Uses the same gate as the space template.
		$bp_user_id       = get_current_user_id();
		$bp_is_privileged = $bp_user_id
			&& ( user_can( $bp_user_id, 'manage_options' )
				|| \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $bp_user_id, $space_id ) );
		$posts            = Post::list_by_space_visible( $space_id, (int) $bp_user_id, (bool) $bp_is_privileged, 'latest', 20 );
		$base             = \Jetonomy\base_url();
		$space_url        = $base . '/s/' . $space->slug . '/';
		$new_post_url     = $space_url . 'new/';
		$post_count       = count( $posts );

		echo '<div class="jt-bp-forum">';

		// Header + action bar.
		echo '<div class="jt-bp-forum-head">';
		echo '<strong>' . esc_html( $space->title ) . '</strong>';
		if ( is_user_logged_in() ) {
			echo ' <a href="' . esc_url( $new_post_url ) . '" class="button bp-primary-action">' . esc_html__( '+ New Topic', 'jetonomy' ) . '</a>';
		}
		echo '</div>';

		if ( empty( $posts ) ) {
			echo '<p class="jt-bp-empty">' . esc_html__( 'No topics yet. Start a discussion!', 'jetonomy' ) . '</p>';
		} else {
			echo '<ul class="jt-bp-recent">';
			foreach ( $posts as $post ) {
				$post_url = $base . '/s/' . $space->slug . '/t/' . $post->slug . '/';
				$author   = get_userdata( (int) $post->author_id );
				$time_ago = human_time_diff( strtotime( $post->last_reply_at ?? $post->created_at ), time() );
				$replies  = (int) $post->reply_count;

				echo '<li>';
				echo '<div class="jt-bp-topic-row">';
				echo '<a href="' . esc_url( $post_url ) . '">' . esc_html( $post->title ) . '</a>';
				if ( $replies > 0 ) {
					// translators: %d: number of replies.
					echo ' <span class="jt-bp-space-tag">' . esc_html( sprintf( _n( '%d reply', '%d replies', $replies, 'jetonomy' ), $replies ) ) . '</span>';
				}
				echo '</div>';
				echo '<div class="jt-bp-topic-meta">';
				echo '<span>' . esc_html( $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ) ) . '</span>';
				// translators: %s: human-readable time difference.
				echo ' <span class="jt-bp-time">' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) ) . '</span>';
				echo '</div>';
				echo '</li>';
			}
			echo '</ul>';

			echo '<p class="jt-bp-view-all"><a href="' . esc_url( $space_url ) . '">' . esc_html__( 'View all topics', 'jetonomy' ) . ' &rarr;</a></p>';
		}

		echo '</div>';
	}

	/*
	 * ══════════════════════════════════════════════
	 *  BP Member Profile: Forum Summary Tab
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Register the "Forum" nav item with sub-tabs on BP member profiles.
	 */
	public function register_profile_forum_tab(): void {
		if ( ! bp_is_active( 'groups' ) ) {
			return;
		}

		$user    = bp_is_my_profile() ? wp_get_current_user() : get_userdata( bp_displayed_user_id() );
		$bp_url  = $user ? self::get_member_url( $user->ID ) : '';
		$jt_base = \Jetonomy\base_url();
		$jt_url  = $user ? $jt_base . '/u/' . $user->user_login . '/' : $jt_base;

		bp_core_new_nav_item(
			array(
				'name'                    => __( 'Forum', 'jetonomy' ),
				'slug'                    => 'forum',
				'position'                => 80,
				'screen_function'         => array( $this, 'profile_posts_screen' ),
				'show_for_displayed_user' => true,
				'default_subnav_slug'     => 'posts',
			)
		);

		// Sub-tab: Posts (default).
		bp_core_new_subnav_item(
			array(
				'name'            => __( 'Posts', 'jetonomy' ),
				'slug'            => 'posts',
				'parent_slug'     => 'forum',
				'parent_url'      => $bp_url . 'forum/',
				'position'        => 10,
				'screen_function' => array( $this, 'profile_posts_screen' ),
			)
		);

		// Sub-tab: Replies.
		bp_core_new_subnav_item(
			array(
				'name'            => __( 'Replies', 'jetonomy' ),
				'slug'            => 'replies',
				'parent_slug'     => 'forum',
				'parent_url'      => $bp_url . 'forum/',
				'position'        => 20,
				'screen_function' => array( $this, 'profile_replies_screen' ),
			)
		);

		// Sub-tab: Bookmarks (own profile only).
		if ( bp_is_my_profile() ) {
			bp_core_new_subnav_item(
				array(
					'name'            => __( 'Bookmarks', 'jetonomy' ),
					'slug'            => 'bookmarks',
					'parent_slug'     => 'forum',
					'parent_url'      => $bp_url . 'forum/',
					'position'        => 30,
					'screen_function' => array( $this, 'profile_bookmarks_screen' ),
					'user_has_access' => bp_is_my_profile(),
				)
			);
		}
	}

	/* Screen callbacks */

	public function profile_posts_screen(): void {
		add_action( 'bp_template_content', array( $this, 'render_profile_posts' ) );
		bp_core_load_template( array( 'members/single/plugins' ) );
	}

	public function profile_replies_screen(): void {
		add_action( 'bp_template_content', array( $this, 'render_profile_replies' ) );
		bp_core_load_template( array( 'members/single/plugins' ) );
	}

	public function profile_bookmarks_screen(): void {
		add_action( 'bp_template_content', array( $this, 'render_profile_bookmarks' ) );
		bp_core_load_template( array( 'members/single/plugins' ) );
	}

	/* ── Profile Sub-Tab: Posts ── */

	public function render_profile_posts(): void {
		$user_id = bp_displayed_user_id();
		$this->render_profile_stats( $user_id );

		$base  = \Jetonomy\base_url();
		$posts = Post::list_by_author( $user_id, 10 );

		if ( ! empty( $posts ) ) {
			echo '<ul class="jt-bp-recent">';
			foreach ( $posts as $post ) {
				$space    = Space::find( (int) $post->space_id );
				$post_url = $base . '/s/' . ( $space ? $space->slug : '' ) . '/t/' . $post->slug . '/';
				$time_ago = human_time_diff( strtotime( $post->created_at ), time() );
				echo '<li>';
				echo '<a href="' . esc_url( $post_url ) . '">' . esc_html( $post->title ) . '</a>';
				if ( $space ) {
					echo ' <span class="jt-bp-space-tag">' . esc_html( $space->title ) . '</span>';
				}
				// translators: %s: human-readable time difference.
				echo ' <span class="jt-bp-time">' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) ) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="jt-bp-empty">' . esc_html__( 'No forum posts yet.', 'jetonomy' ) . '</p>';
		}

		$this->render_profile_link( $user_id );
	}

	/* ── Profile Sub-Tab: Replies ── */

	public function render_profile_replies(): void {
		$user_id = bp_displayed_user_id();
		$this->render_profile_stats( $user_id );

		global $wpdb;
		$base = \Jetonomy\base_url();
		$p    = $wpdb->prefix;

		// Space-visibility + per-post is_private gate on the PARENT post so a
		// member's replies in private/hidden spaces (or under private posts)
		// don't leak to non-member / non-author profile visitors.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
		[ $priv_sql, $priv_params ]           = \Jetonomy\Search\Fulltext_Search::visibility_clause( null, 'p' );
		$gate_sql                             = '';
		$gate_params                          = array();
		if ( '1=1' !== $space_vis_sql ) {
			$gate_sql   .= ' AND ' . $space_vis_sql;
			$gate_params = array_merge( $gate_params, $space_vis_params );
		}
		if ( '' !== $priv_sql ) {
			$gate_sql   .= ' AND ' . $priv_sql;
			$gate_params = array_merge( $gate_params, $priv_params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$replies = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT r.id, r.content_plain, r.created_at, r.post_id,
				        p.title AS post_title, p.slug AS post_slug, p.space_id,
				        s.slug AS space_slug, s.title AS space_title
				 FROM {$p}jt_replies r
				 INNER JOIN {$p}jt_posts p ON p.id = r.post_id
				 INNER JOIN {$p}jt_spaces s ON s.id = p.space_id
				 WHERE r.author_id = %d AND r.status = 'publish'{$gate_sql}
				 ORDER BY r.created_at DESC
				 LIMIT 10",
				$user_id,
				...$gate_params
			)
		);

		if ( ! empty( $replies ) ) {
			echo '<ul class="jt-bp-recent">';
			foreach ( $replies as $reply ) {
				$post_url = $base . '/s/' . $reply->space_slug . '/t/' . $reply->post_slug . '/';
				$time_ago = human_time_diff( strtotime( $reply->created_at ), time() );
				$snippet  = wp_trim_words( $reply->content_plain, 15, '...' );
				echo '<li>';
				echo '<a href="' . esc_url( $post_url ) . '">' . esc_html( $snippet ) . '</a>';
				echo ' <span class="jt-bp-space-tag">' . esc_html( $reply->post_title ) . '</span>';
				// translators: %s: human-readable time difference.
				echo ' <span class="jt-bp-time">' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) ) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="jt-bp-empty">' . esc_html__( 'No forum replies yet.', 'jetonomy' ) . '</p>';
		}

		$this->render_profile_link( $user_id );
	}

	/* ── Profile Sub-Tab: Bookmarks ── */

	public function render_profile_bookmarks(): void {
		$user_id = bp_displayed_user_id();
		$this->render_profile_stats( $user_id );

		$bookmarks = \Jetonomy\Models\Bookmark::list_by_user( $user_id, 10 );
		$base      = \Jetonomy\base_url();

		if ( ! empty( $bookmarks ) ) {
			echo '<ul class="jt-bp-recent">';
			foreach ( $bookmarks as $post ) {
				$space    = Space::find( (int) $post->space_id );
				$post_url = $base . '/s/' . ( $space ? $space->slug : '' ) . '/t/' . $post->slug . '/';
				$time_ago = human_time_diff( strtotime( $post->bookmarked_at ?? $post->created_at ), time() );
				echo '<li>';
				echo '<a href="' . esc_url( $post_url ) . '">' . esc_html( $post->title ) . '</a>';
				if ( $space ) {
					echo ' <span class="jt-bp-space-tag">' . esc_html( $space->title ) . '</span>';
				}
				// translators: %s: human-readable time difference.
				echo ' <span class="jt-bp-time">' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) ) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="jt-bp-empty">' . esc_html__( 'No bookmarked posts yet.', 'jetonomy' ) . '</p>';
		}

		$this->render_profile_link( $user_id );
	}

	/* ── Profile Shared Helpers ── */

	private function render_profile_stats( int $user_id ): void {
		$profile     = UserProfile::find_by_user( $user_id );
		$post_count  = $profile ? (int) $profile->post_count : 0;
		$reply_count = $profile ? (int) $profile->reply_count : 0;
		$reputation  = $profile ? (int) $profile->reputation : 0;
		$trust_level = $profile ? (int) $profile->trust_level : 0;

		echo '<div class="jt-bp-stats">';
		echo '<div class="jt-bp-stat"><strong>' . esc_html( (string) $post_count ) . '</strong> ' . esc_html__( 'Topics', 'jetonomy' ) . '</div>';
		echo '<div class="jt-bp-stat"><strong>' . esc_html( (string) $reply_count ) . '</strong> ' . esc_html__( 'Replies', 'jetonomy' ) . '</div>';
		echo '<div class="jt-bp-stat"><strong>' . esc_html( (string) $reputation ) . '</strong> ' . esc_html__( 'Reputation', 'jetonomy' ) . '</div>';
		echo '<div class="jt-bp-stat"><strong>' . esc_html__( 'Level', 'jetonomy' ) . ' ' . esc_html( (string) $trust_level ) . '</strong> ' . esc_html__( 'Trust', 'jetonomy' ) . '</div>';
		echo '</div>';
	}

	private function render_profile_link( int $user_id ): void {
		$user   = get_userdata( $user_id );
		$base   = \Jetonomy\base_url();
		$jt_url = $user ? $base . '/u/' . $user->user_login . '/' : $base;

		echo '<p class="jt-bp-profile-link"><a href="' . esc_url( $jt_url ) . '" class="button">' . esc_html__( 'View Full Forum Profile', 'jetonomy' ) . ' &rarr;</a></p>';
	}

	/*
	 * ══════════════════════════════════════════════
	 *  Group Create/Manage — Forum Settings
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Render forum settings in group creation wizard and group manage screen.
	 *
	 * Fires via groups_custom_group_fields_editable (creation step + manage > details).
	 */
	public function render_group_forum_settings(): void {
		$group_id       = bp_get_current_group_id();
		$linked_space   = $group_id ? $this->get_linked_space( $group_id ) : null;
		$existing_space = $linked_space ? Space::find( $linked_space ) : null;

		// Get unlinked spaces that the current user owns or moderates.
		global $wpdb;
		$p       = $wpdb->prefix;
		$bp      = buddypress();
		$user_id = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$linked_ids           = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$bp->groups->table_name_groupmeta} WHERE meta_key = %s AND meta_value != ''",
				self::META_KEY
			)
		);
		$exclude              = ! empty( $linked_ids ) ? array_map( 'absint', $linked_ids ) : array( 0 );
		$exclude_placeholders = implode( ',', array_fill( 0, count( $exclude ), '%d' ) );

		// Only show spaces the user is admin/moderator of, or site admins see all.
		if ( current_user_can( 'manage_options' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$available_spaces = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, slug FROM {$p}jt_spaces WHERE id NOT IN ({$exclude_placeholders}) ORDER BY title ASC",
					...$exclude
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$available_spaces = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.title, s.slug FROM {$p}jt_spaces s
					INNER JOIN {$p}jt_space_members sm ON sm.space_id = s.id AND sm.user_id = %d AND sm.role IN ('admin', 'moderator')
					WHERE s.id NOT IN ({$exclude_placeholders})
					ORDER BY s.title ASC",
					$user_id,
					...$exclude
				)
			);
		}

		// Re-include the currently linked space so it shows as selected.
		if ( $existing_space && ! in_array( (int) $existing_space->id, array_column( $available_spaces, 'id' ), true ) ) {
			array_unshift( $available_spaces, $existing_space );
		}
		?>
		<div class="jt-bp-forum-settings">
			<h4><?php esc_html_e( 'Discussion Forum', 'jetonomy' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Add a discussion forum to this group. Members will be synced automatically.', 'jetonomy' ); ?></p>

			<label for="jt-bp-forum-action">
				<?php esc_html_e( 'Discussion Forum', 'jetonomy' ); ?>
			</label>
			<select name="jt_bp_forum_action" id="jt-bp-forum-action">
				<option value="none" <?php selected( ! $linked_space ); ?>><?php esc_html_e( 'No forum', 'jetonomy' ); ?></option>
				<option value="create" <?php selected( false ); ?>><?php esc_html_e( 'Create new discussion forum', 'jetonomy' ); ?></option>
				<?php if ( ! empty( $available_spaces ) ) : ?>
					<optgroup label="<?php esc_attr_e( 'Link existing forum', 'jetonomy' ); ?>">
						<?php foreach ( $available_spaces as $space ) : ?>
							<option value="link_<?php echo absint( $space->id ); ?>" <?php selected( $linked_space, (int) $space->id ); ?>>
								<?php echo esc_html( $space->title ); ?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endif; ?>
			</select>
		</div>
		<?php
	}

	/**
	 * Save forum settings when group details are edited (manage screen).
	 *
	 * @param int $group_id Group ID.
	 */
	public function save_group_forum_settings( int $group_id ): void {
		if ( ! isset( $_POST['jt_bp_forum_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- BP handles nonce
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['jt_bp_forum_action'] ) );
		$this->process_forum_action( $group_id, $action );
	}

	/**
	 * Save forum settings during group creation (after group is saved).
	 *
	 * @param int $group_id Group ID.
	 */
	public function save_group_forum_settings_on_create( int $group_id ): void {
		if ( ! isset( $_POST['jt_bp_forum_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- BP handles nonce
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['jt_bp_forum_action'] ) );

		// During creation, if action is "create", the on_group_created handler
		// at priority 10 already created a space. Only handle "link" and "none".
		if ( 'create' === $action ) {
			return; // Already handled by on_group_created at priority 10.
		}

		$this->process_forum_action( $group_id, $action );
	}

	/**
	 * Process forum link/unlink/create action for a group.
	 *
	 * @param int    $group_id Group ID.
	 * @param string $action   'none', 'create', or 'link_{space_id}'.
	 */
	private function process_forum_action( int $group_id, string $action ): void {
		$current_space = $this->get_linked_space( $group_id );

		if ( 'none' === $action ) {
			if ( $current_space ) {
				self::unlink_group( $group_id );
			}
			return;
		}

		if ( 'create' === $action ) {
			// Unlink old space first.
			if ( $current_space ) {
				self::unlink_group( $group_id );
			}

			$group          = groups_get_group( $group_id );
			$visibility_map = array(
				'public'  => 'public',
				'private' => 'private',
				'hidden'  => 'hidden',
			);
			$bp_status      = $group->status ?? 'public';
			$space_id       = Space::create(
				array(
					'title'       => $group->name ?? 'Group Forum',
					'slug'        => sanitize_title( ( $group->name ?? 'group' ) . '-forum' ),
					'description' => $group->description,
					'visibility'  => $visibility_map[ $bp_status ] ?? 'public',
					'author_id'   => (int) $group->creator_id ?: get_current_user_id(),
				)
			);

			if ( $space_id ) {
				self::link_group_to_space( $group_id, $space_id );
				$creator = (int) ( (int) $group->creator_id ?: get_current_user_id() );
				if ( $creator ) {
					$add_res = SpaceMember::add( $space_id, $creator, 'admin' );
					if ( is_wp_error( $add_res ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( '[Jetonomy] BP group link: failed to add creator — ' . $add_res->get_error_message() );
					}
				}
			}
			return;
		}

		// Link existing space: action = "link_{space_id}".
		if ( 0 === strpos( $action, 'link_' ) ) {
			$target_space = absint( substr( $action, 5 ) );
			if ( $target_space && Space::find( $target_space ) ) {
				// Prevent linking a space already linked to another group.
				$already_linked = self::find_group_by_space( $target_space );
				if ( $already_linked && $already_linked !== $group_id ) {
					return;
				}
				if ( $current_space && $current_space !== $target_space ) {
					self::unlink_group( $group_id );
				}
				self::link_group_to_space( $group_id, $target_space );
			}
		}
	}

	/*
	 * ══════════════════════════════════════════════
	 *  Sidebar — Linked Group
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Show the linked BP group in the sidebar About card.
	 *
	 * @param object $space The current space object.
	 */
	public function render_sidebar_group_link( $space ): void {
		if ( ! isset( $space->id ) ) {
			return;
		}

		$group_id = self::find_group_by_space( (int) $space->id );
		if ( ! $group_id ) {
			return;
		}

		$group = groups_get_group( $group_id );
		if ( ! $group || empty( $group->name ) ) {
			return;
		}

		$group_url = self::get_group_url( $group );
		?>
		<div class="jt-sidebar-meta" style="margin-top: 8px;">
			<a href="<?php echo esc_url( $group_url ); ?>" class="jt-tag" style="text-decoration: none;">
				<?php echo esc_html( $group->name ); ?> &rarr;
			</a>
		</div>
		<?php
	}

	/*
	 * ══════════════════════════════════════════════
	 *  "Back to Group" Banner
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Show a "Back to Group" banner on Jetonomy pages when the space is linked to a BP group.
	 *
	 * @param array $data Template data with 'slug' key for the current space.
	 */
	public function render_back_to_group_banner( $data = array() ): void {
		$slug = $data['slug'] ?? '';
		if ( ! $slug ) {
			return;
		}

		$space = Space::find_by_slug( $slug );
		if ( ! $space ) {
			return;
		}

		$group_id = self::find_group_by_space( (int) $space->id );
		if ( ! $group_id ) {
			return;
		}

		$group = groups_get_group( $group_id );
		if ( ! $group || empty( $group->name ) ) {
			return;
		}

		$group_url = self::get_group_url( $group );
		?>
		<div class="jt-bp-back-banner">
			<a href="<?php echo esc_url( $group_url ); ?>">
				&larr; <?php echo esc_html( $group->name ); ?>
			</a>
		</div>
		<?php
	}

	/*
	 * ══════════════════════════════════════════════
	 *  Helpers
	 * ══════════════════════════════════════════════
	 */

	/**
	 * Get the linked Jetonomy space ID for a BP group.
	 *
	 * @param int $group_id BP Group ID.
	 * @return int|null Space ID or null if not linked.
	 */
	public function get_linked_space( int $group_id ): ?int {
		$space_id = groups_get_groupmeta( $group_id, self::META_KEY, true );
		return $space_id ? (int) $space_id : null;
	}

	/**
	 * Manually link a BP group to a Jetonomy space.
	 *
	 * @param int $group_id BP Group ID.
	 * @param int $space_id Jetonomy Space ID.
	 */
	public static function link_group_to_space( int $group_id, int $space_id ): void {
		groups_update_groupmeta( $group_id, self::META_KEY, $space_id );
	}

	/**
	 * Unlink a BP group from its Jetonomy space.
	 *
	 * @param int $group_id BP Group ID.
	 */
	public static function unlink_group( int $group_id ): void {
		groups_delete_groupmeta( $group_id, self::META_KEY );
	}

	/**
	 * Find the BP group linked to a given space ID.
	 *
	 * @param int $space_id Jetonomy Space ID.
	 * @return int|null BP Group ID or null.
	 */
	public static function find_group_by_space( int $space_id ): ?int {
		global $wpdb;

		$bp = buddypress();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$group_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT group_id FROM {$bp->groups->table_name_groupmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_KEY,
				(string) $space_id
			)
		);

		return $group_id ? (int) $group_id : null;
	}
}
