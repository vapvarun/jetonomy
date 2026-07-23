<?php
/**
 * Permission engine.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Cache;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\Post;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\UserProfile;

/**
 * Three-layer permission resolver.
 *
 * Layer 0 — Global ban check (via Restriction model).
 * Layer 1 — WordPress capability check (jetonomy_{action}).
 * Layer 2 — Space visibility + space-role permission check.
 *
 * WP admins (manage_options) bypass layers 1 and 2.
 */
class Permission_Engine {

	/**
	 * Seconds a can() verdict is cached per (user, action, space). A permission
	 * or role change is reflected within this window — the accepted hot-read
	 * trade-off (Caching Standard §4b lists can() as a sanctioned short-TTL cache),
	 * so the key is not busted on role change; it ages out.
	 */
	private const PERM_TTL = 60;

	/**
	 * Moderation actions a space-level admin / moderator can perform on
	 * content in their space, regardless of the WordPress capability map.
	 * Used by the Layer 0d short-circuit so subscribers promoted to the
	 * mod role from the front-end members page actually get the tools.
	 */
	private const SPACE_MOD_ACTIONS = array(
		'moderate',
		'edit_others_posts',
		'edit_others_replies',
		'delete_others_posts',
		'delete_others_replies',
		'close_posts',
		'pin_posts',
		'move_posts',
		'merge_posts',
		'split_replies',
	);

	/**
	 * Actions permitted per space role.
	 *
	 * Each higher role includes all actions of the roles below it.
	 */
	private const SPACE_ROLE_PERMS = array(
		'viewer'    => array( 'read' ),
		'member'    => array( 'read', 'create_posts', 'create_replies', 'vote', 'flag' ),
		'moderator' => array(
			'read',
			'create_posts',
			'create_replies',
			'vote',
			'flag',
			'edit_others_posts',
			'delete_others_posts',
			'close_posts',
			'pin_posts',
			'move_posts',
		),
		'admin'     => array(
			'read',
			'create_posts',
			'create_replies',
			'vote',
			'flag',
			'edit_others_posts',
			'delete_others_posts',
			'close_posts',
			'pin_posts',
			'move_posts',
			'manage_spaces',
		),
	);

	/**
	 * Determine whether a user is allowed to perform an action.
	 *
	 * Results are cached for 60 seconds per user/action/space combination.
	 *
	 * @param int      $user_id  WP user ID to check.
	 * @param string   $action   Action name (without 'jetonomy_' prefix for WP cap check).
	 * @param int|null $space_id Optional space context.
	 * @return bool
	 */
	public static function can( int $user_id, string $action, ?int $space_id = null ): bool {
		$cache_key = "perm:{$user_id}:{$action}:" . ( $space_id ?? 0 );
		$cached    = Cache::get( $cache_key );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$result = self::resolve( $user_id, $action, $space_id );
		// Store as 1/0 so we can distinguish a cached false from a cache miss.
		Cache::set( $cache_key, $result ? 1 : 0, self::PERM_TTL );
		return $result;
	}

