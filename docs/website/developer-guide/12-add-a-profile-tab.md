Add a custom tab to a member's profile page using the `jetonomy_profile_tabs` filter - no template override needed. The tab appears next to Posts, Replies, and Votes; clicking it loads a separate route that you register alongside the tab.

---

## How Profile Tabs Work

When Jetonomy renders a profile page it builds an ordered map of tabs, then passes it through `jetonomy_profile_tabs` before rendering the tab bar. Each entry in the map is a slug keyed to a label and URL. The `posts` tab is active when no sub-tab is in the URL; all other tabs are matched by slug against the current URL.

**Rendering rule:** every non-empty entry with a `label` and `url` key is rendered. There is no minimum count - even a single tab will render. To remove a built-in tab, unset its slug from the map before returning it.

**Content rule:** a custom tab is just a link. Clicking it navigates to the URL you specify. Content for that URL must come from a separate route and template registered via `jetonomy_template_map` (see [Template Overrides](./03-template-overrides.md)).

---

## The `jetonomy_profile_tabs` Filter

```
apply_filters( 'jetonomy_profile_tabs', $tabs, $user, $is_own )
```

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$tabs` | `array` | Ordered map: `slug => ['label' => string, 'url' => string]`. Built-ins: `posts`, `replies`, `votes`, `bookmarks` (own only), `drafts` (own only). |
| `$user` | `WP_User` | The profile owner. |
| `$is_own` | `bool` | `true` when the viewing user is the profile owner. |

**Return:** `array` - the modified tab map.

**Source:** `templates/views/user-profile.php`

**Built-in slugs and active-state rule:**
The `posts` tab is active when the URL is the bare profile URL (no sub-tab segment). All other tabs are active when their slug appears as the `jetonomy_tab` query var. Setting a tab's URL to your own route means Jetonomy's active-tab logic will not apply to it - your template is responsible for highlighting the tab.

---

## Step 1: Register a Template for the Tab Content

Register a new route via `jetonomy_template_map` and point it to a PHP template in your plugin or theme. The template receives the user login from `get_query_var( 'jetonomy_slug' )`.

```php
add_filter( 'jetonomy_template_map', function( array $map ): array {
    // Absolute path bypasses the theme-override check - correct for plugin templates.
    $map['profile-portfolio'] = MY_PLUGIN_DIR . 'templates/profile-portfolio.php';
    return $map;
} );
```

A minimal template for the route:

```php
<?php
// my-plugin/templates/profile-portfolio.php

$login = get_query_var( 'jetonomy_slug' );
$user  = get_user_by( 'login', $login );

if ( ! $user ) {
    wp_redirect( home_url( '/' ) );
    exit;
}
?>
<div class="jt-app">
    <?php \Jetonomy\Template_Loader::partial( 'header' ); ?>
    <div class="jt-container">
        <h2><?php echo esc_html( $user->display_name ); ?> - Portfolio</h2>
        <?php
        // Your custom content here.
        ?>
    </div>
</div>
```

---

## Step 2: Register the Rewrite Rule

Teach Jetonomy's router about the new URL pattern. The slug captured in `$matches[1]` is passed as `jetonomy_slug` so the template can look up the user.

```php
add_action( 'init', function() {
    $settings  = get_option( 'jetonomy_settings', [] );
    $base      = $settings['base_slug'] ?? 'community';

    add_rewrite_rule(
        '^' . preg_quote( $base, '^' ) . '/u/([^/]+)/portfolio/?$',
        'index.php?jetonomy_route=profile-portfolio&jetonomy_slug=$matches[1]',
        'top'
    );
} );
```

After adding this code, flush permalinks once: **Settings → Permalinks → Save Changes**, or via WP-CLI:

```bash
wp --path="/path/to/wordpress" rewrite flush
```

---

## Step 3: Add the Tab

Hook into `jetonomy_profile_tabs` and append your tab. Build the URL from the `$user` parameter so you never need to look up the user again.

```php
add_filter( 'jetonomy_profile_tabs', function( array $tabs, WP_User $user, bool $is_own ): array {
    $settings = get_option( 'jetonomy_settings', [] );
    $base     = $settings['base_slug'] ?? 'community';

    $tabs['portfolio'] = [
        'label' => __( 'Portfolio', 'my-plugin' ),
        'url'   => home_url( "/{$base}/u/{$user->user_login}/portfolio/" ),
    ];

    return $tabs;
}, 10, 3 );
```

---

## Complete Example

The following block can be placed in your theme's `functions.php` or in a site-specific mu-plugin. Replace `MY_PLUGIN_DIR` with the correct path if you are not working inside a plugin.

```php
/**
 * Portfolio tab on member profiles.
 *
 * 1. Registers a /community/u/{login}/portfolio/ URL.
 * 2. Loads a custom template for that URL.
 * 3. Adds a "Portfolio" tab to the profile tab bar.
 */

