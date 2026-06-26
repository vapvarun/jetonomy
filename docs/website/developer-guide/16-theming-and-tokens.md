Jetonomy uses a single set of CSS custom properties — the `--jt-*` token system — to control every colour, radius, and font across the community UI. All tokens are declared in one place, inherit from the active theme where possible, and can be overridden from multiple layers: the admin palette, the `jetonomy_dynamic_css` filter, or a child-theme stylesheet. This page explains the full layering chain.

**Source references:**
- Token definitions: `assets/css/jetonomy.css` (`:root, .jt-app` block)
- Dynamic CSS assembly: `includes/class-template-loader.php`, `Template_Loader::render()`
- `palette_tokens()` method: `includes/class-template-loader.php:773`
- Host-theme adoption: `docs/standards/host-theme-color-adoption.md`

---

## The five admin palette settings

Under **Jetonomy → Settings → Appearance → Color Palette**, site owners can set up to five colors. Each maps to a root CSS token:

| Setting key (in `jetonomy_settings`) | CSS token written |
|--------------------------------------|-------------------|
| `accent_color` | `--jt-accent` |
| `text_color` | `--jt-text` |
| `bg_color` | `--jt-bg` |
| `bg_subtle_color` | `--jt-bg-subtle` |
| `border_color` | `--jt-border` |

The plugin emits a single `:root,.jt-app { ... }` block containing only the colors the owner actually set. An empty field means "keep the default", so a site that saves nothing gets zero style change. The legacy default value `#0073aa` for `accent_color` is treated as "unset" and never emitted.

Derived tokens (such as `--jt-accent-hover` and `--jt-text-secondary`) are `color-mix()` expressions over these root tokens, so they recompute automatically when a root token changes. Dark mode is unaffected: the `.jt-dark .jt-app` token block outranks `:root,.jt-app` by specificity.

---

## Token catalogue

All `--jt-*` tokens are declared in `:root, .jt-app` in `assets/css/jetonomy.css`. The root tokens inherit from the active theme before falling back to a hard-coded default:

```css
/* Root tokens (condensed) */
--jt-font:   var(--font-body, var(--wp--preset--font-family--body, inherit));
--jt-accent: var(--brand, var(--wp--preset--color--primary, #3B82F6));
--jt-text:   var(--text-1, var(--wp--preset--color--contrast, #1a1a1a));
--jt-bg:     var(--bg, var(--wp--preset--color--base, #ffffff));
--jt-radius: var(--r-md, var(--wp--custom--border-radius, 8px));
```

| Category | Tokens |
|----------|--------|
| Typography | `--jt-font`, `--jt-font-heading`, `--jt-font-mono` |
| Accent | `--jt-accent`, `--jt-accent-hover`, `--jt-accent-light`, `--jt-accent-muted` |
| Text | `--jt-text`, `--jt-text-secondary`, `--jt-text-tertiary` |
| Background | `--jt-bg`, `--jt-bg-subtle`, `--jt-bg-muted`, `--jt-bg-hover` |
| Border | `--jt-border`, `--jt-border-strong` |
| Semantic | `--jt-success`, `--jt-success-light`, `--jt-warn`, `--jt-warn-light`, `--jt-danger`, `--jt-danger-light` |
| Trust levels | `--jt-tl0` through `--jt-tl5` |
| Badge tiers | `--jt-badge-bronze`, `--jt-badge-silver`, `--jt-badge-gold` |
| Radius | `--jt-radius`, `--jt-radius-sm`, `--jt-radius-lg`, `--jt-radius-full` |
| Motion | `--jt-ease`, `--jt-dur` |

---

## `inherit_colors`: adopting the host theme's palette

When the `inherit_colors` setting is enabled, Jetonomy injects the full host-theme color-adoption chain:

```css
:root, .jt-app {
    --jt-accent: var(--bx-color-accent,
                   var(--reign-colors-theme,
                     var(--brand,
                       var(--wp--preset--color--primary,
                         var(--ast-global-color-0,
                           var(--global-palette1,
                             var(--theme-palette-color-1,
                               var(--wp--preset--color--accent, #3B82F6))))))));
    --jt-text: var(--bx-color-fg,
                 var(--text-1,
                   var(--wp--preset--color--contrast, #1a1a1a)));
    --jt-bg:   var(--bx-color-bg-elevated,
                 var(--bg,
                   var(--wp--preset--color--base, #ffffff)));
}
```