	/**
	 * Internal uncached resolution — called by can().
	 */
	private static function resolve( int $user_id, string $action, ?int $space_id ): bool {
		// Layer 0: IP ban check.
		$ip = \Jetonomy\client_ip();
		if ( $ip && Restriction::is_ip_banned( $ip ) ) {
			return false;
		}

		// Layer 0: Global ban.
		if ( $user_id && Restriction::is_banned( $user_id ) ) {
			return false;
		}

		// Layer 0b: Silence check — can read but not write.
		if ( $user_id && class_exists( 'Jetonomy\Models\Restriction' ) && Restriction::is_silenced( $user_id ) ) {
			$write_actions = array( 'create_posts', 'create_replies', 'vote', 'flag', 'create_spaces', 'edit_others_posts', 'delete_others_posts', 'close_posts', 'pin_posts', 'move_posts' );
			if ( in_array( $action, $write_actions, true ) ) {
				return false;
			}
			// Allow read actions to continue through the normal flow.
		}

		// Layer 0c: Space-level ban.
		if ( $user_id && $space_id && Restriction::is_space_banned( $user_id, $space_id ) ) {
			return false;
		}

		// WP admin bypass — skip all further checks.
		if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Layer 0d: Space-level mod role bypass for moderation actions.
		// Without this, a Subscriber promoted to space admin / moderator hits
		// Layer 1's `user_can( jetonomy_moderate )` check, fails, and is
		// denied — the space-level role never gets a chance to grant access.
		// Customer impact: the space owner promotes a member to moderator
		// from the front-end members page, the inline edit / pin / move /
		// merge / delete tools never appear on that user's screen.
		if ( $user_id && $space_id && in_array( $action, self::SPACE_MOD_ACTIONS, true ) ) {
			$mod_role = SpaceMember::get_role( $space_id, $user_id );
			if ( in_array( $mod_role, array( 'admin', 'moderator' ), true ) ) {
				return true;
			}
		}

		// Layer 1: WordPress capability.
		// Guest users (user_id=0) skip the WP cap check for 'read' actions;
		// public space visibility is evaluated in Layer 2 instead.
		if ( $user_id && ! user_can( $user_id, 'jetonomy_' . $action ) ) {
			return false;
		}

		// Guests may only read — reject any non-read action immediately.
		if ( ! $user_id && 'read' !== $action ) {
			return false;
		}

		// No space context — WP cap is sufficient (logged-in), or deny guests.
		if ( null === $space_id ) {
			return (bool) $user_id;
		}

		// Layer 2: Space visibility + membership.
		$space = Space::find( $space_id );
		if ( ! $space ) {
			return false;
		}

		// Private / hidden spaces require membership.
		if ( in_array( $space->visibility, array( 'private', 'hidden' ), true ) ) {
			if ( ! SpaceMember::is_member( $space_id, $user_id ) ) {
				return false;
			}
		}

		// Check access rules (membership, capability, trust level rules).
		$access = AccessRule::resolve_access( $user_id, $space_id );
		if ( $access ) {
			// Access rule grants access — check if sufficient for the action.
			$grants_map      = array(
				'read'        => array( 'read' ),
				'participate' => array( 'read', 'create_posts', 'create_replies', 'vote', 'flag' ),
				'full'        => array( 'read', 'create_posts', 'create_replies', 'vote', 'flag', 'edit_others_posts', 'close_posts', 'pin_posts' ),
			);
			$allowed_actions = $grants_map[ $access['grants'] ] ?? array( 'read' );
			if ( in_array( $action, $allowed_actions, true ) ) {
				return true;
			}
		}

		// Resolve space role (needed for restriction checks below).
		$role = SpaceMember::get_role( $space_id, $user_id );

		// Layer 4: Per-space settings (who_can_post, who_can_reply, allow_voting).
		// Checked BEFORE the public+open shortcut so restrictions are enforced.
		$space_settings = Space::get_settings( $space_id );

		if ( 'create_posts' === $action && ! empty( $space_settings['who_can_post'] ) ) {
			$check_role = $role ?: 'viewer';
			if ( ! self::role_meets_restriction( $check_role, $space_settings['who_can_post'] ) ) {
				return false;
			}
		}

		if ( 'create_replies' === $action && ! empty( $space_settings['who_can_reply'] ) ) {
			$check_role = $role ?: 'viewer';
			if ( ! self::role_meets_restriction( $check_role, $space_settings['who_can_reply'] ) ) {
				return false;
			}
		}

		if ( 'vote' === $action && isset( $space_settings['allow_voting'] ) && '1' !== (string) $space_settings['allow_voting'] ) {
			return false;
		}

		// Public space — no membership required for read.
		// Public + open join_policy — logged-in users may also participate.
		if ( 'public' === $space->visibility ) {
			if ( 'read' === $action ) {
				return true;
			}
			$is_open = 'open' === ( $space->join_policy ?? 'open' );
			if ( $is_open && $user_id ) {
				$open_actions = array( 'create_posts', 'create_replies', 'vote', 'flag' );
				if ( in_array( $action, $open_actions, true ) ) {
					return true;
				}
			}
		}

		if ( ! $role ) {
			// Non-member — only read is allowed (private/hidden already blocked above).
			return 'read' === $action;
		}

		// Layer 3: Trust level gates.
		$profile     = UserProfile::find_by_user( $user_id );
		$trust_level = $profile ? (int) $profile->trust_level : 0;

		$trust_requirements = array(
			'edit_others_posts' => 3,
			'move_posts'        => 3,
			'close_posts'       => 3,
			'pin_posts'         => 3,
			'create_spaces'     => 4,
		);

		if ( isset( $trust_requirements[ $action ] ) ) {
			if ( $trust_level >= $trust_requirements[ $action ] ) {
				// Trust level met — grant the action regardless of space role.
				return true;
			}
			// Trust level not met — only space moderators/admins bypass.
			if ( ! in_array( $role, array( 'moderator', 'admin' ), true ) ) {
				return false;
			}
		}

		/**
		 * Filter the permissions granted to a space role.
		 *
		 * @param array  $permissions Default permissions for the role.
		 * @param string $role        Space role (viewer, member, moderator, admin).
		 * @param int    $space_id    Space ID.
		 */
		$role_perms = apply_filters( 'jetonomy_space_role_permissions', self::SPACE_ROLE_PERMS[ $role ] ?? array(), $role, $space_id );

		return in_array( $action, $role_perms, true );
	}