// Step 1 - template.
add_filter( 'jetonomy_template_map', function( array $map ): array {
    // Point to a template file in your plugin or theme.
    $map['profile-portfolio'] = get_stylesheet_directory() . '/jetonomy/views/profile-portfolio.php';
    return $map;
} );

// Step 2 - rewrite rule.
add_action( 'init', function() {
    $settings = get_option( 'jetonomy_settings', [] );
    $base     = $settings['base_slug'] ?? 'community';

    add_rewrite_rule(
        '^' . preg_quote( $base, '^' ) . '/u/([^/]+)/portfolio/?$',
        'index.php?jetonomy_route=profile-portfolio&jetonomy_slug=$matches[1]',
        'top'
    );
} );

// Step 3 - tab.
add_filter( 'jetonomy_profile_tabs', function( array $tabs, WP_User $user, bool $is_own ): array {
    $settings = get_option( 'jetonomy_settings', [] );
    $base     = $settings['base_slug'] ?? 'community';

    $tabs['portfolio'] = [
        'label' => __( 'Portfolio', 'my-plugin' ),
        'url'   => home_url( "/{$base}/u/{$user->user_login}/portfolio/" ),
    ];

    return $tabs;
}, 10, 3 );
```

---

## Removing or Reordering Built-in Tabs

**Remove a tab:** unset it from the map before returning.

```php
add_filter( 'jetonomy_profile_tabs', function( array $tabs, WP_User $user, bool $is_own ): array {
    // Remove the Votes tab from all profiles.
    unset( $tabs['votes'] );

    // Remove Bookmarks from other people's profiles (it is already hidden
    // by the template for non-owners, but removing it here prevents the
    // URL from loading via direct navigation).
    if ( ! $is_own ) {
        unset( $tabs['bookmarks'] );
    }

    return $tabs;
}, 10, 3 );
```

**Reorder tabs:** PHP arrays preserve insertion order, so rebuild the map in the sequence you want.

```php
add_filter( 'jetonomy_profile_tabs', function( array $tabs, WP_User $user, bool $is_own ): array {
    // Move Replies before Posts.
    $replies = $tabs['replies'] ?? null;
    if ( $replies ) {
        unset( $tabs['replies'] );
        // Re-insert at the front using array union.
        $tabs = [ 'replies' => $replies ] + $tabs;
    }
    return $tabs;
}, 10, 3 );
```

**Relabel a tab:**

```php
add_filter( 'jetonomy_profile_tabs', function( array $tabs ): array {
    if ( isset( $tabs['posts'] ) ) {
        $tabs['posts']['label'] = __( 'Topics', 'my-plugin' );
    }
    return $tabs;
}, 10, 3 );
```

---

## Notes

- Flush permalinks after changing any rewrite rule. In a plugin, call `flush_rewrite_rules()` on activation and deactivation hooks - never on every request.
- The `jetonomy_slug` and `jetonomy_route` query vars are already registered by Jetonomy's router; you do not need to add them to `query_vars`.
- If your tab must only appear for logged-in users, check `is_user_logged_in()` inside the filter before appending the tab.
- See [Template Overrides](./03-template-overrides.md) for calling partials (`header`, `breadcrumb`) correctly inside your custom template so theme overrides are respected.
