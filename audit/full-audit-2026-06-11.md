# Full Audit — Jetonomy Free + Pro (1.5.0-dev, 2026-06-11)

Manifest-driven audit of both plugins: duplicates, dead code, unwired sections,
contract mismatches, and customer-expectation-vs-delivery. Three independent
audit passes (free, pro + free/pro boundary, product); headline claims
spot-verified against source. All five session fixes (compose embed, login
nonce, Turnstile rendering, messaging trio, native avatars) re-verified in
browser before this audit; `wp jetonomy qa-actions` 210/210.

---

## A. CRITICAL — broken or trust-damaging (fix in 1.5.0)

| # | Finding | Where | Evidence |
|---|---------|-------|----------|
| A1 | **Pro Abilities API entirely broken** — all 20 abilities query phantom `wp_jt_*` tables (real prefix `jt_pro_`): `jt_conversations`, `jt_polls`, `jt_badges`, `jt_webhooks`, `jt_reactions`, `jt_custom_fields`, `jt_moderation_rules`. Every execute callback fails at runtime. Worse, they are security-drifted REIMPLEMENTATIONS of extension logic: `execute_get_conversation` has **no participant check**, send-message skips trust gating, several reads use `__return_true` where REST requires `logged_in`. Fix by DELEGATING to extension service methods, never by renaming tables. Add an abilities journey to qa-actions so it can't regress silently. | pro | `includes/class-abilities.php:1066-1530` |
| A2 | **Pro uninstall.php cleans a schema that never shipped** — drops 17 nonexistent `wp_jt_*` tables (base names like `moderation_rules`, `custom_fields`, `analytics_daily` never existed in any version); leaves all 18 real `jt_pro_*` tables, ~8 options (`jetonomy_pro_db_version`, `_white_label`, `_digest_settings`, `_ai_settings`, …) and all `jetonomy_pro_*` user meta behind. Same phantom-schema origin as A1. | pro | `uninstall.php:36-75` |
| A3 | **Analytics aggregate can only drift upward** — listeners only cover creates/votes/pro events; no delete/update/flag-resolution handling in the aggregator, while the query path reflects deletions. Directly invalidates the "<1% drift" 1.5.0 dual-path cutover criterion. Manifest claims listeners that don't exist (renamed in the 1.4.2 hook fix; 7 phantom `free_filters_hooked` entries vs 13 undocumented real listeners). | pro | `extensions/analytics/class-extension.php:130-136`; `class-aggregator.php` |
| A4 | **Web Push is sold but provably non-functional** — payloads sent plaintext; no RFC 8291 (ECDH + AES-128-GCM) anywhere; every modern browser rejects unencrypted push payloads. Red-flagged at 1.4.0, still unfixed. Either implement encryption or pull the readme bullet. | pro | `extensions/web-push/class-extension.php:357-408` |
| A5 | **Three dead tables ship in every install** — `jt_user_interests` (zero entry points: only schema create + privacy eraser), `jt_space_tags` + `jt_space_tag_map` (NO code path ever writes them; `GET /space-tags` can only return an empty list). Clearest three-entry-points violations. Remove or build. | free | `db/class-schema.php:316,74`; `api/class-tags-controller.php:55,114` |
| A6 | **Private Messaging has zero wp-admin surface** — site owner cannot view/moderate/export/purge member DMs (3 tables; only WP-CLI `messaging export-conversations|purge-old`). Flagship Pro feature violating the three-entry-points rule. | pro | `extensions/private-messaging/class-extension.php` (no admin hooks) |
| A7 | **Nonce-refresh retry is inert** — `jetonomy-rest.js` retries 403s against `GET /auth/nonce`, which is not registered (file comment admits "planned"). Ship the endpoint (it would also harden every cached-page mutation) or strip the retry path. | free | `assets/js/jetonomy-rest.js:92`; `api/class-auth-controller.php` |
| A8 | **readme overpromises profile fields** — "bio, website, location, and activity history": `website`/`location` exist nowhere (schema, model, templates). With avatars just shipped natively, finishing the profile story in 1.5.0 closes the gap honestly. | free | `readme.txt`; `jt_user_profiles` schema |

