Inject badges, buttons, counts, and metadata into post cards, reply cards, space cards, and member rows using purpose-built hooks - no template override required for most use cases.

---

## Post Cards

Post cards appear in the space topic listing, the home grid, and in search results. The single-post header reuses the same `jetonomy_post_card_after_badges` hook so a badge added here appears consistently in both surfaces.

### `jetonomy_post_card_after_badges`

Fires right after the built-in status badges (Sticky, Private, Pinned) inside the card. Use this to append your own badge or marker. This hook fires in both the listing card and the single-post header.

```
do_action( 'jetonomy_post_card_after_badges', $post, $space )
```

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post` | `object` | The post row being rendered. |
| `$space` | `object\|null` | The post's space, if loaded. |

**Source:** `templates/partials/post-card.php`, `templates/views/single-post.php`

**Example: Featured badge**

```php
add_action( 'jetonomy_post_card_after_badges', function( object $post, $space ) {
    if ( ! get_post_meta( $post->id, '_my_featured', true ) ) {
        return;
    }
    echo '<span class="my-badge my-badge--featured">' . esc_html__( 'Featured', 'my-plugin' ) . '</span>';
}, 10, 2 );
```

**Example: Space-type label (only on the home grid where $space may vary)**

```php
add_action( 'jetonomy_post_card_after_badges', function( object $post, $space ) {
    if ( ! $space || 'qa' !== ( $space->type ?? '' ) ) {
        return;
    }
    echo '<span class="my-badge my-badge--qa">' . esc_html__( 'Q&A', 'my-plugin' ) . '</span>';
}, 10, 2 );
```

---

### `jetonomy_post_actions`

Fires inside the single-post action toolbar, alongside the built-in actions (vote, bookmark, share). Use this to inject a custom action button visible on a single post view.

```
do_action( 'jetonomy_post_actions', $post )
```

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post` | `object` | The current post being viewed. |

**Source:** `templates/views/single-post.php`

**Example: "Translate" button**

```php
add_action( 'jetonomy_post_actions', function( object $post ) {
    if ( ! is_user_logged_in() ) {
        return;
    }
    $nonce = wp_create_nonce( 'my_translate_' . $post->id );
    printf(
        '<button class="jt-action-btn my-translate-btn" data-post-id="%d" data-nonce="%s" aria-label="%s">%s</button>',
        (int) $post->id,
        esc_attr( $nonce ),
        esc_attr__( 'Translate this post', 'my-plugin' ),
        esc_html__( 'Translate', 'my-plugin' )
    );
} );
```

---

### `jetonomy_after_post_content` (filter)

Filters the HTML rendered directly after the post body in the single-post view. Return a non-empty string to inject content between the post body and the action row.

```
apply_filters( 'jetonomy_after_post_content', $html, $post )
```

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$html` | `string` | HTML to render after the post content (empty by default). |
| `$post` | `\Jetonomy\Models\Post` | The current post object. |

**Return:** `string`

**Source:** `templates/views/single-post.php`

**Example: Related posts block**

```php
add_filter( 'jetonomy_after_post_content', function( string $html, $post ): string {
    $related = my_plugin_get_related( (int) $post->id );
    if ( empty( $related ) ) {
        return $html;
    }

    ob_start();
    echo '<div class="my-related-posts">';
    echo '<strong>' . esc_html__( 'Related discussions', 'my-plugin' ) . '</strong>';
    echo '<ul>';
    foreach ( $related as $r ) {
        printf(
            '<li><a href="%s">%s</a></li>',
            esc_url( $r['url'] ),
            esc_html( $r['title'] )
        );
    }
    echo '</ul></div>';
    $html .= ob_get_clean();

    return $html;
}, 10, 2 );
```

---

### `jetonomy_after_post_article`

Fires after the main post `<article>` element and before the replies section. Ideal for ads, CTAs, or related-content blocks placed between the topic body and the reply list.

```
do_action( 'jetonomy_after_post_article', $post )
```

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post` | `object` | The current post object. |

**Source:** `templates/views/single-post.php`

**Example: Ad placement**

```php
add_action( 'jetonomy_after_post_article', function( object $post ) {
    echo do_shortcode( '[my_ad zone="community_post_bottom"]' );
} );
```

---

## Reply Cards

Reply cards appear in the single-post reply list. The hook fires for every top-level reply in both the "opening" and "latest" batches.

### `jetonomy_reply_actions`

Fires inside the reply card, within the reply action row, alongside the built-in reply actions (upvote, accept, flag).

```
do_action( 'jetonomy_reply_actions', $reply )
```

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply` | `object` | The reply being rendered. |

**Source:** `templates/partials/reply-card.php`

**Example: "Mark as helpful" button**

```php
add_action( 'jetonomy_reply_actions', function( object $reply ) {
    if ( ! is_user_logged_in() ) {
        return;
    }
    $count   = (int) get_user_meta( $reply->id, '_helpful_count', true );
    $nonce   = wp_create_nonce( 'my_helpful_' . $reply->id );
    printf(
        '<button class="jt-action-btn my-helpful-btn" data-reply-id="%d" data-nonce="%s" aria-pressed="false">%s <span class="my-helpful-count">%d</span></button>',
        (int) $reply->id,
        esc_attr( $nonce ),
        esc_html__( 'Helpful', 'my-plugin' ),
        $count
    );
} );
```

---

## Space Cards

Space cards appear on the community home grid (`/community/`) and the category page (`/community/category/{slug}/`). The hook fires outside the card's `<a>` wrapper, so interactive elements (buttons, forms) are valid HTML here.

### `jetonomy_space_card_after`

Fires after each space card, outside its `<a>` wrapper.

```
do_action( 'jetonomy_space_card_after', $space )
```

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$space` | `object` | The space being rendered. |

