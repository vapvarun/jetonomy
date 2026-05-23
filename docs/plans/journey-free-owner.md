# Jetonomy Free-Only Owner Journey Audit

**Branch**: 1.4.4-dev. jetonomy-pro: INACTIVE.
**Date**: 2026-05-23.
**Method**: Static code trace, WP-CLI plugin-list confirmation, and browser verification of the 1.4.4 fixes from `1.4.4-verification.md`.

---

## What got easier on 1.4.4-dev

After activating only Jetonomy 1.4.4 (no Pro, no other helper plugin), you can:

- Open the License screen and click Manage License without seeing a broken-script error. The licensing UI ships with the plugin.
- Walk away from a brand new community and trust that the recurring background jobs still run. Trust promotions, expired ban cleanup, scheduled-post publishing, activity pruning, notification cleanup, and verification reminders all run through a reliable queue, not WP-Cron, so they fire on time even on quiet first-day sites.
- Hit any space on the front end and click "Edit this space" in the admin bar. You no longer have to bounce through wp-admin to tweak a space you are browsing.
- Trust the destructive-confirm dialog in the Banned Users tab to look destructive: ghost Cancel, red Confirm, matching every other danger surface.
- Build a release zip from a clean clone and have it work on first activation. The build refuses to produce a zip that is missing the licensing SDK or the background-job library.

---

## Journey Table

| Moment | Tag | Works free-only? | Notes | Gap |
|---|---|---|---|---|
| **Activation** - plugin boots, DB tables created, caps registered | [free] | Yes | No Pro class is called; activation runs only free `Schema::create_tables()` and `Migrator::run()`. | None |
| **Activation - licensing UI loads** | [free] | Yes | `libs/edd-sl-sdk/` is committed and shipped in every zip. `jetonomy.php` loads the SDK before any admin screen renders. License page JS and CSS resolve from the plugin. | None. Closed in 1.4.4. |
| **Activation - background-job queue boots** | [free] | Yes | `libs/action-scheduler/` is bundled in free. Free no longer relies on WooCommerce or any other plugin to provide Action Scheduler. AS boots at `plugins_loaded`; `Cron` registers the six recurring hooks against AS at `action_scheduler_init`. | None. Closed in 1.4.4. |
| **First-day background jobs run on a quiet site** | [free] | Yes | All six free recurring hooks (`jetonomy_trust_evaluation`, `jetonomy_cleanup_expired`, `jetonomy_prune_activity`, `jetonomy_cleanup_notifications`, `jetonomy_publish_scheduled`, `jetonomy_verification_reminder`) run via AS. They no longer skip on quiet sites with no traffic. A migration flag prevents double scheduling on upgrade. | None. Closed in 1.4.4. |
| **Setup wizard** - `/admin.php?page=jetonomy-setup`, 3-step AJAX wizard | [free] | Yes | The wizard view has zero `JETONOMY_PRO_VERSION` references. AJAX is handled by free `Setup_Handler` + `Demo_Seeder`; Demo_Seeder's Pro branch is guarded by `defined( 'JETONOMY_PRO_VERSION' )`. | None |
| **First category** - Categories admin page, create/edit/delete/reorder | [free] | Yes | `class-categories-handler.php` has no Pro references. The Categories view has no Pro guards. | None |
| **First space** - Spaces admin page, new/edit/access-rules | [free] | Yes | The Spaces view shows a Pro upsell for Polls / Reactions / Analytics that is guarded by `! defined( 'JETONOMY_PRO_VERSION' )`. Space edit tabs show "Custom Fields" and "Reactions" as disabled placeholders behind the same guard. All functional tabs (General, Members, Access, Settings, Join Requests) work without Pro. | None |
| **Front-end "Edit this space" admin-bar entry** | [free] | Yes | `class-admin-bar.php` adds a context-aware Edit link to the Community admin-bar menu when the viewer passes `Permission_Engine::is_space_admin`. Pure-free path; no Pro reference. | None. Closed in 1.4.4. |
| **Permission / trust / reputation config** - Settings → Permissions tab | [free] | Yes | All trust and reputation classes (`Trust_Levels`, `Trust_Evaluator`, `Reputation`, `Rate_Limiter`) live in free with no Pro dependency. The Permissions tab renders without Pro guards. | None |
| **Appearance** - Settings → Appearance tab | [free] | Yes | A "White Label" Pro upsell card is guarded. All functional appearance settings (accent color, container width, sidebar, padding, custom CSS) are free. | None |
| **SEO settings** - Settings → SEO tab | [free] | Yes | An "SEO Pro" upsell for OG tags / advanced schema is guarded. Free SEO (title templates, schema on/off, sitemap, noindex, canonical) all work without Pro. The template loader correctly falls through to the free baseline when Pro is absent. | None |
| **Anti-spam config** - Settings → Anti-Spam tab (CAPTCHA, Akismet, AI spam) | [free] | Yes | No Pro guards in the Anti-Spam tab block. Akismet and AI spam detector classes are pure-free. | None |
| **Email config** - Settings → Email tab | [free] | Yes | An "Email Digest" Pro upsell is guarded. All free email settings (from-name, templates, notification defaults, email logo URL) work without Pro. | None |
| **Moderation queue - Pending Posts tab** - approve/spam/trash | [free] | Yes | The render queries only free tables. Approve/spam/trash AJAX calls `Post::update()` / `Reply::update()`. No Pro dependency. | None |
| **Moderation queue - Pending Replies tab** | [free] | Yes | Same as Pending Posts; reply object type is handled in all three moderation AJAX methods. | None |
| **Moderation queue - Flags tab** - resolve valid/dismiss | [free] | Yes | `Moderation_Service::resolve_flag()` uses only free models. The admin AJAX handler delegates to the same service. No Pro class touched. | None |
| **Moderation queue - Banned Users tab** - unban | [free] | Yes | Ban/unban AJAX uses the free `Restriction` model. The unban confirm dialog uses the design-system Cancel (`jt-btn jt-btn-ghost`) + Confirm (`jt-btn jt-btn-danger`) pair. | None |
| **Auto-Rules tab** - advanced mod auto-rules | [pro] | Graceful absence | The moderation view renders a disabled anchor with a PRO badge when Pro is absent. The `jetonomy_admin_moderation_tabs` / `_tab_content` actions fire with no listener. | None |
| **Flag indicator on single post** - moderator sees open-flag count inline | [free] | Yes | The single-post template reads `$post->flag_count` (denormalized int, column added by `Migration_1_4_4` and backfilled on upgrade). Guard uses free `Permission_Engine::can`. | None |
| **Close / Reopen topic** - moderator toggle on single post | [free] | Yes | The template calls `actions.closePost` (free view.js). REST `POST /jetonomy/v1/posts/{id}/close` is pure-free; permission gate uses `check_permission( 'close_posts', space_id )`. | None |
| **Accept / Unaccept reply** - Q&A answer acceptance | [free] | Yes | `POST /jetonomy/v1/replies/{id}/accept` and `/unaccept` accept only the post author or a moderator with `close_posts`. Pure-free models. | None |
| **Activity log** - admin Activity Log page | [free] | Yes | Reads only the free `jt_activity_log` table via `Activity_List_Table`. CSV export works free. | None |
| **Revisions** - admin Revisions page | [free] | Yes | Reads free `jt_revisions`. No Pro guards. | None |
| **Users admin** - search, trust-level edit, ban/unban | [free] | Yes | A "Custom Badges & Advanced Moderation" Pro upsell is guarded. All functional columns (trust level, reputation, posts, replies, ban modal) work without Pro. | None |
| **Dashboard** - stat cards, recent activity, system info | [free] | Yes | Queries only free tables. An "Analytics" Pro upsell card is guarded. The `jetonomy_admin_dashboard_widgets` action fires empty in free. | None |
| **"Is it ready"** - setup-complete check, demo data cleanup | [free] | Yes | Dashboard checks `jetonomy_setup_complete`. The demo-data cleanup banner uses a free option only. No Pro dependency. | None |
| **Front-end moderation - cross-space `/mod/` route** | [free] | Yes | Uses free `Moderation_Service` + `Moderation_Permissions`. No Pro reference. | None |
| **Front-end moderation - per-space `/s/:slug/mod/` route** | [free] | Yes | Uses `Moderation_Service::list_pending_flags()`. No Pro reference. | None |
| **Private Messages link** - user panel block | [pro] | Graceful absence | The link is rendered only when Pro is active. Router registers the `/messages/` rewrite rules only when Pro is active. A direct URL in free-only mode 404s, as intended. | None |
| **Extensions admin submenu** | [pro] | Guarded | The submenu is registered only when Pro is active. Not visible in free. | None |
| **License settings tab** | [pro] | Guarded | License tab content is rendered only when Pro is active. The sidebar link is guarded too. | None |

