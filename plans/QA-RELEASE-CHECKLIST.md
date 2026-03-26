# Jetonomy Free — QA Release Checklist

> Run this COMPLETE checklist before every release. No shortcuts. Every item must pass.
> Estimated time: 2-3 hours for full run.

---

## Part 1: CLI Automated Checks (run first — blocks release if any fail)

### 1.1 PHP Syntax
```bash
find includes/ templates/ -name "*.php" -exec php -l {} \; 2>&1 | grep -v "No syntax errors"
# Must return ZERO lines
```

### 1.2 WPCS (WordPress Coding Standards)
```bash
# Via MCP or direct
mcp__wpcs__wpcs_check_directory({ path: "includes/", standard: "WordPress" })
mcp__wpcs__wpcs_check_directory({ path: "templates/", standard: "WordPress" })
# Zero errors required. Warnings acceptable.
```

### 1.3 PHPStan Level 5
```bash
mcp__wpcs__wpcs_phpstan_check({ path: "includes/", level: 5 })
# Zero errors required
```

### 1.4 Undefined Variables in Templates
```bash
# Check every template for undefined variable access
grep -rn '\$space->' templates/ | grep -v 'isset\|!empty\|if.*\$space\|null' | head -20
grep -rn '\$post->' templates/ | grep -v 'isset\|!empty\|if.*\$post\|null' | head -20
grep -rn '\$reply->' templates/ | grep -v 'isset\|!empty\|if.*\$reply\|null' | head -20
# Review every hit — each must have a null guard or be in a scope where the variable is guaranteed
```

### 1.5 Field Name Consistency (emoji vs icon, etc.)
```bash
# These should return ZERO results
grep -rn '->emoji' templates/ includes/
grep -rn '->url\b' templates/views/notifications.php  # should be ->object_url
```

### 1.6 Hardcoded URLs
```bash
# These should return ZERO results (all URLs must use base_url() or dynamic base)
grep -rn "'/community/" assets/js/ templates/
grep -rn '"/community/' assets/js/ templates/
# Exception: docs/ files may reference /community/ as documented default
```

### 1.7 Nonce Consistency
```bash
# Find all wp_create_nonce calls and verify they're consumed
grep -rn 'wp_create_nonce' templates/ | grep -v 'wp_rest'
# Every non-wp_rest nonce MUST have a corresponding check somewhere. If not, it's dead code — remove it.
```

### 1.8 REST Route ↔ JS Fetch Cross-Reference
```bash
# List all registered REST routes
grep -rn "register_rest_route" includes/api/ | grep -oP "'[^']+'" | sort

# List all JS fetch URLs
grep -rn "fetch(" assets/js/ | grep -oP "[\`'\"].*?[\`'\"]" | sort

# Manually compare: every JS fetch URL must match a registered route
```

### 1.9 Permission Callback Audit
```bash
# Find any write endpoint with __return_true (should be ZERO)
grep -A5 "methods.*POST\|methods.*PATCH\|methods.*DELETE" includes/api/ | grep "__return_true"
# Every POST/PATCH/DELETE endpoint must have a real permission callback
```

### 1.10 Notification Type Key Alignment
```bash
# Extract type keys from Notifier
grep -oP "create_and_maybe_email.*?'([^']+)'" includes/notifications/class-notifier.php

# Extract type keys from admin settings
grep -oP "'([a-z_]+)'" includes/admin/class-admin.php | sort -u

# Extract type keys from notifications template
grep -oP "'([a-z_]+)'" templates/views/notifications.php | sort -u

# All three lists must be identical
```

### 1.11 Rate Limiter Bypass
```bash
# Verify admin/moderator bypass exists
grep -n "manage_options\|jetonomy_moderate" includes/permissions/class-rate-limiter.php
# Must find the bypass check before the limit check
```

### 1.12 Database Table Check
```bash
wp --path="/path/to/wp" db tables --all-tables | grep jt_
# Must show all 22 jt_ tables
```

---

## Part 2: Manual Flow Checks — Admin User

> Log in as admin (user ID 1). Open browser Network tab, filter XHR. Check debug.log after each page.

