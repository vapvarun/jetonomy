Jetonomy uses a single set of CSS custom properties - the `--jt-*` token system - to control every colour, radius, and font across the community UI. All tokens are declared in one place, adopt the active theme's own brand colour automatically, and can be overridden from multiple layers: the admin accent/palette fields, the `jetonomy_dynamic_css` filter, or a child-theme stylesheet. This page explains the full layering chain.

**Source references:**
- Token definitions: `assets/css/jetonomy-tokens.css` (`:root` block, handle `jetonomy-tokens`)
- Token consumer (declares none): `assets/css/jetonomy.css`
- Dynamic CSS assembly: `includes/class-template-loader.php`, `Template_Loader::render()`
- `palette_css()` / `palette_tokens()` methods: `includes/class-template-loader.php:785` / `:819`
- Host-theme adoption standard: `docs/standards/host-theme-color-adoption.md`

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

`palette_css()` emits a single `:root { ... }` block containing only the colors the owner actually set (`includes/class-template-loader.php:785`). An empty field means "keep the default", so a site that saves nothing gets zero style change. The default value `#0073aa` for `accent_color` is treated as "unset, adopt the theme" and never emitted - this is the accent picker's "not set" sentinel (`palette_tokens()`, `includes/class-template-loader.php:819`).

Derived tokens (such as `--jt-accent-hover` and `--jt-text-secondary`) are `color-mix()` expressions over these root tokens, so they recompute automatically when a root token changes. Dark mode is unaffected: the `.jt-dark, [data-theme="dark"]` block reassigns the same `:root` tokens on the class the host theme puts on `<body>`, and every `--jt-*` consumer inherits from it.

---

## Token catalogue

All `--jt-*` tokens are declared on `:root` **only** in `assets/css/jetonomy-tokens.css` (never on `.jt-app`, and never re-declared per component - see the Dark-mode rule below for why). The root tokens adopt the active theme before falling back to a hard-coded default:

```css
/* Root tokens (condensed) */
--jt-font:   var(--font-body, var(--wp--preset--font-family--body, inherit));
--jt-accent: var(--brand, var(--wp--preset--color--primary, #0073aa)); /* full chain below */
--jt-text:   var(--bx-color-fg, var(--text-1, var(--wp--preset--color--contrast, #1a1a1a)));
--jt-bg:     var(--bx-color-bg-elevated, var(--bg, var(--wp--preset--color--base, #ffffff)));
--jt-radius: var(--r-md, var(--wp--custom--border-radius, 8px));
```

The full `--jt-accent` adoption chain and the WCAG contrast guard that derives readable button text on top of it are described in the next section.

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

## Host-theme colour adoption (unconditional)

Jetonomy **always** adopts the active theme's own brand colour. There is no toggle: the `--jt-accent` chain is declared statically in `:root` in `jetonomy-tokens.css` and resolves to the first token the active theme actually defines:

```css
:root {
    --jt-accent: var(--bx-color-accent,           /* BuddyX / BuddyX Pro 5.1+ */
                 var(--reign-colors-theme,        /* Reign 8.0+               */
                 var(--brand,                     /* BuddyNext                */
                 var(--wp--preset--color--primary,/* "primary"-slug themes    */
                 var(--ast-global-color-0,        /* Astra                    */
                 var(--global-palette1,           /* Kadence                  */
                 var(--theme-palette-color-1,     /* Blocksy                  */
                 var(--nv-primary-accent,         /* Neve                     */
                 var(--wp--preset--color--accent, /* GeneratePress + "accent" */
                 #0073aa)))))))));                 /* neutral default          */
    --jt-text: var(--bx-color-fg,
                 var(--text-1,
                   var(--wp--preset--color--contrast, #1a1a1a)));
    --jt-bg:   var(--bx-color-bg-elevated,
                 var(--bg,
                   var(--wp--preset--color--base, #ffffff)));
}
```

Source: `assets/css/jetonomy-tokens.css` (the `:root` block, `--jt-accent`). The tokens are mutually exclusive per active theme, so ordering only decides precedence for the rare theme that exposes two. Because each link is `var(token, fallback)`, the accent also follows the theme's **dark mode** for free when the theme flips its own token.

> **Removed in 1.8.0.** The `inherit_colors` and `inherit_fonts` settings no longer exist. `inherit_colors` defaulted to checked and, while on, made `palette_tokens()` return nothing - so an owner who picked an accent had it silently discarded. Adoption is now unconditional and the accent field is the single override (see below). `inherit_fonts` emitted `--jt-font:inherit`, which changed nothing because the `--jt-font` chain already ends in `inherit`. Both removals are documented in `includes/class-template-loader.php:218`.

### The accent field is the single override