	/**
	 * May this post's AUTHORED TEXT be emitted to this viewer out-of-band?
	 *
	 * can_read_post() plus the blocked-author check, for the surfaces that
	 * broadcast a post's title/body somewhere the viewer cannot be shown a
	 * tombstone: the <head> (title / og:* / meta description), JSON-LD, and the
	 * oEmbed unfurl. Those three asked can_read_post() — which knows about
	 * visibility and status but not blocks — so a blocked author's title and
	 * (via meta description) their entire body still reached the very person who
	 * blocked them, beside a body that correctly read "Content hidden — you
	 * blocked this user" (1.8.0).
	 *
	 * DELIBERATELY NOT FOLDED INTO can_read_post(). The two questions differ in
	 * their negative outcome, and conflating them regresses the tombstone
	 * shipped earlier in 1.8.0:
	 *
	 *   can_read_post() === false  -> 404, the row is not yours to reach.
	 *   blocked author            -> the row IS yours to reach; we owe you a
	 *                                "you blocked this user" state and an
	 *                                Unblock affordance, and we owe the innocent
	 *                                repliers on that topic their replies.
	 *
	 * Teaching can_read_post() about blocks would 404 single-post.php, collapse
	 * GET /posts/{id}'s blocked_author payload, and orphan every innocent reply
	 * under a blocked author's topic via the replies-controller parent gate —
	 * breaking three surfaces to fix three. Surfaces that render a viewer-facing
	 * state keep can_read_post() + Post::apply_block_tombstone(). Surfaces that
	 * emit text with no room for a state call this.
	 *
	 * DIRECTION IS NOT DECIDED HERE. It comes from BlockedUser::blocked_ids(),
	 * the one primitive every read surface already shares, so whichever way that
	 * set is defined this method agrees with the tombstone beside it by
	 * construction rather than by two places remembering to match.
	 *
	 * NO-VIEWER DEFAULT IS "EMIT", explicitly: blocked_ids( 0 ) is [] because a
	 * guest has blocked nobody. A crawler, cron run, or warm cache therefore
	 * sees byte-identical output to today — blocking must never deindex a public
	 * topic. Cost for that path is zero queries; for a member it is one, memoized
	 * per request and cached for 5 minutes by blocked_ids().
	 *
	 * @since 1.8.0
	 * @param int    $user_id WP user ID (0 for guest).
	 * @param object $post    Post row object.
	 * @return bool
	 */
	public static function can_render_post_text( int $user_id, object $post ): bool {
		if ( ! self::can_read_post( $user_id, $post ) ) {
			return false;
		}

		if ( $user_id <= 0 ) {
			return true;
		}

		return ! in_array(
			(int) ( $post->author_id ?? 0 ),
			\Jetonomy\Models\BlockedUser::blocked_ids( $user_id ),
			true
		);
	}

