# Journey Audit: End-User Find / Discover / Identity

> **STATUS as of 1.4.4-dev (current).** Current spec: `journey-map-1.4.4.md`. All moments here are `[free]`.

Branch: `1.4.4-dev` | Auditor: code-grounded, read-only | Date: 2026-05-23

---

## What got easier on 1.4.4-dev for members

- 1.4.4-dev did not ship direct changes to search or browse. Search, filters, leaderboard, tag pages, and notifications work the same way they did on 1.4.4 tag.
- Member identity gets one indirect upgrade: the Edit Profile page now persists the Pro Custom Fields values you type in. That means profile cards across the site (member directory, hover-cards, leaderboards, replies) can finally surface accurate "About me" answers from those fields, where earlier the answers silently disappeared on save and people looked anonymous to each other.

---

## Closed in 1.4.4-dev (find journey)

- **Custom profile fields used to silently drop on save.** Now the Edit Profile flow PATCHes every `jt_cf[slug]` value to a Pro endpoint after the core profile save returns 200. Indirect for the find journey (profile completeness is part of "find people"), so noting it here briefly; the participate audit covers the full detail.

No direct find-journey changes shipped this version. The closed list from 1.4.4 tag (search private-leak fix, author filter UI, leaderboard `?period`, view.js i18n keys, profile N+1) all remain shipped and verified on this branch.

---

## Open

| Gap | Severity | Why it still matters | Fix direction |
|-----|----------|----------------------|---------------|
| Search result count shows the page slice (max 45) not the DB total. A search returning 200 posts shows "20 results". | Major | Misleading on busy sites; members underestimate the result set. | Use `count_posts` / `count_spaces` / `count_tags` companions already present in the REST controller as the displayed total. |
| No tag subscriptions. A member interested in "javascript" cannot follow the tag and get notified on new posts. | Major | Forces revisits. Spaces and posts can be followed; tags cannot. | Add `object_type='tag'` to the Subscription enum, render a Follow button on `tag.php`, and dispatch `new_post_in_sub` when a tagged post is published. |
| Notifications rows with no actor (system events, automated awards) show a "?" avatar instead of a system glyph. | Minor (polish) | Looks broken even though the event is real. | Render a Jetonomy mark or a Lucide system icon when `actor_id` is null. |
| Leaderboard / member directory does not surface custom-field answers yet. Now that those fields actually save, surfacing them is the next step. | Minor | Profile completeness drives "find people". Storage is fixed; the read surfaces have not been updated. | Add a hook so themes / Pro can render selected custom-field answers on the leaderboard row and profile card. |

---

## Full audit table

| Moment | Customer Expectation | Current Reality | Status |
|--------|---------------------|-----------------|--------|
| **Search - keyword** | Type 2+ chars, see posts, spaces, tags; result count reflects DB total. | REST `Search_Controller` uses BOOLEAN MODE FULLTEXT with safe escaping. Template renders the three sections. | Works for results. Result count still shows the page slice. |
| **Search - author filter** | Filter results by member by typing a name. | REST + template accept `author_id`. Filters form renders an author typeahead that resolves a name via `/users/suggest` into the hidden `author_id`. | Works (shipped in 1.4.4). |
| **Search - private post visibility** | Private posts never appear to non-authors regardless of which filter path runs. | Template's direct-query path now applies the same visibility guard as the REST controller (viewer-aware: `is_private=0 OR author_id=viewer`). | Works (shipped in 1.4.4). |
| **Space browsing - pagination** | Busy space (10k+ posts) loads fast; explicit "Load More". | `space.php` uses LIMIT/OFFSET with per-space `$limit`; pagination partial renders Load More with JS append. | Works. |
| **Space sort pills** | Switch Latest / Popular / Unanswered without full reload. | Sort pills use `add_query_arg` so each click is a full server render. Acceptable at current scale. | Works (polish: REST fetch later). |
| **Notifications - list + filter** | See all notifications; filter by type; unread count in header. | `Notification::list_for_user_with_targets` + `Notification::counts_by_filter` (one SUM/CASE). Tabs: All / Unread / Replies / Mentions / Votes / Badges. | Works. |
| **Notifications - unread count in header** | Live unread badge; clears on mark-all-read. | `header.php` localizes `jetonomyHeader`; `header.js` updates the badge; `unread_count` endpoint is cached for 15s. | Works. |
| **Notifications - i18n (toasts, confirms)** | Toast and dialog copy in the member's language. | The 8 `view.js` keys (`voteFailed`, `reportPlaceholder`, `reportReplyPrompt`, `reportUserPrompt`, `reportUserPlaceholder`, `madePrivate`, `madePublic`, `failedTogglePrivate`) are now in `class-template-loader.php` localize block and in the .pot. | Works (shipped in 1.4.4). |
| **Follow / Subscribe to space** | One-click follow to receive new-post notifications. | `space.php` renders the toggle; `view.js followSpace` posts to `/subscriptions`. | Works. |
| **Follow / Subscribe to post (topic)** | Follow a discussion to get reply notifications. | `single-post.php` renders the toggle; `view.js followPost`. | Works. |
| **Subscribe to tag** | Follow a tag and get notified on new posts tagged with it. | Subscription enum is `['space', 'post']`. No `tag.php` follow affordance. | Open (major). |
| **User profile - view contributions** | See a member's posts, replies, votes, stats; navigate tabs. | `user-profile.php` resolves by `user_login` slug; tabs paginate at 20/page; Reply / Vote models pre-join space slug + title. | Works. |
| **User profile - tab performance** | Profile loads fast even with 1,000+ contributions. | Posts / Replies / Votes tabs no longer fan-out to `Space::find_by_slug` per row; space `type` is read from the joined column. | Works (shipped in 1.4.4). |
| **User profile - custom-field surfaces** | Custom-field answers from Edit Profile show on the public profile / card. | Storage path now persists `jt_cf[*]` values. Public read surfaces (profile card, hover-card, leaderboard row) do not render them yet. | Open (minor). |
| **Leaderboard - period filter (REST)** | REST applies `?period=week|month|all` symmetrically with the template SQL. | `Leaderboards_Controller::list_items` applies the same period WHERE clause as `leaderboard.php`. | Works (shipped in 1.4.4). |
| **Leaderboard - period UI** | Pill row above the leaderboard switches All time / This Month / This Week. | `leaderboard.php` renders period pills mirroring the space sort-pill pattern. | Works (shipped in 1.4.4). |
| **Tag browsing - pagination** | Browse a 1,000+ post tag without freezing. | `tag.php` uses LIMIT/OFFSET keyed off `Tag::post_count` (denormalized via Tag::attach/detach). Prev/Next renders numbered nav. | Works. |
| **i18n - PHP template strings** | All UI labels translate. | All templates use `__()` / `_e()` / `_n()` / `_x()` with `jetonomy` text domain. RTL stylesheet conditionally enqueued. | Works. |
| **i18n - JS toast / dialog strings (view.js)** | Vote feedback, report dialogs, visibility toasts translate. | The 8 previously-missing keys live in the localize block; .pot regenerated. | Works (shipped in 1.4.4). |
| **i18n - header.js strings** | Header search overlay + keyboard shortcut modal translate. | `header.php` localizes every key read by `header.js`. | Works. |
| **Search overlay (header)** | Overlay placeholder, "no results", shortcut labels translate. | All keys present in `jetonomyHeader.i18n`. | Works. |
