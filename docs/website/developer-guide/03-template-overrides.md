Jetonomy's template system is designed to be overridden without touching plugin files. Place your custom templates in your theme and they will be loaded automatically - no hooks, no filters required for simple overrides.

---

## How It Works

When Jetonomy loads a template, `Template_Loader` checks your active theme directory first. If a matching file exists there, it loads that file instead of the plugin's copy. This means your overrides survive plugin updates.

**Resolution order:**

1. `{your-theme}/jetonomy/{relative-path}` - checked first
2. `{jetonomy-plugin}/templates/{relative-path}` - fallback default

The `{relative-path}` is always the same path the plugin uses internally - for example, `views/home.php` or `partials/reply-card.php`.

---

## Directory Structure

Create the following folder structure in your active theme:

```
your-theme/
└── jetonomy/
    ├── views/
    │   ├── home.php
    │   ├── category.php
    │   ├── space.php
    │   ├── space-members.php
    │   ├── space-roadmap.php
    │   ├── single-post.php
    │   ├── new-post.php
    │   ├── user-profile.php
    │   ├── edit-profile.php
    │   ├── leaderboard.php
    │   ├── notifications.php
    │   ├── moderation.php
    │   ├── search.php
    │   ├── tag.php
    │   └── invite.php
    └── partials/
        ├── avatar.php
        ├── breadcrumb.php
        ├── composer.php
        ├── header.php
        ├── pagination.php
        ├── post-card.php
        ├── reply-card.php
        └── sidebar.php
```

You do not need to copy all of these. Only create the files you want to customize - any file not present in your theme directory is loaded from the plugin.

---

## Available Templates

### Views (full-page templates)

| File | Route | URL Pattern |
|------|-------|-------------|
| `views/home.php` | `home` | `/community/` |
| `views/category.php` | `category` | `/community/category/{slug}/` |
| `views/space.php` | `space` | `/community/s/{slug}/` |
| `views/space-members.php` | `space-members` | `/community/s/{slug}/members/` |
| `views/space-roadmap.php` | `space-roadmap` | `/community/s/{slug}/roadmap/` |
| `views/space-edit.php` | `edit-space` | `/community/s/{slug}/edit/` |
| `views/space-moderation.php` | `space-moderation` | `/community/s/{slug}/mod/` |
| `views/single-post.php` | `post` | `/community/s/{slug}/t/{slug}/` |
| `views/new-post.php` | `new-post` | `/community/s/{slug}/new/` |
| `views/new-space.php` | `new-space` | `/community/new-space/` |
| `views/my-spaces.php` | `my-spaces` | `/community/my-spaces/` |
| `views/drafts.php` | `drafts` | `/community/drafts/` |
| `views/bookmarks.php` | `bookmarks` | `/community/bookmarks/` |
| `views/user-profile.php` | `profile` | `/community/u/{login}/` |
| `views/edit-profile.php` | `edit-profile` | `/community/u/{login}/edit/` |
| `views/leaderboard.php` | `leaderboard` | `/community/leaderboard/` |
| `views/notifications.php` | `notifications` | `/community/notifications/` |
| `views/moderation.php` | `moderation` | `/community/mod/` |
| `views/search.php` | `search` | `/community/search/` |
| `views/tag.php` | `tag` | `/community/tag/{slug}/` |
| `views/invite.php` | `invite` | `/community/invite/{code}/` |

### Partials (reusable template fragments)

| File | Rendered via |
|------|-------------|
| `partials/header.php` | Loaded at the top of every community page |
| `partials/sidebar.php` | Loaded in space and home views |
| `partials/breadcrumb.php` | Loaded in space, category, and post views |
| `partials/post-card.php` | Iterated over in space and home views |
| `partials/reply-card.php` | Iterated over in single-post view |
| `partials/pagination.php` | Loaded at the bottom of listing pages |
| `partials/avatar.php` | Used inside post-card, reply-card, and profile views |
| `partials/composer.php` | Rich-text composer used in new-post and reply forms |

---

## Step-by-Step: Overriding the Post Card

This example adds a "Sponsored" badge to posts from a specific space.

### Step 1: Copy the original template

Copy `wp-content/plugins/jetonomy/templates/partials/post-card.php` to:

```
your-theme/jetonomy/partials/post-card.php
```

### Step 2: Modify your copy

Open `your-theme/jetonomy/partials/post-card.php` and add your customization. The variable `$post` (a `\Jetonomy\Models\Post` object) is available inside the partial.

```php
<?php
// your-theme/jetonomy/partials/post-card.php

// The $post variable is passed via Template_Loader::partial()
$is_sponsored = ( (int) $post->space_id === MY_SPONSORED_SPACE_ID );
?>

<div class="jt-row <?php echo $is_sponsored ? 'jt-row--sponsored' : ''; ?>">
    <?php if ( $is_sponsored ) : ?>
        <span class="my-sponsored-badge">Sponsored</span>
    <?php endif; ?>

    <?php
    // Continue with the original template output.
    // Tip: call Template_Loader::partial() for nested partials so they also
    // respect theme overrides.
    \Jetonomy\Template_Loader::partial( 'avatar', [ 'user_id' => $post->author_id ] );
    ?>

    <div class="jt-row__body">
        <a href="<?php echo esc_url( \Jetonomy\post_url( $post ) ); ?>">
            <?php echo esc_html( $post->title ); ?>
        </a>
    </div>
</div>
```

