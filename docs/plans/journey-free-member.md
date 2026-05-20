# Free-Only Member Journey Audit — 1.4.4-dev

**Scope**: jetonomy (free) only. jetonomy-pro INACTIVE. `JETONOMY_PRO_VERSION` undefined.
**Date**: 2026-05-20. **Branch**: 1.4.4-dev.
**Method**: template read + WP-CLI REST calls (user_id=2, non-admin subscriber).

---

## Journey Table

| # | Moment | [free]/[pro] | Works free-only? | Gap (if any) + file:line | Severity |
|---|--------|-------------|-----------------|--------------------------|----------|
| 1 | Discover/browse spaces (home, category) | [free] | Yes | None | - |
| 2 | Join a space (open/approval/invite) | [free] | Yes | None | - |
| 3 | Read a thread (single-post view, threaded replies) | [free] | Yes | None | - |
| 4 | Create post — forum | [free] | Yes | None | - |
| 5 | Create post — QA (question) | [free] | Yes | None | - |
| 6 | Create post — Ideas | [free] | Yes | None | - |
| 7 | Create post — Feed/status | [free] | Yes | None | - |
| 8 | Reply to a post | [free] | Yes | None | - |
| 9 | Nested reply (reply-to-reply) | [free] | Yes | None | - |
| 10 | Vote up/down on post | [free] | Yes | None | - |
| 11 | Vote up/down on reply | [free] | Yes | None | - |
| 12 | Accept answer (QA asker) | [free] | Yes | None | - |
| 13 | Edit own post (PATCH) | [free] | Yes | None | - |
| 14 | Delete own reply | [free] | Yes | None | - |
| 15 | Report/flag post | [free] | Yes | None | - |
| 16 | Report/flag reply | [free] | Yes | None | - |
| 17 | Report/flag user | [free] | Yes | Button in template, REST `POST /jetonomy/v1/flags` confirmed | - |
| 18 | Bookmark post | [free] | Yes | None | - |
| 19 | Remove bookmark | [free] | Yes | None | - |
| 20 | Save draft | [free] | Yes | None | - |
| 21 | View own drafts (profile tab) | [free] | Yes | None | - |
| 22 | Follow/subscribe to a post | [free] | Yes | `POST /jetonomy/v1/subscriptions` with `object_type=post` works | - |
| 23 | Follow/subscribe to a space | [free] | Yes | None | - |
| 24 | Trust level (TL0-5) | [free] | Yes | Computed from activity stats; thresholds in settings | - |
| 25 | Reputation score | [free] | Yes | Stored in `jt_user_profiles.reputation`; visible on profile | - |
| 26 | Badges (custom) | [pro] | N/A — gracefully absent | Pro's `custom-badges` extension hooks `jetonomy_profile_after_stats` (do_action, no listeners in free). No badge UI renders. No stub or broken slot. | - |
| 27 | Reactions on posts/replies | [pro] | N/A — gracefully absent | No reaction HTML in free templates. Pro hooks `jetonomy_reply_actions` / `jetonomy_post_actions` actions. Zero output when Pro inactive. | - |
| 28 | Polls in posts | [pro] | N/A — gracefully absent | Pro replaces `jetonomy_new_post_submit_action` filter. Free default `actions.submitNewPost` fires unmodified. `jetonomy_after_post_content` filter returns empty string in free. | - |
| 29 | Private messaging | [pro] | N/A — gracefully absent | `/community/messages/` route only registered when `JETONOMY_PRO_VERSION` defined (`class-router.php:100`). "Message" button in profile gated by `defined('JETONOMY_PRO_VERSION')` (`user-profile.php:149`). No broken link. | - |
| 30 | Notifications page (all tabs) | [free] | Yes | `badge_earned` filter tab present; no badges fire in free so it stays empty. Not broken. | Low |
| 31 | Notifications — mark read / bulk delete | [free] | Yes | REST `POST /jetonomy/v1/notifications/mark-all-read` confirmed 200 | - |
| 32 | Notifications — unread count | [free] | Yes | REST `GET /jetonomy/v1/notifications/unread-count` confirmed 200 | - |
| 33 | Search — keyword | [free] | Yes | None | - |
| 34 | Search — author filter (template) | [free] | Yes | Template (`search.php:34-55`) resolves `?author=name` to `author_id` server-side via `get_users()`. Works in browser. | - |
| 35 | Search — author filter (REST) | [free] | **Partial gap** | REST `GET /jetonomy/v1/search` accepts `author_id` (integer) but NOT `author_name` (string). `class-search-controller.php:58-61`. Template lookup logic is PHP-only; REST consumers / headless clients cannot filter by author name — must resolve to ID first. | Low |
| 36 | Search — date range filter | [free] | Yes | `date_from` / `date_to` params in both template and REST controller | - |
| 37 | Search — tag filter | [free] | Yes | None | - |
| 38 | Search — sort (relevance/newest/votes) | [free] | Yes | None | - |
| 39 | Profile — Posts tab | [free] | Yes | None | - |
| 40 | Profile — Replies tab | [free] | Yes | None | - |
| 41 | Profile — Votes tab | [free] | Yes | None | - |
| 42 | Profile — Bookmarks tab (own) | [free] | Yes | None | - |
| 43 | Profile — Drafts tab (own) | [free] | Yes | None | - |
| 44 | Profile — Badges tab URL (`/u/:login/badges/`) | [free] | **Gap** | Router registers `badges` and `activity` as valid tab slugs (`class-router.php:57`) but `user-profile.php` has no handler for either tab. Visiting the URL silently renders the Posts tab content. Tab links do NOT appear in the nav (no `<a>` emitted for badges/activity), so users cannot accidentally land there from the UI — but external links or Pro-deactivation scenarios can. | Low |
| 45 | Profile — Activity tab URL (`/u/:login/activity/`) | [free] | **Gap** | Same as above. Router registers the route; template silently falls through to Posts. | Low |
| 46 | Profile — Message button (other user) | [free] | N/A — gracefully absent | Button only rendered when `defined('JETONOMY_PRO_VERSION')` (`user-profile.php:149`). In free, no button. Clean. | - |
| 47 | Profile — custom fields display | [pro] | N/A — gracefully absent | Hooks `jetonomy_profile_display_fields` (do_action, no listeners in free). Empty output. | - |
| 48 | Leaderboard (all-time / month / week) | [free] | Yes | Paginated from `jt_user_profiles` by reputation. REST `GET /jetonomy/v1/leaderboards` confirmed 200 with data. | - |
| 49 | User panel block (messages link) | [free] | N/A — gracefully absent | `show_messages = defined('JETONOMY_PRO_VERSION')` (`class-blocks.php:654`). Messages link hidden in free. Clean. | - |
| 50 | Composer — new-post form | [free] | Yes | `jetonomy_new_post_submit_action` filter defaults to `actions.submitNewPost` when Pro absent. No blank form. | - |
| 51 | Composer — embed (compose-topic-embed) | [free] | Yes | No Pro-only fields rendered. `jetonomy_compose_extras` action fires with no listeners. Clean. | - |
| 52 | Feed-card list (`feed-card.php`) | [free] | Yes | No Pro references | - |
| 53 | Post card list (`post-card.php`) | [free] | Yes | `jetonomy_post_card_after_badges` action fires with no listeners (Pro hooks site-announcement markers here). Empty output is correct. | - |
| 54 | Single-post — Pro poll slot | [free] | N/A — gracefully absent | `jetonomy_after_post_content` filter returns `''` in free. kses allowlist is pre-extended in template (single-post.php:418-472) for when Pro is active, but the extension is safe — no output is emitted when filter returns empty string. | - |
| 55 | Single-post — SEO meta (`og:` tags) | [free] | Yes | Free emits baseline meta. SEO-Pro extension skips free's meta when active (`class-template-loader.php:834`). With Pro inactive, free emits its own meta unconditionally. | - |
| 56 | Spaces — roadmap view (ideas) | [free] | Yes | `space-roadmap.php` template exists in free; renders idea status board | - |

