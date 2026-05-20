Jetonomy exposes 58 hooks in the free plugin and 9 additional hooks in Jetonomy Pro. Every hook follows the `jetonomy_` prefix convention. Use them in your theme's `functions.php`, a site-specific mu-plugin, or a companion plugin.

**Hook naming prefix:** `jetonomy_`
**Namespace:** `Jetonomy\`

---

## Content Hooks

These hooks fire around the full lifecycle of posts and replies.

---

### `jetonomy_after_create_post`

Fires immediately after a new post is saved successfully via REST.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post_id` | `int` | ID of the newly created post |
| `$space_id` | `int` | ID of the space the post was created in |

**Source:** `includes/api/class-posts-controller.php`, `includes/class-abilities.php`

```php
add_action( 'jetonomy_after_create_post', function( int $post_id, int $space_id ) {
    // Push an event to your analytics pipeline.
    my_analytics_track( 'forum_post_created', [
        'post_id'  => $post_id,
        'space_id' => $space_id,
        'user_id'  => get_current_user_id(),
    ] );
}, 10, 2 );
```

For a hook that fires on every insert path (REST, admin, CLI, abilities, import) see `jetonomy_post_created` below.

---

### `jetonomy_post_created`

Fires from the `Post` model right after a row is inserted, so it covers every insert path — REST, admin AJAX, WP-CLI, Abilities, imports. Use this when you want to score the creation event itself regardless of how it was triggered.

Fires for every status. Listeners that only care about published posts should inspect `$context['status']`.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post_id` | `int` | Inserted post ID |
| `$space_id` | `int` | Parent space ID (`0` if unset) |
| `$user_id` | `int` | Author user ID (`0` if unset) |
| `$context` | `array` | The inserted column data (status, post_type, idea_status, slug, etc.) |

**Source:** `includes/models/class-post.php`

```php
// WB Gamification example: award points the moment a post lands, not when it gets upvoted.
add_action( 'jetonomy_post_created', function( int $post_id, int $space_id, int $user_id, array $context ) {
    if ( $user_id <= 0 || 'publish' !== ( $context['status'] ?? 'publish' ) ) {
        return;
    }
    wb_gam_award_points( $user_id, 'forum_post_created', [
        'post_id'  => $post_id,
        'space_id' => $space_id,
    ] );
}, 10, 4 );
```

---

### `jetonomy_after_create_reply`

Fires immediately after a new reply is saved successfully via REST. The built-in Notifier also listens to this hook to dispatch reply notifications.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply_id` | `int` | ID of the newly created reply |
| `$post_id` | `int` | ID of the post being replied to |

**Source:** `includes/api/class-replies-controller.php`, `includes/class-abilities.php`

```php
add_action( 'jetonomy_after_create_reply', function( int $reply_id, int $post_id ) {
    // Award XP in your gamification plugin.
    my_gamification_award_xp( get_current_user_id(), 5, 'reply_created' );
}, 10, 2 );
```

For a hook that fires on every insert path see `jetonomy_reply_created` below.

---

### `jetonomy_reply_created`

Mirrors `jetonomy_post_created` for the reply path. Fires from the `Reply` model so every insert path (REST, admin AJAX, CLI, Abilities, imports) is covered.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply_id` | `int` | Inserted reply ID |
| `$post_id` | `int` | Parent post ID (`0` if unset) |
| `$user_id` | `int` | Author user ID (`0` if unset) |
| `$context` | `array` | The inserted column data (status, parent_id, content, etc.) |

**Source:** `includes/models/class-reply.php`

```php
add_action( 'jetonomy_reply_created', function( int $reply_id, int $post_id, int $user_id, array $context ) {
    if ( $user_id <= 0 || 'publish' !== ( $context['status'] ?? 'publish' ) ) {
        return;
    }
    wb_gam_award_points( $user_id, 'forum_reply_created', [
        'reply_id' => $reply_id,
        'post_id'  => $post_id,
    ] );
}, 10, 4 );
```

---

### `jetonomy_post_updated`

Fires after a post is updated.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post_id` | `int` | ID of the updated post |

