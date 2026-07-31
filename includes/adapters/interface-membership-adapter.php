<?php
/**
 * Membership adapter interface.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * What Jetonomy needs from a membership/LMS plugin to gate a space on it.
 *
 * OPTIONAL, deliberately not in the signature list:
 *
 *     public function get_level_url( string $level_id ): string;
 *
 * Where somebody buys or joins this level - the destination of the button on a
 * gated space's "this needs the VIP tier" screen. Adding it to the interface
 * would fatal every third-party adapter already implementing this one, so
 * `Adapter_Registry::membership_level_url()` resolves it through
 * `method_exists()` instead, and any adapter may adopt it at any time.
 *
 * Implement it when the level is something a visitor can go and GET: a plan,
 * a course, a subscription tier. Return '' when it is not, or when the URL
 * cannot be resolved with certainty - the gate then states the requirement
 * without a button, which is the correct outcome. A button to the wrong
 * checkout is worse than no button.
 *
 * Adapters that intentionally return nothing here, because their level is
 * granted rather than sold: WP Roles, WP Fusion tags, SureMembers access
 * groups, and the tag-membership bridge. Site owners direct people to those
 * however their funnel works, via `jetonomy_membership_upgrade_url`.
 */
interface Membership_Adapter {
	public function is_active(): bool;
	public function get_user_levels( int $user_id ): array;
	public function user_has_level( int $user_id, string $level_id ): bool;
	public function get_all_levels(): array;
	public function get_level_label( string $level_id ): string;
	public function register_hooks(): void;
}
