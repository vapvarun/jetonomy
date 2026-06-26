# Developer Docs & Extensibility Gap Report

**Date:** 2026-06-26
**Status:** Plan for review (no code/docs written yet beyond this report)
**Goal:** Make Jetonomy's developer/customizer story complete and discoverable - especially for people coming from BuddyPress / BuddyBoss who do not yet know what Jetonomy already offers.

---

## TL;DR

The developer guide is **already strong** on raw reference: `02-hooks-reference.md` documents 140+ hooks, `03-template-overrides.md` covers the child-theme override mechanism + a worked post-card example, plus REST (`01`), adapters (`05`), CLI (`10`), abilities (`11`). The real gaps are three different kinds:

1. **No assembled RECIPES.** The pieces exist but no page shows how to combine them ("add a nav item", "add a REST field", "customize a reply/space/member card", "build your first companion plugin").
2. **Internal docs not public.** `frontend-interactivity.md`, `EMAILS.md`, and the `--jt-*` token guide exist but are not in the public developer guide.
3. **Genuine missing extension points** for the *most-requested* customizations (add a profile tab, add a space tab, customize space/member cards, register a custom space type/status/notification). These are **hard-coded today** - so they cannot be documented as clean one-liners until a small filter is added. This is exactly where Jetonomy can out-do native BB/BP.

**Recommendation:** add ~6 small filters (Part B), promote 3 internal docs (Part C), then write ~10 recipe pages + a BuddyPress/BuddyBoss migration guide (Part A/D).

---

## Part A - Doc sections to write (backed by EXISTING hooks/templates - writable now)

| Proposed page | Backed by (verified) |
|---|---|
| **Add an item to the community nav** | `do_action('jetonomy_header_nav_items')` (templates/partials/header.php:59); `jetonomy_show_community_nav` filter to suppress |
| **Customize the cards** (post, reply, single-post) | `jetonomy_post_card_after_badges` (post-card.php:100, single-post.php:394); `jetonomy_post_meta_fields`, `jetonomy_after_post_content`, `jetonomy_post_actions`, `jetonomy_after_post_article`, `jetonomy_before/between/after_replies` (single-post.php); `jetonomy_reply_actions` (reply-card.php:237) |
| **Child-theme template overrides (complete)** | class-template-loader.php lookup (theme `jetonomy/` -> plugin default); the full overridable view+partial list (~40 files); `jetonomy_template_map` to add routes. Extend the existing `03` with the full table + reply card example |
| **Theming with `--jt-*` tokens** | 5 admin palette tokens (accent/text/bg/bg-subtle/border) + `inherit_colors` chain + `inherit_fonts` + `custom_css`; child-theme override via higher-specificity `:root`. (Promote token catalogue from CLAUDE.md/standards) |
| **Extend the frontend (Interactivity API)** | `store('jetonomy', ...)`, `jetonomy:navigated` event (view.js:775), `window.jetonomyRest.restFetch`, `jetonomyHydrateInteractive`, `jetonomyCollectCustomFields` (promote internal `frontend-interactivity.md`) |
| **Extend the REST API** | `jetonomy_rest_prepare_{post,reply,space,user,notification}` filters to add fields; `register_rest_route('jetonomy/v1', ...)` for new routes; `jetonomy_check_content` moderation intercept |
| **Custom notifications** | `jetonomy_notification_created` (class-notifier.php:911) + `Notification::create(...)`; the full `jetonomy_email_*` filter set (promote internal `EMAILS.md`) |
| **Admin extensions** | settings tabs (`jetonomy_admin_settings_tabs`/`_tab_content`), space-edit tabs, moderation tabs, dashboard widgets, `jetonomy_admin_menu_label`/`_icon`/`_footer_text` |
| **Template-tag / helper reference** | `Jetonomy\base_url/get_profile_url/get_space_edit_url/header_logo/footer_text/notification_deep_link/get_user_link/client_ip/table` + global helpers (`jetonomy_admin_empty_state`, `jetonomy_community_pulse`, `jetonomy_space_activity_label`) - currently undocumented |
| **Developer quick-start / cookbook index** | New landing page: "build your first companion plugin" wiring `functions.php` -> hooks -> template override -> REST field |

## Part D (a doc, listed with A) - "Coming from BuddyPress / BuddyBoss"