	/**
	 * Check if a user can read a specific post, considering status and private visibility.
	 *
	 * @param int       $user_id WP user ID (0 for guest).
	 * @param \stdClass $post    Post row (must have status, is_private, author_id, space_id).
	 * @return bool
	 */
	public static function can_read_post( int $user_id, object $post ): bool {
		$space_id = (int) $post->space_id;

		// Space-level check first.
		if ( ! self::can( $user_id, 'read', $space_id ) ) {
			return false;
		}

		// Status gate (1.8.0). Deleting a post is a SOFT delete — the REST
		// DELETE route and the admin/moderation handlers all set status='trash'
		// and the row stays in the table — so without this, "deleted" content
		// stayed fully readable to anyone who knew the id, as did every post
		// sitting in the moderation queue.
		//
		// The rule is not new: single-post.php has enforced exactly this since
		// before 1.4.0. It just lived inline in one template, so the REST route,
		// oEmbed, JSON-LD, the updates poller and four Pro extensions — all of
		// which already call this method for the private-post gate below — never
		// received it. Hoisted here so there is one status gate, not one per
		// surface that remembers to ask.
		//
		// Author-visible on purpose, matching that established contract: a
		// pending post is not gone (its author is waiting on moderation and must
		// still be able to open it), and an author who deletes their own topic
		// keeps the link working rather than 404-ing on their own words. Anyone
		// who is neither the author nor a moderator gets a flat false, guests
		// included.
		if ( 'publish' !== (string) ( $post->status ?? 'publish' ) ) {
			if ( ! $user_id ) {
				return false;
			}
			$is_author = (int) $post->author_id === $user_id;
			if ( ! $is_author && ! self::can( $user_id, 'moderate', $space_id ) ) {
				return false;
			}
		}

		// Public posts are readable by anyone with space access.
		if ( empty( $post->is_private ) ) {
			return true;
		}

		// Private post: author always sees it.
		if ( $user_id > 0 && (int) $post->author_id === $user_id ) {
			return true;
		}

		// WP admin bypass.
		if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Space moderators/admins can see all private posts.
		return self::is_space_privileged( $user_id, $space_id );
	}

	/**
	 * May this viewer read a PRIVATE reply's text? (1.8.1, Basecamp 9804279999)
	 *
	 * Companion to {@see self::can_read_post()}'s private-post branch, with one
	 * addition: the PARENT TOPIC's author always sees private replies on their
	 * own thread — it is their conversation, and "share the sensitive detail
	 * privately with the person who asked" is the entire use case.
	 *
	 * Callers pass the parent post so no surface re-fetches it per reply. A
	 * non-private reply is always readable (the thread's space/status gating
	 * happened at the post level before any reply rendered).
	 *
	 * The read surfaces TOMBSTONE on false (Reply::apply_private_tombstone) —
	 * they never row-filter — so reply counts and reply_permalink() page
	 * math stay identical for every viewer, same as the blocked-author rule.
	 *
	 * @param int       $user_id Viewer ID (0 for guests).
	 * @param \stdClass $reply   Reply row (is_private, author_id).
	 * @param \stdClass $post    Parent post row (author_id, space_id).
	 * @return bool
	 */
	public static function can_read_reply( int $user_id, object $reply, object $post ): bool {
		if ( empty( $reply->is_private ) ) {
			return true;
		}
		if ( ! $user_id ) {
			return false;
		}
		if ( (int) $reply->author_id === $user_id ) {
			return true;
		}
		if ( (int) $post->author_id === $user_id ) {
			return true;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return self::is_space_privileged( $user_id, (int) $post->space_id );
	}

	/**
	 * Check if a user has moderator or admin role in a space.
	 *
	 * @param int $user_id  WP user ID.
	 * @param int $space_id Space ID.
	 * @return bool
	 */
	public static function is_space_privileged( int $user_id, int $space_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$role = SpaceMember::get_role( $space_id, $user_id );
		return in_array( $role, array( 'moderator', 'admin' ), true );
	}

	/**
	 * Check if a user is a space admin (role='admin' on this space) or WP admin.
	 *
	 * Narrower than is_space_privileged — mods do not qualify. Gates actions
	 * that manage the space itself (role changes, settings, invite links).
	 *
	 * @param int $user_id  WP user ID.
	 * @param int $space_id Space ID.
	 * @return bool
	 */
	public static function is_space_admin( int $user_id, int $space_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return 'admin' === SpaceMember::get_role( $space_id, $user_id );
	}

	/**
	 * Check if a space role meets a restriction level.
	 *
	 * @param string $role        User's space role (viewer/member/moderator/admin).
	 * @param string $restriction Required level (members/moderators/admins).
	 * @return bool
	 */
	private static function role_meets_restriction( string $role, string $restriction ): bool {
		$hierarchy = array(
			'viewer'    => 0,
			'member'    => 1,
			'moderator' => 2,
			'admin'     => 3,
		);
		$required  = array(
			'members'    => 1,
			'moderators' => 2,
			'admins'     => 3,
		);

		$user_level = $hierarchy[ $role ] ?? 0;
		$req_level  = $required[ $restriction ] ?? 1;

		return $user_level >= $req_level;
	}
}
