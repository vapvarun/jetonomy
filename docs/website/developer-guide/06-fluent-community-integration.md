Developer reference for the FluentCommunity coexistence integration shipped in Jetonomy 1.3.8. This page is for plugin/theme developers extending or debugging the integration. End users should start with the [FluentCommunity integration guide](../integrations/12-fluent-community.md).

## What You Will Learn

- Where the integration lives and when it loads
- Which WordPress options persist its state
- Which FluentCommunity and Jetonomy hooks it consumes
- Which helpers you can reuse from your own code
- How loop protection, identity keying, and stale-pair handling work

## File Layout

The entire integration lives in one class:

```
includes/integrations/class-fluent-community.php
```

Loaded from `includes/class-jetonomy.php` only when FluentCommunity is active:

```php
if ( class_exists( '\\FluentCommunity\\App\\App' ) ) {
    require_once JETONOMY_DIR . 'includes/integrations/class-fluent-community.php';
    new Integrations\Fluent_Community();
}
```

No autoload entry beyond this gate. Deactivate FluentCommunity and nothing from this file is parsed or instantiated.

## Persisted State

Two WordPress options hold the entire integration footprint. No custom tables, no post meta, no user meta.

| Option | Type | Default | Purpose |
|--------|------|---------|---------|
| `jetonomy_fc_space_pairs` | array `{fc_space_id: jt_space_id}` | `[]` | Space pairing map. One row holds every pair. |
| `jetonomy_fc_tab_label` | string | `Discussions` | Label used on the FC tab, the Jetonomy sidebar card, and the FC profile Discussions block. |
| `jetonomy_fc_sync_members` | `'1'` / `'0'` | `'1'` | Toggle for bidirectional member sync. |
| `jetonomy_fc_broadcast` | `'1'` / `'0'` | `'1'` | Toggle for topic broadcast to the paired FC feed. |

All four options are removed on uninstall by Jetonomy's standard `jetonomy_*` option sweep in `uninstall.php`.

### Reading the pair map

```php
$pairs = get_option( 'jetonomy_fc_space_pairs', array() );
if ( is_array( $pairs ) ) {
    foreach ( $pairs as $fc_space_id => $jt_space_id ) {
        // Use as needed. Both IDs are integers when the map is valid.
    }
}
```

### Reverse lookup

To find the Jetonomy space paired with a given FC space (or the reverse), walk the map:

```php
$fc_id_for_jt = function ( int $jt_id ) {
    $pairs = get_option( 'jetonomy_fc_space_pairs', array() );
    if ( ! is_array( $pairs ) ) {
        return 0;
    }
    foreach ( $pairs as $fc => $jt ) {
        if ( (int) $jt === $jt_id ) {
            return (int) $fc;
        }
    }
    return 0;
};
```

## FluentCommunity Hooks Consumed

| Hook | Type | Surface |
|------|------|---------|
| `get_avatar_url` (core WP) | filter | Unifies the avatar across both sides by reading `wp_fcom_xprofile.avatar` keyed on `user_id`. |
| `fluent_community/space_header_links` | filter | Appends the Discussions tab to the FC space header. |
| `fluent_community/activity/after_contents_user` | filter | Renders the Discussions block on the FC profile. |
| `fluent_community/space/joined` | action | Triggers member sync from FC to Jetonomy. |
| `fluent_community/comment_added` | action | Triggers the comment-to-reply bridge on broadcast feed comments. |

All FC hook names were verified against FluentCommunity 2.3.0.

## Jetonomy Hooks Consumed

| Hook | Type | Surface |
|------|------|---------|
| `jetonomy_admin_settings_tabs` | action | Registers the FluentCommunity settings tab. |
| `jetonomy_admin_settings_tab_content` | action | Renders the tab body when active. |
| `jetonomy_sidebar_after_about` | action | Renders the "Also on {community}" sidebar card on paired Jetonomy spaces. |
| `jetonomy_profile_after_stats` | action | Renders the cross-link to the member's FluentCommunity profile. |
| `jetonomy_user_joined_space` | action | Triggers member sync from Jetonomy to FC. |
| `jetonomy_after_create_post` | action | Triggers the broadcast of a new topic to the paired FC feed. |

