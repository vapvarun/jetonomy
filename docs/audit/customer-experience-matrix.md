# Jetonomy customer-experience matrix

**Audit date:** 2026-04-26
**Plugin versions audited:** free 1.4.0 (HEAD `9dc0c2d`), pro 1.4.0 (HEAD `b989a58`)
**Method:** five parallel Sonnet sub-agents, each owning one customer journey, three lenses per feature (customer expectation / current reality / long-term fix), severity rubric (🟢 matches expectation, 🟡 works with friction, 🔴 breaks expectation, ⚪ skipped because dependency not active).
**Per-journey reports:** `/tmp/jetonomy-audit-1.4.0/a{1..5}-*.md` (raw findings; promoted to this repo as snapshots in this audit dir).

## Severity counts

| | 🔴 | 🟡 | 🟢 | ⚪ | Total |
|---|---|---|---|---|---|
| A1 First-run + setup | 1 | 6 | 2 | 0 | 9 |
| A2 Daily community life | 3 | 6 | 1 | 0 | 10 |
| A3 Member journey | 4 | 4 | 1 | 0 | 9 |
| A4 Owner / moderator | 4 | 5 | 1 | 0 | 10 |
| A5 Pro extensions + integrations | 1 | 7 | 7 | 4 | 19 |
| **Total** | **13** | **28** | **12** | **4** | **57** |

13 of 57 audited surfaces (23%) break customer expectation. Another 28 (49%) work with friction. The remaining 12 green + 4 skipped (28%) are clean or pending third-party install.

## Reds, sorted by 1.4.0-fit

🔴 = customer expectation broken on this surface today. "1.4.0 fit" rates how well a fix maps to the existing 1.4.0 plan.

