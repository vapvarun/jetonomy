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

- [ ] License page renders at Jetonomy → License
- [ ] Enter valid Starter key → activates, shows tier and site count
- [ ] Enter valid Growth key → activates, shows tier and site count
- [ ] Enter valid Agency key → activates, shows tier and site count
- [ ] Enter valid Lifetime key → activates, shows "Lifetime" status
- [ ] Invalid key → clear error message
- [ ] Deactivate license → status reverts, Pro features disabled
- [ ] Auto-updater checks EDD store for new versions
- [ ] Update notification appears when new version available
- [ ] Update installs successfully
- [ ] Expired license → update blocked with renewal notice
- [ ] Tier gating: Starter key cannot access Agency-only modules

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

- [ ] Extensions manager page lists all 10 modules
- [ ] Enable/disable individual modules
- [ ] Disabled module: features hidden, database tables remain
- [ ] Re-enable module: features restored without data loss
- [ ] Each extension extends `Jetonomy_Extension` base class
- [ ] `meta()`, `boot()`, `activate()`, `deactivate()` methods called correctly
- [ ] Extension hooks fire and can be filtered by third-party code

---

## 14. Pro UI & Admin

- [ ] Pro admin pages use branded card/button/table styles
- [ ] Pro badge/indicator visible on Pro-only features
- [ ] Settings pages for Pro modules render and save correctly
- [ ] No Pro features accessible without valid license
- [ ] Expired license: Pro features show upgrade/renewal notice
- [ ] Pro templates registered via `jetonomy_template_map` hook

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
