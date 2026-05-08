When BuddyNext is active alongside Jetonomy, the two plugins integrate automatically - sharing navigation, design tokens, and community surfaces without any configuration.

![Jetonomy admin settings showing BuddyNext integration status](../images/admin-settings.png)

## What You Will Learn

- What the BuddyNext integration enables
- How shared navigation and design tokens work
- How Jetonomy surfaces in BuddyNext's community hub
- What you need to do (spoiler: nothing)

## Auto-Detection

Jetonomy checks for BuddyNext on every load via the `buddynext_loaded` action. When detected, the integration layer activates automatically. There are no settings to configure and no toggles to enable.

> **Note:** The integration activates only when BuddyNext 1.0 or higher is active and the BuddyNext community hub feature is enabled.

## Shared Design Tokens

BuddyNext's `TokenService` injects CSS custom properties (`--brand`, `--bg`, `--text-1`, `--green`, `--amber`, `--red`, `--r-md`, `--font-body`, `--font-display`) into the page. Jetonomy's CSS inherits these automatically through its `--jt-*` token cascade:

```css
--jt-accent: var(--brand, var(--wp--preset--color--primary, #3B82F6));
--jt-bg:     var(--bg,    var(--wp--preset--color--base,    #ffffff));
--jt-font:   var(--font-body, var(--wp--preset--font-family--body, inherit));
```

This means Jetonomy matches the BuddyNext visual style without you writing a single line of CSS. Accent color, typography, border radius, and dark mode all flow through automatically.

## Forum Tab in BuddyNext Spaces

When BuddyNext community spaces are active, Jetonomy adds a **Forum** tab to each BuddyNext space. The tab links to the corresponding Jetonomy space, filtered to show only posts from that community space.

BuddyNext uses the `jetonomy_template_map` filter to register the forum tab route. This integration is non-destructive - if a BuddyNext space has no linked Jetonomy space, the Forum tab does not appear.

## Unified Navigation

Jetonomy's community header navigation respects BuddyNext's active navigation state. When a user is inside a BuddyNext community, the Jetonomy breadcrumb trail includes the BuddyNext community name as the first crumb.

The `jetonomy_profile_url` filter is also overridden automatically: user profile links inside Jetonomy point to BuddyNext member profiles instead of Jetonomy's built-in `/community/u/` pages.

## Dark Mode

BuddyNext's dark mode toggle sets `data-theme="dark"` on the document root. Jetonomy's dark mode overrides live in `.jt-dark .jt-app` and respond to this same attribute - so toggling dark mode in BuddyNext also applies to Jetonomy community pages instantly.

## Developer Notes

If you are building a custom BuddyNext integration, the BuddyNext bridge code lives in `includes/adapters/class-buddynext-bridge.php` in the Jetonomy plugin. You can hook into:

```php
// Fires after BuddyNext integration initializes.
do_action( 'jetonomy_buddynext_ready' );
```

Use this hook to register additional tab mappings or extend the forum tab with custom fields.

## Troubleshooting

**Forum tab not appearing in BuddyNext spaces** - Confirm BuddyNext's community spaces feature is active (not just the plugin). Also confirm the BuddyNext space has a linked Jetonomy space configured in its space settings.

**Design tokens not matching** - BuddyNext's TokenService may not be firing on community pages. Check the BuddyNext community page template and ensure `buddynext_loaded` action fires before the `wp_head` hook.

## What's Next?

Learn how Jetonomy adapts to any WordPress theme via CSS custom properties and template overrides.

[Theme Compatibility →](07-theme-compatibility.md)
