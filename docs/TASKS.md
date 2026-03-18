# Jetonomy v1.0 — Task Status

> All 22 tasks COMPLETE as of 2026-03-19.

---

## CRITICAL (Blocks core functionality)

- [x] **T1: Enrich Posts API response** — Add author_name, author_avatar, author_login, trust_level, reputation, time_ago, profile_url to `Posts_Controller::prepare_post()`. Same pattern as `Replies_Controller::prepare_reply()`. File: `includes/api/class-posts-controller.php`

- [x] **T2: Create JoinRequest model** — File `includes/models/class-join-request.php` doesn't exist but is referenced in Privacy cleanup and the schema has the table. Create model with: create, list_pending_for_space, approve, deny, find_by_user_space. Then update `Spaces_Controller` approval flow to use it instead of wp_options hack.

- [x] **T3: Silence enforcement in Permission Engine** — `Restriction::is_silenced()` exists but `Permission_Engine::can()` never checks it. Add: if silenced, allow `read` but deny all write actions (create_posts, create_replies, vote). File: `includes/permissions/class-permission-engine.php`

- [x] **T4: Moderation REST gaps** — Add missing endpoints: `POST /moderation/trash/:type/:id` (set status=trash), `POST /moderation/flags/:id/resolve` (valid/dismissed), `POST /moderation/ban` (create restriction), `DELETE /moderation/ban/:id` (remove restriction). File: `includes/api/class-moderation-controller.php`

- [x] **T5: Notification API enrichment** — Add `message`, `actor_name`, `actor_avatar`, `actor_login`, `object_url`, `time_ago` to `Notifications_Controller` prepare method. File: `includes/api/class-notifications-controller.php`

---

## HIGH (Expected by users, missing)

- [x] **T6: @mention parsing + notification** — Scan post/reply content for `@username` patterns on create. For each found: create notification of type `mention`. Wrap matched @username in `<a>` link to profile. Files: `includes/api/class-posts-controller.php`, `includes/api/class-replies-controller.php`, `includes/notifications/class-notifier.php`

- [x] **T7: Vote batch notifications** — Instead of creating individual notifications per vote, batch them. On vote, check if a "vote" notification exists for the same object within the last hour. If yes, update its message ("5 people upvoted your post"). If no, create new. File: `includes/notifications/class-notifier.php`

- [x] **T8: Search "All" combined mode + tag search** — When filter=all, query posts + spaces + tags simultaneously and return grouped results. Add `tag` as a search type. File: `includes/api/class-search-controller.php`, `templates/views/search.php`

- [x] **T9: Canonical URL in free SEO** — Add `<link rel="canonical" href="...">` output in `Template_Loader::set_seo_meta()` for all routes. File: `includes/class-template-loader.php`

- [x] **T10: Admin-configurable trust thresholds** — Move trust level requirements from hardcoded array in `Trust_Levels` to `jetonomy_settings` option. Add "Permissions" tab fields for: Level 1/2/3 thresholds (posts, days_active, reputation, replies_received) and Level 0 rate limits. Files: `includes/trust/class-trust-levels.php`, `includes/admin/views/settings.php`

---

## MEDIUM (Quality improvements)

- [x] **T11: Enrich Spaces API members response** — `GET /spaces/:id/members` should return display_name, avatar_url, trust_level, reputation per member. File: `includes/api/class-spaces-controller.php`

- [x] **T12: Enrich Categories API with spaces** — `GET /categories` list endpoint should include spaces nested under each category (currently only single-category GET does). File: `includes/api/class-categories-controller.php`

- [x] **T13: Posts per page from settings** — Read `posts_per_page` and `replies_per_page` from `jetonomy_settings` option instead of hardcoded 20. Apply in Post::list_by_space, Reply::list_by_post, and single-post.php. Files: `includes/models/class-post.php`, `includes/models/class-reply.php`, `templates/views/single-post.php`, `templates/views/space.php`

- [x] **T14: Email unsubscribe header (RFC 8058)** — Add `List-Unsubscribe` and `List-Unsubscribe-Post` headers to all notification emails. Generate a token-based one-click unsubscribe URL. File: `includes/adapters/class-wp-mail-adapter.php`, `includes/notifications/class-notifier.php`

- [x] **T15: Moderator action notification** — When a moderator approves/spam/trashes a user's content, notify the content author. Hook into the moderation AJAX handlers. File: `includes/admin/class-admin.php`, `includes/notifications/class-notifier.php`

- [x] **T16: Import dry-run + progress** — Add `--dry-run` flag to CLI import. Add AJAX progress reporting to admin import (send total/imported/skipped counts periodically). Files: `includes/import/class-importer.php`, `includes/class-cli.php`, `includes/admin/views/import.php`

- [x] **T17: SEO Pro query var fix** — `get_current_context()` reads `jetonomy_space` and `jetonomy_post` but router uses `jetonomy_slug` and `jetonomy_space_slug`. Fix to match. File: `jetonomy-pro/includes/extensions/seo-pro/class-extension.php`

---

## LOW (Polish)

- [x] **T18: MemberPress/PMPro auto-join on activation** — When membership activates, query access_rules for matching membership level and auto-call `SpaceMember::add()`. Currently the sync_user_spaces method exists but may not fire correctly on all adapter hooks. Files: `includes/adapters/class-member-press-adapter.php`, `includes/adapters/class-pmpro-adapter.php`

- [x] **T19: Pro adapter register_hooks verification** — Verify WooCommerce, RCP, LearnDash adapters all implement `register_hooks()` for status change events. Files: `jetonomy-pro/includes/adapters/class-woocommerce-adapter.php`, `class-rcp-adapter.php`, `class-learndash-adapter.php`