| # | Surface | Today's behaviour | 1.4.0 fix | 1.4.0 fit |
|---|---|---|---|---|
| R1 | First space creation (A1.4) | Only wp-admin can create a space | Already in plan as G6 (frontend `/community/new-space/`, trust-level gate) | direct |
| R2 | G7 my-spaces landing (A3.8) | Not implemented; member has no single landing for spaces they run / are in | Already in plan as G7 | direct |
| R3 | Permission engine clarity + members list scale (A4.10) | Three-layer model (WP cap + space role + trust) split, surface ambiguous; `SpaceMember::list_by_space()` has no LIMIT (rule violation) | G4 already planned for guards; add LIMIT/cursor + admin "what does this gate" copy | direct |
| R4 | Inline moderation tools invisible to space mods (A4.7) | `reply-card.php` and `single-post.php` gate edit/delete/pin on `current_user_can('jetonomy_moderate')`. That cap defaults to editor+, so a subscriber-role space moderator sees no mod tools | Add space-role check to the same gate; one-line fix per template plus the helper update. Direct contradiction of what 1.3.8 promised; should land in 1.4.0 as a regression fix | high |
| R5 | Join request review uses wp-admin URL (A4.4) | `Notifier::send_email_notification` line 763 hardcodes `admin_url(...)` for the action link; space owners without WP admin role get 403 and cannot approve / decline at all | Route through the new `/community/s/:slug/mod/` page (already exists); update the notifier to build a community URL when the recipient lacks admin caps | high |
| R6 | Banned-user login still allowed (A4.8) | No `wp_authenticate` filter blocks a banned user. Plus: space mods have no front-end ban path | Add filter + frontend ban affordance gated by space-admin role | high |
| R7 | Tags page hard-coded `LIMIT 30`, post-card tags non-clickable (A2.10) | Tag listing silently drops post 31+ (rule violation); tags in post-card render as `<span>` not `<a>` | Add cursor pagination to tag page; make post-card tags clickable to `/community/tag/:slug/` | high |
| R8 | Read-status / unread indicators unwired (A2.9) | `ReadStatus` model has `mark_read`, `has_unread`, `last_read_at` — but `mark_read()` is called from zero places. Unread feature is dead code | Wire `mark_read` from post-single view + REST GET; expose unread badge in space-card and notification UI | medium |
| R9 | Notifications auto-mark-all-read on page load (A3.5) | `templates/views/notifications.php:15` calls `Notification::mark_all_read()` server-side before render. The user never sees their unread state | Remove the server-side call; rely on the existing REST `mark-all-read` endpoint triggered by user click | high (one-line) |
| R10 | No `@mention` autocomplete (A2.5 + A3.7, same root cause) | Composer.js has no autocomplete code, no `/users/suggest` REST endpoint exists. Members must type the exact `user_login` blind | New REST endpoint `GET /users/suggest?q=` + composer.js typeahead with debounce | high |
| R11 | GDPR data export Display Name always blank (A3.9) | `class-privacy.php:67` reads `$profile->display_name`, but that column does not exist on `jt_user_profiles` (it's on `wp_users`). Every export returns `""` | One-line fix: read from `WP_User::get('display_name')` or join in the model | high (one-line) |
| R12 | Access rules / join requests path partially broken (A4.4) | Approval is wp-admin-only; member-side request flow lacks confirm / cancel affordance after submission | Front-end approval at `/community/s/:slug/mod/` already exists in 1.3.8 — wire that into the notification link; fix the member-side flow | direct (extends G5/G6) |
| R13 | Web Push payload not encrypted per RFC 8291 (A5.10, **Pro**) | VAPID JWT is signed correctly, subscriptions are stored, but the push body is sent unencrypted. All major browsers reject. Code comment labels it a stub | Out of 1.4.0 scope (Pro is compatibility-only). Queue for **v1.4.1 hotfix or v1.5.0**. Until fixed, web-push extension should be marked "experimental — disabled by default" |

## Cross-cutting findings

**Extreme-scale rule violations (CLAUDE.md):** R3 and R7. Both ship LIMIT-less or LIMIT-30 reads on tables that the design rule says must paginate. R7 is customer-visible silent data loss; R3 is a future blow-up at admin scale.

**REST-first / frontend AJAX rule violations (deltas vs Phase A scope):** A1.7 (Login block AJAX) is already in Phase A. No additional surfaces found beyond the three handlers Phase A targets.

**Test-fixture leakage in production:** A3.A2 — "QA smoke" custom fields are visible in every user's edit-profile form (likely seeded by `Demo_Seeder`); A3.A1 — "View on Jetonomy Demo" cross-link on every profile when FluentCommunity site name is unset. Both 🟡 today, but they erode customer trust on first impression. Worth folding into the demo-data cleanup pass.

## Yellows by journey (28 total)

Full per-feature narratives in the per-journey reports. Quick index:

- **A1 (6):** activation re-run silent, setup wizard misses Ideas/Feed types, non-member redirected to wp-login on `/new/`, empty home page no admin CTA, login block uses AJAX (Phase A), nav block empty-state silent.
- **A2 (6):** no unread badges on space cards, oldest/newest sort missing at space level, no role pills on posts/replies (G3), quote-reply broken on touch devices, bookmark list buried, no subscriptions management page.
- **A3 (4):** "View on Jetonomy Demo" cross-link, QA smoke fields in production, trust shows level number only (no name / progression), leaderboard period filter unrendered.
- **A4 (5):** no bulk actions on spaces list, ideas-type roadmap link missing, role-update REST has no last-admin guard (G4), flag card shows `type #id` not titles, false-positive recovery non-obvious.
- **A5 Pro (7):** four `requires` tier-string typos (`white-label` and `seo-pro` say `'1.0.0'`, `custom-badges` and `advanced-moderation` say `'growth'` instead of `'starter'`); three onboarding gaps (email-digest cron silent, reply-by-email no onboarding, AI provider empty-state).

## Greens (12 total)

A1: demo seeder, theme bridge. A2: voting. A3: notification email delivery. A4: categories. A5: private messaging, reactions, polls, custom fields, webhooks, analytics, FluentCommunity integration. These match expectation today; re-verify on next audit cycle.

## Skipped (4 total)

A5: BuddyPress, LMS adapters (5), Membership (3), WooCommerce. Not active on the audit site. Real verification needs the third-party plugin installed; code-read was completed.

## Recommended 1.4.0 scope amendment

The original 1.4.0 plan covered 7 governance G-items + Phase A (3 frontend-AJAX migrations) + Phase 0 (`Space::create` seeding fix). The audit surfaces **8 additional 🔴 fixes** that should land in 1.4.0:

| Audit ID | Original plan? | Cost | Decision |
|---|---|---|---|
| R4 inline mod tools regression | not in plan | 1 line per template + helper | **add to 1.4.0** (regression from 1.3.8) |
| R5 join request notification URL | not in plan | one-line in notifier + URL builder | **add to 1.4.0** |
| R6 banned-user login filter | not in plan | small filter + frontend ban affordance | **add to 1.4.0** |
| R7 tags broken | not in plan | clickable spans + cursor pagination | **add to 1.4.0** (extreme-scale rule violation) |
| R8 read-status wire-up | not in plan | small wire-up + UI badges | **add to 1.4.0** |
| R9 notifications auto-mark-read | not in plan | one-line server-side fix | **add to 1.4.0** (one-line, customer-visible bug) |
| R10 mention autocomplete | not in plan | new REST endpoint + composer.js typeahead | **add to 1.4.0** |
| R11 GDPR Display Name | not in plan | one-line | **add to 1.4.0** (privacy-export bug) |
| R13 Web Push encryption | Pro is compat-only | sizeable; needs RFC 8291 implementation | **defer to v1.4.1 hotfix or v1.5.0** |

R1, R2, R3, R12 are already mapped to existing G-items.

**Scope expansion:** 1.4.0 grows from 7 G-items + Phase A + Phase 0 to **7 G-items + Phase A + Phase 0 + 8 audit-driven fixes**. Most are small (one-line, regression-fix scale). The cluster around `Notifier`, `ReadStatus`, and `mention` is the only meaningful added scope.

## Living-doc rule

This matrix is regenerated per release using the same five-journey audit method (codified in memory rule `feedback_audit_by_journey_not_by_file.md`). After 1.4.0 ships, re-run and confirm reds shrink. Yellows trended downward across releases; greens trended upward. Anything not addressed in 1.4.0 is logged as `(deferred to vX.Y.Z)`.