### 2.1 Community Home
- [ ] `/community/` loads, 0 PHP errors
- [ ] All space categories render with icons (not `->emoji`)
- [ ] Sidebar: Trending, Top Members, Popular Tags all populated
- [ ] Nav links: Community, Search, Leaderboard, My Profile, Moderation, Messages (if Pro)

### 2.2 Space Page
- [ ] `/community/s/help-support/` loads, 0 PHP errors
- [ ] Topic list with vote counts, trust level badges, reply counts, timestamps
- [ ] Sort tabs: Latest, Popular, Unanswered — all clickable
- [ ] "+ Ask a Question" button visible for admin
- [ ] Follow button visible and clickable
- [ ] Sidebar renders alongside (not below)

### 2.3 Single Topic
- [ ] `/community/s/help-support/t/rest-api-403-on-post-endpoints/` loads, 0 PHP errors
- [ ] Vote up/down buttons — click vote, score updates, NO rate limit error for admin
- [ ] Replies render with trust level badges
- [ ] Accepted answer highlighted (green border)
- [ ] Reply composer visible with Markdown toolbar
- [ ] Share, Bookmark, Report buttons all visible
- [ ] "..." menu: Edit, Delete, Pin, Move, Close visible for admin
- [ ] React button visible (Pro)
- [ ] Hover card: hover on username → single card appears (not doubled)

### 2.4 Create New Post
- [ ] `/community/s/general-discussion/new/` loads, 0 PHP errors
- [ ] Title, Tags, Content fields — single borders (no double)
- [ ] "Post Topic" button + dropdown (Publish now, Save as draft, Schedule)
- [ ] Type and submit a test post → redirects to new post URL
- [ ] Check Network tab: `POST /spaces/:id/posts` returns 201

### 2.5 Search
- [ ] `/community/search/?q=wordpress` loads, results render
- [ ] Filter tabs: All, Posts, Spaces, Tags
- [ ] Results show vote/reply counts and content previews
- [ ] Sidebar visible

