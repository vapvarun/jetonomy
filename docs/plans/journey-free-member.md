# Free-Only Member Journey Audit - 1.4.4-dev

**Scope**: jetonomy (free) only. jetonomy-pro INACTIVE. `JETONOMY_PRO_VERSION` undefined.
**Date**: 2026-05-23. **Branch**: 1.4.4-dev.
**Method**: template read + WP-CLI REST calls (user_id=2, non-admin subscriber) + browser walks at 390px.

---

## What got easier on 1.4.4-dev (free-only members)

Plain-English summary of what a free-only member or space admin actually notices on this release:

1. Reporting bad content costs the offender reputation again. The -10 deduction the docs always promised now actually fires when a flag is created. False reports do not leave permanent damage either: when a moderator dismisses a flag, the -10 is restored. Reputation is finally fair.
2. The Report button now tells you up-front that you have already reported this post. No more filling out the reason form just to be told the report is a duplicate. The button shows the "reported" state inline.
3. Trust promotions, scheduled posts, ban expiry, activity pruning, notification cleanup, and verification reminders all run reliably on a quiet site. Free's six recurring jobs are now driven by Action Scheduler instead of WP-Cron, so they no longer stall when nobody is browsing.
4. Action Scheduler 3.9.3 is bundled directly inside the free plugin. A free-only site no longer needs WooCommerce or any other plugin to get reliable background processing. It just works on activation.
5. Space admins who are not WordPress site admins now get an "Edit this space" entry in the WordPress admin bar whenever they are viewing a space they administer. Useful for free communities where space-level moderators are not WP admins.

None of these depend on jetonomy-pro. They all ship in free.

---

## Pro features still do NOT apply on a free-only install

Reminder: a free-only install does not get auto-awarded badges, custom profile fields, reactions on posts/replies, polls inside posts, or private messaging. Those require jetonomy-pro to be active. Free continues to hide every Pro surface cleanly (no broken buttons, no empty slots, no fatal links) when Pro is absent.

---

## Journey Table

| # | Moment | [free]/[pro] | Works free-only? | Notes | Severity |
|---|--------|-------------|-----------------|-------|----------|
| 1 | Discover/browse spaces (home, category) | [free] | Yes | None | - |
| 2 | Join a space (open/approval/invite) | [free] | Yes | None | - |
| 3 | Read a thread (single-post view, threaded replies) | [free] | Yes | None | - |
| 4 | Create post - forum | [free] | Yes | None | - |
| 5 | Create post - QA (question) | [free] | Yes | None | - |
| 6 | Create post - Ideas | [free] | Yes | None | - |
| 7 | Create post - Feed/status | [free] | Yes | None | - |
| 8 | Reply to a post | [free] | Yes | None | - |
| 9 | Nested reply (reply-to-reply) | [free] | Yes | None | - |
| 10 | Vote up/down on post | [free] | Yes | None | - |
| 11 | Vote up/down on reply | [free] | Yes | None | - |
| 12 | Accept answer (QA asker) | [free] | Yes | None | - |
| 13 | Edit own post (PATCH) | [free] | Yes | None | - |
| 14 | Delete own reply | [free] | Yes | None | - |
| 15 | Report/flag post | [free] | Yes | **1.4.4:** -10 reputation deduction now applied to the post author on flag creation; dismissal restores the -10. | - |
| 16 | Report/flag reply | [free] | Yes | Same reputation rule as posts. | - |
| 17 | Report/flag user | [free] | Yes | Button in template, REST `POST /jetonomy/v1/flags` confirmed | - |
| 17b | "Already reported" surfacing on report button | [free] | Yes | **1.4.4 new:** Report button exposes `data-flagged` to the current viewer. Second click short-circuits to a toast instead of re-opening the reason form. | - |
| 18 | Bookmark post | [free] | Yes | None | - |
| 19 | Remove bookmark | [free] | Yes | None | - |
| 20 | Save draft | [free] | Yes | None | - |
| 21 | View own drafts (profile tab) | [free] | Yes | None | - |
| 22 | Follow/subscribe to a post | [free] | Yes | `POST /jetonomy/v1/subscriptions` with `object_type=post` works | - |
| 23 | Follow/subscribe to a space | [free] | Yes | None | - |
| 24 | Trust level (TL0-5) | [free] | Yes | **1.4.4:** trust evaluation cron now runs via Action Scheduler. Promotions/demotions land on schedule even on quiet sites. | - |
| 25 | Reputation score | [free] | Yes | Stored in `jt_user_profiles.reputation`; visible on profile. Flag deduction + restoration enforced on 1.4.4. | - |
| 26 | Badges (custom) | [pro] | N/A - gracefully absent | Requires jetonomy-pro. Free renders no badge UI. | - |
| 27 | Reactions on posts/replies | [pro] | N/A - gracefully absent | Requires jetonomy-pro. Free emits no reaction HTML. | - |
| 28 | Polls in posts | [pro] | N/A - gracefully absent | Requires jetonomy-pro. Free composer uses the default submit action. | - |
| 29 | Private messaging | [pro] | N/A - gracefully absent | Requires jetonomy-pro. `/community/messages/` route and profile Message button are guarded by `defined('JETONOMY_PRO_VERSION')`. | - |
| 30 | Notifications page (all tabs) | [free] | Yes | `badge_earned` filter tab present; no badges fire in free so it stays empty. Not broken. | Low |
| 31 | Notifications - mark read / bulk delete | [free] | Yes | REST `POST /jetonomy/v1/notifications/mark-all-read` confirmed 200. **1.4.4:** notification cleanup cron now runs via Action Scheduler. | - |
| 32 | Notifications - unread count | [free] | Yes | REST `GET /jetonomy/v1/notifications/unread-count` confirmed 200 | - |
| 33 | Search - keyword | [free] | Yes | None | - |
| 34 | Search - author filter (template) | [free] | Yes | Template resolves `?author=name` to `author_id` server-side | - |
| 35 | Search - author filter (REST) | [free] | **Partial gap** | REST `GET /jetonomy/v1/search` accepts `author_id` only; headless clients must resolve name to ID first via `/users/suggest`. | Low |
| 36 | Search - date range filter | [free] | Yes | `date_from` / `date_to` params in template and REST | - |
| 37 | Search - tag filter | [free] | Yes | None | - |
| 38 | Search - sort (relevance/newest/votes) | [free] | Yes | None | - |
| 39 | Profile - Posts tab | [free] | Yes | None | - |
| 40 | Profile - Replies tab | [free] | Yes | None | - |
| 41 | Profile - Votes tab | [free] | Yes | None | - |
| 42 | Profile - Bookmarks tab (own) | [free] | Yes | None | - |
| 43 | Profile - Drafts tab (own) | [free] | Yes | None | - |
| 44 | Profile - Badges tab URL (`/u/:login/badges/`) | [free] | **Gap** | Router registers the slug but template silently renders the Posts tab. Not in free nav, only reachable via direct URL. | Low |
| 45 | Profile - Activity tab URL (`/u/:login/activity/`) | [free] | **Gap** | Same as badges tab. Silent fall-through. | Low |
| 46 | Profile - Message button (other user) | [free] | N/A - gracefully absent | Hidden when Pro absent. | - |
| 47 | Profile - custom fields display | [pro] | N/A - gracefully absent | Requires jetonomy-pro. | - |
| 48 | Leaderboard (all-time / month / week) | [free] | Yes | Paginated from `jt_user_profiles` by reputation | - |
| 49 | User panel block (messages link) | [free] | N/A - gracefully absent | Hidden when Pro absent. | - |
| 50 | Composer - new-post form | [free] | Yes | Default submit action fires when Pro absent | - |
| 51 | Composer - embed (compose-topic-embed) | [free] | Yes | No Pro-only fields rendered | - |
| 52 | Feed-card list (`feed-card.php`) | [free] | Yes | No Pro references | - |
| 53 | Post card list (`post-card.php`) | [free] | Yes | Action hook fires with no listeners. Empty output is correct. | - |
| 54 | Single-post - Pro poll slot | [free] | N/A - gracefully absent | Filter returns `''` in free | - |
| 55 | Single-post - SEO meta (`og:` tags) | [free] | Yes | Free emits baseline meta unconditionally when Pro absent | - |
| 56 | Spaces - roadmap view (ideas) | [free] | Yes | `space-roadmap.php` ships in free | - |
| 57 | Background jobs (trust eval, ban expiry, activity pruning, notifications cleanup, scheduled publish, verification reminder) | [free] | Yes | **1.4.4 new:** all six free recurring jobs run via Action Scheduler. AS 3.9.3 is bundled in the free plugin zip. No external dependency. | - |
| 58 | "Edit this space" admin-bar entry on space pages | [free] | Yes | **1.4.4 new:** appears on space pages for any user with `jetonomy_admin_space` capability on that space, even if they are not a WP site admin. Links to the front-end edit page. | - |