## B. Duplicate functions / logic (consolidate)

Free:
- `Avatar::resolve_user_id()` vs `Fluent_Community::resolve_user_id()` — verbatim duplicate (introduced this session); FC should call the now-public `Avatar::resolve_user_id()`. `class-avatar.php:124` / `class-fluent-community.php:1484`
- `Base_Controller::author_bypasses_spam_check()` reimplements `Permission_Engine::is_space_privileged()`. `class-base-controller.php:74` / `class-permission-engine.php:322`
- Copy-pasted `require_approval → pending` block in Posts + Replies controllers — **with a latent bug**: uses `current_user_can('manage_options')` instead of `user_can($author)`. `class-posts-controller.php:518-526` / `class-replies-controller.php:306-314`
- Invite acceptance forked between `templates/views/invite.php:25-100` and `Spaces_Controller::use_invite()` — security-sensitive flow, drift risk. Extract `InviteLink::accept()`.
- `Cron::prune_activity_log` (batched) vs dead `ActivityLog::prune` (unbatched). Move batched version into the model.
- No `get_space_url()/get_post_url()` helpers — 89 + 43 ad-hoc `'/s/'.$slug` / `'/t/'` concatenations.
- `header.js` + `moderation.js` bypass `jetonomyRest.restFetch` with raw fetch (lose nonce-refresh retry once A7 ships).

Pro / boundary:
- `window.jetonomyMessaging` (apiBase+nonce) duplicates free's `window.jetonomyData`; typeahead uses raw fetch instead of `restFetch`. `private-messaging/class-extension.php:93-104`
- `can_send_messages()` + custom-badges raw-SQL `jt_user_profiles` instead of free's `UserProfile::find_by_user()` (AI extension does it right). `private-messaging:381-397`, `custom-badges:246,980,1014`
- AI + reply-by-email each hand-roll the same transient rate limiter; free ships `Rate_Limiter`.
- Three parallel conversation query paths (views SQL, REST handlers, broken abilities).
- PM views emit raw `get_avatar_url()` `<img>` instead of free's avatar partial (no initials fallback).
- 20+ inline `$wpdb->prefix.'jt_pro_'` because `Extension::table()` is instance-protected.

## C. Dead code (remove)

Free:
- 9 superseded REST permission callbacks (replaced by `REST_Auth` closures): `login_permission_check`, `require_login_check`, `update_permission_check`, `manage_permission_check`, `upload_permissions_check`, `require_ban_permission`, `require_flag`, `require_manage_options`, `REST_Auth::auth_read`.
- `Admin::render_license()` + the never-fireable `jetonomy_admin_render_license` hook (license is a settings tab now).
- ~16 dead model/service methods: `ActivityLog::list_since/prune`, `Flag::count_pending_for_object/count_for_object`, `Post::set_private`, `Revision::count_for_object`, `Space::count_all`, `Moderation_Permissions::can_issue_ban`, `Moderation_Service::dashboard_summary`, `Capabilities::unregister/get_all`, `Cache::flush/is_persistent`, `Reputation::action_points_map`, `Trust_Levels::can_auto_earn`, `Preview_Service::forget`, `Importer::get_phases`, `Fluent_Community::is_fc_active`, `Email_Adapter::send_batch`.
- 3 dead AJAX handlers no client calls: `jetonomy_run_import` (the "kept for CLI" comment is false — CLI calls `Import_Manager::run()` directly), `jetonomy_import_progress`, `jetonomy_get_replies`.
- Dead JS: `actions.changeSort`, `actions.showReplyComposer`, the whole `startPolling → pollNotifications → state.unreadCount` chain (header.js owns polling), write-only `state.composerVisible`.

Pro:
- Dead AJAX `jetonomy_pro_test_ai_provider` (no JS caller; CLI uses the journey). Wire a "Test connection" button or remove.
- Zero-reference methods: `Reactions::can_react`, `Web_Push::can_subscribe`, `Extension::enqueue_frontend_css` (adopt it — it would deduplicate 6× inline-style calls), `AI_Client::get_tracker`, `Email_Digest::get_users_for_frequency`, `Queue::has_action_scheduler`.
- `Semantic_Search` stub class + its orphan `semantic_search.enabled` settings key.