To pin an exact accent instead of the adopted one, set **Settings → Appearance → Accent Color** to any value other than the `#0073aa` default. That emits `:root{--jt-accent:<chosen>}` inline via `Template_Loader::palette_css()`, which outranks the static chain on every theme. Leaving it at `#0073aa` (the "not set" sentinel) keeps adoption active - `palette_tokens()` deliberately skips that exact value (`includes/class-template-loader.php:819`).

### WCAG contrast guard (1.8.0)

Adopting an arbitrary theme brand colour and painting hard-coded white text on it fails WCAG AA on pale or muted brand colours (measured: 7 of 11 themes failed, Reign's lavender at 2.13:1). The fix cannot run in PHP - `--jt-accent` usually resolves to a theme token the browser only learns at runtime, so the server never sees the final colour. Instead the readable foreground is **derived in CSS** from whatever colour was adopted:

```css
/* assets/css/jetonomy-tokens.css */
@supports (color: oklch(from red l c h)) {
    :root {
        --jt-accent-fg:       oklch(from var(--jt-accent)       clamp(0, (0.57 - l) * 1000, 1) 0 h);
        --jt-accent-hover-fg: oklch(from var(--jt-accent-hover) clamp(0, (0.57 - l) * 1000, 1) 0 h);
    }
}
```

It pulls the lightness (`l`) out of the accent, snaps it across the `0.57` threshold, and forces chroma `0` - so the text resolves to pure black on light accents and pure white on dark ones. The threshold is derived, not chosen: sweeping `0.40–0.80` against 11 real theme accents, `0.56–0.58` is the only band where all 11 clear AA. Hover gets its own derivation because `--jt-accent-hover` is the accent mixed 85% toward black and can flip the black/white choice. Buttons consume it - `.jt-btn-fill { color: var(--jt-accent-fg); }` in `assets/css/jetonomy.css`. The rule is feature-gated behind `@supports`; engines without relative-colour syntax fall back to the plain `--jt-accent-fg: var(--jt-white)` default declared in `:root`, so behaviour is never worse than before. The theme's brand colour itself is untouched; only the text on top of it adapts.

See [`docs/standards/host-theme-color-adoption.md`](../standards/host-theme-color-adoption.md) for the full verified token map per theme, the compliance checklist, and guidance on themes that expose no recognizable brand token.

---

## `custom_css`: freeform CSS from the admin

When the `custom_css` setting contains text, it is appended to the inline style block after the palette and inherit-colors rules. The value is passed through `wp_strip_all_tags()` before output, so HTML markup is stripped but valid CSS (including `--jt-*` token assignments) is preserved.

This is the right place for a site owner to make one-off tweaks without a child theme - for example:

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

The `jetonomy_dynamic_css` filter fires after the full dynamic CSS string is assembled - container width, palette overrides (`palette_css()`), layout-density rules, and the admin `custom_css` - and before it is attached to the page via `wp_add_inline_style()`. Note that the host-theme colour-adoption chain and the font tokens are **not** part of this string: they are declared statically in `assets/css/jetonomy-tokens.css`, not injected inline.

Use it from a plugin (companion plugin, add-on) to append token overrides or scoped rules without requiring a child theme.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$css` | `string` | The assembled inline CSS string |
| `$settings` | `array` | The `jetonomy_settings` option array |

**Returns:** `string` - the modified CSS string.

**Source:** `includes/class-template-loader.php:264`

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
    // Only tighten radius on spacious communities.
    if ( 'spacious' === ( $settings['layout_density'] ?? '' ) ) {
        $css .= ':root { --jt-radius: 4px; }';
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

Both the static adoption chain and the admin accent override are declared on `:root`. A child-theme rule on `.jt-app` is more specific than `:root`, so it wins without `!important`; a child-theme rule on `:root` needs to load after the plugin's stylesheet (or carry higher specificity) to take precedence.

---

## Dark-mode rule

Never write per-component dark selectors. Dark mode overrides belong only in the `.jt-dark, [data-theme="dark"]` block in `assets/css/jetonomy-tokens.css`, reassigning the `--jt-*` root tokens on the class the host theme puts on `<body>`. Individual components inherit dark mode automatically because they reference the tokens:

```css
/* Correct */
.jt-card {
    background: var(--jt-bg);
    border: 1px solid var(--jt-border);
}

/* Wrong - breaks when a token value changes */
.jt-dark .jt-card {
    background: #1e1e1e;
}
```

---

## What's next

- [Template Overrides](./03-template-overrides.md) - adding CSS to overridden template files
- [Extend the Frontend](./17-extend-the-frontend.md) - injecting JavaScript that uses the same token values
- [Hooks Reference](./02-hooks-reference.md) - `jetonomy_before_content` for injecting markup that needs matching styles
