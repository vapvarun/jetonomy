# Jetonomy Free — Manual QA Checklist

> Systematic testing checklist for the free core plugin. Test on a clean WordPress 6.7+ install with PHP 8.1+.

---

## 1. Installation & Activation

- [ ] Plugin installs via ZIP upload without errors
- [ ] Plugin activates without fatal errors or warnings
- [ ] All 21 database tables created (check `wp_jt_*` tables)
- [ ] Default capabilities added to Administrator, Editor, Subscriber roles
- [ ] Rewrite rules flushed (visit Settings > Permalinks or check `jetonomy_permalinks_flushed` option)
- [ ] Admin menu "Jetonomy" appears with sub-pages
- [ ] `/community/` page loads without 404
- [ ] Deactivation runs cleanly (no errors)
- [ ] Uninstall removes all tables, options, capabilities, and cron events

---

## 2. Setup Wizard / Demo Data

- [ ] Setup wizard launches on first activation
- [ ] Demo data installs sample categories, spaces, posts, replies, users, badges
- [ ] Demo data tracked in `jetonomy_demo_data` option
- [ ] One-click demo cleanup removes all demo content
- [ ] Activity backfill runs once (`jetonomy_activity_backfilled` flag set)

---

## 3. Categories

- [ ] Create category from WP Admin
- [ ] Edit category (name, slug, description)
- [ ] Delete category (confirm cascade behavior)
- [ ] Drag-sort category ordering
- [ ] Category shows on `/community/` with child spaces
- [ ] `GET /jetonomy/v1/categories` returns nested tree
- [ ] Category page at `/community/category/:slug/` renders correctly

---

## 4. Spaces

- [ ] Create space under a category (Forum type)
- [ ] Create space (Q&A type)
- [ ] Create space (Ideas type)
- [ ] Set visibility: public / private / hidden
- [ ] Set join policy: open / approval / invite
- [ ] Create sub-space under a parent space
- [ ] Assign space moderator
- [ ] Per-space permission overrides work
- [ ] Space card on category page shows: post count, member count, activity bar
- [ ] Space page at `/community/s/:slug/` lists posts correctly
- [ ] Private space hidden from non-members
- [ ] Hidden space not visible in any listing for non-members
- [ ] Approval-based space: join request flow works
- [ ] `GET /jetonomy/v1/spaces` with filters (category, type, visibility)
- [ ] `GET /jetonomy/v1/spaces/:id/members` returns paginated member list

---

## 5. Posts

- [ ] Create post in a Forum space (title + content + tags)
- [ ] Rich editor renders and submits correctly
- [ ] Post appears in space listing with: vote score, reply count, author, tags, time
- [ ] Single post view at `/community/s/:slug/t/:post-slug/`
- [ ] Edit post — creates revision
- [ ] Delete post — soft delete (status=trash)
- [ ] Close post (moderator action)
- [ ] Pin post (sticks to top of listing)
- [ ] Move post to another space
- [ ] Sort posts: Latest / Popular / Unanswered
- [ ] Pagination works (cursor-based)
- [ ] Post as Q&A type — "Accept Answer" button appears for author
- [ ] Post as Ideas type — status tracking (submitted → planned → completed)
- [ ] Guest reading (if enabled) — unauthenticated users can view
- [ ] "Require login to participate" — guests cannot create/reply
- [ ] `POST /jetonomy/v1/spaces/:id/posts` creates post
- [ ] `PATCH /jetonomy/v1/posts/:id` updates post
- [ ] `DELETE /jetonomy/v1/posts/:id` soft deletes

---

## 6. Replies

- [ ] Reply to a post
- [ ] Threaded reply (reply to a reply, up to 3 levels)
- [ ] Collapsible threads work
- [ ] "Show X more replies" gap loader
- [ ] Sort replies: Oldest / Newest / Best
- [ ] Smart loading: first 10 + gap + last 10 for high-traffic posts
- [ ] New replies banner appears (polling)
- [ ] Edit reply — creates revision
- [ ] Delete reply (moderator)
- [ ] Q&A: accept answer — accepted answer highlighted, moved to top
- [ ] `POST /jetonomy/v1/posts/:id/replies` creates reply
- [ ] `POST /jetonomy/v1/replies/:id/accept` marks accepted answer

