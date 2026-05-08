# Jetonomy Design System

**Status**: Living document. Every UI change must check this file first and either follow the rules or update the rules explicitly. No ad-hoc patches.

**Audience**: Developers, designers, and anyone reviewing PRs that touch `assets/css/*` or `templates/`.

---

## 1. Design principles

1. **Premium SaaS forum feel** - uniform hierarchy, generous breathing room, no visual surprises between pages.
2. **Mobile-first** - design decisions start at 390px. Tablet and desktop are progressive enhancements of the mobile layout.
3. **Accessibility is table-stakes** - every interactive element has a visible focus state, a 40×40px tap target, and an accessible name (label, `aria-label`, or `title`).
4. **One source of truth per concept** - one button component, one card component, one row component. No parallel implementations.
5. **Token-driven** - spacing, colors, radius, typography, motion all reference `--jt-*` custom properties. No hardcoded hex codes or px values inside component CSS unless explicitly listed as exceptions.
6. **Theme-adaptive** - the plugin inherits accent color and dark mode from the active WordPress theme via the token bridge. Don't fight the theme; augment it.
7. **No patch work** - if a fix needs to touch more than one selector or more than one media query, rewrite the component against the design system instead.

---

## 2. Breakpoints

Exactly three breakpoints. Do not invent new ones.

| Name | Width | When to use |
|---|---|---|
| **Mobile** | ≤ 640px | Phones portrait. Single-column layouts, icon-only navigation, compact typography. |
| **Tablet** | 641–1024px | iPad portrait, small laptops. Two-column where helpful, icon+label nav, full typography. |
| **Desktop** | ≥ 1025px | Desktop/laptop. Two/three-column, all affordances visible, keyboard-first. |

### Media query convention

All responsive rules live in **one `@media` block per breakpoint** at the bottom of `jetonomy.css`. Do not scatter tablet or mobile rules inline with their desktop counterparts.

```css
/* Desktop styles - base (no media query) */
.jt-row { grid-template-columns: 48px 1fr 80px 80px; }

/* All mobile/tablet adjustments go in the two consolidated blocks at
   the bottom of jetonomy.css - NOT next to the desktop rule above. */
```

### Specificity rule

When a desktop rule uses a compound selector like `a.jt-row` (specificity 0,1,1), the responsive override **must match or exceed** that specificity. Use `.jt-row, a.jt-row` or `.jt-row.jt-row` to win over tag-prefixed selectors. Not doing this is the #1 source of "it works on div but not on a" bugs.

---

## 3. Typography scale

All font sizes in `rem`. Root `font-size` is the theme's default (typically 16px).

| Role | Desktop | Mobile | Weight | Example |
|---|---|---|---|---|
| Page title | `1.75rem` (28px) | `1.25rem` (20px) | 700 | "Announcements" |
| Section title | `1.25rem` (20px) | `1.125rem` (18px) | 700 | "Top Members" |
| Card title | `1.0625rem` (17px) | `0.9375rem` (15px) | 600 | Post title in a row |
| Body | `1rem` (16px) | `0.9375rem` (15px) | 400 | Post content |
| Secondary/meta | `0.8125rem` (13px) | `0.75rem` (12px) | 400 | "3 weeks ago", author name |
| Caption / tag | `0.75rem` (12px) | `0.6875rem` (11px) | 500 | Tag pills |
| Stat label | `0.75rem` (12px) | `0.6875rem` (11px) | 500 uppercase | "REPLIES", "POSTS" |

**Rules**
- Never drop below `0.6875rem` (11px) - becomes illegible on small devices.
- Line-height: `1.5` for body, `1.35` for titles, `1.2` for stat numbers.
- Titles use `--jt-font-heading`, body uses `--jt-font`, numbers use `--jt-font-mono`.

---

## 4. Spacing scale

Use semantic spacing tokens. No arbitrary `px` values.

| Token | Value | Use for |
|---|---|---|
| `--jt-space-1` | 4px | Icon/text inline gap |
| `--jt-space-2` | 8px | Tight inline gap, badge padding |
| `--jt-space-3` | 12px | Row inner gap, form-group gap |
| `--jt-space-4` | 16px | Card padding, container padding mobile |
| `--jt-space-5` | 20px | Card padding desktop, post-head padding |
| `--jt-space-6` | 24px | Section gap, container padding tablet |
| `--jt-space-8` | 32px | Section gap desktop, large spacer |
| `--jt-space-10` | 40px | Page heading margin |

**Row-gap for wrapping flex rows** = column gap (so wrapped items are visually balanced).

**Rule**: If a new component needs a padding/margin not on the scale, either (a) pick the nearest token, or (b) add a new token to the scale and document it here.

---

## 5. Tap targets + focus

- **Minimum tap target**: 40 × 40px. Measured via `min-height` on buttons and anchor targets.
- **Minimum spacing between adjacent tap targets**: 8px (preferably 12px).
- **Focus ring**: `outline: 2px solid var(--jt-accent); outline-offset: 2px;` on `:focus-visible` for all interactive elements. Never remove focus outlines without a replacement.
- **Hover states**: subtle background change (`var(--jt-bg-hover)`) or border color change. Never rely on hover alone for state indication.