The chain resolves in priority order: BuddyX/BuddyX Pro (`--bx-color-*`) → Reign (`--reign-colors-theme`) → BuddyNext (`--brand`) → WP preset slugs (Astra, Kadence, Blocksy, GeneratePress) → hard-coded fallback.

When `inherit_colors` is on, the manual palette fields have no effect — the chain wins.

See [`docs/standards/host-theme-color-adoption.md`](../standards/host-theme-color-adoption.md) for the full verified token map per theme, the compliance checklist, and guidance on themes that expose no recognizable brand token.

---

## `custom_css`: freeform CSS from the admin

When the `custom_css` setting contains text, it is appended to the inline style block after the palette and inherit-colors rules. The value is passed through `wp_strip_all_tags()` before output, so HTML markup is stripped but valid CSS (including `--jt-*` token assignments) is preserved.

This is the right place for a site owner to make one-off tweaks without a child theme — for example:

```css
/* In Jetonomy → Settings → Appearance → Custom CSS */
.jt-app {
    --jt-radius: 0;  /* square corners sitewide */
}
.jt-btn--primary {
    font-weight: 700;
}
```

---

## The `jetonomy_dynamic_css` filter

*Since 1.5.0.*

The `jetonomy_dynamic_css` filter fires after the full dynamic CSS string is assembled — container width, palette overrides, font-inherit rules, host-theme color chain, density rules, and the admin `custom_css` — and before it is attached to the page via `wp_add_inline_style()`.

Use it from a plugin (companion plugin, add-on) to append token overrides or scoped rules without requiring a child theme.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$css` | `string` | The assembled inline CSS string |
| `$settings` | `array` | The `jetonomy_settings` option array |

**Returns:** `string` — the modified CSS string.

**Source:** `includes/class-template-loader.php:250`

```php
add_filter( 'jetonomy_dynamic_css', function ( string $css, array $settings ): string {
    // Force a wider sidebar on a high-content site.
    $css .= '.jt-two-col { --jt-sidebar-width: 320px; }';
    return $css;
}, 10, 2 );
```

You can also branch on `$settings` to conditionally apply a rule:

```php
add_filter( 'jetonomy_dynamic_css', function ( string $css, array $settings ): string {
    if ( ! empty( $settings['inherit_colors'] ) ) {
        // Only adjust radius when theme colors are inherited (brand already set).
        $css .= ':root,.jt-app { --jt-radius: 4px; }';
    }
    return $css;
}, 10, 2 );
```

---

## Child-theme overrides via `:root`

For theme-level token changes, override in your child theme's `style.css` (or an enqueued stylesheet) by targeting `:root` or `.jt-app` with a specificity that outranks the plugin's block:

```css
/* your-child-theme/style.css */

/* Override the accent token for Jetonomy only. */
.jt-app {
    --jt-accent: #7c3aed;
    --jt-accent-hover: #6d28d9;
}

/* Or override globally (affects both Jetonomy and any component that reads the WP preset). */
:root {
    --wp--preset--color--primary: #7c3aed;
}
```

The `.jt-app` selector targets the Jetonomy container specifically without leaking into the rest of the page. The `:root` approach shares the change with any other plugin that reads the same WP preset token.

If you are using the `inherit_colors` setting alongside a child-theme override, note that `inherit_colors` writes to `:root,.jt-app` at the same specificity level as your override. Use a more-specific selector (`.jt-app .jt-container`) or add `!important` (last resort) to guarantee your value wins.

---

## Dark-mode rule

Never write per-component dark selectors. Dark mode overrides belong only in `.jt-dark .jt-app` in `jetonomy.css`, reassigning the `--jt-*` root tokens. Individual components inherit dark mode automatically because they reference the tokens:

```css
/* Correct */
.jt-card {
    background: var(--jt-bg);
    border: 1px solid var(--jt-border);
}

/* Wrong — breaks when a token value changes */
.jt-dark .jt-card {
    background: #1e1e1e;
}
```

---

## What's next

- [Template Overrides](./03-template-overrides.md) — adding CSS to overridden template files
- [Extend the Frontend](./17-extend-the-frontend.md) — injecting JavaScript that uses the same token values
- [Hooks Reference](./02-hooks-reference.md) — `jetonomy_before_content` for injecting markup that needs matching styles
