Jetonomy includes five shortcodes, four classic widgets, and five Gutenberg blocks so you can embed community content anywhere on your WordPress site — sidebars, pages, posts, or block-based layouts.

## What You Will Learn

- How to use the five built-in shortcodes and their attributes
- How to add the four classic widgets to sidebar areas
- How to insert the five Gutenberg blocks in the block editor
- How shortcodes and blocks share the same rendering logic

## Shortcodes

All shortcodes are registered by `Jetonomy\Shortcodes::register()` and are available on any page or post.

---

### `[jetonomy_recent_posts]`

Displays a list of the most recent published posts across your community or within a specific space.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `count` | int | `5` | Number of posts to display |
| `space_id` | int | `0` | Restrict to a space. `0` = all spaces |
| `sort` | string | `latest` | `latest` or `votes` |

```
[jetonomy_recent_posts count="5" space_id="3" sort="votes"]
```

Each post card shows the title, author name, space name, time ago, vote score, and reply count.

---

### `[jetonomy_spaces]`

Displays a list of public, active spaces, ordered by post count.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `count` | int | `6` | Number of spaces to display |
| `category_id` | int | `0` | Filter by category. `0` = all categories |

```
[jetonomy_spaces count="6" category_id="2"]
```

Each space card shows the title, a short description excerpt, and the post count.

---

### `[jetonomy_leaderboard]`

Displays a ranked list of the top community members by reputation score.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `count` | int | `10` | Number of members to display |

```
[jetonomy_leaderboard count="10"]
```

---

### `[jetonomy_user_profile]`

Displays a compact profile card for a specific user or the currently logged-in user.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `user_id` | int | `0` | Target user. `0` = current logged-in user |

```
[jetonomy_user_profile user_id="0"]
```

The card shows the display name, trust level badge, bio excerpt, reputation score, and post count.

---

### `[jetonomy_space_members]`

Displays a list of members for a specific space, ordered by reputation.

**Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `space_id` | int | — | **Required.** ID of the space |
| `count` | int | `10` | Number of members to display |

```
[jetonomy_space_members space_id="5" count="20"]
```

---

## Classic Widgets

Jetonomy registers four classic widgets for use in any theme widget area. Each widget is configured through the standard WordPress widget admin screen or the Customizer.

### Recent Posts Widget

Displays recent forum posts in any sidebar or widget area.

**Settings:** Title, Count, Space (optional filter), Sort order

### Leaderboard Widget

Displays top community contributors ranked by reputation.

**Settings:** Title, Count

### Active Spaces Widget

Displays the most active spaces by post count.

**Settings:** Title, Count

### User Stats Widget

Displays the currently logged-in user's stats: reputation, post count, reply count, and trust level.

**Settings:** Title (no other configuration — always reflects the current user)

---

## Gutenberg Blocks

Jetonomy registers five server-side rendered blocks. All blocks use `render_callback` functions that call the same shortcode logic internally (for the three list blocks) or dedicated rendering components (for Navigation and Login), so output is consistent wherever they appear.

### `jetonomy/forum-feed`

Renders a live post feed from a selected space or all spaces.

**Block Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `count` | number | `5` | Posts to show |
| `spaceId` | number | `0` | Space ID (0 = all spaces) |
| `sort` | string | `latest` | `latest` or `votes` |

### `jetonomy/space-list`

Renders a list of community spaces. Supports category filtering.

**Block Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `count` | number | `6` | Spaces to show |
| `categoryId` | number | `0` | Filter by category (0 = all) |

### `jetonomy/leaderboard`

Renders a leaderboard of top community members by reputation.

**Block Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `count` | number | `10` | Members to show |

---

### `jetonomy/navigation` *(1.3.5+)*

Renders the Category → Space tree as permission-aware sidebar navigation. Designed for the community sidebar of any block theme or widget area.

**Why this block exists**

Most community themes render the space list with a hand-maintained nav menu. That list rots the moment you add a space, and it leaks private spaces to anonymous viewers. This block queries the live category/space tree on every render and honors Jetonomy's permission layer, so private spaces stay hidden from viewers who do not have access.

**Block Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `showCategories` | boolean | `true` | Group spaces by parent category |
| `showCounts` | boolean | `true` | Show topic count next to each space |
| `highlightCurrent` | boolean | `true` | Add `is-current` class to the current space |
| `maxDepth` | number | `2` | How deep to render sub-spaces. Max 3. |

Scales to sites with thousands of spaces — the rendered tree uses Jetonomy's cached category/space index, not a per-request DB scan.

---

### `jetonomy/login` *(1.3.5+)*

Renders an inline login and register panel for the community sidebar. Logged-out viewers see Login and Register tabs without leaving the page. Logged-in viewers get nothing rendered — no layout shift when state changes.

**Block Attributes**

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `showRegister` | boolean | `true` | Show the Register tab alongside Login |
| `redirectTo` | string | current URL | Where to send the user after successful login |
| `labelLogin` | string | `Log in` | Tab label for the Login form |
| `labelRegister` | string | `Register` | Tab label for the Register form |

**Security**

- Both forms are nonce-protected
- Failed login attempts are rate-limited via `Jetonomy\Security\Rate_Limiter` (5 attempts / 15 minutes per IP)
- Registration respects whatever `users_can_register` is set to in your WP admin and any anti-spam adapter you have active (Akismet, AI spam detection)

---

## CSS Classes for Styling

All shortcode and block output uses the `jt-shortcode` CSS class prefix so you can style them in your theme without affecting core community pages:

| Class | Element |
|-------|---------|
| `.jt-shortcode` | Wrapper on all shortcode output |
| `.jt-shortcode-recent-posts` | Recent posts container |
| `.jt-shortcode-post` | Individual post card |
| `.jt-shortcode-post-title` | Post title link |
| `.jt-shortcode-post-meta` | Author, space, and time line |
| `.jt-shortcode-post-stats` | Vote and reply counts |
| `.jt-shortcode-spaces` | Spaces container |
| `.jt-shortcode-space` | Individual space card |
| `.jt-shortcode-leaderboard` | Leaderboard container |
| `.jt-shortcode-profile-card` | User profile card |
| `.jt-shortcode-members` | Members list container |
| `.jt-shortcode-empty` | Empty state message |

---

## Building Companion Shortcodes or Blocks

If you are building a companion plugin that needs to query Jetonomy data, guard your code with a class existence check:

```php
if ( ! defined( 'JETONOMY_VERSION' ) ) {
    return;
}
```

Use the model classes for server-side rendering or the REST API for client-side fetches. See the [REST API Reference](./01-rest-api.md) for available endpoints.

---

## What's Next?

- [REST API Reference](./01-rest-api.md) — Fetch community data from any context
- [Template Overrides](./03-template-overrides.md) — Customize community page layouts
- [Adapter System](./05-adapters.md) — Extend search, email, and real-time integrations
