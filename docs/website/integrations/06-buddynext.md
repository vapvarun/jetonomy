When BuddyNext is active alongside Jetonomy, the two plugins integrate automatically - sharing design tokens and letting BuddyNext own the page header and navigation, without any configuration.

![The Jetonomy admin settings screen](../images/admin-settings.png)

## What You Will Learn

- How BuddyNext and Jetonomy split the work between a social layer and a forum layer
- Why pairing the two in-house plugins beats bolting on a third-party social plugin
- What the BuddyNext integration enables
- How shared design tokens work
- How Jetonomy suppresses its own header and nav when BuddyNext is active
- What you need to do (spoiler: nothing)

## The Social Layer for Jetonomy

BuddyNext is Wbcom's in-house **social-networking layer**, and Jetonomy is the **forum, community, and Q&A layer**. They are built to pair:

| Layer | Plugin | Owns |
|---|---|---|
| Social | **BuddyNext** | Member profiles, the activity feed/stream, connections and the social graph |
| Forum | **Jetonomy** | Spaces, topics, ideas, and roadmaps |

Run them together and you get a **complete community platform from one vendor** - social profiles and a member feed on the BuddyNext side, structured discussion and Q&A on the Jetonomy side. There is no third-party social plugin (BuddyPress or BuddyBoss) required: the social graph and the forum come from the same house and are designed to fit.

> **Already on BuddyPress?** Jetonomy also pairs with BuddyPress directly - see the [BuddyPress integration](13-buddypress.md). BuddyNext is the path for site owners who want the social layer and the forum layer to come from a single vendor.

## Why BuddyNext Over a Third-Party Social Plugin

For a site owner choosing what to run underneath Jetonomy, BuddyNext has practical advantages over a third-party social plugin:

- **One vendor.** Social layer and forum layer ship from the same team, so there is one place to go for billing, licensing, and questions - not two ecosystems to reconcile.
- **Aligned roadmap and support.** Features on both sides are planned together, so an integration point doesn't break when one plugin updates ahead of the other. Support sees the whole stack.
- **Native colour and dark-mode sync.** Jetonomy adopts BuddyNext's design tokens automatically, so the forum matches the social layer in light and dark mode with no custom CSS. See [Popular Page-Builder Themes and colour adoption](07-theme-compatibility.md#popular-page-builder-themes-150) for how the underlying token chain works.
- **Lighter footprint.** No extra abstraction layer or compatibility shim between a third-party social plugin and Jetonomy - the two plugins talk through shared tokens and a single detection hook.

## Auto-Detection

Jetonomy detects BuddyNext automatically - there are no settings to configure and no toggles to enable. When BuddyNext is active, Jetonomy adopts its colors and typography and lets BuddyNext own the page header and navigation.

> **Note:** The integration activates only when BuddyNext 1.0 or higher is active and the BuddyNext community hub feature is enabled.

## Shared Design Tokens

BuddyNext's `TokenService` injects CSS custom properties (`--brand`, `--bg`, `--text-1`, `--green`, `--amber`, `--red`, `--r-md`, `--font-body`, `--font-display`) into the page. Jetonomy's CSS inherits these automatically through its `--jt-*` token cascade:

```css
--jt-accent: var(--brand, var(--wp--preset--color--primary, #3B82F6));
--jt-bg:     var(--bg,    var(--wp--preset--color--base,    #ffffff));
--jt-font:   var(--font-body, var(--wp--preset--font-family--body, inherit));
```

This means Jetonomy matches the BuddyNext visual style without you writing a single line of CSS. Accent color, typography, border radius, and dark mode all flow through automatically.

## Header and Navigation Suppression

When BuddyNext is active, Jetonomy detects it via the `buddynext_loaded` action and suppresses its own community header and top navigation. BuddyNext owns the page chrome, so Jetonomy renders its content inside BuddyNext's layout without duplicating the header bar or navigation.

This keeps the page from showing two competing headers. When BuddyNext is not active, Jetonomy renders its own header and navigation as normal.

## Dark Mode

BuddyNext's dark mode toggle sets `data-theme="dark"` on the document root. Jetonomy's dark mode overrides live in `.jt-dark .jt-app` and respond to this same attribute - so toggling dark mode in BuddyNext also applies to Jetonomy community pages instantly.

## Developer Notes

Detection is driven by the `buddynext_loaded` action. Jetonomy's templates check `did_action( 'buddynext_loaded' )` and skip rendering their own header and navigation when BuddyNext is present (see `templates/partials/header.php` and `templates/partials/sidebar.php`). There is no separate bridge class to configure - the suppression is built into the templates and runs automatically.

## Troubleshooting

**Jetonomy still showing its own header alongside BuddyNext** - The `buddynext_loaded` action must fire before Jetonomy's templates render. Confirm BuddyNext is active and loading on the page, and that no caching layer is serving a pre-BuddyNext version of the page.

**Design tokens not matching** - BuddyNext's TokenService may not be firing on community pages. Check the BuddyNext community page template and ensure `buddynext_loaded` action fires before the `wp_head` hook.

## What's Next?

Learn how Jetonomy adapts to any WordPress theme via CSS custom properties and template overrides.

[Theme Compatibility →](07-theme-compatibility.md)