---

## Free-Only Gaps (Summary)

1. **`/u/:login/badges/` and `/u/:login/activity/` silently render Posts tab** — `class-router.php:57` registers both URL slugs as rewrite-rule matches, but `user-profile.php` contains no `elseif ('badges' === $current_tab)` or `elseif ('activity' === $current_tab)` branch. Result: visiting either URL renders the Posts tab content with no error and no tab highlight, misleading any bookmarked or externally linked URL. Neither tab link appears in the free UI nav, so normal member navigation cannot reach them — the gap only bites after Pro deactivation or via a direct URL. Fix: either drop both slugs from the free rewrite rule, or add explicit empty-state branches in `user-profile.php`. **Severity: Low.**

2. **REST search lacks `author_name` param** — `class-search-controller.php:58-61` accepts `author_id` (integer) only. The template (`search.php:33-55`) resolves a typed author name to an ID in PHP, so the browser search form works. REST/headless clients or the Abilities API cannot filter by author name directly; they must resolve the name to an ID via a separate `GET /jetonomy/v1/users/suggest?q=name` call first. This is a minor API usability gap, not a runtime error. **Severity: Low.**

3. **`badge_earned` notification filter tab is visible but will always be empty in free** — `notifications.php:63` shows a "Badges" filter tab. No free code path ever fires a `badge_earned` notification (that is dispatched by `jetonomy-pro/includes/extensions/custom-badges/`). The tab renders, but clicking it always shows the empty-state "No badges yet" copy. Not broken — the empty state copy is sensible — but the tab can confuse a free-only customer. **Severity: Low.**
