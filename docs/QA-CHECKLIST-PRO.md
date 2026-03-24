# Jetonomy Pro — Manual QA Checklist

> Systematic testing checklist for all Pro modules. Requires Jetonomy Free activated + valid Pro license.

---

## Prerequisites

- [ ] Jetonomy Free installed and activated
- [ ] Jetonomy Pro installed as separate plugin
- [ ] Valid license key entered in Jetonomy → License
- [ ] License activated successfully (shows "Active" status)
- [ ] Pro extensions menu visible under Jetonomy → Extensions
- [ ] All free core QA tests passing (see QA-CHECKLIST-FREE.md)

---

## 1. License & Auto-Updater

> License is embedded in Settings → License tab (not a standalone admin page).

- [ ] Jetonomy → Settings → License tab renders correctly (card layout, no raw admin page look)
- [ ] Enter valid Starter key → activates, shows "License Active" badge with tier and expiry
- [ ] Enter valid Lifetime key → activates, shows "Lifetime" in expiry field
- [ ] Invalid key → clear error message shown in settings notice
- [ ] Deactivate license → status reverts to "No Active License", Pro features disabled
- [ ] Expired license → shows "License Expired" card with renewal link
- [ ] Auto-updater checks EDD store for new versions
- [ ] Update notification appears when new version available
- [ ] Update installs successfully
- [ ] Tier gating: lower-tier key cannot activate higher-tier-only modules

---

## 2. Module 1: SEO Pro

- [ ] SEO tab appears on space edit page
- [ ] Set custom meta title template (verify on frontend `<title>`)
- [ ] Set custom meta description template (verify in `<meta>`)
- [ ] Upload Open Graph image per space (verify `og:image` meta)
- [ ] Sitemap controls: exclude a space → verify removed from sitemap XML
- [ ] Set priority per space in sitemap
- [ ] Set noindex on a space → verify `<meta name="robots" content="noindex">`
- [ ] Set nofollow on a space → verify in robots meta
- [ ] Custom canonical URL → verify `<link rel="canonical">`
- [ ] Template variables resolve: `{post_title}`, `{space_name}`, `{site_name}`
- [ ] `PATCH /jetonomy/v1/spaces/:id/seo` API works

---

## 3. Module 2: White Label

- [ ] Branding tab appears in Settings
- [ ] Set custom community name → replaces site name in Jetonomy header
- [ ] Upload custom logo → replaces "J" icon
- [ ] Set custom footer text → renders in community footer
- [ ] Remove footer entirely option works
- [ ] Custom admin menu label → WP Admin sidebar shows new label
- [ ] Custom admin menu icon → icon updates
- [ ] Force accent color override → all accent elements use new color
- [ ] Custom CSS injection field → CSS applied on frontend
- [ ] `GET /jetonomy/v1/settings/white-label` returns current config
- [ ] `PATCH /jetonomy/v1/settings/white-label` saves changes
- [ ] Hooks fire: `jetonomy_header_logo`, `jetonomy_admin_menu_label`, `jetonomy_admin_menu_icon`

---

## 4. Module 3: Reactions

- [ ] Reaction bar appears below posts
- [ ] Reaction bar appears below replies
- [ ] All 8 emojis available: thumbs up, heart, smile, party, thinking, eyes, rocket, thumbs down
- [ ] Click emoji → adds reaction, count increments
- [ ] Click same emoji again → removes reaction, count decrements
- [ ] Current user's reactions highlighted
- [ ] Multiple users can react with same emoji (count aggregates)
- [ ] Admin can configure which emojis are available
- [ ] Disabled emoji not shown in reaction bar
- [ ] `jt_pro_reactions` table stores reactions correctly
- [ ] `POST /jetonomy/v1/posts/:id/reactions` creates reaction
- [ ] `GET /jetonomy/v1/posts/:id/reactions` returns counts + user's reactions
- [ ] Same endpoints work for replies
- [ ] Reactions render via `jetonomy_post_actions` / `jetonomy_reply_actions` hooks

---

## 5. Module 4: Polls

