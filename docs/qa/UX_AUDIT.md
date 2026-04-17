# Jetonomy — UX Audit Checklist

How to use: walk this top-to-bottom on a real site (staging or Local) as a
**logged-in admin**, then repeat as an **anonymous visitor** and as a
**logged-in regular member** (trust level 1 or 2). Mark ✅ / ❌ next to
each item. File a Basecamp card for every ❌.

Also run at **390px viewport** and in **dark mode** — every section has a
mobile and dark check.

---

## Global chrome (every page)

- [ ] Page title reflects the current view (e.g. "Space Name – Site")
- [ ] Jetonomy header/nav renders, admin bar doesn't overlap
- [ ] Current nav link has active state (Community / Search / Leaderboard / My Profile / Moderation / Messages)
- [ ] Notifications bell shows unread count when > 0
- [ ] Font scale buttons (A / A+ / A++) work
- [ ] Dark mode toggle (if Reign/BuddyX) flips colors; no hard-coded whites
- [ ] At 390px: nav collapses cleanly, no horizontal scroll, touch targets ≥ 44px

## Home — /community/

- [ ] Category sections render in sort order
- [ ] Empty categories hidden (when setting enabled)
- [ ] Each space card: title, description, post_count, member_count, space type, visibility
- [ ] Sidebar: auth card (Login OR User Panel), Trending, Top Members, Popular Tags
- [ ] Click a space → correct space listing page
- [ ] Mobile: single column, cards stack, readable text

## Category — /community/category/:slug/

- [ ] Breadcrumb: Home / Category
- [ ] Spaces in this category only
- [ ] Correct count + pagination if > 20 spaces
- [ ] Mobile check

## Space listing — /community/s/:slug/

- [ ] Breadcrumb includes category + space
- [ ] Sort tabs: Latest / Oldest / Best work
- [ ] Post cards: title, excerpt, author, reply count, vote score, age, pin/lock indicators
- [ ] "New Post" button (visible only to allowed roles)
- [ ] "Join Space" button for non-members (if approval/invite)
- [ ] Sidebar About card shows stats + members link
- [ ] Pagination via cursor (no `COUNT(*)` spike at scale)
- [ ] Mobile check

## Single post — /community/s/:slug/t/:slug/

- [ ] Title, body with paragraphs preserved (card 9797743212 fix)
- [ ] Author card: avatar, name, trust level, age, Follow button
- [ ] Post body renders embeds (oEmbed), mentions, hashtags
- [ ] Vote up/down works, count updates
- [ ] Share, Bookmark, More-options work
- [ ] More-options → Edit opens inline editor; paragraphs preserved on open + save
- [ ] More-options → Pin, Move, Merge, Delete for privileged users
- [ ] Replies section: Oldest/Newest/Best
- [ ] Each reply: author, body (paragraphs), vote, Reply, Quote, React, More-options
- [ ] Quote button prefills composer with blockquote
- [ ] Reply composer renders when logged in + allowed
- [ ] Reply submit → new reply appears without full refresh
- [ ] Mobile check
- [ ] Dark mode: all accent/background/border tokens flip

## Composer (new post/reply)

- [ ] Formatting buttons (B / I / code / link / quote / upload)
- [ ] Multi-paragraph typing preserved on submit (Enter twice → `<p><p>`)
- [ ] Image upload respects limits; preview renders
- [ ] Markdown hint shown; keyboard Ctrl+Enter submits
- [ ] Captcha shows when configured
- [ ] Akismet skip for admins/moderators (card 9797397081)

## Profile — /community/u/:login/

- [ ] Avatar (large), display name, trust level badge
- [ ] Stats: posts, replies, reputation, joined date
- [ ] Recent activity tab
- [ ] Badges tab (Pro) — Pro-only; free shows "Upgrade" CTA cleanly
- [ ] Mobile check

## Edit profile — /community/u/:login/edit/

- [ ] Form fields load current values
- [ ] Save → toast + values persist on reload
- [ ] Password change flow works
- [ ] Notification preferences grid saves correctly

## Notifications — /community/notifications/

- [ ] List loads with read + unread distinction
- [ ] Click item → navigates to source + marks read
- [ ] "Mark all read" button clears badges

## Moderation — /community/mod/

- [ ] Queue: pending flags visible, spam flags visible (with `status=spam`)
- [ ] Flag card: small View / Remove / Dismiss buttons (no hero-sized buttons)
- [ ] Remove → row disappears, counters update
- [ ] Dismiss → row disappears, content stays live
- [ ] Mobile check: row stacks, buttons stay usable

## Messages (Pro) — /community/messages/

- [ ] Conversation list renders with unread counts
- [ ] New conversation flow from user profile or user search
- [ ] Send → appears immediately; real-time delivery if poll adapter configured
- [ ] Mute conversation works
- [ ] Read tracking updates on conversation open
- [ ] Mobile: two-pane collapses to single pane

## Blocks

- [ ] Forum Feed block: count, space, sort attributes
- [ ] Space List block: count, category attribute
- [ ] Leaderboard block: count attribute
- [ ] Navigation block: permission-aware tree, active-space highlight, collapsible (`<details>`)
- [ ] Login block: tabs appear only when `users_can_register=1`, inline AJAX login + register, reload on success
- [ ] User Panel block: logged-in only, avatar + unread badge + links, empty for guests
- [ ] All blocks at 390px: card width fits, taps work

## Admin — each list page

For: Categories / Tags / Spaces / Content > Posts / Content > Replies / Moderation / Users / Pro > Polls:

- [ ] `.wrap.jetonomy-admin` wrapper present
- [ ] Table inside `.jt-content-table-wrap` card (no raw edges)
- [ ] Toolbar (search / per-page / bulk) in-card, Search pinned right
- [ ] Pagination bar styled: pill buttons, 32px, current filled blue
- [ ] Empty state message is friendly
- [ ] Mobile: toolbar wraps, table scrolls horizontally if needed

## Accessibility smoke

- [ ] All interactive elements reachable by keyboard (Tab cycles)
- [ ] Focus rings visible on inputs and buttons
- [ ] Images have `alt`
- [ ] Status messages use `role="alert"` or `aria-live="polite"`
- [ ] Modal traps focus; Escape closes

## Performance smoke

- [ ] Home loads < 2s on typical hosting (no N+1)
- [ ] Sidebar widgets don't trigger additional queries per item
- [ ] Pagination uses LIMIT/OFFSET, not full-table loads
