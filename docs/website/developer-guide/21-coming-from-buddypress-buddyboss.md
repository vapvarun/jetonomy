A reference for developers who know BuddyPress or BuddyBoss and want to understand how Jetonomy maps to those concepts. This page covers concept-level differences, API equivalents for common tasks, and an honest account of where no equivalent exists.

If you want to run Jetonomy alongside an active BuddyPress install, start with the [BuddyPress Integration](./07-buddypress-integration.md) developer reference, which covers the coexistence layer (group-to-space pairing, member sync, activity broadcast, and the comment-to-reply bridge).

---

## Concept Map

| BuddyPress / BuddyBoss | Jetonomy | Notes |
|------------------------|----------|-------|
| **Group** | **Space** | The primary container for discussion. BP groups are general-purpose social containers; Jetonomy spaces are forum/Q&A/ideas/feed containers with a configurable type. |
| **Group topic / reply** (bbPress) | **Post / Reply** | Jetonomy stores posts and replies in custom tables, not as WordPress CPTs. |
| **Activity stream** | *(no equivalent)* | Jetonomy has no site-wide activity stream. When BuddyPress is active, new posts broadcast to the paired group's activity stream via the integration layer. See [BuddyPress Integration](./07-buddypress-integration.md). |
| **Extended profile field** (xprofile) | **Pro Custom Fields** | Custom field definitions per object type (post, user, space) via the Jetonomy Pro custom-fields extension. |
| **Member type** (`bp_register_member_type`) | **Trust Levels + WP roles** | Jetonomy uses a 0-5 trust ladder (auto-evaluated from activity) plus standard WP roles. There is no named member-type registry. |
| **Group forum tab** (`BP_Group_Extension`) | **Space tab** (`jetonomy_space_tabs` filter) | See [Add a Space Tab](./13-add-a-space-tab.md). |
| **Profile tab / nav item** (`bp_core_new_nav_item`) | **Profile tab** (`jetonomy_profile_tabs` filter) | See [Add a Profile Tab](./12-add-a-profile-tab.md). |
| **Community nav link** | `jetonomy_header_nav_items` action | See [Add a Nav Item](./14-add-a-nav-item.md). |
| **Group visibility** (public / private / hidden) | **Space visibility** (public / private) | Jetonomy does not have a "hidden" equivalent. Private spaces are invisible to non-members; there is no invitation-only discovery mode. |
| **bp_user_can / bp_current_user_can** | `Jetonomy\Permissions\Permission_Engine::can()` | Per-action, per-space capability check. See [Visibility and Access Matrix](./08-visibility-and-access-matrix.md). |
| **Community public/private toggle** | `Jetonomy\Visibility::can_view_community()` | Community-level read gate (public = anyone can read, private = logged-in only). Separate from per-space access rules. |
| **Member directory + facet filters** | *(no equivalent)* | Jetonomy has `jetonomy_search_query_args` for content search but no member directory or faceted member browser. |
| **Cover images** | *(no equivalent)* | Jetonomy user profiles and spaces do not have cover images. |
| **Notification preference hooks** | *(no equivalent)* | Jetonomy's notification preference UI has no injection hook. The email adapter is swappable (see [Adapter System](./05-adapters.md)) but there is no per-type preference UI hook. |

---

## How Do I Do X?

### Profile and navigation

| BuddyPress / BuddyBoss | Jetonomy equivalent | Reference |
|------------------------|---------------------|-----------|
| `bp_core_new_nav_item()` — add a profile tab | `jetonomy_profile_tabs` filter | [12-add-a-profile-tab.md](./12-add-a-profile-tab.md) |
| `BP_Group_Extension` — add a space/group tab | `jetonomy_space_tabs` filter | [13-add-a-space-tab.md](./13-add-a-space-tab.md) |
| `bp_core_add_nav_item()` — add a community nav link | `jetonomy_header_nav_items` action | [14-add-a-nav-item.md](./14-add-a-nav-item.md) |
| `bp_core_get_user_domain( $user_id )` | `\Jetonomy\get_profile_url( $user_id )` | Filterable via `jetonomy_profile_url` |