---

## 6. Color system

Already fully tokenized via `--jt-*` custom properties. See "CSS Token Rules" in `CLAUDE.md`. Key rules:
- Never write hex or RGB values in component CSS
- Dark mode lives only in `.jt-dark .jt-app { ... }` - reassigning root tokens, not per-component selectors
- Use `color-mix(in srgb, var(--jt-foo) X%, transparent)` for alpha variants, with a hex fallback

---

## 7. Radius + motion

| Token | Value | Use for |
|---|---|---|
| `--jt-radius-sm` | 4px | Small inline badges |
| `--jt-radius` | 8px | Cards, buttons, inputs |
| `--jt-radius-lg` | 12px | Panels, modals |
| `--jt-radius-full` | 9999px | Pills, avatars |
| `--jt-dur` | 0.15s | Hover transitions |
| `--jt-ease` | cubic-bezier(0.4, 0, 0.2, 1) | Ease curves |

---

## 8. Icons

- Source: Lucide (`assets/icons/*.svg`)
- Render via `jetonomy_echo_icon( $slug, $size )`
- Standard sizes: 14 (inline with text), 16 (inline with body), 18 (nav), 20 (button icon), 24 (feature)
- Stroke width: 2 (Lucide default)
- Color: inherits from parent via `currentColor`

---

## 9. Component patterns

### Buttons

Variants (exactly these, no others):

| Variant | Class | When |
|---|---|---|
| Primary (filled) | `.jt-btn.jt-btn-fill` | Primary CTA: Post Topic, Save, Submit, + New Post |
| Secondary (ghost) | `.jt-btn.jt-btn-ghost` | Secondary actions: Cancel, Follow, Subscribe |
| Danger | `.jt-btn.jt-btn-danger` | Delete, Ban (always with confirmation) |
| Small | append `.jt-btn-sm` | Inline actions in cards/rows |

**Rules**
- Minimum height 40px, padding `var(--jt-space-2) var(--jt-space-5)`
- Primary CTAs on mobile get `width: 100%` when inside `.jt-bar` or `.jt-form-actions`
- Always have `icon + label` for primary CTAs - never icon-only
- Secondary icon-only buttons (Vote, Share, Bookmark, More): 40×40px square with `title` + `aria-label`

### Cards

One canonical class: `.jt-card` (or semantic variants that extend it - e.g., `.jt-profile`, `.jt-post`, `.jt-idea`). Each card has:
- `border: 1px solid var(--jt-border)`
- `border-radius: var(--jt-radius)`
- `background: var(--jt-bg)`
- `padding: var(--jt-space-4)` (mobile) / `var(--jt-space-5)` (desktop)
- No box-shadow by default - use hover shadow for elevation

### Rows (`.jt-row`)

Grid-based horizontal layout for lists. **One grid template per breakpoint**, never inline variations.

| Breakpoint | Grid template | Gap | Padding |
|---|---|---|---|
| Desktop | `48px 1fr 80px 80px` | `12px` | `12px 16px` |
| Tablet | `48px 1fr 80px 80px` | `12px` | `12px 16px` (same as desktop) |
| Mobile | `36px 1fr 48px` (hide last stat) | `8px` | `10px 12px` |

Children:
- `.jt-votes` - first column, 36–48px wide
- `.jt-row-main` - title + `.jt-row-sub` meta, `min-width: 0` for text truncation
- `.jt-row-stat` - right-aligned stats, last one hides on mobile

Anchor-wrapped rows MUST use the same selector compound as the override: `.jt-row, a.jt-row { ... }`.

### Meta line (`.jt-meta`)

Horizontal flex row of metadata items (author + trust + time + tags).

```css
.jt-meta {
  display: flex;
  align-items: center;
  gap: 10px 12px;
  flex-wrap: wrap;              /* Wrap to next row when cramped. */
  color: var(--jt-text-secondary);
}
.jt-meta > * {
  white-space: nowrap;          /* Never break individual items mid-word. */
  flex-shrink: 0;
}
.jt-meta > a {
  min-height: 32px;             /* Accessible tap target inside a flex row. */
  display: inline-flex;
  align-items: center;
}
```

This pattern applies to **every** meta line in the plugin - post header, reply card, user profile, topic row sub, search result.

### Tags + pills

- `.jt-tag` - semantic tag pill. `font-size: 0.75rem` desktop, `0.6875rem` mobile. `padding: 2px 8px`. `border-radius: var(--jt-radius-full)`.
- `.jt-badge-*` - status badges (private, resolved, closed, draft, scheduled). Same shape as tag pill, different background colors.
- `.jt-tl` - trust level circle. `18×18px` desktop, `16×16px` mobile. Never word-wraps.
- `.jt-level-tag` - full profile "Level X" badge. `white-space: nowrap`, `display: inline-block`.

### Community nav

