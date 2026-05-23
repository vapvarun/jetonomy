# Jetonomy 1.4.4 - Journey Map (canonical, free/Pro-tagged, function + UX)

Date: 2026-05-23. Branch: 1.4.4-dev. This is the authoritative journey index for 1.4.4.
It reflects every fix shipped this cycle and is meant to drive testing (function AND UX), not sit stale.

**How it was built:** combo-mode journey audit (4 slices) + free-only journey pass
(Pro deactivated: owner + member) + a browser UX sweep at 390px in Reign dark + a
pre-tag verification walk against the canonical fix list. Detailed three-lens findings
live in the per-slice docs:
`journey-audit-owner-firstrun.md`, `-owner-moderation.md`, `-member-participate.md`,
`-member-find.md`, `journey-free-owner.md`, `journey-free-member.md`, `journey-audit-gap-register.md`,
`1.4.4-verification.md` (canonical fix list).

**Tags:** `[free]` = works in jetonomy alone - `[pro]` = needs jetonomy-pro - `[free+pro]` = free
core, Pro decorates. **Function** = does it work. **UX** = visual/mobile/dark verified at 390px+dark.

---

## 1.4.4 release headline

Plain-English summary of what customers actually get on this release. Free first, then Pro.

**For everyone (free)**

- **Reputation is fair again.** Reporting bad content now applies the -10 deduction the docs always promised, and a moderator dismissing a flag restores the -10. False reports do not leave permanent damage.
- **The Report button speaks for itself.** It tells you up-front if you have already reported a post, instead of opening the reason form and rejecting your submission as a duplicate.
- **Quiet sites still work.** Trust promotions, ban expiry, scheduled post publishing, activity pruning, notification cleanup, and verification reminders now run on Action Scheduler. They no longer stall on low-traffic sites.
- **No dependency on WooCommerce.** Action Scheduler 3.9.3 is bundled directly inside the free plugin. Activate jetonomy on a stock WP site and background jobs work out of the box.
- **Space admins get the admin bar.** Anyone with a per-space admin role now sees "Edit this space" in the WP admin bar on space pages, even if they are not WP site admins. Free communities with space-level moderators benefit the most.

**For Pro customers (in addition to all of the above)**

- **Custom profile fields save correctly.** Values entered into Pro custom fields persist after Save Profile and reload.
- **Award Badge from wp-admin works in one click.** The picker no longer stacks confirm dialogs.
- **Badges auto-award within seconds.** Earned badges now fire from event listeners through Action Scheduler, not on a six-hour drift.
- **Conversation kebab labels are readable.** Mute notifications, Archive conversation, Block user render with text.
- **Messages "New" opens the compose form.** No more dead button.
- **Banned Users confirm uses the design-system danger button.** Destructive actions look destructive.

**Schema change:** 1.4.4 ships DB version `1.4.4.0` with the new denormalised `flag_count`
column on posts. Exercise the migration on a real upgrade for both free and combo before tagging.

---

## Free-only health (Pro deactivated) - VERIFIED on 1.4.4-dev

Owner journey: zero free-only fatals. Every Pro surface is `defined('JETONOMY_PRO_VERSION')`
guarded (hides or upsells, never errors). Member journey: all 19 core actions work standalone,
and the reputation + cron improvements above ship in free. A free-only customer gets a complete,
reliable product without buying Pro.

---

## Owner / first-run  [mostly free]

Detailed slice doc: `journey-audit-owner-firstrun.md`.

| Moment | Tag | Function | UX |
|--------|-----|----------|----|
| Activation -> setup wizard redirect | [free] | OK | OK |
| Wizard: category + first space | [free] | OK | OK |
| Permissions / trust thresholds | [free] | OK | OK + "what this unlocks" copy |
| Reputation points config | [free] | OK (configurable) | OK |
| Appearance / branding | [free] | OK | OK |
| Community title on home | [free] | OK (was screen-reader-only) | OK 390/dark |

Gaps closed by 1.4.4: none net-new in this slice; this was already clean entering the release.

---

## Owner / daily moderation  [free core + pro rules]

Detailed slice doc: `journey-audit-owner-moderation.md`.

