# Plan: Post/Reply header — dark-mode + mobile compactness

Status: DRAFT for approval. Branch 1.4.4-dev. Diagnosed live at 390px under Reign dark.

## What's actually wrong (root-caused, not guessed)

The reported symptoms — invisible post title, light-green accepted-reply header, low-contrast
meta, "2 months ago" wrapping to 3 lines, "Accepted/Resolved" pill running off the card — are
**two independent problems**, not five:

### Problem 1 (the big one): dark mode never reaches the Jetonomy app
- Reign is in dark mode → `<body class="... dark-scheme">`. Verified.
- Jetonomy's app element has **no `.jt-dark`** → every `--jt-*` token resolves to its LIGHT
  value while sitting on Reign's dark background.
- Proof: post title computes `color: rgb(32,38,46)` (near-black = light-mode `--jt-text`) on a
  dark surface → invisible. Accepted head uses opaque light `--jt-success-light` (#dcfce7) → the
  light-green block. Meta uses light `--jt-text-tertiary` → low contrast.
- Root cause: `assets/js/dark-mode-mirror.js` watches `darkClasses = ['wp-dark-mode-active',
  'dark-mode', 'theme-dark']` and toggles `.jt-dark`. Reign's class is **`dark-scheme`**, which
  is not in the list. The script *is* enqueued under Reign (theme-integration.php:122-124) — it
  just never matches, so `.jt-dark` is never added.
- **Fix A (one line + audit):** add `'dark-scheme'` (Reign) to `darkClasses`; audit the other
  supported themes' real dark classes (BuddyX, wp-dark-mode variants) and include them. This
  single fix makes the ENTIRE app adapt — title, accepted head, meta, every surface — because
  they already use `--jt-*` tokens that have correct dark values. No per-element color patching.
- Verify: under Reign dark, `.jt-dark` lands on body, title is light, accepted head is the dark
  translucent green, meta passes contrast. Re-check BuddyX + a light theme for no regression.

### Problem 2 (independent): the header isn't compact at mobile (≤640px)
Reproduces even in light mode at 390px. The header meta row is a single non-wrapping flex line
with too many items, so it overflows and the timestamp gets squeezed into a 3-line wrap.

Two surfaces share the pattern: the single-post header (`templates/views/single-post.php` ->
`.jt-post-head`) and the reply head (`templates/partials/reply-card.php` -> `.jt-reply-head`).

**Fix B — mobile header design (what's visible + how it compacts):**

Visibility priority on mobile (most → least important):
1. Title (single post) — must be prominent and high-contrast. Never clipped.
2. Author name + avatar.
3. Status (Accepted / Resolved / idea-status) — keep, but compact.
4. OP badge.
5. Timestamp — lowest priority; may shorten or hide at the narrowest widths.

Layout rules:
- `.jt-post-head` / `.jt-reply-head`: `flex-wrap: wrap` + `row-gap` so nothing overflows; items
  reflow to a second line instead of clipping.
- Status pills (`.jt-accepted-tag`, idea/resolved pill): make compact — smaller padding/font,
  `flex-shrink: 0`, and an **adaptive translucent** background
  `color-mix(in srgb, var(--jt-success) 12%, transparent)` (with hex fallback) instead of the
  opaque `--jt-success-light`. This matches the existing `.jt-accepted-callout` pattern and is
  legible in light AND dark regardless of Problem 1.
- `.jt-reply-time` / post meta time: `white-space: nowrap` so "2 months ago" stays one line.
  Per your steer, at ≤480px shorten or hide the timestamp on the accepted/reply head to free
  space (keep it in the post header where there's room). Decide: shorten ("2mo") vs hide.
- The inline "Accepted" tag duplicates the pinned "ACCEPTED ANSWER" callout above the list — on
  mobile, prefer the compact pill and let the callout carry the full label.
- Title: ensure `.jt-post-title` has a mobile size that fits 390px without the Follow button
  overlapping (the Follow button currently sits over the title region — make the header a
  wrapping flex so title and Follow stack).

## Files
- `assets/js/dark-mode-mirror.js` (+ rebuild .min) — Fix A.
- `includes/integrations/class-theme-integration.php` — confirm enqueue covers the dark classes; no change expected beyond the list if detection is JS-side.
- `assets/css/jetonomy.css` (+ rebuild .min/RTL) — Fix B: `.jt-post-head`, `.jt-reply-head`,
  `.jt-reply-time`, `.jt-accepted-tag`, status pills, `.jt-post-title` mobile.
- `templates/views/single-post.php`, `templates/partials/reply-card.php` — only if markup needs
  a wrapper/order change for the stack; prefer CSS-only.

## Verify (per the verify-per-item rule)
- 390px + Reign dark: title visible/high-contrast; accepted head dark translucent green; meta one
  line; "Accepted/Resolved" pill compact, not clipped; Follow doesn't overlap title.
- 390px + light theme: same layout, light tokens, no regression.
- Desktop: unchanged.
- Re-check BuddyX dark (the other mirrored theme).

## Open decisions for you
1. Timestamp at ≤480px on the reply/accepted head: **shorten** ("2mo") or **hide**?
2. Keep the inline "Accepted" pill on mobile, or rely on the callout label + green border only?
3. Scope: ship Fix A (dark propagation) on its own first (it's the highest-impact, lowest-risk,
   and unblocks correct colors everywhere), then Fix B (mobile compactness) as a second pass?