---

## 7. Voting

- [ ] Upvote a post — score increments, animation plays
- [ ] Downvote a post — score decrements
- [ ] Toggle vote (click same button again) — vote removed
- [ ] Change vote direction (up → down) — score adjusts by 2
- [ ] One vote per user per object enforced
- [ ] Vote on reply works identically
- [ ] Denormalized score on post/reply row matches actual votes
- [ ] Voting awards/deducts reputation to content author
- [ ] `POST /jetonomy/v1/posts/:id/vote` with `{ "value": 1 }` works
- [ ] `DELETE /jetonomy/v1/posts/:id/vote` removes vote

---

## 8. Trust Levels & Reputation

- [ ] New user starts at Trust Level 0 (Newcomer)
- [ ] Trust badge displays on user avatar everywhere
- [ ] Level 0 rate limits enforced: 3 posts/day, 10 replies/day, 5 votes/day
- [ ] Rate limit error message shown when exceeded
- [ ] Reputation points awarded: post upvoted (+10), reply upvoted (+5), accepted answer (+15)
- [ ] Reputation deducted: downvoted (-2), post removed (-20)
- [ ] Trust evaluation cron runs every 12 hours
- [ ] Auto-promotion from Level 0 → 1 → 2 → 3 based on thresholds
- [ ] Levels 4-5 only grantable manually by admin
- [ ] Trust level thresholds configurable in admin settings
- [ ] Trust badge uses `data-jt-tl` attribute for styling
- [ ] Level 0 cannot post links (if configured)
- [ ] Level 3+ can close topics, wiki-edit

---

## 9. Permissions (3-Layer)

- [ ] **Layer 1 — WP Caps:** Subscriber has basic caps, Editor has moderate caps, Admin has manage caps
- [ ] 20 capabilities registered correctly
- [ ] **Layer 2 — Space Roles:** viewer (read only), member (participate), moderator (manage), admin (full)
- [ ] Space admin can manage their space without being WP Admin
- [ ] Sub-space role inheritance (higher wins)
- [ ] **Layer 3 — Trust gates + Access rules:** Trust level gates block low-trust users
- [ ] Access rules: logged-in-only space works
- [ ] Access rules: membership-level gating works (with MemberPress/PMPro)
- [ ] Banned user denied everywhere
- [ ] WP Admin always allowed
- [ ] `Permission_Engine::can($user_id, $action, $space_id)` returns correct results

---

## 10. Search

- [ ] Search page at `/community/search/` renders
- [ ] Search input works (minimum 2 characters)
- [ ] Filter tabs: All / Posts / Spaces / Tags
- [ ] Post results show: title, author, space, time, excerpt (25 words)
- [ ] Space results show: title, description, post count
- [ ] Tag results show: tag pills with post count
- [ ] Result count displayed ("7 results")
- [ ] Pagination works
- [ ] FULLTEXT search matches title and content_plain
- [ ] Private/hidden space content excluded for non-members

---

## 11. Moderation

- [ ] Mod queue accessible at `/community/mod/`
- [ ] 4 tabs: Pending Posts / Pending Replies / Flags / Banned Users
- [ ] Flag content: user selects reason (spam/offensive/off-topic/harassment/other)
- [ ] Flagged content appears in mod queue
- [ ] Approve action publishes content
- [ ] Spam action marks as spam, -20 reputation to author
- [ ] Trash action removes content
- [ ] Resolve flag dismisses without action
- [ ] Ban user — global ban, user cannot access community
- [ ] Per-space ban works
- [ ] Ban with expiry — user auto-unbanned after expiry
- [ ] Silence user — can read but not write
- [ ] Unban/unsilence works

---

## 12. Notifications

