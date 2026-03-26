# Jetonomy Backend Options Audit

**Date:** 2026-03-26
**Goal:** Verify all admin settings are wired end-to-end (admin UI → save → DB → frontend)

---

## Settings Pipeline Summary

| # | Setting Key | Admin Tab | Saved | Frontend Used | Status |
|---|-------------|-----------|-------|---------------|--------|
| 1 | `base_slug` | General | Yes | Yes (router, URLs, templates) | **OK** |
| 2 | `posts_per_page` | General | Yes | Yes (space listing, search) | **OK** |
| 3 | `replies_per_page` | General | Yes | Yes (single-post reply pagination) | **OK** |
| 4 | `default_space_type` | General | Yes | **No** — never read when creating spaces | **UNWIRED** |
| 5 | `guest_read` | Access | Yes | **No** — template loader doesn't check it | **UNWIRED** |
| 6 | `require_login` | Access | Yes | **No** — no middleware enforces it | **UNWIRED** |
| 7 | `trust_thresholds` | Trust | Yes | Yes (trust evaluator cron) | **OK** |
| 8 | `rate_limits` | Trust | Yes | Yes (API controllers check limits) | **OK** |
| 9 | `email_from_name` | Email | Yes | Yes (wp_mail adapter) | **OK** |
| 10 | `email_from_email` | Email | Yes | Yes (wp_mail adapter) | **OK** |
| 11 | `inherit_fonts` | Appearance | Yes | **No** — CSS tokens don't read it | **UNWIRED** |
| 12 | `inherit_colors` | Appearance | Yes | **No** — CSS tokens don't read it | **UNWIRED** |
| 13 | `accent_color` | Appearance | Yes | **No** — not injected as CSS var | **UNWIRED** |
| 14 | `layout_density` | Appearance | Yes | **No** — no class/spacing change | **UNWIRED** |
| 15 | `custom_css` | Appearance | Yes | **No** — not output via wp_add_inline_style | **UNWIRED** |
| 16 | `seo_post_title` | SEO | Yes | **No** — not used in wp_title filter | **UNWIRED** |
| 17 | `seo_space_title` | SEO | Yes | **No** — not used in wp_title filter | **UNWIRED** |
| 18 | `seo_schema` | SEO | Yes | **No** — Schema_Markup doesn't check flag | **UNWIRED** |
| 19 | `seo_sitemap` | SEO | Yes | **No** — Sitemap doesn't check flag | **UNWIRED** |
| 20 | `seo_noindex_profiles` | SEO | Yes | **No** — no robots meta output | **UNWIRED** |
| 21 | `seo_noindex_search` | SEO | Yes | **No** — no robots meta output | **UNWIRED** |
| 22 | `notification_defaults` | Notifications | Yes | Yes (notifier checks defaults) | **OK** |

### Score: 8 OK / 14 UNWIRED

---

## Fix Plan — Wire All 14 Settings

### P0 — Must wire (affects core functionality)

| # | Setting | Fix | File | Effort |
|---|---------|-----|------|--------|
| 1 | `guest_read` | Check in template_loader — redirect non-logged-in if false | `class-template-loader.php` | 15 min |
| 2 | `require_login` | Check in template_loader — redirect to login if true + not logged in | `class-template-loader.php` | 15 min |
| 3 | `custom_css` | Output via `wp_add_inline_style('jetonomy', ...)` | `class-template-loader.php` | 10 min |
| 4 | `accent_color` | Inject as `--jt-accent` CSS custom property | `class-template-loader.php` | 10 min |

### P1 — Should wire (affects display/UX)

