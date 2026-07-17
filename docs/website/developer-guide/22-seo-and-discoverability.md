# SEO & Discoverability

When Jetonomy is active it adds SEO metadata to every community page automatically: JSON-LD structured data, Open Graph and Twitter cards, canonical URLs, an XML sitemap, and per-space RSS feeds. No separate SEO plugin is needed for the `/community/*` routes. This page lists what is emitted, where it comes from in the code, and how to change it.

> Everything here is in the free plugin unless marked **Pro**. Jetonomy renders its own `/community/*` routes from custom tables (there is no WP post object), so general SEO plugins leave these URLs alone - Jetonomy owns their meta and schema.

## Structured data (JSON-LD)

On every community page Jetonomy prints a `<script type="application/ld+json">` block in `wp_head`, matched to the page type. Source: `includes/seo/class-schema-markup.php`.

| URL | Schema `@type` | Rich-result intent |
|---|---|---|
| `/community/` (home) | `WebSite` + `SearchAction` | Sitelinks search box |
| `/community/s/{slug}/` (space) | `CollectionPage` + `ItemList` | Itemised topic list |
| `/community/s/{slug}/t/{slug}/` (Q&A topic with an accepted answer) | `QAPage` (`Question` + `Answer` + `Person`) | Q&A rich result |
| `/community/s/{slug}/t/{slug}/` (discussion topic) | `DiscussionForumPosting` + `InteractionCounter` | Forum-post rich result with vote + reply counts |
| `/community/tag/{slug}/` | `CollectionPage` + `ItemList` | Itemised tag list |
| `/community/u/{login}/` | `Person` | Author/profile |
| breadcrumbed pages | `BreadcrumbList` | Breadcrumb trail |

`QAPage` and `DiscussionForumPosting` are the two schemas Google specifically documents for forum content, and the `InteractionCounter` nodes feed the up-vote and answer counts straight from your tables. Validate any page with Google's [Rich Results Test](https://search.google.com/test/rich-results).

## Document titles

Titles are set through WordPress's `document_title_parts` filter, so they work with any theme. Two admin settings let owners shape them without code:

| Setting (under **Jetonomy → Settings → SEO**) | Applies to | Placeholders |
|---|---|---|
| `seo_post_title` | single topic pages | `{post_title}`, `{site_name}` |
| `seo_space_title` | space pages | `{space_name}`, `{site_name}` |

Leave a pattern empty to use Jetonomy's sensible default. `{site_name}` is expanded once - the WordPress separator already appends the site name, so you won't get it twice.

## Open Graph & Twitter cards

Every community route emits `og:title`, `og:description`, `og:url`, `og:image` (+ `og:image:alt`), `og:type`, and a `twitter:card`. The image uses a fallback chain so a shared link still carries an image when a page has none of its own:

```
per-page image  →  seo_default_og_image setting  →  theme custom logo  →  site icon
```

Set a site-wide default under **Jetonomy → Settings → SEO → Default OG image**. Descriptions are clipped to 160 characters at emit time.

## Canonical URLs

Each page emits a single `<link rel="canonical">` pointing at its clean URL, so sort/filter query parameters (e.g. `?sort=top`) don't fracture ranking signals across duplicates.

## robots / noindex

Thin or duplicative surfaces (such as paginated tails) emit `<meta name="robots" content="noindex, follow">` automatically - the links are still crawled, the page just isn't indexed. You can force or clear this per route with the filter below.

## XML sitemap

Jetonomy registers two providers on WordPress core's sitemap (`wp-sitemap.xml`), so spaces and topics are submitted to search engines with no extra plugin (`includes/seo/class-sitemap.php`):

- `jetonomyspaces` - every public space
- `jetonomyposts` - every public topic

Visit `https://yoursite.com/wp-sitemap.xml` and you'll see the `jetonomyspaces` and `jetonomyposts` sub-sitemaps listed.

## RSS feeds

Every space publishes an RSS 2.0 feed at:

```
/community/s/{slug}/feed/
```