**Source:** `templates/views/home.php`, `templates/views/category.php`

**Example: "New" badge on recently created spaces**

```php
add_action( 'jetonomy_space_card_after', function( object $space ) {
    $created = strtotime( $space->created_at ?? '' );
    if ( ! $created || ( time() - $created ) > WEEK_IN_SECONDS ) {
        return;
    }
    echo '<span class="my-space-badge my-space-badge--new">' . esc_html__( 'New', 'my-plugin' ) . '</span>';
} );
```

**Example: Join button outside the card link**

Since this hook fires outside the `<a>` wrapper, you can safely render a button here without nesting interactive elements inside the link.

```php
add_action( 'jetonomy_space_card_after', function( object $space ) {
    if ( ! is_user_logged_in() ) {
        return;
    }
    // Only show the button if the user is not already a member.
    if ( \Jetonomy\Models\SpaceMember::is_member( get_current_user_id(), (int) $space->id ) ) {
        return;
    }
    printf(
        '<button class="my-space-join-btn" data-space-id="%d">%s</button>',
        (int) $space->id,
        esc_html__( 'Join', 'my-plugin' )
    );
} );
```

---

## Member Cards (Space Members List)

Member rows appear on the space members page (`/community/s/{slug}/members/`). The hook fires after each row's closing element, giving you a slot for per-member extras like badges, direct-message links, or moderator actions.

### `jetonomy_member_card_after`

Fires after each member row in the space members list.

```
do_action( 'jetonomy_member_card_after', $member, $space )
```

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$member` | `object` | The space membership row. Properties: `user_id` (int), `role` (string), `joined_at` (string datetime). |
| `$space` | `object` | The space being viewed. |

**Source:** `templates/views/space-members.php`

**Example: Badge count for each member**

```php
add_action( 'jetonomy_member_card_after', function( object $member, object $space ) {
    $count = my_badges_count_for_user( (int) $member->user_id );
    if ( $count < 1 ) {
        return;
    }
    printf(
        '<span class="my-member-badge-count" title="%s">%d %s</span>',
        esc_attr__( 'Badges earned', 'my-plugin' ),
        (int) $count,
        esc_html( _n( 'badge', 'badges', $count, 'my-plugin' ) )
    );
}, 10, 2 );
```

**Example: Message button (only for Pro users with messaging)**

```php
add_action( 'jetonomy_member_card_after', function( object $member, object $space ) {
    $current_user_id = get_current_user_id();
    // Do not show the button to guests or to the member themselves.
    if ( ! $current_user_id || (int) $member->user_id === $current_user_id ) {
        return;
    }
    $settings = get_option( 'jetonomy_settings', [] );
    $base     = $settings['base_slug'] ?? 'community';
    printf(
        '<a href="%s" class="jt-btn jt-btn--ghost jt-btn--sm my-dm-btn">%s</a>',
        esc_url( home_url( "/{$base}/messages/?to={$member->user_id}" ) ),
        esc_html__( 'Message', 'my-plugin' )
    );
}, 10, 2 );
```

---

## Styling Custom Injected Content

Use Jetonomy's CSS tokens so your injected elements adapt to the active theme, dark mode, and RTL layout automatically.

```css
/* your-plugin/assets/css/my-plugin.css */

/* Badge appended via jetonomy_post_card_after_badges */
.my-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: var(--jt-radius-sm);
    background: var(--jt-accent-light);
    color: var(--jt-accent);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.my-badge--featured {
    background: var(--jt-warn-light);
    color: var(--jt-warn);
}

/* Button appended via jetonomy_member_card_after */
.my-dm-btn {
    margin-inline-start: auto; /* RTL-safe push to the trailing edge */
}
```

Available tokens are listed in the plugin's `CLAUDE.md` CSS Token Rules section. The key categories are `--jt-accent-*`, `--jt-text-*`, `--jt-bg-*`, `--jt-radius-*`, and `--jt-border`. Never use hardcoded hex or `px` values in styles that ship alongside these hooks.

---

## Notes

- `jetonomy_post_card_after_badges` fires in both the listing card and the single-post header. Test both surfaces when you add a badge.
- `jetonomy_space_card_after` fires outside the `<a>` wrapper - this is intentional so buttons and forms are valid HTML. Don't wrap the emitted content in another `<a>`.
- `jetonomy_member_card_after` fires inside the members list grid, not inside any wrapper link, so interactive elements are valid.
- For heavier customizations that require restructuring the card layout, use [Template Overrides](./03-template-overrides.md) instead.
- See [Hooks Reference](./02-hooks-reference.md) for the full list of hooks, including sidebar, reply, and between-replies slots.
