# Host-Theme Color Adoption Standard

**Status:** Normative. Every Jetonomy frontend surface MUST follow it, and it is
the reference implementation other Wbcom plugins copy.

## Principle

A plugin's frontend must read as **native to whatever theme the site owner runs**.
The owner picks a brand color once, in their theme's Customizer (or theme.json),
and the plugin adopts it automatically — no plugin-side color setting required,
no per-theme code. There is **no universal "brand color" token in WordPress**, so
we adopt the *active* theme's own brand token through a single `var()` fallback
chain that resolves to the first token the active theme actually defines.

Order of preference: **the active theme's own brand token → the WordPress
`primary`/`accent` preset slugs → a neutral plugin default.** Only one theme is
active at a time, so the other themes' tokens are undefined and fall through
cleanly. Because each link is `var(token, fallback)`, the chain also follows the
theme's **dark mode** for free when the theme flips its own token.

## The canonical accent chain

This is the single source of truth, defined once on `:root` in
`assets/css/jetonomy-tokens.css`. Adoption is unconditional — there is no toggle
gating it (see "Where it's wired").

```css
--jt-accent: var(--bx-color-accent,            /* BuddyX / BuddyX Pro 5.1+  */
             var(--reign-colors-theme,         /* Reign 8.0+                */
             var(--brand,                       /* BuddyNext                 */
             var(--wp--preset--color--primary,  /* "primary"-slug themes     */
             var(--ast-global-color-0,          /* Astra                     */
             var(--global-palette1,             /* Kadence                   */
             var(--theme-palette-color-1,       /* Blocksy                   */
             var(--nv-primary-accent,           /* Neve                      */
             var(--wp--preset--color--accent,   /* GeneratePress + "accent" slug */
             #0073aa)))))))));                   /* plugin default (unset sentinel) */
```

Tokens are **mutually exclusive per active theme**, so order only sets precedence
for the rare theme exposing two. Keep our own themes first, the WP-standard
`primary` slug next, then the big page-builder themes, then the `accent` slug,
then the hex default last.

## Verified host-theme brand-token map

Every entry below was confirmed against a live install by reading
`getComputedStyle(documentElement)` on the front end (not from memory).

| Theme | Market | Brand token | Verified value |
|---|---|---|---|
| BuddyX / BuddyX Pro 5.1+ | Wbcom | `--bx-color-accent` | customizer `site_primary_color` |
| Reign 8.0+ | Wbcom | `--reign-colors-theme` | active Site Skin brand |
| BuddyNext | Wbcom | `--brand` | TokenService brand |
| Astra | ~1M+ installs | `--ast-global-color-0` | `#046bd2` |
| Kadence | popular | `--global-palette1` | `#2B6CB0` |
| Blocksy | popular | `--theme-palette-color-1` | `#2872fa` |
| GeneratePress | popular | `--wp--preset--color--accent` (also `--accent`) | `#1e73be` |
| Twenty Twenty-Three | stock | `--wp--preset--color--primary` | theme palette `primary` |
| Any theme using a `primary` or `accent` palette slug | — | `--wp--preset--color--*` | theme palette |

## Graceful degradation + the universal override

Stock block themes that expose **no recognizable brand token** — e.g. Twenty
Twenty-Four / Twenty Twenty-Five use numbered `accent-1..6` slugs (TT5's
`accent-1` is `#FFEE58`, a low-contrast yellow that would fail WCAG as a button) —
must NOT be auto-adopted blindly. The chain deliberately falls to the neutral
plugin default (`#0073aa`). That value doubles as the "not set" sentinel:
`palette_tokens()` treats `#0073aa` as "no override, adopt the theme". Button
text stays readable regardless, because a runtime CSS contrast guard derives
`--jt-accent-fg` from whatever colour the chain resolves to (see the
`@supports (color: oklch(from ...))` block in `jetonomy-tokens.css`). The plugin
is never unstyled; it just shows its own clean brand.

For those themes the site owner has one reliable, theme-agnostic override:
**Settings → Appearance → Color Palette → set Accent Color** to anything other
than `#0073aa`. That emits `:root{--jt-accent:<chosen>}` via
`Template_Loader::palette_css()` and outranks the chain on any theme. There is no
inherit toggle to clear first — the `inherit_colors` setting was removed in 1.8.0
(it defaulted to on and silently discarded the picked colour).

Do NOT add numbered/decorative slugs (`accent-1`, `palette3`, etc.) to the chain
to chase auto-matching — a wrong-but-confident color is worse than a clean
default plus a one-field override.

## Where it's wired (Jetonomy)

1. `assets/css/jetonomy-tokens.css` — the `:root` token block (`--jt-accent`) and
   the `@supports` contrast guard (`--jt-accent-fg`). Source of truth;
   `grunt cssmin` regenerates `jetonomy-tokens.min.css`.
2. `includes/class-template-loader.php` — `palette_css()` emits a `:root{...}`
   accent override ONLY when the owner sets a non-sentinel accent colour; adoption
   itself is unconditional and lives in the static CSS above.
3. `includes/integrations/class-theme-integration.php` — an *optional* PHP bridge
   that resolves a few host themes' Customizer mods to hex literals for themes
   whose tokens aren't exposed as CSS vars. It must only map tokens it can read
   correctly; when a theme already exposes a usable CSS var (Reign 8.0+), the
   bridge returns empty and **defers to the chain** rather than emitting a stale
   literal.

`--jt-text`, `--jt-bg`, `--jt-border` follow the same shape but only adopt the
WP `contrast`/`base` presets before a neutral default — page-builder text/bg/
border slugs are not auto-adopted (too varied; risk of clashing with card UI).

## Verifying a new theme (do this, don't guess)

```
wp --path="…" theme activate <theme>
# front end, then in the browser console on a Jetonomy page:
getComputedStyle(document.documentElement).getPropertyValue('--jt-accent')
# compare to the theme's own brand token; confirm a .jt-btn-fill renders it
```

Add a theme to the chain only after this confirms (a) the exact token name and
(b) that the value is a contrast-safe action color.

## Section checklist (gate every release + every new plugin adopting this)

1. `--{prefix}-accent` resolves through the chain above, our themes first, hex last.
2. The same chain is mirrored in any inline/dynamic emission, not just the static CSS.
3. No numbered/decorative palette slugs in the chain.
4. A theme-agnostic owner override exists (inherit-off + accent setting).
5. The PHP bridge (if any) maps only correctly-readable tokens and defers to the chain otherwise.
6. New theme support is browser-verified (token name + contrast) before it lands.
7. `grunt cssmin`/RTL regenerated so the `.min` matches source.