**Source:** `includes/api/class-posts-controller.php`

```php
add_action( 'jetonomy_post_updated', function( int $post_id ) {
    // Bust an external cache when a post changes.
    my_cdn_purge( 'post', $post_id );
} );
```

---

### `jetonomy_post_deleted`

Fires after a post is permanently deleted (not trashed).

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post_id` | `int` | ID of the deleted post |

**Source:** `includes/api/class-posts-controller.php`

---

### `jetonomy_reply_updated`

Fires after a reply is updated.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply_id` | `int` | ID of the updated reply |

**Source:** `includes/api/class-replies-controller.php`

---

### `jetonomy_reply_deleted`

Fires after a reply is permanently deleted.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply_id` | `int` | ID of the deleted reply |

**Source:** `includes/api/class-replies-controller.php`

---

### `jetonomy_reply_accepted`

Fires after a reply is marked as the accepted answer. The free plugin uses this to award +15 reputation to the reply author.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply_id` | `int` | ID of the accepted reply |
| `$post_id` | `int` | ID of the parent post |

**Source:** `includes/api/class-replies-controller.php`

```php
add_action( 'jetonomy_reply_accepted', function( int $reply_id, int $post_id ) {
    // Grant a badge for having a reply accepted.
    my_badges_award( get_current_user_id(), 'answer-accepted' );
}, 10, 2 );
```

---

## Voting

### `jetonomy_after_vote`

Fires after a vote is cast or changed on a post or reply via REST. Use this for receiver-side analytics; for voter-side gamification see `jetonomy_vote_cast` / `jetonomy_vote_retracted` below.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$object_type` | `string` | `'post'` or `'reply'` |
| `$object_id` | `int` | ID of the voted-on item |
| `$direction` | `string` | `'up'`, `'down'`, or `'none'` (vote removed) |
| `$user_id` | `int` | The voting user's WP ID |

**Source:** `includes/api/class-votes-controller.php`, `includes/class-abilities.php`

```php
add_action( 'jetonomy_after_vote', function( string $type, int $id, string $direction, int $user_id ) {
    if ( 'up' === $direction && 'post' === $type ) {
        // Award XP to the post author for receiving an upvote.
        $post = \Jetonomy\Models\Post::find( $id );
        if ( $post ) {
            my_xp_award( (int) $post->author_id, 2, 'post_upvoted' );
        }
    }
}, 10, 4 );
```

---

### `jetonomy_vote_cast`

Fires when a voter casts a new vote (or flips an existing one). Used by gamification to reward the voter directly. Reputation handles the receiver; this hook is the missing voter-side signal.

When a voter flips from upvote to downvote (or vice versa) a `jetonomy_vote_retracted` fires for the old value, then `jetonomy_vote_cast` fires for the new value.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$vote_type` | `int` | Raw vote value the voter chose (`1` for upvote, `-1` for downvote) |
| `$object_type` | `string` | `'post'` or `'reply'` |
| `$object_id` | `int` | Target object ID |
| `$voter_id` | `int` | Voting user ID |

**Source:** `includes/models/class-vote.php`

```php
// "Voted 10x this week" challenge.
add_action( 'jetonomy_vote_cast', function( int $vote_type, string $object_type, int $object_id, int $voter_id ) {
    wb_gam_award_points( $voter_id, 'forum_vote_cast', [
        'vote_type'   => $vote_type,
        'object_type' => $object_type,
        'object_id'   => $object_id,
    ] );
}, 10, 4 );
```

---

### `jetonomy_vote_retracted`

