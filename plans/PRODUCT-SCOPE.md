# Jetonomy Product Scope

> Complete scope for core forum functionality and every Pro module. Build to this spec. No feature creep. No half implementations.

---

## Core Forum (Free Plugin)

### Spaces & Categories

**What users see:**
- Community home: categories with space cards showing post count, member count, activity bar
- Each space: topic listing with vote score, reply count, author, tags, time
- Sub-spaces: child spaces under a parent (max 3-tier: Category → Space → Sub-Space)
- Space types: Forum (discussion), Q&A (voting + accepted answer), Ideas (status tracking)

**What admins configure:**
- Create/edit/delete categories and spaces via WP Admin
- Set space type, visibility (public/private/hidden), join policy (open/approval/invite)
- Assign space moderators
- Set per-space permission overrides (who can post, who can reply, require approval)
- Drag-sort ordering for categories and spaces

**REST API:**
- `GET /categories` — nested tree
- `GET /spaces` — filter by category, type, visibility
- `GET /spaces/:id` — detail with stats
- `CRUD /spaces` — create, update, delete
- `GET /spaces/:id/members` — paginated member list
- `POST /spaces/:id/members` — join/request
- `PATCH /spaces/:id/members/:uid` — change role

---

### Posts & Replies

**What users see:**
- Create post with title, content (rich editor), tags
- Single post view: full content, vote buttons, reply count, view count, author with trust badge
- Replies: threaded (3 levels deep), collapsible threads, "Show X more replies" gap loader
- Sort replies: Oldest / Newest / Best (by votes)
- Smart loading: first 10 + gap + last 10 top-level replies for high-traffic posts
- New replies banner (real-time polling, sticky bottom)
- Q&A: accept answer button for post author, accepted answer highlighted
- Close/pin/move post (moderator actions)

**What admins configure:**
- Posts per page (default: 20)
- Replies per batch (default: 20)
- Max thread depth (default: 3)
- Allow guest reading (on/off)
- Require login to participate (on/off)

**REST API:**
- `GET /spaces/:id/posts` — paginated with sort (latest/popular/unanswered)
- `GET /posts/:id` — detail with enriched author data
- `POST /spaces/:id/posts` — create (sanitized HTML, auto-slug, auto-subscribe)
- `PATCH /posts/:id` — update (creates revision)
- `DELETE /posts/:id` — soft delete (status=trash)
- `POST /posts/:id/close` / `pin` / `move`
- `GET /posts/:id/replies` — paginated with sort, enriched author data
- `POST /posts/:id/replies` — create (with optional parent_id for threading)
- `PATCH /replies/:id` — update (creates revision)
- `POST /replies/:id/accept` — Q&A accepted answer

---

### Voting

**What users see:**
- Upvote/downvote on posts and replies
- Vote score displayed prominently
- Visual pop animation on vote
- Can undo vote (toggle)
- Changing vote direction (up→down) adjusts score correctly

**Business logic:**
- One vote per user per object (UNIQUE constraint)
- Toggle: same vote again = remove
- Score is denormalized on the post/reply row
- Voting awards/deducts reputation to content author

**REST API:**
- `POST /posts/:id/vote` — body: `{ "value": 1 | -1 }`
- `DELETE /posts/:id/vote` — remove vote
- Same for replies

---

### Trust Levels & Reputation