A migration guide + FAQ table. Concept map (groups->spaces, activity->feed, xprofile->Pro custom fields, member types->trust levels/roles, group tabs->space tabs, `bp_user_can`->`Jetonomy\Visibility`). The per-row mapping is already drafted from the discovery and is mostly write-ready. **Caveat:** several rows resolve to "no direct equivalent" - see Part B - so this guide's honesty depends on whether we add those filters.

---

## Part B - Feature gaps: NO extension point today (decision: add a small filter, then document)

These are the customizations developers (and the user) most want, and they are **hard-coded** right now. Each is a small, low-risk filter. Adding them turns "override the whole template" into a clean one-liner - and directly answers "how do I add a profile menu/tab" which native BB/BP make trivial.

| # | Customization | Current state (hard-coded) | Proposed extension point |
|---|---|---|---|
| 1 | **Add a profile tab/menu** | tabs are static `<a>` in user-profile.php:246-264; no hook | `apply_filters('jetonomy_profile_tabs', $tabs, $user)` + a content hook/slug route |
| 2 | **Add a space (frontend) tab** | static `<a>` in space.php:334-348; only the wp-admin side has tab hooks | `apply_filters('jetonomy_space_tabs', $tabs, $space)` + content hook |
| 3 | **Space card / member card hooks** | space cards inline in home.php/category.php, member rows in space-members.php; no hooks | `jetonomy_space_card_after_meta` / `jetonomy_member_card_actions` (mirror the post-card hook) |
| 4 | **Register a custom space type / post type** | whitelist `['forum','qa','ideas','feed']` hard-coded (admin + posts-controller); REST `type` enum gates too | `apply_filters('jetonomy_space_types', $types)` (+ REST enum read from it) |
| 5 | **Register a custom roadmap/idea status** | `Post::valid_idea_statuses()` returns a hard-coded array | `apply_filters('jetonomy_idea_statuses', $statuses)` |
| 6 | **Filter the dynamic token CSS** | `$dynamic_css` emitted with no filter | `apply_filters('jetonomy_dynamic_css', $css, $settings)` |
| 7 | **Register a notification type** | type enum hard-coded; no registry | `apply_filters('jetonomy_notification_types', $types)` (in-app pref UI + dispatch) |
| 8 | **Admin list-table columns/bulk actions** | `get_columns()` hard-coded in Activity/Revisions list tables | standard `manage_*_columns`-style filters on the JT list tables |

Each is ~5-15 lines. #1, #2, #3 are the headline asks ("profile menus, new tabs, cards").

---

## Part C - Internal docs to PROMOTE into the public developer guide

These already exist and are accurate; they just are not in `docs/website/developer-guide/`:

- `docs/standards/frontend-interactivity.md` -> "Extend the frontend" dev page
- `docs/developer/EMAILS.md` -> "Customize notification emails" dev page
- `docs/standards/host-theme-color-adoption.md` + CLAUDE.md token catalogue -> "Theming with tokens" dev page

---

## Bigger structural gaps vs BB/BP (feature decisions, not docs)

The discovery also surfaced things with **no Jetonomy equivalent at all** - flagged here so they are a conscious choice, not an accidental omission:

- **No site-wide activity stream** (BP's core surface). Bridged only via the BuddyPress integration's `jetonomy_topic` broadcast.
- **No member directory + facet filters** (`groups_directory_group_filter_options`). Only `jetonomy_search_query_args` exists.
- **No member types** (`bp_register_member_type`). Trust levels (0-5) + WP roles are the nearest.
- **No member cover images.**
- **No notification-preference UI injection hook.**

These are roadmap calls (do we want them?), separate from the docs work.

---

## Proposed sequence (once approved)

1. **Add Part B filters #1-#5** (profile tab, space tab, space/member card, space type, idea status) - small PR, unlocks the headline docs.
2. **Promote Part C** internal docs into the public guide.
3. **Write Part A recipes** + the **BuddyPress/BuddyBoss migration guide** (now able to show clean one-liners instead of full template overrides).
4. **Decide** on the structural BB/BP gaps (activity stream, directory filters, member types, cover images) as roadmap items.

All docs are GitHub-only (repo markdown), nothing synced to the live docs site.
