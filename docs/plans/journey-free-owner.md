# Jetonomy Free-Only Owner Journey Audit

Branch: 1.4.4-dev. jetonomy-pro: INACTIVE. Audit method: static code trace + WP-CLI plugin-list confirmation. DB queries skipped (MySQL socket unavailable in audit session; schema assertions are code-level only).

---

## Journey Table

| Moment | Tag | Works free-only? | Gap / file:line | Severity |
|---|---|---|---|---|
| **Activation** — plugin boots, DB tables created, caps registered | [free] | Yes | No Pro class called; `class-jetonomy.php` calls only free `Schema::create_tables()` and `Migrator::run()`. | None |
| **Setup wizard** — `/admin.php?page=jetonomy-setup`, 3-step AJAX wizard | [free] | Yes | `setup-wizard.php` has zero `JETONOMY_PRO_VERSION` references. AJAX handled by `Setup_Handler` + `Demo_Seeder`; Demo_Seeder's Pro branch at `class-demo-seeder.php:638` is guarded by `defined( 'JETONOMY_PRO_VERSION' )`. | None |
| **First category** — Categories admin page, create/edit/delete | [free] | Yes | `class-categories-handler.php` has no Pro references. View `categories.php` has no Pro guards. | None |
| **First space** — Spaces admin page, new/edit/access-rules | [free] | Yes | `spaces.php:292` shows a Pro upsell (Polls/Reactions/Analytics) guarded by `! defined( 'JETONOMY_PRO_VERSION' )`. Space edit tabs at `space-edit.php:46-48` show "Custom Fields" and "Reactions" tabs as disabled placeholders (guarded). All functional tabs (General, Members, Access, Settings, Join Requests) work without Pro. | None |
| **Permission/Trust/Reputation config** — Settings > Permissions tab (trust thresholds, rate limits, reputation points, role map) | [free] | Yes | All trust/reputation classes (`Trust_Levels`, `Trust_Evaluator`, `Reputation`, `Rate_Limiter`) live in free with no Pro dependency. Settings view at `settings.php:292-410` renders the full Permissions tab without Pro guards. | None |
| **Appearance** — Settings > Appearance tab (theme tokens, accent color, container width, sidebar, padding, custom CSS) | [free] | Yes | `settings.php:849` shows a "White Label" Pro upsell, guarded. All functional appearance settings are free. | None |
| **SEO settings** — Settings > SEO tab | [free] | Yes | `settings.php:1043` shows an "SEO Pro" upsell for OG tags / advanced schema, guarded. Free SEO (title templates, schema on/off, sitemap, noindex, canonical) all work without Pro. The template loader at `class-template-loader.php:834` checks `defined( 'JETONOMY_PRO_VERSION' )` before deferring meta-emit to seo-pro — correctly falls through to free baseline when Pro is absent. | None |
| **Anti-spam config** — Settings > Anti-Spam tab (CAPTCHA, Akismet, AI spam) | [free] | Yes | No Pro guards in the Anti-Spam tab block. `class-akismet.php` and `class-ai-spam-detector.php` are pure-free classes. | None |
| **Email config** — Settings > Email tab | [free] | Yes | `settings.php:702` shows an "Email Digest" Pro upsell, guarded. All free email settings (from-name, templates, notification defaults) work without Pro. | None |
| **Moderation queue — Pending Posts tab** — approve/spam/trash | [free] | Yes | `render_moderation()` in `class-admin.php:928-1008` queries only free tables. AJAX in `class-moderation-handler.php` calls `Post::update()` / `Reply::update()` — no Pro dependency. | None |
| **Moderation queue — Pending Replies tab** | [free] | Yes | Same as above; `reply` object type handled in all three moderation AJAX methods. | None |
| **Moderation queue — Flags tab** — resolve valid/dismiss | [free] | Yes | `Moderation_Service::resolve_flag()` in `class-moderation-service.php` uses only free models (`Flag`, `Post`, `Reply`, `Reputation`). Admin AJAX handler at `class-moderation-handler.php:100-133` delegates to the same service. No Pro class touched. | None |
| **Moderation queue — Banned Users tab** — unban | [free] | Yes | Ban/unban AJAX in `Users_Handler` uses free `Restriction` model. `moderation.php` view shows banned-users tab, no Pro guard. | None |
| **Auto-Rules tab** — advanced mod auto-rules | [pro] | Graceful absence | `moderation.php:45-47` renders a `disabled` anchor with PRO badge when `! defined( 'JETONOMY_PRO_VERSION' )`. The `do_action( 'jetonomy_admin_moderation_tabs', $active_tab )` and `do_action( 'jetonomy_admin_moderation_tab_content', $active_tab )` fire with no listener in free — correct empty-fire. | None |
| **Flag indicator on single post** — moderator sees open-flag count inline | [free] | Yes | `single-post.php:552-570` reads `$post->flag_count` (denormalized int, column added in `Migration_1_4_4`, schema at `class-schema.php:164`). Guard is `$jt_can_moderate_here` (free `Permission_Engine::can`). No Pro class referenced. The 1.4.4 migration backfills the counter on upgrade. | None |
| **Close / Reopen topic** — moderator toggle on single post | [free] | Yes | `single-post.php:596` calls `actions.closePost` (free `view.js`). REST: `POST /jetonomy/v1/posts/{id}/close` at `class-posts-controller.php:764-786` is pure-free; permission gate uses `check_permission( 'close_posts', space_id )` from free `Permission_Engine`. | None |
| **Accept / Unaccept reply** — Q&A answer acceptance | [free] | Yes | `POST /jetonomy/v1/replies/{id}/accept` and `/unaccept` at `class-replies-controller.php:93-98`. Accepts only post author or moderator (`close_posts` ability). Pure-free models. | None |
| **Activity log** — admin Activity Log page | [free] | Yes | `render_activity()` reads free `jt_activity_log` table via `Activity_List_Table`. View `activity.php` has zero Pro guards. CSV export works free. | None |
| **Revisions** — admin Revisions page | [free] | Yes | `Revisions_List_Table` reads free `jt_revisions`. View `revisions.php` has zero Pro guards. | None |
| **Users admin** — search, trust-level edit, ban/unban | [free] | Yes | `users.php:192` shows a "Custom Badges & Advanced Moderation" Pro upsell, guarded. All functional columns (trust level, reputation, posts, replies, ban modal) work without Pro. | None |
| **Dashboard** — stat cards, recent activity, system info | [free] | Yes | Dashboard queries only free tables. `dashboard.php:269` shows an "Analytics" Pro upsell card, guarded by `! defined( 'JETONOMY_PRO_VERSION' )`. The hook `do_action( 'jetonomy_admin_dashboard_widgets' )` fires empty in free — correct. | None |
| **"Is it ready"** — setup-complete check, demo data cleanup | [free] | Yes | `dashboard.php:15` checks `jetonomy_setup_complete` option. Demo data cleanup banner at `dashboard.php:231` uses free option only. No Pro dependency on the readiness signal. | None |
| **Frontend moderation — cross-space /mod/ route** | [free] | Yes | `views/moderation.php` uses `Moderation_Service` (free), `Moderation_Permissions` (free). No Pro reference. | None |
| **Frontend moderation — per-space /s/:slug/mod/ route** | [free] | Yes | `views/space-moderation.php` uses `Moderation_Service::list_pending_flags()` (free). No Pro reference. | None |
| **Private Messages link** — user panel block | [pro] | Graceful absence | `class-blocks.php:654`: `$show_messages = defined( 'JETONOMY_PRO_VERSION' )`. Link rendered only when Pro active. Router at `class-router.php:100-103` only registers `/messages/` rewrite rules when Pro is active. Template map has no `messages`/`conversation` entries — a direct URL would 404. | None |
| **Extensions admin submenu** | [pro] | Guarded | `class-admin.php:179-189`: `add_submenu_page` for Extensions only called when `defined( 'JETONOMY_PRO_VERSION' )`. Not visible in free. | None |
| **License settings tab** | [pro] | Guarded | `settings.php:1225`: license tab content only rendered when `defined( 'JETONOMY_PRO_VERSION' )`. Sidebar link at `settings.php:92` also guarded. | None |

