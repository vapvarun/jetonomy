The SEO settings tab controls how Jetonomy pages appear in search engines - XML sitemaps, structured data, meta title patterns, and robots directives for specific page types.

![SEO settings with sitemap toggle, meta title patterns, and robots directives](../images/admin-seo.png)

## What You Will Learn

- How Jetonomy generates SEO-friendly URLs for community pages
- How to enable or disable the XML sitemap
- How schema markup works for forum content
- How to configure meta title patterns
- How to exclude certain page types from search engine indexing

Go to **Jetonomy → Settings → SEO** to access these settings.

## How Jetonomy Generates URLs

Every Jetonomy page has a clean, human-readable URL structured around your community base slug:

| Page Type | URL Pattern |
|---|---|
| Community home | `/community/` |
| Category | `/community/category/slug/` |
| Space | `/community/s/slug/` |
| Post | `/community/s/space-slug/t/post-slug/` |
| User profile | `/community/u/username/` |
| Tag | `/community/tag/slug/` |
| Search | `/community/search/` |

Post slugs are auto-generated from the post title, truncated at 60 characters, and deduplicated with a numeric suffix if needed. All URLs use standard WordPress rewrite rules and are compatible with all SEO plugins.

## XML Sitemap

**Setting:** `seo_sitemap`
**Default:** On
**Location:** SEO tab → Sitemap section

When enabled, Jetonomy registers a custom sitemap provider with WordPress's built-in sitemap API. Community pages appear at `/wp-sitemap.xml` alongside your regular WordPress content.

The sitemap includes:
- All public spaces
- All public posts (paginated if over 2,000)
- User profile pages

Private, hidden, and archived spaces are excluded. Draft and scheduled posts are excluded.

> **Tip:** Check **Settings → Reading → Search engine visibility** is not set to "Discourage search engines" or your sitemap will be disregarded.

## Schema Markup

**Setting:** `seo_schema`
**Default:** On
**Location:** SEO tab → Structured Data section

When enabled, Jetonomy outputs JSON-LD structured data on community pages. This helps search engines understand your content and can improve how your pages appear in search results.

| Page Type | Schema Type |
|---|---|
| Community home | `WebSite` + `BreadcrumbList` |
| Space | `DiscussionForumPosting` (as container) |
| Single post | `DiscussionForumPosting` |
| Post with replies | `DiscussionForumPosting` + `Comment` |
| User profile | `Person` |

The `DiscussionForumPosting` type is the W3C-recognized schema for forum content. Google uses it to display rich results for Q&A content in particular.

## Meta Title Patterns

**Settings:** `seo_post_title`, `seo_space_title`
**Location:** SEO tab → Title Patterns section

These patterns control the `<title>` tag on Jetonomy pages. Use the available tokens to build the pattern you want.

**Available tokens:**

| Token | Output |
|---|---|
| `{post_title}` | The post title |
| `{space_name}` | The space name |
| `{site_name}` | Your WordPress site name |
| `{page_number}` | Current page number (reply pagination) |

**Default post title pattern:** `{post_title} - {space_name} | {site_name}`

**Default space title pattern:** `{space_name} | {site_name}`

> **Tip:** Keep titles under 60 characters to avoid truncation in search results. The `{site_name}` suffix is good for brand recognition but costs characters. For long space names, consider omitting `{site_name}`.

## Noindex Controls

**Settings:** `seo_noindex_profiles`, `seo_noindex_search`
**Default:** Off for profiles, On for search
**Location:** SEO tab → Robots section

**Noindex user profiles** - When enabled, Jetonomy adds `<meta name="robots" content="noindex">` to all `/community/u/*/` pages. Enable this if you prefer profiles not to appear in search results (common for privacy-sensitive communities).

**Noindex search results** - When enabled, the `/community/search/` page is excluded from indexing. This is on by default because search results pages provide minimal SEO value and can create duplicate-content signals.

## Open Graph and Twitter Card Tags

Jetonomy outputs Open Graph and Twitter Card meta tags automatically on all public community pages. No setting is needed. These tags control how your posts appear when shared on social media:

- `og:title` - the post or space title
- `og:description` - the post excerpt (first 160 characters of body text)
- `og:type` - `article` for posts, `website` for other pages
- `og:url` - the canonical URL
- `twitter:card` - `summary` (or `summary_large_image` if a post has an image attachment)

> **Note:** If you use an SEO plugin like Yoast SEO, RankMath, or The SEO Framework, its OG tags may override Jetonomy's. This is fine - SEO plugin output takes priority via standard WordPress `wp_head` hook priority ordering.

## What's Next?

Set up anti-spam protection to keep your community clean without frustrating legitimate members.

[Anti-Spam Settings →](06-anti-spam.md)
