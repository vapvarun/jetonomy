# Anonymous Posting (Pro) — Design Spec

- **Date:** 2026-07-09
- **Feature:** Anonymous posting & replying, gated as a Pro extension
- **Repos touched:** `jetonomy` (free — the author-render seam + flag column) and `jetonomy-pro` (the `anonymous-posting` extension)
- **Status:** Design approved (Approach A). Pending implementation plan.

## 1. Problem & Goal

Customers are asking to let members post topics and replies **anonymously** (name + avatar hidden from other members). We built no real feature for this before — the only prior trace is a stray `allow_anonymous` key in a `SpaceTest` round-trip assertion, which is not wired to anything.

Anonymity is a **privacy-grade promise**: once a member is told "this will be anonymous," no member-facing surface may ever leak their identity. The goal is a leak-proof implementation with an accountable reveal path for site admins only.

## 2. Product Decisions (locked)

| Decision | Choice |
|---|---|
| Identity storage | Store the **real `author_id`**; mask on display via an `is_anonymous` flag. Never authorless. |
| Content types | **Posts and replies** both. |
| Control scope | **Global master switch AND per-space opt-in** — both must be ON for a space to allow anonymous authoring. |
| Reveal UX | **Site admins only.** Space moderators only ever see "Anonymous." Admin reveal is an explicit action, and **every reveal is written to the activity log**. |
| Degradation | Pro inactive → no new anonymous content can be created; existing anonymous rows **stay masked** (free honors the flag). No retroactive de-anonymization. |

## 3. Architecture — Approach A (free seam + Pro driver)

The critical constraint: **free has no central author-render seam today.** `author_name` / `author_avatar` / `author_id` are rebuilt independently in each REST controller, the `functions.php` template helper, the RSS feed, search, notifications, and the BuddyPress integration. Masking must happen where identity is resolved — which is in **free**. Therefore this is a paired free + pro feature.

### 3.1 Free responsibilities (`jetonomy`)

1. **Flag column.** Add `is_anonymous TINYINT(1) NOT NULL DEFAULT 0` to `jt_posts` and `jt_replies` via a new idempotent migration (`includes/db/migrations/class-migration_1_7_0.php`, following the `Migration_1_6_0` pattern) and to the `CREATE TABLE` blocks in `includes/db/class-schema.php`. Whitelist the column in `Post::create()` / `Reply::create()` insert data so a `$data['is_anonymous']` set by a `before_create_*` filter is persisted.
2. **Central author resolver.** Introduce `Jetonomy\Author::for_display( int $author_id, ?object $object = null ): array` returning `[ 'id', 'name', 'avatar', 'url' ]`. When `$object->is_anonymous` is truthy **and** `apply_filters( 'jetonomy_author_can_reveal', false, $object, get_current_user_id() )` is false, it returns the generic anonymous identity: `id => 0`, `name => __( 'Anonymous', 'jetonomy' )`, `avatar => ` the existing silhouette URL, `url => ''`. Otherwise it returns the real author's identity (routed through `Avatar::display_url()`).
3. **Route every surface through the resolver** (the leak-audit list, §6): the `functions.php` template author helper, `class-posts-controller.php` and `class-replies-controller.php` REST author blocks, `class-search-controller.php`, `class-feed.php` (RSS `dc:creator`), notification actor rendering, and the BuddyPress activity integration.
4. **Hooks for Pro:** free already fires `jetonomy_before_create_post` / `jetonomy_before_create_reply` (write path), `jetonomy_composer_toolbar` (compose UI), and `jetonomy_admin_space_edit_tabs` / `_tab_content` (per-space setting UI). The only *new* free seam is the `Author::for_display()` resolver and its `jetonomy_author_can_reveal` filter. No new free UI.

### 3.2 Pro responsibilities (`jetonomy-pro`)

New extension `includes/extensions/anonymous-posting/class-extension.php` (extends `Jetonomy_Pro\Extension`, opt-in via the `jetonomy_pro_extensions` option, id `anonymous-posting`). It wires:

