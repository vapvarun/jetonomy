# Shortcodes, Widgets & Blocks

> **Important note for developers:** The manifest scan performed during plugin audit (2026-03-24) found that Jetonomy 1.0.0 does **not** register shortcodes, classic widgets, or Gutenberg blocks in the current release. The plugin routes community pages through its own URL rewrite system and uses the **WP Interactivity API** for dynamic frontend behavior rather than registered block types.
>
> This document covers: (1) the planned shortcode/widget/block API surface for v1.1, and (2) the current mechanisms for embedding community content on non-community pages.

---

## Current: Embed Community Content

### Using Direct URLs

The most reliable way to link to or embed community sections is with direct URLs from the community URL structure:

| Page | URL Pattern |
|------|-------------|
| Community home | `/community/` |
| Space listing | `/community/s/{space-slug}/` |
| Single post | `/community/s/{space-slug}/t/{post-slug}/` |
| User profile | `/community/u/{login}/` |
| Leaderboard | `/community/leaderboard/` |
| Search | `/community/search/?q={query}` |
| Tag view | `/community/tag/{slug}/` |

The `community` segment is configurable — check `get_option('jetonomy_settings')['base_slug']` for the active value.

### Using the REST API in Custom Templates

You can embed community data (recent posts, leaderboard, active spaces) in any WordPress page by querying the REST API from a page template, widget, or Gutenberg pattern.

**Example: Recent posts in a classic page template (server-side)**

```php
<?php
// In a page template or functions.php — runs server-side.
$response = wp_remote_get( rest_url( 'jetonomy/v1/spaces/1/posts?per_page=5&sort=latest' ) );

if ( ! is_wp_error( $response ) ) {
    $posts = json_decode( wp_remote_retrieve_body( $response ), true )['data'] ?? [];
    foreach ( $posts as $post ) {
        printf(
            '<li><a href="%s">%s</a></li>',
            esc_url( \Jetonomy\post_url( $post['id'] ) ),
            esc_html( $post['title'] )
        );
    }
}
```

**Example: Leaderboard via REST API (client-side fetch)**

```javascript
// Note: Always sanitize server-supplied HTML. Use textContent for plain text values.
fetch('/wp-json/jetonomy/v1/leaderboards?per_page=5&period=month')
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('jt-leaderboard-embed');
        data.data.forEach(u => {
            const row = document.createElement('div');
            const name = document.createElement('span');
            const pts  = document.createElement('span');
            name.textContent = u.display_name;
            pts.textContent  = u.reputation + ' pts';
            row.append(name, ' — ', pts);
            el.appendChild(row);
        });
    });
```

---

## Planned Shortcodes (v1.1)

The following shortcode API is planned for Jetonomy 1.1. These are **not available in 1.0.0** — this section documents the intended API for plugin developers building against the upcoming release.

### `[jetonomy_recent_posts]`

Renders a list of the most recent posts across all spaces or within a specific space.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `count` | int | `5` | Number of posts to display (max 50) |
| `space_id` | int | `0` | Restrict to a space. `0` = all spaces |
| `sort` | string | `latest` | `latest`, `votes`, `replies` |
| `show_excerpt` | bool | `true` | Show content excerpt |
| `show_meta` | bool | `true` | Show author and reply count |

```
[jetonomy_recent_posts count="5" space_id="3" sort="votes"]
```

### `[jetonomy_spaces]`

Renders a grid or list of community spaces.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `count` | int | `10` | Number of spaces to display |
| `category_id` | int | `0` | Filter by category. `0` = all |
| `orderby` | string | `position` | `position`, `member_count`, `post_count` |
| `layout` | string | `list` | `list` or `grid` |

```
[jetonomy_spaces count="10" layout="grid"]
```

### `[jetonomy_leaderboard]`

Renders the top community contributors for a given time period.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `count` | int | `10` | Number of users to display |
| `period` | string | `month` | `week`, `month`, `all-time` |
| `space_id` | int | `0` | Restrict to a space. `0` = site-wide |
| `show_avatar` | bool | `true` | Display user avatar |

```
[jetonomy_leaderboard count="10" period="all-time"]
```

### `[jetonomy_user_profile]`

Renders a compact profile card for a specific user or the currently logged-in user.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `user_id` | int | `0` | Target user. `0` = current logged-in user |
| `show_stats` | bool | `true` | Show post count, reply count, reputation |
| `show_badges` | bool | `true` | Show trust level and earned badges |

```
[jetonomy_user_profile user_id="0"]
```

### `[jetonomy_space_members]`

Renders a list of members for a specific space.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `space_id` | int | — | **Required.** ID of the space |
| `count` | int | `20` | Number of members to display |
| `show_role` | bool | `false` | Show member role badge (member/moderator/admin) |