| Moment | Tag | Function | UX |
|--------|-----|----------|----|
| Moderation queue (pending, flags) | [free] | OK | OK |
| Ban / unban member | [free] | OK | OK (confirm popup verified) |
| Banned Users confirm dialog uses danger button | [free+pro admin] | OK | **1.4.4:** Cancel = `jt-btn jt-btn-ghost`, Confirm = `jt-btn jt-btn-danger` |
| Accept best answer (as mod) | [free] | OK (mod-gated) | OK |
| Un-accept answer | [free] | OK | OK |
| Close / reopen topic (+ mod-reply-on-closed) | [free] | OK | OK |
| Flag indicator while browsing | [free] | OK (denormalised flag_count, links to queue) | OK danger badge |
| Pin / move / merge topic | [free] | OK | OK (merge popup verified) |
| Report -> -10 reputation deduction on flag create | [free] | **1.4.4 new:** enforced | n/a |
| Report -> dismissal restores -10 reputation | [free] | **1.4.4 new:** enforced | n/a |
| Edit this space (admin bar entry on space pages) | [free] | **1.4.4 new:** per-space admin sees it without being a WP admin | OK |
| Advanced auto-moderation rules | [pro] | OK (guarded; absent in free) | n/a free |

Gaps closed by 1.4.4: reputation deduction + restoration on flag lifecycle; Banned Users danger
styling; admin-bar "Edit this space" entry for per-space admins.

---

## Member / participate  [free core + pro engagement]

Detailed slice doc: `journey-audit-member-participate.md` + `journey-free-member.md`.

| Moment | Tag | Function | UX |
|--------|-----|----------|----|
| Join space (open/request/invite) | [free] | OK | OK |
| Join-request outcome notification | [free] | OK (approved/denied) | OK |
| Create post (forum/qa/ideas) | [free] | OK | OK composer 390/dark |
| Create FEED status (no title) | [free] | OK (page + inline + Pro-poll paths) | OK |
| Reply | [free] | OK | OK |
| Vote up/down (single post) | [free] | OK | OK |
| Vote from space listing | [free] | OK (wiring + arrow fix) | OK |
| Feed-card downvote | [free] | OK (was upvote-only) | OK |
| Downvote notification suppression | [free] | OK (no "voted on your post" on downvote) | n/a |
| Accept answer (asker) | [free] | OK | OK |
| Edit / delete own; delete reply | [free] | OK | OK |
| Report content | [free] | OK | **1.4.4:** Report button shows "already reported" state inline (`data-flagged`), second click toasts instead of re-opening reason form |
| Reactions / polls / private messaging / badges | [pro] | OK (guarded; absent in free) | OK (combo) |
| Custom profile fields persist on Save | [pro] | **1.4.4:** value typed in custom field saves + reloads correctly | OK |
| Conversation kebab labels (Mute / Archive / Block) | [pro] | **1.4.4:** all three labels render with text | OK |
| Messages "New" opens compose | [pro] | **1.4.4:** button opens recipient + textarea + Send form | OK |
| Badges auto-award within seconds of qualifying activity | [pro] | **1.4.4:** event listener -> Action Scheduler -> badge row written | n/a |
| wp-admin Award Badge picker (single AJAX) | [pro admin] | **1.4.4:** one dialog only, no stacked confirm | OK |

Gaps closed by 1.4.4 (free): Report button "already reported" state; reputation deduction +
restoration on the report lifecycle (also listed under moderation).

Gaps closed by 1.4.4 (Pro): custom-field persistence, Award Badge single-click, badge
auto-award latency, conversation kebab labels, Messages New button.

---

## Member / find + identity  [free]

Detailed slice doc: `journey-audit-member-find.md` + `journey-free-member.md`.

| Moment | Tag | Function | UX |
|--------|-----|----------|----|
| Search (keyword) | [free] | OK | OK |
| Search filters: date / tag / sort | [free] | OK | OK |
| Search by author (name) | [free] | OK (UI + REST headless parity for ID; name resolution remains a low-severity gap) | OK |
| Search private-post visibility | [free] | OK (leak fixed) | n/a |
| Notifications (list, filter tabs, unread) | [free] | OK | OK - minor: system rows show "?" avatar |
| Profile (tabs, stats) | [free] | OK (N+1 fixed) | OK 390/dark |
| Leaderboard (+ period) | [free] | OK (period fix + pills) | OK |
| Dark mode (Reign/BuddyX) | [free] | OK (propagation fixed) | OK |