1. **Global setting** — `jetonomy_pro_anonymous_enabled` (bool). Rendered on the Pro settings tab (`jetonomy_admin_settings_tab_content`).
2. **Per-space setting** — an `allow_anonymous` boolean stored in the space `settings` JSON (no schema change; `Space::get_settings()` already round-trips arbitrary keys). UI added via `jetonomy_admin_space_edit_tabs` / `_tab_content`; also expose read/write on the space REST payload for app parity.
3. **Compose toggles** — a "Post anonymously" / "Reply anonymously" checkbox rendered via `jetonomy_composer_toolbar` (shared post+reply composer; `$_reply_to` distinguishes). Shown **only** when both gates pass for the target space.
4. **Write enforcement** — hook `jetonomy_before_create_post` / `jetonomy_before_create_reply`: if the request asked for anonymous AND global gate ON AND the target space's `allow_anonymous` ON AND the author is a logged-in member, set `$data['is_anonymous'] = 1`; otherwise force it to 0. Never trust the client flag alone.
5. **Reveal + logging** — on admin post/reply views, a "Reveal author" action (visible only when `current_user_can( 'manage_options' )`). Pro registers `add_filter( 'jetonomy_author_can_reveal', ... )` returning true only for site admins in an explicit-reveal context, and writes `ActivityLog::log( $admin_id, 'anonymous_author_revealed', $object_type, $object_id, [ 'real_author' => $author_id ] )` on each reveal.
6. **REST** — a Pro endpoint `POST /jetonomy-pro/v1/anonymous/reveal` (admin-only, nonce + capability) returning the real author for one object and logging the reveal — the API entry point mirroring the admin UI (three-entry-point rule).

## 4. Data Model

- **`jt_posts.is_anonymous`**, **`jt_replies.is_anonymous`** — `TINYINT(1) DEFAULT 0`. No index needed (never queried by; only read alongside the row already being fetched).
- **Per-space allow flag** — `settings.allow_anonymous` (bool) inside the existing space `settings` JSON. No column.
- **Global flag** — `jetonomy_pro_anonymous_enabled` option (bool).
- **Reveal audit** — reuses `jt_activity_log` via `ActivityLog::log()`. No new table.

Net schema change: **two nullable-safe columns on two existing free tables**, added idempotently. Everything else reuses existing storage.

## 5. Write Path (end to end)

1. Member opens composer in space S. Pro checks: global ON? `S.settings.allow_anonymous` ON? member logged in? If all yes, the "Post/Reply anonymously" checkbox renders.
2. Member submits with the box checked. Free `Post::create()` / `Reply::create()` runs `jetonomy_before_create_*`.
3. Pro's filter re-validates all gates server-side and sets `$data['is_anonymous'] = 1` (or forces 0). Client flag alone is never trusted.
4. Free persists the row with the real `author_id` and `is_anonymous = 1`.
5. All existing per-user logic (post count, rate limiting, edit-own, auto-join) continues to work because the real `author_id` is intact.

## 6. Display / Leak-Audit Surfaces (every one routes through `Author::for_display()`)

This list **is** the acceptance criteria for "leak-proof." Each must show "Anonymous" + silhouette for a flagged row when the viewer is not a revealing admin:

1. Feed / post card (`templates/partials/*`, `functions.php` author helper)
2. Reply card (`templates/partials/reply-card.php`)
3. REST posts (`class-posts-controller.php` — `author_name`/`author_avatar`/`author_id`/`author_url`)
4. REST replies (`class-replies-controller.php`)
5. REST search results (`class-search-controller.php`)
6. RSS feed `dc:creator` (`class-feed.php`)
7. Notifications actor (a reply/mention notification to the topic author must read "Anonymous replied", never the real name)
8. BuddyPress activity integration (`includes/integrations/class-buddypress.php`) — activity actor + permalink must not expose identity
9. Profile activity / author archives — an anonymous post must not appear under the real author's public profile stream
10. `@mention` autocomplete & mention notifications — an anonymous author is not surfaced as a mention target for that post
11. Vote/reaction attribution — an anonymous post's own author is masked in any "who posted" surface (voters lists are a separate concern and unchanged)