| # | Setting | Fix | File | Effort |
|---|---------|-----|------|--------|
| 5 | `default_space_type` | Pre-fill type dropdown in admin space creation | `admin/views/space-edit.php` | 10 min |
| 6 | `inherit_fonts` | When true, set `--jt-font: inherit` (don't override theme) | `class-template-loader.php` | 10 min |
| 7 | `inherit_colors` | When true, set `--jt-accent: var(--wp--preset--color--primary)` | `class-template-loader.php` | 10 min |
| 8 | `layout_density` | Add `data-jt-density="comfortable|compact"` to `.jt-app` + CSS rules | `class-template-loader.php` + `jetonomy.css` | 20 min |

### P2 — Nice to wire (SEO features)

| # | Setting | Fix | File | Effort |
|---|---------|-----|------|--------|
| 9 | `seo_post_title` | Use pattern in `wp_title` / `document_title_parts` filter | `seo/class-schema-markup.php` | 15 min |
| 10 | `seo_space_title` | Same as above for space pages | `seo/class-schema-markup.php` | 15 min |
| 11 | `seo_schema` | Check flag before outputting JSON-LD | `seo/class-schema-markup.php` | 5 min |
| 12 | `seo_sitemap` | Check flag before registering sitemap provider | `seo/class-sitemap.php` | 5 min |
| 13 | `seo_noindex_profiles` | Add `<meta name="robots" content="noindex">` on profile pages | `class-template-loader.php` | 10 min |
| 14 | `seo_noindex_search` | Same for search page | `class-template-loader.php` | 10 min |

---

## Pro Plugin Options

| Option Key | Used | Status |
|------------|------|--------|
| `jetonomy_pro_extensions` | Yes — checked on every extension boot | **OK** |
| `jetonomy_pro_db_version` | Yes — migration check on load | **OK** |
| `jetonomy_pro_white_label` | Yes — logo/colors in templates | **OK** |
| `jetonomy_pro_digest_settings` | Yes — email digest cron | **OK** |
| `jetonomy_pro_digest_meta_migrated` | Yes — one-time migration guard | **OK** |

### Pro Score: 5/5 OK

---

## Total Effort (Global Settings)

| Priority | Items | Time |
|----------|-------|------|
| P0 | 4 settings | ~50 min |
| P1 | 4 settings | ~50 min |
| P2 | 6 settings | ~60 min |
| **Total** | **14 settings** | **~2.5 hours** |

**Status: ALL 14 FIXED** — commit `3bae019`

---

## Deep Audit: Per-Space Settings (CRITICAL GAPS)

### UNWIRED — Admin UI exists, value saved, but NEVER enforced

| # | Setting | Stored In | Admin UI | Read By | Status |
|---|---------|-----------|----------|---------|--------|
| 1 | **who_can_post** | `spaces.settings['who_can_post']` | space-edit.php dropdown (members/moderators/admins) | **Nothing** — Permission_Engine ignores it | **UNWIRED** |
| 2 | **who_can_reply** | `spaces.settings['who_can_reply']` | space-edit.php dropdown | **Nothing** | **UNWIRED** |
| 3 | **require_approval** | `spaces.settings['require_approval']` | space-edit.php checkbox | **Nothing** — posts publish immediately | **UNWIRED** |
| 4 | **allow_voting** | `spaces.settings['allow_voting']` | space-edit.php checkbox | **Nothing** — voting always works | **UNWIRED** |
| 5 | **posts_per_page** (space override) | `spaces.settings['posts_per_page']` | space-edit.php input | **Nothing** — uses global setting | **UNWIRED** |

### Fix Plan

| # | Fix | File | Effort |
|---|-----|------|--------|
| 1 | Read `who_can_post` in Permission_Engine before granting `create_posts` | `class-permission-engine.php` | 30 min |
| 2 | Read `who_can_reply` in Permission_Engine before granting `create_replies` | `class-permission-engine.php` | 15 min |
| 3 | Read `require_approval` in Posts_Controller::create_item — set status to 'pending' | `class-posts-controller.php` | 20 min |
| 4 | Read `allow_voting` in Votes_Controller — reject if disabled | `class-votes-controller.php` | 10 min |
| 5 | Read space `posts_per_page` in Post::list_by_space with global fallback | `class-post.php` | 10 min |

---

## Deep Audit: Notification Defaults

### Current defaults are ALL `false` — first-time site owners get zero notifications

**Recommended defaults for first-time experience:**

| Type | Web | Email | Reason |
|------|-----|-------|--------|
| reply_to_post | **true** | **true** | Core feature — users expect replies to their posts |
| reply_to_reply | **true** | false | In-app is enough for threaded replies |
| mention | **true** | **true** | @mentions should always notify |
| accepted_answer | **true** | **true** | Q&A core feature |
| vote_on_post | **true** | false | In-app only — email per vote is noisy |
| badge_earned | **true** | false | Nice-to-know, not urgent |
| new_post_in_sub | **true** | false | In-app only — email would be noisy |

---

## QA Tester Protocol: Backend Options (reusable for all plugins)

### 5-Layer Audit Checklist

1. **Global Options** — every `get_option()` key: change in admin → verify frontend changes
2. **Per-Entity Settings** — every DB column + JSON settings field: set value → verify enforcement
3. **Per-User Preferences** — notification prefs, display prefs: change per-user → verify behavior
4. **Access Rules** — membership rules, trust restrictions: create rule → verify access blocked/granted
5. **Feature Flags** — toggles, checkboxes: disable → verify feature stops working

### Red Flags
- Admin dropdown exists but no code reads the value
- Notification type in UI but no `notify()` hook fires for it
- Permission setting in admin but Permission_Engine doesn't check it
- Default is `false` but should be `true` for first-time experience