The space page also includes an `<link rel="alternate" type="application/rss+xml">` tag, so browsers and feed readers auto-discover it. Good for syndication, email-digest tools, Zapier/Make automations, and letting members follow a space without an account.

## Customising any of it - the `jetonomy_seo_meta` filter

One filter sits in front of every meta tag Jetonomy emits. Return a modified payload to change titles, descriptions, canonical, OG image, card type, or `noindex` per route.

```php
add_filter( 'jetonomy_seo_meta', function ( array $meta, array $data ) {
    // $data['route'] is 'home' | 'space' | 'post' | 'tag' | 'profile' | ...
    // $data['slug']  is the current space/topic/tag/user slug.

    // Example: brand every share image on the leaderboard, and noindex search.
    if ( 'search' === $data['route'] ) {
        $meta['noindex'] = true;
    }
    return $meta;
}, 10, 2 );
```

The payload you receive and return:

| Key | Type | Controls |
|---|---|---|
| `title` | string | OG/Twitter title |
| `desc` | string | meta description (clipped to 160 chars on output) |
| `url` | string | canonical + `og:url` |
| `image` | string | `og:image` URL |
| `image_alt` | string | `og:image:alt` |
| `og_type` | string | `og:type` (default `website`) |
| `twitter_card` | string | `twitter:card` (default `summary`) |
| `noindex` | bool | emit `robots noindex, follow` |
| `article_meta` | array | `article:*` meta, keyed by property |

**Running a general SEO plugin (Yoast, Rank Math)?** They no longer collide with Jetonomy's routes. Every Jetonomy URL is virtual - the rewrite sets a `jetonomy_route` query var that `WP_Query` doesn't recognise, so core used to fall back to `is_home = true`. On a site with a static front page that made Yoast publish the *Posts page's* title and a canonical pointing at it on every Space and topic. Jetonomy now corrects the main query on `parse_query` - `Router::correct_query_state()` clears `is_home`, `is_front_page`, `is_singular`, `is_404`, and the queried object on any `jetonomy_route` request (`includes/class-router.php:106`) - so nothing downstream (core's title, themes, breadcrumbs, or an SEO plugin) reads a stale page's data. The fix is vendor-neutral: it corrects the query state rather than fighting any one plugin, so Yoast, Rank Math, AIOSEO, SEOPress and core all resolve correctly with no SEO-plugin code. Jetonomy then sets its own title, canonical, and OG for that route in `wp_head`. If you'd rather your SEO plugin own a route, return an empty `title`/`desc` from this filter for that route to suppress Jetonomy's tags.

> On a **mapped front page** (the community rendered over a real WP page), Jetonomy emits nothing and leaves SEO to the page's own object and your SEO plugin - the query state there is already correct because a real page backs the URL.

## SEO Pro (Pro)

The **SEO Pro** extension adds per-space control on top of the free baseline (`jetonomy-pro`, extension `seo-pro`):

- Per-space **meta title**, **meta description**, and **OG image**
- Per-space **`noindex` / `nofollow`** and **canonical base**
- Per-space **sitemap priority** and **exclude-from-sitemap**
- A `robots_txt` filter and `wp_sitemaps_posts_query_args` integration
- A REST surface (`GET`/`POST` per-space SEO) so the controls are editable from the space admin UI

When SEO Pro is active it takes over the topic (`post`) route's baseline meta to avoid duplicate tags; free continues to own every other route.

## Verify it yourself

```bash
# Structured data + meta tags on a topic page
curl -s 'https://yoursite.com/community/s/general/t/welcome/' | grep -E 'og:|twitter:|canonical|ld\+json|robots'

# Sitemap providers
curl -s 'https://yoursite.com/wp-sitemap.xml' | grep jetonomy

# A space feed
curl -s 'https://yoursite.com/community/s/general/feed/' | head -20
```

## Related

- [Hooks Reference](02-hooks-reference.md) - `jetonomy_seo_meta` and every other filter
- [Template overrides](03-template-overrides.md) - change the HTML around the content
- [Theming & tokens](16-theming-and-tokens.md) - match your brand