**Admin-side (site admin only):** moderation queue, single post/reply admin view — show "Anonymous" with a "Reveal author" action; revealing is logged.

## 7. Edge Cases & Rules

- **Edit / delete own anonymous content** — works unchanged: the member is the real `author_id`, so ownership checks pass; the item stays anonymous through edits.
- **Notifications to the anonymous author** — the author still receives replies/votes on their own anonymous post (they own it); only *outbound* identity is masked.
- **Mentions by an anonymous author** — allowed to mention others; but the mention notification's actor is "Anonymous."
- **Trust / gamification** — real `author_id` still accrues post count / trust / badges silently (server-side), but any *public* display of that activity respects the mask (§6.9).
- **Deactivation** — Pro off: composer toggle disappears, no new anonymous rows; existing rows stay masked by free.
- **Uninstall** — Pro uninstall does not touch free's `is_anonymous` column or existing masked rows (they remain masked). Only Pro's own option/setting is removed. Document in the Pro uninstall routine.
- **Migration safety** — column add is idempotent (`SHOW COLUMNS` guard), safe on re-run and on sites where dbDelta already added it.

## 8. Big-Site Readiness (per portfolio checklist)

- No new list/grid is introduced; the reveal action operates on a single object. The moderation queue that gains the "Reveal" action already paginates.
- No N+1: `Author::for_display()` operates on the row already loaded; anonymous rows short-circuit before any user lookup.
- The `is_anonymous` read adds one already-selected column — no extra query, no index needed.
- The reveal endpoint acts on one id; no unbounded scan.

## 9. i18n, A11y, Responsive, Dark Mode

- All new strings (`Post anonymously`, `Reply anonymously`, `Anonymous`, `Reveal author`, `Allow anonymous posts in this space`) wrapped in `jetonomy` (free strings) / `jetonomy-pro` (Pro strings) text domains, including the JS store keys (1.6.1 just did an i18n sweep — match it).
- Compose checkbox is a real labeled `<input type="checkbox">`, keyboard reachable; the "Reveal author" control is a `<button>` with an aria-label.
- Toggle + reveal styles use existing design tokens (dark-mode + RTL safe); no raw hex/px.

## 10. Manifests & Docs

- Update **free** manifest (`audit/manifest.json`) with the new `Author::for_display()` helper and `jetonomy_author_can_reveal` filter.
- Update **Pro** manifest with the `anonymous-posting` extension, its option, its per-space setting key, and the reveal REST route.
- Customer docs (free `docs/website/`): a short "Anonymous posting" page — how to enable globally + per space, and that admins can reveal. WooCommerce-style changelog bullets on release.

## 11. Testing / Verification

- **Unit** — `Author::for_display()` masking matrix: flagged/unflagged × admin/mod/member/guest.
- **Write path** — anonymous flag set only when all gates pass; forced to 0 when any gate off or when the space disallows; client-supplied flag never trusted.
- **Leak audit (integration + browser)** — for each of the 11 surfaces in §6, a flagged post by user A viewed as user B (member) and as a space moderator shows "Anonymous"; viewed as site admin shows the reveal path and logs it.
- **REST parity** — app endpoints return masked author for non-admin tokens.
- **Browser (Playwright MCP, incl. 390px)** — compose toggle visibility gating, anonymous render on feed/reply/profile, admin reveal + log entry.
- **Regression** — edit/delete own anonymous content; notifications to the author still arrive.

## 12. Out of Scope (YAGNI)

- Per-user "always post anonymously" preference.
- Letting an author later self-reveal an existing anonymous post.
- Anonymous voting/reactions (only authoring is anonymized).
- Anonymous private messages (separate extension; not in this scope).