### Membership and access

| BuddyPress / BuddyBoss | Jetonomy equivalent | Notes |
|------------------------|---------------------|-------|
| `groups_is_user_member( $user_id, $group_id )` | `\Jetonomy\Models\SpaceMember::is_member( $space_id, $user_id )` | Returns `bool` |
| `bp_user_can( $user_id, 'publish_posts', 'groups' )` | `\Jetonomy\Permissions\Permission_Engine::can( $user_id, 'create_posts', $space_id )` | Action strings: `create_posts`, `vote`, `flag`, `moderate`, `manage_space`, etc. |
| `groups_is_user_admin( $user_id, $group_id )` | `\Jetonomy\Models\SpaceMember::get_role( $space_id, $user_id ) === 'admin'` | Roles: `'member'`, `'moderator'`, `'admin'` |
| `bp_is_user_in_group()` (template tag) | `\Jetonomy\Models\SpaceMember::is_member( $space_id, get_current_user_id() )` | n/a |
| `bp_register_member_type( 'premium' )` | WP `add_role()` / `add_cap()` + Jetonomy trust level evaluation | No named member-type registry; use WP roles or trust levels |

### Content and spaces

| BuddyPress / BuddyBoss | Jetonomy equivalent | Reference |
|------------------------|---------------------|-----------|
| `groups_create_group( $args )` | REST `POST /jetonomy/v1/spaces` or `\Jetonomy\Models\Space::create( $data )` | [01-rest-api.md](./01-rest-api.md) |
| `groups_get_groups( $args )` | REST `GET /jetonomy/v1/spaces` or `\Jetonomy\Models\Space::list( $args )` | [01-rest-api.md](./01-rest-api.md) |
| `groups_get_group( $group_id )` | `\Jetonomy\Models\Space::find( $space_id )` | n/a |
| `bp_activity_add( $args )` | REST `POST /jetonomy/v1/spaces/{id}/posts` or `\Jetonomy\Models\Post::create( $data )` | [01-rest-api.md](./01-rest-api.md) |
| `bp_activity_delete( $args )` | REST `DELETE /jetonomy/v1/posts/{id}` | [01-rest-api.md](./01-rest-api.md) |
| `xprofile_set_field_data( $field, $user_id, $value )` | Pro Custom Fields extension — REST `PUT /jetonomy/v1/users/{id}/custom-fields` | Pro only |

### Hooks

| BuddyPress / BuddyBoss hook | Jetonomy equivalent | Reference |
|-----------------------------|---------------------|-----------|
| `groups_join_group` | `jetonomy_user_joined_space` | [02-hooks-reference.md](./02-hooks-reference.md) |
| `groups_leave_group` / `groups_remove_member` | `jetonomy_user_left_space` | [02-hooks-reference.md](./02-hooks-reference.md) |
| `groups_promote_member` | *(no direct equivalent)* | Listen to `jetonomy_user_joined_space` with `$role = 'admin'` or `'moderator'` |
| `bp_activity_comment_posted` | `jetonomy_after_create_reply` | [02-hooks-reference.md](./02-hooks-reference.md) |
| `bp_activity_action` (activity posted) | `jetonomy_after_create_post` | [02-hooks-reference.md](./02-hooks-reference.md) |
| `bp_get_user_domain` | `jetonomy_profile_url` filter | [02-hooks-reference.md](./02-hooks-reference.md) |
| `bp_signup_validate` | `jetonomy_check_content` filter (content gate) | [02-hooks-reference.md](./02-hooks-reference.md) |

---

## Profile Tabs

In BuddyPress, `bp_core_new_nav_item()` registers a top-level profile tab; BuddyBoss adds `bp_nouveau_get_nav_items()` on top.

Jetonomy now has a `jetonomy_profile_tabs` filter for the same purpose. Full worked example: [Add a Profile Tab](./12-add-a-profile-tab.md).