- [ ] Create poll attached to a post (question + 2+ options)
- [ ] Single choice poll: user can select one option only
- [ ] Multiple choice poll: user can select multiple options
- [ ] Vote on poll option → count updates, percentage bar shows
- [ ] Cannot vote twice on single choice poll
- [ ] Can change vote on single choice poll
- [ ] Set close date → poll auto-closes at that date
- [ ] Closed poll shows results only (no voting)
- [ ] Reopen closed poll works
- [ ] Denormalized vote counts match actual votes
- [ ] Poll renders via `jetonomy_after_post_content` hook
- [ ] `POST /jetonomy/v1/posts/:id/poll` creates poll
- [ ] `GET /jetonomy/v1/posts/:id/poll` returns poll data
- [ ] `POST /jetonomy/v1/polls/:id/vote` casts vote
- [ ] `PATCH /jetonomy/v1/polls/:id` updates (close/reopen)
- [ ] Tables: `jt_pro_polls`, `jt_pro_poll_options`, `jt_pro_poll_votes` created

---

## 6. Module 5: Email Digest

- [ ] User can set digest frequency: none / daily / weekly
- [ ] User can set preferred send time
- [ ] Daily digest cron fires and sends email
- [ ] Weekly digest cron fires and sends email
- [ ] Digest content includes: top posts (by votes), new posts in subscribed spaces
- [ ] Digest content includes: replies to user's posts, trending discussions
- [ ] HTML email template renders correctly (responsive, inline CSS)
- [ ] Plain text fallback email readable
- [ ] One-click unsubscribe (token-based) works
- [ ] Unsubscribed user stops receiving digests
- [ ] Admin can: enable/disable digest, set default frequency
- [ ] Admin can: preview digest email, send test email
- [ ] `GET /jetonomy/v1/users/me/digest-preferences` returns prefs
- [ ] `PATCH /jetonomy/v1/users/me/digest-preferences` saves prefs

---

## 7. Module 6: Analytics

- [ ] Analytics dashboard page loads under Jetonomy admin
- [ ] Mini widget on main Jetonomy dashboard
- [ ] Metrics display: posts/day, replies/day, active users, votes
- [ ] Period comparison percentages shown (vs previous period)
- [ ] Time range selector: 7d / 30d / 90d
- [ ] Top spaces chart (by activity)
- [ ] Top contributors list (by posts, replies, votes received)
- [ ] Engagement rate graph: (replies + votes) / posts over time
- [ ] Content health: unanswered ratio, avg reply time
- [ ] Moderation stats: flags, bans, spam caught
- [ ] CSV export downloads correctly formatted file
- [ ] API endpoints return data:
  - [ ] `GET /jetonomy/v1/analytics/overview`
  - [ ] `GET /jetonomy/v1/analytics/top-spaces`
  - [ ] `GET /jetonomy/v1/analytics/top-contributors`
  - [ ] `GET /jetonomy/v1/analytics/engagement`
  - [ ] `GET /jetonomy/v1/analytics/moderation`
  - [ ] `GET /jetonomy/v1/analytics/export`

---

## 8. Module 7: Custom Badges

- [ ] Badge builder page in admin
- [ ] Create badge: name, icon (emoji), tier (bronze/silver/gold), category
- [ ] Set criteria: metric + operator + value (e.g., post_count >= 10)
- [ ] AND/OR logic for multiple criteria
- [ ] 8 metrics available: post_count, reply_count, reputation, trust_level, vote_received, days_active, accepted_answers, spaces_joined
- [ ] 5 default badges seeded on module activation
- [ ] Auto-evaluation cron runs every 6 hours
- [ ] User meeting criteria auto-receives badge
- [ ] Manual award by admin works
- [ ] Badge display on user profiles
- [ ] Notification sent when badge earned
- [ ] Reputation bonus on badge earn
- [ ] Badge list table in admin with edit/delete
- [ ] Tables: `jt_pro_badges`, `jt_pro_user_badges` created
- [ ] `CRUD /jetonomy/v1/badges` works
- [ ] `GET /jetonomy/v1/users/:id/badges` returns user badges
- [ ] `POST /jetonomy/v1/badges/:id/award` manual award works

---

## 9. Module 8: Advanced Moderation