- [x] **T20: Cursor-based pagination** — Replace offset-based pagination with cursor-based (`?after=ID&limit=20`) on all list endpoints. This is a bigger refactor — lower priority but required for large-scale performance. Files: all API controllers.

- [x] **T21: Full accessibility audit** — Verify aria-labels on all interactive elements, focus-visible ring in CSS, heading hierarchy (one h1 per template), all `<img>` have alt/width/height. Run automated WCAG checker.

- [x] **T22: CSS interaction audit** — Complete. All premium interactions verified in the UX pass (2026-03-19). Cross-referenced with interaction table in Dev Guide 2.5.

---

## Summary

| Priority | Count | Estimated Work |
|---|---|---|
| Critical | 5 tasks | ~4 hours |
| High | 5 tasks | ~5 hours |
| Medium | 7 tasks | ~6 hours |
| Low | 5 tasks | ~5 hours |
| **Total** | **22 tasks** | **~20 hours** |

---

## Scalability Tasks (also shipped in v1.0.0) ✅

- [x] **S1: Cache class** — `includes/class-cache.php` with get/set/delete/remember/flush. Applied to Space::find, UserProfile::find_by_user, Permission_Engine::can
- [x] **S2: Eager loading in API** — `batch_load_users()`, `batch_load_profiles()`, `enrich_with_author()` in Base_Controller. Posts + Replies list endpoints use batch loading (2 queries instead of N+1)
- [x] **S3: Cursor-based pagination** — `?after=ID&limit=20` supported on all list endpoints. `cursor_next` in response meta. Offset still works as fallback.
- [x] **S4: Queue class (Pro)** — `Jetonomy_Pro\Queue` with Action Scheduler detection + WP-Cron fallback. async/recurring/batch/cancel methods.
- [x] **S5: Batch Email Digest** — 50 users per batch via Queue::batch(). No more full-user-table iteration.
- [x] **S6: Batch Badge Evaluator** — 100 users per batch via Queue::batch(). Badges loaded once per batch, not per user.
- [x] **S7: Cached messaging unread counts** — 30s TTL cache, invalidated on new message. X-Cache header for debugging.

**All scalability items shipped in v1.0.0. Zero deferrals.**

---

## Competitive Gap Features (v1.0.0)

### Free (10 features)

- [ ] **G1: Drag-drop image upload + paste-to-upload** — Hook into WP media AJAX uploader, paste event listener in editor, insert image URL into contenteditable. Files: `assets/js/view.js`, `templates/partials/composer.php`, `templates/views/new-post.php`

- [ ] **G2: Auto-embed URLs (oEmbed)** — Process URLs in post content via `wp_oembed_get()` before display. YouTube, Twitter, Vimeo auto-preview. Files: `includes/api/class-posts-controller.php`, `includes/api/class-replies-controller.php`

- [ ] **G3: Instant search-as-you-type** — Debounced AJAX search hitting REST API, dropdown overlay on header search + search page input, highlight matched terms. Files: `assets/js/view.js`, `assets/css/jetonomy.css`, `templates/partials/header.php`

- [ ] **G4: RTL stylesheet** — Generate `assets/css/jetonomy-rtl.css` with logical properties. Load when `is_rtl()`. Replace `margin-left` with `margin-inline-start` throughout. Files: `assets/css/jetonomy-rtl.css`, `includes/class-template-loader.php`

- [ ] **G5: Quote selected text** — Detect text selection on reply content, show floating "Quote" button, insert blockquote in composer with attribution. Files: `assets/js/view.js`, `assets/css/jetonomy.css`

- [ ] **G6: User hover cards** — Lightweight popover on avatar/name hover, AJAX-loaded user summary (bio, stats, badges, recent posts), debounced show/hide. Files: `assets/js/view.js`, `assets/css/jetonomy.css`, `includes/api/class-users-controller.php`

- [ ] **G7: IP tracking + Akismet integration** — Log IP on post/reply create, IP ban in restrictions table, Akismet spam check adapter. Files: `includes/api/class-posts-controller.php`, `includes/api/class-replies-controller.php`, `includes/models/class-restriction.php`, `includes/moderation/class-akismet.php`

- [ ] **G8: Keyboard shortcuts** — j/k navigate topics, l upvote, r reply, n new post, / focus search. Global listener + help modal (?). Files: `assets/js/view.js`, `assets/css/jetonomy.css`

- [ ] **G9: Emoji picker in editor** — Lightweight emoji picker button in composer toolbar, insert emoji into contenteditable. Files: `assets/js/view.js`, `templates/partials/composer.php`, `templates/views/new-post.php`

- [ ] **G10: Invite links with expiry** — Generate shareable invite URL per space, configurable expiry, auto-join on visit. New table: `jt_invite_links`. Files: `includes/models/class-invite-link.php`, `includes/api/class-spaces-controller.php`, admin UI

### Pro (3 features)

- [ ] **G11: Webhooks** — Webhook registration table, `do_action` hooks on all CRUD events, HTTP dispatch with retry, admin UI for managing endpoints. Files: `jetonomy-pro/includes/extensions/webhooks/class-extension.php`

- [ ] **G12: Reply-by-email** — Inbound email processing (IMAP polling or SendGrid/Mailgun inbound webhook), token-based reply address per notification, content parsing, security. Files: `jetonomy-pro/includes/extensions/reply-by-email/class-extension.php`

- [ ] **G13: Web push notifications** — Service worker, VAPID keys, push subscription storage, notification dispatch via Web Push protocol. Files: `jetonomy-pro/includes/extensions/web-push/class-extension.php`

---

| Tier | Count | Estimated |
|---|---|---|
| Free | 10 features | ~18 days |
| Pro | 3 features | ~12 days |
| **Total** | **13 features** | **~30 days** |
