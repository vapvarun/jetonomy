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
- [ ] `wp jetonomy qa-actions` → all green (REST + Model + Pro + Journey). The gate is zero FAIL lines; qa-actions also fails on its own when a phase runs no checks.
- [ ] Browser console open; auto-login via `?autologin=1`.
- [ ] Run each surface at desktop AND 390px (mobile).
- [ ] **After the version bump, re-run the upgrade checks.** Dev branches keep the last release's version headers, so Pro's upgrade routine (`Jetonomy_Pro::run_upgrade()`) never runs on them. Once Free and Pro carry the new version, on a site still stamped with the previous one: `wp jetonomy qa-actions` (UG1-UG12 drive the 2.0.7 job conversion) and `composer test` (`tests/pro/UpgradeJobsTest.php`, `tests/pro/ProQueueShimTest.php`), then load one wp-admin page and confirm `jetonomy_pro_db_version` moved to the new version.
- [ ] **2.1.0 changelog in both readme.txt.** Neither Free nor Pro has its 2.1.0 entry yet: write both (action-prefix style) before tagging, including the job changes (kept jobs on deactivation, recovery after activation, webhook timeout filter, demo-data confirm). It must also say, in customer words: (1) "A rate limit of 0 now means that limit is off (sites that stored 0 under the old code were blocking every new member and now have no cap on that action)." (2) An empty number field on the settings screen saves the default, not 0, and an out-of-range number is saved as the nearest allowed value with a notice saying so (reputation points included). (3) The verification reminder defaults to 24 hours on a site that never saved it, and the "translation loaded too early" notice on the first load after an upgrade is gone. (4) A valid Pro licence no longer shows a renewal reminder, and Pro's notices show only on Jetonomy's own screens. (5) Deleting the plugins with data removal on now also removes the licence update cache.
- [ ] **Translations at release.** Regenerate the .pot (`grunt makepot`), then merge it into every shipped .po with `msgmerge --update --backup=none languages/<domain>-<locale>.po languages/<domain>.pot` (Free and Pro) so no msgid is missing (2.1.0 QA found 75 Free and 90 Pro missing), translate, compile .mo and JSON (`grunt i18n`), then `php bin/check-script-translations.php --fix` and `php bin/check-script-translations.php ../jetonomy-pro --fix` (`wp i18n make-json` mangles names ending in `dmin.js`). The build fails while either check fails.

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
      **Skip on a BuddyNext / WPMediaVerse site.** WPMediaVerse owns messaging there, so
      `Private_Messaging\Extension::is_dormant()` is true and the routes are deliberately not
      registered. Gating is at the LINK, not the route: `\Jetonomy\messaging_active()` is
      false, so free hides every Messages link, and `/community/messages/` 404s if typed by
      hand — correct, because nothing links to it. The dormancy explainer is a hidden
      WP-ADMIN page (Jetonomy → Conversations), not a frontend page (Basecamp 10150583760).
      Absent DM routes on such a site are correct, not a bug, and enabling the extension in
      `jetonomy_pro_extensions` does not change it.
- [ ] **Polls** → vote on a poll, result bar updates, no double-vote.
- [ ] **Reactions** → react/unreact on a post/reply, count updates.
- [ ] **Custom fields** → render on profile + space; save via PATCH /users/me/fields.
- [ ] Any Pro action that lives in `pro-view.js` runs without "action not found" / undefined-state console errors.

## Sign-off
- [ ] 0 console errors across all surfaces, desktop + 390px.
- [ ] `wp jetonomy qa-actions` still green after the change.
- [ ] Record the run (date, commit) in the PR / card before merge.
