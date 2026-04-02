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

		// BP Group nav: Forum tab.
		add_action( 'bp_setup_nav', array( $this, 'register_group_forum_tab' ), 20 );

		// BP Member profile: Forum summary tab.
		add_action( 'bp_setup_nav', array( $this, 'register_profile_forum_tab' ), 20 );
	}

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
				'description' => $group->description ?? '',
				'visibility'  => $visibility,
				'author_id'   => $group->creator_id ?? get_current_user_id(),
			)
		);

		if ( $space_id ) {
			groups_update_groupmeta( $group_id, self::META_KEY, $space_id );

			// Add group creator as space admin.
			$creator_id = (int) ( $group->creator_id ?? get_current_user_id() );
			if ( $creator_id ) {
				SpaceMember::add( $space_id, $creator_id, 'admin' );
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

		$data = array();
		if ( ! empty( $group->name ) ) {
			$data['title'] = $group->name;
		}
		if ( isset( $group->description ) ) {
			$data['description'] = $group->description;
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
			SpaceMember::add( $space_id, $user_id, 'member' );
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
		SpaceMember::add( $space_id, $user_id, $role );
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

		SpaceMember::add( $space_id, $user_id, 'member' );
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
				'parent_url'      => bp_get_group_url( groups_get_current_group() ),
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
		bp_core_load_template( 'groups/single/plugins' );
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

		$user_id       = get_current_user_id();
		$is_privileged = \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $user_id, $space_id );
		$posts         = Post::list_by_space_visible( $space_id, $user_id, $is_privileged, 'latest', 20 );
		$base          = \Jetonomy\base_url();
		$space_url     = $base . '/s/' . $space->slug . '/';
		$new_post_url  = $space_url . 'new/';

		echo '<div class="jt-bp-forum">';
		echo '<div class="jt-bp-forum-head">';
		echo '<strong>' . esc_html( $space->title ) . '</strong>';
		echo ' <a href="' . esc_url( $new_post_url ) . '" class="button bp-primary-action">' . esc_html__( '+ New Topic', 'jetonomy' ) . '</a>';
		echo '</div>';

		if ( empty( $posts ) ) {
			echo '<p class="jt-bp-empty">' . esc_html__( 'No topics yet. Start a discussion!', 'jetonomy' ) . '</p>';
		} else {
			echo '<table class="jt-bp-topics"><thead><tr>';
			echo '<th>' . esc_html__( 'Topic', 'jetonomy' ) . '</th>';
			echo '<th>' . esc_html__( 'Replies', 'jetonomy' ) . '</th>';
			echo '<th>' . esc_html__( 'Last Activity', 'jetonomy' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $posts as $post ) {
				$post_url = $base . '/s/' . $space->slug . '/t/' . $post->slug . '/';
				$author   = get_userdata( (int) $post->author_id );
				$time_ago = human_time_diff( strtotime( $post->last_reply_at ?? $post->created_at ), time() );
				echo '<tr>';
				echo '<td><a href="' . esc_url( $post_url ) . '">' . esc_html( $post->title ) . '</a>';
				echo '<br><small>' . esc_html( $author ? $author->display_name : __( 'Anonymous', 'jetonomy' ) ) . '</small></td>';
				echo '<td>' . (int) $post->reply_count . '</td>';
				// translators: %s: human-readable time difference.
				echo '<td>' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
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
	 * Register a "Forum" nav item on BP member profiles.
	 */
	public function register_profile_forum_tab(): void {
		if ( ! bp_is_active( 'groups' ) ) {
			return;
		}

		bp_core_new_nav_item(
			array(
				'name'                    => __( 'Forum', 'jetonomy' ),
				'slug'                    => 'forum',
				'position'                => 80,
				'screen_function'         => array( $this, 'profile_forum_screen' ),
				'show_for_displayed_user' => true,
				'default_subnav_slug'     => '',
			)
		);
	}

	/**
	 * Screen callback for the member profile Forum tab.
	 */
	public function profile_forum_screen(): void {
		add_action( 'bp_template_content', array( $this, 'profile_forum_content' ) );
		bp_core_load_template( 'members/single/plugins' );
	}

	/**
	 * Render forum summary on a BP member profile.
	 */
	public function profile_forum_content(): void {
		$displayed_user_id = bp_displayed_user_id();
		if ( ! $displayed_user_id ) {
			return;
		}

		$profile = UserProfile::find_by_user( $displayed_user_id );
		$base    = \Jetonomy\base_url();
		$user    = get_userdata( $displayed_user_id );
		$jt_url  = $user ? $base . '/u/' . $user->user_login . '/' : $base;

		$post_count  = $profile ? (int) $profile->post_count : 0;
		$reply_count = $profile ? (int) $profile->reply_count : 0;
		$reputation  = $profile ? (int) $profile->reputation : 0;
		$trust_level = $profile ? (int) $profile->trust_level : 0;

		echo '<div class="jt-bp-profile">';

		// Stats bar.
		echo '<div class="jt-bp-stats">';
		echo '<div class="jt-bp-stat"><strong>' . (int) $post_count . '</strong> ' . esc_html__( 'Topics', 'jetonomy' ) . '</div>';
		echo '<div class="jt-bp-stat"><strong>' . (int) $reply_count . '</strong> ' . esc_html__( 'Replies', 'jetonomy' ) . '</div>';
		echo '<div class="jt-bp-stat"><strong>' . (int) $reputation . '</strong> ' . esc_html__( 'Reputation', 'jetonomy' ) . '</div>';
		echo '<div class="jt-bp-stat"><strong>' . esc_html__( 'Level', 'jetonomy' ) . ' ' . (int) $trust_level . '</strong> ' . esc_html__( 'Trust', 'jetonomy' ) . '</div>';
		echo '</div>';

		// Recent topics.
		$recent_posts = Post::list_by_author( $displayed_user_id, 5 );

		if ( ! empty( $recent_posts ) ) {
			echo '<h4>' . esc_html__( 'Recent Topics', 'jetonomy' ) . '</h4>';
			echo '<ul class="jt-bp-recent">';
			foreach ( $recent_posts as $post ) {
				$space    = Space::find( (int) $post->space_id );
				$post_url = $base . '/s/' . ( $space ? $space->slug : '' ) . '/t/' . $post->slug . '/';
				$time_ago = human_time_diff( strtotime( $post->created_at ), time() );
				echo '<li>';
				echo '<a href="' . esc_url( $post_url ) . '">' . esc_html( $post->title ) . '</a>';
				// translators: %s: human-readable time difference.
				echo ' <span class="jt-bp-time">' . esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) ) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'No forum topics yet.', 'jetonomy' ) . '</p>';
		}

		echo '<p><a href="' . esc_url( $jt_url ) . '" class="button">' . esc_html__( 'View Full Forum Profile', 'jetonomy' ) . ' &rarr;</a></p>';
		echo '</div>';
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
