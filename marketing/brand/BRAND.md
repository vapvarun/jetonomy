# Jetonomy — Brand Sheet

> Source-of-truth brand reference for the Jetonomy media kit (1.5.0). Logo, color,
> type, and usage rules. All assets live under `marketing/brand/`. Nothing here is
> published anywhere — it feeds decks, the store page, docs, and social.

---

## 1. Logo

The mark is a **community of members** — two overlapping member silhouettes on a
gradient tile, reading as people, belonging, and conversation. The wordmark splits
**Jet** (ink) + **onomy** (accent gradient) so the brand color is always present in
the lockup, and nods to the "-onomy" (system/economy) half of the name.

### Files (`marketing/brand/logo/`)

| Variant | Source SVG | Raster (`png/`) | Use |
|---|---|---|---|
| App mark (gradient) | `jetonomy-mark.svg` | `jetonomy-mark-{16,32,48,64,128,180,192,256,512,1024}.png` | Primary icon everywhere |
| App mark (flat 1-color) | `jetonomy-mark-flat.svg` | `jetonomy-mark-flat-{256,512}.png` | One-color print, low-ink |
| Mono glyph (no tile) | `jetonomy-mark-mono.svg` | `jetonomy-mark-mono-{ink,white}-512.png` | Watermark, emboss, single-color stamp |
| Horizontal lockup (light bg) | `jetonomy-horizontal.svg` | `jetonomy-horizontal-{1x,2x}.png` | Site header, docs, light decks |
| Horizontal lockup (dark bg) | `jetonomy-horizontal-dark.svg` | `jetonomy-horizontal-dark-{1x,2x}.png` | Dark UI, footers, dark decks |
| Horizontal lockup (all-white) | `jetonomy-horizontal-white.svg` | `jetonomy-horizontal-white-2x.png` | Over photos / brand-color fills |
| Wordmark only | `jetonomy-wordmark.svg` | `jetonomy-wordmark-{2x}.png`, `…-white-2x.png` | When the mark is shown separately |
| Favicon | `jetonomy-favicon.svg`, `png/jetonomy-favicon.ico` | `jetonomy-favicon-{16,32,48}.png` | Browser tab, PWA, bookmarks |

`png/jetonomy-mark-180.png` = apple-touch-icon · `192`/`512` = PWA manifest icons ·
`1024` = app-store / store-listing tile.

### Clear space & minimum size

- **Clear space:** keep padding equal to ¼ of the tile height on all sides of the
  lockup. Nothing intrudes into that band.
- **Minimum size:** mark no smaller than **16px**; horizontal lockup no smaller than
  **140px** wide (below that, use the mark alone). The favicon SVG uses a slightly
  larger front member so it stays legible at 16px.

### Do / Don't

- **Do** use the gradient mark as the default. Use flat/mono only when a single color
  is required.
- **Do** put the dark-bg lockup on dark surfaces and the all-white lockup over photos
  or brand-color fills.
- **Don't** recolor the mark outside the approved palette, rotate it, add a drop
  shadow, stretch it, or box it in a second container.
- **Don't** recreate the wordmark in a different typeface — use the supplied files.

---

## 2. Color

### Core (mirrors the product `--jt-*` tokens)

| Token | Hex | Role |
|---|---|---|
| Accent / Primary | `#3B82F6` | Brand blue — CTAs, links, "Jet…" gradient start, mark gradient start (`--jt-accent`) |
| Accent Hover | `#2563EB` | Hover/active (`--jt-accent-hover`) |
| Gradient Start | `#3B82F6` | Mark tile + wordmark gradient, top-left |
| Gradient End | `#7C3AED` | Mark tile + wordmark gradient, bottom-right (violet) |
| Accent Light | `#60A5FA` | Mark gradient start on dark lockups |
| Accent Light 2 | `#A78BFA` | Mark gradient end on dark lockups |
| Ink (wordmark) | `#1F2733` | "Jet" in the wordmark, headings |
| Text (product) | `#1A1A1A` | Body text token (`--jt-text` light) |
| Text on dark | `#E5E5E5` | `--jt-text` dark mode |
| Surface | `#FFFFFF` | Light background (`--jt-bg`) |
| Surface Dark | `#171717` | Dark background (`--jt-bg` dark) |
| Muted text | `#6B7280` | Tagline, secondary copy |

Brand gradient (CSS): `linear-gradient(135deg, #3B82F6 0%, #7C3AED 100%)`.
On dark surfaces: `linear-gradient(135deg, #60A5FA 0%, #A78BFA 100%)`.

### Semantic (from product tokens)

`#16A34A` success (`--jt-success`) · `#CA8A04` warn (`--jt-warn`) ·
`#DC2626` danger (`--jt-danger`). Dark-mode danger `#EF4444`.

---

## 3. Type

- **Logo wordmark:** **Inter**, weight 800, letter-spacing tight (≈ −5 at 104px). The
  supplied SVGs declare an `Inter → Segoe UI → system-ui → -apple-system → sans-serif`
  fallback stack, so they render cleanly even where Inter isn't installed. For
  pixel-identical output, install Inter before re-exporting, or use the PNGs in `png/`
  (already rasterized).
- **Product UI:** Jetonomy inherits the active theme's font via the `--jt-font` token
  cascade (`--font-body` → `--wp--preset--font-family--body` → system). No bundled UI
  font by design.
- **Marketing copy / docs:** Inter (or system sans) for headings and body.

---

## 4. Tagline

**Community platform for WordPress.** Used in the horizontal lockup and as the
one-line descriptor. Sentence case, no period in UI chrome, period in prose.