## D. Unwired sections (three entry points)

- Digest preferences: REST routes exist (`/users/me/digest-preferences`, `/admin/digest/test|stats`) with **zero UI consumers**, and digest emails link to a `#digest-preferences` anchor no template renders — a customer-visible dead end. Cheapest wire-up: render a block on edit-profile via `jetonomy_profile_edit_fields`. (pro)
- `jt_read_status` — frontend only (no REST/admin). `jt_revisions` — admin + writes, no REST read / frontend history. `jt_invite_links` — no admin list/revoke UI. `jt_pro_reactions` / `jt_pro_push_subscriptions` — config-only admin, no data browse. Document intentional exceptions in the manifest or wire. (both)
- Free REST endpoints with no first-party consumer: `GET /tags`, `GET /space-tags`, `GET /leaderboards`, `POST/PATCH/DELETE /categories` (admin uses AJAX), `POST /admin/users/trust-level`, `GET /invite/(token)`. Document as external API surface or converge.

## E. Manifest drift (both manifests need a coverage-gated refresh)

- Free: ~40 AJAX entries record nonce `jetonomy_nonce` but handlers verify `jetonomy_admin`/`jetonomy_setup`; stale permission callbacks on `/spaces/*`; `jetonomy_admin_render_license` listed though unfireable; 22 undocumented null-consumer hooks that ARE extension points.
- Pro: 7 phantom `free_filters_hooked` + 13 missing real listeners; ~12 wrong/missing REST routes (digest routes are fiction, PM mute/archive/leave/block + recipient-suggestions absent, polls/reactions paths wrong); fictional `jetonomy_pro_webhook_retry` cron + "retry queue" subsystem claim; site-announcements registers `jetonomy-pro/v1` while everything documents `jetonomy/v1`; `admin_pages` lists 4 of 7; `Orchestrator` class doesn't exist; resolved KG-1 notes still say white-label hooks are deferred.
- Action: full `/wp-plugin-onboard --refresh` on BOTH plugins before further 1.5.0 work relies on the manifests.

## F. Expectation vs delivery (people-first product gaps)

Stale-doc corrections first: FULLTEXT search, @mention autocomplete, inline reply composer, scheduled-post UI, unread badges, and bulk content moderation ALL exist — the gap-analysis doc understates the product.

Top 10 (expectation damage ÷ effort):
1. Web Push: encrypt (RFC 8291) or de-list — d, M, pro (= A4)
2. Profile completion: website/location/social links + cover image — d, S-M, free (readme already promises it; = A8)
3. Member directory `/community/members/` + `GET /users` browse/search — a, M, free
4. Subscriptions management page — REST + follow buttons already shipped, one template+route makes the invisible feature real — c, S, free
5. Moderator member notes + per-user moderation history — a, M, free
6. Social login / SSO (Google first) — a, L, pro
7. Native Stripe per-space paywall + billing UI (adapters stay the integration story) — a, L, pro
8. Markdown input + code fences alongside rich editor — a, M, free
9. Admin-action audit log — a, M, pro
10. Pro discoverability bundle: AI provider empty-state CTA, reply-by-email onboarding card, digest next-run status — c, S, pro (~day of work, known since 1.4.0)

Honorable mentions: events/calendar/RSVP, email broadcasts + send log, full community export (import-only today), bulk member actions, member-follow graph.

## Suggested 1.5.0 order

1. A1+A2 together (same phantom-schema root cause) → abilities delegate to services, uninstall rewritten from manifest
2. A3 analytics delete/update listeners (unblocks the cutover decision this branch exists for)
3. A5 drop/wire the three dead free tables + A6 PM admin surface
4. E manifest refresh both plugins (after the above so it captures them)
5. B consolidation pass + C dead-code sweep (mechanical, low risk, big diff — own commit series)
6. F items 1, 2, 4, 10 (small/medium, high customer visibility) — then scope 3, 5 for 1.5.0/1.6.0