- [ ] Bell icon in community nav shows unread badge count
- [ ] Notifications page at `/community/notifications/`
- [ ] Mark-all-read on visiting notifications page
- [ ] Notification types received:
  - [ ] Reply to your post
  - [ ] Reply to subscribed thread
  - [ ] @mention
  - [ ] Accepted answer (Q&A)
  - [ ] Vote batch ("5 people upvoted your post")
  - [ ] Trust level promotion
  - [ ] Moderator action on your content
- [ ] Each notification shows: actor avatar, action text, link, time
- [ ] Clicking notification navigates to correct content

---

## 13. Email Notifications

- [ ] Email sent on reply to your post (via wp_mail)
- [ ] Email sent on @mention
- [ ] Email sent on accepted answer
- [ ] Email template renders correctly (HTML + plain text fallback)
- [ ] Unsubscribe link works
- [ ] User notification preferences respected

---

## 14. SEO

- [ ] Schema.org JSON-LD on single post: `DiscussionForumPosting`
- [ ] Schema.org JSON-LD on Q&A post: `QAPage` with `acceptedAnswer`
- [ ] `BreadcrumbList` schema on all pages
- [ ] XML Sitemap includes spaces and posts (check `/wp-sitemap.xml`)
- [ ] Hidden/private spaces excluded from sitemap
- [ ] Meta description and OG tags on every community page
- [ ] Twitter Card tags present
- [ ] Clean canonical URLs
- [ ] Server-side rendered HTML (view source confirms content)
- [ ] User profile pages have `noindex`

---

## 15. Import Tools

- [ ] Admin → Jetonomy → Import page loads
- [ ] **bbPress import:**
  - [ ] Auto-detect shows source stats
  - [ ] Dry run works without modifying data
  - [ ] Full import: forums → categories/spaces, topics → posts, replies → replies
  - [ ] User profiles created
  - [ ] Progress indicator during import
  - [ ] Results: imported / skipped / errors
  - [ ] Resume on failure works
- [ ] **wpForo import:** same flow as bbPress
- [ ] **Asgaros import:** same flow as bbPress
- [ ] ID mapping preserved (old_id → new_id)

---

## 16. Membership Integration

- [ ] **WP Roles adapter:** access rules by role work
- [ ] **MemberPress adapter:**
  - [ ] Space gated by MemberPress level
  - [ ] Membership activated → auto-join gated spaces
  - [ ] Membership deactivated → downgrade to viewer
  - [ ] Membership upgraded → unlock additional spaces
- [ ] **PMPro adapter:** same flow as MemberPress
- [ ] Access rule types: membership / role / capability / trust_level / logged_in / everyone
- [ ] Rule grants: read / participate / full

---

## 17. Frontend & UI

- [ ] Community home renders categories with space cards
- [ ] Mobile responsive (test 375px, 768px, 1024px, 1440px)
- [ ] RTL language support works
- [ ] CSS inherits theme fonts/colors via theme.json custom properties
- [ ] Fallback styles applied when theme has no theme.json
- [ ] No inline styles in templates (except dynamic values)
- [ ] CSS Layers prevent theme style bleed
- [ ] 3 color schemes work
- [ ] Interactivity API store functions (voting, sorting, polling)
- [ ] Keyboard shortcuts work (if implemented)
- [ ] Hover cards on user avatars
- [ ] Emoji picker functional

---

## 18. REST API General

- [ ] All endpoints require authentication where expected
- [ ] Unauthenticated requests return 401 for protected endpoints
- [ ] Rate limiting enforced (429 responses)
- [ ] Cursor-based pagination: `?cursor=` works correctly
- [ ] Response format consistent (envelope with data + pagination)
- [ ] Invalid requests return proper error codes and messages
- [ ] CORS headers correct for headless use

---

## 19. Admin Dashboard

- [ ] Dashboard overview page loads with stats
- [ ] Spaces admin page: CRUD operations
- [ ] Content admin page: post/reply management
- [ ] Users admin page: search, filter, trust level management
- [ ] Settings page: all tabs render and save
- [ ] Import page: source selection and import flow
- [ ] Abilities API: 18 abilities registered in 5 categories

