# Jetonomy v1.0 — Remaining Tasks

> Sorted by priority. Each task has a clear scope. No task exceeds 1 hour of work.

---

## CRITICAL (Blocks core functionality)

- [ ] **T1: Enrich Posts API response** — Add author_name, author_avatar, author_login, trust_level, reputation, time_ago, profile_url to `Posts_Controller::prepare_post()`. Same pattern as `Replies_Controller::prepare_reply()`. File: `includes/api/class-posts-controller.php`

- [ ] **T2: Create JoinRequest model** — File `includes/models/class-join-request.php` doesn't exist but is referenced in Privacy cleanup and the schema has the table. Create model with: create, list_pending_for_space, approve, deny, find_by_user_space. Then update `Spaces_Controller` approval flow to use it instead of wp_options hack.

- [ ] **T3: Silence enforcement in Permission Engine** — `Restriction::is_silenced()` exists but `Permission_Engine::can()` never checks it. Add: if silenced, allow `read` but deny all write actions (create_posts, create_replies, vote). File: `includes/permissions/class-permission-engine.php`

- [ ] **T4: Moderation REST gaps** — Add missing endpoints: `POST /moderation/trash/:type/:id` (set status=trash), `POST /moderation/flags/:id/resolve` (valid/dismissed), `POST /moderation/ban` (create restriction), `DELETE /moderation/ban/:id` (remove restriction). File: `includes/api/class-moderation-controller.php`

- [ ] **T5: Notification API enrichment** — Add `message`, `actor_name`, `actor_avatar`, `actor_login`, `object_url`, `time_ago` to `Notifications_Controller` prepare method. File: `includes/api/class-notifications-controller.php`

---

## HIGH (Expected by users, missing)

- [ ] **T6: @mention parsing + notification** — Scan post/reply content for `@username` patterns on create. For each found: create notification of type `mention`. Wrap matched @username in `<a>` link to profile. Files: `includes/api/class-posts-controller.php`, `includes/api/class-replies-controller.php`, `includes/notifications/class-notifier.php`

- [ ] **T7: Vote batch notifications** — Instead of creating individual notifications per vote, batch them. On vote, check if a "vote" notification exists for the same object within the last hour. If yes, update its message ("5 people upvoted your post"). If no, create new. File: `includes/notifications/class-notifier.php`

- [ ] **T8: Search "All" combined mode + tag search** — When filter=all, query posts + spaces + tags simultaneously and return grouped results. Add `tag` as a search type. File: `includes/api/class-search-controller.php`, `templates/views/search.php`

- [ ] **T9: Canonical URL in free SEO** — Add `<link rel="canonical" href="...">` output in `Template_Loader::set_seo_meta()` for all routes. File: `includes/class-template-loader.php`

- [ ] **T10: Admin-configurable trust thresholds** — Move trust level requirements from hardcoded array in `Trust_Levels` to `jetonomy_settings` option. Add "Permissions" tab fields for: Level 1/2/3 thresholds (posts, days_active, reputation, replies_received) and Level 0 rate limits. Files: `includes/trust/class-trust-levels.php`, `includes/admin/views/settings.php`

---

## MEDIUM (Quality improvements)

- [ ] **T11: Enrich Spaces API members response** — `GET /spaces/:id/members` should return display_name, avatar_url, trust_level, reputation per member. File: `includes/api/class-spaces-controller.php`

- [ ] **T12: Enrich Categories API with spaces** — `GET /categories` list endpoint should include spaces nested under each category (currently only single-category GET does). File: `includes/api/class-categories-controller.php`

- [ ] **T13: Posts per page from settings** — Read `posts_per_page` and `replies_per_page` from `jetonomy_settings` option instead of hardcoded 20. Apply in Post::list_by_space, Reply::list_by_post, and single-post.php. Files: `includes/models/class-post.php`, `includes/models/class-reply.php`, `templates/views/single-post.php`, `templates/views/space.php`

- [ ] **T14: Email unsubscribe header (RFC 8058)** — Add `List-Unsubscribe` and `List-Unsubscribe-Post` headers to all notification emails. Generate a token-based one-click unsubscribe URL. File: `includes/adapters/class-wp-mail-adapter.php`, `includes/notifications/class-notifier.php`

- [ ] **T15: Moderator action notification** — When a moderator approves/spam/trashes a user's content, notify the content author. Hook into the moderation AJAX handlers. File: `includes/admin/class-admin.php`, `includes/notifications/class-notifier.php`

- [ ] **T16: Import dry-run + progress** — Add `--dry-run` flag to CLI import. Add AJAX progress reporting to admin import (send total/imported/skipped counts periodically). Files: `includes/import/class-importer.php`, `includes/class-cli.php`, `includes/admin/views/import.php`

- [ ] **T17: SEO Pro query var fix** — `get_current_context()` reads `jetonomy_space` and `jetonomy_post` but router uses `jetonomy_slug` and `jetonomy_space_slug`. Fix to match. File: `jetonomy-pro/includes/extensions/seo-pro/class-extension.php`

---

## LOW (Polish)

- [ ] **T18: MemberPress/PMPro auto-join on activation** — When membership activates, query access_rules for matching membership level and auto-call `SpaceMember::add()`. Currently the sync_user_spaces method exists but may not fire correctly on all adapter hooks. Files: `includes/adapters/class-member-press-adapter.php`, `includes/adapters/class-pmpro-adapter.php`

- [ ] **T19: Pro adapter register_hooks verification** — Verify WooCommerce, RCP, LearnDash adapters all implement `register_hooks()` for status change events. Files: `jetonomy-pro/includes/adapters/class-woocommerce-adapter.php`, `class-rcp-adapter.php`, `class-learndash-adapter.php`

- [ ] **T20: Cursor-based pagination** — Replace offset-based pagination with cursor-based (`?after=ID&limit=20`) on all list endpoints. This is a bigger refactor — lower priority but required for large-scale performance. Files: all API controllers.

- [ ] **T21: Full accessibility audit** — Verify aria-labels on all interactive elements, focus-visible ring in CSS, heading hierarchy (one h1 per template), all `<img>` have alt/width/height. Run automated WCAG checker.

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