- Desktop: icon + label horizontal, no scroll, fits 6+ links.
- Tablet (≤1024px): scroll fallback (overflow-x auto, hidden scrollbar), font-scale widget hidden.
- Mobile (≤640px): icon-only with `title` tooltip + `aria-label`, labels hidden via `.jt-nav-label { display: none }`.

Every link needs:
```html
<a href="..." title="Label" class="...">
  <?php jetonomy_echo_icon( 'icon-slug', 18 ); ?>
  <span class="jt-nav-label">Label</span>
</a>
```

### Bottom padding for themes

`.jt-app { padding-bottom: 80px; }` on ≤640px to make room for themes that inject a fixed mobile bottom tab bar.

---

## 10. Page layout patterns

### Full-width container

Default wrapper: `.jt-container` with `max-width: var(--jt-container-width, 1200px)` and responsive padding (`var(--jt-space-4)` mobile / `var(--jt-space-6)` tablet / `var(--jt-space-8)` desktop).

### Two-column with sidebar

Use `.jt-two-col` grid `[main] 1fr [sidebar] 280px` on desktop/tablet, single column on mobile. Sidebar stacks below main.

### Narrow single-column (forms)

Use `.jt-narrow` with `max-width: 720px` centered. Used for new topic, edit profile, search.

### Grid of cards

Use `grid-template-columns: repeat(auto-fill, minmax(260px, 1fr))` for space/category cards. Single column on mobile.

---

## 11. Navigation hierarchy

1. **Theme header** - site logo, global search (out of plugin scope).
2. **Community nav** (`.jt-community-nav`) - Jetonomy's internal nav (Community, Search, Leaderboard, Profile, Moderation, Messages). Icons + labels.
3. **Breadcrumb** (`.jt-breadcrumb`) - Home / Category / Space / Thread. Truncated with ellipsis on mobile.
4. **Sort tabs** (`.jt-sort-tabs`) - Latest / Popular / Unanswered. Text-only (never icon).
5. **Page action** (`.jt-bar`) - primary CTA button (+ New Post, Submit). Full width on mobile.
6. **List / grid** - the actual content (topic rows, space cards, etc.).

Every frontend page follows this order top-to-bottom. Don't reorder or skip levels.

---

## 12. Tooltip policy

- Use native `title` attribute for all icon-only buttons
- Also set `aria-label` for screen-reader compatibility
- For state-toggling buttons (Follow/Following, Bookmark/Remove), **update both `title` and `aria-label` in the IA action** when state changes
- Never rely on tooltip alone for critical information - tooltips are a hint, not documentation

---

## 13. Visual hierarchy rules

**Step-down pattern**: title > body > meta > caption. Each step should be clearly smaller AND lighter than the previous:

- Title: `var(--jt-text)` (full black), `font-weight: 700`, `font-size: 1.0625rem+`
- Body: `var(--jt-text)`, `font-weight: 400`, `font-size: 0.9375rem+`
- Meta: `var(--jt-text-secondary)`, `font-weight: 400`, `font-size: 0.75rem+`
- Caption: `var(--jt-text-tertiary)`, `font-weight: 400`, `font-size: 0.6875rem+`

Never render secondary content at the same size as the title.

---

## 14. Anti-patterns

Things we will catch and reject in review:

- ❌ Inline `onclick="..."` attributes inside `.jt-app` (breaks Preact/IA hydration)
- ❌ Hardcoded hex colors in component CSS
- ❌ Hardcoded px values for spacing (use tokens)
- ❌ New media queries outside the two canonical blocks
- ❌ `position: absolute` on buttons without a mobile fallback
- ❌ Flex rows without `flex-wrap` when they contain text that might not fit
- ❌ Tap targets below 40×40px
- ❌ Icon-only buttons without `title` AND `aria-label`
- ❌ Button variants outside the 4 canonical ones (primary / ghost / danger / sm)
- ❌ Row templates that don't match `.jt-row, a.jt-row` specificity
- ❌ CSS that fights the theme's tokens instead of extending them

---

## 15. Review checklist

Before merging any CSS or template change:

- [ ] Typography uses scale tokens, not arbitrary sizes
- [ ] Spacing uses `--jt-space-*` tokens
- [ ] Colors use `--jt-*` tokens
- [ ] New component has a mobile variant tested at 390px
- [ ] New component has a tablet variant tested at 768px
- [ ] New component has a desktop variant tested at 1280px
- [ ] All interactive elements have `min-height: 40px` on mobile
- [ ] All icon-only buttons have `title` and `aria-label`
- [ ] Flex rows with text have `flex-wrap: wrap` and children `white-space: nowrap`
- [ ] No new `onclick` inline attributes
- [ ] Responsive overrides match desktop specificity (`.foo, a.foo` when needed)
- [ ] No new media queries outside the two canonical blocks
- [ ] Dark mode inherits correctly via the `--jt-*` bridge (no per-component dark selectors)
- [ ] Playwright screenshot at 390px + 768px + 1280px attached to PR

---

## 16. Enforcement

This doc is the source of truth. If you disagree with a rule, **update the doc first** with a rationale, then change the code. Don't write code that contradicts the current doc.