```php
// Register a "Portfolio" tab on every Jetonomy profile.
// Signature: $tabs (array), $user (WP_User), $is_own (bool — true when viewing your own profile).
add_filter( 'jetonomy_profile_tabs', function( array $tabs, \WP_User $user, bool $is_own ): array {
    $tabs[] = [
        'slug'  => 'portfolio',
        'label' => __( 'Portfolio', 'acme' ),
        'url'   => \Jetonomy\get_profile_url( $user->ID ) . 'portfolio/',
    ];
    return $tabs;
}, 10, 3 );
```

---

## Space Tabs (frontend)

In BuddyPress, `BP_Group_Extension` adds a frontend tab to a group's sub-navigation. BuddyBoss exposes `bp_nouveau_get_group_secondary_nav()` on top.

Jetonomy now has a `jetonomy_space_tabs` filter for the equivalent. Full worked example: [Add a Space Tab](./13-add-a-space-tab.md).

```php
// Add a "Resources" tab to every space's frontend nav.
// Signature: $tabs (array), $space (object), $show_members (bool).
add_filter( 'jetonomy_space_tabs', function( array $tabs, object $space, bool $show_members ): array {
    $tabs[] = [
        'slug'  => 'resources',
        'label' => __( 'Resources', 'acme' ),
    ];
    return $tabs;
}, 10, 3 );
```

Note: the wp-admin side of space editing uses a different hook pair — `jetonomy_admin_space_edit_tabs` and `jetonomy_admin_space_edit_tab_content`. See [Admin Extensions](./20-admin-extensions.md).

---

## Trust Levels vs. Member Types

BuddyPress member types (`bp_register_member_type`) create named cohorts that can gate capabilities, appearance, and directory filters. Jetonomy does not have a member-type registry.

The closest tools are:

- **WP roles** — `add_role()` / `add_cap()` — for capability gating. Jetonomy's Permission Engine checks WP capabilities via `current_user_can()` at the community level, then layered space roles and trust levels.
- **Trust Levels 0-5** — auto-evaluated daily from activity (posts, replies, reputation, days active). Readable as `$user_profile->trust_level`. Listen to `jetonomy_trust_level_changed` to react to promotions.
- **Space roles** — `'member'`, `'moderator'`, `'admin'` — per-space, not site-wide.

There is no equivalent of BP's per-type directory filter, profile field visibility gating, or per-type nav injection. Those require WP role–based conditionals or trust-level checks in your own code.

---

## What Has No Equivalent (honest gaps)

| BuddyPress / BuddyBoss feature | Jetonomy status |
|--------------------------------|-----------------|
| Site-wide activity stream (`bp_activity_*`) | Not implemented. The BuddyPress integration bridges new Jetonomy posts to a BP group's stream, but Jetonomy has no native activity stream. |
| Member directory with facet filters | Not implemented. `jetonomy_search_query_args` covers content search; there is no member browser. |
| Member types (`bp_register_member_type`) | Not implemented. Use WP roles and trust levels. |
| Member cover images | Not implemented. |
| Notification preference UI hooks | Not implemented. The email adapter is swappable, but there is no per-notification-type preference toggle for members. |
| `BP_Group_Extension` admin panel | Not directly equivalent. Use `jetonomy_admin_space_edit_tabs` for a wp-admin panel on the space edit page. See [Admin Extensions](./20-admin-extensions.md). |

These are conscious omissions or open roadmap items, not accidental gaps.

---

## What's Next?

- [Add a Profile Tab](./12-add-a-profile-tab.md) - Worked example for the `jetonomy_profile_tabs` filter
- [Add a Space Tab](./13-add-a-space-tab.md) - Worked example for the `jetonomy_space_tabs` filter
- [Add a Nav Item](./14-add-a-nav-item.md) - Add links to the community header nav
- [BuddyPress Integration](./07-buddypress-integration.md) - Developer reference for the coexistence layer
- [Visibility and Access Matrix](./08-visibility-and-access-matrix.md) - The `Permission_Engine::can()` reference
- [Hooks Reference](./02-hooks-reference.md) - Full `jetonomy_*` hook listing