Fires when a voter retracts an existing vote (clicks the same arrow again or flips to the opposite arrow).

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$vote_type` | `int` | Raw value of the retracted vote (`1` or `-1`) |
| `$object_type` | `string` | `'post'` or `'reply'` |
| `$object_id` | `int` | Target object ID |
| `$voter_id` | `int` | Voting user ID |

**Source:** `includes/models/class-vote.php`

```php
add_action( 'jetonomy_vote_retracted', function( int $vote_type, string $object_type, int $object_id, int $voter_id ) {
    wb_gam_revoke_points( $voter_id, 'forum_vote_cast', [
        'object_type' => $object_type,
        'object_id'   => $object_id,
    ] );
}, 10, 4 );
```

---

## Ideas / Roadmap

### `jetonomy_idea_status_changed`

Fires after an idea's roadmap status changes (e.g. `planned` → `in_progress` → `shipped`). Use this to reward the post author on every transition, not just `planned`.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post_id` | `int` | Post ID |
| `$new_status` | `string` | New `idea_status` value |
| `$old_status` | `string` | Previous value (empty if unset) |
| `$actor_id` | `int` | Moderator who changed it |
| `$author_id` | `int` | Original post author (`0` if unset) — added so listeners can reward the author without a second lookup |

**Source:** `includes/api/class-posts-controller.php`

```php
add_action( 'jetonomy_idea_status_changed', function( int $post_id, string $new_status, string $old_status, int $actor_id, int $author_id ) {
    if ( $author_id <= 0 || $author_id === $actor_id ) {
        return; // Author can't farm their own status changes.
    }
    if ( 'shipped' === $new_status ) {
        wb_gam_award_points( $author_id, 'idea_shipped', [ 'post_id' => $post_id ] );
    }
}, 10, 5 );
```

---

## Moderation

### `jetonomy_content_moderated`

Fires when a moderator takes an action on a post or reply: approve, spam, or trash.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$action` | `string` | `'approve'`, `'spam'`, or `'trash'` |
| `$object_type` | `string` | `'post'` or `'reply'` |
| `$object_id` | `int` | ID of the moderated item |
| `$moderator_id` | `int` | WP user ID of the moderator |

**Source:** `includes/api/class-moderation-controller.php`, `includes/admin/class-admin.php`

```php
add_action( 'jetonomy_content_moderated', function( string $action, string $type, int $id, int $mod_id ) {
    if ( 'spam' === $action ) {
        my_spam_log( $type, $id, $mod_id );
    }
}, 10, 4 );
```

---

## Trust & Reputation

### `jetonomy_trust_level_pre_change`

Filter (not action). Lets you intercept an automatic trust-level promotion before it is written — to veto promotions for sandboxed users, fast-track a cohort during onboarding, or apply per-tenant ladder rules.

Only fires on automatic promotion paths: the daily cron evaluator and `wp jetonomy trust-evaluate`. Manual admin/CLI overrides bypass this filter on purpose so admins can always force-set a level.

Returning the user's current level short-circuits the write (no DB update, no `jetonomy_trust_level_changed` action).

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$new_level` | `int` | Level the evaluator chose (the proposed level) |
| `$user_id` | `int` | Target user ID |
| `$stats` | `array` | Stats fed to the evaluator: `post_count`, `days_active`, `reputation`, `replies_received` |

**Returns:** `int` — the level to actually write.

**Source:** `includes/class-cron.php`, `includes/class-cli.php`

```php
// WB Gamification example: veto auto-promotion for users in the sandbox.
add_filter( 'jetonomy_trust_level_pre_change', function( int $new_level, int $user_id, array $stats ): int {
    if ( get_user_meta( $user_id, 'wb_gam_sandboxed', true ) ) {
        $current = (int) \Jetonomy\Models\UserProfile::find( $user_id )->trust_level;
        return $current; // short-circuit the write.
    }
    return $new_level;
}, 10, 3 );
```

---

### `jetonomy_trust_level_changed`

Fires when a user's trust level is recalculated and changes. Runs from the daily cron job and the `wp jetonomy recalculate-trust` WP-CLI command.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | WP user ID |
| `$old_level` | `int` | Previous trust level (0–5) |
| `$new_level` | `int` | New trust level (0–5) |

**Source:** `includes/class-cron.php`, `includes/class-cli.php`