---

## Closed in 1.4.4 (previously open or assumed-Pro)

1. **Licensing UI works on first activation.** EDD SL SDK is now bundled under `libs/edd-sl-sdk/` and shipped in every release zip. The Manage License button no longer fails with a missing-script error after a clean install.
2. **Background-job queue ships with free.** Action Scheduler 3.9.3 lives under `libs/action-scheduler/` in free. A free customer with no Pro plugin, no WooCommerce, and no other AS-providing plugin still gets a working queue from day one.
3. **First-day cron jobs are reliable.** All six free recurring hooks run via AS instead of WP-Cron, so trust promotions, ban expirations, and scheduled publishes fire on time on quiet sites that get no traffic.
4. **Front-end Edit Space affordance.** Space admins and owners see "Edit this space" in the admin bar on every space-context page and reach the front-end edit screen in one click.
5. **Destructive admin confirmations match the design system.** The Banned Users tab uses the ghost-cancel + danger-confirm classes. No code change was needed; the older audit screenshot predated the token swap.
6. **Release-zip integrity is enforced at build time.** `bin/build-release.sh` asserts the EDD SL SDK loader, the SDK JS bundle, the SDK CSS, and Action Scheduler are all present in staging before producing the zip. A clean clone can no longer build a broken zip.

---

## Free-Only Gaps (things broken or materially absent for a free customer)

1. **No gaps found at the free vs Pro boundary.** Every Pro-specific surface (auto-rules, reactions, polls, custom fields, analytics, white-label, private messages, email digest, SEO Pro, custom badges) degrades gracefully in free-only mode: either a `defined( 'JETONOMY_PRO_VERSION' )` guard shows a static "PRO" upsell or disabled tab, or a `do_action` fires with no listener. No unguarded `Jetonomy_Pro\*` class instantiations exist in the free codebase.

2. **`flag_count` column** is new in 1.4.4 via `Migration_1_4_4`. Installations upgrading from pre-1.4.4 are backfilled by the migrator at registration time. On a fresh 1.4.4 install the column is created by `Schema::create_tables()`. Not a free-vs-pro gap; recorded here for upgrade context.

3. **`jetonomy_new_post_submit_action` filter.** In free this filter defaults to `actions.submitNewPost` and fires with no Pro listener; Pro filters it to `actions.submitNewPostWithPoll`. Free-only mode runs the correct default without error. Recorded here so any future fix to the composer touches both view.js (free) and pro-view.js (Pro).