### Step 3: Add styles

Add CSS to your theme for the new `.jt-row--sponsored` class. Reference Jetonomy's CSS tokens so your styles adapt to dark mode automatically:

```css
/* your-theme/style.css or an enqueued stylesheet */

.jt-row--sponsored {
    border-left: 3px solid var(--jt-accent);
}

.my-sponsored-badge {
    display: inline-block;
    font-size: 0.75rem;
    color: var(--jt-text-secondary);
    background: var(--jt-accent-light);
    border-radius: var(--jt-radius-sm);
    padding: 2px 6px;
}
```

---

## Calling Partials from Templates

Inside any view or partial, use `Template_Loader::partial()` instead of a raw `include`. This ensures the theme-override check runs for nested partials too:

```php
// Inside your custom view or partial:
\Jetonomy\Template_Loader::partial( 'pagination', [
    'total'    => $total,
    'per_page' => 20,
    'page'     => $current_page,
] );
```

The second argument is passed as local variables to the partial (via `extract()`).

---

## Available Variables in Templates

Templates receive route data via `$data` (array with `route` and `slug` keys). Most views also query the database directly at the top of the file. When you copy a template, review the top of the plugin's original file to understand which variables are set before the HTML output begins.

Common objects available in plugin templates:

| Variable | Type | Available in |
|----------|------|-------------|
| `$data` | `array` | All views |
| `$space` | `\Jetonomy\Models\Space` | space, space-members, new-post |
| `$post` | `\Jetonomy\Models\Post` | single-post, post-card partial |
| `$reply` | `\Jetonomy\Models\Reply` | reply-card partial |
| `$user` | `WP_User` | user-profile, edit-profile |
| `$posts` | `array` | home, space, search, tag |
| `$settings` | `array` | All views (from `get_option('jetonomy_settings')`) |

---

## The `jetonomy_template_map` Filter

For cases where you need to register a completely new route - or override the template for an existing route with a file stored outside the theme - use the `jetonomy_template_map` filter.

```php
add_filter( 'jetonomy_template_map', function( array $map ): array {
    // Override an existing route with an absolute path.
    $map['leaderboard'] = get_stylesheet_directory() . '/jetonomy/views/leaderboard.php';

    // Register a brand-new route (accessible at /community/events/).
    $map['events'] = MY_PLUGIN_DIR . 'templates/community-events.php';

    return $map;
} );
```

**Important:** If you provide an absolute path (starting with `/`), the theme-override check is bypassed - the file you point to is loaded directly. This is the correct approach for Pro extensions and companion plugins that ship their own templates.

The Jetonomy Router must know about new routes before they can receive traffic. Register rewrite rules alongside the template map:

```php
// Register a custom rewrite rule for /community/events/.
add_action( 'init', function() {
    $settings  = get_option( 'jetonomy_settings', [] );
    $base_slug = $settings['base_slug'] ?? 'community';

    add_rewrite_rule(
        '^' . preg_quote( $base_slug, '^' ) . '/events/?$',
        'index.php?jetonomy_route=events',
        'top'
    );
} );

// Teach WordPress to recognize the query var.
add_filter( 'query_vars', function( array $vars ): array {
    $vars[] = 'jetonomy_route';
    return $vars;
} );
```

After adding rewrite rules, flush permalinks once: go to **Settings → Permalinks** and click **Save Changes**, or run:

```bash
wp --path="/path/to/wordpress" rewrite flush
```

---

## Child Theme Compatibility

If you are using a child theme, place overrides in the child theme directory - `get_stylesheet_directory()` resolves to the child theme path when a child theme is active. The plugin does not check the parent theme separately, so all overrides must live in the active (child) theme.

---

## Pro Template Overrides

Jetonomy Pro registers its own templates for Private Messaging via the `jetonomy_template_map` filter using absolute paths. You can override Pro templates in your theme using the same directory structure:

```
your-theme/
└── jetonomy/
    └── views/
        ├── messages.php      # /community/messages/
        └── conversation.php  # /community/messages/{id}/
```

Place overrides here and they will be detected automatically because Pro's `Template_Loader::partial()` calls still respect the theme directory check for non-absolute paths.

---

## What's Next?

- [Hooks Reference](./02-hooks-reference.md) - Inject content at specific points without overriding full templates
- [REST API Reference](./01-rest-api.md) - Fetch data to power your custom templates
- [Shortcodes, Widgets & Blocks](./04-shortcodes-widgets-blocks.md) - Embed community content on non-community pages