Gaps closed by 1.4.4 in this slice: none net-new; this slice was already cleaned up in the
1.4.4 prep window.

---

## Background jobs + dependencies  [free]

New slice tracked for 1.4.4 because the cron migration affects every owner and member journey
indirectly.

| Moment | Tag | Function | UX |
|--------|-----|----------|----|
| Trust evaluation runs on schedule (quiet sites) | [free] | **1.4.4:** runs via Action Scheduler | n/a |
| Expired-ban cleanup runs on schedule | [free] | **1.4.4:** runs via Action Scheduler | n/a |
| Activity log pruning runs on schedule | [free] | **1.4.4:** runs via Action Scheduler | n/a |
| Notifications cleanup runs on schedule | [free] | **1.4.4:** runs via Action Scheduler | n/a |
| Scheduled-post publishing runs on schedule | [free] | **1.4.4:** runs via Action Scheduler | n/a |
| Verification reminder runs on schedule | [free] | **1.4.4:** runs via Action Scheduler | n/a |
| Action Scheduler 3.9.3 bundled in free zip | [free] | **1.4.4:** ships in `libs/action-scheduler/` of the free plugin; no WooCommerce dependency | n/a |
| EDD SL SDK bundled in free zip | [free] | **1.4.4:** License screen assets ship with the zip | OK |

Gaps closed by 1.4.4: WP-Cron stalls on quiet free-only sites; external dependency on
WooCommerce or another AS host plugin.

---

## Closed in 1.4.4 (rollup, customer-facing)

- Report -> -10 reputation deduction applied to authors on flag creation (free).
- Flag dismissal restores the -10 reputation to the author (free).
- Report button surfaces "already reported" state to the reporter (free).
- All six free recurring crons migrated to Action Scheduler (free).
- Action Scheduler 3.9.3 bundled inside the free zip (free).
- "Edit this space" admin-bar entry for per-space admins on space pages (free).
- EDD SL SDK bundled inside both free and Pro release zips.
- Pro custom profile fields persist on Save Profile (Pro).
- wp-admin Award Badge picker fires a single AJAX call (Pro).
- Badges auto-award within seconds of qualifying activity (Pro).
- Conversation kebab labels show Mute / Archive / Block text (Pro).
- Messages "New" opens the compose form (Pro).
- wp-admin Banned Users confirm uses the design-system danger button (free admin).

---

## Open after 1.4.4 (Low - not release blockers)

- `[free]` Notifications: system / no-actor rows render a "?" avatar. Polish.
- `[free]` `/u/:login/badges/` and `/u/:login/activity/` rewrite rules exist but render the Posts tab. Graceful, not in nav, only reachable via direct URL.
- `[free]` Composer "Post Topic" split-button dropdown half looks oversized at 390px. Minor CSS.
- `[free]` REST `/jetonomy/v1/search` accepts `author_id` only; headless clients must resolve name -> ID via `/users/suggest`. API usability gap, not a runtime error.
- `[free]` `badge_earned` notification filter tab visible in free; always empty without Pro. Empty-state copy is sensible.

## Still TODO (net-new features, their own cards)

- `[free]` Invite-link admin UI (REST exists, no wp-admin surface).
- `[free]` "Is my community ready?" launch checklist (needs product input on the checks).

---

## Testing guidance

Run BOTH smoke modes before tagging 1.4.4: `free` (Pro off, confirms the free-only product)
and `combo` (both, confirms Pro decoration). The canonical fix list to walk row-by-row lives in
`1.4.4-verification.md`. 1.4.4 ships a schema change (`flag_count`, DB 1.4.4.0); exercise the
migration on a real free AND combo upgrade. AS bundling means a fresh activation on a stock WP
install must produce six pending `jetonomy_*` rows in `wp_actionscheduler_actions` and zero legacy
`wp cron event list` entries for those hooks.
