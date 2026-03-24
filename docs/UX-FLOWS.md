# Jetonomy — Manual Usability Flow Checklist

> **Purpose:** Role-based end-to-end flow testing. Do this by hand in a browser.
> Not automated scripts — this is human usability verification.
>
> Each flow is a complete scenario that a real user would perform.
> Check each box only when the flow completes without confusion, error, or visual glitch.
>
> **Site URL:** `http://forums.local`
> **Auto-login:** Append `?autologin=1` or `?autologin={username}` to any URL.

---

## Role Legend

| Role | Auto-login | What they can do |
|------|-----------|-----------------|
| **Site Owner** | `?autologin=1` | Everything — WP admin + community |
| **Moderator** | `?autologin=moderator` | Approve/flag content, ban users |
| **Regular Member** | `?autologin=member` | Post, reply, vote |
| **New Visitor** | (no autologin, incognito) | Browse only; prompted to register |

Create test accounts before running if they don't exist:

```bash
wp --path="/Users/varundubey/Local Sites/forums/app/public" user create moderator mod@test.local --role=subscriber --display_name="Test Mod"
wp --path="/Users/varundubey/Local Sites/forums/app/public" user create member1 member1@test.local --role=subscriber --display_name="Alice Member"
wp --path="/Users/varundubey/Local Sites/forums/app/public" user create member2 member2@test.local --role=subscriber --display_name="Bob Member"
```

---

## 1. Site Owner Flows

### 1A — Initial Setup & Configuration

- [ ] Go to `wp-admin/admin.php?page=jetonomy&autologin=1`
- [ ] **Setup wizard** appears on fresh install — step through all 5 steps without errors
- [ ] Settings → General tab saves: community name, posts-per-page, default sort
- [ ] Settings → Permissions tab: change trust-level requirement for posting, save, verify it persists
- [ ] Settings → Email tab: click "Send Test Email", receive it in inbox
- [ ] Settings → Advanced tab: "Flush Rewrite Rules" shows success toast
- [ ] **No PHP warnings** in Settings save flow (check debug.log)

---

### 1B — Category Management

- [ ] Admin → Categories: create a new category with name, description, icon, sort order
- [ ] Category appears in list immediately after save
- [ ] Edit the category: change name → list updates inline
- [ ] Drag-reorder two categories: order persists after page refresh
- [ ] Delete a category that has spaces: confirm modal appears, deletion cascades or prompts correctly
- [ ] Empty state shows correctly when all categories deleted

---

### 1C — Space Management

- [ ] Admin → Spaces: create a **public forum** space, assign to a category, set type = forum
- [ ] Create a **private Q&A** space (visibility = private, join_policy = open)
- [ ] Create a **hidden ideas** space (visibility = hidden, join_policy = approval)
- [ ] Edit a space: change description, cover image — changes appear on the frontend space page
- [ ] Archive a space: banner shows "This space is archived" on frontend; New Post button hidden
- [ ] Lock a space: banner shows "This space is locked"; New Post button hidden
- [ ] Delete a space with content: confirmation prompt appears

---

### 1D — User & Trust Management (Admin)

- [ ] Admin → Users: ban a user (member1) — `is_banned = true` in profile
- [ ] Verify banned user cannot post: log in as member1, attempt to reply → blocked
- [ ] Unban member1 from admin panel → member1 can post again
- [ ] Manually set a user's trust level to 3 (Expert) from admin panel
- [ ] Verify trust level badge shows "Expert" on that user's profile and posts
- [ ] Override trust level back to auto-calculated

---

### 1E — Content Moderation (Admin)

- [ ] Admin → Content: view post list — displays title, space, author, status
- [ ] Filter by status (pending/trash/spam) — list updates correctly
- [ ] Trash a post from admin panel: post disappears from frontend space listing
- [ ] Bulk action: select 3 posts → Trash All → all removed
- [ ] Mark a post as spam: status changes, author's reputation decremented

---

### 1F — Import

- [ ] Admin → Import → bbPress: select a bbPress export file, step through progress tracker
- [ ] Progress bar advances and shows record counts
- [ ] If import fails mid-way: "Resume" button restarts from last successful batch
- [ ] After completion: imported categories/spaces/posts visible on frontend
- [ ] Run import a second time on same data: duplicates are NOT created (idempotent)

---

### 1G — Demo Data

- [ ] Admin → Setup → Seed Demo Data: creates spaces, posts, replies, users
- [ ] Community home shows populated spaces and recent posts
- [ ] Admin → Setup → Clean Up Demo: removes all seeded content
- [ ] Verify no orphaned data remains after cleanup (user profiles, votes, tags)

---

### 1H — Site Owner Reading & Moderating Community