- [ ] Auto-Rules tab appears in moderation page
- [ ] Create rule: keyword filter → content containing keyword flagged/held/blocked
- [ ] Create rule: regex pattern → pattern match triggers action
- [ ] Create rule: link limit → post with N+ links triggers action
- [ ] Create rule: new user restriction → Level 0 users auto-held
- [ ] Create rule: spam score → high spam score triggers action
- [ ] Actions work: flag (publish but flag), hold (pending), block (reject), spam
- [ ] Per-space rule scoping works
- [ ] Global rule applies to all spaces
- [ ] Hit counter increments when rule triggers
- [ ] Rules list shows stats (hit count, last triggered)
- [ ] Edit/delete rules works
- [ ] `jetonomy_check_content` filter fires before post/reply creation
- [ ] Table: `jt_pro_mod_rules` created
- [ ] `CRUD /jetonomy/v1/moderation/rules` works
- [ ] `GET /jetonomy/v1/moderation/rules/:id/stats` returns hit stats

---

## 10. Module 9: Custom Fields

- [ ] Field builder in admin
- [ ] Create field for each type: text, textarea, number, email, url, select, checkbox, radio, date
- [ ] Set context: post / profile / space
- [ ] Per-space scoping: field appears only in assigned spaces
- [ ] Global scoping: field appears in all spaces
- [ ] Required flag: form cannot submit without required field
- [ ] Searchable flag: field values included in search
- [ ] Filterable flag: filter option appears in post listing
- [ ] Options builder for select/checkbox/radio (add/remove/reorder)
- [ ] Validation engine: email validates format, number validates range, URL validates format
- [ ] Post context: fields appear in new post form, values display on post
- [ ] Profile context: fields appear in profile edit, values display on profile
- [ ] Space context: fields appear in space settings
- [ ] Field values stored in `jt_pro_field_values` (not on post table)
- [ ] Hooks fire: `jetonomy_new_post_fields`, `jetonomy_post_meta_fields`, `jetonomy_profile_edit_fields`, `jetonomy_profile_display_fields`
- [ ] Tables: `jt_pro_fields`, `jt_pro_field_values` created
- [ ] `CRUD /jetonomy/v1/fields` works
- [ ] `GET/PATCH /jetonomy/v1/posts/:id/fields` works
- [ ] `GET/PATCH /jetonomy/v1/users/me/fields` works

---

## 11. Module 10: Private Messaging

- [ ] "Messages" link appears in community nav
- [ ] Unread badge count on messages link
- [ ] Messages page at `/community/messages/`
- [ ] Start new conversation (1:1 DM)
- [ ] Start group conversation (3+ participants)
- [ ] Existing conversation reuse (no duplicate DMs with same person)
- [ ] Send message in conversation
- [ ] Messages display in chronological order
- [ ] Conversation list: participants, last message preview, time, unread indicator
- [ ] Unread tracking per participant (`last_read_at`)
- [ ] Auto-mark-as-read when viewing conversation
- [ ] Mute conversation → no notifications from it
- [ ] Unmute conversation works
- [ ] Trust level gating: Level 0 users cannot send messages
- [ ] Level 1+ users can send messages
- [ ] Message count denormalized on conversation
- [ ] Chat view at `/community/messages/:id/`
- [ ] Cursor-based message pagination (scroll up to load older)
- [ ] Tables: `jt_pro_conversations`, `jt_pro_conversation_participants`, `jt_pro_messages` created
- [ ] `CRUD /jetonomy/v1/conversations` works
- [ ] `GET /jetonomy/v1/conversations/:id/messages` returns messages
- [ ] `POST /jetonomy/v1/conversations/:id/messages` sends message
- [ ] `GET /jetonomy/v1/conversations/unread-count` returns count
- [ ] `PATCH /jetonomy/v1/conversations/:id` mute/unmute works

---

## 12. Pro Adapters

### WooCommerce Memberships
- [ ] Adapter activates when WooCommerce Memberships plugin is active
- [ ] Map WC membership plan to space access rule
- [ ] Membership activated → user auto-joins gated spaces
- [ ] Membership deactivated → user downgraded to viewer (grace period)
- [ ] Membership upgraded → additional spaces unlocked
- [ ] Membership cancelled → access revoked after grace period

### Restrict Content Pro
- [ ] Adapter activates when RCP plugin is active
- [ ] Map RCP subscription level to space access rule
- [ ] Subscription activated → auto-join gated spaces
- [ ] Subscription expired → access downgraded
- [ ] Level change → space access updated

### LearnDash
- [ ] Adapter activates when LearnDash plugin is active
- [ ] Map course enrollment to space access rule
- [ ] Map group membership to space access rule
- [ ] Course enrolled → auto-join gated spaces
- [ ] Enrollment removed → access revoked

