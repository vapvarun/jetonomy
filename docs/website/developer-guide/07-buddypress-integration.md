Developer reference for the BuddyPress coexistence integration. This page is for plugin/theme developers extending or debugging the integration. End users should start with the [BuddyPress integration guide](../integrations/13-buddypress.md).

## What You Will Learn

- Where the integration lives and when it loads
- What state persists (group meta + options) and where
- Which BuddyPress and Jetonomy hooks the integration consumes
- How the activity broadcast and comment-to-reply bridge work, and how to extend them
- How loop protection, identity keying, and stale-pair handling are implemented

## File Layout

The integration lives in a single class:

```
includes/integrations/class-buddypress.php
```

Loaded from `includes/class-jetonomy.php` only when the BuddyPress Groups component is active:

```php
if ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) ) {
    require_once JETONOMY_DIR . 'includes/integrations/class-buddypress.php';
    new Integrations\BuddyPress();
}
```

The broadcast and comment-bridge methods additionally gate themselves on `bp_is_active( 'activity' )` at runtime, so a BP install with Groups but not Activity stays fatal-free.

## Persisted State

| Where | Key | Type | Purpose |
|-------|-----|------|---------|
| BP group meta | `jetonomy_space_id` | int | Points a group at its paired Jetonomy space. One value per group. |
| WP option | `jetonomy_bp_broadcast` | `'1'` / `'0'` | Toggle for JT topic → BP group activity broadcast. Defaults on. |
| WP option | `jetonomy_bp_comment_bridge` | `'1'` / `'0'` | Toggle for BP activity comment → JT reply bridge. Defaults on. |
| BP activity meta | `jetonomy_post_id` | int | Tags a broadcast activity row with its originating Jetonomy post ID. The comment bridge reads this to decide which activity comments should round-trip as JT replies. |

### Class constants

```php
BuddyPress::META_KEY              = 'jetonomy_space_id';
BuddyPress::OPT_BROADCAST         = 'jetonomy_bp_broadcast';
BuddyPress::OPT_COMMENT_BRIDGE    = 'jetonomy_bp_comment_bridge';
BuddyPress::ACTIVITY_META_POST    = 'jetonomy_post_id';
BuddyPress::ACTIVITY_TYPE         = 'jetonomy_topic';
```

### Reading the pair

```php
$space_id = (int) groups_get_groupmeta( $group_id, \Jetonomy\Integrations\BuddyPress::META_KEY, true );
```

### Reverse lookup

```php
$group_id = \Jetonomy\Integrations\BuddyPress::find_group_by_space( $space_id );
```

This runs a single meta-keyed query, no `get_posts()` loop.

## BuddyPress Hooks Consumed

### Group lifecycle

| Hook | Handler | Note |
|------|---------|------|
| `groups_created_group` | `on_group_created` + `save_group_forum_settings_on_create` | Reads the `jt_bp_forum_action` form field ('create', 'link_{id}', 'none'). Only creates a new space when explicitly requested. |
| `groups_delete_group` | `on_group_deleted` | Unlinks the space. Space itself is preserved. |
| `groups_details_updated` | `on_group_updated` | Syncs name, description, and visibility (public/private/hidden) to the paired space. |

### Member sync

| Hook | Handler | Direction |
|------|---------|-----------|
| `groups_join_group` | `on_member_join` | BP → JT |
| `groups_leave_group` | `on_member_leave` | BP → JT |
| `groups_remove_member` | `on_member_leave` | BP → JT |
| `groups_ban_member` | `on_member_leave` | BP → JT |
| `groups_unban_member` | `on_member_join` | BP → JT |
| `groups_promote_member` | `on_member_promote` | BP → JT (admin/mod) |
| `groups_demote_member` | `on_member_demote` | BP → JT (back to member) |

### Activity

| Hook | Handler | Note |
|------|---------|------|
| `bp_register_activity_actions` | `register_activity_type` | Registers the `jetonomy_topic` activity type with `bp_activity_set_action` so BP renders it alongside native types. |
| `bp_activity_comment_posted` | `on_bp_activity_comment_posted` | Runs the comment-to-reply bridge when the parent activity carries the broadcast meta marker. |
| `bp_activity_allowed_tags` | `filter_broadcast_allowed_tags` | Adds `<br>` and `<p>` to BP's kses allowlist so broadcast paragraphs survive save AND display. Both tags carry no attributes, so no XSS surface. |