### 2.6 User Profile
- [ ] `/community/u/jt_demo_alice/` loads, shows reputation, badges, posts
- [ ] "Message" button visible (Pro active, viewing other user's profile)
- [ ] Tabs: Posts, Replies, Votes, Bookmarks work
- [ ] Own profile shows "Edit Profile" button (not Message)

### 2.7 Edit Profile
- [ ] `/community/u/admin/edit/` loads, 0 PHP errors
- [ ] Sidebar visible alongside edit form
- [ ] Display Name, Bio, Avatar, Notification Preferences all render
- [ ] Save → redirects to profile

### 2.8 Notifications
- [ ] Bell icon dropdown: shows notifications (not empty)
- [ ] "View all notifications" → `/community/notifications/` loads
- [ ] Notification items render with correct labels (not raw type strings)
- [ ] Mark all read works

### 2.9 Leaderboard
- [ ] `/community/leaderboard/` loads, all members listed
- [ ] Period filter: `?period=week` shows subset, `?period=all` shows all
- [ ] Sidebar visible (not double-nested)

### 2.10 Moderation Queue
- [ ] `/community/mod/` loads (admin has access)
- [ ] Pending items shown with View/Remove/Dismiss buttons
- [ ] Dismiss a flag → item removed from queue (check Network: 200)

### 2.11 Admin Settings
- [ ] `/wp-admin/admin.php?page=jetonomy-settings` → General tab loads
- [ ] Switch to Permissions tab → trust thresholds show current values
- [ ] Save Permissions tab → switch to General tab → General values NOT reset
- [ ] All tabs load: General, Permissions, Email, Appearance, SEO, Anti-Spam, License

### 2.12 Admin Spaces
- [ ] `/wp-admin/admin.php?page=jetonomy-spaces` → space list loads
- [ ] Edit a space → all settings render (type, visibility, join policy, etc.)
- [ ] "Allow Voting" checkbox saves correctly (toggle off → save → reload → still off)
- [ ] "Posts Per Page" saves correctly per-space

### 2.13 Admin Dashboard
- [ ] `/wp-admin/admin.php?page=jetonomy` → stats cards render
- [ ] Activity feed populates
- [ ] Quick Actions work (Create Space, View Community, Flush Rules)

---

## Part 3: Manual Flow Checks — Regular User (TL1-3)

> Log OUT of admin. Log in as Alice Chen (`?autologin=jt_demo_alice`).

### 3.1 Navigation
- [ ] No "Moderation" link in nav (Alice lacks `jetonomy_moderate`)
- [ ] No admin toolbar items (no Dashboard, no Customize)
- [ ] My Profile links to Alice's profile

### 3.2 Viewing & Voting
- [ ] Can view all public spaces and topics
- [ ] Vote buttons visible and functional (TL3 = no rate limit)
- [ ] Reply composer visible on topics

### 3.3 Own Content
- [ ] Edit/Delete visible ONLY on Alice's own posts and replies
- [ ] Edit/Delete NOT visible on other users' posts
- [ ] Accept Answer NOT visible on topics Alice didn't author

### 3.4 Creating Content
- [ ] Can create new topic in any open space
- [ ] Can reply to any topic

### 3.5 Restricted Spaces
- [ ] Invite-only space: shows "Invite Only" badge (not Follow/Join button)
- [ ] Approval space: shows "Request to Join" button (not Follow)
- [ ] Non-member of restricted space: NO "New Post" button

### 3.6 Moderation Page
- [ ] `/community/mod/` → "You do not have permission" (access denied)

### 3.7 Profile
- [ ] Own profile: Edit Profile button, Drafts tab visible
- [ ] Other user's profile: Message button (Pro), no Edit button
- [ ] Edit profile: sidebar visible, notification preferences render

---

## Part 4: Manual Flow Checks — Guest (Logged Out)

> Log out completely.

### 4.1 Public Access
- [ ] Community home loads, spaces visible
- [ ] Space page loads, topics visible
- [ ] Single topic loads, content visible
- [ ] Search works

### 4.2 Gated Actions
- [ ] Vote buttons NOT visible (read-only score shown)
- [ ] Reply composer NOT visible — "Log in to reply" link shown
- [ ] "New Post" button → "Log in to post" link shown
- [ ] Notifications page → redirects to login
- [ ] Edit profile → redirects to login
- [ ] Messages → redirects to login

---

## Part 5: Space Settings Enforcement

> Log in as admin. For each setting, change it and verify enforcement.

### 5.1 Who Can Post = "Administrator"
- [ ] Save space setting → log in as Alice → "New Post" button hidden
- [ ] If Alice navigates to `/s/:slug/new/` directly → form shows but submit returns 403

### 5.2 Who Can Reply = "Moderator"
- [ ] Save → Alice sees no reply composer on topics in that space

### 5.3 Allow Voting = Off
- [ ] Save → Alice sees no vote buttons on topics in that space

### 5.4 Require Approval = On
- [ ] Alice creates post → status set to "pending" → toast shows moderation message
- [ ] Post appears in admin moderation queue
- [ ] Admin approves → post becomes visible

### 5.5 Visibility = Private
- [ ] Non-members cannot access the space (redirected or access denied)
- [ ] Members can access normally

### 5.6 Join Policy = Invite Only
- [ ] Non-members see "Invite Only" badge, no join button
- [ ] Admin creates invite link → non-member uses link → becomes member

### 5.7 Join Policy = Require Approval
- [ ] Non-members see "Request to Join" button
- [ ] Click → request submitted (202)
- [ ] Admin approves → user becomes member

---

## Part 6: Post-Fix Regression Checks

> After any bug fix, run these specific checks to catch common regressions:

- [ ] Settings save: change one tab, verify other tabs unchanged
- [ ] Notification type keys: Notifier types match settings keys match template labels
- [ ] Permission Engine: who_can_post/who_can_reply checks run before public+open shortcut
- [ ] Vote rate limit: admin/moderator bypass works
- [ ] Follow/Unfollow: check Network tab for correct response shape (`data.data`)
- [ ] Accept Answer: `$space` variable passed to reply-card partial
- [ ] Hover card: only one instance (not doubled)
- [ ] Bell dropdown: shows content (not empty)
- [ ] Input borders: single border, no double

---

## Sign-Off

| Check | Passed | Date | Tester |
|-------|--------|------|--------|
| Part 1: CLI | | | |
| Part 2: Admin flows | | | |
| Part 3: Regular user flows | | | |
| Part 4: Guest flows | | | |
| Part 5: Space settings | | | |
| Part 6: Regression | | | |

**Release blocked until ALL parts pass.**