**What users see:**
- Trust level badge (0-5) on every avatar
- Reputation score on profile
- Progressive ability unlocking (Level 0 can't post links, Level 3 can close topics)
- Trust level names: Newcomer (0), Member (1), Regular (2), Trusted (3), Leader (4), Moderator (5)

**How it works:**
- Levels 0-3: auto-earned based on activity thresholds
- Levels 4-5: manually granted by admins
- Reputation points: post upvoted (+10), reply upvoted (+5), accepted answer (+15), downvoted (-2), post removed (-20)
- Trust evaluation runs via WP-Cron every 12 hours
- Level 0 rate limits: 3 posts/day, 10 replies/day, 5 votes/day

**What admins configure:**
- Trust level thresholds (posts, days active, reputation, replies received)
- Rate limits per trust level

---

### Permissions (3-Layer)

**Layer 1 — WordPress Capabilities:**
- Mapped to WP roles (Subscriber → basic, Editor → moderate, Admin → manage)
- 20 capabilities: `jetonomy_read`, `jetonomy_create_posts`, `jetonomy_moderate`, etc.

**Layer 2 — Space Roles:**
- viewer, member, moderator, admin (per-space)
- Space admins can manage their own space without being WP Admin
- Sub-space roles inherit from parent (higher wins)

**Layer 3 — Trust Level Gates + Access Rules:**
- Trust level gates: Level 3+ for wiki-editing, closing topics
- Access rules: membership-based gating (MemberPress, PMPro levels)
- Rate limiting: per trust level, stored in transients

**How it resolves:**
```
Banned? → DENY
WP Admin? → ALLOW
WP Capability? → NO: DENY
Access Rules match? → Apply grants
Space member? → Check role
Trust level gate? → Check level
Rate limit? → Check count
→ ALLOW
```

---

### Search

**What users see:**
- Full-width search input on dedicated search page
- Filter tabs: All / Posts / Spaces / Tags
- Result count ("7 results")
- Post results: title, author, space, time, content excerpt (25 words)
- Space results: title, description, post count
- Tag results: tag pills with post count
- Pagination

**How it works:**
- MySQL FULLTEXT in BOOLEAN MODE on `content_plain` + `title`
- Search adapter pattern — pluggable (Pro: Meilisearch, Elasticsearch)
- Minimum 2 characters

---

### Moderation

**What moderators see:**
- Moderation queue: Pending Posts / Pending Replies / Flags / Banned Users (4 tabs)
- Actions: Approve / Spam / Trash / Resolve Flag / Ban / Unban
- Flag system: users report content with reason (spam/offensive/off-topic/harassment/other)

**What it does:**
- Flag content → goes to mod queue
- Approve → publishes content
- Spam → marks as spam, -20 reputation to author
- Ban → global or per-space, with optional expiry
- Silence → user can read but not write

---

### Notifications

**What users see:**
- Bell icon in community nav with unread badge count
- Notifications page: list of all notifications, mark-all-read on visit
- Each notification: actor avatar, action text, link to content, time

**Types:**
- Reply to your post / subscribed thread
- @mention
- Accepted answer (Q&A)
- Vote batch ("5 people upvoted your post")
- Trust level promotion
- Moderator action on your content

**Dispatch chain:**
- Action → `do_action()` → Notifier catches → creates notification → optionally sends email

---

### SEO

**What search engines see:**
- Schema.org JSON-LD: `DiscussionForumPosting`, `QAPage` (with acceptedAnswer), `BreadcrumbList`
- XML Sitemaps: spaces + posts (registered as WP core sitemap providers)
- Meta description + OG tags + Twitter cards on every page
- Clean URLs: `/community/s/space-slug/t/post-slug/`
- Server-side rendered HTML (Interactivity API SSR)
- Proper canonical URLs

---

### Import

**What admins do:**
- Admin → Jetonomy → Import
- Select source: bbPress / wpForo / Asgaros Forum
- Auto-detect: shows source stats ("Found: 12 forums, 3,847 topics...")
- Dry run option
- Progress during import
- Results: imported / skipped / errors

**Data mapping:**
- Source forums → Jetonomy categories + spaces
- Source topics → Jetonomy posts
- Source replies → Jetonomy replies
- Source users → WP users + Jetonomy profiles

---

### Membership Integration

**What admins configure:**
- Access rules per space: rule type (membership/role/capability/trust_level/logged_in/everyone)
- Rule grants: read / participate / full
- Auto-assign space role on membership activation

**Adapters shipped:**
- WP Roles (free, always active)
- MemberPress (free)
- Paid Memberships Pro (free)
- WooCommerce Memberships (Pro)
- Restrict Content Pro (Pro)
- LearnDash (Pro)

**Membership events:**
- Activated → auto-join gated spaces
- Deactivated → downgrade to viewer (grace period)
- Upgraded → unlock additional spaces

---

## Pro Modules

### Module 1: SEO Pro

**Scope:**
- Per-space customizable meta title template (`{post_title} - {space_name} | {site_name}`)
- Per-space meta description template
- Open Graph image per space (cover_image or custom upload)
- Sitemap controls: exclude spaces, set priority per space
- Noindex/nofollow per space
- Canonical URL customization
- robots.txt additions for forum content

**Admin:** SEO tab on space edit page
**API:** `PATCH /spaces/:id/seo`

---

### Module 2: White Label

**Scope:**
- Custom community name (replaces site name in Jetonomy header)
- Custom logo upload (replaces "J" icon)
- Custom footer text (or remove entirely)
- Custom admin menu label and icon
- Force accent color override
- Custom CSS injection field

**Admin:** "Branding" tab in Settings
**API:** `GET/PATCH /settings/white-label`
**Hooks:** `jetonomy_header_logo`, `jetonomy_admin_menu_label`, `jetonomy_admin_menu_icon`

---

### Module 3: Reactions

**Scope:**
- 8 emoji reactions: 👍 ❤️ 😄 🎉 🤔 👀 🚀 👎
- Toggle: click to add, click again to remove
- Show reaction bar below posts and replies
- Reaction counts per emoji
- Current user's reactions highlighted
- Admin: configure which emojis are available

**Database:** `jt_pro_reactions` (user_id, object_type, object_id, emoji)
**API:** `POST/GET /posts/:id/reactions`, `POST/GET /replies/:id/reactions`
**Frontend:** Pill-style buttons via `jetonomy_post_actions` / `jetonomy_reply_actions` hooks

---

### Module 4: Polls

**Scope:**
- Create poll attached to a post (question + options)
- Single choice or multiple choice
- Optional close date (auto-closes)
- Vote on options (one per user for single, multiple for multi)
- Live results with percentage bars
- Close/reopen poll
- Denormalized vote counts

**Database:** `jt_pro_polls`, `jt_pro_poll_options`, `jt_pro_poll_votes`
**API:** `POST /posts/:id/poll`, `GET /posts/:id/poll`, `POST /polls/:id/vote`, `PATCH /polls/:id`
**Frontend:** Rendered via `jetonomy_after_post_content` hook
**Admin:** Poll management in space admin

---

### Module 5: Email Digest

**Scope:**
- Daily and weekly digest emails
- Per-user frequency: none / daily / weekly
- Digest content: top posts (by votes), new posts in subscribed spaces, replies to user's posts, trending discussion
- Beautiful HTML email template (inline CSS, responsive)
- Plain text fallback
- Send time preference (user picks hour)
- Unsubscribe: one-click token-based
- Admin: enable/disable, default frequency, preview, test send

**Cron:** Daily + weekly scheduled events
**API:** `GET/PATCH /users/me/digest-preferences`

---

### Module 6: Analytics

**Scope:**
- Dashboard: posts/day, replies/day, active users, votes (with period comparison %)
- Time range: 7d / 30d / 90d
- Top spaces (by activity)
- Top contributors (by posts, replies, votes received)
- Engagement rate: (replies + votes) / posts over time
- Content health: unanswered ratio, avg reply time
- Moderation stats: flags, bans, spam caught
- CSV export

**Database:** No new tables — queries existing data
**API:** `GET /analytics/overview`, `/top-spaces`, `/top-contributors`, `/engagement`, `/moderation`, `/export`
**Admin:** Dedicated analytics dashboard page + mini widget on Jetonomy dashboard

---

### Module 7: Custom Badges

**Scope:**
- Badge builder: name, icon (emoji), tier (bronze/silver/gold), category, criteria
- Criteria engine: 8 metrics (post_count, reply_count, reputation, trust_level, vote_received, days_active, accepted_answers, spaces_joined) with operators (>=, >, =, <=, <) and AND/OR logic
- Auto-evaluation via cron (every 6 hours)
- Manual award by admin
- 5 default badges seeded on activation
- Badge display on user profiles
- Notification + reputation bonus on earn

**Database:** `jt_pro_badges`, `jt_pro_user_badges`
**API:** `CRUD /badges`, `GET /users/:id/badges`, `POST /badges/:id/award`
**Admin:** Badge builder with criteria UI, badge list table, manual award modal

---

### Module 8: Advanced Moderation

**Scope:**
- Auto-moderation rules engine
- Rule types: keyword filter, regex pattern, link limit, new user restriction, spam score
- Actions: flag (publish but flag), hold (pending), block (reject), spam (mark spam)
- Per-space or global scope
- Hit counter per rule
- Admin: rules list with stats, add/edit form in moderation page "Auto-Rules" tab

**Database:** `jt_pro_mod_rules`
**API:** `CRUD /moderation/rules`, `GET /moderation/rules/:id/stats`
**Hook:** `jetonomy_check_content` filter — runs before every post/reply creation

---

### Module 9: Custom Fields

**Scope:**
- 9 field types: text, textarea, number, email, url, select, checkbox, radio, date
- 3 contexts: post, profile, space
- Per-space or global scoping
- Required/searchable/filterable flags
- Options builder for select/checkbox/radio (JSON array)
- Validation engine per field type
- Field values stored separately from posts (no schema pollution)
- Admin: field builder with type-aware options, field list table

**Database:** `jt_pro_fields`, `jt_pro_field_values`
**API:** `CRUD /fields`, `GET/PATCH /posts/:id/fields`, `GET/PATCH /users/me/fields`
**Hooks:** `jetonomy_new_post_fields`, `jetonomy_post_meta_fields`, `jetonomy_profile_edit_fields`, `jetonomy_profile_display_fields`

---

### Module 10: Private Messaging

**Scope:**
- Direct (1:1) and group conversations
- Existing conversation reuse (no duplicate DMs)
- Cursor-based message pagination
- Unread tracking per participant (last_read_at)
- Auto-mark-as-read on fetch
- Mute/unmute conversations
- Trust level gating (Level 1+ only — anti-spam)
- Message preview + count denormalized on conversation
- "Messages" link in community nav with unread badge
- Conversation list: participants, last message preview, time, unread indicator
- Chat view: messages in chronological order, send input at bottom

**Database:** `jt_pro_conversations`, `jt_pro_conversation_participants`, `jt_pro_messages`
**API:** `CRUD /conversations`, `GET/POST /conversations/:id/messages`, `GET /conversations/unread-count`, `PATCH /conversations/:id` (mute)
**Routes:** `/community/messages/`, `/community/messages/:id/`
**Hook:** `jetonomy_header_nav_items` (adds Messages link), `jetonomy_template_map` (registers Pro templates)

---

### Pro Adapters

**WooCommerce Memberships:** Maps WC membership plans to space access rules. Hooks into membership status changes.

**Restrict Content Pro:** Maps RCP subscription levels to space access rules. Hooks into level changes.

**LearnDash:** Maps course enrollment and group membership to space access rules. Hooks into enrollment changes.

---

### EDD License System

**Store:** https://wbcomdesigns.com
**Item:** Jetonomy Pro
**Tiers:** Starter ($99/yr, 1 site), Growth ($199/yr, 5 sites), Agency ($399/yr, unlimited), Lifetime ($599)
**Tier gating:** Each tier unlocks specific modules
**Auto-updater:** Checks EDD store for new versions
**Admin:** License page under Jetonomy menu — enter key, activate/deactivate, show status

---

## What's NOT in Scope (v1.0)

- Social Feed module (v2.0)
- Real-time push via WebSockets/Mercure (v2.0)
- Mobile PWA (v2.0)
- Multisite support (v2.0)
- Slack/Discord bridge (v2.0)
- Third-party extension marketplace (v2.0)
- AI-powered features (v2.0 — but hooks ready now)
- Threaded replies beyond 3 levels
- Video/audio in posts
- Direct file uploads (uses WP media library)