```php
add_action( 'jetonomy_trust_level_changed', function( int $user_id, int $old, int $new ) {
    if ( $new > $old ) {
        // Grant a WP capability when a user reaches Trust Level 3.
        if ( 3 === $new ) {
            $user = get_user_by( 'ID', $user_id );
            $user->add_cap( 'my_plugin_advanced_features' );
        }
    }
}, 10, 3 );
```

---

### `jetonomy_reputation_changed`

Fires whenever a user's reputation score changes.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | WP user ID |
| `$delta` | `int` | Points added (positive) or removed (negative) |
| `$reason` | `string` | Machine-readable reason slug (e.g. `'post_upvoted'`, `'reply_accepted'`) |

**Source:** `includes/trust/class-reputation.php`

```php
add_action( 'jetonomy_reputation_changed', function( int $user_id, int $delta, string $reason ) {
    // Sync reputation to BuddyPress profile.
    bp_update_user_meta( $user_id, 'jetonomy_rep', \Jetonomy\Models\UserProfile::get_reputation( $user_id ) );
}, 10, 3 );
```

---

## Notifications

### `jetonomy_notification_created`

Fires after a notification is created and stored.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$notification_id` | `int` | ID of the new notification record |
| `$user_id` | `int` | Recipient WP user ID |
| `$type` | `string` | Notification type slug (e.g. `'reply'`, `'mention'`, `'accepted'`) |

**Source:** `includes/notifications/class-notifier.php`

```php
add_action( 'jetonomy_notification_created', function( int $notif_id, int $user_id, string $type ) {
    // Forward notifications to a mobile push service.
    if ( 'mention' === $type ) {
        my_push_service_notify( $user_id, 'You were mentioned in a discussion.' );
    }
}, 10, 3 );
```

---

## Spaces

### `jetonomy_user_joined_space`

Fires after a user successfully joins a space.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | WP user ID of the new member |
| `$space_id` | `int` | ID of the space joined |

**Source:** `includes/models/class-space-member.php`

```php
add_action( 'jetonomy_user_joined_space', function( int $user_id, int $space_id ) {
    // Auto-subscribe the user to a MailChimp list tied to this space.
    my_mailchimp_subscribe( $user_id, "space_{$space_id}" );
}, 10, 2 );
```

---

## Membership

These hooks fire from both the MemberPress and PMPro adapters.

### `jetonomy_membership_activated`

Fires when a user's membership subscription becomes active.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | WP user ID |
| `$level_id` | `string` | Membership level identifier |
| `$adapter` | `string` | Adapter identifier (e.g. `'memberpress'`, `'pmpro'`, `'woocommerce'`) |

**Source:** `includes/adapters/class-member-press-adapter.php`, `class-pmpro-adapter.php`

---

### `jetonomy_membership_deactivated`

Fires when a user's membership expires or is cancelled.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$user_id` | `int` | WP user ID |
| `$level_id` | `string` | Membership level identifier |
| `$adapter` | `string` | Adapter identifier (e.g. `'memberpress'`, `'pmpro'`, `'woocommerce'`) |

**Source:** `includes/adapters/class-member-press-adapter.php`, `class-pmpro-adapter.php`

```php
add_action( 'jetonomy_membership_deactivated', function( int $user_id, string $level_id, string $adapter ) {
    // Revoke access to private spaces when membership lapses.
    $private_spaces = \Jetonomy\Models\Space::get_by_membership_level( $level_id );
    foreach ( $private_spaces as $space ) {
        \Jetonomy\Models\SpaceMember::remove( $user_id, $space->id );
    }
}, 10, 3 );
```

---

## Topic Management

### `jetonomy_post_merged`

Fires after two posts are merged (all replies moved to the target, source deleted).

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$source_post_id` | `int` | The post that was merged and deleted |
| `$target_post_id` | `int` | The post that received the replies |

---

### `jetonomy_reply_split`

Fires after a reply is split into a new standalone post.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$new_post_id` | `int` | ID of the newly created post |
| `$original_reply_id` | `int` | ID of the reply that was split out |

---

## Template Hooks

These hooks fire inside the PHP templates and let you inject content without overriding template files.

### `jetonomy_before_content`