## Identity Keying

Everything joins on `user_id`, never on username. `user_id` is the primary key in both `wp_fcom_xprofile` and `wp_jt_user_profiles`, so the integration stays correct no matter how usernames diverge across the two plugins.

The integration ships a static helper to map `user_id` to the FC username for URL construction:

```php
// Private in the integration class. Shape reproduced here for reference.
// Returns null when the user has no FC xprofile row.
fc_username_for_user( int $user_id ): ?string
```

If you need this elsewhere, query `wp_fcom_xprofile.username` by `user_id` directly. The result is request-scoped cached inside the integration class.

## Community Name Helper

`fc_site_title(): string` reads FC's configured `site_title` from the `fluent_community_settings` option and falls back to the WP site name, then to the translated string `Community`. This is what drives the dynamic button and card labels. Reuse the same option key if you are rendering your own cross-links:

```php
$settings = get_option( 'fluent_community_settings', array() );
$title    = is_array( $settings ) && ! empty( $settings['site_title'] )
    ? (string) $settings['site_title']
    : get_bloginfo( 'name' );
```

## Loop Protection

Member sync and broadcast use a static `$syncing` flag so a join or post on one side never triggers a boomerang write back:

```php
// Pseudocode matching the real implementation.
if ( self::$syncing ) {
    return;
}
self::$syncing = true;
try {
    // Write to the other side.
} finally {
    self::$syncing = false;
}
```

For the comment-to-reply bridge, only comments on broadcast feed posts round-trip. Broadcast feed rows are tagged with a meta marker when Jetonomy creates them, and the bridge listens only for comments on rows carrying that marker. Native FC feed posts never create Jetonomy replies.

## Stale Pair Handling

At render time, every surface re-resolves the paired space ID. If the referenced FC or Jetonomy space no longer exists (deleted, trashed, or the pair option references an invalid ID), the tab or card silently disappears and no admin cleanup is required. The integration never fatals on a stale pair.

## Add-Only Semantics

Member sync is deliberately add-only:

- Joins propagate in both directions.
- Leaves do not propagate. Removing yourself from one side never yanks you out of the other.
- Role changes do not propagate. Each plugin manages its own role structure.

If you build on top of this integration and need leave-sync or role-sync behaviour, do it in a separate extension with an explicit per-pair toggle and a visible admin warning. The defaults stay add-only to avoid accidental bulk removals.

## Privacy Guard

Topics marked as private (`is_private = 1`) are never broadcast to FluentCommunity. The FC feed audience can be broader than the private-topic scope, so the guard prevents leaking private content to a wider audience. If you add your own broadcast surfaces on top, apply the same guard:

```php
if ( ! empty( $post->is_private ) ) {
    return; // Skip broadcast.
}
```

## REST Architecture Note

FluentCommunity ships as a Vue SPA consuming its REST API. All the PHP filters the integration uses run inside REST response preparation, and their output flows through to the SPA render automatically. No Jetonomy-side REST additions or JS injection are needed for v1 of the integration.

Verified against live FC endpoints at build time:

| Endpoint | Filter that lands output in SPA |
|----------|-------------------------------|
| `GET /fluent-community/v2/spaces/{slug}/by-slug` | `fluent_community/space_header_links` populates `header_links` |
| `GET /fluent-community/v2/profile/{username}` | `fluent_community/profile_view_data` populates `profile_navs` (not used in v1) |
| `GET /fluent-community/v2/activities?...` | `fluent_community/activity/after_contents_user` appends to the user activity view |

## Extending

Want to build on top? Two clean extension points:

- **Listen for the broadcast.** The integration calls `fluent_community/feed/created` after creating the FC feed row, so your code can react to broadcasts with your own handler.
- **Replace a surface.** Because every render hook exits early when its pair resolves to nothing, you can remove the integration's handler from `jetonomy_sidebar_after_about` (priority `10`) and register your own without code-level conflicts.

Destructive extensions (leave-sync, role-sync, privacy mirroring) belong in a Pro extension with explicit per-pair toggles and a backfill tool, not as drop-in replacements for the free integration.