```
[jetonomy_space_members space_id="5" count="20"]
```

---

## Planned Widgets (v1.1)

Classic widget support is planned for v1.1 for sites using classic themes. Widgets will use the same underlying REST API queries as shortcodes.

### Recent Posts Widget

Displays recent posts in any classic widget area.

**Settings:** Title, Count, Space (optional filter), Sort order

### Leaderboard Widget

Displays top contributors for a period.

**Settings:** Title, Count, Period (week/month/all-time)

### Active Spaces Widget

Displays the most active spaces by recent post count.

**Settings:** Title, Count

### User Stats Widget

Displays the currently logged-in user's stats: reputation, post count, reply count, trust level.

**Settings:** Title (no other configuration — always reflects the current user)

---

## Planned Blocks (v1.1)

Gutenberg block support is planned for v1.1. All blocks will use **server-side rendering** (`render_callback`) so they reflect live data without client-side JavaScript at render time.

### `jetonomy/forum-feed`

Renders a live post feed from a selected space or all spaces.

**Block attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `spaceId` | number | `0` | Space ID (0 = all spaces) |
| `count` | number | `5` | Posts to show |
| `sortBy` | string | `latest` | `latest`, `votes`, `replies` |
| `showExcerpt` | boolean | `true` | Show post excerpt |

The block will use a `render_callback` that calls the Jetonomy data layer directly (no REST round-trip):

```php
// Example of the planned render_callback pattern.
register_block_type( 'jetonomy/forum-feed', [
    'render_callback' => function( array $attrs ): string {
        $space_id = absint( $attrs['spaceId'] ?? 0 );
        $count    = min( absint( $attrs['count'] ?? 5 ), 50 );
        $sort     = sanitize_key( $attrs['sortBy'] ?? 'latest' );

        $posts = \Jetonomy\Models\Post::get_list( [
            'space_id' => $space_id ?: null,
            'per_page' => $count,
            'sort'     => $sort,
            'status'   => 'publish',
        ] );

        ob_start();
        foreach ( $posts as $post ) {
            \Jetonomy\Template_Loader::partial( 'post-card', [ 'post' => $post ] );
        }
        return ob_get_clean();
    },
    'attributes' => [
        'spaceId'     => [ 'type' => 'number', 'default' => 0 ],
        'count'       => [ 'type' => 'number', 'default' => 5 ],
        'sortBy'      => [ 'type' => 'string', 'default' => 'latest' ],
        'showExcerpt' => [ 'type' => 'boolean', 'default' => true ],
    ],
] );
```

### `jetonomy/space-list`

Renders a grid or list of spaces. Supports category filtering.

**Block attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `categoryId` | number | `0` | Filter by category (0 = all) |
| `count` | number | `10` | Spaces to show |
| `layout` | string | `list` | `list` or `grid` |

### `jetonomy/leaderboard`

Renders a leaderboard for a configurable time period.

**Block attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `month` | `week`, `month`, `all-time` |
| `count` | number | `10` | Contributors to show |
| `spaceId` | number | `0` | Restrict to a space |

---

## Building Companion Shortcodes or Blocks

If you are building a companion plugin and want to add shortcodes or blocks that use Jetonomy data, the REST API and model classes are the recommended integration points.

**Via model classes (server-rendered shortcodes):**

```php
// Requires Jetonomy to be active. Guard with class_exists() first.
add_shortcode( 'my_community_posts', function( array $atts ): string {
    if ( ! class_exists( '\Jetonomy\Models\Post' ) ) {
        return '';
    }

    $atts  = shortcode_atts( [ 'count' => 5, 'space_id' => 0 ], $atts );
    $posts = \Jetonomy\Models\Post::get_list( [
        'per_page' => min( absint( $atts['count'] ), 50 ),
        'space_id' => absint( $atts['space_id'] ) ?: null,
        'sort'     => 'latest',
        'status'   => 'publish',
    ] );

    $output = '<ul class="my-recent-posts">';
    foreach ( $posts as $post ) {
        $output .= sprintf(
            '<li><a href="%s">%s</a></li>',
            esc_url( \Jetonomy\post_url( $post->id ) ),
            esc_html( $post->title )
        );
    }
    $output .= '</ul>';

    return $output;
} );
```

Always check that Jetonomy is active before calling its classes:

```php
if ( ! defined( 'JETONOMY_VERSION' ) ) {
    return;
}
```

---

## What's Next?

- [REST API Reference](./01-rest-api.md) — Fetch community data from any context
- [Template Overrides](./03-template-overrides.md) — Customize community page layouts
- [Adapter System](./05-adapters.md) — Extend search, email, and real-time integrations