Fires inside the `.jt-app` wrapper, before the header partial and content container. Bridge plugins (such as BuddyNext) use this to inject a community subnav in place of the default Jetonomy community nav.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$data` | `array` | Route data: `['route' => string, 'slug' => string]` |

**Source:** `includes/class-template-loader.php`

```php
add_action( 'jetonomy_before_content', function( array $data ) {
    echo '<div class="my-subnav">Custom nav here</div>';
} );
```

---

### `jetonomy_after_content`

Fires after the main `.container` closes, before the `.jt-app` wrapper closes.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$data` | `array` | Route data |

---

### `jetonomy_new_post_fields`

Fires inside the new-post form, after the built-in fields. Use this to inject custom form fields (e.g. for Pro custom fields extension).

**Source:** `templates/views/new-post.php`

```php
add_action( 'jetonomy_new_post_fields', function() {
    // Render an additional "Estimated time" field.
    echo '<label for="jt-estimated-time">' . esc_html__( 'Estimated time (hours)', 'my-plugin' ) . '</label>';
    echo '<input type="number" id="jt-estimated-time" name="estimated_time" min="0" />';
} );
```

---

### `jetonomy_post_meta_fields`

Fires inside the single-post view, after the post meta line.

**Source:** `templates/views/single-post.php`

---

### `jetonomy_post_actions`

Fires inside the single-post view, inside the post action toolbar.

**Source:** `templates/views/single-post.php`

---

### `jetonomy_reply_actions`

Fires inside the reply card, inside the reply action row.

**Source:** `templates/partials/reply-card.php`

---

### `jetonomy_profile_after_stats`

Fires on user profile pages, after the reputation/trust stats block.

**Source:** `templates/views/user-profile.php`

---

### `jetonomy_profile_display_fields`

Fires on user profile pages in the display (read-only) section. Use this to render extra profile fields.

**Source:** `templates/views/user-profile.php`

```php
add_action( 'jetonomy_profile_display_fields', function() {
    $user_id = get_queried_object_id(); // or extract from the URL
    $company = get_user_meta( $user_id, 'company', true );
    if ( $company ) {
        printf( '<p class="jt-profile-field"><strong>%s</strong> %s</p>',
            esc_html__( 'Company:', 'my-plugin' ),
            esc_html( $company )
        );
    }
} );
```

---

### `jetonomy_profile_edit_fields`

Fires inside the edit-profile form. Pair with `jetonomy_profile_display_fields` and a custom `save_post` / REST action to persist data.

**Source:** `templates/views/edit-profile.php`

---

### `jetonomy_header_nav_items`

Fires inside the community header, after the built-in nav items. Add extra navigation links here.

**Source:** `templates/partials/header.php`

```php
add_action( 'jetonomy_header_nav_items', function() {
    echo '<a href="/community/events/" class="jt-nav-link">Events</a>';
} );
```

---

### `jetonomy_sidebar_before`

Fires at the top of the Jetonomy sidebar, before any widgets render. Prime slot for ad plugins, announcement banners, or custom cards.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$space` | `object\|null` | Current space object, or `null` outside a space |

**Source:** `templates/partials/sidebar.php`

```php
add_action( 'jetonomy_sidebar_before', function( $space ) {
    echo do_shortcode( '[wb_ads position="jetonomy_sidebar_top"]' );
} );
```

---

### `jetonomy_sidebar_after`

Fires at the bottom of the Jetonomy sidebar, after all widgets render.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$space` | `object\|null` | Current space object, or `null` outside a space |

**Source:** `templates/partials/sidebar.php`

```php
add_action( 'jetonomy_sidebar_after', function( $space ) {
    echo do_shortcode( '[wb_ads position="jetonomy_sidebar_bottom"]' );
} );
```

---

### `jetonomy_sidebar_after_about`