- [ ] Browse community as admin: see all private/hidden spaces in navigation
- [ ] Open a private space — full access (no gate shown)
- [ ] Flag button visible on posts/replies: submit a flag
- [ ] Admin → Moderation: flag appears in list, resolve it → removed from list
- [ ] Pin a post from the admin moderation view: post shows "pinned" indicator at top of space

---

## 2. Moderator Flows

### 2A — Moderator Setup

- [ ] Log in as moderator user (`?autologin=moderator`)
- [ ] Community nav shows correctly — no WP admin bar items that moderators shouldn't see
- [ ] Access `/community/mod/` — moderation page loads with pending flags
- [ ] WP admin is accessible (Jetonomy menu visible but limited — no Settings, no Import)

---

### 2B — Content Review & Action

- [ ] View a flagged post in moderation queue
- [ ] Click **Approve**: post status → published, flag resolved, page updates without reload
- [ ] Click **Spam**: post status → spam, user's spam count incremented
- [ ] Click **Trash**: post removed from public view
- [ ] Resolve a flag without taking action (mark as "dismissed")
- [ ] Verify resolved flags disappear from the queue

---

### 2C — Reply Moderation

- [ ] Flag a reply from the frontend post view
- [ ] Switch to moderator account: see reply flag in moderation queue
- [ ] Trash the reply: reply disappears from the thread; post reply_count decremented
- [ ] Reply count on the post card in the space listing is correct after deletion

---

### 2D — User Ban (Moderator)

- [ ] From a user's profile page (as moderator): Ban User button visible
- [ ] Ban the user: confirmation dialog appears → confirm
- [ ] Banned user's posts remain visible but grayed out / marked
- [ ] Banned user attempting to post: gets "You are banned" error
- [ ] Moderator can unban from the same profile page

---

## 3. Regular Member Flows

### 3A — First Visit & Registration

- [ ] Visit `http://forums.local/community/` as incognito (no autologin)
- [ ] Community homepage loads: categories and spaces visible
- [ ] Click a public space: posts list visible without login
- [ ] Click "Log in to post" button: redirected to WP login, then back to space
- [ ] After login: "+ New Post" button appears in space header
- [ ] User profile created automatically on first login (no 500 error)

---

### 3B — Discovering the Community

- [ ] Home page → all top-level categories visible
- [ ] Click a category: shows spaces in that category
- [ ] Click a space: shows post listing with sorting tabs (Latest / Popular / Unanswered)
- [ ] Switch between sort tabs: list reloads with correct ordering
- [ ] Breadcrumb shows: Home → Category → Space (each level is a link)
- [ ] Space stats (Posts count, Members count) are visible and accurate

---

### 3C — Creating a Post

