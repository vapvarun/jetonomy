# Free + Pro Lockstep Test Checklist (Rail A)

**When to run:** before merging ANY change that touches the shared `jetonomy`
Interactivity API store, `assets/js/view.js`, `assets/js/lib/optimistic.js`,
`window.jetonomyRest`, or the client-side router.

**Why:** `jetonomy-pro/assets/js/pro-view.js` calls `store('jetonomy', {...})` —
the SAME iAPI namespace as free `view.js`. They deep-merge into one runtime
store, so a change to the free store's state/actions shape can silently break Pro
(Private Messaging, Polls, Reactions, custom fields). Free-only testing is not
sufficient. Always test with **both plugins active**.

## Pre-flight
- [ ] `wp plugin list --status=active | grep jetonomy` shows BOTH `jetonomy` and `jetonomy-pro`.
- [ ] `wp jetonomy qa-actions` → all green (currently 230/230: REST + Model + Pro + Journey).
- [ ] Browser console open; auto-login via `?autologin=1`.
- [ ] Run each surface at desktop AND 390px (mobile).

## Free surfaces (shared store)
- [ ] **Vote** post up/down + reply up/down → score reconciles, button toggles, POST 200, no console error.
- [ ] **Bookmark** toggle → POST /bookmarks 200, state persists on reload.
- [ ] **Follow / unfollow** post and space → GET resolves sub-id, DELETE/POST 200, button flips.
- [ ] **Report** post/reply/user → prompt → POST /flags 201, "reported" toast.
- [ ] **Reply submit** → POST /replies 201; **delete reply** → DELETE 200, row removed.
- [ ] **Edit** post and reply → PATCH 200, content updates.
- [ ] **Pin / close / make-private** (admin) → PATCH/POST 200, state flips.
- [ ] **Move / merge / split** (admin) → navigates to the resulting topic.
- [ ] **Accept / unaccept answer** (Q&A space) → badge moves.
- [ ] **Idea status** (ideas space) → status pill updates.
- [ ] **Profile save** (edit-profile) → PATCH /users/me ok, redirect.
- [ ] **Search** (header overlay + post/space pickers + similar-topics) → GET /search 200.
- [ ] **Notifications** (header dropdown + page) → list loads, mark-read, delete.
- [ ] **Load More** (set a space's posts_per_page low to force it) → appends in place, no full reload.

## Client-side navigation (Phase 2) — both plugins active
- [ ] Nav bar Community / Search / Leaderboard → swaps with NO full reload, URL + title update.
- [ ] Click a space → swaps; **vote on a post there** → POST 200 (interactivity hydrated on swapped content).
- [ ] **Load More on a client-navigated space** → appends (URL unchanged, no `?pg=2`).
- [ ] Click a **topic (`/t/`) link** → FULL page load (route guard; needs prismjs).
- [ ] Focus lands on the new region; active-nav highlight updates.
- [ ] `href="#"` controls (search toggle), modified/new-tab clicks → NOT hijacked.

## Pro surfaces (merge into the same store)
- [ ] **Private Messaging** (`/messages/`) → conversation list + thread load, send message.
- [ ] **Polls** → vote on a poll, result bar updates, no double-vote.
- [ ] **Reactions** → react/unreact on a post/reply, count updates.
- [ ] **Custom fields** → render on profile + space; save via PATCH /users/me/fields.
- [ ] Any Pro action that lives in `pro-view.js` runs without "action not found" / undefined-state console errors.

## Sign-off
- [ ] 0 console errors across all surfaces, desktop + 390px.
- [ ] `wp jetonomy qa-actions` still green after the change.
- [ ] Record the run (date, commit) in the PR / card before merge.