Fires in the sidebar immediately after the "About" space card closes. Only fires when a space is present (i.e. on space-scoped pages). Ideal for ads, announcements, or CTAs pinned below the space intro, before Trending and other widgets.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$space` | `object` | Current space object |

**Source:** `templates/partials/sidebar.php`

```php
add_action( 'jetonomy_sidebar_after_about', function( $space ) {
    echo do_shortcode( '[wbam_ad id="42"]' );
} );
```

---

### `jetonomy_after_post_article`

Fires after the main post `<article>` element and before the replies section on a single post view. Ideal for ads, related-topic blocks, or CTAs between the topic body and the reply list.

> ⚠️ Do not confuse with the `jetonomy_after_post_content` **filter** (below) which fires *inside* the post body. This hook is an **action** named `_article` to avoid collision.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post` | `object` | Current post object |

**Source:** `templates/views/single-post.php`

```php
add_action( 'jetonomy_after_post_article', function( $post ) {
    echo do_shortcode( '[wb_ads position="jetonomy_after_topic"]' );
} );
```

---

### `jetonomy_before_replies`

Fires inside `.jt-replies-section`, immediately before the replies list (above both the empty state and the populated list).

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post` | `object` | Current post object |
| `$total_replies` | `int` | Total reply count for the post |

**Source:** `templates/views/single-post.php`

```php
add_action( 'jetonomy_before_replies', function( $post, $total_replies ) {
    if ( $total_replies > 5 ) {
        echo do_shortcode( '[wb_ads position="jetonomy_replies_top"]' );
    }
}, 10, 2 );
```

---

### `jetonomy_between_replies`

Fires after each top-level reply in the reply list. Use the `$index` parameter to inject content every Nth reply (e.g. an ad every 5 replies).

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$reply` | `object` | The reply object just rendered |
| `$index` | `int` | Zero-based index within the current batch (first batch or last batch) |
| `$post` | `object` | Current post object |

**Source:** `templates/views/single-post.php`

> The replies list renders in two batches (opening + latest). The index resets at the start of each batch. Use `$reply->id` for absolute identity.

```php
add_action( 'jetonomy_between_replies', function( $reply, $index, $post ) {
    // Inject an ad after every 5th reply.
    if ( 4 === $index % 5 ) {
        echo '<div class="jt-reply-ad">' . do_shortcode( '[wb_ads position="jetonomy_between_replies"]' ) . '</div>';
    }
}, 10, 3 );
```

---

### `jetonomy_after_replies`

Fires inside `.jt-replies-section`, after the replies list and before the composer.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$post` | `object` | Current post object |
| `$total_replies` | `int` | Total reply count for the post |

**Source:** `templates/views/single-post.php`

```php
add_action( 'jetonomy_after_replies', function( $post, $total_replies ) {
    echo do_shortcode( '[wb_ads position="jetonomy_replies_bottom"]' );
}, 10, 2 );
```

---

## Admin Extension Hooks

Use these to add content to the Jetonomy admin pages without overriding core admin files.

| Hook | Parameters | Where it fires |
|------|------------|---------------|
| `jetonomy_admin_dashboard_widgets` | none | Dashboard page - add custom stat cards |
| `jetonomy_admin_dashboard_after_stats` | none | Dashboard - below the stats row |
| `jetonomy_admin_settings_tabs` | none | Settings page - register new tab nav items |
| `jetonomy_admin_settings_tab_content` | `$active_tab` (string) | Settings page - render tab content |
| `jetonomy_admin_moderation_tabs` | none | Moderation page - extra tab nav items |
| `jetonomy_admin_moderation_tab_content` | `$active_tab` (string) | Moderation page - render tab content |
| `jetonomy_admin_space_edit_tabs` | `$space_id` (int) | Space edit page - extra tab nav items |
| `jetonomy_admin_space_edit_tab_content` | `$active_tab` (string), `$space_id` (int) | Space edit page - render tab content |
| `jetonomy_admin_render_extensions` | none | Admin - Extensions tab placeholder |
| `jetonomy_admin_render_license` | none | Admin - License tab placeholder |

**Example: adding a Settings tab**

```php
// Register the tab nav item.
add_action( 'jetonomy_admin_settings_tabs', function() {
    $active = $_GET['tab'] ?? 'general';
    $class  = 'my-custom' === $active ? 'nav-tab-active' : '';
    printf(
        '<a href="?page=jetonomy-settings&tab=my-custom" class="nav-tab %s">%s</a>',
        esc_attr( $class ),
        esc_html__( 'My Settings', 'my-plugin' )
    );
} );

