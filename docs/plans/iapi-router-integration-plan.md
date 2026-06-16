# Jetonomy — iAPI Client-Side Router Integration: Safety-First Plan

**Branch:** `1.5.0-dev` (free + pro)
**Origin:** Luis Herranz (Automattic, co-creator of the Interactivity API) offered help integrating the iAPI client-side router into Jetonomy (Crisp, 2026-06-16).
**Guiding principle:** Do NOT break what already works. Every phase is independently shippable and reversible, gated by a free+pro test pass before the next phase starts. No big-bang rewrite.

---

## 1. Current architecture (verified on 1.5.0-dev)

- **iAPI already in use.** `assets/js/view.js` (3,228 lines) registers ONE store `store('jetonomy', {...})` and drives voting, sorting, load-more, notifications polling.
- **Pro merges into the SAME store.** `jetonomy-pro/assets/js/pro-view.js` also calls `store('jetonomy', {...})` — namespace deep-merge. Any change to the free store shape can break Pro (Private Messaging, Polls, Reactions).
- **Navigation today = full-page loads.** Pagination is hand-rolled `fetch()` + DOMParser append in `pagination-frontend.js`, with real `<a href="?pg=N">` for no-JS fallback (Basecamp #9860293843).
- **21 routes, per-route conditional script enqueues** (`includes/class-template-loader.php`):
  - global: `jetonomy-view` (module), `jetonomy-data`, `optimistic`, `smart-dropdown`, `modals`
  - `new-space` -> `new-space.js`; `edit-space` -> `space-edit.js`; `notifications` -> `notifications-page.js`
  - `space-members` -> `space-members.js`; `moderation`/`space-moderation` -> moderation resolver; `post` -> prismjs
- **REST is a consistent `{data, meta}` envelope** behind one helper: `window.jetonomyRest.restFetch()` returns `{ ok, status, data }` and owns nonce + auto-refresh. Adoption is partial (~61 raw `fetch()` remain).
- **WP is on latest** -> the iAPI router API is available. Availability is NOT the risk; integration is.

---

## 2. Audit results (wp-plugin-qa MCP) — read critically

- `rest-js-contract`: 22 "failures" — **all false positives.** The static checker compares properties against the controller's TOP-LEVEL keys `[data, meta]` and cannot see into the `data` envelope; JS legitimately reads `res.data.slug` etc. Spot-verified on `new-space.js` + `restFetch`. No action required beyond optionally adding JSON contract fixtures later.
- `wiring-completeness`: 3 "half-wired" settings (`action_type`, `jetonomy_bp_broadcast`, `viewport`) — **low confidence.** Checker only scans `templates/` for reads; these are consumed in `includes/` PHP or are non-setting params. Verify-then-dismiss; not blockers for this work.
- **Conclusion:** no new blocking bugs. The audit confirms a clean, consistent REST envelope — good foundation.

---

## 3. Risk register (what could break)

| # | Risk | Severity | Mitigation |
|---|------|----------|------------|
| R1 | Free store shape change breaks Pro (shared `jetonomy` namespace) | **Critical** | Free+Pro lockstep test on every store touch (Rail A) |
| R2 | Client-side nav to a route whose per-route JS was never enqueued (e.g. moderation queue dead) | **Critical** | Solve per-route script loading BEFORE multi-route router (Rail B) |
| R3 | Optimistic updates drift from server truth (vote counts, moderation holds, trust gating) | High | Keep server reload as fallback; only convert flows with a verified client mirror |
| R4 | Nonce goes stale across long-lived navigations -> 403 on writes | High | restFetch already auto-refreshes nonce; consolidate first (Phase 1) |
| R5 | REST consolidation removes moderation.js's deliberate no-restFetch fallback | Medium | Preserve the fallback branch; migrate file-by-file with the pair test |
| R6 | No-JS / SEO fallback lost (real `<a href>`) | Low | Router must only intercept real links; verify with JS disabled |

---

## 4. Phased rollout (each phase shippable + reversible)

### Phase 0 — Safety rails (do first, no user-facing change)
- **Rail A:** Free+Pro lockstep test plan/checklist (Card: lockstep). Establish the "test both together" gate.
- **Rail B:** Solve per-route script loading under client-side navigation (Card: per-route). Decide: (a) register all interactive scripts as script modules the router can pull on demand, or (b) scope the router to route-groups that share a script set. This gate MUST pass before any multi-route navigation ships.

### Phase 1 — REST consolidation (low risk, high payoff)
- Migrate the ~61 raw `fetch()` to `restFetch`, **preserving** the moderation.js fallback branch (R5).
- Outcome: one choke point for nonce/refresh -> R4 largely solved before the router arrives.
- Gate: free+pro smoke (vote, post, reply, moderate, ban, search, media upload) green.

#### Phase 1 — STATUS (2026-06-16, branch 1.5.0-dev)
Migrated 10 frontend `fetch` -> `restFetch` across 3 files; each declares the `jetonomy-rest` enqueue dependency:
- `assets/js/space-edit.js` (2: media upload, space PATCH) — VERIFIED: `PATCH /spaces/164` -> 200, no console errors.
- `assets/js/composer.js` (2: instant search, @mention suggest) — loads clean (no console errors, eslint clean); @mention path NOT triggered via Playwright (contenteditable harness limitation) — same proven mechanism, flagged for manual re-test.
- `assets/js/header.js` (6: notif list, mark-all-read, mark-one, search, hover-by-id, hover-by-login) — VERIFIED: `GET /notifications?limit=5` -> 200, no console errors. Header search overlay intercepted by theme form during test; mechanism proven by notifications 200.

Behavior preserved: distinct network-vs-HTTP error messaging via `res.status === 0`; `res.ok` checks replace `.catch` for loadFail/hide paths. Dead `nonce` var removed from composer.js mention module.

**Deliberately EXCLUDED (verified intentional, do not migrate):**
- `assets/js/login-block.js` (4 `/auth/*`) — comment at line 102 "Deliberately NO X-WP-Nonce; endpoints are public"; routes use `auth_public_write()`; block is dependency-free by design so it renders on non-community pages where `jetonomy-rest` isn't enqueued. Adding the dep would break login there. `restFetch`'s 403-refresh doesn't apply to public auth endpoints.
- 6 admin AJAX files (`admin-*.js`, `setup-wizard.js`) — admin-ajax is acceptable per Feature Acceptance Rule 2.
- `pagination-frontend.js` (fetches HTML, not JSON), `moderation.js:102` (the intentional fallback branch).

**Phase 1b (view.js — shared Pro store, R1) — COMPLETE (2026-06-16):**
All 36 view.js raw `fetch()` migrated to `window.jetonomyRest.restFetch` across 8 commits, each browser-verified free+Pro before commit. view.js now has ZERO raw fetch. Frontend-wide: every customer-facing surface (view store, composer, header, space-edit) is on restFetch; only intentional exclusions remain (login-block public auth, admin AJAX, pagination HTML, moderation fallback). Verified actions: voting, bookmark, pin/unpin, follow post/space (GET+POST+DELETE), report (201), reply submit (201) + delete (200), togglePrivate (PATCH), profile save (PATCH /users/me), similar-topics search (GET). optimistic.js dual-contract shipped + terser-rebuilt. Commits: 9a78c7e, 0e44a63, 18a7985, f77b325, 2b3a4f8, 20569d4, 656dd0f.

--- original in-progress notes below (historical) ---

**Phase 1b (view.js — shared Pro store, R1) — IN PROGRESS:**
- view.js has 36 raw `fetch`: 10 `optimistic()`-wrapped (Category A) + ~23 direct generator/await (Category B) + ~3 edge.
- Structural unblocker DONE: `optimistic.js` now accepts both a native `Response` and a `restFetch` result `{ok,status,data}` (duck-typed on `.json`), backward compatible. `optimistic.min.js` must be rebuilt with terser — grunt `uglify` globs `assets/js/*.js` only, NOT `lib/` (pre-existing Gruntfile gap; worth fixing separately).
- DONE + verified (free+Pro): vote cluster (voteUp/Down, voteReplyUp/Down) -> restFetch. `POST /posts/834/vote` 200, score 5->6->5, no console errors. Commit 9a78c7e.
- REMAINING (32 sites) — migrate with the same recipe, verify per feature free+Pro:
  - Category A (6 left): subscriptions toggle, bookmarks, pin, close, accept-reply (×2), idea-status.
  - Category B (~23): subscription GET/DELETE/POST, flags (create/list), post fetch, move/merge/split, reply edit/delete, link-preview, space subscribe, instant search.
  - NOTE: view.js is a SCRIPT MODULE; it cannot declare `jetonomy-rest` (classic) as a dep — relies on runtime availability (already the case; view.js used restFetch pre-existing). Confirm `jetonomy-rest` is enqueued on every route view.js runs.
  - Verification matrix (free+Pro each): voting (done), subscribe/unsubscribe, bookmark, pin/close, accept answer, idea status, flag, move/merge/split, reply edit/delete, link preview.

Pre-existing inconsistency noted: `space-members.js` uses `restFetch` but was enqueued without the `jetonomy-rest` dep (works only via global load) — left as-is, fold into 1b.
Cleanup TODO (lint:js): leftover unused `apiBase`/`nonce` locals elsewhere are pre-existing, not from this change.

#### Phase 2 + Rail B — DONE (2026-06-17, commit 085f732): working client-side navigation
Shipped, browser-verified free+Pro. Root-cause of the spike blocker: the region element needs BOTH `data-wp-interactive` AND `data-wp-router-region` — with only the latter the router didn't recognise it and mangled the diff. Fix:
- `@wordpress/interactivity-router` added as a dynamic dep of jetonomy-view.
- View wrapped in `<div data-wp-interactive="jetonomy" data-wp-router-region="jetonomy/main">` (grid on inner .jt-two-col, so no layout impact).
- `data-wp-on--click="actions.navigate"` delegated on #jetonomy-app — wires every internal link, no per-template edits.
- `navigate` action = the Rail B guard: client-navs ONLY the global-bundle routes (home, /search/, /leaderboard/, /category|tag|u|s/{slug}); everything needing a per-route script full-loads. Bails on anchors/modified/new-tab/cross-origin; falls back to location.href on error; real <a href> preserved.

Verified: home<->leaderboard<->space swap with NO reload (window marker survives), URL+title update, region renders, **interactivity preserved on swapped content (vote POST 200 on a client-navigated space)**, /t/ post link FULL-loads (guard). 0 console errors. qa-actions 230/230.

REMAINING GAP (load-more + a11y card 10000879711): the iAPI store (view.js) auto-hydrates swapped content, but CLASSIC scripts do NOT re-bind after a client nav — `pagination-frontend.js` (Load More) and `header.js` handlers won't attach to router-swapped views. Either convert them to modules that re-init on navigation, or re-run their init in a router-navigation hook. Also pending: active-nav highlight + a11y focus management on navigation. This is the next slice.

#### Phase 2 — SPIKE RESULT (2026-06-17): FEASIBLE, with a region-placement blocker
Proven live (browser): a `navigate` action that dynamic-imports `@wordpress/interactivity-router` + a `data-wp-router-region="jetonomy/main"` wrapper + 3 wired same-script nav links produced real client-side navigation — NO full reload (window marker survived), URL + document title + active-nav all updated, 0 console errors. Confirms the iAPI router drives classic server-rendered PHP (no block requirement) — resolves R1's biggest unknown.

BLOCKER: with the region placed as a sibling of `.jt-community-nav` + a `<template>` inside `.jt-container`, navigation DROPPED the region content (destination view rendered empty) and the diff also touched the nav (active state changed) — i.e. region scoping was wrong, likely a Preact positional-diff mismatch. Fix direction: header/nav OUTSIDE the region's parent; region = clean consistent subtree; confirm destination region is matched+inserted; maybe explicit `data-wp-interactive` on the region or `attachTo`. Prototype code was REVERTED (uncommitted) — empty-content state breaks nav, not shippable until region structure is fixed. This region/layout restructure overlaps Rail B.

Working recipe (mechanically confirmed): (1) `array('id'=>'@wordpress/interactivity-router','import'=>'dynamic')` in jetonomy-view module deps; (2) defensive `navigate` generator (sync href read, skip modified/new-tab/cross-origin, preventDefault, dynamic-import, fall back to location.href on error); (3) `data-wp-router-region` wrapper; (4) wire only same-script-set links.

### Phase 2 — Router spike + single-pair prototype
- Prototype `actions.navigate` between TWO same-script-set views (e.g. home feed -> single topic) on `1.5.0-dev`.
- Verify: scroll/focus, no-JS `<a href>` fallback (R6), no console errors, Pro active.
- This is where we take Luis up on a minimal example. Do NOT widen beyond the shared-script route-group yet.

### Phase 3 — Optimistic updates (replace reload-after-action incrementally)
- Convert `window.location.reload()` flows to optimistic only where a verified client mirror exists; leave reload as the fallback for moderation-hold / trust-gated flows (R3).
- One flow per PR, each with a free+pro test.

### Phase 4 — Widen router coverage
- Extend navigation to more route-groups only after Rail B's per-route loading is proven. Re-test the full route matrix with Pro active.

### Non-goal / Not now
- `view.js` module split — optional, opportunistic only (already in "Not now"). The iAPI store-merge makes this safe to do piecemeal later; not required for the router.

---

## 5. Definition of done per phase
- Free + Pro tested together (Rail A checklist).
- Browser-verified at desktop + 390px.
- No-JS fallback still works.
- No regression in the route matrix smoke.
- contract-audit + smoke green before any tag.

---

## 6. Card mapping (Basecamp, project 46596502)
- Spike (10000877331), Prototype (10000878069) -> Phase 2
- Context integrity / nonce (10000879201) -> Phase 1 + 4
- Load-more + a11y/SEO (10000879711) -> Phase 2/4
- Reload -> optimistic (10000893184) -> Phase 3
- REST consolidation (10000893795) -> Phase 1
- view.js refactor (10000894713) -> Not now (optional)
- **NEW Rail A — Free+Pro lockstep test plan -> Phase 0**
- **NEW Rail B — per-route script loading under router -> Phase 0**