- [ ] Log in as member1 (`?autologin=member1`)
- [ ] Navigate to a public forum space → click "+ New Post"
- [ ] New post form loads with: title input, rich editor, tags input
- [ ] Type in editor: **bold** (Ctrl+B), *italic* (Ctrl+I), `code` (Ctrl+\`), blockquote
- [ ] Paste an image into the editor → image preview appears inline
- [ ] Add 2 tags via autocomplete (type 3 chars → suggestions appear)
- [ ] Submit form: redirected to the new post page
- [ ] Post appears in the space listing immediately

---

### 3D — Editor Shortcuts & Toolbar

- [ ] Open new post form
- [ ] Press `?` key: keyboard shortcut help modal opens
- [ ] Press `Escape`: modal closes
- [ ] Press `j` / `k` in space view: navigate between posts
- [ ] Press `Enter` on focused post: opens the post
- [ ] Press `l` on a post: vote up (if logged in)
- [ ] Press `r` on a post: opens reply composer

---

### 3E — Joining a Space

- [ ] As member1, find a **private open-join** space (visibility=private, join_policy=open)
- [ ] Space gate shows "Join Space" button
- [ ] Click "Join Space": button changes to "Joined" / space content becomes visible
- [ ] Refresh: still a member, content still visible
- [ ] Find an **approval-required** space: gate shows request form with optional message
- [ ] Submit join request: confirmation shown; space content still gated
- [ ] As site owner: Admin → Spaces → Members → approve the request
- [ ] As member1: space now accessible without gate

---

### 3F — Reading & Replying

- [ ] Open a post with 20+ replies
- [ ] First 10 and last 10 replies load immediately; "Load More" gap button visible in between
- [ ] Click gap button: intermediate replies load inline (no page reload)
- [ ] Reply sort tabs: switch to "Best" → highest-voted replies first; switch to "Oldest" → chronological
- [ ] Write a reply: click "Reply" button below a reply to nest it
- [ ] Nested reply shows "Replying to @name" indicator
- [ ] Submit reply: appears immediately in thread; reply count on post increments

---

### 3G — Voting

- [ ] Upvote a post: vote count increments immediately (optimistic); button active state shows
- [ ] Upvote same post again: vote removed (undo)
- [ ] Downvote a post: count decrements; user's reputation decremented
- [ ] Vote on a reply: vote count updates correctly
- [ ] Reload page: votes persist (not just optimistic)
- [ ] Check post author's reputation: increased by correct points (+10 for upvote)

---

### 3H — Search

- [ ] Press `/` key anywhere in community: search bar focuses
- [ ] Type 3+ characters: instant results appear (posts, spaces, users) with < 300ms
- [ ] Click a result: navigated to that resource
- [ ] Go to `/community/search/?q=test`: full search results page with sections
- [ ] Search for a tag name: matching posts appear
- [ ] Empty search (`?q=`): shows "Enter a search term" state

---

### 3I — Notifications

- [ ] member2 replies to a post by member1
- [ ] Log in as member1: notification bell shows unread count badge
- [ ] Click notifications bell → `/community/notifications/`
- [ ] Notification for the reply visible with "X replied to your post"
- [ ] Click notification: goes to the post with the reply highlighted
- [ ] Mark as read: notification no longer bold; unread count decrements
- [ ] "Mark all read" button: all notifications marked; count resets to 0
- [ ] Bell updates in near-real-time (within 30 seconds of a new reply)

---

### 3J — User Profile

- [ ] Click own username → user profile page
- [ ] Profile shows: avatar, display name, bio, trust level badge, post count, reply count, reputation
- [ ] Tabs work: Posts / Badges / Activity (each loads correct content)
- [ ] Activity tab shows recent actions with human-readable descriptions ("Posted in …", "Replied to …")
- [ ] Click "Edit Profile": goes to `/community/u/{login}/edit/`
- [ ] Edit bio and interests, save: changes appear on profile page immediately
- [ ] View another user's profile: "Edit Profile" button NOT visible

---

### 3K — Invite Links

- [ ] As site owner: Admin → Spaces → select a space → Invite Links → create invite with 7-day expiry
- [ ] Copy the invite link (`/community/invite/:code/`)
- [ ] Open invite link as logged-out user: landing page shows space name, "Join Space" CTA
- [ ] Click Join: auto-joins the space (or submits request if approval required)
- [ ] Open an expired invite link: "This invite has expired" message

---

### 3L — Quote Text Feature

- [ ] Open a post with a long reply
- [ ] Select text in a reply with the mouse
- [ ] Quote button floats near selection: click it
- [ ] Composer opens with the quoted text as a blockquote
- [ ] Edit and submit reply: blockquote visible in rendered reply

---

### 3M — Hover Cards

- [ ] Hover over a username in a post/reply list
- [ ] After ~400ms: hover card appears with avatar, display name, trust level, short bio, profile link
- [ ] Move mouse away: card disappears
- [ ] Hover card on mobile (tap): verify no broken state on small screen

---

## 4. Unauthenticated Visitor Flows

### 4A — Browsing Public Content

- [ ] Open `http://forums.local/community/` with no session
- [ ] Categories and spaces visible
- [ ] Click into a public space: post listing visible
- [ ] Click into a post: content visible, "Log in to reply" prompt shown
- [ ] Vote button: clicking prompts login (no silent failure)
- [ ] Leaderboard (`/community/leaderboard/`): visible without login

---

### 4B — Private Space Gate

- [ ] Navigate to a private space URL directly
- [ ] Gate shows: "This space is private. Please log in to request access."
- [ ] "Log In" button redirects to login and returns to space after login
- [ ] After login as non-member: gate shows join option (appropriate to join_policy)

---

### 4C — 404 Handling

- [ ] Visit `/community/s/nonexistent-space/`: shows 404 empty state (not WP 404)
- [ ] Visit `/community/s/real-space/t/nonexistent-post/`: shows 404 empty state
- [ ] Visit `/community/u/nosuchuser/`: shows 404 empty state

---

## 5. Cross-Role Interaction Flows

### 5A — Accept Answer (Q&A Space)

- [ ] Create a Q&A-type space (type = qa)
- [ ] Post a question as member1
- [ ] member2 replies with an answer
- [ ] member1 (question author): "Accept Answer" button visible on the reply
- [ ] Click Accept: reply gets accepted indicator; post shows "Answered" status badge
- [ ] member2 receives notification "Your answer was accepted" (+15 reputation)
- [ ] Accepted reply appears pinned at top when sort = "Best"

---

### 5B — Ideas / Roadmap Space

- [ ] Create an ideas space (type = ideas)
- [ ] Post an idea as member1
- [ ] Other members vote on the idea: vote score accumulates
- [ ] `/community/s/:slug/roadmap/` loads the kanban board
- [ ] Site owner drags idea from "Under Consideration" to "In Progress"
- [ ] Status change visible on the idea's post detail page

---

### 5C — Multi-Role Space Membership

- [ ] Site owner creates a private space
- [ ] Grants member1 the **moderator** role in that space
- [ ] member1 can now: pin/trash content within that space only
- [ ] member1 CANNOT moderate other spaces
- [ ] Grant member2 the **viewer** role: can read but not post

---

### 5D — Mention Notification

- [ ] member1 writes a reply mentioning `@member2` by name in a post
- [ ] member2 receives a notification: "member1 mentioned you in …"
- [ ] Clicking notification goes to the post with the reply visible
- [ ] member2 is NOT the post author (tests separate mention vs. reply notification paths)

---

### 5E — Tag Browsing

- [ ] Post uses tags: "announcements", "bug-report"
- [ ] Click a tag badge on the post: goes to `/community/tag/announcements/`
- [ ] Tag page shows all posts with that tag across all accessible spaces
- [ ] Private space posts NOT visible in tag results for non-members

---

## 6. Edge Case & Stress Flows

### 6A — Empty States

- [ ] New space with no posts: empty state with CTA "Be the first to post"
- [ ] User with no posts: Profile → Posts tab shows empty state
- [ ] No notifications: Notifications page shows "You're all caught up!" state
- [ ] Search with no results: "No results found for 'xyz'" state

---

### 6B — Restricted Space

- [ ] Archive a space
- [ ] Visit space as member: yellow "archived" banner visible
- [ ] "+ New Post" button hidden
- [ ] All existing posts/replies remain readable
- [ ] Replying to an existing post: reply button hidden

---

### 6C — Long Content

- [ ] Post with 500-word content: renders correctly, no layout overflow
- [ ] Reply with nested quote-in-quote: renders without breaking layout
- [ ] Post with 10 tags: all tags display; no overflow
- [ ] Space name with 80 characters: truncates correctly in space listing

---

### 6D — RTL Language

- [ ] In WP admin: switch site language to Arabic (ar)
- [ ] Visit `/community/`: navigation, layout, icons mirror correctly (RTL)
- [ ] Breadcrumb arrows point left
- [ ] Composer toolbar aligns right
- [ ] Switch back to English: layout restores

---

### 6E — Mobile Viewport

Test these at 390px width (iPhone 14 viewport):

- [ ] Community home: single-column space grid (not 3-column)
- [ ] Space listing: post rows stack vertically; vote buttons above title
- [ ] Single post: composer toolbar wraps cleanly; no horizontal scroll
- [ ] Admin dashboard: stat cards stack; tables scroll horizontally
- [ ] Navigation: hamburger or stacked menu (no overflowing items)

---

## Checklist Summary

| Flow | Role | Items | Status |
|------|------|-------|--------|
| 1A Initial Setup | Site Owner | 7 | |
| 1B Categories | Site Owner | 6 | |
| 1C Spaces | Site Owner | 7 | |
| 1D Users/Trust | Site Owner | 6 | |
| 1E Content (Admin) | Site Owner | 5 | |
| 1F Import | Site Owner | 5 | |
| 1G Demo Data | Site Owner | 4 | |
| 1H Admin Moderating | Site Owner | 5 | |
| 2A Mod Setup | Moderator | 4 | |
| 2B Content Review | Moderator | 6 | |
| 2C Reply Moderation | Moderator | 4 | |
| 2D User Ban | Moderator | 4 | |
| 3A First Visit | Member | 6 | |
| 3B Discovering | Member | 6 | |
| 3C Creating Post | Member | 7 | |
| 3D Shortcuts | Member | 7 | |
| 3E Joining Space | Member | 7 | |
| 3F Reading & Replying | Member | 7 | |
| 3G Voting | Member | 6 | |
| 3H Search | Member | 6 | |
| 3I Notifications | Member | 8 | |
| 3J User Profile | Member | 7 | |
| 3K Invite Links | Member | 5 | |
| 3L Quote Text | Member | 4 | |
| 3M Hover Cards | Member | 4 | |
| 4A Browsing Public | Visitor | 5 | |
| 4B Private Gate | Visitor | 4 | |
| 4C 404 Handling | Visitor | 3 | |
| 5A Accept Answer | Multi | 6 | |
| 5B Ideas/Roadmap | Multi | 5 | |
| 5C Multi-Role Members | Multi | 5 | |
| 5D Mention Notify | Multi | 4 | |
| 5E Tag Browsing | Multi | 4 | |
| 6A Empty States | Any | 5 | |
| 6B Restricted Space | Any | 5 | |
| 6C Long Content | Any | 4 | |
| 6D RTL Language | Any | 6 | |
| 6E Mobile Viewport | Any | 5 | |
| **TOTAL** | | **~190 checks** | |

---

*Last updated: 2026-03-24*