---

## 20. Performance & Caching

- [ ] Page loads under 2s on shared hosting
- [ ] Object cache used for: spaces, posts, permissions, user profiles
- [ ] Cache invalidation correct: new reply invalidates post + space list
- [ ] Vote invalidates target object cache
- [ ] No N+1 queries on listing pages
- [ ] Eager loading applied for author data on post/reply lists

---

## 21. Edge Cases & Error Handling

- [ ] Creating post in nonexistent space returns proper error
- [ ] Voting on own post (if disallowed) returns error
- [ ] Duplicate vote attempt handled gracefully
- [ ] Very long post title truncated/handled
- [ ] XSS attempt in post content sanitized (`wp_kses_post`)
- [ ] SQL injection attempt in search query handled
- [ ] Concurrent vote from same user handled (UNIQUE constraint)
- [ ] Plugin works with default permalink structure (?p=123)
- [ ] Plugin works with popular themes (Astra, GeneratePress, Kadence, Twenty Twenty-Five)
- [ ] No JavaScript errors in browser console
- [ ] No PHP notices/warnings in debug.log

---

## 22. WP-CLI Commands

> Run all commands with `--path="/Users/varundubey/Local Sites/forums/app/public"`.

- [ ] `wp jetonomy status` — displays plugin version, table counts, user/space/post counts, recent activity summary
- [ ] `wp jetonomy flush-rules` — rewrite rules regenerated; verify `/community/*` URLs resolve correctly after
- [ ] `wp jetonomy recount --type=all` — updates post_count, reply_count, vote_score on all spaces and posts
- [ ] `wp jetonomy recount --type=posts` — only post counts updated; reply/vote counts unchanged
- [ ] `wp jetonomy recount --type=votes` — only vote_score updated; counts unchanged
- [ ] `wp jetonomy trust-evaluate` — auto-updates trust levels for all users based on current criteria; verify `wp_jt_user_profiles.trust_level` changes
- [ ] `wp jetonomy backfill-activity` — populates `wp_jt_activity_log` with historical events; guarded by `jetonomy_activity_backfilled` flag (runs once only)
- [ ] `wp jetonomy demo-seed` — creates demo users, spaces, categories, posts, replies; tracked in `jetonomy_demo_data` option
- [ ] `wp jetonomy demo-seed --force` — re-seeds even if demo data exists
- [ ] `wp jetonomy demo-cleanup` — removes all content tracked in `jetonomy_demo_data`; verify clean removal
- [ ] `wp jetonomy import bbpress --dry-run` — shows import counts without importing; no data written
- [ ] `wp jetonomy import bbpress` — imports bbPress posts/replies/users into Jetonomy tables
- [ ] `wp jetonomy import wpforo` — imports wpForo content into Jetonomy tables
- [ ] CLI commands fail gracefully when free plugin not active (clear error message)

---

## 23. Admin Pages — Detailed Coverage

### Dashboard
- [ ] Jetonomy admin dashboard loads at `admin.php?page=jetonomy`
- [ ] Stats cards show correct totals: spaces, posts, replies, active users
- [ ] Recent activity section shows last 10 activity items with type, user, time
- [ ] Setup wizard notice visible on fresh install; dismissable after setup
- [ ] Quick-action links work: New Space, New Category, View Community

### Content Management
- [ ] Jetonomy → Content page loads with post/reply table
- [ ] Filter by status: Published, Pending, Spam, Trash — each filters correctly
- [ ] Filter by space: dropdown shows all spaces; filters to that space's content
- [ ] Bulk action: Trash selected items → items moved to trash
- [ ] Bulk action: Approve (from Pending) → items published
- [ ] Bulk action: Mark Spam → items marked spam
- [ ] Inline: edit post title in table
- [ ] Inline: move post to different space via dropdown
- [ ] Pagination: 20 items per page default; Next/Prev links work
- [ ] Empty state: "No posts found" message shows correctly