---

## 13. Pro Extension System

- [ ] Extensions page (Jetonomy → Extensions) loads: 3-column card grid with category filter tabs
- [ ] Category filter tabs: All / Engagement / Communication / Content / Administration
- [ ] "All" tab shows all 13 extensions
- [ ] Each category tab filters to the correct extensions
- [ ] Enable/disable individual extension: toggle saves, page reloads with new state
- [ ] Disabled module: features hidden on frontend, database tables preserved
- [ ] Re-enable module: features restored without data loss
- [ ] Enabled extension: card shows enabled badge (green dot)
- [ ] Disabled extension: card shows disabled state

---

## 14. Pro UI & Admin

### Settings Page Navigation
- [ ] Jetonomy → Settings sidebar: shows 5 core tabs (General, Permissions, Email, Appearance, SEO)
- [ ] Sidebar Pro section (below divider): License tab visible when Pro active
- [ ] Sidebar Advanced section (below divider): Pro extension tabs appear when extensions enabled
- [ ] Active tab link highlighted in sidebar
- [ ] Switching tabs: page loads correct content without full reload

### Settings Page Card Layout
- [ ] All settings tabs use jt-settings-card layout (no raw h2/form-table/hr layout)
- [ ] Settings → License: card with title "Jetonomy Pro License", license status card inside
- [ ] Settings → Branding (White Label) — navigate with `?tab=branding` (not `white-label`): single card with all form fields
- [ ] Settings → Email Digest: 4 separate cards (Settings, Subscription Statistics, Actions, Cron Status)
- [ ] Settings → Reactions: card wrapping emoji toggle form
- [ ] Settings → Web Push: card wrapping VAPID config form
- [ ] Settings → Reply by Email: card wrapping provider config form
- [ ] Settings → Webhooks: two cards (Registered Webhooks list + Add/Edit Webhook form)
- [ ] Settings → Integrations: card showing adapter status table

### General Admin
- [ ] No Pro features accessible without valid license
- [ ] Expired license: Pro features show upgrade/renewal notice
- [ ] No PHP errors or warnings in debug.log from any Pro module
- [ ] No JavaScript console errors from Pro admin pages

---

## 15. Cross-Module Integration

- [ ] Reactions + Polls on same post: both render correctly
- [ ] Custom fields on post + reactions: layout not broken
- [ ] Private message notification appears in bell notification list
- [ ] Analytics captures Pro feature usage (reactions, polls, messages)
- [ ] Advanced mod rules + reactions: flagging a reacted-to post works
- [ ] Custom badges criteria works with Pro features (e.g., messages_sent)
- [ ] Email digest includes Pro content (poll results, badge earned)
- [ ] Search includes custom field values (if searchable flag set)

---

## 16. Edge Cases & Error Handling

- [ ] Pro plugin without Free plugin → graceful error, prompt to install Free
- [ ] Free plugin deactivated while Pro active → Pro deactivates gracefully
- [ ] License at site limit → clear error on new site activation
- [ ] Pro features degrade gracefully when module disabled mid-use
- [ ] No PHP errors/warnings in debug.log from Pro modules
- [ ] No JavaScript console errors from Pro frontend components
- [ ] Pro tables created on activation, preserved on deactivation
- [ ] Pro tables removed on uninstall (if "delete data" option checked)

---

## 17. Module 11: Webhooks

> Enable Webhooks at **Jetonomy → Extensions**, then configure at **Jetonomy → Settings → Webhooks tab**.

### Webhook Registration
- [ ] Webhooks settings tab renders using `jt-settings-card` layout (two cards: list + form)
- [ ] Add new webhook: fill Payload URL + select events + save → appears in list
- [ ] Edit existing webhook: update URL or events → changes persist
- [ ] Delete webhook → removed from list
- [ ] Inactive (paused) webhook: toggle deactivates without deleting
- [ ] Reactivate paused webhook → deliveries resume