---

## Free-Only Gaps (things broken or materially absent for a free customer that combo mode masked)

1. **No gaps found.** Every Pro-specific surface (auto-rules, reactions, polls, custom fields, analytics, white-label, private messages, email digest, SEO Pro, custom badges) degrades gracefully in free-only mode: either a `defined( 'JETONOMY_PRO_VERSION' )` guard shows a static "PRO" upsell/disabled tab, or a `do_action` fires with no listener. No unguarded `Jetonomy_Pro\*` class instantiations exist in the free codebase (`includes/` grep returned zero results outside the QA journey-test file, which guards all Pro class references with `class_exists()` before calling).

2. **`flag_count` column** is new in 1.4.4 (`Migration_1_4_4`). Installations upgrading from pre-1.4.4 without running migrations would show `$post->flag_count = null` (PHP null-coalesced to 0 at `single-post.php:555`), so the flag indicator would never appear even on posts with open reports. This is a migration-sequencing concern, not a free-vs-pro gap, and the migrator registers the migration at `class-migrator.php:51`. On a fresh 1.4.4 install the column is created by `Schema::create_tables()`.

3. **One combo-masked assumption identified but not a free gap:** the `jetonomy_new_post_submit_action` filter in `new-post.php:94` defaults to `actions.submitNewPost` in free; Pro filters it to `actions.submitNewPostWithPoll`. In free-only, the filter fires with the correct default and no runtime error occurs. This only became visible in free-only review because in combo mode the filter value always differed.
