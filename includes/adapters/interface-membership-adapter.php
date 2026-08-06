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

	/**
	 * Every level this adapter can gate on.
	 *
	 * Required keys per row: `id` and `label`.
	 *
	 * Two OPTIONAL keys let the access-rule picker explain a choice instead of
	 * making the owner guess. They are additive: an adapter returning only
	 * `id` + `label` keeps working exactly as before, ungrouped and un-noted,
	 * with no notice and no deprecation. There are fourteen in-tree adapters
	 * plus any a site owner or third party has written against this interface,
	 * and none of them may break on upgrade.
	 *
	 *   'kind' - what this row IS, e.g. 'Course', 'Cohort', 'Plan'. The picker
	 *            groups rows under it. Set this and DROP any "(Vendor Course)"
	 *            suffix you were baking into `label`, or the picker shows the
	 *            kind twice - once as the group heading and once in the label.
	 *   'note' - one sentence on who the row matches, written as a
	 *            consequence rather than a definition, and saying when access
	 *            ENDS where that is knowable. "Everyone enrolled in this
	 *            course, for as long as they stay enrolled" beats "a course
	 *            object". Rendered once per group, so every row sharing a
	 *            `kind` should carry the same `note`.
	 *
	 * A grant is ADMISSION, never a cap: a rule lets people in, and only
	 * visibility and join policy hold anyone back. Do not write a note that
	 * promises a restriction - on a public space that anyone may join, a
	 * signed-in member can take part whatever the rule says.
	 *
	 * @return array<int, array{id: string, label: string, kind?: string, note?: string}>
	 */
	public function get_all_levels(): array;
	public function get_level_label( string $level_id ): string;
	public function register_hooks(): void;
}