### Render

| Hook | Handler |
|------|---------|
| `bp_setup_nav` (priority 20) | `register_group_forum_tab` + `register_profile_forum_tab` |
| `groups_custom_group_fields_editable` | `render_group_forum_settings` (the dropdown in Create + Manage > Details) |
| `groups_group_details_edited` | `save_group_forum_settings` |
| `bp_after_group_details_creation_step` | `render_group_forum_settings` (creation step) |

## Jetonomy Hooks Consumed

| Hook | Handler | Surface |
|------|---------|---------|
| `jetonomy_before_content` | `render_back_to_group_banner` | Renders the "← Group Name" link at the top of paired space / topic pages. |
| `jetonomy_sidebar_about_after_meta` | `render_sidebar_group_link` | Renders the small tag in the sidebar About card linking back to the BP group. |
| `jetonomy_user_joined_space` | not directly hooked; member sync is BP → JT only (BP is the source of truth for group membership). | n/a |
| `jetonomy_after_create_post` | `on_jt_post_created_for_bp` | Triggers the broadcast to the paired BP group activity stream. |

## Activity Broadcast Flow

On `jetonomy_after_create_post`:

1. If broadcast is disabled or no pair exists for the space, return.
2. If the post is private (`is_private`), return.
3. If the BP Activity component is not active, return.
4. Build the activity body: excerpt converted to `<p>` paragraphs with block-level tag boundaries preserved, plus a trailing "Shared from the forum · View discussion" attribution line.
5. Call `bp_activity_add` with `component=groups`, `type=jetonomy_topic`, `item_id=$group_id`, `secondary_item_id=$post_id`, and `hide_sitewide` set when the group is not public.
6. Store the post ID in activity meta: `bp_activity_update_meta( $activity_id, 'jetonomy_post_id', $post_id )`.

The `bp_activity_allowed_tags` filter that whitelists `<br>` and `<p>` is attached globally while broadcast is enabled. BP runs kses both on save and on display, so a per-call toggle would strip the tags when the activity is rendered later.

## Comment-to-Reply Bridge Flow

On `bp_activity_comment_posted( $comment_id, $r, $activity )`:

1. If the loop-guard flag is set, return. Prevents boomerang writes.
2. If `bp_activity_get_meta( $activity->id, 'jetonomy_post_id' )` is empty, the parent activity is not one of ours, return.
3. Load the Jetonomy post; if it is not published, return (the broadcast survives, but we do not create replies against draft/trashed topics).
4. Build the reply content: `wp_kses_post` on the comment HTML for display, `wp_strip_all_tags` for the plain version.
5. Create the reply via `Reply::create` with the same author as the BP commenter.

Edits and deletes on BP do NOT propagate. The JT thread is the durable record.

## Loop Protection

A shared static `$syncing` flag stops a write on one side from triggering a boomerang write back. Every member-sync, broadcast, and bridge method flips it for the duration of the write:

```php
self::$syncing = true;
// do the write that might fire hooks we listen to
self::$syncing = false;
```

Both the group-lifecycle handlers (`on_group_created`, `on_group_updated`) and the member-sync handlers read `self::$syncing` at entry.

## Identity Keying

Everything joins on `user_id`. BP member profiles and Jetonomy user profiles share the same WP user ID, so username divergence is not a problem.

## Stale Pair Handling

Every render hook resolves the paired entity lazily. If the paired space no longer exists when the forum tab is about to render, the tab callback returns early without emitting markup. The same pattern applies to the sidebar link and back-banner.

## Extending

Three clean extension points:

- **Disable member-leave propagation.** Remove the `groups_leave_group`, `groups_remove_member`, and `groups_ban_member` actions from the integration at `init + 30` or later if you want the add-only semantics the FluentCommunity integration uses.
- **Custom activity rendering.** Filter `bp_activity_action_before_save` or add a filter on `bp_get_activity_action` to override how `jetonomy_topic` rows render without touching the integration.
- **Custom permission gate on forum tab.** Filter `bp_is_user_in_group` (or call `groups_is_user_member` directly) inside your own hook handler on `register_group_forum_tab` (priority < 20) to restrict the Forum tab to certain roles.

Destructive or privacy-affecting extensions (forcing role sync one-way, propagating flags cross-surface, cascading deletes) belong in a Pro extension with explicit per-pair toggles, not as drop-in replacements for the free integration.
