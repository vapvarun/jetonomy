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