### Event Coverage (13 events)
- [ ] `post.created` fires when a post is published
- [ ] `post.updated` fires when a post is edited
- [ ] `post.deleted` fires when a post is deleted
- [ ] `reply.created` fires when a reply is published
- [ ] `reply.updated` fires when a reply is edited
- [ ] `reply.deleted` fires when a reply is deleted
- [ ] `user.registered` fires when a new user account is created
- [ ] `user.trust_level_changed` fires when trust level changes
- [ ] `vote.cast` fires when a vote is cast
- [ ] `flag.created` fires when a post/reply is flagged
- [ ] `flag.resolved` fires when a flag is resolved
- [ ] `space.member_joined` fires when a user joins a space
- [ ] `space.member_left` fires when a user leaves a space

### Payload & Security
- [ ] Request body is valid JSON with expected fields (event, data, timestamp)
- [ ] `X-Jetonomy-Signature` header present on every delivery (`sha256=HMAC`)
- [ ] Signature verifies correctly against request body using the webhook secret
- [ ] Each webhook has its own auto-generated unique secret (not shared)

### Reliability
- [ ] Failed delivery (endpoint returns 5xx or times out): retry logged
- [ ] After 5 consecutive failures: webhook auto-disables, admin shown notice
- [ ] Re-enabling a failed webhook resets fail counter
- [ ] `POST /jetonomy/v1/webhooks/:id/test` sends test payload immediately
- [ ] Test payload received at registered endpoint with valid signature
- [ ] Table `jt_pro_webhooks` created on extension activation

### REST API
- [ ] `GET /jetonomy/v1/webhooks` lists all webhooks
- [ ] `POST /jetonomy/v1/webhooks` creates a webhook
- [ ] `PATCH /jetonomy/v1/webhooks/:id` updates a webhook
- [ ] `DELETE /jetonomy/v1/webhooks/:id` deletes a webhook
- [ ] `POST /jetonomy/v1/webhooks/:id/test` sends test payload

---

## 18. Module 12: Reply by Email

> Enable at **Jetonomy → Extensions**, then configure at **Jetonomy → Settings → Reply by Email tab**.
> Full functional test requires real IMAP/mail infrastructure. **Config-save + parsing-logic tests are in scope for v1.0 QA; live email delivery test is out of scope.**

### Settings & Config
- [ ] Reply by Email settings tab renders using `jt-settings-card` layout
- [ ] Email Domain field saves correctly
- [ ] IMAP method: IMAP Host, Port, Username, Password fields appear and save
- [ ] Webhook method: inbound URL displayed (`/wp-json/jetonomy/v1/reply-by-email/inbound`)
- [ ] Switching between IMAP and Webhook method works and saves
- [ ] Saved settings persist after page reload

### Token Mechanism
- [ ] Outgoing notification emails contain a `Reply-To` address in format `reply+TOKEN@domain.com`
- [ ] Token is unique per (user, post) pair — two notifications for different posts get different tokens
- [ ] Expired tokens (>7 days) are rejected with error — no reply created
- [ ] Invalid/tampered tokens are rejected — no reply created

### Email Parsing Logic
- [ ] Quoted reply lines (starting with `>`) are stripped from reply content
- [ ] Email signature lines (after `-- ` separator) are stripped
- [ ] Reply content after stripping is the actual message text only
- [ ] HTML email bodies are converted to plain text before creating reply

### Rate Limiting & Abuse
- [ ] User sending more than 10 email replies per hour: 11th reply rejected
- [ ] Rate limit resets after the hour window
- [ ] Failed/rejected tokens do not increment reply counter

### Inbound Webhook Method (config-level test)
- [ ] `POST /jetonomy/v1/reply-by-email/inbound` endpoint exists (returns 400 without valid payload — not 404)
- [ ] Cron job `jetonomy_pro_reply_by_email_poll` registered when IMAP method selected

---

## 19. Module 13: Web Push Notifications

> Enable at **Jetonomy → Extensions**, then configure at **Jetonomy → Settings → Web Push tab**.
> **Full delivery test requires HTTPS.** On localhost (HTTP), test config-save, frontend script load, and opt-in button appearance only.

### Settings & VAPID
- [ ] Web Push settings tab renders using `jt-settings-card` layout
- [ ] VAPID Public Key auto-generated on activation (read-only field shows key)
- [ ] Default Notification Title field saves correctly
- [ ] Notification Icon URL field saves correctly
- [ ] Settings persist after page reload

