Add a custom tab to a space page using the `jetonomy_space_tabs` filter. The tab appears alongside Discussions (or Questions / Ideas / Posts), Roadmap, and Members; clicking it loads a route you register separately.

---

## How Space Tabs Work

When Jetonomy renders a space page it assembles an ordered tab map, then passes it through `jetonomy_space_tabs` before rendering the nav. Two details determine whether and how the nav renders:

**The >1-tab rule:** the `<nav class="jt-space-tabs">` element is only rendered when the filtered map contains more than one tab. If your filter results in exactly one tab, no nav appears. Adding a custom tab to the default single-tab view (a non-ideas, members-hidden space) creates two tabs and triggers nav rendering.

**The `active` flag:** each tab entry can carry an `'active' => bool` key. Set this to `true` on the tab that represents the current page. Jetonomy reads `'active'` to add the `on` CSS class and `aria-current="page"` attribute. The built-in `primary` tab always starts as `active => true`; when your custom route renders, set `active => false` on `primary` and `active => true` on your tab (see the template example below).

---

## The `jetonomy_space_tabs` Filter

```
apply_filters( 'jetonomy_space_tabs', $tabs, $space, $show_members )
```

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$tabs` | `array` | Ordered map: `slug => ['label' => string, 'url' => string, 'active' => bool]`. Built-ins: `primary` (always), `roadmap` (ideas spaces), `members` (logged-in users). |
| `$space` | `object` | The space being viewed (`slug`, `type`, `name`, etc.). |
| `$show_members` | `bool` | Whether the Members tab is visible (`is_user_logged_in()`). |

**Return:** `array` - the modified tab map. Entries missing `label` or `url` are skipped.

**Source:** `templates/views/space.php`

---

## Step 1: Register a Template for the Tab Content

Register a new route via `jetonomy_template_map`. The template receives the space slug from `get_query_var( 'jetonomy_slug' )`.

```php
add_filter( 'jetonomy_template_map', function( array $map ): array {
    $map['space-analytics'] = MY_PLUGIN_DIR . 'templates/space-analytics.php';
    return $map;
} );
```

A minimal template for the route. It sets the active flag correctly on the tab map via the same filter - because the filter runs again when this template is loaded under the `space-analytics` route:

```php
<?php
// my-plugin/templates/space-analytics.php

$slug  = get_query_var( 'jetonomy_slug' );
$space = \Jetonomy\Models\Space::find_by_slug( $slug );

if ( ! $space ) {
    wp_redirect( home_url( '/' ) );
    exit;
}
?>
<div class="jt-app">
    <?php \Jetonomy\Template_Loader::partial( 'header' ); ?>
    <div class="jt-container">
        <?php
        // Your custom content here.
        // The tab bar is rendered by the partials/header - no need to
        // re-emit it here. Your filter below handles the active flag.
        ?>
        <h2><?php echo esc_html( $space->name ); ?> - Analytics</h2>
    </div>
</div>
```

---

## Step 2: Register the Rewrite Rule

```php
add_action( 'init', function() {
    $settings = get_option( 'jetonomy_settings', [] );
    $base     = $settings['base_slug'] ?? 'community';

    add_rewrite_rule(
        '^' . preg_quote( $base, '^' ) . '/s/([^/]+)/analytics/?$',
        'index.php?jetonomy_route=space-analytics&jetonomy_slug=$matches[1]',
        'top'
    );
} );
```

Flush permalinks once after adding: **Settings → Permalinks → Save Changes** or `wp rewrite flush`.

---

## Step 3: Add the Tab

Append the tab inside the filter. When running under the `space-analytics` route, also flip the `active` flag so the correct tab is highlighted.

```php
add_filter( 'jetonomy_space_tabs', function( array $tabs, object $space, bool $show_members ): array {
    $settings      = get_option( 'jetonomy_settings', [] );
    $base          = $settings['base_slug'] ?? 'community';
    $analytics_url = home_url( "/{$base}/s/{$space->slug}/analytics/" );

    // Detect whether we are currently on the analytics page.
    $is_active = ( 'space-analytics' === get_query_var( 'jetonomy_route' ) );

    // When analytics is the active page, un-mark the primary tab.
    if ( $is_active && isset( $tabs['primary'] ) ) {
        $tabs['primary']['active'] = false;
    }

    $tabs['analytics'] = [
        'label'  => __( 'Analytics', 'my-plugin' ),
        'url'    => $analytics_url,
        'active' => $is_active,
    ];

    return $tabs;
}, 10, 3 );
```

---

## Complete Example

```php
/**
 * Analytics tab on space pages.
 *
 * 1. Registers /community/s/{slug}/analytics/.
 * 2. Loads a custom template for that URL.
 * 3. Adds an "Analytics" tab to the space tab bar.
 * 4. Marks the tab active when the analytics route is loaded.
 */