### Moderation Queue
- [ ] Jetonomy → Moderation → Pending tab shows content awaiting approval
- [ ] Jetonomy → Moderation → Flags tab shows flagged content with reason
- [ ] Jetonomy → Moderation → Banned Users tab shows restrictions
- [ ] Approve pending post → moves to Published, author notified
- [ ] Mark as Spam → moves to spam, author reputation penalized
- [ ] Trash post → soft-deleted, author notified
- [ ] Resolve flag: mark as valid (action taken) or dismissed
- [ ] Ban user: IP ban, account ban with expiry date
- [ ] Unban user: restriction deleted, access restored

### Settings Page
- [ ] Settings sidebar shows all 5 core tabs: General, Permissions, Email, Appearance, SEO
- [ ] Each tab loads content without full page reload
- [ ] General tab: base slug, community name, post types
- [ ] Permissions tab: trust level thresholds configurable
- [ ] Email tab: from name, from email, notification templates
- [ ] Appearance tab: accent color, cover image, layout options
- [ ] SEO tab: title template, meta description template, sitemap on/off
- [ ] Settings saved correctly on submit (success notice)
- [ ] Invalid input rejected with appropriate error

### Setup Wizard
- [ ] Setup wizard launches on first activation
- [ ] Step 1: base URL slug → sets `jetonomy_settings[base_slug]`
- [ ] Step 2: initial spaces → creates 1–3 starter spaces
- [ ] Step 3: default trust thresholds
- [ ] Complete wizard → wizard flag set, no longer shows on dashboard
- [ ] Dismiss without completing → wizard dismissable

---

## 24. REST API — Extended Coverage

### Posts
- [ ] `POST /spaces/:id/posts` with `status=draft` → post created as draft (not public)
- [ ] `POST /spaces/:id/posts` with `tags=["tag1","tag2"]` → tags applied
- [ ] `PATCH /posts/:id` with `is_pinned=true` → post pinned at top of space
- [ ] `PATCH /posts/:id` with `is_closed=true` → post closed, no new replies
- [ ] `POST /posts/:id/close` — closes post; replying blocked after
- [ ] `POST /posts/:id/pin` — pins post (space admin only)
- [ ] `POST /posts/:id/move` with `space_id=456` → post moved; activity logged
- [ ] Closed post: `POST /posts/:id/replies` returns 403 with "post is closed" message

### Replies
- [ ] `GET /replies/:id` — returns single reply with author data, vote count
- [ ] `PATCH /replies/:id` — update reply content (creates revision)
- [ ] `DELETE /replies/:id` — soft delete reply; status=trash

### Voting
- [ ] `POST /posts/:id/vote` with `value=1` → upvote; score +1; reputation +1 to author
- [ ] `POST /posts/:id/vote` with `value=-1` → downvote; score -1
- [ ] `POST /posts/:id/vote` with same value again → vote removed (toggle)
- [ ] Flip vote (up → down) → old vote deleted, new vote created, score adjusted by 2
- [ ] `DELETE /posts/:id/vote` → vote removed, score restored
- [ ] Same endpoints for replies

### Tags
- [ ] `GET /tags` — returns all tags with usage_count, paginated
- [ ] `GET /tags?search=keyword` — filters tags by name
- [ ] `GET /tags/:slug` — returns single tag with recent posts

### Search
- [ ] `GET /search?q=keyword` — returns matching posts/spaces/users grouped
- [ ] Search `filter=posts` — only posts returned
- [ ] Search `filter=spaces` — only spaces returned
- [ ] Search `filter=tags` — only tags returned
- [ ] Search respects space visibility (private space results only for members)
- [ ] Pagination works on search results

### Leaderboard
- [ ] `GET /leaderboards?type=posts` — top posters, ranked by post count
- [ ] `GET /leaderboards?type=reputation` — highest reputation, ranked
- [ ] `GET /leaderboards?range=7d` — filters to last 7 days
- [ ] `GET /leaderboards?range=30d` — filters to last 30 days
- [ ] `GET /leaderboards?range=all` — all-time leaderboard

