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

## Total Effort

| Priority | Items | Time |
|----------|-------|------|
| P0 | 4 settings | ~50 min |
| P1 | 4 settings | ~50 min |
| P2 | 6 settings | ~60 min |
| **Total** | **14 settings** | **~2.5 hours** |
