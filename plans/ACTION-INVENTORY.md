# Jetonomy — Complete User Action Inventory

> Auto-generated from template scan. Update when new actions are added.
> Used by `wp jetonomy qa-actions` to ensure 100% coverage.

---

## REST-Backed Actions (27 total — these MUST be tested)

### Post Actions (7)

| Action | Endpoint | Method | Template | Who |
|--------|----------|--------|----------|-----|
| Create post | `/spaces/:id/posts` | POST | new-post.php | logged-in |
| Edit post | `/posts/:id` | PATCH | single-post.php | author-or-mod |
| Delete post | `/posts/:id` | DELETE | single-post.php | author-or-mod |
| Pin/unpin post | `/posts/:id/pin` | POST | single-post.php | mod |
| Move post | `/posts/:id/move` | POST | single-post.php | mod |
| Merge post | `/posts/:id/merge` | POST | single-post.php | mod |
| Close post | `/posts/:id/close` | POST | single-post.php | mod |

### Reply Actions (5)

| Action | Endpoint | Method | Template | Who |
|--------|----------|--------|----------|-----|
| Create reply | `/posts/:id/replies` | POST | composer.php | logged-in |
| Edit reply | `/replies/:id` | PATCH | reply-card.php | author-or-mod |
| Delete reply | `/replies/:id` | DELETE | reply-card.php | author-or-mod |
| Accept answer | `/replies/:id/accept` | POST | reply-card.php | post-author |
| Split to topic | `/replies/:id/split` | POST | reply-card.php | mod |

### Vote Actions (4)

| Action | Endpoint | Method | Template | Who |
|--------|----------|--------|----------|-----|
| Vote up post | `/posts/:id/vote` | POST `{value:1}` | single-post.php | logged-in |
| Vote down post | `/posts/:id/vote` | POST `{value:-1}` | single-post.php | logged-in |
| Vote up reply | `/replies/:id/vote` | POST `{value:1}` | reply-card.php | logged-in |
| Vote down reply | `/replies/:id/vote` | POST `{value:-1}` | reply-card.php | logged-in |

### Subscription Actions (4)

| Action | Endpoint | Method | Template | Who |
|--------|----------|--------|----------|-----|
| Follow post | `/subscriptions` | POST | single-post.php | logged-in |
| Unfollow post | `/subscriptions/:id` | DELETE | single-post.php | logged-in |
| Follow space | `/subscriptions` | POST | space.php | logged-in |
| Unfollow space | `/subscriptions/:id` | DELETE | space.php | logged-in |

### Other Actions (7)

| Action | Endpoint | Method | Template | Who |
|--------|----------|--------|----------|-----|
| Bookmark toggle | `/bookmarks` | POST | single-post.php | logged-in |
| Flag post | `/flags` | POST | single-post.php | logged-in (non-author) |
| Resolve flag | `/moderation/flags/:id/resolve` | POST | moderation.php | mod |
| Save profile | `/users/me` | PATCH | edit-profile.php | logged-in |
| Mark all read | `/notifications/mark-all-read` | POST | header.php | logged-in |
| Join space | `/spaces/:id/members` | POST | space.php | logged-in |
| Create notification | `/notifications` (internal) | — | notifier.php | system |

---

## Client-Only Actions (no REST call — UI state only)

| Action | Template | Description |
|--------|----------|-------------|
| sharePost | single-post.php | Copy link / social share dropdown |
| toggleMoreMenu | single-post.php, reply-card.php | Show/hide "..." dropdown |
| toggleThread | single-post.php | Collapse/expand reply thread |
| setReplyTo | reply-card.php | Set threaded reply target |
| cancelReplyComposer | composer.php | Cancel threaded reply |
| togglePublishMenu | new-post.php | Show publish mode dropdown |
| selectPublishNow/Draft/Schedule | new-post.php | Set publish mode |
| jtSetFontScale | header.php | A / A+ / A++ font size |
| Keyboard shortcuts (j/k/Enter/?) | header.php | Navigation + help modal |

---

## Read-Only REST Actions (background, no user trigger)

| Action | Endpoint | Method | Template | Trigger |
|--------|----------|--------|----------|---------|
| Load gap replies | `/posts/:id/replies` | GET | single-post.php | Scroll |
| Load more replies | `/posts/:id/replies` | GET | single-post.php | IntersectionObserver |
| Poll for new replies | `/updates` | GET | single-post.php | 30s interval |
| Poll unread count | `/notifications/unread-count` | GET | view.js | 60s interval |
| Notification dropdown | `/notifications?limit=5` | GET | header.php | Bell click |
| User hover card | `/users/:id` | GET | header.php | Mouse hover |
| Search suggestions | `/search?q=...` | GET | header.php | Keystroke |
| Space picker | `/spaces` | GET | view.js | Move post modal |
| Post picker | `/search?type=post` | GET | view.js | Merge post modal |
| Link preview | `/link-preview?url=...` | GET | view.js | Paste URL in editor |

---

## Coverage in `wp jetonomy qa-actions`

### Currently Tested (12/27 REST actions)

- [x] Create post
- [x] Create reply
- [x] Vote up post
- [x] Vote up reply
- [x] Bookmark on/off
- [x] Subscribe/unsubscribe
- [x] Flag
- [x] Accept answer
- [x] Notification create
- [x] Space membership check
- [x] Permission engine (4 actions)
- [x] Tag create

### Not Yet Tested (15/27 — add in v1.1)

- [ ] Edit post (PATCH)
- [ ] Delete post (DELETE)
- [ ] Pin post
- [ ] Move post
- [ ] Merge post
- [ ] Close post
- [ ] Edit reply (PATCH)
- [ ] Delete reply (DELETE)
- [ ] Split reply
- [ ] Vote down post
- [ ] Vote down reply
- [ ] Unfollow (DELETE subscription)
- [ ] Resolve flag
- [ ] Save profile (PATCH /users/me)
- [ ] Mark all notifications read
- [ ] Join space

---

## Pro Extension Actions (separate from core)

See: `plans/PRO-EXTENSION-QA-CHECKLIST.md`

| Extension | Key Actions |
|-----------|------------|
| Messaging | Send message, create conversation, mute |
| Reactions | Toggle reaction emoji |
| Polls | Vote on poll |
| Analytics | Export CSV |
| Badges | Manual award |
| Webhooks | Test dispatch |
| Custom Fields | Update post/profile fields |