---

## Closed in 1.4.4 (free-only)

- **Report deduction enforced.** Filing a flag against a post or reply now applies the -10 reputation penalty to the author. Documented behaviour finally matches runtime.
- **Dismissed reports restore reputation.** When a moderator marks a flag as dismissed, the -10 is added back to the author. False reports do not leave permanent damage.
- **Already-reported state on the Report button.** Reporters see inline state on a post they have already flagged, instead of being told mid-form that their report is a duplicate.
- **Free crons moved to Action Scheduler.** All six recurring hooks (trust evaluation, expired-ban cleanup, activity log pruning, notifications cleanup, scheduled post publishing, verification reminder) now run via Action Scheduler. Quiet free-only sites no longer see them stall.
- **Action Scheduler bundled.** AS 3.9.3 is shipped inside the free plugin. No need to install WooCommerce or any other host plugin to get reliable background processing.
- **Space admin "Edit this space" admin-bar link.** Users with a per-space admin role now get a one-click edit jump for the space they are viewing, even if they are not WP site admins.

---

## Open free-only gaps (Low - not blockers)

1. **`/u/:login/badges/` and `/u/:login/activity/` silently render the Posts tab.** Rewrite rules exist; template handler does not. Not linked from free nav, only reachable via direct URL or after Pro deactivation. Fix is either drop the slugs in free or add explicit empty-state branches.
2. **REST search lacks `author_name` param.** REST `GET /jetonomy/v1/search` accepts `author_id` (integer) only. The browser search form works because the PHP template resolves the name to an ID server-side; headless clients must do the same resolution via `/users/suggest`. API usability gap, not a runtime error.
3. **`badge_earned` notification filter tab is empty in free.** The tab renders on a free-only install but never has rows. Empty-state copy is sensible; the tab is still slightly confusing for free-only customers. Polish.
4. **Notifications: system/no-actor rows render a "?" avatar.** Should be a typed icon. Polish.
5. **Composer "Post Topic" split-button dropdown half oversized at 390px.** Minor CSS.