// Render the tab content.
add_action( 'jetonomy_admin_settings_tab_content', function( string $active_tab ) {
    if ( 'my-custom' !== $active_tab ) {
        return;
    }
    echo '<div class="jt-settings-card">';
    echo '<div class="jt-settings-card__head">';
    echo '<p class="jt-settings-card__title">My Settings</p>';
    echo '</div>';
    // Your settings form here.
    echo '</div>';
} );
```

---

## Performance & Cron

### `jetonomy_cron_batch_size`

Filters the maximum number of rows processed per run by any of Jetonomy's background cleanup handlers. The default is 500 rows per run, which prevents cron jobs from timing out on large sites with high activity volumes.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$batch_size` | `int` | Maximum rows to process. Default: `500` |
| `$handler` | `string` | The handler name, e.g. `'prune_activity_log'`, `'expire_restrictions'`, `'cleanup_notifications'`, `'publish_scheduled_posts'` |

**Return:** `int`

**Source:** `includes/class-cron.php`

Use the `$handler` parameter to set different limits per job:

```php
add_filter( 'jetonomy_cron_batch_size', function( int $batch_size, string $handler ): int {
    // Allow the activity log pruner to work through larger batches on this high-traffic site.
    if ( 'prune_activity_log' === $handler ) {
        return 1000;
    }
    return $batch_size;
}, 10, 2 );
```

---

## Filter Hooks

### `jetonomy_template_map`

Filters the route-to-template map used by `Template_Loader`. Pass an absolute path to override an existing template or add a completely new route. See [Template Overrides](./03-template-overrides.md) for the complete guide.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$map` | `array` | `['route' => 'relative/path.php']` |

**Return:** `array` Modified map

```php
add_filter( 'jetonomy_template_map', function( array $map ): array {
    // Register a new 'events' route resolved against the Pro plugin directory.
    $map['events'] = MYPLUGIN_DIR . 'templates/events.php';
    return $map;
} );
```

---

### `jetonomy_check_content`

Filters content before it is saved as a post or reply. Return a `WP_Error` to reject the content with a message shown to the user.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$result` | `true\|WP_Error` | Pass through or return a `WP_Error` to block |
| `$content` | `string` | The sanitized HTML content string |
| `$user_id` | `int` | Author's WP user ID |

**Return:** `true|WP_Error`

**Source:** `includes/api/class-posts-controller.php`, `class-replies-controller.php`

```php
add_filter( 'jetonomy_check_content', function( $result, string $content, int $user_id ) {
    // Block posts containing a forbidden phrase.
    if ( str_contains( strtolower( $content ), 'buy cheap followers' ) ) {
        return new WP_Error( 'spam_blocked', __( 'This content was flagged as spam.', 'my-plugin' ) );
    }
    return $result;
}, 10, 3 );
```

---

### `jetonomy_after_post_content`

Filters output rendered after the main post content area in single-post view. Return an HTML string.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$html` | `string` | HTML to render after post content (empty by default) |
| `$post` | `\Jetonomy\Models\Post` | The current post object |

**Return:** `string`

**Source:** `templates/views/single-post.php`

```php
add_filter( 'jetonomy_after_post_content', function( string $html, $post ): string {
    $html .= '<div class="my-related-posts">' . my_get_related_posts( $post->id ) . '</div>';
    return $html;
}, 10, 2 );
```

---

### `jetonomy_notification_email_headers`

Filters the email headers array passed to `wp_mail()` for all Jetonomy notifications.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$headers` | `array` | Array of mail headers |

**Return:** `array`

**Source:** `includes/adapters/class-wp-mail-adapter.php`

```php
add_filter( 'jetonomy_notification_email_headers', function( array $headers ): array {
    $headers[] = 'Reply-To: noreply@example.com';
    return $headers;
} );
```

---

