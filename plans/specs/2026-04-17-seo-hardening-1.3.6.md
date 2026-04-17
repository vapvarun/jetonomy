# Jetonomy 1.3.6 — SEO Hardening for UGC

**Status:** queued for 1.3.6. No code to ship yet.
**Scope boundary:** free plugin only. SEO Pro extension (jetonomy-pro) is unaffected.

## Why

Forum content is user-generated, sits in custom `wp_jt_*` tables, and is invisible to third-party SEO plugins unless we feed them. Today's free build covers Spaces + Topics (via WP core `wp_sitemaps`), emits `DiscussionForumPosting` / `QAPage` / `BreadcrumbList` schema, and applies customisable title patterns. The gaps are categories, tags, sort/page canonicalisation, crawl efficiency, social meta, and the admin's ability to see which SEO surface is active.

## Design principle — one pipeline, three exits

We keep a single source of truth for forum URLs. That source publishes through whichever exit the admin's environment exposes:

1. **Always:** WP core `wp_sitemaps` registry (what we already use). This is the WordPress-native API every well-behaved SEO plugin respects.
2. **Auto-detect and bridge:** When Yoast / Rank Math / AIOSEO is active, also register via that plugin's documented filter so our URLs merge into its sitemap index. Admin gets one unified sitemap, not two.
3. **Admin visibility:** Settings → SEO shows a "Sitemap Status" card with the live detected bridge, counts per entity, and direct links.

When the admin disables Yoast, WP core's sitemap takes back over with zero admin action.

## Changes — free plugin

### 1. New sitemap providers

- `Categories_Sitemap_Provider` — reads `wp_jt_categories`, 2k per page, `lastmod` = category `updated_at` or latest child space activity.
- `Tags_Sitemap_Provider` — reads `wp_jt_tags`, 2k per page, `lastmod` = latest tagged-post `updated_at`.

Register both in `Sitemap::register()` alongside existing providers.

### 2. SEO plugin bridges

New namespace `Jetonomy\SEO\Bridges\*`. Each bridge ~40 lines, same data source:

- `Yoast_Bridge` — hooks `wpseo_sitemap_index` + `wpseo_sitemap` to emit our four provider URL lists.
- `Rank_Math_Bridge` — hooks `rank_math/sitemap/providers` with a custom provider class.
- `AIOSEO_Bridge` — hooks `aioseo_sitemap_additional_urls`.

Detection at `init` priority 20 via class-exists / defined-constant checks. Only one bridge loads at a time.

### 3. Canonical + pagination hygiene

Template-layer touches in `templates/views/single-post.php` and `templates/views/space.php`:

- Sort variants (`?sort=latest|oldest|best`) → `<link rel="canonical">` points at the no-query URL (default sort).
- Paginated topic listings (`?page=N`) → each page self-canonicalises. Do NOT point page N back at page 1 (per Google's 2023 guidance).

### 4. Crawl efficiency

- `Last-Modified` header on single topic + space views, driven by `last_reply_at` (topic) / `last_activity_at` (space).
- Handle `If-Modified-Since` with 304 short-circuit before template rendering.

### 5. Social + article meta

Extend `Schema_Markup::output_schema()` to also emit on single topics:

- `og:type="article"`, `og:title`, `og:description`, `og:url`, `og:image` (first embedded image or space OG image fallback)
- `article:published_time`, `article:modified_time`, `article:author`
- `twitter:card="summary_large_image"`, `twitter:title`, `twitter:description`, `twitter:image`

### 6. UGC link hardening

Post + reply body renderer (`jetonomy_format_content`) — add `rel="ugc nofollow"` to every external `<a>` in user-generated content. Internal links untouched. Protects site authority and matches Google's explicit UGC guidance.

### 7. Admin — Sitemap Status card

New card in Settings → SEO tab:

- Detected bridge name (Yoast / Rank Math / AIOSEO / WP core)
- Link to the active sitemap index
- Live counts per entity (spaces, topics, categories, tags) read from the provider classes
- Toggle to disable all bridges (fallback to WP core only) for admins who want manual control

## Out of scope — deliberately

- hreflang — no i18n story in 1.3.6
- Profile `Person` schema — profiles stay `noindex` by default, skip until the admin opts in
- Reply-level canonicals — replies inherit the topic URL via fragment; no separate page to canonicalise
- SEO Pro extension changes — per-space overrides already work; this release is about community-level coverage

## Verification plan

Throwaway Local site, for each SEO plugin {none, Yoast, Rank Math, AIOSEO}:

1. Activate the plugin.
2. Visit `/sitemap_index.xml` (or `/wp-sitemap.xml` for none) — confirm our four URL types are present.
3. Run Google Search Console URL Inspection on a topic URL — confirm `DiscussionForumPosting` schema parses cleanly.
4. Confirm `Last-Modified` header on a topic view via `curl -I`; confirm 304 on `If-Modified-Since`.
5. Confirm `og:*` + `twitter:*` on View Source of a topic.
6. Confirm external links in a posted reply have `rel="ugc nofollow"`.
7. Admin → Settings → SEO → Sitemap Status card shows the detected bridge + live counts.

## Files touched (estimate)

| Path | Change |
|------|--------|
| `includes/seo/class-sitemap.php` | Register 2 new providers + bridge loader |
| `includes/seo/class-categories-sitemap-provider.php` | New |
| `includes/seo/class-tags-sitemap-provider.php` | New |
| `includes/seo/bridges/class-yoast-bridge.php` | New |
| `includes/seo/bridges/class-rank-math-bridge.php` | New |
| `includes/seo/bridges/class-aioseo-bridge.php` | New |
| `includes/seo/class-schema-markup.php` | Extend with og/twitter/article meta |
| `includes/seo/class-crawl-headers.php` | New — Last-Modified + 304 |
| `jetonomy.php` | `jetonomy_format_content()` — add `rel=ugc nofollow` to external links |
| `templates/views/single-post.php` | Canonical tag respecting sort/page |
| `templates/views/space.php` | Canonical tag respecting sort/page |
| `includes/admin/views/settings.php` | Sitemap Status card in SEO tab |
| `readme.txt` | `= 1.3.6 =` changelog entry |
| `jetonomy.php` | `Version:` + `JETONOMY_VERSION` → `1.3.6` |

## Estimated effort

~600 lines net. Three days: one for providers + bridges, one for schema/meta/canonical/headers, one for the admin card + QA + changelog.