### Subscriptions
- [ ] `POST /subscriptions` with `space_id=123` → user subscribed to space
- [ ] `GET /subscriptions` → returns all user's subscriptions
- [ ] `DELETE /subscriptions/:id` → unsubscribed; no more notifications from space

### Notifications
- [ ] `GET /notifications` — returns unread notifications, paginated
- [ ] `GET /notifications?include_read=1` — includes read notifications
- [ ] `PATCH /notifications/:id` with `is_read=true` → single notification marked read
- [ ] `POST /notifications/mark-all-read` → all notifications marked read

---

## 25. Frontend Routes — Extended Coverage

- [ ] `/community/s/:slug/new/` — new post form loads; composer ready; space context set
- [ ] `/community/s/:slug/new/?reply_to=123` — composer pre-fills with quote from post 123
- [ ] `/community/u/:login/edit/` — profile edit page loads; display name, bio, avatar editable
- [ ] `/community/u/:login/edit/` save → profile updated; `jetonomy_user_profile_updated` hook fires
- [ ] `/community/tag/:slug/` — tag page loads; all posts with that tag listed
- [ ] `/community/tag/:slug/` pagination — works correctly
- [ ] `/community/leaderboard/` — loads; tabs: Posts, Replies, Votes Received, Reputation
- [ ] `/community/leaderboard/` time range selector: 7d, 30d, all-time
- [ ] `/community/notifications/` — loads all notifications; paginated
- [ ] `/community/notifications/` mark as read — individual and bulk

---

## 26. Trust Level System — Detailed Behavior

- [ ] Level 0 rate limiting: >3 posts in 24h → 4th post blocked with error "Daily post limit reached"
- [ ] Level 1+ has no rate limit on posts
- [ ] Auto-promotion: user reaches Level 2 thresholds → trust_level auto-updated within 6h (cron)
- [ ] `jetonomy_trust_level_changed` hook fires on auto-promotion (with user_id, old_level, new_level)
- [ ] Admin manual override: set user to Level 5 → override persists after auto-eval runs
- [ ] Level 0 cannot post links (stripped or blocked based on settings)
- [ ] Level 3+ can close topics
- [ ] Level 4+ can silence users
- [ ] Level 5 (Moderator) bypasses all restrictions

---

## 27. Membership Adapters — Integration Scenarios

### MemberPress
- [ ] MemberPress membership activated → user auto-joins all spaces with matching access rule
- [ ] MemberPress membership expires → user role in gated spaces downgraded to viewer
- [ ] New membership level created → map to space access rule in Jetonomy admin
- [ ] Adapter only loads when MemberPress plugin is active (no errors when inactive)

### Restrict Content Pro
- [ ] RCP subscription created → user auto-joins applicable spaces
- [ ] RCP subscription cancelled → access revoked after grace period
- [ ] RCP subscription level changed → space access updated accordingly
- [ ] Adapter only loads when RCP plugin is active

### WP Roles
- [ ] Administrator role → granted `space_admin` capability
- [ ] Editor role → granted `space_moderator` capability
- [ ] Subscriber → viewer role only
- [ ] Custom role mapping in Settings → Permissions works

### PMPro
- [ ] PMPro level assigned → user auto-joins gated spaces
- [ ] PMPro level removed → access revoked
- [ ] Adapter only loads when PMPro plugin is active

---

## 28. Future Test Infrastructure (Roadmap)

> These sections will be implemented in a future sprint. Track in PLANS-INDEX.md.

### WP-CLI Automated Tests (Planned)
- All commands in § 22 will have corresponding WP-CLI test assertions
- Scaffold: `wp scaffold plugin-tests jetonomy` → PHPUnit setup
- CLI tests will use `WP_UnitTestCase` with database reset per test

### PHP Unit Tests (Planned)
- Model classes: Space, Post, Reply, Vote, UserProfile (CRUD + edge cases)
- Permission Engine: all capability checks for each role
- Trust Level auto-evaluation logic
- REST API controllers: endpoint responses, permissions, pagination
- Notifier: event-to-notification mapping

> Until unit tests exist, § 1–27 manual QA is the authoritative test gate for release.