### Service Worker & Frontend Script
- [ ] Service worker endpoint exists: `GET /wp-json/jetonomy/v1/push/service-worker.js` returns JavaScript (not 404)
- [ ] Response has header `Service-Worker-Allowed: /`
- [ ] Web Push extension frontend script loads on community pages
- [ ] No JavaScript errors in browser console on community pages with extension enabled

### Opt-In Flow (HTTPS only)
- [ ] "Enable notifications" button appears in community header for logged-in users
- [ ] Button not shown for users who have already opted in
- [ ] Clicking button triggers browser permission prompt
- [ ] On grant: subscription stored in `jt_pro_push_subscriptions` table
- [ ] "Enable notifications" button disappears after successful opt-in

### Notification Delivery
- [ ] Web Push notification sent when a reply is posted to the user's thread
- [ ] Web Push notification sent when user is mentioned in a post or reply
- [ ] Web Push notification sent when a badge is awarded
- [ ] Notification payload includes title, body, and click-through URL
- [ ] Clicking notification routes to the correct post/notification page

### Subscription Lifecycle
- [ ] Expired subscription (server returns 410): removed from `jt_pro_push_subscriptions` automatically
- [ ] User with multiple devices: each device gets its own subscription row
- [ ] Revoking browser permission stops deliveries to that device (browser-side)
- [ ] Table `jt_pro_push_subscriptions` created on extension activation

---

## 20. Supplementary: Per-Extension Edge Cases

### Reactions — Per-Space Config
- [ ] Admin can configure which emoji reactions are enabled globally (Settings → Reactions tab)
- [ ] Disabled emoji removed from reaction bar on next page load
- [ ] Re-enabling emoji: reaction bar shows it again without data loss

### Email Digest — Cron Verification
- [ ] Daily cron event `jetonomy_pro_send_daily_digest` registered in WP Cron schedule
- [ ] Weekly cron event `jetonomy_pro_send_weekly_digest` registered in WP Cron schedule
- [ ] Sending test email from admin (Actions card) delivers to admin email address
- [ ] Digest email respects user's subscribed spaces (only includes posts from subscribed spaces)
- [ ] User with frequency "none" does not receive digest even if cron fires
- [ ] Unsubscribe token in email footer is unique per user (not shared)
- [ ] Clicking unsubscribe link sets user preference to "none" (no more digests)

### Analytics — Custom Date Range
- [ ] Custom date range (`range=custom&start=YYYY-MM-DD&end=YYYY-MM-DD`) works on all 6 API endpoints
- [ ] CSV export for custom range includes only data from that date range
- [ ] Period comparison % calculates against equivalent prior period (same duration)
- [ ] Empty periods (no data) return zeros, not errors

### Private Messaging — Additional Coverage
- [ ] System messages (e.g., "X joined the group") render differently from user messages
- [ ] Group conversation: admin/creator can add new participants
- [ ] Group conversation name is editable by participants
- [ ] Conversation participant count shown on conversation list

### White Label — Frontend Verification
- [ ] Custom community name appears in `<title>` on community pages
- [ ] Custom logo appears in community header (not just admin)
- [ ] Custom accent color applied to CTA buttons and links on community frontend
- [ ] Custom CSS injection visible on community frontend (not only admin)
- [ ] Admin menu label change: WordPress sidebar shows new plugin label

---

## 21. Future Test Infrastructure (Pro — Planned)

> The tests in §§ 1–20 are the authoritative manual QA gate for v1.0.0.
> Automated test layers will be added post-launch as time permits.

| Layer | Scope | Status | Notes |
|-------|-------|--------|-------|
| WP-CLI batch test commands | Activate/deactivate all extensions, check tables, fire crons | Planned | CLI commands defined in plugin — write bash harness to call them sequentially |
| PHPUnit integration tests | License gating, token validation, webhook signature, rate limiting | Planned | These are the highest-value unit-testable components |
| Playwright E2E (Pro flows) | Full DM flow, badge award, poll vote, digest send-test | Planned | Extend free plugin E2E suite with Pro test file |
| Load test (messaging) | 100 concurrent message sends to single conversation | Post-launch | Validate cursor-based pagination under real load |

**Priority for first automated test pass:**
1. Token validation (reply-by-email): pure functions, no browser needed
2. Webhook HMAC signature: pure crypto, easy to unit test
3. License tier gating: mock license stub → verify feature enable/disable
4. Email digest cron logic: mock `wp_mail()` → verify recipient list and meta key reads

