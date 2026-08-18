<?php
/**
 * Minimal BuddyPress stubs for static analysis.
 *
 * Jetonomy's BuddyPress integration calls 17 BP functions and touches 2 BP
 * classes. PHPStan needs to know those symbols exist; it does NOT need the
 * plugin.
 *
 * phpstan.neon.dist used to point `scanDirectories` at a BuddyPress checkout
 * via a /tmp symlink. That had two problems, and this file fixes both:
 *
 * 1. It made PHPStan parse 687 files / 14 MB purely for symbol discovery,
 *    which exhausted memory in a parallel worker. The full-tree run crashed
 *    even at --memory-limit=1G and reported "Result is incomplete", so the
 *    gate CLAUDE.md describes as enforced was not actually analysing anything
 *    (Basecamp 10212939583).
 * 2. The path was /tmp/bp-stubs/buddypress - a symlink into another Local
 *    site, recreated by hand. It does not survive a reboot and does not exist
 *    on anyone else's machine or in CI, so the config only worked here.
 *
 * Signatures are copied from BuddyPress 15.x. Bodies are deliberately empty:
 * PHPStan reads declarations, never executes them. This file is NEVER loaded
 * at runtime and is excluded from the release zip.
 *
 * If the integration starts calling another BP function, add it here rather
 * than pointing the config back at a real checkout.
 *
 * @package Jetonomy
 */

// phpcs:ignoreFile -- Stub declarations for static analysis only.

/**
 * @param int  $user_id   User ID.
 * @param bool $no_anchor Whether to return just the name.
 * @param bool $just_link Whether to return just the URL.
 * @return string
 */
function bp_core_get_userlink( $user_id, $no_anchor = false, $just_link = false ) {}

/**
 * @param string|array $templates Template(s) to load.
 * @return void
 */
function bp_core_load_template( $templates ) {}

/**
 * @param array  $args      Nav item args.
 * @param string $component Component the item belongs to.
 * @return bool
 */
function bp_core_new_nav_item( $args, $component = 'members' ) {}

/**
 * @param array       $args      Subnav item args.
 * @param string|null $component Component the item belongs to.
 * @return bool|null
 */
function bp_core_new_subnav_item( $args, $component = null ) {}

/**
 * @return int
 */
function bp_displayed_user_id() {}

/**
 * @return int
 */
function bp_get_current_group_id() {}

/**
 * @return string
 */
function bp_get_current_group_slug() {}

/**
 * @param string $component Component slug.
 * @param string $feature   Optional feature within the component.
 * @return bool
 */
function bp_is_active( $component = '', $feature = '' ) {}

/**
 * @return bool
 */
function bp_is_group() {}

/**
 * @return bool
 */
function bp_is_my_profile() {}

/**
 * @return object
 */
function buddypress() {}

/**
 * @return bool
 */
function is_buddypress() {}

/**
 * @param int    $group_id   Group ID.
 * @param string|false $meta_key   Meta key.
 * @param string|false $meta_value Meta value.
 * @param bool   $delete_all Whether to delete across all groups.
 * @return bool
 */
function groups_delete_groupmeta( $group_id, $meta_key = false, $meta_value = false, $delete_all = false ) {}

/**
 * @return BP_Groups_Group|null
 */
function groups_get_current_group() {}

/**
 * @param int $group_id Group ID.
 * @return BP_Groups_Group
 */
function groups_get_group( $group_id ) {}

/**
 * @param int    $group_id Group ID.
 * @param string $meta_key Meta key.
 * @param bool   $single   Whether to return a single value.
 * @return mixed
 */
function groups_get_groupmeta( $group_id, $meta_key = '', $single = true ) {}

/**
 * @param int    $group_id   Group ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 * @param mixed  $prev_value Previous value to match.
 * @return bool
 */
function groups_update_groupmeta( $group_id, $meta_key, $meta_value, $prev_value = '' ) {}

/**
 * BuddyPress group object.
 */
class BP_Groups_Group {

	/** @var int */
	public $id;

	/** @var string */
	public $name;

	/** @var string */
	public $slug;

	/** @var string */
	public $description;

	/** @var string */
	public $status;

	/** @var int */
	public $creator_id;

	/** @var string */
	public $date_created;

	/**
	 * @param int|null $id Group ID.
	 */
	public function __construct( $id = null ) {}

	/**
	 * @param array $args Query args.
	 * @return array
	 */
	public static function get( $args = array() ) {}
}

/**
 * BuddyPress activity object.
 */
class BP_Activity_Activity {

	/** @var int */
	public $id;

	/** @var int */
	public $user_id;

	/** @var string */
	public $component;

	/** @var string */
	public $type;

	/** @var string */
	public $action;

	/** @var string */
	public $content;

	/** @var string */
	public $primary_link;

	/** @var int */
	public $item_id;

	/** @var int */
	public $secondary_item_id;

	/** @var string */
	public $date_recorded;

	/** @var int */
	public $hide_sitewide;

	/**
	 * @param int|null $id Activity ID.
	 */
	public function __construct( $id = null ) {}

	/**
	 * @return bool
	 */
	public function save() {}

	/**
	 * @param array $args Query args.
	 * @return array
	 */
	public static function get( $args = array() ) {}
}
