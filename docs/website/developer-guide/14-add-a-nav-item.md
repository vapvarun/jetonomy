Add a link or button to Jetonomy's community top nav using the `jetonomy_header_nav_items` action — no template override needed.

---

## How the Community Nav Works

The community header nav (`partials/header.php`) renders a row of icon-and-label links: Home, Leaderboard, Profile, Moderation. It closes with `do_action( 'jetonomy_header_nav_items' )` after the built-in items, so anything you echo from that hook appears at the right end of the nav rail.

The nav is rendered on every Jetonomy page. The active state on built-in links is set by comparing the current `$current_route` (from `get_query_var('jetonomy_route')`) to a hard-coded route name. For custom items, manage the active class yourself.

**Source:** `templates/partials/header.php`

---

## The `jetonomy_header_nav_items` Action

```
do_action( 'jetonomy_header_nav_items' )
```

**Parameters:** none.

**Where it fires:** inside `.jt-community-nav-links`, after all built-in nav links and before the actions rail (notifications bell, avatar).

---

## Basic Example: Add a Static Link

```php
add_action( 'jetonomy_header_nav_items', function() {
    echo '<a href="' . esc_url( home_url( '/events/' ) ) . '" class="jt-nav-link" title="' . esc_attr__( 'Events', 'my-plugin' ) . '">';
    echo '<span class="jt-nav-label">' . esc_html__( 'Events', 'my-plugin' ) . '</span>';
    echo '</a>';
} );
```

---

## Complete Example: Link with Active State and Icon

The built-in nav links use `jetonomy_echo_icon()` for their SVG icons. You can use the same function if you want a consistent icon style, or pass your own SVG. The example below adds a "Help" link that highlights when the user is on your custom help route.

```php
add_action( 'jetonomy_header_nav_items', function() {
    $current_route = get_query_var( 'jetonomy_route' );
    $is_active     = ( 'help' === $current_route );

    // Build the URL from the Jetonomy base slug so it adapts if the site
    // owner has customized the community path.
    $settings  = get_option( 'jetonomy_settings', [] );
    $base      = $settings['base_slug'] ?? 'community';
    $help_url  = home_url( "/{$base}/help/" );

    $classes = 'jt-nav-link' . ( $is_active ? ' active' : '' );

    echo '<a href="' . esc_url( $help_url ) . '" class="' . esc_attr( $classes ) . '" title="' . esc_attr__( 'Help', 'my-plugin' ) . '">';

    // Use Jetonomy's icon helper if available, or inline SVG as fallback.
    if ( function_exists( 'jetonomy_echo_icon' ) ) {
        jetonomy_echo_icon( 'help-circle', 18 );
    } else {
        // Minimal inline SVG fallback.
        echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    }

    echo '<span class="jt-nav-label">' . esc_html__( 'Help', 'my-plugin' ) . '</span>';
    echo '</a>';
} );
```

---

## Adding a Nav Item Only for Specific Roles

```php
add_action( 'jetonomy_header_nav_items', function() {
    // Only show the moderation shortcut to users who can moderate.
    if ( ! current_user_can( 'jetonomy_moderate' ) ) {
        return;
    }

    $settings = get_option( 'jetonomy_settings', [] );
    $base     = $settings['base_slug'] ?? 'community';

    echo '<a href="' . esc_url( home_url( "/{$base}/mod/queue/" ) ) . '" class="jt-nav-link" title="' . esc_attr__( 'Queue', 'my-plugin' ) . '">';
    echo '<span class="jt-nav-label">' . esc_html__( 'Queue', 'my-plugin' ) . '</span>';
    echo '</a>';
} );
```

---

## Hiding the Built-in Nav

If you need to suppress the entire built-in community nav (for example, when a bridge plugin provides its own navigation), use the `jetonomy_show_community_nav` filter instead of working around the action:

```php
// Hide the community nav bar entirely (documented in 02-hooks-reference.md).
add_filter( 'jetonomy_show_community_nav', '__return_false' );
```

---

## Notes

- Echo valid anchor elements only. The hook fires inside a `<div>` — block-level elements will break the nav layout. Use `<a>` tags styled with `jt-nav-link` to match the built-in items, or provide your own class.
- Use `esc_url()`, `esc_attr()`, and `esc_html()` on all output. Never echo raw variable values.
- The hook fires on every Jetonomy page including admin-facing moderation views, so conditional checks (logged-in state, capability, route) prevent items appearing in the wrong context.
- If you want to remove a built-in nav item rather than add a new one, override `partials/header.php` in your theme. See [Template Overrides](./03-template-overrides.md).