// Step 1 - template.
add_filter( 'jetonomy_template_map', function( array $map ): array {
    $map['space-analytics'] = get_stylesheet_directory() . '/jetonomy/views/space-analytics.php';
    return $map;
} );

// Step 2 - rewrite rule.
add_action( 'init', function() {
    $settings = get_option( 'jetonomy_settings', [] );
    $base     = $settings['base_slug'] ?? 'community';

    add_rewrite_rule(
        '^' . preg_quote( $base, '^' ) . '/s/([^/]+)/analytics/?$',
        'index.php?jetonomy_route=space-analytics&jetonomy_slug=$matches[1]',
        'top'
    );
} );

// Step 3 - tab (handles both the space view and the analytics route).
add_filter( 'jetonomy_space_tabs', function( array $tabs, object $space, bool $show_members ): array {
    $settings      = get_option( 'jetonomy_settings', [] );
    $base          = $settings['base_slug'] ?? 'community';
    $analytics_url = home_url( "/{$base}/s/{$space->slug}/analytics/" );
    $is_active     = ( 'space-analytics' === get_query_var( 'jetonomy_route' ) );

    if ( $is_active && isset( $tabs['primary'] ) ) {
        $tabs['primary']['active'] = false;
    }

    $tabs['analytics'] = [
        'label'  => __( 'Analytics', 'my-plugin' ),
        'url'    => $analytics_url,
        'active' => $is_active,
    ];

    return $tabs;
}, 10, 3 );
```

---

## Removing or Reordering Built-in Tabs

**Remove a tab:**

```php
add_filter( 'jetonomy_space_tabs', function( array $tabs ): array {
    // Hide the Members tab entirely.
    unset( $tabs['members'] );
    return $tabs;
}, 10, 3 );
```

**Relabel a tab:**

```php
add_filter( 'jetonomy_space_tabs', function( array $tabs ): array {
    if ( isset( $tabs['roadmap'] ) ) {
        $tabs['roadmap']['label'] = __( 'Planned Features', 'my-plugin' );
    }
    return $tabs;
}, 10, 3 );
```

**Reorder tabs (rebuild map):**

```php
add_filter( 'jetonomy_space_tabs', function( array $tabs ): array {
    // Place Members before Roadmap.
    $members = $tabs['members'] ?? null;
    $roadmap = $tabs['roadmap'] ?? null;

    if ( $members && $roadmap ) {
        unset( $tabs['members'], $tabs['roadmap'] );
        $tabs['members'] = $members;
        $tabs['roadmap'] = $roadmap;
    }

    return $tabs;
}, 10, 3 );
```

---

## Notes

- The `>1-tab rule` is evaluated after your filter runs. If your filter removes tabs and leaves only one, the nav will be hidden - this is by design.
- The `active` key is optional; entries without it are treated as inactive. You only need to set it on the tab representing the current page.
- The `jetonomy_slug` and `jetonomy_route` query vars are already registered by Jetonomy's router; you do not need to register them again.
- Flush permalinks whenever you add or modify a rewrite rule. Use activation/deactivation hooks in a plugin - never call `flush_rewrite_rules()` on every request.
- See [Template Overrides](./03-template-overrides.md) for the full `jetonomy_template_map` contract and how to call partials correctly from custom templates.