### `jetonomy_profile_url`

Filters the public URL for a user's community profile.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$url` | `string` | Default profile URL (e.g. `/community/u/janedoe/`) |
| `$user_id` | `int` | WP user ID |

**Return:** `string`

**Source:** `includes/functions.php`

```php
add_filter( 'jetonomy_profile_url', function( string $url, int $user_id ): string {
    // Point profile links to a BuddyPress profile instead.
    $bp_url = bp_core_get_user_domain( $user_id );
    return $bp_url ?: $url;
}, 10, 2 );
```

---

### `jetonomy_admin_menu_label`

Filters the top-level admin menu label.

**Return:** `string`

```php
add_filter( 'jetonomy_admin_menu_label', fn() => 'Forum' );
```

---

### `jetonomy_admin_menu_icon`

Filters the Dashicons icon for the admin menu item.

**Return:** `string` (Dashicons class, e.g. `'dashicons-format-chat'`)

---

### `jetonomy_show_community_nav`

Filters whether the built-in community nav bar is displayed. Return `false` to hide it (useful when a bridge plugin provides its own nav).

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$show` | `bool` | `true` by default |

**Return:** `bool`

---

### `jetonomy_importers`

Filters the list of registered importers shown in the Import tool.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$importers` | `array` | `['id' => Importer_Instance]` |

**Return:** `array`

**Source:** `includes/import/class-import-manager.php`

```php
add_filter( 'jetonomy_importers', function( array $importers ): array {
    $importers['my-forum'] = new My_Forum_Importer();
    return $importers;
} );
```

---

### `jetonomy_search_query_args`

Fires inside the Search controller before the SQL is built. Use this to modify search parameters.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$args` | `array` | Keys: `q`, `space_id`, `date_from`, `date_to`, `author_id`, `tag_slug`, `sort` |

**Return:** `array`

**Source:** `includes/api/class-search-controller.php`

---

## Pro Hooks

These hooks are available only when **Jetonomy Pro** is active. Pro injects into core admin via the standard admin hooks (`jetonomy_admin_dashboard_widgets`, `jetonomy_admin_settings_tabs`) and registers its own extension lifecycle events.

| Hook | Type | Description |
|------|------|-------------|
| `jetonomy_pro_extension_booted` | action | Fires after a Pro extension's `boot()` runs. Params: `$extension_id (string)` |
| `jetonomy_pro_extension_enabled` | action | Fires when an extension is toggled on in admin. Params: `$extension_id (string)` |
| `jetonomy_pro_extension_disabled` | action | Fires when an extension is toggled off. Params: `$extension_id (string)` |
| `jetonomy_pro_message_sent` | action | Fires after a private message is sent. Params: `$message_id (int)`, `$conversation_id (int)`, `$sender_id (int)` |
| `jetonomy_pro_dm_received` | action | Fires once per recipient when a DM is delivered. Counterpart to `jetonomy_pro_message_sent` — lets you build "received first DM" or "active inbox" rules. Skipped for system messages. Params: `$message_id (int)`, `$conversation_id (int)`, `$sender_id (int)`, `$recipient_id (int)` |
| `jetonomy_pro_reaction_added` | action | Fires when a reaction is added. Params: `$object_type (string)`, `$object_id (int)`, `$emoji (string)`, `$user_id (int)` |
| `jetonomy_pro_poll_vote_cast` | action | Fires when a poll vote is cast. Params: `$poll_id (int)`, `$option_id (int)`, `$user_id (int)` |
| `jetonomy_pro_webhook_sent` | action | Fires after a webhook is dispatched. Params: `$webhook_id (int)`, `$event (string)`, `$response_code (int)` |
| `jetonomy_pro_digest_sent` | action | Fires after an email digest is sent. Params: `$user_id (int)`, `$frequency (string)` |

---

## What's Next?

- [Template Overrides](./03-template-overrides.md) - Customize templates without modifying plugin files
- [REST API Reference](./01-rest-api.md) - Full endpoint listing
- [Adapter System](./05-adapters.md) - Swap email, search, and real-time backends
